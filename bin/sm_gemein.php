<?php
/**
 * Smartmeter classic - was Oberflaeche, Abholer und Endpunkt gemeinsam haben
 *
 * Diese Datei liegt unter bin/, weil sie aus DREI Baeumen erreichbar sein
 * muss: webfrontend/htmlauth (Oberflaeche), webfrontend/html (Endpunkt fuer
 * den Miniserver) und bin (Abholer aus dem Cron). Auf dem installierten
 * LoxBerry liegen die drei in getrennten Baeumen; ein require ueber eine
 * feste Zahl von ".." trifft nur den Archivfall.
 *
 * Sie ist KEIN Endpunkt und tut beim Einbinden nichts ausser Funktionen zu
 * definieren.
 *
 * Warum es sie gibt: die Altersgrenze eines Messwertes hat zwei Verbraucher
 * - den Endpunkt, der OK und ALTER an Loxone liefert, und den Reiter Test,
 * der dieselbe Frage beantwortet. Eine Grenze mit zwei Verbrauchern steht in
 * EINER Funktion; zwei Ausschreibungen mit einem Kommentar, der auf die
 * andere verweist, sind die Bauart, aus der Widersprueche entstehen.
 *
 * Alle Namen tragen das Kuerzel smg_ - die Datei landet mit sm_lib.php im
 * selben Prozess, und zwei gleichnamige Funktionen sind dort kein
 * Namensraum-Problem, sondern ein "Cannot redeclare" beim Start.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

if (!function_exists('smg_cfg_lesen')) {

/**
 * smartmeter.cfg abschnittsweise lesen.
 *
 * Nicht mit parse_ini_file(): die Datei fuehrt frei vergebene Bezeichnungen
 * der Lesekoepfe. Ein "&", "(" oder "!" darin laesst parse_ini_file() die
 * GANZE Datei verwerfen - dann waere ploetzlich kein Token gesetzt und der
 * Endpunkt stuende offen.
 */
function smg_cfg_lesen($datei)
{
    $alles = array();
    if (!is_readable($datei)) {
        return $alles;
    }
    $abschnitt = 'MAIN';
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES) as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === ';' || $z[0] === '#') {
            continue;
        }
        if ($z[0] === '[' && substr($z, -1) === ']') {
            $abschnitt = substr($z, 1, -1);
            if (!isset($alles[$abschnitt])) { $alles[$abschnitt] = array(); }
            continue;
        }
        $pos = strpos($z, '=');
        if ($pos === false) {
            continue;
        }
        $alles[$abschnitt][trim(substr($z, 0, $pos))] = trim(substr($z, $pos + 1));
    }
    return $alles;
}

function smg_wert($alles, $abschnitt, $schluessel, $vorgabe = '')
{
    return (isset($alles[$abschnitt][$schluessel]) && $alles[$abschnitt][$schluessel] !== '')
        ? $alles[$abschnitt][$schluessel] : $vorgabe;
}

/** Der Abstand zweier Abfragen in Sekunden - 0 heisst "nur beim Systemstart". */
function smg_takt_sekunden($takt)
{
    $takt = (string) $takt;
    if ($takt === 'M' || $takt === '') {
        return 0;
    }
    return preg_match('/^[0-9]+$/', $takt) ? ((int) $takt) * 60 : 0;
}

/**
 * Ab wann gilt ein Messwert als zu alt?
 *
 * Deutlich ueber dem Takt, damit ein einzelner verpasster Durchlauf nichts
 * ausloest - und mindestens 300 s, weil der vzLogger-Weg fest im
 * Minutentakt laeuft.
 *
 * 0 heisst: es gibt keine Grenze. Ueber eine Betriebsart, die absichtlich
 * nur beim Systemstart liest, wird kein Altersurteil gefaellt - ein Wert,
 * der alt sein SOLL, ist kein Befund.
 */
function smg_alter_grenze($cron, $vz_an)
{
    if ($vz_an) {
        return 300;
    }
    $t = smg_takt_sekunden($cron);
    if ($t <= 0) {
        return 0;
    }
    return max(300, 5 * $t);
}

/**
 * Einen Wert fuer das UDP-Relais des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE und trennt Thema und Wert am Leerraum. Ein
 * Zeilenumbruch im Wert zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen.
 *
 * Bis 2.3.14 hatte diese Saeuberung nur bin/fetch.php; bin/fetch_vzlogger.pl
 * schickte den Wert roh - und Last_Update ist ein Text mit Leerzeichen.
 * Zwei Wege, die dieselben Werte tragen sollen, behandeln sie gleich.
 */
function smg_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/** Den Katalog bin/sm_felder.json lesen. Leeres Feld, wenn es ihn nicht gibt. */
function smg_katalog($datei)
{
    $leer = array('obis' => array(), 'felder' => array());
    if (!is_readable($datei)) {
        return $leer;
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['felder']) || !is_array($d['felder'])) {
        return $leer;
    }
    return array(
        'obis'   => (isset($d['obis']) && is_array($d['obis'])) ? $d['obis'] : array(),
        'felder' => $d['felder'],
    );
}

/**
 * Den umlaufenden Zaehler eine Stelle weiterdrehen.
 *
 * 0..999, danach wieder 0. Er liegt auf der Ramdisk neben den Datendateien:
 * dass er nach einem Neustart bei -1 beginnt, ist die richtige Aussage.
 *
 * Warum ein Zaehler und nicht nur ein Zeitstempel: ein Raspberry Pi hat
 * keine Echtzeituhr. Nach dem Booten steht er in der Vergangenheit, und
 * sobald NTP greift, springt die Zeit - ein Alter in Sekunden wird dann
 * negativ und meldet nach max(0, ...) "gerade eben gemessen".
 */
function smg_zaehler_weiter($datei)
{
    $alt = -1;
    if (is_file($datei)) {
        $w = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9]{1,3}$/', $w)) {
            $alt = (int) $w;
        }
    }
    $neu = ($alt < 0) ? 0 : (($alt + 1) % 1000);
    $tmp = $datei . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, (string) $neu) !== false) {
        if (!@rename($tmp, $datei)) {
            @unlink($tmp);
        }
    }
    return $neu;
}

/** Den Stand des Zaehlers lesen. -1 heisst "noch nie gelaufen". */
function smg_zaehler_lesen($datei)
{
    if (!is_file($datei)) {
        return -1;
    }
    $w = trim((string) @file_get_contents($datei));
    return preg_match('/^[0-9]{1,3}$/', $w) ? (int) $w : -1;
}

}
