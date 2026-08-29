<?php
/**
 * Smartmeter classic - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Das Plugin kennt zwei Lesewege, die sich dieselbe serielle Schnittstelle
 * teilen: vzLogger (modern) und den Legacy-Leser mit Zaehlerprofilen. Beide
 * senden ueber dieselben MQTT- und UDP-Einstellungen.
 */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
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

function sm_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $home = $home ? $home : lb_wurzel_ermitteln();
    // Den Pluginordner aus dem eigenen Ablageort ableiten statt ihn fest
    // einzutragen.
    $ordner = basename(dirname(__FILE__));
    if ($ordner === '' || $ordner === 'htmlauth') {
        // NICHT "smartmeter": so heisst der Ordner des Originalplugins, das
        // neben diesem installiert sein kann. Der Rueckfall zeigte damit auf
        // dessen Konfiguration, dessen /dev/shm und dessen Protokoll.
        $ordner = 'smartmeter-classic';
    }
    $p = array(
        'home'    => $home,
        'plugin'  => $ordner,
        'config'  => $home . '/config/plugins/' . $ordner,
        'vzjson'  => $home . '/config/plugins/' . $ordner . '/vzlogger.json',
        'vzconf'  => $home . '/config/plugins/' . $ordner . '/vzlogger.conf',
        'legacy'  => $home . '/config/plugins/' . $ordner . '/smartmeter.cfg',
        'bin'     => $home . '/bin/plugins/' . $ordner,
        'datadir'    => $home . '/data/plugins/' . $ordner,
        'log'     => $home . '/log/plugins/' . $ordner,
        'logdatei' => $home . '/log/plugins/' . $ordner . '/smartmeter.log',
        'shm'     => '/dev/shm/' . $ordner,
        'vzlog'   => '/dev/shm/' . $ordner . '/vzlogger.log',
        'fetchlog' => '/dev/shm/' . $ordner . '/vzlogger_fetch.log',
        'zaehler' => '/dev/shm/' . $ordner . '/zaehler',
        'general' => $home . '/config/system/general.json',
    );
    return $p;
}

function sm_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Sprache
 *
 * Bis 2.3.2 lagen zwar templates/lang/language_de.ini und _en.ini im
 * Plugin, gelesen hat sie aber niemand: die Oberflaeche schrieb ihre
 * Texte unmittelbar auf Deutsch ins HTML. Zwanzig Schluessel standen in
 * den Dateien, keiner davon kam im Programm vor - eine begonnene und nie
 * zu Ende gefuehrte Uebersetzung.
 *
 * Seit 2.3.2 geht jeder sichtbare Text durch sm_t(). Englisch ist die
 * Rueckfallebene: fehlt ein Schluessel in der gewaehlten Sprache, wird
 * der englische genommen; fehlt auch der, kommt der Schluesselname
 * selbst heraus. Das ist Absicht - eine leere Seite verschweigt den
 * Fehler, ein sichtbares "VZ.LABEL_DEVICE" nicht.
 * ================================================================== */

function sm_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'. */
function sm_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = sm_paths();
        // Installiert liegen die Sprachdateien unter
        // <home>/templates/plugins/<ordner>/lang/. Im ausgepackten Archiv
        // (Entwicklung) liegen sie neben dem Plugin.
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . sm_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW gibt die Werte samt der Anfuehrungszeichen zurueck,
        // in die sie in der Datei stehen muessen. Die gehoeren nicht in die
        // Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

/** Einen Befehl ausfuehren und die Ausgabe zurueckgeben. */
function sm_sh($befehl)
{
    $aus = array(); $code = 0;
    @exec($befehl . ' 2>&1', $aus, $code);
    return array($code, implode("\n", $aus));
}

/* ==================================================================
 * Protokoll
 *
 * Bis 2.3.14 schrieb nur daemon/daemon nach log/plugins/<ordner>/, also
 * einmal beim Systemstart. Alles andere ging auf die Ramdisk unter
 * /dev/shm. Der Reiter Logdateien zeigt LBWeb::loglist_html(), und der
 * sieht log/plugins - dort stand ausser dem Startprotokoll nichts.
 * Wer ein Protokoll anzeigt, muss es auch schreiben.
 * ================================================================== */

/** Die letzten Zeilen einer Datei, rueckwaerts gelesen.
 *
 * Nicht die ganze Datei einlesen und nicht exec("tail"): an 12 000 Zeilen
 * gemessen ist der Weg ueber fseek rund vierzigmal schneller als file() und
 * kostet keinen Prozessstart. Muster aus VORLAGE_hausstandard.css.html.
 */
function sm_log_ende($datei, $anzahl = 400, $block = 8192)
{
    // Erst fragen, dann oeffnen. Ein @fopen auf eine fehlende Datei ist
    // stumm, aber ein gesetzter Fehlerbehandler sieht die Warnung trotzdem -
    // und vor dem ersten Start gibt es die Datei regelmaessig nicht.
    if (!is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/**
 * Eine Zeile ins Plugin-Protokoll.
 *
 * Mit Kappung ueber smg_log_kappen() aus bin/sm_gemein.php (ab 500 kB
 * bleiben die letzten 200 Zeilen) - log/plugins liegt auf einer Ramdisk,
 * eine wachsende Datei frisst dort Arbeitsspeicher, nicht Plattenplatz.
 *
 * Nicht zu verwechseln mit sm_fetch_log() in bin/fetch.php: die schreibt
 * das fluechtige /dev/shm/<ordner>/fetch.log DIESES einen Laufs. Bis
 * 2.4.1 hiessen beide sm_log() bei verschiedenen Rumpfen - auf dem
 * Geraet getrennte Baeume, im entpackten Archiv zwei Wahrheiten unter
 * einem Namen.
 */
function sm_log($text, $stufe = 'INFO')
{
    $p = sm_paths();
    $datei = $p['logdatei'];
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    // Faellt der gemeinsame Vorlauf aus - bin/sm_gemein.php wird unten
    // ueber eine Kandidatenliste gesucht -, wird nur nicht gekappt; die
    // Zeile selbst geht trotzdem hinaus. Denselben Rueckfall nimmt
    // sm_zaehler_lesen(), und die Selbstpruefung im Reiter Test meldet
    // den Ausfall.
    if (function_exists('smg_log_kappen')) {
        smg_log_kappen($datei);
    }
    @file_put_contents($datei,
        date('Y-m-d H:i:s') . ' <' . $stufe . '> ' . $text . "\n", FILE_APPEND);
}

/**
 * Dieselbe Meldung hoechstens einmal je Stunde.
 *
 * Ohne Bremse schreibt eine Dauerstoerung die Datei voll, und die eine
 * Zeile, auf die es ankommt, geht darin unter. Der Merker wird
 * zurueckgesetzt, sobald die Protokolldatei fehlt - sonst unterdrueckt die
 * Bremse ausgerechnet die erste Zeile in einer frisch geleerten Datei.
 */
function sm_log_wenn_neu($marke, $text, $stufe = 'INFO', $sekunden = 3600)
{
    $p = sm_paths();
    if (!is_dir($p['shm'])) {
        @mkdir($p['shm'], 0775, true);
    }
    $merker = $p['shm'] . '/.meld_' . preg_replace('/[^A-Za-z0-9_]/', '_', $marke);
    if (!is_file($p['logdatei'])) {
        @unlink($merker);
    }
    $letzt = is_file($merker) ? (int) @file_get_contents($merker) : 0;
    if (time() - $letzt < $sekunden) {
        return false;
    }
    @file_put_contents($merker, (string) time());
    sm_log($text, $stufe);
    return true;
}

/* ==================================================================
 * Schreiben: Rechte vor dem Inhalt, Nebendatei mit Prozessnummer
 *
 * "Schreiben, dann chmod" laesst die Datei fuer die Dauer des Schreibens
 * mit den Vorgaben der umask stehen. smartmeter.cfg enthaelt das
 * Zugriffstoken - das ist der Unterschied zwischen "kurz lesbar" und
 * "nie lesbar". Und die Nebendatei traegt die PID: schreiben Oberflaeche
 * und Dienst gleichzeitig, ueberschreibt sonst einer die Nebendatei des
 * anderen, und umbenannt wird eine Mischung.
 * ================================================================== */
function sm_atomar_schreiben($pfad, $inhalt, $rechte = null)
{
    $tmp = $pfad . '.tmp.' . getmypid();
    if (!is_dir(dirname($pfad))) {
        @mkdir(dirname($pfad), 0775, true);
    }
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $ok = ftruncate($fh, 0);
    // Nicht auf === false pruefen: eine KURZE Schreibung ist genauso kaputt
    // wie gar keine, meldet sich aber nicht als Fehler.
    $ok = $ok && (fwrite($fh, $inhalt) === strlen($inhalt));
    fflush($fh);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** JSON schreiben - erst kodieren, dann den Rueckgabewert ansehen, dann schreiben. */
function sm_json_schreiben($pfad, $daten, $rechte = null)
{
    $z = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // json_encode gibt bei ungueltigem UTF-8 false zurueck. Daraus macht
    // file_put_contents einen Leerstring, schreibt null Byte und meldet mit
    // der 0 einen Erfolg.
    if ($z === false) {
        return false;
    }
    return sm_atomar_schreiben($pfad, $z, $rechte);
}

/**
 * Einen Wert fuer das UDP-Relais des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE und trennt Thema und Wert am Leerraum. Ein
 * Zeilenumbruch im Wert zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen.
 *
 * Diese Funktion gibt es in derselben Form in bin/fetch.php und in
 * bin/fetch_vzlogger.pl. Bis 2.3.14 hatte sie nur der klassische Weg - zwei
 * Wege, die dieselben Werte tragen sollen, behandeln sie gleich.
 */
function sm_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/* ==================================================================
 * Die EINE Quelle: Feldnamen, Einheiten, Bedeutungen
 *
 * bin/sm_felder.json wird von PHP UND von Perl gelesen. Erzeugt wird sie
 * von Werkzeuge/sm_felder_erzeugen.py aus dem Quelltext selbst - die
 * Schluesselmenge stammt aus bin/sm_logger.pl, die OBIS-Zuordnung aus der
 * Tabelle %SM_NAME in bin/fetch_vzlogger.pl.
 *
 * Warum es sie gibt: am 26.08.2026 wurde gemessen, dass die erzeugte
 * Loxone-Vorlage den Titel smartmeter_vzlogger_1_0_1_8_0 anlegte, waehrend
 * der Dienst unter smartmeter/vzlogger/Consumption_Total_OBIS_1.8.0
 * veroeffentlichte. Kein einziger der drei erzeugten Eingaenge konnte je
 * einen Wert bekommen.
 * ================================================================== */

function sm_felder_datei()
{
    $p = sm_paths();
    foreach (array($p['bin'] . '/sm_felder.json',
                   dirname(dirname(dirname(__FILE__))) . '/bin/sm_felder.json') as $k) {
        if (is_readable($k)) {
            return $k;
        }
    }
    return '';
}

/** Der ganze Katalog. Leeres Feld, wenn die Datei fehlt - der Aufrufer sagt das. */
function sm_katalog()
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    // Gelesen wird der Katalog von smg_katalog() aus bin/sm_gemein.php -
    // derselben Stelle, aus der ihn healthcheck und der unangemeldete
    // Endpunkt holen. Hier stand bis 2.3.14 eine zweite, wortgleiche
    // Auswertung desselben JSON. Zwei Leser derselben Datei laufen genau
    // so lange gleich, bis einer von beiden gepflegt wird.
    $k = array('obis' => array(), 'felder' => array());
    $datei = sm_felder_datei();
    if ($datei !== '' && function_exists('smg_katalog')) {
        $k = smg_katalog($datei);
    }
    return $k;
}

/** Die Angaben zu einem Feld - oder null, wenn der Katalog es nicht kennt. */
function sm_feld($name)
{
    $k = sm_katalog();
    return isset($k['felder'][$name]) ? $k['felder'][$name] : null;
}

/**
 * OBIS-Kennzahl -> Feldname, genau so wie bin/fetch_vzlogger.pl es tut.
 *
 * Medienkennung "1-0:" und Vorschrift "*255" fallen weg; was die Tabelle
 * nicht kennt, wird "OBIS_<kurz>". Diese beiden Zeilen stehen dort ebenso -
 * sie sind der Grund, warum die Zuordnung in eine gemeinsame Datei gehoert
 * und nicht in zwei Sprachen nachgebaut wird.
 */
function sm_obis_feld($obis)
{
    $kurz = preg_replace('/^\d+-\d+:/', '', (string) $obis);
    $kurz = preg_replace('/\*\d+$/', '', $kurz);
    $k = sm_katalog();
    return isset($k['obis'][$kurz]) ? $k['obis'][$kurz] : 'OBIS_' . $kurz;
}

/** Das MQTT-Thema eines Wertes. */
function sm_thema($praefix, $serial, $feld)
{
    return trim((string) $praefix, '/') . '/' . $serial . '/' . $feld;
}

/**
 * Der Name, unter dem das MQTT-Gateway den virtuellen Eingang anlegt.
 *
 * Das Gateway ersetzt in Themen NUR / und % durch _; Punkte bleiben stehen.
 * Bis 2.3.14 ersetzte die Vorlage zusaetzlich : - und . - damit traf kein
 * einziger Titel.
 */
function sm_ve_name($praefix, $serial, $feld)
{
    return str_replace(array('/', '%'), '_', sm_thema($praefix, $serial, $feld));
}

/**
 * Die Loxone-Befehlserkennung fuer einen Wert.
 *
 * Die Zeilen des UDP-Satzes und des HTTP-Endpunkts haben dieselbe Form:
 *     <serial>:<Feldname>:<Wert>
 * Der Feldname steht damit hinter einem Doppelpunkt und vor einem
 * Doppelpunkt - er kann nicht in einem anderen Feldnamen stecken. Die
 * Pruefzeile im Reiter Test misst das trotzdem nach; sie kostet nichts und
 * faengt das sechsundsechzigste Feld ab.
 */
function sm_check($serial, $feld)
{
    return '\i' . $serial . ':' . $feld . ':\i\v';
}

/* ==================================================================
 * MQTT-Gateway: Zustand UND Fassung
 * ================================================================== */

/**
 * Zustand und FASSUNG des LoxBerry-MQTT-Gateways.
 *
 * Die Fassung steht als Mqtt.Gatewayversion in general.json (ab Werk 1). Sie
 * entscheidet, was der Anwender eintragen muss:
 *   V1  Das Abo wird von Hand eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Der Kern schaltet auf der Abonnement-Seite die Knoepfe ab; die
 *       Themengruppe erscheint dort, und es werden nur noch die gewuenschten
 *       Datenpunkte angehakt.
 *
 * Rueckgabe: null, wenn general.json nicht lesbar ist - sonst ein Feld mit
 * autostart (bool) und fassung (int, 0 = unbekannt).
 *
 * 0 ist NICHT auf 1 vorbelegt: "unbekannt" und "Fassung 1" sind verschiedene
 * Aussagen, und die Oberflaeche behandelt sie verschieden.
 *
 * Vorbild: mg_mqtt_gateway_info() aus MG iSmart 1.1.0.
 */
function sm_mqtt_gateway_info()
{
    static $m = null;
    if ($m !== null) {
        return $m === false ? null : $m;
    }
    $p = sm_paths();
    $m = false;
    if ($p['home'] === '' || !is_readable($p['general'])) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) {
        return null;
    }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    $m = array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0,
        'udpin'     => isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0,
    );
    return $m;
}

/** Hausstandard: nur der Autostart. Eine Huelle um dieselbe Quelle, kein zweiter Weg. */

/**
 * Der Abo-Hinweis in der Fassung, die zum Gateway passt.
 *
 * ES GIBT GENAU DIESE EINE STELLE. Der Satz "Ohne diesen Eintrag kommt am
 * Miniserver nichts an" stand in diesem Plugin an ZWEI Stellen unbedingt da:
 * im Reiter MQTT und in Schritt 2 des Reiters Einbindung in Loxone. Wer nur
 * eine davon an die Fassung haengt, hat unter Gateway V2 beide Texte auf der
 * Seite - genau das ist dem Vorbild MG iSmart 1.1.0 passiert
 * (MQTTR.ABO_HINWEIS blieb unbedingt) und wurde dort am 25.08.2026
 * berichtigt. Gefunden hat es kein Lesen, sondern
 * Werkzeuge/gateway_wirkung.py an der GERENDERTEN Seite.
 *
 * Die Rueckgabe ist ROH auszugeben, nicht durch sm_e(): sie traegt
 * Auszeichnung. Ein maskierter Hinweis zeigt dem Anwender die spitzen
 * Klammern - auch das misst das Werkzeug.
 *
 * Drei Ausgaenge, nicht zwei. Ist die Fassung nicht lesbar, stehen BEIDE
 * Faelle da: einen von beiden zu behaupten waere fuer die Haelfte der
 * Anlagen falsch.
 */
function sm_abo_text()
{
    $g = sm_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    if ($f >= 2) {
        return sm_t('MQ.ABO_V2');
    }
    if ($f === 1) {
        return sm_t('MQ.ABO_PFLICHT');
    }
    return sm_t('MQ.ABO_UNBEKANNT')
         . '<br><br>' . sm_t('MQ.ABO_PFLICHT')
         . '<br><br>' . sm_t('MQ.ABO_V2');
}

/** Die Klasse des Kastens, in dem der Abo-Hinweis steht. */
function sm_abo_klasse()
{
    $g = sm_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    // V2 verlangt keine Handarbeit - das ist ein Hinweis, keine Warnung.
    return $f >= 2 ? 'sm-alert sm-info' : 'sm-alert sm-warn';
}

/* ==================================================================
 * vzLogger-Konfiguration (JSON)
 * ================================================================== */

function sm_vz_vorgaben()
{
    return array(
        'enabled'   => 0,
        'device'    => '',
        'protocol'  => 'sml',
        'baudrate'  => 9600,
        'parity'    => '8n1',
        // Viele Haushaltszaehler (z. B. Landis+Gyr E220) senden keine
        // gestellte Uhr. vzlogger verwirft solche Telegramme dann mit
        // "timestamp before 1990, IGNORING" - der Zaehler wird gelesen,
        // aber kein einziger Wert kommt an. Deshalb ist die Rechner-Uhrzeit
        // die Vorgabe.
        'localtime' => 1,
        'sendudp'   => 1,
        'udpport'   => 7000,
        'httpport'  => 8083,
        'serial'    => 'vzlogger',
        'channels'  => array('1-0:1.8.0', '1-0:2.8.0', '1-0:16.7.0'),
        'uuids'     => array(),
    );
}

function sm_vz_read()
{
    $cfg = sm_vz_vorgaben();
    $datei = sm_paths()['vzjson'];
    if (is_readable($datei)) {
        $roh = json_decode((string) @file_get_contents($datei), true);
        if (is_array($roh)) {
            foreach ($cfg as $k => $v) {
                if (array_key_exists($k, $roh)) {
                    $cfg[$k] = $roh[$k];
                }
            }
        }
    }
    if (!is_array($cfg['channels']) || !$cfg['channels']) {
        $cfg['channels'] = sm_vz_vorgaben()['channels'];
    }
    return $cfg;
}

function sm_vz_write($cfg)
{
    return sm_json_schreiben(sm_paths()['vzjson'], $cfg, 0640);
}

/** Feste UUIDs je Kanal - vzlogger verlangt sie. */
function sm_vz_uuids($kanaele)
{
    $u = array();
    foreach ($kanaele as $c) {
        $h = md5('loxberry-smartmeter-vzlogger-' . $c);
        $u[$c] = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
               . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
    return $u;
}

/** vzlogger.conf aus den gespeicherten Einstellungen erzeugen. */
function sm_vz_conf_schreiben($cfg)
{
    $p = sm_paths();
    $chans = array();
    foreach ($cfg['channels'] as $c) {
        $chans[] = array(
            'api'        => 'null',
            'uuid'       => isset($cfg['uuids'][$c]) ? $cfg['uuids'][$c] : '',
            'identifier' => $c,
        );
    }
    $meter = array(
        'enabled'  => (bool) $cfg['enabled'],
        'protocol' => $cfg['protocol'],
        'device'   => $cfg['device'],
        'baudrate' => (int) $cfg['baudrate'],
        'parity'   => $cfg['parity'],
        'channels' => $chans,
    );
    // Ohne diesen Schluessel nimmt vzlogger den Zeitstempel des Zaehlers.
    $meter['use_local_time'] = $cfg['localtime'] ? true : false;
    // D0 braucht eine Aufforderung ("/?!<CR><LF>").
    if ($cfg['protocol'] === 'd0') {
        $meter['pullseq'] = '2F3F210D0A';
    }
    $conf = array(
        'verbosity' => 5,
        'log'       => $p['vzlog'],
        'retry'     => 30,
        'local'     => array(
            'enabled' => true,
            'port'    => (int) $cfg['httpport'],
            'index'   => true,
            'timeout' => 0,
            'buffer'  => -1,
        ),
        'meters'    => array($meter),
    );
    // Ueber sm_json_schreiben: erst kodieren, dann den Rueckgabewert ansehen.
    // Bis 2.3.14 ging das Ergebnis von json_encode hier unmittelbar an
    // file_put_contents - bei ungueltigem UTF-8 waere eine leere Datei
    // entstanden, und der Rueckgabewert 0 haette Erfolg gemeldet.
    return sm_json_schreiben($p['vzconf'], $conf);
}

/* ==================================================================
 * Legacy-Konfiguration (smartmeter.cfg) - hier steht auch MQTT
 * ================================================================== */

/**
 * Die ganze Datei als array[Abschnitt][Schluessel] = Wert.
 *
 * Nicht mit parse_ini_file: die Datei stammt von Config::Simple und darf
 * Werte ohne Anfuehrungszeichen enthalten, die PHPs INI-Leser anders
 * auslegen wuerde (etwa "on", "off", "none"). Und sie fuehrt frei vergebene
 * Bezeichnungen der Lesekoepfe - ein "&", "(" oder "!" darin laesst
 * parse_ini_file() die GANZE Datei verwerfen.
 *
 * Steht seit 2.3.14 hier und nicht mehr in sm_legacy.php: sm_legacy_read()
 * liest darueber, und ein zweiter, abschnittsblinder Leser waere die zweite
 * Wahrheit.
 */
function sm_cfg_read()
{
    $datei = sm_paths()['legacy'];
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
        $k = trim(substr($z, 0, $pos));
        $v = trim(substr($z, $pos + 1));
        $alles[$abschnitt][$k] = $v;
    }
    return $alles;
}

/** Die ganze Datei zurueckschreiben - unteilbar, mit Rechten vor dem Inhalt. */
function sm_cfg_write($alles)
{
    $z = "; Smartmeter classic\n; Geschrieben von der Oberflaeche.\n\n";
    // MAIN zuerst - so war die Datei schon immer aufgebaut.
    $reihenfolge = array_merge(array('MAIN'),
        array_diff(array_keys($alles), array('MAIN')));
    foreach ($reihenfolge as $abschnitt) {
        if (!isset($alles[$abschnitt]) || !is_array($alles[$abschnitt])) {
            continue;
        }
        $z .= '[' . $abschnitt . "]\n";
        foreach ($alles[$abschnitt] as $k => $v) {
            // Zeilenumbrueche wuerden die Datei zerlegen.
            $v = str_replace(array("\r", "\n"), '', (string) $v);
            $z .= $k . '=' . $v . "\n";
        }
        $z .= "\n";
    }
    // 0640: die Datei fuehrt das Zugriffstoken des Endpunkts. Die Rechte
    // gehoeren an das Anlegen, nicht hinterher.
    return sm_atomar_schreiben(sm_paths()['legacy'], $z, 0640);
}

/** Einzelne Werte in einem Abschnitt setzen. */
function sm_cfg_set($abschnitt, $paare)
{
    $alles = sm_cfg_read();
    if (!isset($alles[$abschnitt])) {
        $alles[$abschnitt] = array();
    }
    foreach ($paare as $k => $v) {
        $alles[$abschnitt][$k] = $v;
    }
    return sm_cfg_write($alles);
}

function sm_cfg_get($alles, $abschnitt, $schluessel, $vorgabe = '')
{
    return (isset($alles[$abschnitt][$schluessel]) && $alles[$abschnitt][$schluessel] !== '')
        ? $alles[$abschnitt][$schluessel] : $vorgabe;
}

/**
 * Die Vorgaben des Abschnitts [MAIN] - an genau EINER Stelle.
 *
 * Bis 2.3.14 stand diese Liste dreimal und war zweimal verschieden:
 *   webfrontend/htmlauth/sm_lib.php   SENDMQTT '0'
 *   bin/fetch.php                     SENDMQTT '1'
 *   bin/fetch_vzlogger.pl             SENDMQTT  1
 * Fehlte der Schluessel, zeigte die Oberflaeche den Haken AUS, waehrend
 * beide Dienste sendeten. Das ist der Gardena-Befund, woertlich.
 *
 * Der gemeinsame Wert ist 1 und nicht 0: die ausgelieferte
 * config/smartmeter.cfg fuehrt SENDMQTT=1, und beide Dienste haben sich
 * bisher so verhalten. Eine 0 haette auf jeder Anlage, der der Schluessel
 * fehlt, MQTT stillschweigend abgeschaltet.
 *
 * Der Perl-Dienst kann diese Funktion nicht aufrufen; er liest dieselben
 * Vorgaben aus bin/sm_felder.json, Abschnitt "vorgaben".
 */
function sm_vorgaben()
{
    return array(
        'SENDMQTT'  => '1',
        'MQTTTOPIC' => 'smartmeter',
        'SENDUDP'   => '0',
        'UDPPORT'   => '7000',
        'CRON'      => '5',
        'READ'      => '0',
        // Freiwilliges Wortzeichen des unangemeldeten Endpunkts. Leer
        // heisst: der Endpunkt antwortet ohne Zusatz. Siehe
        // webfrontend/html/index.php.
        'TOKEN'     => '',
        // Merkmal gegen fremde Absender. Wird ausschliesslich von der
        // ANGEMELDETEN Oberflaeche angelegt, nie vom Endpunkt, und wandert
        // NICHT in die Sicherung.
        'FORMKEY'   => '',
    );
}

/**
 * Die Werte des Abschnitts [MAIN], vervollstaendigt aus den Vorgaben.
 *
 * Liest ueber sm_cfg_read() - also abschnittsbewusst. Bis 2.3.14 lief hier
 * ein eigenes Muster ueber die GANZE Datei; nur die Positivliste der sechs
 * Schluessel verhinderte eine Kollision mit einem Lesekopf-Abschnitt, und
 * ein Lesekopf fuehrt bereits TIMEOUT, PARITY und CRC.
 */
function sm_legacy_read()
{
    $alles = sm_cfg_read();
    $cfg = sm_vorgaben();
    foreach ($cfg as $k => $v) {
        if (isset($alles['MAIN'][$k]) && $alles['MAIN'][$k] !== '') {
            $cfg[$k] = $alles['MAIN'][$k];
        }
    }
    return $cfg;
}

/** Liest der Legacy-Weg gerade? Er belegt dieselbe Schnittstelle. */
function sm_legacy_aktiv()
{
    $c = sm_legacy_read();
    return $c['READ'] === '1';
}

/**
 * Welche Schluessel fehlen in der Datei, welche stehen darin und gibt es
 * nicht?
 *
 * Fehlend wird geschrieben (sm_cfg_vervollstaendigen), Fremdes wird nur
 * GENANNT: niemand weiss, ob dort der Rest einer aelteren Fassung steht
 * oder etwas, das der naechsten schon gehoert.
 *
 * BERICHTIGT AM 26.08.2026. SUBFOLDER und SCRIPTNAME stehen in JEDER
 * installierten Konfiguration - postinstall.sh setzt sie mit sed ein -,
 * aber nicht in sm_vorgaben(), weil sie keine Vorgabe HABEN: ihr Wert
 * haengt am Installationsordner. Der Vergleich hielt sie deshalb fuer
 * fremd, und der Selbsttest zeigte auf einer voellig heilen Anlage ein
 * rotes Kreuz mit der Begruendung "Schluessel, die dieses Plugin nicht
 * kennt: SCRIPTNAME, SUBFOLDER". Ein Kreuz, das immer steht, liest nach
 * kurzer Zeit niemand mehr - und dann faellt das echte auch nicht auf.
 *
 * Gefunden hat es der Pruefstand auslieferung_pruefstand.php, nicht das
 * Auge: auf dem Entwicklungsrechner traegt die Datei die Platzhalter und
 * sieht damit anders aus als beim Anwender.
 */
function sm_cfg_lage()
{
    $vorgaben = sm_vorgaben();
    $alles = sm_cfg_read();
    $datei = isset($alles['MAIN']) && is_array($alles['MAIN']) ? $alles['MAIN'] : array();
    // Bekannt, aber ohne Vorgabe: sie duerfen dastehen und fehlen duerfen
    // sie auch - der Installateur setzt sie. sm_sichern_tabu() fuehrt
    // dieselben beiden Namen, und zwar aus demselben Grund.
    $bekannt = array_merge(array_keys($vorgaben),
                           array('SUBFOLDER', 'SCRIPTNAME'));
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($datei)));
    $fremd   = array_values(array_diff(array_keys($datei), $bekannt));
    sort($fehlend);
    sort($fremd);
    return array('fehlend' => $fehlend, 'fremd' => $fremd,
                 'anzahl' => count($vorgaben), 'gesetzt' => count($datei));
}

/**
 * Fehlende Schluessel EINMAL mit ihrer Vorgabe in die Datei schreiben.
 *
 * Ergaenzen heisst: beim Lesen tritt die Vorgabe ein, die Datei bleibt
 * lueckenhaft, und "fehlt" ist von "steht auf dem Vorgabewert" nicht zu
 * unterscheiden. Vervollstaendigen heisst: es steht danach da.
 *
 * array_key_exists und nicht isset: isset haelt einen leeren Wert fuer
 * nicht vorhanden und wuerde ein bewusst geleertes Token bei jedem Aufruf
 * zurueckschreiben.
 */
function sm_cfg_vervollstaendigen()
{
    $lage = sm_cfg_lage();
    if (!$lage['fehlend']) {
        return array();
    }
    $vorgaben = sm_vorgaben();
    $neu = array();
    foreach ($lage['fehlend'] as $k) {
        $neu[$k] = $vorgaben[$k];
    }
    if (sm_cfg_set('MAIN', $neu)) {
        sm_log('Konfiguration ergaenzt: ' . implode(', ', $lage['fehlend']));
        return $lage['fehlend'];
    }
    return array();
}

/* ==================================================================
 * Formularschutz gegen fremde Absender
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht; die Anmeldung geht dabei automatisch mit.
 *
 * Hier haengt daran mehr als anderswo: lox_token_neu macht jede Adresse im
 * Miniserver ungueltig, lox_token_weg oeffnet den Endpunkt fuer jedes
 * Geraet im Netz, und vz_install stoesst eine Paketinstallation an.
 *
 * Der Hausstandard leitet das Merkmal aus dem Aktionstoken ab. Das geht
 * hier nicht: das Token ist FREIWILLIG und darf leer sein, und
 * hash_equals('', '') ist true - ein aus dem Leerstring abgeleitetes
 * Merkmal waere fuer jeden ausrechenbar. Es gibt deshalb einen eigenen
 * Schluessel FORMKEY, den ausschliesslich die angemeldete Oberflaeche
 * anlegt. Er steht in den Vorgaben, geht also bei keinem Speichern
 * verloren, und er wandert NICHT in die Sicherung.
 * ================================================================== */

/** Ein Zufallswort. Ohne mehrdeutige Zeichen, weil man es abtippt. */
function sm_zufall($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Den FORMKEY holen und bei Bedarf anlegen.
 *
 * $erzeugen ist der Schalter aus der Hausregel "Der unangemeldete Endpunkt
 * darf nichts anlegen": die Oberflaeche ruft mit true, alles andere mit
 * false.
 */
function sm_formkey($erzeugen = false)
{
    $cfg = sm_legacy_read();
    $k = trim((string) $cfg['FORMKEY']);
    if ($k === '' && $erzeugen) {
        $k = sm_zufall(32);
        if (!sm_cfg_set('MAIN', array('FORMKEY' => $k))) {
            return '';
        }
        sm_log('Formularmerkmal angelegt.');
    }
    return $k;
}

/** Das Merkmal, das jedes Formular mitfuehrt. Leer heisst: kein Schutz moeglich. */
function sm_formtoken($erzeugen = false)
{
    $k = sm_formkey($erzeugen);
    // Fail closed: ohne Schluessel gibt es kein Merkmal, und der Wachposten
    // weist den POST ab.
    if ($k === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $k);
}

/** Das versteckte Feld fuer ein Formular. */
function sm_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . sm_e(sm_formtoken()) . '">';
}

/* ==================================================================
 * vzlogger: Programm, Prozess, Schnittstelle
 * ================================================================== */

/**
 * Ein lauffaehiges vzlogger-Programm suchen.
 * Liefert (Pfad, Grund). Pfad ist '' wenn nichts laeuft; der Grund wird
 * dem Benutzer woertlich angezeigt.
 *
 * Zwischengespeichert: die Funktion startet drei Prozesse und wurde bis
 * 2.3.14 je Seitenaufbau zweimal gerufen.
 */
function sm_vz_binary()
{
    static $erg = null;
    if ($erg !== null) {
        return $erg;
    }
    $p = sm_paths();
    list(, $arch) = sm_sh('uname -m');
    $arch = trim($arch);
    list(, $sys) = sm_sh('command -v vzlogger');
    $sys = trim($sys);

    if ($sys === '') {
        $erg = array('', sprintf(sm_t('VZ.KEIN_VZLOGGER'), $arch));
        return $erg;
    }
    if (!is_executable($sys)) {
        $erg = array('', sprintf(sm_t('VZ.NICHT_AUSFUEHRBAR'), $sys));
        return $erg;
    }
    if (sm_vz_binary_laeuft($sys)) {
        $erg = array($sys, '');
        return $erg;
    }

    // Vorhanden, aber nicht startbar - der ausfuehrliche Bericht.
    $check = $p['bin'] . '/vz_check.sh';
    $bericht = '';
    if (is_executable($check)) {
        list(, $bericht) = sm_sh(escapeshellarg($check) . ' ' . escapeshellarg($sys));
        $bericht = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' | ', $bericht)));
    }
    if ($bericht === '') {
        list($rc, $out) = sm_vz_binary_probe($sys);
        $bericht = sprintf(sm_t('VZ.STARTET_NICHT_RC'), $rc,
            substr(preg_replace('/\s+/', ' ', $out), 0, 160));
    }
    $erg = array('', sprintf(sm_t('VZ.VORHANDEN_STARTET_NICHT'), $sys) . ' ' . $bericht);
    return $erg;
}

function sm_vz_binary_probe($bin)
{
    return sm_sh(escapeshellarg($bin) . ' --version');
}

/**
 * Laeuft das Programm wirklich? Die Fehlermeldung der Shell enthaelt den
 * Pfad und damit das Wort "vzlogger" - danach darf also nicht gesucht
 * werden.
 */
function sm_vz_binary_laeuft($bin)
{
    list($rc, $out) = sm_vz_binary_probe($bin);
    if (preg_match('/not found|No such file|cannot execute|Exec format error|Permission denied|Syntax error/i', $out)) {
        return false;
    }
    if ($rc === 0) {
        return true;
    }
    return (bool) preg_match('/^\s*vzlogger\s+[\d.]/i', $out);
}

/** Fassung laut Paketverwaltung. Zwischengespeichert - "available" ruft apt-cache. */
function sm_vz_paket($was)
{
    static $c = array();
    if (isset($c[$was])) {
        return $c[$was];
    }
    $h = sm_paths()['bin'] . '/vzlogger_pkg.sh';
    if (!is_executable($h)) {
        $c[$was] = '';
        return '';
    }
    list(, $v) = sm_sh(escapeshellarg($h) . ' ' . escapeshellarg($was));
    $c[$was] = trim($v);
    return $c[$was];
}

/** Installation anstossen (ueber sudoers freigegeben). */
function sm_vz_install()
{
    $h = sm_paths()['bin'] . '/vzlogger_pkg.sh';
    if (!is_executable($h)) {
        return sprintf(sm_t('VZ.PAKETHELFER_FEHLT'), $h);
    }
    sm_log('vzlogger-Installation angestossen.');
    list(, $out) = sm_sh('sudo -n ' . escapeshellarg($h) . ' install');
    return trim($out) !== '' ? $out : sm_t('ALLG.KEINE_AUSGABE');
}

/**
 * PIDs unseres vzlogger - erkannt an unserer eigenen Konfigurationsdatei,
 * damit ein vzlogger eines anderen Plugins nie mitgezaehlt wird.
 *
 * Zwischengespeichert: bis 2.3.14 lief sie je Seitenaufbau dreimal.
 */
function sm_vz_running()
{
    static $erg = null;
    if ($erg !== null) {
        return $erg;
    }
    // pgrep -f findet auch die Shell, die pgrep aufruft - ihre Befehlszeile
    // enthaelt das Suchmuster. Deshalb jede Fundstelle gegen den echten
    // Programmnamen pruefen.
    list(, $roh) = sm_sh('pgrep -f -- ' . escapeshellarg('-c ' . sm_paths()['vzconf']));
    $ok = array();
    foreach (preg_split('/\s+/', trim($roh)) as $pid) {
        if ($pid === '' || !preg_match('/^[0-9]+$/', $pid)) {
            continue;
        }
        list(, $comm) = sm_sh('ps -p ' . (int) $pid . ' -o comm=');
        if (preg_match('/vzlogger/i', $comm)) {
            $ok[] = $pid;
        }
    }
    $erg = implode(' ', $ok);
    return $erg;
}

/** Den Zwischenspeicher der drei Prozessabfragen verwerfen. */
function sm_cache_verwerfen()
{
    $p = sm_paths();
    @unlink($p['datadir'] . '/diagnose.json');
}

/** Haelt sonst jemand die serielle Schnittstelle? Liefert Text oder ''. */
function sm_vz_device_busy($dev)
{
    if ($dev === '') {
        return '';
    }
    $real = is_link($dev) ? readlink($dev) : $dev;
    if ($real !== '' && $real[0] !== '/') {
        $real = '/dev/' . $real;
    }
    // fuser ohne -v: PIDs auf der Standardausgabe, Namen auf stderr.
    // Mit -v landete der Geraetename in der PID-Liste.
    $ziele = ($real !== '' && $real !== $dev)
        ? escapeshellarg($dev) . ' ' . escapeshellarg($real)
        : escapeshellarg($dev);
    list(, $out) = sm_sh('fuser ' . $ziele);
    $meine = array_flip(preg_split('/\s+/', trim(sm_vz_running())));
    $andere = array();
    if (preg_match_all('/\b(\d+)\b/', $out, $m)) {
        foreach (array_unique($m[1]) as $pid) {
            if (isset($meine[$pid]) || !is_dir('/proc/' . $pid)) {
                continue;
            }
            list(, $comm) = sm_sh('ps -p ' . (int) $pid . ' -o comm=');
            $comm = trim($comm);
            $andere[] = $comm !== '' ? $comm . ' (' . $pid . ')' : $pid;
        }
    }
    return implode(', ', $andere);
}

function sm_vz_note($text)
{
    $p = sm_paths();
    @mkdir($p['shm'], 0775, true);
    @file_put_contents($p['vzlog'],
        date('Y-m-d H:i:s') . ' [Plugin] ' . $text . "\n", FILE_APPEND);
}

function sm_vz_restart($cfg)
{
    $p = sm_paths();
    @mkdir($p['shm'], 0775, true);
    sm_sh('pkill -f -- ' . escapeshellarg('-c ' . $p['vzconf']));
    sleep(1);
    sm_cache_verwerfen();
    if (!$cfg['enabled']) {
        sm_log('vzlogger angehalten (Betriebsart ausgeschaltet).');
        return '';
    }
    list($bin, $warum) = sm_vz_binary();
    if ($bin === '') {
        sm_vz_note('START ABGEBROCHEN: ' . $warum);
        sm_log('vzlogger liess sich nicht starten: ' . $warum, 'ERROR');
        return $warum;
    }
    // Startfehler landen im Protokoll statt in /dev/null.
    sm_sh('nohup ' . escapeshellarg($bin) . ' -c ' . escapeshellarg($p['vzconf'])
        . ' >> ' . escapeshellarg($p['vzlog']) . ' 2>&1 &');
    sleep(2);
    // Der Zwischenspeicher der Prozessabfrage ist jetzt ueberholt.
    $pid = sm_vz_running_frisch();
    if ($pid === '') {
        sm_vz_note('START FEHLGESCHLAGEN: ' . $bin . ' -c ' . $p['vzconf']
                 . ' lief nach 2 Sekunden nicht mehr. Ursache siehe Zeilen darueber.');
        sm_log('vzlogger startete und fiel binnen zwei Sekunden aus.', 'ERROR');
        return sm_t('VZ.START_GEFALLEN');
    }
    sm_vz_note('gestartet: ' . $bin . ' (PID ' . $pid . ')');
    sm_log('vzlogger gestartet, PID ' . $pid . '.');
    return '';
}

/**
 * Dieselbe Frage wie sm_vz_running(), aber ohne Zwischenspeicher.
 *
 * Nach einem Start oder Stopp ist der gespeicherte Wert ueberholt; ein
 * static, den man nach einer Wirkung nicht verwirft, beantwortet die Frage
 * von vorhin.
 */
function sm_vz_running_frisch()
{
    list(, $roh) = sm_sh('pgrep -f -- ' . escapeshellarg('-c ' . sm_paths()['vzconf']));
    foreach (preg_split('/\s+/', trim($roh)) as $pid) {
        if ($pid === '' || !preg_match('/^[0-9]+$/', $pid)) {
            continue;
        }
        list(, $comm) = sm_sh('ps -p ' . (int) $pid . ' -o comm=');
        if (preg_match('/vzlogger/i', $comm)) {
            return $pid;
        }
    }
    return '';
}

/** Die erkannten Lesekoepfe. */
function sm_lesekoepfe()
{
    $d = glob('/dev/serial/smartmeter/*');
    return is_array($d) ? $d : array();
}

/** Letzte Zeilen der beiden Protokolle auf der Ramdisk. */
function sm_logtail()
{
    $p = sm_paths();
    $aus = array();
    foreach (array($p['vzlog'], $p['fetchlog']) as $f) {
        // Rueckwaerts mit fseek statt exec("tail") - kein Prozessstart.
        $z = sm_log_ende($f, 25);
        if (!$z) {
            continue;
        }
        $aus[] = '--- ' . $f . " ---\n" . implode("\n", $z);
    }
    return $aus ? implode("\n", $aus) : sm_t('LOG.NOCH_NICHTS');
}

function sm_hostname()
{
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/* ==================================================================
 * Umlaufender Zaehler - das Lebenszeichen fuer Loxone
 *
 * Ein virtueller Eingang behaelt seinen letzten Wert. Faellt der Zaehler
 * aus, sieht in der App alles normal aus. Bis 2.3.14 empfahl der Reiter
 * "Einbindung in Loxone", dafuer die Wirkleistung zu beobachten - die
 * steht nachts bei konstanter Grundlast minutenlang exakt still, und bei
 * einem Zaehler ohne 16.7.0 gibt es sie gar nicht.
 *
 * Ein Zeitstempel allein traegt auch nicht: ein Raspberry Pi hat keine
 * Echtzeituhr, und sobald NTP nach dem Booten greift, springt die Zeit.
 * Deshalb ein umlaufender Zaehler; -1 heisst "noch nie gelaufen", und 0
 * waere ein gueltiger Stand.
 *
 * Er liegt auf der Ramdisk neben den Datendateien: er ist neu erzeugbar,
 * und dass er nach einem Neustart bei -1 beginnt, ist die richtige
 * Aussage.
 * ================================================================== */

function sm_zaehler_lesen()
{
    // Ueber den gemeinsamen Vorlauf - dieselbe Funktion, die auch der
    // Endpunkt und der Abholer benutzen. Faellt er aus, gibt es keinen
    // Ersatzwert: -1 heisst "noch nie gelaufen", und das ist hier die
    // richtige Antwort.
    if (!function_exists('smg_zaehler_lesen')) {
        return -1;
    }
    return smg_zaehler_lesen(sm_paths()['zaehler']);
}

/* ==================================================================
 * Loxone-Vorlagen
 * ================================================================== */

/** Die Felder, die auf dem vzLogger-Weg wirklich veroeffentlicht werden. */
function sm_vz_felder($cfg)
{
    $f = array();
    foreach ($cfg['channels'] as $c) {
        $f[] = sm_obis_feld($c);
    }
    // Genau die Zusaetze, die bin/fetch_vzlogger.pl selbst bildet.
    $f[] = 'Last_Update';
    $f[] = 'Last_UpdateUnix';
    $f[] = 'Last_UpdateLoxEpoche';
    if (in_array('Total_Power_OBIS_16.7.0', $f, true)) {
        $f[] = 'Consumption_CalculatedPower_OBIS_1.99.0';
        $f[] = 'Delivery_CalculatedPower_OBIS_2.99.0';
    }
    return array_values(array_unique($f));
}

/**
 * Eine VirtualInHttp-Vorlage bauen.
 *
 * Bauform nach dem Heimkino-Kunstgriff (12.08.2026): Dummy-Adresse
 * http://localhost und Abfragezyklus 604800 s, nur damit Loxone die richtig
 * benannten Eingaenge anlegt - die Werte kommen vom MQTT-Gateway. Format
 * wie Original-Export aus Loxone Config 17.1.
 *
 * $eintraege ist eine Liste aus (Titel, Feldname). Textfelder gehoeren
 * NICHT hinein: das nachgebaute Format ist nur fuer Zahlenwerte belegt, und
 * ein Eingang mit Analog="true" auf einen Text zeigt dauerhaft 0.
 */
function sm_xml_virtual_in($titel, $kommentar, $eintraege)
{
    $crlf = "\r\n";
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="' . $x($titel) . '" Comment="' . $x($kommentar)
        . '" Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($eintraege as $e) {
        list($t, $feld) = $e;
        $md = sm_feld($feld);
        // Ein Feld, das der Katalog nicht kennt, bekommt keine erfundene
        // Einheit und keine erfundenen Grenzen.
        $einheit = ($md && $md['einheit'] !== '') ? '<v.' . (int) $md['nk'] . '> ' . $md['einheit'] : '';
        $nk   = $md ? (int) $md['nk'] : 0;
        $min  = $md ? (int) $md['min'] : 0;
        $max  = $md ? (int) $md['max'] : 1000000;
        $sig  = ($md && $md['signed']) ? 'true' : 'false';
        // Der Kommentar wird in Loxone Config zum ANZEIGENAMEN, nicht zur
        // Dokumentation. Eine knappe Zeile, kein Fliesstext.
        $bed  = $md ? sm_t($md['bed']) : $feld;
        $o .= "\t" . '<VirtualInHttpCmd Title="' . $x($t) . '" ';
        $o .= 'Comment="' . $x($bed) . '" Check=" " ';
        $o .= 'Signed="' . $sig . '" Analog="true" SourceValLow="0" DestValLow="0" '
            . 'SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $min
            . '" MaxVal="' . $max . '" Unit="' . $x($einheit) . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Vorlage der MQTT-Eingaenge.
 *
 * Der Titel MUSS der Name sein, unter dem das MQTT-Gateway den Eingang
 * anlegt - bei MQTT ist der Titel die Adresse. Er kommt deshalb aus
 * sm_ve_name(), derselben Funktion, aus der auch die Themen-Tabelle im
 * Reiter MQTT schoepft.
 */
function sm_vorlage()
{
    $cfg    = sm_vz_read();
    $legacy = sm_legacy_read();
    $praefix = $legacy['MQTTTOPIC'];
    $serial  = $cfg['serial'];

    $eintraege = array();
    $text = 0;
    foreach (sm_vz_felder($cfg) as $feld) {
        $md = sm_feld($feld);
        if ($md && $md['typ'] === 'text') {
            $text++;
            continue;
        }
        $eintraege[] = array(sm_ve_name($praefix, $serial, $feld), $feld);
    }
    $kommentar = sprintf(sm_t('LOX.VORLAGE_KOMMENTAR'), date('d.m.Y'), $praefix)
        . ($text > 0 ? ' ' . sprintf(sm_t('LOX.VORLAGE_TEXTFELDER'), $text) : '');
    return array('VI_smartmeter_mqtt.xml',
        sm_xml_virtual_in(sm_t('LOX.VORLAGE_TITEL'), $kommentar, $eintraege));
}

/**
 * Die Vorlage der Werte des klassischen Lesers.
 *
 * Angelegt wird nur, was beim letzten erfolgreichen Abruf wirklich einen
 * Wert trug - eine Liste aus dem Katalog waere eine dritte Stelle, die mit
 * dem Code auseinanderlaeuft, und Loxone traegt fuer einen Eingang ohne
 * Wert DefVal="0" ein. Eine 0 sieht aus wie ein Messwert.
 *
 * Hat noch kein Lesekopf geantwortet, entsteht KEINE Datei; die Oberflaeche
 * sagt, warum.
 */
function sm_vorlage_legacy()
{
    $legacy = sm_legacy_read();
    $praefix = $legacy['MQTTTOPIC'];
    $eintraege = array();
    $text = 0;
    $koepfe = 0;
    foreach (sm_koepfe() as $k) {
        $serial = $k['ABSCHNITT'];
        $werte = sm_werte($serial);
        if (!$werte) {
            continue;
        }
        $koepfe++;
        foreach ($werte as $pv) {
            $feld = $pv[0];
            $md = sm_feld($feld);
            if ($md && $md['typ'] === 'text') {
                $text++;
                continue;
            }
            $eintraege[] = array(sm_ve_name($praefix, $serial, $feld), $feld);
        }
    }
    if (!$eintraege) {
        return array('', '');
    }
    $kommentar = sprintf(sm_t('LOX.VORLAGE_KOMMENTAR_LG'), date('d.m.Y'), $koepfe)
        . ($text > 0 ? ' ' . sprintf(sm_t('LOX.VORLAGE_TEXTFELDER'), $text) : '');
    return array('VI_smartmeter_klassisch.xml',
        sm_xml_virtual_in(sm_t('LOX.VORLAGE_TITEL_LG'), $kommentar, $eintraege));
}

/* ==================================================================
 * Einstellungen sichern und zurueckspielen
 *
 * Zweck ist der UMZUG auf einen zweiten LoxBerry, nicht die Sicherung
 * gegen Verlust - dafuer gibt es die Zweitschrift aus preupgrade.sh.
 *
 * Die Datei traegt das TOKEN. Ohne es stuenden nach dem Zurueckspielen
 * alle Felder richtig, und der Miniserver bekaeme weiterhin 403 - die
 * Datei waere wertlos. Damit traegt sie ein Geheimnis, und der Text am
 * Knopf sagt das.
 *
 * NICHT hinein gehoeren:
 *   FORMKEY              das Merkmal gegen fremde Absender. Es lebt fuer
 *                        diese Anlage; in einer Datei hat es nichts zu
 *                        suchen.
 *   SUBFOLDER, SCRIPTNAME   sie gehoeren zur Installation. sm_cron_setzen()
 *                        raeumt zu Beginn JEDE Verknuepfung dieses Namens
 *                        aus allen cron.*-Ordnern weg - ein aus einer
 *                        fremden Anlage mitgebrachter SCRIPTNAME liesse
 *                        das Plugin die Cron-Eintraege eines ANDEREN
 *                        Plugins loeschen.
 * ================================================================== */

/** Schluessel, die nie in eine Sicherung wandern. */
function sm_sichern_tabu()
{
    return array('FORMKEY', 'SUBFOLDER', 'SCRIPTNAME');
}

/** Die Konfiguration als Text - dieselbe Schreibweise wie smartmeter.cfg. */
function sm_sichern_text()
{
    $tabu = sm_sichern_tabu();
    $main = sm_legacy_read();
    $vz   = sm_vz_read();
    $alles = sm_cfg_read();

    $t  = "; Smartmeter classic - Sicherung der Einstellungen\n";
    $t .= '; ' . date('d.m.Y H:i') . ', Plugin-Fassung ' . sm_fassung() . "\n";
    $t .= "; ACHTUNG: enthaelt das Zugriffstoken des Endpunkts.\n";
    $t .= "; Wie ein Passwort behandeln - nicht in ein Forum haengen.\n\n";

    $t .= "[MAIN]\n";
    foreach (sm_vorgaben() as $k => $v) {
        if (in_array($k, $tabu, true)) {
            continue;
        }
        $t .= $k . '=' . sm_wert_saeubern($main[$k]) . "\n";
    }
    $t .= "\n[VZLOGGER]\n";
    foreach (sm_vz_vorgaben() as $k => $v) {
        if ($k === 'uuids') {
            // Aus den Kanaelen neu gebildet - keine zweite Wahrheit.
            continue;
        }
        $w = $vz[$k];
        if (is_array($w)) {
            $w = implode(',', $w);
        }
        $t .= $k . '=' . sm_wert_saeubern($w) . "\n";
    }
    foreach (sm_koepfe() as $k) {
        $t .= "\n[KOPF " . $k['ABSCHNITT'] . "]\n";
        foreach (sm_kopf_felder() as $f) {
            $w = isset($alles[$k['ABSCHNITT']][$f]) ? $alles[$k['ABSCHNITT']][$f] : '';
            $t .= $f . '=' . sm_wert_saeubern($w) . "\n";
        }
    }
    return $t;
}

/**
 * Eine hochgeladene Sicherung pruefen.
 *
 * Rueckgabe: array(Ergebnis, Beanstandungen, Hinweise). Ergebnis ist null,
 * sobald eine Beanstandung vorliegt - eine halb gueltige Datei
 * ueberschreibt NICHTS.
 *
 * Alle Beanstandungen werden gesammelt. Wer nur die erste zeigt, schickt
 * den Anwender in eine Schleife aus je einem Fund pro Anlauf.
 */
function sm_sichern_einlesen($roh)
{
    $mangel  = array();
    $hinweis = array();
    $tabu    = sm_sichern_tabu();
    $vorgabe_main = sm_vorgaben();
    $vorgabe_vz   = sm_vz_vorgaben();
    $kopffelder   = sm_kopf_felder();

    $main = array();
    $vz   = array();
    $koepfe = array();
    $abschnitt = '';
    $gefunden = 0;
    $nr = 0;

    foreach (preg_split('/\R/', (string) $roh) as $z) {
        $nr++;
        $t = trim($z);
        if ($t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if ($t[0] === '[' && substr($t, -1) === ']') {
            $abschnitt = trim(substr($t, 1, -1));
            if ($abschnitt !== 'MAIN' && $abschnitt !== 'VZLOGGER'
                && strncmp($abschnitt, 'KOPF ', 5) !== 0) {
                $mangel[] = sprintf(sm_t('SICH.ABSCHNITT'), $nr, sm_e($abschnitt));
            }
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            $mangel[] = sprintf(sm_t('SICH.ZEILE'), $nr, sm_e($t));
            continue;
        }
        $k = trim(substr($t, 0, $pos));
        $v = trim(substr($t, $pos + 1));

        if ($abschnitt === 'MAIN') {
            if (in_array($k, $tabu, true)) {
                // Kein stiller Verlust: die Datei nennt etwas, das
                // ausdruecklich nicht uebernommen wird.
                $hinweis[] = sprintf(sm_t('SICH.UEBERGANGEN'), sm_e($k));
                continue;
            }
            if (!array_key_exists($k, $vorgabe_main)) {
                $mangel[] = sprintf(sm_t('SICH.SCHLUESSEL'), $nr, sm_e($k));
                continue;
            }
            $main[$k] = $v;
            $gefunden++;
        } elseif ($abschnitt === 'VZLOGGER') {
            if ($k === 'uuids') {
                continue;
            }
            if (!array_key_exists($k, $vorgabe_vz)) {
                $mangel[] = sprintf(sm_t('SICH.SCHLUESSEL'), $nr, sm_e($k));
                continue;
            }
            $vz[$k] = $v;
            $gefunden++;
        } elseif (strncmp($abschnitt, 'KOPF ', 5) === 0) {
            $s = trim(substr($abschnitt, 5));
            if ($s === '') {
                $mangel[] = sprintf(sm_t('SICH.KOPF_OHNE_NAME'), $nr);
                continue;
            }
            if (!in_array($k, $kopffelder, true)) {
                $mangel[] = sprintf(sm_t('SICH.SCHLUESSEL'), $nr, sm_e($k));
                continue;
            }
            if (!isset($koepfe[$s])) {
                $koepfe[$s] = array();
            }
            $koepfe[$s][$k] = $v;
            $gefunden++;
        } else {
            $mangel[] = sprintf(sm_t('SICH.OHNE_ABSCHNITT'), $nr, sm_e($k));
        }
    }

    if ($gefunden === 0) {
        $mangel[] = sm_t('SICH.LEER');
    }

    // Werte pruefen - dieselben Grenzen wie in den Formularen. Eine
    // Sicherung ist eine Eingabe wie jede andere.
    if (isset($main['CRON']) && !array_key_exists($main['CRON'], sm_takte())) {
        $mangel[] = sprintf(sm_t('SICH.WERT'), 'CRON', sm_e($main['CRON']));
    }
    foreach (array('UDPPORT') as $k) {
        if (isset($main[$k]) && (!preg_match('/^[0-9]+$/', $main[$k])
            || (int) $main[$k] < 1 || (int) $main[$k] > 65535)) {
            $mangel[] = sprintf(sm_t('SICH.WERT'), $k, sm_e($main[$k]));
        }
    }
    foreach (array('udpport', 'httpport') as $k) {
        if (isset($vz[$k]) && (!preg_match('/^[0-9]+$/', $vz[$k])
            || (int) $vz[$k] < 1 || (int) $vz[$k] > 65535)) {
            $mangel[] = sprintf(sm_t('SICH.WERT'), $k, sm_e($vz[$k]));
        }
    }
    if (isset($vz['baudrate']) && (!preg_match('/^[0-9]+$/', $vz['baudrate'])
        || (int) $vz['baudrate'] < 300 || (int) $vz['baudrate'] > 921600)) {
        $mangel[] = sprintf(sm_t('SICH.WERT'), 'baudrate', sm_e($vz['baudrate']));
    }
    if (isset($vz['parity']) && !in_array($vz['parity'], array('8n1', '7n1', '7e1', '8e1'), true)) {
        $mangel[] = sprintf(sm_t('SICH.WERT'), 'parity', sm_e($vz['parity']));
    }
    if (isset($vz['protocol']) && !in_array($vz['protocol'], array('sml', 'd0'), true)) {
        $mangel[] = sprintf(sm_t('SICH.WERT'), 'protocol', sm_e($vz['protocol']));
    }
    $profile = sm_profile();
    foreach ($koepfe as $s => $w) {
        if (isset($w['METER']) && $w['METER'] !== ''
            && !array_key_exists($w['METER'], $profile)) {
            $mangel[] = sprintf(sm_t('SICH.WERT'), 'METER ' . sm_e($s), sm_e($w['METER']));
        }
    }

    if ($mangel) {
        return array(null, $mangel, $hinweis);
    }
    return array(array('MAIN' => $main, 'VZLOGGER' => $vz, 'KOEPFE' => $koepfe),
                 array(), $hinweis);
}

/**
 * Eine gepruefte Sicherung uebernehmen.
 *
 * Rueckgabe: array(ok, Hinweise). Der Aufrufer sagt danach, was mit dem
 * Dienst geschehen ist.
 */
function sm_sichern_uebernehmen($neu)
{
    $hinweis = array();
    $ok = true;

    if ($neu['MAIN']) {
        $ok = sm_cfg_set('MAIN', $neu['MAIN']) && $ok;
    }
    foreach ($neu['KOEPFE'] as $s => $w) {
        // Ein Geraetepfad gehoert zu DIESER Anlage. Steckt der Lesekopf hier
        // nicht, wird das Profil uebernommen und der Pfad genannt statt
        // gesetzt - sonst zeigt die Konfiguration auf ein Geraet, das es
        // nicht gibt.
        if (isset($w['DEVICE']) && $w['DEVICE'] !== '' && !file_exists($w['DEVICE'])) {
            $hinweis[] = sprintf(sm_t('SICH.KOPF_FEHLT'), sm_e($s), sm_e($w['DEVICE']));
            unset($w['DEVICE']);
        }
        $ok = sm_cfg_set($s, $w) && $ok;
    }
    if ($neu['VZLOGGER']) {
        $cfg = sm_vz_read();
        foreach ($neu['VZLOGGER'] as $k => $v) {
            if ($k === 'channels') {
                $liste = array();
                foreach (preg_split('/[\s,]+/', $v) as $c) {
                    if ($c !== '') { $liste[] = $c; }
                }
                $cfg['channels'] = $liste ? $liste : sm_vz_vorgaben()['channels'];
            } else {
                $cfg[$k] = $v;
            }
        }
        if (isset($cfg['device']) && $cfg['device'] !== '' && !file_exists($cfg['device'])) {
            $hinweis[] = sprintf(sm_t('SICH.KOPF_FEHLT'), 'vzLogger', sm_e($cfg['device']));
            $cfg['device'] = '';
        }
        $cfg['uuids'] = sm_vz_uuids($cfg['channels']);
        $ok = sm_vz_write($cfg) && $ok;
        if ($ok) {
            sm_vz_conf_schreiben(sm_vz_read());
        }
    }
    sm_cache_verwerfen();
    sm_log('Einstellungen aus einer Sicherung zurueckgespielt.');
    return array($ok, $hinweis);
}

/* ==================================================================
 * Die beiden Dateien gehoeren zusammen.
 *
 * sm_lib.php haelt die Grundlagen (Pfade, Konfiguration, Katalog,
 * Gateway, Vorlagen), sm_legacy.php die Lesekoepfe, Zaehlerprofile und
 * den Abfragetakt. Mehrere Funktionen hier rufen dort etwas auf
 * (sm_koepfe, sm_werte, sm_takte, sm_profile, sm_kopf_felder) - deshalb
 * wird die Schwesterdatei am ENDE eingebunden, nicht am Anfang: dann
 * stehen die eigenen Funktionen schon.
 *
 * Beide Reihenfolgen tragen. Wer sm_legacy.php zuerst einbindet, laeuft
 * ueber dessen require_once hierher; das require_once unten sieht die
 * Datei dann als "laeuft schon" und kehrt zurueck.
 * ================================================================== */
require_once __DIR__ . '/sm_legacy.php';

/* ==================================================================
 * Der gemeinsame Vorlauf der drei Baeume
 *
 * bin/sm_gemein.php haelt, was Oberflaeche, Abholer UND Endpunkt brauchen:
 * die Altersgrenze, die Wertsaeuberung, den umlaufenden Zaehler. Auf dem
 * installierten LoxBerry liegen webfrontend/htmlauth, webfrontend/html und
 * bin in GETRENNTEN Baeumen - deshalb eine Kandidatenliste und kein
 * require ueber eine feste Zahl von "..".
 *
 * Faellt er aus, ist das kein stiller Ausfall: die Selbstpruefung im
 * Reiter Test meldet es.
 * ================================================================== */
$sm_lib_gemein = array(
    sm_paths()['bin'] . '/sm_gemein.php',
    dirname(dirname(dirname(__FILE__))) . '/bin/sm_gemein.php',
);
foreach ($sm_lib_gemein as $sm_lib_k) {
    if (is_file($sm_lib_k)) {
        require_once $sm_lib_k;
        break;
    }
}
unset($sm_lib_gemein, $sm_lib_k);

/** Die Fassung des Plugins - eine Konstante, eine Stelle. */
function sm_fassung()
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $v = (string) LBSystem::pluginversion();
    }
    if ($v === '') {
        // Die plugin.cfg kommentiert mit "#". PHPs INI-Zerleger kennt als
        // Kommentarzeichen nur ";" und bricht sonst ab - deshalb die
        // Kommentarzeilen vorher entfernen.
        foreach (array(sm_paths()['home'] . '/config/plugins/' . sm_paths()['plugin'] . '/plugin.cfg',
                       dirname(dirname(dirname(__FILE__))) . '/plugin.cfg') as $k) {
            if (!is_readable($k)) {
                continue;
            }
            $roh = preg_replace('/^[ \t]*#.*$/m', '', (string) @file_get_contents($k));
            $d = @parse_ini_string($roh, true, INI_SCANNER_RAW);
            if (is_array($d) && isset($d['PLUGIN']['VERSION'])) {
                $v = trim((string) $d['PLUGIN']['VERSION'], '"');
                break;
            }
        }
    }
    return $v;
}
