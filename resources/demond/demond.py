# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

"""
Démon de polling pour MyOkoTouch.

Interroge périodiquement l'API JSON de la chaudière Okofen (GET /all?),
envoie les valeurs à Jeedom via un callback HTTP POST, et traite les
commandes d'écriture reçues depuis Jeedom via socket TCP.

Contrainte chaudière : délai minimum de 2500 ms entre deux lectures (/all?).
Les écritures n'ont pas de contrainte de délai.
"""

import argparse
from collections import deque
import json
import logging
import os
import signal
import sys
import time
import traceback
import urllib.request

import requests

try:
    from jeedom.jeedom import jeedom_utils, jeedom_com, jeedom_socket, JEEDOM_SOCKET_MESSAGE
except ImportError as exc:
    # On expose la vraie cause (ex : "No module named 'pyudev'") : la lib template
    # jeedom.jeedom importe requests/pyserial/pyudev, installés via les paquets apt
    # déclarés dans plugin_info/packages.json (python3-requests, python3-serial, python3-pyudev).
    print("Error: importing module jeedom.jeedom: " + repr(exc), flush=True)
    sys.exit(1)


# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

_MIN_DELAY   = 2.5   # secondes — délai minimum entre deux lectures Okofen
_WRITE_DELAY = 0.25  # secondes — délai entre deux écritures consécutives
_IDLE_SLEEP  = 0.05  # secondes — sleep quand rien à faire (garde la boucle réactive)

# ---------------------------------------------------------------------------
# Variables globales (initialisées depuis les arguments CLI)
# ---------------------------------------------------------------------------

_log_level   = 'error'
_callback    = ''
_apikey      = ''
_socketport  = 55009
_cycle       = 3.0
_host        = ''
_port        = 4321
_password    = ''
_pidfile     = '/tmp/demond.pid'

_last_request_time = 0.0   # timestamp de la dernière lecture HTTP vers Okofen
_write_queue = deque()     # queue FIFO des commandes d'écriture
_regen_queue = deque()     # queue FIFO des demandes de régénération de commandes
_syncall_pending = False   # une synchro globale (toutes commandes) est demandée par Jeedom


# ---------------------------------------------------------------------------
# Communication avec la chaudière
# ---------------------------------------------------------------------------

def _mask_pwd(text):
    """Masque le mot de passe Okofen dans une chaîne destinée aux logs (URL).
    La requête réelle n'est jamais modifiée — seul l'affichage est nettoyé."""
    if _password:
        return text.replace('/' + _password + '/', '/***/').replace('/' + _password, '/***')
    return text


def _wait_okofen_delay():
    """Respecte le délai minimum entre deux requêtes Okofen."""
    global _last_request_time
    elapsed = time.time() - _last_request_time
    if elapsed < _MIN_DELAY:
        wait = _MIN_DELAY - elapsed
        logging.debug('[okofen] attente délai minimum: %.2fs', wait)
        time.sleep(wait)


def fetch_okofen():
    """
    Lit toutes les données de la chaudière avec métadonnées : GET /all?
    Utilise urllib pour préserver le '?' final (requests le supprime).
    Retourne le dict JSON ou lève une exception.
    """
    global _last_request_time
    _wait_okofen_delay()
    _last_request_time = time.time()  # mis à jour avant la requête pour throttler même en cas d'erreur
    url = f'http://{_host}:{_port}/{_password}/all?'
    logging.debug('[okofen] GET %s', _mask_pwd(url))
    with urllib.request.urlopen(url, timeout=5) as resp:
        # Le firmware Okofen n'annonce pas toujours son charset : on respecte celui déclaré
        # dans l'en-tête HTTP (repli UTF-8), et errors='replace' évite un crash du démon sur
        # un octet inattendu plutôt que de perdre tout le polling.
        charset = resp.headers.get_content_charset() or 'utf-8'
        body = resp.read().decode(charset, errors='replace')
    return json.loads(body)


def write_okofen(component, var, value):
    """
    Écrit une valeur sur un registre : GET http://host:port/password/component.var=value
    Pas de délai minimum requis pour les écritures.
    Retourne le dict JSON de la réponse Okofen.
    """
    url = f'http://{_host}:{_port}/{_password}/{component}.{var}={value}'
    logging.debug('[okofen] GET %s', _mask_pwd(url))
    resp = requests.get(url, timeout=5)
    resp.raise_for_status()
    logging.info('[write] %s.%s = %s', component, var, value)
    try:
        return resp.json()
    except Exception:
        return {}


# ---------------------------------------------------------------------------
# Traitement des messages socket (commandes Jeedom → démon)
# ---------------------------------------------------------------------------

def read_socket():
    """
    Traite tous les messages en attente dans la queue socket.
    Format attendu : {"apikey": "...", "action": "write", "component": "hk1", "var": "mode_auto", "value": 1}
    """
    global _syncall_pending
    while not JEEDOM_SOCKET_MESSAGE.empty():
        raw = JEEDOM_SOCKET_MESSAGE.get()
        try:
            msg = json.loads(raw.decode('utf-8').strip())
        except Exception as e:
            logging.warning('[socket] JSON invalide: %s — données brutes: %s', e, raw)
            continue

        logging.debug('[socket] message reçu: %s', msg)

        if msg.get('apikey') != _apikey:
            logging.warning('[socket] clé API invalide')
            continue

        action = msg.get('action')
        if action == 'write':
            component = msg.get('component', '')
            var       = msg.get('var', '')
            value     = msg.get('value', 0)
            if not component or not var:
                logging.warning('[socket] commande write sans component/var: %s', msg)
                continue
            _write_queue.append({'component': component, 'var': var, 'value': value})
            logging.debug('[queue] ajout écriture %s.%s = %s (queue: %d)', component, var, value, len(_write_queue))
        elif action == 'regen':
            component   = msg.get('component', '')
            eq_logic_id = msg.get('eqLogicId', 0)
            if not component or not eq_logic_id:
                logging.warning('[socket] regen sans component/eqLogicId: %s', msg)
                continue
            _regen_queue.append({
                'component':  component,
                'eqLogicId':  eq_logic_id,
                'nameMode':   msg.get('nameMode', 'default'),
                'cmdMode':    msg.get('cmdMode'),
            })
            logging.debug('[queue] ajout regen %s (eqLogicId=%s, queue: %d)', component, eq_logic_id, len(_regen_queue))
        elif action == 'syncall':
            # Synchro globale demandée : on lèvera un drapeau, satisfait au prochain cycle
            # de lecture (réutilise les données fraîches, pas d'appel /all? supplémentaire).
            _syncall_pending = True
            logging.debug('[queue] synchro globale demandée (syncall)')
        else:
            logging.warning('[socket] action inconnue: %s', action)


# ---------------------------------------------------------------------------
# Traitement d'une écriture depuis la queue
# ---------------------------------------------------------------------------

def execute_one_write(item, jcom):
    """
    Exécute une écriture depuis la queue FIFO, parse la réponse Okofen
    et met à jour Jeedom immédiatement via send_change_immediate().

    Format réponse Okofen : {"component.var": value, ..., "state": true/false}
    """
    component = item['component']
    var       = item['var']
    value     = item['value']
    try:
        response_data = write_okofen(component, var, value)

        if not response_data.get('state', False):
            logging.warning('[write] état KO retourné par Okofen pour %s.%s', component, var)

        updates = {}  # {component: {var: val, ...}}
        for key, val in response_data.items():
            if key == 'state' or '.' not in key:
                continue
            comp, v = key.split('.', 1)
            updates.setdefault(comp, {})[v] = val

        for comp, vars_dict in updates.items():
            jcom.send_change_immediate({comp: vars_dict})
            logging.debug('[write] mise à jour immédiate %s: %s', comp, vars_dict)

    except Exception as e:
        logging.error('[write] erreur %s.%s: %s', component, var, e)


# ---------------------------------------------------------------------------
# Traitement des données de lecture (factorisé — utilisé par poll et regen)
# ---------------------------------------------------------------------------

def _process_poll_data(data, jcom):
    """
    Parcourt les données /all? et envoie les valeurs à Jeedom via add_changes.
    Retourne le nombre de variables envoyées.
    """
    total_vars = 0
    for component, vars_data in data.items():
        if not isinstance(vars_data, dict):
            continue
        for key, vardata in vars_data.items():
            if isinstance(vardata, dict):
                if 'val' not in vardata:
                    continue
                raw = vardata['val']
                try:
                    factor = float(vardata.get('factor', 1) or 1)
                    val = round(float(raw) * factor, 4)
                except (ValueError, TypeError):
                    val = str(raw).replace('|', ' / ')
            elif isinstance(vardata, (str, int, float)):
                val = str(vardata).replace('|', ' / ')
            else:
                continue
            jcom.add_changes(f'{component}::{key}', val)
            logging.debug('[poll] %s.%s = %s', component, key, val)
            total_vars += 1
    return total_vars


def _satisfy_regen_queue(data, jcom):
    """
    Satisfait toutes les requêtes regen en attente à partir de données déjà lues.
    Appelé après un cycle de lecture pour éviter un second appel à /all?.
    """
    while _regen_queue:
        item = _regen_queue.popleft()
        component_data = data.get(item['component'], {})
        payload = {'_regen': {
            'eqLogicId':     item['eqLogicId'],
            'nameMode':      item['nameMode'],
            'componentData': component_data,
        }}
        if item.get('cmdMode'):
            payload['_regen']['cmdMode'] = item['cmdMode']
        jcom.send_change_immediate(payload)
        logging.info('[regen] satisfait via cycle lecture: %s (eqLogicId=%s)', item['component'], item['eqLogicId'])


def _satisfy_syncall(data, jcom):
    """
    Satisfait une demande de synchro globale à partir de données /all? déjà lues :
    renvoie le JSON complet (avec métadonnées) à Jeedom via le marqueur _syncall, qui
    (re)crée les équipements et leurs commandes. Évite tout appel /all? supplémentaire.
    """
    global _syncall_pending
    if not _syncall_pending:
        return
    jcom.send_change_immediate({'_syncall': {'allData': data}})
    _syncall_pending = False
    logging.info('[syncall] données complètes envoyées à Jeedom')


def execute_one_regen(item, jcom):
    """
    Exécute une demande de régénération : lit /all? (en respectant le délai minimum),
    envoie les valeurs fraîches à Jeedom, puis envoie le callback _regen avec les
    métadonnées complètes du composant pour que PHP recrée les commandes.
    """
    component   = item['component']
    eq_logic_id = item['eqLogicId']
    try:
        data = fetch_okofen()   # _wait_okofen_delay() + lecture /all? avec métadonnées
        # Envoyer les valeurs fraîches à Jeedom (comme un cycle de lecture normal)
        total = _process_poll_data(data, jcom)
        logging.debug('[regen] %d valeur(s) envoyée(s) via poll data', total)
        # Envoyer le callback regen avec les métadonnées complètes du composant
        component_data = data.get(component, {})
        payload = {'_regen': {
            'eqLogicId':     eq_logic_id,
            'nameMode':      item['nameMode'],
            'componentData': component_data,
        }}
        if item.get('cmdMode'):
            payload['_regen']['cmdMode'] = item['cmdMode']
        jcom.send_change_immediate(payload)
        logging.info('[regen] callback envoyé pour %s (eqLogicId=%s)', component, eq_logic_id)
    except Exception as e:
        logging.error('[regen] erreur %s: %s', component, e)
        logging.debug(traceback.format_exc())


# ---------------------------------------------------------------------------
# Boucle principale
# ---------------------------------------------------------------------------

def polling_loop(jcom):
    """
    Boucle principale — interleave lectures et écritures.

    Priorités à chaque itération :
      1. Si le cycle de lecture est échu → lire /all? (prioritaire)
      2. Sinon si la queue d'écriture est non vide → exécuter une écriture + attendre 250ms
      3. Sinon → idle 50ms (reste réactif aux nouvelles commandes socket)

    La socket est drainée à chaque itération pour alimenter _write_queue.
    """
    logging.info('[poll] démarrage (cycle=%.1fs)', _cycle)
    last_read_time = 0.0

    while True:
        try:
            read_socket()

            now = time.time()

            if now - last_read_time >= _cycle:
                # ── Lecture globale (prioritaire) ─────────────────────────
                data = fetch_okofen()
                last_read_time = time.time()
                total_vars = _process_poll_data(data, jcom)
                logging.debug('[poll] cycle lecture OK — %d valeur(s)', total_vars)
                # Satisfaire les requêtes regen / synchro en attente avec les données fraîches
                if _regen_queue:
                    _satisfy_regen_queue(data, jcom)
                if _syncall_pending:
                    _satisfy_syncall(data, jcom)

            elif _write_queue:
                # ── Une écriture en attente ───────────────────────────────
                execute_one_write(_write_queue.popleft(), jcom)
                time.sleep(_WRITE_DELAY)

            elif _regen_queue:
                # ── Régénération demandée (lecture dédiée avec délai) ─────
                execute_one_regen(_regen_queue.popleft(), jcom)
                last_read_time = time.time()  # évite un cycle de lecture immédiat après

            elif _syncall_pending:
                # ── Synchro globale demandée (lecture dédiée avec délai) ──
                data = fetch_okofen()           # _wait_okofen_delay() + /all? avec métadonnées
                last_read_time = time.time()
                _process_poll_data(data, jcom)  # remonte aussi les valeurs fraîches
                _satisfy_syncall(data, jcom)

            else:
                # ── Idle — reste réactif ──────────────────────────────────
                time.sleep(_IDLE_SLEEP)

        except Exception as e:
            logging.error('[poll] erreur: %s', e)
            logging.debug(traceback.format_exc())
            time.sleep(_IDLE_SLEEP)


# ---------------------------------------------------------------------------
# Gestion des signaux et arrêt
# ---------------------------------------------------------------------------

def shutdown(jeedom_sock=None):
    logging.info('Arrêt du démon')
    try:
        if jeedom_sock:
            jeedom_sock.close()
    except Exception:
        pass
    try:
        os.remove(_pidfile)
        logging.debug('Fichier PID supprimé: %s', _pidfile)
    except Exception:
        pass
    logging.debug('Exit 0')
    sys.stdout.flush()
    os._exit(0)


# ---------------------------------------------------------------------------
# Point d'entrée
# ---------------------------------------------------------------------------

parser = argparse.ArgumentParser(description='MyOkoTouch daemon — Okofen Pelletronic Touch')
parser.add_argument('--loglevel',   help='Niveau de log (debug/info/warning/error)', type=str, default='error')
parser.add_argument('--callback',   help='URL de callback Jeedom',                   type=str, default='')
parser.add_argument('--apikey',     help='Clé API Jeedom',                           type=str, default='')
parser.add_argument('--socketport', help='Port TCP du socket',                        type=int, default=55009)
parser.add_argument('--cycle',      help='Intervalle de polling en secondes',         type=float, default=3.0)
parser.add_argument('--host',       help='IP ou hostname de la chaudière Okofen',    type=str, default='')
parser.add_argument('--port',       help='Port HTTP de la chaudière (défaut: 4321)', type=int, default=4321)
parser.add_argument('--password',   help='Mot de passe de l\'API Okofen',            type=str, default='')
parser.add_argument('--pid',        help='Fichier PID',                              type=str, default='/tmp/demond.pid')
args = parser.parse_args()

_log_level  = args.loglevel
_callback   = args.callback
_apikey     = args.apikey
_socketport = args.socketport
_cycle      = max(args.cycle, _MIN_DELAY)   # jamais en dessous du délai minimum
_host       = args.host
_port       = args.port
_password   = args.password
_pidfile    = args.pid

jeedom_utils.set_log_level(_log_level)

logging.info('=== Démarrage MyOkoTouch daemon ===')
logging.info('Log level  : %s', _log_level)
logging.info('Host       : %s:%s', _host, _port)
logging.info('Cycle      : %.1fs', _cycle)
logging.info('Socket port: %s', _socketport)
logging.info('PID file   : %s', _pidfile)
logging.info('Callback   : %s', _callback)

if not _host or not _password:
    logging.error('Paramètres manquants (--host et --password obligatoires)')
    sys.exit(1)

jcom = jeedom_com(apikey=_apikey, url=_callback, cycle=_cycle)
logging.info('Callback Jeedom: %s', _callback)

# Écriture du PID
jeedom_utils.write_pid(str(_pidfile))

# Démarrage du socket
jeedom_sock = jeedom_socket(port=_socketport, address='localhost')

# Handlers de signal
signal.signal(signal.SIGINT,  lambda s, f: shutdown(jeedom_sock))
signal.signal(signal.SIGTERM, lambda s, f: shutdown(jeedom_sock))

# Démarrage du socket en thread de fond
jeedom_sock.open()

try:
    polling_loop(jcom)
except Exception as e:
    logging.error('Erreur fatale: %s', e)
    logging.debug(traceback.format_exc())
    shutdown(jeedom_sock)
