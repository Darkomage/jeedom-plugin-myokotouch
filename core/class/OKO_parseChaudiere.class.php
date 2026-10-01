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

/**
 * Client HTTP pour l'API JSON Okofen (firmware Pelletronic Touch).
 *
 * API Okofen :
 *   Lecture  : http://{host}:{port}/{password}/{component}?   (avec ? = métadonnées)
 *   Écriture : http://{host}:{port}/{password}/{component}.{var}={val}
 *   Délai minimum entre requêtes : 2500 ms (imposé par la chaudière)
 */
class OKO_parseChaudiere
{
    /**
     * Composants fixes (sans numéro) et composants numérotés (prefix => max).
     */
    private static $COMPONENT_PATTERNS = [
        'system', 'weather', 'forecast', 'error', 'stirling', 'power',
        'hk'         => 6,
        'ww'         => 3,
        'pe'         => 4,
        'circ'       => 3,
        'pu'         => 3,
        'sk'         => 6,
        'se'         => 3,
        'wireless'   => 6,
        'thirdparty' => 10,
    ];

    /**
     * Construit la liste complète des noms de composants attendus.
     */
    private static function buildKnownComponents(): array
    {
        $known = [];
        foreach (self::$COMPONENT_PATTERNS as $key => $val) {
            if (is_int($key)) {
                $known[] = $val; // composant fixe (ex: 'system')
            } else {
                for ($i = 1; $i <= $val; $i++) {
                    $known[] = $key . $i;
                }
            }
        }
        return $known;
    }

    /**
     * Construit l'URL de base : http://{host}:{port}/{password}
     */
    public static function buildUrl(string $host, int $port, string $password): string
    {
        return 'http://' . $host . ':' . $port . '/' . $password;
    }

    /**
     * Exécute une requête HTTP GET avec timeout 5s et retourne le corps brut + le Content-Type.
     * Utilise cURL pour préserver le '?' final dans les URLs (file_get_contents le supprime,
     * ce qui prive l'API Okofen des métadonnées text/factor/unit).
     * Lève une Exception en cas d'échec.
     *
     * @return array{body: string, content_type: string} corps brut (octets bruts, non convertis)
     */
    public static function httpGetRaw(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPGET        => true,
        ]);
        $result      = curl_exec($ch);
        $err         = curl_error($ch);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($result === false) {
            throw new Exception('Okofen HTTP error: ' . $err);
        }
        return ['body' => $result, 'content_type' => $contentType];
    }

    /**
     * Exécute une requête HTTP GET et retourne le corps brut (string).
     * Conservé pour compatibilité avec les appelants existants.
     */
    public static function httpGet(string $url): string
    {
        return self::httpGetRaw($url)['body'];
    }

    /**
     * Normalise un corps HTTP en UTF-8 valide.
     * Le firmware Okofen peut servir du Latin-1 (ou ne pas déclarer son charset) : sans cette
     * conversion, json_decode échoue ou les caractères spéciaux (ex : °C) se corrompent en ?C.
     */
    public static function toUtf8(string $body, ?string $contentType): string
    {
        $charset = null;
        if ($contentType && preg_match('/charset=([^\s;]+)/i', $contentType, $m)) {
            $charset = strtoupper(trim($m[1]));
        }
        if ($charset && $charset !== 'UTF-8') {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            // Charset non déclaré et octets non-UTF-8 valides : repli défensif Latin-1.
            return mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        return $body;
    }

    /**
     * Récupère toutes les données avec métadonnées : GET /all?
     */
    public static function fetchAll(string $host, int $port, string $password): array
    {
        $url = self::buildUrl($host, $port, $password) . '/all?';
        $raw = self::httpGetRaw($url);
        $body = self::toUtf8($raw['body'], $raw['content_type']);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('Okofen: réponse JSON invalide pour /all?');
        }
        return $data;
    }

    /**
     * Détecte les composants présents dans une réponse all?,
     * en ne gardant que ceux qui correspondent aux patterns connus.
     */
    public static function detectComponents(array $allData): array
    {
        $knownSet = array_flip(self::buildKnownComponents());
        $detected = [];
        foreach (array_keys($allData) as $key) {
            if (isset($knownSet[$key])) {
                $detected[] = $key;
            }
        }
        return $detected;
    }

    /**
     * Applique le facteur de conversion Okofen sur une valeur entière.
     * Ex : applyFactor(493, 0.1) = 49.3
     */
    public static function applyFactor($val, $factor): float
    {
        if (!is_numeric($factor) || $factor == 0) {
            return (float) $val;
        }
        return round((float) $val * (float) $factor, 4);
    }
}
