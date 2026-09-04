<?php
/**
 * Smartmeter classic - der Lastgang als JSON
 *
 * WOZU
 *
 * Die Spotpreis-Plugins dieses Kontos (aWATTar, Octopus, Tibber) gewichten
 * ihren Tarifvergleich mit einem eingebauten HAUSHALTSPROFIL, weil ihnen
 * niemand sagt, wann wirklich verbraucht wurde. Ihr spot_lastgang() holt
 * dafuer stuendliche Werte von einer frei einstellbaren JSON-Adresse. Diese
 * Datei ist diese Adresse.
 *
 * Damit wird aus dem Tarifvergleich eine Messung statt einer Modellrechnung -
 * und zwar ohne dass hier eine zweite Preisquelle entsteht: die Preise
 * bleiben beim Spotpreis-Plugin, die Menge kommt von hier. Jede Sache
 * einmal.
 *
 * DAS FORMAT
 *
 * Gemessen an plan_pv_lesen() in Spotpreis-aWATTar 1.2.20: die Bauform
 * "objekt" erwartet unter einem Pfad ein Feld Zeit => Wert. Die Zeit darf
 * eine Unix-Sekunde sein, der Wert eine Zahl; die Einheit stellt der
 * Anwender dort ein (wh oder kwh).
 *
 *     {"einheit":"wh","stunden":{"1756000000":812.5, ...},"...":...}
 *
 * Im Spotpreis-Plugin einzutragen:
 *     Quelle   objekt
 *     Pfad     stunden
 *     Einheit  wh
 *
 * WAS NICHT AUSGELIEFERT WIRD
 *
 * Stunden, deren Einheit nicht belegt ist. Auf dem vzLOGGER-Weg reicht
 * vzlogger den Wert des Zaehlers ungewandelt durch, und ob das Wh oder kWh
 * sind, ist nicht gemessen (einheit_vz im Katalog ist leer). Solche Stunden
 * traegt die Historie mit "?" - sie werden hier UEBERGANGEN und in
 * "offen" gezaehlt. Eine Zahl mit geratener Einheit waere in einer
 * Kostenrechnung um den Faktor 1000 daneben, und niemand saehe es.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Expires: 0');
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function sm_lg_pluginordner()
{
    $ordner = basename(dirname(__FILE__));
    // NICHT "smartmeter": so heisst der Ordner des Originalplugins, das
    // neben diesem installiert sein kann.
    return ($ordner === '' || $ordner === 'html') ? 'smartmeter-classic' : $ordner;
}

function sm_lg_ende($code, $grund)
{
    http_response_code($code);
    echo json_encode(array('ok' => 0, 'grund' => $grund, 'stunden' => new stdClass()));
    echo "\n";
    exit;
}

$sm_home = getenv('LBHOMEDIR');
if (!$sm_home || !is_dir($sm_home)) { $sm_home = lb_wurzel_ermitteln(); }
$sm_ordner = sm_lg_pluginordner();

/* Der gemeinsame Vorlauf - Kandidatenliste wie im Endpunkt, weil html/ und
 * bin/ auf dem installierten LoxBerry in getrennten Baeumen liegen. Die
 * durchsuchten Pfade gehen ins Fehlerprotokoll des Webservers, NICHT in die
 * Antwort: hier hat sich der Aufrufer noch nicht ausgewiesen. */
$sm_kandidaten = array(
    ($sm_home !== '' ? $sm_home . '/bin/plugins/' . $sm_ordner . '/sm_gemein.php' : ''),
    dirname(dirname(dirname(dirname(__DIR__)))) . '/bin/plugins/' . $sm_ordner . '/sm_gemein.php',
    dirname(dirname(__DIR__)) . '/bin/sm_gemein.php',
);
$sm_gefunden = false;
foreach ($sm_kandidaten as $sm_k) {
    if ($sm_k !== '' && is_file($sm_k)) { require_once $sm_k; $sm_gefunden = true; break; }
}
if (!$sm_gefunden) {
    error_log('Smartmeter classic (lastgang): sm_gemein.php nicht gefunden, gesucht in: '
        . implode(', ', array_filter($sm_kandidaten)));
    sm_lg_ende(500, 'BIBLIOTHEK_FEHLT');
}
if ($sm_home === '') { sm_lg_ende(500, 'KEINE_WURZEL'); }

$sm_cfgdatei = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$sm_cfg = smg_cfg_lesen($sm_cfgdatei);

/* ---------------- Token, falls eingerichtet ----------------
 *
 * Dasselbe Token und dieselbe Bauform wie im Datenendpunkt: aus $_GET, nie
 * aus $_REQUEST (dort haette ein Cookie namens "token" die Pruefung
 * gefuettert), Vergleich mit hash_equals, der leere Fall vorher abgefangen.
 *
 * Freiwillig ist es wie dort auch - ein Pflichttoken risse jeden
 * bestehenden Aufbau ab. */
$sm_soll = smg_wert($sm_cfg, 'MAIN', 'TOKEN', '');
$sm_ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
if ($sm_soll !== '' && !(($sm_ist !== '') && hash_equals($sm_soll, $sm_ist))) {
    sm_lg_ende(403, 'TOKEN');
}

/* ---------------- Wieviele Stunden ----------------
 *
 * Vorgabe 48: das Spotpreis-Plugin braucht die 24 Stunden des heutigen
 * Tages und prueft, wieviele davon gedeckt sind. 48 deckt den Tageswechsel
 * ab, ohne die Antwort aufzublaehen. Die Obergrenze steht, damit ein
 * "?tage=9999" nicht die ganze Historie durch den Webserver schiebt. */
$sm_stunden = 48;
if (isset($_GET['stunden']) && is_string($_GET['stunden'])
    && preg_match('/^[0-9]{1,5}$/', $_GET['stunden'])) {
    $sm_stunden = max(1, min(17520, (int) $_GET['stunden']));
}

/* Welche Groesse? Vorgabe ist der Bezug - danach fragt ein Tarifvergleich.
 * Abgewiesen wird, was nicht in der Liste steht; zurechtgebogen nichts. */
$sm_art = 'bezug';
if (isset($_GET['art']) && is_string($_GET['art'])) {
    if (!in_array($_GET['art'], array('bezug', 'einspeisung'), true)) {
        sm_lg_ende(400, 'ART_UNBEKANNT');
    }
    $sm_art = $_GET['art'];
}

$sm_hist = $sm_home . '/data/plugins/' . $sm_ordner . '/historie.csv';
if (!is_readable($sm_hist)) {
    /* Kein Fehler des Aufrufers: die Historie entsteht erst, wenn der
     * Leser zweimal gelaufen ist. Die Antwort sagt das, statt eine leere
     * Liste als Messung auszugeben. */
    sm_lg_ende(503, 'KEINE_HISTORIE');
}

$sm_ab = time() - $sm_stunden * 3600;
$sm_werte = array();      // stunde => Summe ueber alle Zaehlernummern
$sm_offen = 0;            // Stunden, deren Einheit nicht belegt ist
$sm_zeilen = 0;

$sm_fh = @fopen($sm_hist, 'rb');
if ($sm_fh === false) { sm_lg_ende(500, 'HISTORIE_UNLESBAR'); }
while (($sm_z = fgets($sm_fh)) !== false) {
    $sm_z = trim($sm_z);
    if ($sm_z === '' || strncmp($sm_z, 'stunde;', 7) === 0) { continue; }
    $sm_f = explode(';', $sm_z);
    if (count($sm_f) < 7) { continue; }
    $sm_st = (int) $sm_f[0];
    if ($sm_st < $sm_ab) { continue; }
    $sm_zeilen++;
    // Nur belegte Einheiten. "?" heisst gemessen, aber nicht umgerechnet.
    if ($sm_f[4] !== 'Wh') { $sm_offen++; continue; }
    $sm_w = ($sm_art === 'bezug') ? (float) $sm_f[2] : (float) $sm_f[3];
    /* Mehrere Lesekoepfe werden ADDIERT. Wer zwei Zaehlpunkte hat, will im
     * Tarifvergleich die Summe - und wer nur einen hat, merkt davon
     * nichts. */
    $sm_werte[$sm_st] = (isset($sm_werte[$sm_st]) ? $sm_werte[$sm_st] : 0.0) + $sm_w;
}
fclose($sm_fh);
ksort($sm_werte);

/* Wieviele der 24 Stunden des heutigen Tages sind gedeckt? Genau danach
 * entscheidet spot_lastgang(), ob es die Messung nimmt oder sein Profil -
 * die Zahl steht deshalb in der Antwort, damit sie sich hier nachsehen
 * laesst statt nur dort. */
$sm_tagesbeginn = strtotime('today');
$sm_heute = 0;
foreach ($sm_werte as $sm_st => $sm_w) {
    if ($sm_st >= $sm_tagesbeginn && $sm_st < $sm_tagesbeginn + 86400) { $sm_heute++; }
}

$sm_aus = array(
    'ok'        => 1,
    'einheit'   => 'wh',
    'art'       => $sm_art,
    'stunden'   => $sm_werte ? $sm_werte : new stdClass(),
    'anzahl'    => count($sm_werte),
    'heute'     => $sm_heute,
    'offen'     => $sm_offen,
    'gelesen'   => $sm_zeilen,
    'ts'        => time(),
);
/* JSON_PRESERVE_ZERO_FRACTION gibt es erst ab 5.6.6 - hier ohne Belang,
 * aber die Schluessel muessen Zeichenketten bleiben: PHP macht aus einem
 * numerischen Feldschluessel sonst ein JSON-ARRAY, und plan_pv_lesen()
 * erwartet ein Objekt Zeit => Wert. Genau diese Falle hat in diesem Haus
 * schon einmal ein Auswahlfeld leergeraeumt. */
echo json_encode($sm_aus, JSON_FORCE_OBJECT);
echo "\n";
exit(0);
