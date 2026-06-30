<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/myokotouch.class.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Adresse IP / Hostname}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP ou nom d\'hôte de la chaudière Okofen (ex : 192.168.1.100 ou okofen.lan)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="okofen_host" placeholder="192.168.1.100"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Port JSON}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port de l\'interface JSON Okofen (défaut : 4321)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="okofen_port" type="number" placeholder="4321"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Mot de passe}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe de l\'interface JSON Okofen}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="okofen_password" type="password"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Délai de polling (ms)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Délai minimum imposé par la chaudière : 2500 ms. Valeur recommandée : 3000 ms ou plus.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="okofen_polling" type="number" min="2500" placeholder="3000"/>
        <span class="help-block">{{Minimum : 2500 ms — Recommandé : 3000 ms}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Port socket démon}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port TCP local utilisé par le démon pour recevoir les commandes de Jeedom (défaut : 55009)}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="socketport" type="number" min="1024" max="65535" placeholder="55009"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Mode commandes par défaut}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mode appliqué lors de la création d\'un nouvel équipement. Standard : commandes essentielles uniquement. Expert : toutes les variables Okofen.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="cmd_mode_default">
          <option value="standard" selected>{{Standard}}</option>
          <option value="expert">{{Expert}}</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-4">
        <button type="button" class="btn btn-default" id="bt_testOkofen">
          <i class="fas fa-plug"></i> {{Tester la connexion}}
        </button>
        <div id="testOkofenResult" style="margin-top:8px;"></div>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Diagnostic / Support}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Génère un fichier contenant le retour brut des JSON de la chaudière et les informations d\'encodage, à envoyer au support en cas de problème d\'affichage. Le mot de passe et l\'adresse sont masqués.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <button type="button" class="btn btn-info" id="bt_debugDump">
          <i class="fas fa-bug"></i> {{Générer un fichier de diagnostic}}
        </button>
        <div id="debugDumpResult" style="margin-top:8px;"></div>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6" style="margin-top:16px;">
        <span class="text-muted">
          <i class="fas fa-info-circle"></i>
          {{Version du plugin}} : <strong><?= myokotouch::getPluginVersion() ?></strong>
        </span>
      </div>
    </div>

  </fieldset>
</form>

<script>
$('#bt_testOkofen').off('click').on('click', function () {
  $('#testOkofenResult').html('<i class="fas fa-spinner fa-spin"></i> {{Connexion en cours…}}');
  $.ajax({
    type: 'POST',
    url: 'plugins/myokotouch/core/ajax/myokotouch.ajax.php',
    data: {
      action:   'testConnection',
      host:     $('input.configKey[data-l1key="okofen_host"]').val(),
      port:     $('input.configKey[data-l1key="okofen_port"]').val(),
      password: $('input.configKey[data-l1key="okofen_password"]').val()
    },
    dataType: 'json',
    error: function (request, status, error) {
      $('#testOkofenResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + error + '</span>');
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#testOkofenResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + data.result + '</span>');
      } else {
        var msg = '{{Composants détectés}} : ' + data.result.components;
        if (data.result.daemonStarted) {
          msg += ' — <strong>{{Démon démarré automatiquement}}</strong>';
        }
        $('#testOkofenResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> ' + msg + '</span>');
      }
    }
  });
});

$('#bt_debugDump').off('click').on('click', function () {
  $('#debugDumpResult').html('<i class="fas fa-spinner fa-spin"></i> {{Génération en cours…}}');
  // Téléchargement via POST de formulaire caché vers un endpoint qui renvoie le fichier en
  // Content-Disposition. La CSP de Jeedom bloque les URL blob: → on passe par un vrai
  // téléchargement HTTP. Le POST ne fait pas naviguer la page (réponse = pièce jointe).
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = 'plugins/myokotouch/core/php/debugReport.php';
  form.style.display = 'none';
  var fields = {
    host:     $('input.configKey[data-l1key="okofen_host"]').val(),
    port:     $('input.configKey[data-l1key="okofen_port"]').val(),
    password: $('input.configKey[data-l1key="okofen_password"]').val()
  };
  for (var k in fields) {
    var inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = k;
    inp.value = fields[k] || '';
    form.appendChild(inp);
  }
  document.body.appendChild(form);
  form.submit();
  setTimeout(function () { if (form.parentNode) { form.parentNode.removeChild(form); } }, 2000);
  setTimeout(function () {
    $('#debugDumpResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> {{Fichier de diagnostic généré (voir vos téléchargements)}}</span>');
  }, 1500);
});
</script>
