<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
/* 2.8.23: die Abschrift des gemeinsamen Sicherungsmoduls (Stammfassung Werkzeuge/gemeinsam/sicherung.php, byte-gleich,
 * Werkzeuge/sicherung_einbauen.py) liegt neben dieser Datei. "Einstellungen sichern" und "Einstellungen zurueckspielen"
 * laufen darueber, in allen Linien gleich (Auftrag des Hausherrn 12.11.2026); diese Linie uebergibt nur ihre
 * Beschreibung (sm_sicherung_beschreibung()). Antwortet als Einstieg mit 403. */
require_once __DIR__ . '/sicherung.php';
/* 2.8.23: die erste Zeile jeder Textsicherung bis 2.8.22. sm_sicherung_text_wandeln() erkennt sie daran und setzt sie in
 * den Kopf _aus_textsicherung der umgewandelten Datei; der Uebersetzer sm_sicherung_alt_erkennen() erkennt diese daran
 * (Form wie WOLF-ISM-NG 3.1.22 und Kodi-NG 1.2.29; Entscheidung des Hausherrn 18.11.2026, Frage 37 der Nacht). Seit
 * Modul 1.2.11 wertet das Modul die Koepfe _aus_textsicherung und _text_mangel selbst aus (Regel 21; Fragen 52 und 53
 * der Nacht, "ins Modul"). */
if (!defined('SM_TEXT_KOPF')) { define('SM_TEXT_KOPF', '; Smartmeter classic - Sicherung der Einstellungen'); }
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
            // Gruppe 35 (Regeln/06 :179/:531): dritte Bedingung general.json.
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
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
        // Gruppe 35 (Entscheidung 7, T2-3): kein fester Systempfad mehr (Regeln/06 :179).
        foreach (array(lb_wurzel_ermitteln()) as $k) {
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
        /* 2.8.23: nennt der Aufrufer den Ordner ausdruecklich (LBPPLUGINDIR - so arbeitet der gemeinsame
         * Sicherungs-Pruefstand mit seiner Attrappe), gilt er. Nur hier im Rueckfall: installiert liegt diese Datei
         * unter webfrontend/htmlauth/plugins/<ordner>/, und der Ablageort entscheidet wie bisher. */
        $sm_env = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}\z/', $sm_env) && strpos($sm_env, '..') === false) {
            $ordner = $sm_env;
        }
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
    /* ENT_SUBSTITUTE, sonst liefert htmlspecialchars() bei einem
     * einzigen ungueltigen Byte einen LEERSTRING statt des Textes.
     * Getroffen hat das den Mitschnitt: sm_mitschnitt() liefert rohe
     * Bytes, bei D0 mit Steuerzeichen und einem BCC-Pruefbyte, das jeden
     * Wert annehmen kann - und fread() schneidet bei 4000 Byte auch
     * mitten in einer Mehrbytefolge. Der Anwender sah dann einen leeren
     * Kasten ohne jeden Hinweis, ausgerechnet an dem Werkzeug, das jede
     * Fehlersuche traegt. Gemessen am 02.09.2026 in 7.4.33 und 8.4.24. */
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    /* Wurde nicht bis zum Dateianfang gelesen, ist $zeilen[0] der Rest
     * einer angeschnittenen Zeile - kein Anfang. Er muss WEG, bevor
     * gefiltert wird: das Filtern entfernt den Leereintrag, den das
     * abschliessende \n erzeugt, und schiebt das Bruchstueck dadurch in
     * die Auswahl. Gemessen am 02.09.2026 mit den echten Vorgaben (400
     * Zeilen, Block 8192) in 7.4.33 und 8.4.24: die aelteste angezeigte
     * Zeile begann mitten im Text. */
    if ($pos > 0 && $zeilen) {
        array_shift($zeilen);
    }
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
    if ($rechte !== null && !@chmod($tmp, $rechte)) {
        /* Der Kopfkommentar macht aus den Rechten ein Versprechen -
         * smartmeter.cfg traegt das Zugriffstoken. Schlaegt chmod fehl,
         * wuerde die Datei mit den Vorgaben der umask umbenannt, und
         * niemand erfuehre es. Dann lieber gar nicht schreiben. */
        @unlink($tmp);
        return false;
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

/**
 * Die Quellenspalte der Bausteinliste fuer einen OBIS-Kanal.
 *
 * Der Feldname kommt aus sm_obis_feld(), also aus bin/sm_felder.json -
 * derselben Datei wie die Themen-Tabelle und der Dienst. Ist der Kanal
 * gar nicht eingestellt, sagt die Zeile das, statt einen virtuellen
 * Eingang vorzuschlagen, der nie einen Wert bekommt.
 */
function sm_quelle_mqtt($obis, $felder)
{
    $feld = sm_obis_feld($obis);
    $mono = '<span class="sm-mono">' . sm_e($feld) . '</span>';
    return in_array($feld, $felder, true)
        ? 'MQTT ' . $mono
        : $mono . ' <i>(' . sm_e(sm_t('LOX.KANAL_FEHLT')) . ')</i>';
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
    /* Gruppe 1 (2.8.11): der V1-Satz nennt das Abo mit dem eingestellten Praefix - das Plugin
     * traegt es selbst ein (mqtt_subscriptions.cfg), der Anwender prueft es nur. */
    $f  = sm_gateway_fassung();
    $pr = trim((string) sm_legacy_read()['MQTTTOPIC'], '/');
    $v1 = sprintf(sm_t('MQ.ABO_PFLICHT'), sm_e($pr));
    if ($f === 2) {
        return sm_t('MQ.ABO_V2');
    }
    if ($f === 1) {
        return $v1;
    }
    return sm_t('MQ.ABO_UNBEKANNT')
         . '<br><br>' . $v1
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

/**
 * Die Fassung des MQTT-Gateways in drei Stufen (Gruppe 1, 2.8.11): 0 = unbekannt, 1, 2 (= 2 und
 * hoeher). Dieselbe Einteilung wie sm_abo_text() und sm_abo_klasse() - EINE Stelle fuer die
 * Teile der Seite, die seit 2.8.11 dazukommen (Schritttitel, Abo-Zeile, Hakenliste).
 */
function sm_gateway_fassung()
{
    $g = sm_mqtt_gateway_info();
    $f = ($g === null) ? 0 : (int) $g['fassung'];
    return ($f >= 2) ? 2 : (($f === 1) ? 1 : 0);
}

/**
 * Der Titel von Schritt 2 im Reiter Einbindung in Loxone (Gruppe 1, 2.8.11, Entwurf 2.5).
 * Bis 2.8.10 hiess er immer "Abo im MQTT-Gateway eintragen" - unter V2 eine Aufforderung, die es
 * dort nicht gibt, und unter V1 traegt das Plugin das Abo selbst ein (mqtt_subscriptions.cfg).
 */
function sm_s2_titel()
{
    $f = sm_gateway_fassung();
    if ($f === 2) {
        return sm_t('LOX.S2_TITEL_V2');
    }
    if ($f === 1) {
        return sm_t('LOX.S2_TITEL');
    }
    return sm_t('LOX.S2_TITEL_BEIDE');
}

/**
 * Die Hakenliste fuer Gateway V2 (Gruppe 1, 2.8.11, Entwurf 2.5 und E7; seit dem Nachtrag 19.10.2026
 * aus der Namenstabelle statt aus den Vorlagen).
 *
 * V2 abonniert nur, was unter System -> MQTT Gateway -> Abonnements angehakt ist. Die Liste wird
 * GEBAUT, nicht gepflegt: die eingeschalteten Gruppen von sm_namen_gruppen(), also genau das, was das
 * Plugin sendet. Textfelder gehoeren dazu - ein Virtueller Texteingang braucht den Haken ebenso. Ein
 * Name, der zweimal kommt, steht einmal da.
 *
 * Merker "Noncached" an den drei Werten, die meist dauerhaft auf 1 stehen: ohne den Haken stuenden sie
 * nach einem Neustart des Miniservers auf 0, bis sich der Wert aendert (V2 sendet einen gleichen Wert
 * nicht noch einmal). "Zuruecksetzen nach Senden" braucht diese Linie nicht - sie hat kein Impulsthema.
 *
 * Die Pruefzeile sm_haken_probe() im Reiter Test haelt die Liste gegen die Themen des Reiters MQTT
 * (sm_mqtt_themen(), eine eigene Aufstellung dessen, was die Dienste senden).
 *
 * Rueckgabe: Liste array(Datenpunkt, Bedeutung, Merker, Textfeld); Merker '' oder 'Noncached'.
 */
function sm_v2_haken()
{
    $aus = array();
    $gesehen = array();
    foreach (sm_namen_gruppen() as $g) {
        if (!$g[2]) {
            continue;
        }
        foreach ($g[3] as $r) {
            if (isset($gesehen[$r[0]])) {
                continue;
            }
            $gesehen[$r[0]] = true;
            $aus[] = array($r[0], $r[1], $r[4], $r[3]);
        }
    }
    return $aus;
}

/** Die Hakenliste als Tabelle (roh auszugeben; jeder Text geht durch sm_e()). */
function sm_v2_haken_html()
{
    $z = sm_v2_haken();
    if (!$z) {
        return '<p class="sm-small">' . sm_e(sm_t('LOX.HAKEN_LEER')) . '</p>';
    }
    $o = '<div class="sm-breit"><table class="sm-tbl"><tr><th>' . sm_e(sm_t('LOX.SP_DATENPUNKT'))
       . '</th><th>' . sm_e(sm_t('ALLG.BEDEUTUNG')) . '</th><th style="width:14%">'
       . sm_e(sm_t('LOX.SP_ZUSATZ')) . '</th></tr>';
    foreach ($z as $r) {
        $o .= '<tr><td class="sm-mono">' . sm_e($r[0]) . '</td><td>' . sm_e($r[1]) . '</td><td>'
            . ($r[2] !== '' ? sm_e($r[2]) : '&ndash;') . '</td></tr>';
    }
    return $o . '</table></div><p class="sm-small">' . sm_e(sm_t('LOX.HAKEN_QUELLE')) . '</p>';
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

/**
 * Ist vzlogger.json heil? 'fehlt' | 'ok' | 'kaputt' (O8, Durchgang 01.10.2026).
 *
 * sm_vz_read() faellt bei einer abgeschnittenen Datei still auf die Vorgaben
 * zurueck - gemessen: Haken "vzLogger" aus, Reiter Test gruen, und das naechste
 * Speichern haette die echte Einstellung mit der Vorgabe ueberschrieben
 * (Oberflaechenbericht O8). Leer oder unlesbar ist ebenfalls kaputt - es ist
 * der Abbruch eines Schreibvorgangs, kein leerer Zustand.
 */
function sm_vz_lage()
{
    $datei = sm_paths()['vzjson'];
    clearstatcache(true, $datei);
    if (!file_exists($datei)) {
        return 'fehlt';
    }
    if (!is_readable($datei)) {
        return 'kaputt';
    }
    $roh = (string) @file_get_contents($datei);
    if (trim($roh) === '') {
        return 'kaputt';
    }
    return is_array(json_decode($roh, true)) ? 'ok' : 'kaputt';
}

function sm_vz_write($cfg)
{
    // 0600 (Gruppe 35, T1-1 A) - wie smartmeter.cfg.
    if (!sm_json_schreiben(sm_paths()['vzjson'], $cfg, 0600)) {
        return false;
    }
    sm_zweitschrift_ziehen('vzlogger.json');     // 2.8.23: bei jedem Speichern
    return true;
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
    // Gruppe 35 (2.8.13, T2-5): fester Modus 0600 statt umask, Rechte vor dem Inhalt (sm_atomar_schreiben).
    if (!sm_json_schreiben($p['vzconf'], $conf, 0600)) {
        return false;
    }
    sm_zweitschrift_ziehen('vzlogger.conf');     // 2.8.23: bei jedem Speichern
    return true;
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
    // 0600 (Gruppe 35, Regeln/05 :358; bis 2.8.12 0640): die Datei fuehrt das Zugriffstoken des
    // Endpunkts. Die Rechte gehoeren an das Anlegen, nicht hinterher.
    if (!sm_atomar_schreiben(sm_paths()['legacy'], $z, 0600)) {
        return false;
    }
    // 2.8.23 (Entscheidung des Hausherrn 18.11.2026 "Ja, beim Speichern"): jedes Speichern zieht die Zweitschrift mit.
    sm_zweitschrift_ziehen('smartmeter.cfg');
    return true;
}

/** 2.8.23: die Zweitschrift einer Datei des Konfigordners - NEBEN dem Ordner, Name wie in preupgrade.sh/postinstall.sh. */
function sm_zweitschrift_pfad($name)
{
    $p = sm_paths();
    return $p['home'] . '/config/plugins/' . $p['plugin'] . '.backup.' . $name;
}

/**
 * 2.8.23 (Entscheidung des Hausherrn 18.11.2026 "Ja, beim Speichern"): die Zweitschrift nach jedem Schreiben
 * nachziehen - bis 2.8.22 entstand sie nur in preupgrade.sh, und eine Aenderung in der Oberflaeche ueberlebte ein
 * gescheitertes postupgrade.sh nicht. Wie dort (netz_zweitschrift) nur aus einer Datei, die ihr Merkmal traegt
 * ([MAIN], "enabled", "meters"), und wie dort nie aus einer Datei, die noch einen Platzhalter traegt (REPLACEBY...):
 * sie ist die Werkseinstellung, keine Einstellung dieser Anlage, und eine gute Zweitschrift bleibt dann stehen
 * (Entscheidung des Hausherrn 18.11.2026, Frage 41 der Nacht: "Ich folge deinen Empfehlungen"; bis dahin fehlte diese
 * Wache hier). Byte-gleich mit dem Original, mit dessen Rechten (0600), unteilbar geschrieben. Ein Fehlschlag oder
 * eine uebergangene Datei steht im Protokoll; das Speichern selbst ist dann gelungen, und beim Zurueckspielen meldet
 * das Modul die abweichende Zweitschrift.
 */
function sm_zweitschrift_ziehen($name)
{
    $p = sm_paths();
    $merkmal = array('smartmeter.cfg' => '[MAIN]', 'vzlogger.json' => '"enabled"', 'vzlogger.conf' => '"meters"');
    if ((string) $p['home'] === '' || !isset($merkmal[$name])) {
        return false;
    }
    $quelle = $p['config'] . '/' . $name;
    clearstatcache(true, $quelle);
    $inhalt = @file_get_contents($quelle);
    if (!is_string($inhalt) || $inhalt === '' || strpos($inhalt, $merkmal[$name]) === false) {
        sm_log_wenn_neu('zweitschrift_' . $name, 'Zweitschrift ' . $name . ' nicht nachgezogen: die Datei fehlt, ist leer '
                        . 'oder traegt ihr Merkmal nicht.', 'WARN');
        return false;
    }
    if (strpos($inhalt, 'REPLACEBY') !== false) {
        sm_log_wenn_neu('zweitschrift_' . $name, 'Zweitschrift ' . $name . ' nicht nachgezogen: die Datei traegt noch '
                        . 'Platzhalter (REPLACEBY...); die bisherige Zweitschrift bleibt.', 'WARN');
        return false;
    }
    $rechte = @fileperms($quelle);
    $rechte = is_int($rechte) ? ($rechte & 0777) : 0600;
    if (!sm_atomar_schreiben(sm_zweitschrift_pfad($name), $inhalt, $rechte)) {
        sm_log('Zweitschrift ' . $name . ' liess sich nicht schreiben.', 'WARN');
        return false;
    }
    return true;
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
        /* Gruppe 35 (2.8.13, E-E8): Schalter des unangemeldeten Endpunkts (index.php) und des Lastgangs
         * (lastgang.php). Die mitgelieferte smartmeter.cfg traegt 0 (Neuinstallation: aus); fehlt der
         * Schluessel, ist es eine Anlage von 2.8.12 oder aelter - dort war beides offen, also 1
         * (postupgrade.sh schreibt ihn; diese Vorgabe ist nur der Rueckfall). */
        'ENDPUNKT_EIN' => '1',
        'LASTGANG_EIN' => '1',
        /* 2.8.18: Anbieter haus/energie (ab Werk aus - nur ein Anbieter je Haus) und der Lesekopf am
         * Netzanschluss (leer = der einzige eingeschaltete). Fehlt der Schluessel, gilt aus. */
        'HAUS_ENERGIE_EIN' => '0',
        'HAUS_ENERGIE_ZAEHLER' => '',
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

/**
 * Installation anstossen (ueber sudoers freigegeben, seit 2.8.13 NUR diese
 * eine Form: "vzlogger_pkg.sh install").
 *
 * Gruppe 35 (2.8.13, Pruefbericht T5-2, Entscheidung 7): bis 2.8.12 lief der
 * Aufruf im Vordergrund ohne Frist - curl und apt-get hatten keine, und die
 * Seite hing, solange die Paketquelle nicht antwortete. Jetzt im Hintergrund
 * (eigene Sitzung, Ein-/Ausgabe umgelenkt), hoechstens 900 s, die Ausgabe in
 * data/plugins/<ordner>/vz_install.txt; solange die Marke vz_install.laeuft
 * liegt, sagt der Reiter "laeuft ..." (sm_vz_install_stand()).
 */
function sm_vz_install()
{
    $p = sm_paths();
    $h = $p['bin'] . '/vzlogger_pkg.sh';
    if (!is_executable($h)) {
        return sprintf(sm_t('VZ.PAKETHELFER_FEHLT'), $h);
    }
    $marke = $p['datadir'] . '/vz_install.laeuft';
    $aus = $p['datadir'] . '/vz_install.txt';
    if (is_file($marke) && (time() - (int) @filemtime($marke)) < 960) {
        return sm_vz_install_stand();
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    if (@file_put_contents($marke, (string) time()) === false) {
        return sprintf(sm_t('VZ.INSTALL_NICHT_GESTARTET'), sm_e($marke));
    }
    sm_log('vzlogger-Installation angestossen (im Hintergrund, hoechstens 900 s).');
    $innen = 'timeout -k 10 900 sudo -n ' . escapeshellarg($h) . ' install > ' . escapeshellarg($aus)
           . ' 2>&1; echo "rc=$?" >> ' . escapeshellarg($aus) . '; rm -f ' . escapeshellarg($marke);
    sm_sh('nohup setsid sh -c ' . escapeshellarg($innen) . ' < /dev/null > /dev/null 2>&1 &');
    return sm_t('VZ.INSTALL_GESTARTET');
}

/** Was die Installation im Hintergrund gerade sagt - '' heisst: nichts zu zeigen. */
function sm_vz_install_stand()
{
    $p = sm_paths();
    $marke = $p['datadir'] . '/vz_install.laeuft';
    $aus = $p['datadir'] . '/vz_install.txt';
    $text = is_readable($aus) ? (string) @file_get_contents($aus, false, null, 0, 65536) : '';
    if (is_file($marke) && (time() - (int) @filemtime($marke)) < 960) {
        return sprintf(sm_t('VZ.INSTALL_LAEUFT'), max(0, time() - (int) @filemtime($marke)))
             . ($text !== '' ? "\n\n" . $text : '');
    }
    if ($text !== '' && (time() - (int) @filemtime($aus)) < 3600) {
        return $text;
    }
    return '';
}

/**
 * Die Prozessnummern UNSERES vzlogger - argumentweise ueber /proc.
 *
 * BERICHTIGT MIT 2.8.3. Bis 2.8.2 stand hier
 *
 *     pgrep -f -- "-c <unsere vzlogger.conf>"
 *
 * mit einer Gegenprobe ueber "ps -o comm=". Die Gegenprobe war richtig, das
 * Suchmuster nicht: "-f" vergleicht eine Teilzeichenkette der GANZEN
 * Befehlszeile und trifft deshalb auch die Schale, die pgrep aufruft, und
 * jeden fremden Prozess, in dem der Pfad hinter einem "-c" vorkommt. Am
 * 18.09.2026 in WSL gemessen: der Aufruf lieferte RC=15 - pkill an derselben
 * Stelle hatte die eigene Huellschale mit beendet
 * (Pruefung-Smartmeter-classic-2.8.3/messe_pkill.vorher.txt, Fall B2).
 *
 * Ein Treffer traegt jetzt die beiden Argumente "-c" (oder "--config") und
 * GENAU unsere Konfigurationsdatei, und der Kern nennt ihn vzlogger.
 * /proc/<pid>/comm ist derselbe Wert, den "ps -o comm=" ausgibt - nur ohne
 * Prozessstart, und er stammt vom Kern und nicht aus der frei
 * beschreibbaren Befehlszeile. Dieselbe Erkennung fahren daemon/daemon
 * und uninstall/uninstall.
 *
 * Rueckgabe: Feld von Prozessnummern als Zeichenketten, aufsteigend.
 */
function sm_vz_pids()
{
    $conf = sm_paths()['vzconf'];
    $gefunden = array();
    // Erst fragen, dann oeffnen: ein @opendir auf ein fehlendes Verzeichnis
    // ist stumm, aber ein gesetzter Fehlerbehandler sieht die Warnung
    // trotzdem - dieselbe Stelle wie in sm_log_ende(). Beim Rendern unter
    // Windows (Werkzeuge/rendern.py) gibt es /proc nicht.
    if (!is_dir('/proc')) {
        return $gefunden;
    }
    $dh = @opendir('/proc');
    if ($dh === false) {
        return $gefunden;
    }
    while (($e = readdir($dh)) !== false) {
        if (!preg_match('/^[0-9]+$/', $e)) {
            continue;
        }
        // Ein Prozess kann zwischen readdir und Lesen enden - deshalb erst
        // fragen und den Lesefehler still lassen.
        if (!is_readable('/proc/' . $e . '/cmdline')) {
            continue;
        }
        $roh = @file_get_contents('/proc/' . $e . '/cmdline');
        if ($roh === false || $roh === '') {
            continue;
        }
        $args = explode("\0", $roh);
        $anzahl = count($args);
        $treffer = false;
        for ($i = 0; $i + 1 < $anzahl; $i++) {
            if (($args[$i] === '-c' || $args[$i] === '--config')
                && $args[$i + 1] === $conf) {
                $treffer = true;
                break;
            }
        }
        if (!$treffer) {
            continue;
        }
        if (!is_readable('/proc/' . $e . '/comm')) {
            continue;
        }
        $comm = trim((string) @file_get_contents('/proc/' . $e . '/comm'));
        if ($comm === '' || stripos($comm, 'vzlogger') === false) {
            continue;
        }
        $gefunden[] = $e;
    }
    closedir($dh);
    sort($gefunden, SORT_NUMERIC);
    return $gefunden;
}

/**
 * PIDs unseres vzlogger als Zeichenkette, durch Leerzeichen getrennt.
 *
 * Zwischengespeichert: bis 2.3.14 lief sie je Seitenaufbau dreimal.
 */
function sm_vz_running()
{
    static $erg = null;
    if ($erg !== null) {
        return $erg;
    }
    $erg = implode(' ', sm_vz_pids());
    return $erg;
}

/**
 * Die gepufferten Befunde des Reiters Test verwerfen.
 *
 * Beide Puffer, nicht nur einer: endpunkt.json fehlte bis 2.4.2. Genau
 * ihn braucht es aber - lox_token_neu und lox_token_weg rufen diese
 * Funktion, und nach "Token entfernen" steht der Endpunkt jedem Geraet im
 * Netz offen, waehrend die Pruefzeile bis zu 300 s weiter den alten Haken
 * zeigte. Eine Selbstpruefung, die beruhigt, ist schlimmer als keine.
 *
 * Der Zwischenspeicher der drei Prozessabfragen ist hiermit NICHT gemeint
 * - der liegt in static-Variablen, die diese Funktion nicht erreicht.
 * Dafuer gibt es sm_vz_running_frisch().
 */
function sm_cache_verwerfen()
{
    $p = sm_paths();
    @unlink($p['datadir'] . '/diagnose.json');
    @unlink($p['datadir'] . '/endpunkt.json');
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

/**
 * Die eigenen vzlogger-Prozesse beenden - gezielt, nach Nummer.
 *
 * BERICHTIGT MIT 2.8.3. Bis 2.8.2 stand hier eine Zeile:
 *
 *     sm_sh('pkill -f -- ' . escapeshellarg('-c ' . $p['vzconf']));
 *
 * exec() startet dafuer eine Schale, und deren eigene Befehlszeile traegt
 * das Suchmuster - pkill hat sich also selbst mit beendet. Am 18.09.2026 in
 * WSL gemessen (messe_pkill.vorher.txt, Fall B2): "RC=15". Mitgestorben sind
 * ausserdem ein Koeder "nano -c <unsere vzlogger.conf>" und eine fremde
 * Schale, die den Pfad nur ausgab (Fall B).
 *
 * Gesucht wird jetzt mit sm_vz_pids() - argumentweise - und signalisiert
 * wird je Nummer. Vor JEDEM Signal wird neu gesucht (Regeln/02): zwischen
 * zwei Durchgaengen kann eine Nummer frei werden und einem fremden Prozess
 * gehoeren. Behandelt werden ALLE Treffer, nicht nur der erste; nach einem
 * Upgrade kann ein verwaister vzlogger neben dem neuen stehen.
 *
 * Rueckgabe: array('beendet' => [...], 'uebrig' => [...]).
 */
function sm_vz_anhalten()
{
    $vorher = sm_vz_pids();
    if (!$vorher) {
        return array('beendet' => array(), 'uebrig' => array());
    }
    foreach (array('TERM' => 2, 'KILL' => 1) as $signal => $warten) {
        $offen = sm_vz_pids();
        if (!$offen) {
            break;
        }
        foreach ($offen as $pid) {
            // Nur die Nummer geht in die Befehlszeile, kein Suchmuster.
            sm_sh('kill -' . $signal . ' ' . (int) $pid);
        }
        sleep($warten);
    }
    $uebrig = sm_vz_pids();
    return array(
        'beendet' => array_values(array_diff($vorher, $uebrig)),
        'uebrig'  => $uebrig,
    );
}

/** Der Pfad der Upgrade-Marke - NEBEN dem Datenordner. */
function sm_upgrade_marke()
{
    $d = sm_paths()['datadir'];
    return dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
}

/**
 * Laeuft gerade eine Aktualisierung dieses Plugins?
 *
 * NEU MIT 2.8.3. preupgrade.sh legt die Marke als Erstes an, postroot.sh
 * entfernt sie ueber einen trap. Nur eine Marke, die hoechstens eine Stunde
 * alt ist, zaehlt; aelter, aus der Zukunft oder unlesbar gilt sie nicht -
 * eine abgebrochene Installation darf den Zaehler nicht fuer immer
 * stilllegen. Die Uhr steht in PHP ueber time() immer zur Verfuegung; der
 * Fall "keine lesbare Uhr", der in daemon/daemon geschlossen ausfaellt,
 * kommt hier nicht vor.
 */
function sm_upgrade_laeuft()
{
    $m = sm_upgrade_marke();
    if (!is_file($m)) {
        return false;
    }
    $roh = trim((string) @file_get_contents($m));
    if (!preg_match('/^[0-9]+$/', $roh)) {
        return false;
    }
    $alter = time() - (int) $roh;
    return ($alter >= 0 && $alter < 3600);
}

/**
 * vzlogger anhalten und - bei eingeschalteter Betriebsart - wieder starten.
 *
 * SEIT DEM DURCHGANG 01.10.2026 (C3, O5): die Rueckgabe sagt, was GESCHAH:
 *     array('lage' => 'gestartet' | 'angehalten' | 'gescheitert', 'text' => ...)
 * 'text' ist bei gescheitert der Grund (fuer den Anwender), sonst leer.
 * Bis 2.8.5 kam bei ausgeschalteter Betriebsart '' zurueck, und die
 * Oberflaeche meldete "vzlogger wurde neu gestartet", obwohl er angehalten
 * war; blieb ein Prozess uebrig, ebenso (gemessen, Oberflaechenbericht O5,
 * Codebericht Nr. 8). Der Ablauf selbst ist unveraendert: auch wenn ein alter
 * Prozess stehen bleibt, wird wie bisher ein Start versucht - das Urteil ist
 * dann trotzdem "gescheitert" (Frage im Baubericht).
 */
function sm_vz_restart($cfg)
{
    $p = sm_paths();
    @mkdir($p['shm'], 0775, true);
    $halt = sm_vz_anhalten();
    sm_cache_verwerfen();
    $uebrig = '';
    if ($halt['uebrig']) {
        $uebrig = implode(' ', $halt['uebrig']);
        sm_log('vzlogger liess sich nicht beenden (PID ' . $uebrig . ').', 'ERROR');
    } elseif ($halt['beendet']) {
        sm_log('vzlogger beendet (PID ' . implode(' ', $halt['beendet']) . ').');
    }
    if (!$cfg['enabled']) {
        if ($uebrig !== '') {
            return array('lage' => 'gescheitert', 'text' => sprintf(sm_t('VZ.NICHT_BEENDET'), $uebrig));
        }
        sm_log('vzlogger angehalten (Betriebsart ausgeschaltet).');
        return array('lage' => 'angehalten', 'text' => '');
    }
    // Waehrend einer Aktualisierung wird nicht gestartet: der alte Prozess
    // laeuft weiter, und ein zweiter auf derselben seriellen Schnittstelle
    // ist der Schaden, den die Marke verhindert (Begruendung in
    // preupgrade.sh). Angehalten wird oben trotzdem - das ist der
    // ausdrueckliche Wunsch des Anwenders.
    if (sm_upgrade_laeuft()) {
        sm_vz_note('START ABGEBROCHEN: eine Aktualisierung dieses Plugins laeuft.');
        sm_log('vzlogger nicht gestartet - eine Aktualisierung laeuft.', 'WARNING');
        return array('lage' => 'gescheitert', 'text' => sm_t('VZ.UPGRADE_LAEUFT'));
    }
    list($bin, $warum) = sm_vz_binary();
    if ($bin === '') {
        sm_vz_note('START ABGEBROCHEN: ' . $warum);
        sm_log('vzlogger liess sich nicht starten: ' . $warum, 'ERROR');
        return array('lage' => 'gescheitert', 'text' => $warum);
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
        return array('lage' => 'gescheitert', 'text' => sm_t('VZ.START_GEFALLEN'));
    }
    sm_vz_note('gestartet: ' . $bin . ' (PID ' . $pid . ')');
    sm_log('vzlogger gestartet, PID ' . $pid . '.');
    if ($uebrig !== '') {
        return array('lage' => 'gescheitert', 'text' => sprintf(sm_t('VZ.NICHT_BEENDET'), $uebrig));
    }
    return array('lage' => 'gestartet', 'text' => '');
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
    $pids = sm_vz_pids();
    return $pids ? $pids[0] : '';
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
 * Die Verbrauchshistorie - nur LESEN
 *
 * Geschrieben wird sie von bin/sm_historie.php aus dem Cron. Die
 * Oberflaeche sieht sie nur an: zwei Schreiber auf derselben Datei waeren
 * zwei Wahrheiten, und der Cron-Lauf haette keine Chance gegen einen
 * Seitenaufbau.
 * ================================================================== */

/**
 * Wie steht es um den Lastgang? Gemessen an der Datei, nicht behauptet.
 *
 * Rueckgabe: stunden_heute (wieviele der 24 Stunden des heutigen Tages
 * gedeckt sind - genau danach entscheidet spot_lastgang() im
 * Spotpreis-Plugin, ob es die Messung nimmt oder sein eingebautes
 * Profil), zeilen (insgesamt), offen (Stunden ohne belegte Einheit).
 */
function sm_lastgang_lage()
{
    $p = sm_paths();
    $erg = array('stunden_heute' => 0, 'zeilen' => 0, 'offen' => 0, 'da' => false);
    $datei = $p['datadir'] . '/historie.csv';
    // clearstatcache, weil derselbe Prozess die Datei mehrfach ansieht
    // und der Cron sie zwischendurch verlaengert haben kann.
    clearstatcache(true, $datei);
    if (!is_readable($datei)) {
        return $erg;
    }
    $erg['da'] = true;
    $fh = @fopen($datei, 'rb');
    if ($fh === false) {
        return $erg;
    }
    $heute = strtotime('today');
    $stunden = array();
    while (($z = fgets($fh)) !== false) {
        $z = trim($z);
        if ($z === '' || strncmp($z, 'stunde;', 7) === 0) { continue; }
        $f = explode(';', $z);
        if (count($f) < 7) { continue; }
        $erg['zeilen']++;
        if ($f[4] !== 'Wh') { $erg['offen']++; continue; }
        $st = (int) $f[0];
        if ($st >= $heute && $st < $heute + 86400) { $stunden[$st] = true; }
    }
    fclose($fh);
    $erg['stunden_heute'] = count($stunden);
    return $erg;
}

/**
 * Der Stand des Fahrplan-Abgleichs - nur LESEN.
 *
 * Geschrieben wird er von bin/sm_abgleich.php aus dem Cron. Zwei Schreiber
 * auf derselben Datei waeren zwei Wahrheiten.
 */
function sm_abgleich_stand()
{
    $p = sm_paths();
    $datei = $p['datadir'] . '/abgleich.json';
    clearstatcache(true, $datei);
    if (!is_readable($datei)) {
        return array('regeln' => array(), 'ts' => 0, 'quelle_ok' => 0,
                     'grund' => '', 'da' => false);
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d)) {
        return array('regeln' => array(), 'ts' => 0, 'quelle_ok' => 0,
                     'grund' => 'UNLESBAR', 'da' => true);
    }
    return array_merge(array('regeln' => array(), 'ts' => 0, 'quelle_ok' => 0,
                             'grund' => '', 'da' => true), $d);
}

/**
 * Der zuletzt gerechnete Kostenstand aus data/plugins/<ordner>/kosten.json.
 *
 * Wie bei sm_abgleich_stand(): "da" sagt, ob bin/sm_kosten.php ueberhaupt
 * schon gelaufen ist. Ein fehlender Wert bleibt fehlend - die Oberflaeche
 * zeigt dann einen Strich, keine Null. Eine Null hiesse, es habe nichts
 * gekostet.
 */
function sm_kosten_stand()
{
    $p = sm_paths();
    $datei = $p['datadir'] . '/kosten.json';
    clearstatcache(true, $datei);
    $leer = array('ts' => 0, 'quelle_ok' => 0, 'grund' => '', 'da' => false);
    if (!is_readable($datei)) {
        return $leer;
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d)) {
        $leer['da'] = true;
        $leer['grund'] = 'UNLESBAR';
        return $leer;
    }
    $leer['da'] = true;
    return array_merge($leer, $d);
}

/**
 * Gruppe 35 (2.8.13, E-E14): die letzten $n Tage aus kosten_historie.csv,
 * juengster zuerst - nur lesen. Eine Zeile ist ein Feld mit den Spalten
 * datum, kwh, ct, eur, stunden, offen, ohne_preis, stunde23, zeilen.
 */
function sm_kosten_tage($n = 14)
{
    $datei = sm_paths()['datadir'] . '/kosten_historie.csv';
    if (!is_readable($datei)) {
        return array();
    }
    $kopf = array('datum', 'kwh', 'ct', 'eur', 'stunden', 'offen', 'ohne_preis', 'stunde23', 'zeilen');
    $aus = array();
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $z) {
        $f = explode(';', trim((string) $z));
        if (count($f) !== count($kopf) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $f[0])) {
            continue;
        }
        $aus[] = array_combine($kopf, $f);
    }
    return array_reverse(array_slice($aus, -(int) $n));
}

/**
 * Gruppe 35 (2.8.13, Entscheidung 5): die Lage des Lesers haus/speicher als
 * array(Zustand fuer sm_farbe(), Text) - '' wenn der Haken aus ist.
 */
function sm_speicher_lage()
{
    if (sm_cfg_get(sm_cfg_read(), 'ABGLEICH', 'SPEICHER_EIN', '0') !== '1') {
        return array('', '');
    }
    $d = json_decode((string) @file_get_contents(sm_paths()['shm'] . '/speicher_lage.json'), true);
    if (!is_array($d) || !isset($d['ts'])) {
        return array('warn', sm_t('AB.SL_NOCH_KEINE'));
    }
    $alter = time() - (int) $d['ts'];
    $u = isset($d['urteil']) ? (string) $d['urteil'] : '';
    if ($alter < 0 || $alter > 120 || $u === 'quelle_schweigt') {
        return array('warn', sprintf(sm_t('AB.SL_QUELLE_SCHWEIGT'), max(0, $alter)));
    }
    if ($u === 'entlaedt') {
        return array('warn', sprintf(sm_t('AB.SL_ENTLAEDT'), $alter));
    }
    if ($u === 'entlaedt_nicht') {
        return array('ok', sprintf(sm_t('AB.SL_ENTLAEDT_NICHT'), $alter));
    }
    return array('warn', sprintf(sm_t('AB.SL_OHNE_AUSSAGE'), $alter));
}

/* ==================================================================
 * Loxone-Vorlagen
 * ================================================================== */

/**
 * Die Felder, die bin/fetch_vzlogger.pl SELBST bildet - nicht der Zaehler.
 *
 * Der Unterschied entscheidet ueber die Einheit: was das Plugin bildet,
 * hat eine bekannte (die Zeitstempel sind Sekunden), was vom Zaehler
 * kommt, geht auf dem vzLogger-Weg ungewandelt durch. Siehe
 * sm_einheit_fuer().
 *
 * Die beiden abgeleiteten Leistungen stehen NICHT hier: sie werden aus
 * 16.7.0 gerechnet und tragen deshalb dessen Einheit. Seit 2.8.0 ist die
 * am Zaehler gemessen (W), und der Katalog fuehrt sie mit quelle_vz
 * "code" - die Angabe ist am eigenen Quelltext abgelesen, nicht am
 * Zaehler.
 */
function sm_vz_eigene()
{
    return array('Last_Update', 'Last_UpdateUnix', 'Last_UpdateLoxEpoche');
}

/** Die Felder, die auf dem vzLogger-Weg wirklich veroeffentlicht werden. */
function sm_vz_felder($cfg)
{
    $f = array();
    foreach ($cfg['channels'] as $c) {
        $f[] = sm_obis_feld($c);
    }
    // Genau die Zusaetze, die bin/fetch_vzlogger.pl selbst bildet.
    foreach (sm_vz_eigene() as $sm_e) {
        $f[] = $sm_e;
    }
    if (in_array('Total_Power_OBIS_16.7.0', $f, true)) {
        $f[] = 'Consumption_CalculatedPower_OBIS_1.99.0';
        $f[] = 'Delivery_CalculatedPower_OBIS_2.99.0';
    }
    return array_values(array_unique($f));
}

/**
 * Einheit und Grenzen eines Feldes - je nach Leseweg verschieden.
 *
 * DER GRUND STEHT IM KATALOG SELBST. bin/sm_felder.json sagt zu 'einheit':
 * "gilt fuer den KLASSISCHEN Weg; bin/sml_parser.php rechnet Wh auf kWh und
 * W auf kW um" - und zu 'einheit_vz': "LEER und das mit Absicht: vzlogger
 * rechnet nicht um, es reicht den Wert des Zaehlers durch."
 *
 * Bis 2.4.3 nahm die Vorlage BEIDER Wege 'einheit'. Auf dem vzLogger-Weg
 * stand damit "<v.3> kWh" an einem Eingang, der moeglicherweise rohe Wh
 * bekommt - und MaxVal=1000000 kappte einen Zaehlerstand in Wh schon nach
 * 1000 kWh. Ein Eingang, der bei einem Messwert stehenbleibt, sieht in der
 * Visualisierung aus wie ein ruhiger Zaehler.
 *
 * Solange 'einheit_vz' leer ist - und leer ist sie, weil es ohne Zaehler
 * nicht zu messen war -, bekommt der vzLogger-Weg KEINE Einheit und weite
 * Grenzen. Das ist die einzige Angabe, die nichts behauptet: lieber eine
 * nackte Zahl als eine falsche Einheit, und lieber ein weiter Bereich als
 * ein gekappter Wert.
 *
 * SEIT 2.8.0 IST einheit_vz FUER FUENF FELDER BELEGT (am Zaehler
 * gemessen, siehe Werkzeuge/sm_felder_erzeugen.py). Damit kommt die
 * Einheit zurueck in die Vorlage - und mit ihr eine Falle: die Grenzen im
 * Katalog gelten fuer die UMGERECHNETE Einheit des klassischen Weges. Ein
 * Zaehlerstand von 11.088.277 Wh sprengt MaxVal=1000000 (kWh) um den
 * Faktor elf, und Loxone kappt ihn - der Eingang bliebe stehen und saehe
 * aus wie ein ruhiger Zaehler.
 *
 * Deshalb werden Grenzen UND Nachkommastellen mit umgerechnet, wenn sich
 * die beiden Einheiten nur um das k unterscheiden (kWh->Wh, kW->W): mal
 * 1000 bei den Grenzen, drei Stellen weniger hinter dem Komma. Das ist
 * genau die Umrechnung, die bin/sml_parser.php auf dem klassischen Weg in
 * die andere Richtung macht - keine neue Annahme, sondern dieselbe Regel.
 *
 * Rueckgabe: array(Einheit, MinVal, MaxVal, Nachkommastellen).
 */
function sm_einheit_fuer($md, $weg, $feld = '')
{
    // Ohne Katalogeintrag wird nichts erfunden - weder Einheit noch Grenzen.
    if (!$md) {
        return array('', -1000000000, 1000000000, 0);
    }
    // Unsicher ist nur, was der ZAEHLER liefert. Die Zeitstempel bildet
    // das Plugin selbst (time() und die Loxone-Epoche) - dort ist die
    // Einheit Sekunden, und daran aendert der Leseweg nichts. Sie mit
    // wegzunehmen waere kein Vorbehalt, sondern ein Verlust.
    if ($weg === 'vz' && !in_array($feld, sm_vz_eigene(), true)) {
        $eh = isset($md['einheit_vz']) ? (string) $md['einheit_vz'] : '';
        if ($eh !== '') {
            /* Belegt. Unterscheiden sich die Einheiten nur um das k, wird
             * mit umgerechnet - sonst gelten die Grenzen des Katalogs
             * unveraendert (dann ist die vz-Einheit dieselbe wie die des
             * klassischen Weges, und es gibt nichts umzurechnen). */
            $faktor = 1;
            $kat_eh = (string) $md['einheit'];
            if ($kat_eh === 'k' . $eh) {
                $faktor = 1000;
            }
            $nk = (int) $md['nk'];
            if ($faktor > 1) {
                $nk = max(0, $nk - 3);
            }
            return array($eh, (int) $md['min'] * $faktor,
                         (int) $md['max'] * $faktor, $nk);
        }
        {
            // Ungemessen: keine Einheit, und eine Obergrenze, die auch
            // eine rohe Wh-Zahl traegt. Die Grenzen im Katalog gelten
            // fuer die UMGERECHNETE Einheit des klassischen Weges.
            //
            // Nach UNTEN bleibt es beim Katalog: ob eine Groesse negativ
            // werden kann, ist eine Eigenschaft der Groesse und keine
            // der Einheit. Ein Zaehlerstand wird nicht negativ, nur weil
            // niemand weiss, ob er in Wh oder kWh kommt.
            return array('', $md['signed'] ? -1000000000 : 0, 1000000000, 0);
        }
    }
    return array((string) $md['einheit'], (int) $md['min'], (int) $md['max'],
                 (int) $md['nk']);
}

/**
 * Die Namenstabelle der Gateway-Namen (Gruppe 1, 2.8.11, Nachtrag 19.10.2026).
 *
 * Fuer Werte ueber das MQTT-Gateway gibt es keine Importvorlage mehr (bis 18.10. ein virtueller
 * HTTP-Eingang mit der Adresse http://localhost): die LoxBerry-Anleitung (Wiki, MQTT-Widget) und
 * Regeln/07 verlangen je Wert einen einfachen Virtuellen Eingang bzw. Virtuellen Texteingang mit genau
 * dem Namen, den das Gateway bildet (sm_ve_name(): nur / und % werden zu _). Diese Funktion liefert die
 * Namen je Gruppe - EINE Quelle fuer die Tabelle in Schritt 3 und die Hakenliste fuer Gateway V2.
 *
 * Gruppen in der Reihenfolge der Seite: vzLogger (die eingestellten Kanaele samt den Feldern, die
 * fetch_vzlogger.pl selbst bildet), klassischer Leser (je Lesekopf mit Zaehlerprofil die Felder der
 * letzten Messung - dieselbe Auswahl wie sm_mqtt_themen()), Lebenszeichen, Kostenrechnung,
 * Fahrplan-Abgleich (Regel 1). Jede Gruppe traegt, ob sie gerade eingeschaltet ist; die Tabelle zeigt
 * alle, die Hakenliste nur die eingeschalteten. Textfelder stehen mit Art "Text" darin.
 *
 * Rueckgabe: Liste array(Schluessel, Ueberschrift, eingeschaltet, Zeilen, Hinweis); eine Zeile ist
 * array(Name, Bedeutung, Einheit und Bereich, Textfeld, Merker) mit Merker '' oder 'Noncached'.
 */
function sm_namen_gruppen()
{
    $legacy  = sm_legacy_read();
    $vz      = sm_vz_read();
    $alles   = sm_cfg_read();
    $praefix = $legacy['MQTTTOPIC'];
    $pr      = trim((string) $praefix, '/');
    /* Noncached (Entwurf E7; Gruppe 35, E-E5, N4): EINE Liste - sm_noncached_themen() (status/ok,
     * status/zaehler, kosten/quelle_ok, abgleich/quelle_ok, abgleich/<n>/ok je Regel). */
    $regeln = sm_abgleich_regeln();
    $nc = array();
    foreach (sm_noncached_themen() as $th) {
        $nc[] = str_replace(array('/', '%'), '_', $pr . '/' . $th);
    }
    $gr = array();

    $z = array();
    foreach (sm_vz_felder($vz) as $feld) {
        $z[] = sm_namen_zeile(sm_ve_name($praefix, $vz['serial'], $feld), $feld, 'vz');
    }
    $gr[] = array('vz', sm_t('MQ.G_VZ'), !empty($vz['enabled']), $z, '');

    $z = array();
    foreach (sm_koepfe() as $k) {
        $m = isset($k['METER']) ? (string) $k['METER'] : '0';
        if ($m === '' || $m === '0') {
            continue;
        }
        $werte = sm_werte($k['ABSCHNITT']);
        foreach ($werte ? $werte : array() as $pv) {
            $z[] = sm_namen_zeile(sm_ve_name($praefix, $k['ABSCHNITT'], $pv[0]), $pv[0], 'legacy');
        }
    }
    $gr[] = array('legacy', sm_t('MQ.G_KLASSISCH'), $legacy['READ'] === '1', $z,
                  $z ? '' : sm_t('MQ.T_NOCH_KEINE'));

    $z = array();
    $bereich = array('ok' => '0 … 1', 'ts' => 's (Unix)', 'zaehler' => '0 … 999');
    foreach (sm_vorlage_status($praefix) as $e) {
        $t = substr((string) $e[1], strlen('status_'));
        $z[] = array($e[0], (string) $e[2], isset($bereich[$t]) ? $bereich[$t] : '', false,
                     in_array($e[0], $nc, true) ? 'Noncached' : '');
    }
    $gr[] = array('status', sm_t('MQ.G_STATUS'), !empty($vz['enabled']) || $legacy['READ'] === '1', $z, '');

    /* Gruppe 35 (2.8.13, Fremd-E2, Entwurf 5.3): der Fahrplan-Abgleich fuer JEDE Regel, die abgleich.json
     * kennt (hoechstens 5), Regel 1 immer - die Musterseite baut Regel 1, die Namen fuer 2-5 stehen hier.
     * Dazu kosten/gestern_* (Tagesabschluss) und abgleich/<n>/speicher (haus/speicher). */
    $zk = array();
    $za = array();
    foreach ($regeln as $r) {
        foreach (sm_vorlage_kosten_eintraege($praefix, $r) as $e) {
            $th = (string) $e[1];
            $ist_k = (strpos($th, 'kosten/') === 0);
            $ist_q = ($th === 'abgleich/quelle_ok');
            $ist_r = (strpos($th, 'abgleich/' . $r . '/') === 0);
            if ($r !== $regeln[0] && !$ist_r) {
                continue;
            }
            $eh = trim((string) preg_replace('/<v[^>]*>/', '', (string) $e[3]));
            $zeile = array($e[0], (string) $e[2], sm_bereich_text($eh, $e[4][2], $e[4][3]), false,
                           in_array($e[0], $nc, true) ? 'Noncached' : '');
            if ($ist_k) {
                $zk[] = $zeile;
            } elseif ($ist_q || $ist_r) {
                $za[] = $zeile;
            }
        }
    }
    $gr[] = array('kosten', sm_t('MQ.G_KOSTEN'), sm_cfg_get($alles, 'KOSTEN', 'AKTIV', '0') === '1', $zk, '');
    $gr[] = array('abgleich', sm_t('MQ.G_ABGLEICH'), sm_cfg_get($alles, 'ABGLEICH', 'AKTIV', '0') === '1', $za, '');
    return $gr;
}

/** Gruppe 35: die Regelnummern des Fahrplan-Abgleichs (abgleich.json; Regel 1 immer, hoechstens 5). */
function sm_abgleich_regeln()
{
    return function_exists('smg_regeln') ? smg_regeln(sm_paths()['datadir'] . '/abgleich.json') : array(1);
}

/**
 * Gruppe 35 (2.8.13, E-E5, N4): die Themen, die im MQTT-Gateway V2 "Noncached" brauchen - EINE Liste fuer
 * Namenstabelle, Hakenliste, Pruefzeile im Reiter Test und bin/healthcheck (smg_noncached_themen()).
 */
function sm_noncached_themen()
{
    if (function_exists('smg_noncached_themen')) {
        return smg_noncached_themen(sm_abgleich_regeln());
    }
    return array('status/ok', 'status/zaehler', 'kosten/quelle_ok', 'abgleich/quelle_ok', 'abgleich/1/ok');
}

/** Eine Zeile der Namenstabelle fuer ein Feld des Katalogs (Einheit und Grenzen je Leseweg). */
function sm_namen_zeile($name, $feld, $weg)
{
    $md   = sm_feld($feld);
    $text = $md && $md['typ'] === 'text';
    $bed  = $md ? sm_t($md['bed']) : $feld;
    $bereich = '';
    if (!$text) {
        list($eh, $min, $max) = sm_einheit_fuer($md, $weg, $feld);
        $bereich = sm_bereich_text($eh, $min, $max);
    }
    return array((string) $name, (string) $bed, $bereich, $text, '');
}

/** "kWh, 0 … 1000000" bzw. "0 … 9" ohne Einheit. */
function sm_bereich_text($eh, $min, $max)
{
    $b = (string) (0 + $min) . ' … ' . (string) (0 + $max);
    return ((string) $eh !== '') ? $eh . ', ' . $b : $b;
}

/** Die Namenstabelle als HTML (roh auszugeben; jeder Text geht durch sm_e()). */
function sm_namen_html()
{
    $o = '';
    foreach (sm_namen_gruppen() as $g) {
        list(, $titel, $an, $zeilen, $hinweis) = $g;
        $o .= '<p class="sm-small"><b>' . sm_e($titel) . '</b>'
            . ($an ? '' : ' <i>(' . sm_e(sm_t('LOX.NAMEN_AUS')) . ')</i>') . '</p>';
        if (!$zeilen) {
            $o .= '<p class="sm-small">' . sm_e($hinweis) . '</p>';
            continue;
        }
        $o .= '<div class="sm-breit"><table class="sm-tbl sm-namen"><tr><th>' . sm_e(sm_t('LOX.SP_TITEL_VE'))
            . '</th><th style="width:30%">' . sm_e(sm_t('ALLG.BEDEUTUNG')) . '</th><th style="width:18%">'
            . sm_e(sm_t('LOX.SP_BEREICH')) . '</th><th style="width:16%">' . sm_e(sm_t('LOX.SP_ART')) . '</th></tr>';
        foreach ($zeilen as $r) {
            $o .= '<tr><td><span class="sm-mono">' . sm_e($r[0]) . '</span> '
                . '<button type="button" data-role="none" class="sm-kopie" data-name="' . sm_e($r[0])
                . '" data-ok="' . sm_e(sm_t('LOX.NAMEN_KOPIERT')) . '" data-fehl="' . sm_e(sm_t('LOX.NAMEN_MARKIEREN'))
                . '" onclick="return smNameKopieren(this);">' . sm_e(sm_t('LOX.NAMEN_KOPIEREN')) . '</button></td>'
                . '<td>' . sm_e($r[1]) . '</td><td>' . ($r[2] !== '' ? sm_e($r[2]) : '&ndash;') . '</td><td>'
                . sm_e($r[3] ? sm_t('LOX.NAMEN_TEXT') : sm_t('LOX.NAMEN_ZAHL')) . '</td></tr>';
        }
        $o .= '</table></div>';
    }
    return $o;
}

/**
 * Die Eingaenge der Vorlage "Kosten und Fahrplan" (Welle Bild 11 VOLL, 2.8.9).
 *
 * EINE Quelle fuer die Namenstabelle (sm_namen_gruppen(); bis zum Nachtrag 19.10.2026 die Vorlage
 * sm_vorlage_kosten()) und die Baustein-Liste der Seite
 * "Kosten und Fahrplan-Abgleich" im Reiter Einbindung in Loxone. Inhalt wie im LoxBerry-Plugins
 * Musterprojekt (Musterprojekt/vorlagen/VI_smartmeter_kosten.xml): die acht Kostenthemen, das
 * Lebenszeichen des Abgleichs und die sieben Themen einer Regel. Titel = Name, unter dem das
 * MQTT-Gateway den Eingang anlegt (wie sm_ve_name(): nur / und % werden zu _). Die Themen selbst
 * senden bin/sm_kosten.php und bin/sm_abgleich.php - hier aendert sich nichts daran.
 *
 * Rueckgabe: Liste array(Titel, Thema unter dem Praefix, Kommentar, Anzeige,
 * array(Vorzeichen, Vorgabewert, Min, Max)); -1 heisst bei den Kosten und beim Urteil "unbekannt".
 */
function sm_vorlage_kosten_eintraege($praefix, $regel = 1)
{
    $pr = trim((string) $praefix, '/');
    $r  = (string) (int) $regel;
    // Die Schluessel ausgeschrieben - die Pruefung der Sprachschluessel findet nur, was
    // woertlich dasteht.
    $themen = array(
        array('kosten/quelle_ok',  sm_t('LOX.VE_K_QUELLE_OK'),  '<v>',       array(false, 0, 0, 1)),
        array('kosten/stunde_ct',  sm_t('LOX.VE_K_STUNDE_CT'),  '<v.1> ct',  array(true, -1, -1, 100000)),
        array('kosten/heute_ct',   sm_t('LOX.VE_K_HEUTE'),      '<v.1> ct',  array(true, -1, -1, 1000000)),
        array('kosten/heute_eur',  sm_t('LOX.VE_K_HEUTE'),      '<v.2> EUR', array(true, -1, -1, 10000)),
        array('kosten/heute_kwh',  sm_t('LOX.VE_K_HEUTE_KWH'),  '<v.2> kWh', array(true, -1, -1, 100000)),
        array('kosten/stunden',    sm_t('LOX.VE_K_STUNDEN'),    '<v> h',     array(false, 0, 0, 24)),
        array('kosten/offen',      sm_t('LOX.VE_K_OFFEN'),      '<v> h',     array(false, 0, 0, 24)),
        array('kosten/ohne_preis', sm_t('LOX.VE_K_OHNE_PREIS'), '<v> h',     array(false, 0, 0, 24)),
        array('abgleich/quelle_ok', sm_t('LOX.VE_A_QUELLE_OK'), '<v>',       array(false, 0, 0, 1)),
        // Gruppe 1 (2.8.11, E2): eine entfernte Regel bekommt aktiv -1 (bis 2.8.10 "-") - also
        // vorzeichenbehaftet mit Min -1; Vorgabe -1 wie die uebrigen "unbekannt"-Werte.
        array('abgleich/' . $r . '/aktiv',  sprintf(sm_t('LOX.VE_A_AKTIV'), $r), '<v>',       array(true, -1, -1, 1)),
        array('abgleich/' . $r . '/soll',   sprintf(sm_t('LOX.VE_A_SOLL'), $r),  '<v.2> kWh', array(false, 0, 0, 100000)),
        array('abgleich/' . $r . '/ist',    sprintf(sm_t('LOX.VE_A_IST'), $r),   '<v.2> kWh', array(false, 0, 0, 100000)),
        array('abgleich/' . $r . '/fehlt',  sprintf(sm_t('LOX.VE_A_FEHLT'), $r), '<v.2> kWh', array(false, 0, 0, 100000)),
        array('abgleich/' . $r . '/dauer',  sprintf(sm_t('LOX.VE_A_DAUER'), $r), '<v> s',     array(false, 0, 0, 1000000)),
        array('abgleich/' . $r . '/sicher', sm_t('LOX.VE_A_SICHER'),             '<v>',       array(false, 0, 0, 1)),
        array('abgleich/' . $r . '/ok',     sm_t('LOX.VE_A_OK'),                 '<v>',       array(true, -1, -1, 1)),
        // Gruppe 35 (2.8.13): angehaengt, damit die Nummern 0-15 der Baustein-Liste bleiben. 16: haus/speicher
        // (Entscheidung 5), 17-20: Tagesabschluss (Entscheidung 2) - nie retained.
        array('abgleich/' . $r . '/speicher', sm_t('LOX.VE_A_SPEICHER'),         '<v>',       array(true, -1, -1, 1)),
        array('kosten/gestern_eur',     sm_t('LOX.VE_K_GESTERN_EUR'),     '<v.2> EUR', array(true, -1, -1, 10000)),
        array('kosten/gestern_kwh',     sm_t('LOX.VE_K_GESTERN_KWH'),     '<v.2> kWh', array(true, -1, -1, 100000)),
        array('kosten/gestern_ct',      sm_t('LOX.VE_K_GESTERN_CT'),      '<v.1> ct',  array(true, -1, -1, 1000000)),
        array('kosten/gestern_stunden', sm_t('LOX.VE_K_GESTERN_STUNDEN'), '<v> h',     array(false, 0, 0, 25)),
    );
    $aus = array();
    foreach ($themen as $t) {
        $aus[] = array(str_replace(array('/', '%'), '_', $pr . '/' . $t[0]), $t[0], $t[1], $t[2], $t[3]);
    }
    return $aus;
}

/* ==================================================================
 * Einstellungen sichern und zurueckspielen - seit 2.8.23 ueber das
 * gemeinsame Sicherungsmodul, Beschreibung in sm_sicherung_beschreibung()
 * weiter unten. Was nicht hinein gehoert (FORMKEY, SUBFOLDER, SCRIPTNAME),
 * steht dort; bis 2.8.22 stand es in sm_sichern_tabu().
 * ================================================================== */

/**
 * Gruppe 35 (2.8.13, T2-1): die Abschnitte [ABGLEICH] und [KOSTEN] der
 * Sicherung - Schluessel => Wert, wenn die Datei keinen traegt. Beide sind
 * beim Einlesen OPTIONAL: eine Sicherung aus 2.8.12 ohne sie bleibt gueltig
 * (seit 2.8.23 'fehlt' => 'behalten' in sm_sicherung_beschreibung()).
 */
function sm_sichern_zusatz()
{
    return array(
        'ABGLEICH' => array('AKTIV' => '0', 'FAHRPLAN_URL' => '', 'SPEICHER_EIN' => '0'),
        'KOSTEN'   => array('AKTIV' => '0', 'PREIS_URL' => ''),
    );
}

/**
 * Gruppe 35 (2.8.13, T1-6): EINE Pruefung fuer die Adressen des Fahrplans und
 * der Preise - im Formular und beim Zurueckspielen. Die Regel steht in
 * smg_url_ok() (bin/sm_gemein.php), weil die beiden Abholer sie auch
 * brauchen.
 */
function sm_url_ok($u)
{
    return function_exists('smg_url_ok') ? smg_url_ok($u) : false;
}

/** Die Sprachansagen aus dem Feld der Hauptkonfiguration: was fehlt, kommt aus den Vorgaben (ab Werk EIN). */
function sm_ansage_block_lesen()
{
    $roh = (function () { $a = sm_cfg_read(); return isset($a['ANSAGE']['BLOCK']) ? (string) $a['ANSAGE']['BLOCK'] : ''; })();
    $b = $roh !== '' ? json_decode((string) base64_decode($roh), true) : null;
    return (is_array($b) ? $b : array()) + sm_ansage_vorgaben();
}

/** Zurueck in das Feld der Hauptkonfiguration. */
function sm_ansage_block_schreiben(array $cfg)
{
    $x = array();
    foreach (sm_ansage_schluessel() as $k) { if (array_key_exists($k, $cfg)) { $x[$k] = $cfg[$k]; } }
    $b = base64_encode((string) json_encode($x));
    return sm_cfg_set('ANSAGE', array('BLOCK' => $b));
}

/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function sm_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function sm_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('zaehler' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function sm_ansage_wert($k, $w)
{
    if (!in_array((string) $k, sm_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, sm_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('zaehler' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function sm_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function sm_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = sm_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, sm_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = sm_tts($j);
    }
    foreach (array_diff(sm_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function sm_ansage_cfg()
{
    $c = sm_ansage_block_lesen();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(sm_paths()['legacy']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && sm_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            sm_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function sm_ansage_cfg_schreiben(array $cfg)
{
    return sm_ansage_block_schreiben($cfg);
}

/** Die Ausgabearten dieser Linie. */
function sm_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function sm_tts($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), sm_ansage_modi());
}

/** Der Kontext des Moduls. */
function sm_ansage_k()
{
    $d = sm_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Smartmeter classic'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return sm_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function sm_ansage_anlaesse()
{
    return array('zaehler' => sm_t('ANSAGEPLUGIN.L_ZAEHLER'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function sm_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('zaehler' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function sm_ansage_eingaben($setzen = null)
{
    $f = sm_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function sm_ansage_post(array $post)
{
    $cfg = sm_ansage_cfg();
    $k = sm_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, sm_tts($cfg), $m, $b, array('modi' => sm_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, sm_ansage_regeln($cfg), sm_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        sm_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 2.8.17: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 2.8.16 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(sm_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function sm_ansage_form_html($felder)
{
    $cfg = sm_ansage_cfg();
    $k = sm_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = sm_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(sm_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(sm_tts($cfg), $h + array('modi' => sm_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(sm_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('zaehler' => 1), sm_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(sm_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(sm_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = sm_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function sm_ansage_test()
{
    $k = sm_ansage_k();
    sm_ansage_token_pflegen();
    $r = ansage_testansage(sm_ansage_vor_ort(), $k);
    sm_log('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (2.8.15, Welle S7, Stamm sprachausgabe.php 1.4.0) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('SM_ANSAGE_HOECHSTALTER')) { define('SM_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function sm_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function sm_ansage_warte($fn)
{
    $d = sm_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function sm_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    sm_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; sm_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function sm_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(sm_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), sm_ansage_k());
    if (!$g) { return false; }
    $cfg = sm_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!sm_ansage_cfg_schreiben($cfg)) { return false; }
    sm_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function sm_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    return ansage_token_fuer_lauf(sm_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), sm_ansage_k());
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function sm_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    $t = sm_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(sm_ansage_vor_ort($cfg), sm_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function sm_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = sm_ansage_cfg(); }
    $k = sm_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(sm_tts($cfg), sm_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function sm_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function sm_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = sm_ansage_cfg();
    $k = sm_ansage_k();
    $t = $tts === null ? sm_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, sm_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function sm_ansage_senden($jetzt = null)
{
    $d = sm_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = sm_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= SM_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = sm_ansage_cfg();
        sm_ansage_token_pflegen($conf);
        $conf = sm_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, sm_tts($conf), sm_ansage_regeln($conf), sm_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { sm_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            sm_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/**
 * Die Flanke eines Anlasses (2.8.15). Merker <Daten>/ansage_flanke.json, unter Dateisperre; er ueberlebt einen Neustart des Dienstes.
 * Der erste Lauf schweigt: Was schon gestoert war, als die Linie zum ersten Mal hinsah, sagt sie nicht an und meldet auch keine Rueckkehr.
 * Nur der Eintritt spricht, nach $frist Sekunden Dauer; die Rueckkehr nur, wenn der Eintritt gesprochen hat. Ein abgewaehlter Haken laeuft mit: Einschalten sagt nichts Altes an.
 * $name: der Name, den der Nutzer vergeben hat, nur im Satz, nie im Protokoll; Kennungen, Adressen und Seriennummern gehoeren nicht hierher.
 * Rueckgabe: nichts, erster_lauf, aus, oder das Ergebnis von <px>_ansage_legen().
 */
function sm_ansage_flanke($anlass, $gestoert, $name = '', $frist = 0, $jetzt = null)
{
    $l = array('zaehler' => array('zaehler', 'SATZ_ZAEHLER', 'SATZ_WIEDER', 0));
    if (!isset($l[$anlass])) { return 'unbekannt'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $sch = $anlass . ($name !== '' ? '|' . substr(md5($name), 0, 8) : '');
    $d = sm_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $h = @fopen($d . '/ansage_flanke.json', 'c+');
    if ($h === false) { return 'nichts'; }
    if (!flock($h, LOCK_EX)) { fclose($h); return 'nichts'; }
    $m = json_decode((string) stream_get_contents($h), true);
    $m = is_array($m) ? $m : array();
    $e = (isset($m[$sch]) && is_array($m[$sch]) && isset($m[$sch]['z'], $m[$sch]['s'], $m[$sch]['g'])) ? $m[$sch] : null;
    $neu = $gestoert ? 1 : 0;
    $tat = 'nichts';
    if ($e === null) {
        $e = array('z' => $neu, 's' => $jetzt, 'g' => $neu ? 2 : 0);
        $tat = 'erster_lauf';
    } elseif ($neu === 1) {
        if ((int) $e['z'] === 0) { $e = array('z' => 1, 's' => $jetzt, 'g' => 0); }
        if ((int) $e['g'] === 0 && $jetzt - (int) $e['s'] >= (int) $frist) {
            if (sm_ansage_anlass_an($l[$anlass][0])) { $e['g'] = 1; $tat = 'stoerung'; } else { $e['g'] = 2; $tat = 'aus'; }
        }
    } elseif ((int) $e['z'] === 1) {
        if ((int) $e['g'] === 1) { $tat = $l[$anlass][2] !== '' ? 'wieder' : 'nichts'; }
        $e = array('z' => 0, 's' => $jetzt, 'g' => 0);
    }
    if (!isset($m[$sch]) || $m[$sch] !== $e) {     // nur bei einer Aenderung schreiben: Dienste rufen das im Takt
        $m[$sch] = $e;
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) json_encode($m));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($d . '/ansage_flanke.json', 0600);
    if ($tat !== 'stoerung' && $tat !== 'wieder') { return $tat; }
    $satz = sm_t('ANSAGEPLUGIN.' . ($tat === 'stoerung' ? $l[$anlass][1] : $l[$anlass][2]));
    if (strpos($satz, '%s') !== false) { $satz = sprintf($satz, $name); }
    $ein = array('schluessel' => $tat === 'stoerung' ? $sch : 'wieder|' . $sch, 'text' => $satz);
    if ($tat === 'wieder') { $ein['nur_nach'] = $sch; }
    if ($tat === 'stoerung' && $l[$anlass][3]) { $ein['dringend'] = true; }
    sm_ansage_token_pflegen();
    return sm_ansage_legen(array($ein));
}

/* ==================================================================
 * 2.8.23: Einstellungen sichern und zurueckspielen ueber das gemeinsame
 * Sicherungsmodul (sicherung.php neben dieser Datei, Stammfassung 1.2.9,
 * Auftrag des Hausherrn 18.11.2026 "Ja, umbauen"). Diese Linie uebergibt nur
 * ihre Beschreibung (sm_sicherung_beschreibung()); Lesen, Pruefen, Speichern,
 * Zweitschrift messen und die Protokollzeile macht das Modul.
 *
 * Bis 2.8.22 war die Sicherung eine Textdatei in der Schreibweise der
 * smartmeter.cfg (Kopf "; Smartmeter classic - Sicherung der Einstellungen",
 * Abschnitte [MAIN], [VZLOGGER], [ABGLEICH], [KOSTEN], [KOPF <name>],
 * [ANSAGE]). Das Modul liest nur JSON (K_KEIN_JSON). Eine solche Textdatei
 * wandelt sm_sicherung_text_wandeln() VOR dem Modul in die JSON-Form dieser
 * Fassung um (Entscheidung des Hausherrn 18.11.2026 "Linie wandelt vorweg
 * um"); alles Weitere entscheidet das Modul wie bei einer neuen Sicherung.
 *
 * Aufbau der Sicherung (JSON):
 *   MAIN       Block: die Schluessel aus sm_vorgaben() - darunter TOKEN, das
 *              Zugriffstoken des Endpunkts. Nie darin: FORMKEY (Merkmal gegen
 *              fremde Absender), SUBFOLDER und SCRIPTNAME (gehoeren zur
 *              Installation; sm_cron_setzen() raeumt Verknuepfungen dieses
 *              Namens weg). Sie behalten beim Zurueckspielen ihren geltenden
 *              Wert ('nie_aus_datei' mit Pfad, Modul 1.2.7).
 *   ABGLEICH, KOSTEN  Bloecke (sm_sichern_zusatz()).
 *   KOEPFE     die Lesekoepfe als Liste: je Kopf ABSCHNITT (der Name des
 *              Abschnitts) und die Felder aus sm_kopf_felder(); zugeordnet wird
 *              ueber ABSCHNITT. Ein unbekanntes Feld wird verworfen und genannt
 *              (Regel 2 im Listeneintrag; bis 2.8.22 wies es die ganze Datei
 *              ab), jeder Wert geht durch sm_kopf_fehler() wie bis 2.8.22.
 *   tts, ansage_regeln, ansage_haken  die Sprachansagen (in smartmeter.cfg im
 *              Abschnitt [ANSAGE] als base64-JSON); nie darin: die
 *              Sprechtoken, ansage_token_herkunft, ansage_aus_datei.
 *   VZLOGGER   Teil ('teile'): vzlogger.json ohne uuids - eine eigene Datei
 *              mit eigenem Lese- und Schreibweg (sm_vz_read()/sm_vz_write(),
 *              daraus vzlogger.conf), keine Einstellung der smartmeter.cfg.
 *
 * Was fehlen darf: alles behaelt fehlend den geltenden Wert und wird genannt
 * ('fehlt' => 'behalten'). So las schon sm_sichern_einlesen_kern() bis
 * 2.8.22: kein Schluessel war Pflicht, was die Datei nicht nannte, blieb
 * (sm_cfg_set() schrieb nur Genanntes; die Spaetliste nannte nur einen Teil
 * davon). Jeder Wert geht durch dieselben Regeln wie bis 2.8.22.
 * ================================================================== */

/** Die Abschnittsnamen der smartmeter.cfg, die kein Lesekopf sind. */
function sm_sicherung_abschnitte()
{
    return array('MAIN', 'ABGLEICH', 'KOSTEN', 'ANSAGE', 'VZLOGGER');
}

/** Die Schluessel des Teils VZLOGGER - die Vorgaben von vzlogger.json ohne uuids (aus den Kanaelen gebildet). */
function sm_sicherung_vz_schluessel()
{
    return array_values(array_diff(array_keys(sm_vz_vorgaben()), array('uuids')));
}

/** Ein Einzelwert der Sicherung als Text: Zeichenkette oder ganze Zahl, sonst null (Feld, Wahrheitswert, null). */
function sm_sicherung_text($w)
{
    if (is_string($w)) { return $w; }
    if (is_int($w)) { return (string) $w; }
    return null;
}

/** Ist $a ein Feld mit Namen (kein JSON-Array)? */
function sm_sicherung_objekt($a)
{
    return is_array($a) && ($a === array() || array_keys($a) !== range(0, count($a) - 1));
}

/**
 * Der geltende Stand fuer das Modul. MAIN, ABGLEICH und KOSTEN wie sie gelten
 * (sm_legacy_read(), Vorgabe fuer leere Werte - so schrieb sie schon die
 * Textsicherung), die Lesekoepfe mit ihren Feldern, die Sprachansagen.
 * $mit_fremden (nur fuer das Zurueckspielen, 'jetzt'): dazu, was die Datei
 * traegt und diese Fassung nicht kennt - ein unbekannter Schluessel in MAIN,
 * ABGLEICH oder KOSTEN, ein unbekannter Abschnitt (ohne DEVICE). Das Modul
 * nennt sie dann als entfernt (Bauform vom 15.11.2026: die Lesefunktion
 * sortiert nichts aus). "Einstellungen sichern" baut ohne sie.
 */
function sm_sicherung_jetzt($mit_fremden = false)
{
    $alles = sm_cfg_read();
    $aus = array();
    $aus['MAIN'] = sm_legacy_read();
    foreach (array('SUBFOLDER', 'SCRIPTNAME') as $k) {
        if (isset($alles['MAIN'][$k])) { $aus['MAIN'][$k] = (string) $alles['MAIN'][$k]; }
    }
    foreach (sm_sichern_zusatz() as $ab => $vg) {
        $aus[$ab] = array();
        foreach ($vg as $k => $v) {
            $aus[$ab][$k] = (isset($alles[$ab][$k]) && $alles[$ab][$k] !== '') ? (string) $alles[$ab][$k] : $v;
        }
    }
    $aus['KOEPFE'] = array();
    foreach ($alles as $ab => $werte) {
        if (in_array($ab, sm_sicherung_abschnitte(), true) || !is_array($werte) || !isset($werte['DEVICE'])) { continue; }
        $kopf = array('ABSCHNITT' => (string) $ab);
        foreach (sm_kopf_felder() as $f) { $kopf[$f] = isset($werte[$f]) ? (string) $werte[$f] : ''; }
        $aus['KOEPFE'][] = $kopf;
    }
    $a = sm_ansage_cfg();
    foreach (sm_ansage_schluessel() as $k) {
        if (array_key_exists($k, $a)) { $aus[$k] = $a[$k]; }
    }
    if ($mit_fremden) {
        foreach (array('MAIN', 'ABGLEICH', 'KOSTEN') as $ab) {
            if (!isset($alles[$ab]) || !is_array($alles[$ab])) { continue; }
            foreach ($alles[$ab] as $k => $v) {
                if (!array_key_exists($k, $aus[$ab])) { $aus[$ab][$k] = (string) $v; }
            }
        }
        foreach ($alles as $ab => $werte) {
            $ab = (string) $ab;
            if (in_array($ab, sm_sicherung_abschnitte(), true) || !is_array($werte) || isset($werte['DEVICE'])
                || array_key_exists($ab, $aus)) { continue; }
            $aus[$ab] = $werte;
        }
    }
    return $aus;
}

/** Der Teil VZLOGGER fuer "Einstellungen sichern" und als geltender Stand ('teile_jetzt'). */
function sm_sicherung_vz_jetzt()
{
    $vz = sm_vz_read();
    $aus = array();
    foreach (sm_sicherung_vz_schluessel() as $k) { $aus[$k] = $vz[$k]; }
    return $aus;
}

/** Die Beschreibung dieser Linie fuer das gemeinsame Sicherungsmodul. */
function sm_sicherung_beschreibung()
{
    $p = sm_paths();
    $behalten = function (array $namen) {
        $k = array();
        foreach ($namen as $n) { $k[$n] = array('fehlt' => 'behalten'); }
        return $k;
    };
    $main = array_merge(array_keys(sm_vorgaben()), array('SUBFOLDER', 'SCRIPTNAME'));
    $zusatz = sm_sichern_zusatz();
    $tts = array_merge(array_keys(ansage_werk_block()), ansage_geheim());
    $schluessel = array(
        'MAIN'     => sicherung_block($main, $behalten($main)) + array('fehlt' => 'behalten'),
        'ABGLEICH' => sicherung_block(array_keys($zusatz['ABGLEICH']), $behalten(array_keys($zusatz['ABGLEICH'])))
                      + array('fehlt' => 'behalten'),
        'KOSTEN'   => sicherung_block(array_keys($zusatz['KOSTEN']), $behalten(array_keys($zusatz['KOSTEN'])))
                      + array('fehlt' => 'behalten'),
        'KOEPFE'   => sicherung_liste(array_merge(array('ABSCHNITT'), sm_kopf_felder()), 'ABSCHNITT',
                                      $behalten(sm_kopf_felder())) + array('fehlt' => 'behalten'),
        'tts' => sicherung_block($tts, array('ziele' => sicherung_liste(array('art', 'wo', 'laut', 'sprache', 'ein'))))
                 + array('fehlt' => 'behalten', 'sichern' => 'ansage_sicherung_bereinigen'),
        'ansage_regeln' => sicherung_block(array_keys(ansage_regeln_vorgaben())) + array('fehlt' => 'behalten'),
        'ansage_haken'  => sicherung_block(array('zaehler')) + array('fehlt' => 'behalten'),
        'VZLOGGER' => sicherung_block(sm_sicherung_vz_schluessel(), $behalten(sm_sicherung_vz_schluessel())),
    );
    foreach (array('MAIN', 'ABGLEICH', 'KOSTEN') as $ab) {
        $schluessel[$ab]['wandeln'] = function ($w) {
            foreach ($w as $k => $v) {
                $t = sm_sicherung_text($v);
                if ($t !== null) { $w[$k] = $t; }
            }
            return $w;
        };
    }
    return array(
        'kennzeichen' => 'LoxBerry-Plugin-Smartmeter-classic',
        /* Die Textsicherung bis 2.8.22 traegt kein Kennzeichen. sm_sicherung_text_wandeln() gibt sie ohne _plugin, mit
         * dem Kopf _aus_textsicherung weiter, und der Uebersetzer erkennt sie daran; die Meldung sagt "aeltere
         * Sicherung erkannt". Bis zur Angleichung vom 18.11.2026 (Frage 37) trug die umgewandelte Datei ein erfundenes
         * frueheres Kennzeichen, das keine veroeffentlichte Fassung je schrieb. */
        'uebersetzer' => 'sm_sicherung_alt_erkennen',
        'hinweis' => sm_t('EINST.SICH_KOPF_HINWEIS'),
        'home' => (string) $p['home'],
        'ordner' => (string) $p['plugin'],
        'plugin_cfg' => dirname(dirname(__DIR__)) . '/plugin.cfg',
        'vorgaben' => array('MAIN' => sm_vorgaben(), 'ABGLEICH' => $zusatz['ABGLEICH'],
                            'KOSTEN' => $zusatz['KOSTEN'], 'KOEPFE' => array()) + sm_ansage_vorgaben(),
        'schluessel' => $schluessel,
        'nie_aus_datei' => array('ansage_token_herkunft', 'ansage_aus_datei',
                                 'MAIN.FORMKEY', 'MAIN.SUBFOLDER', 'MAIN.SCRIPTNAME'),
        'teile' => array('VZLOGGER' => 'sm_sicherung_vz_lesen'),
        'teile_jetzt' => array('VZLOGGER' => 'sm_sicherung_vz_jetzt'),
        'pruefen' => 'sm_sicherung_wert',
        'quer' => 'sm_sicherung_quer',
        'vorbehandeln' => 'sm_sicherung_vorbehandeln',
        'jetzt' => function () { return sm_sicherung_jetzt(true); },
        'log' => function ($z) { sm_log($z); },
        't' => 'sm_t',
        'e' => 'sm_e',
    );
}

/**
 * Ein Wert der Sicherung (oben) - dieselben Grenzen wie in den Formularen und wie bis 2.8.22. Rueckgabe fuer das
 * Modul: '' oder Liste von Beanstandungen (maskiert, Namen statt Werten).
 */
function sm_sicherung_wert($k, $w)
{
    $aw = sm_ansage_wert($k, $w);
    if ($aw !== null) { return $aw ? '' : sm_t('ANSAGEPLUGIN.M_FEHL') . ' (' . sm_e($k) . ')'; }
    $falsch = function ($name) { return sprintf(sm_t('SICH.WERT_NAME'), sm_e($name)); };
    if ($k === 'KOEPFE') {
        if (!is_array($w) || ($w !== array() && array_keys($w) !== range(0, count($w) - 1))) { return $falsch('KOEPFE'); }
        $aus = array();
        $profile = sm_profile();
        $protokolle = sm_protokolle();
        $namen = array();
        foreach ($w as $i => $felder) {
            $f = array();
            $s = (is_array($felder) && isset($felder['ABSCHNITT']) && is_string($felder['ABSCHNITT'])) ? $felder['ABSCHNITT'] : '';
            if (sm_sicherung_objekt($felder)) {
                unset($felder['ABSCHNITT']);
                foreach ($felder as $fk => $fv) {
                    if (sm_sicherung_text($fv) === null) { $f[] = (string) $fk; unset($felder[$fk]); }
                }
                $f = array_merge($f, sm_kopf_fehler($s, $felder, $profile, $protokolle));
            } else {
                $f[] = 'KOPF';
            }
            // ANSAGE ist ein Abschnitt dieser Linie, kein Lesekopf; derselbe Kopf zweimal ist nicht zu entscheiden.
            if ((strtoupper($s) === 'ANSAGE' || isset($namen[$s])) && !in_array('KOPF', $f, true)) { $f[] = 'KOPF'; }
            $namen[$s] = true;
            if ($f) {
                $aus[] = sprintf(sm_t('SICH.KOPF_FELDER'), sm_e(sm_kopf_name_ok($s) ? $s : 'KOEPFE[' . ($i + 1) . ']'),
                                 sm_e(implode(', ', array_values(array_unique($f)))));
            }
        }
        return $aus;
    }
    if (!in_array($k, array('MAIN', 'ABGLEICH', 'KOSTEN'), true)) { return $falsch($k); }
    if (!sm_sicherung_objekt($w)) { return $falsch($k); }
    $aus = array();
    foreach ($w as $sk => $roh) {
        $sk = (string) $sk;
        $name = $k . '.' . $sk;
        $v = sm_sicherung_text($roh);
        if ($v === null || preg_match('/[\x00-\x1F\x7F]/', $v)) { $aus[] = $falsch($name); continue; }
        if ($k === 'MAIN') {
            $gut = true;
            switch ($sk) {
                case 'CRON':
                    $gut = array_key_exists($v, sm_takte());
                    break;
                case 'UDPPORT':
                    $gut = preg_match('/^[0-9]+$/', $v) && (int) $v >= 1 && (int) $v <= 65535;
                    break;
                case 'READ': case 'SENDMQTT': case 'SENDUDP': case 'ENDPUNKT_EIN': case 'LASTGANG_EIN':
                case 'HAUS_ENERGIE_EIN':
                    $gut = ($v === '0' || $v === '1');
                    break;
                case 'HAUS_ENERGIE_ZAEHLER':
                    $gut = ($v === '' || sm_kopf_name_ok($v));
                    break;
                case 'MQTTTOPIC':
                    $gut = function_exists('smg_praefix_fehler') && smg_praefix_fehler($v) === '';
                    break;
                case 'TOKEN':
                    // Der Wert wird nie angezeigt - er ist ein Geheimnis.
                    if ($v !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,128}\z/', $v)) { $aus[] = sm_t('SICH.TOKEN_WERT'); }
                    continue 2;
            }
            if (!$gut) { $aus[] = $falsch($name); }
            continue;
        }
        if (($sk === 'AKTIV' || $sk === 'SPEICHER_EIN') && $v !== '0' && $v !== '1') {
            $aus[] = $falsch($name);
        } elseif (substr($sk, -4) === '_URL' && $v !== '' && !sm_url_ok($v)) {
            $aus[] = $falsch($name);
        }
    }
    return $aus;
}

/**
 * Der Teil VZLOGGER: jeder Wert wie im Formular des Reiters vzLogger, in dessen Form (Zahlen als Zahl, Kanaele als
 * Liste). Wie bis 2.8.22: ein Geraet, das hier nicht angesteckt ist, wird nicht gesetzt, sondern genannt; eine leere
 * Kanalliste gilt als die drei Vorgabekanaele. Rueckgabe fuer das Modul: array(Wert|null, Beanstandungen, Hinweise).
 */
function sm_sicherung_vz_lesen($w)
{
    if (!sm_sicherung_objekt($w)) {
        return array(null, array(sprintf(sm_t('SICH.WERT_NAME'), 'VZLOGGER')));
    }
    $mangel = array();
    $hinweis = array();
    $falsch = function ($n) use (&$mangel) { $mangel[] = sprintf(sm_t('SICH.WERT_NAME'), sm_e('VZLOGGER.' . $n)); };
    $zahl = function ($v, $lo, $hi) {
        $t = sm_sicherung_text($v);
        return ($t !== null && preg_match('/^[0-9]+\z/', $t) && (int) $t >= $lo && (int) $t <= $hi) ? (int) $t : null;
    };
    $aus = array();
    foreach ($w as $k => $v) {
        $k = (string) $k;
        switch ($k) {
            case 'enabled': case 'localtime': case 'sendudp':
                $z = $zahl($v, 0, 1);
                if ($z === null) { $falsch($k); } else { $aus[$k] = $z; }
                break;
            case 'udpport': case 'httpport':
                $z = $zahl($v, 1, 65535);
                if ($z === null) { $falsch($k); } else { $aus[$k] = $z; }
                break;
            case 'baudrate':
                $z = $zahl($v, 300, 921600);
                if ($z === null) { $falsch($k); } else { $aus[$k] = $z; }
                break;
            case 'parity':
                if (!is_string($v) || !in_array($v, array('8n1', '7n1', '7e1', '8e1'), true)) { $falsch($k); } else { $aus[$k] = $v; }
                break;
            case 'protocol':
                if (!is_string($v) || !in_array($v, array('sml', 'd0'), true)) { $falsch($k); } else { $aus[$k] = $v; }
                break;
            case 'serial':
                if (!is_string($v) || ($v !== '' && !preg_match('/^[A-Za-z0-9_\-]+\z/', $v))) { $falsch($k); } else { $aus[$k] = $v; }
                break;
            case 'device':
                if (!is_string($v) || preg_match('/[\x00-\x1F\x7F]/', $v)) { $falsch($k); break; }
                if ($v !== '' && !file_exists($v)) {
                    $hinweis[] = sprintf(sm_t('SICH.KOPF_FEHLT'), 'vzLogger', sm_e($v));
                    $v = '';
                }
                $aus[$k] = $v;
                break;
            case 'channels':
                if (!is_array($v) || !($v === array() || array_keys($v) === range(0, count($v) - 1))) { $falsch($k); break; }
                $liste = array();
                foreach ($v as $c) {
                    if (!is_string($c) || !preg_match('/^[\d\.:\-\*]+\z/', $c)) { $falsch($k); $liste = null; break; }
                    $liste[] = $c;
                }
                if ($liste !== null) { $aus[$k] = $liste ? $liste : sm_vz_vorgaben()['channels']; }
                break;
            default:
                // Unbekannte Schluessel hat das Modul schon verworfen und genannt.
                break;
        }
    }
    return $mangel ? array(null, $mangel) : array($aus, array(), $hinweis);
}

/**
 * Quer ueber die ganze Datei: zwei Leser koennen sich eine serielle Schnittstelle nicht teilen (wie bis 2.8.22,
 * gegen den zusammengefuehrten Stand). Was die Umwandlung einer alten Textsicherung nicht lesen konnte (Kopf
 * _text_mangel, sm_sicherung_text_wandeln()), weist seit Modul 1.2.11 das Modul selbst ab, mit seinen Saetzen
 * (Regel 21); bis dahin stand diese Pruefung hier, mit eigenen Kennungen und Saetzen (SICH.ZEILE_UNLESBAR ...).
 */
function sm_sicherung_quer($neu, $r = null, $daten = null)
{
    $aus = array();
    $read = (is_array($neu) && isset($neu['MAIN']['READ'])) ? (string) $neu['MAIN']['READ'] : sm_legacy_read()['READ'];
    $vz = (is_array($r) && isset($r['teile']['VZLOGGER']['enabled'])) ? (string) $r['teile']['VZLOGGER']['enabled']
        : (string) (int) sm_vz_read()['enabled'];
    if ($read === '1' && $vz === '1') {
        $aus[] = array('MAIN.READ', sm_t('FEHLER.BEIDE_LESER'));
    }
    return $aus;
}

/**
 * Vor der Pruefung (nach dem Verwerfen unbekannter Schluessel und nach Regel 3), wie bis 2.8.22
 * sm_ansage_sicherung_vor(): eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die
 * Wertpruefung nennt ihn); sonst wird tts vervollstaendigt und die geltenden Sprechtoken bleiben.
 */
function sm_sicherung_vorbehandeln($bereit)
{
    if (array_key_exists('tts', $bereit)) {
        $j = sm_ansage_cfg();
        $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
        $g = '';
        $pr = is_array($bereit['tts']) ? ansage_wert_pruefen($bereit['tts'], $g, sm_ansage_modi()) : null;
        if (!is_array($bereit['tts']) || ansage_sicherung_mangel($bereit['tts']) || $pr === null) {
            $bereit['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($pr, 'aus');
            $bereit['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    }
    return $bereit;
}

/**
 * Vor dem Speichern: Lesekoepfe, die die Datei nicht nennt, bleiben, und ein Feld eines Kopfs, das die Datei nicht
 * nennt, behaelt seinen Wert - wie bis 2.8.22 (sm_cfg_set() je Kopf; ein Lesekopf gehoert zur Anlage, an der er
 * steckt). Wie bis 2.8.22 gilt nach dem Zurueckspielen ansage_aus_datei = 1: eine liegengebliebene ansage.json
 * (Probe vom 01.11.) wird danach nicht mehr ueber die zurueckgespielten Sprachansagen gelegt.
 */
function sm_sicherung_vorbereiten($neu)
{
    $datei = array();
    foreach ((isset($neu['KOEPFE']) && is_array($neu['KOEPFE'])) ? $neu['KOEPFE'] : array() as $kopf) {
        $datei[(string) $kopf['ABSCHNITT']] = $kopf;
    }
    $koepfe = array();
    foreach (sm_cfg_read() as $ab => $werte) {
        $ab = (string) $ab;
        if (in_array($ab, sm_sicherung_abschnitte(), true) || !is_array($werte) || !isset($werte['DEVICE'])) { continue; }
        $koepfe[] = array('ABSCHNITT' => $ab) + (isset($datei[$ab]) ? array_merge($werte, $datei[$ab]) : $werte);
        unset($datei[$ab]);
    }
    foreach ($datei as $kopf) { $koepfe[] = $kopf; }
    $neu['KOEPFE'] = $koepfe;
    $neu['ansage_aus_datei'] = 1;
    return $neu;
}

/**
 * Speichern: genau der Stand, den das Modul liefert. smartmeter.cfg wird ganz geschrieben (MAIN, ABGLEICH, KOSTEN,
 * die Lesekoepfe, [ANSAGE]); der Teil VZLOGGER geht in vzlogger.json, daraus vzlogger.conf. Jede der drei Dateien
 * zieht beim Schreiben ihre Zweitschrift mit (sm_cfg_write(), sm_vz_write(), sm_vz_conf_schreiben()).
 */
function sm_sicherung_speichern($neu, $jetzt = null, $r = null)
{
    $alles = array('MAIN' => $neu['MAIN'], 'ABGLEICH' => $neu['ABGLEICH'], 'KOSTEN' => $neu['KOSTEN']);
    foreach ($neu['KOEPFE'] as $kopf) {
        $s = (string) $kopf['ABSCHNITT'];
        unset($kopf['ABSCHNITT']);
        $alles[$s] = $kopf;
    }
    $x = array();
    foreach (sm_ansage_schluessel() as $k) { if (array_key_exists($k, $neu)) { $x[$k] = $neu[$k]; } }
    $alles['ANSAGE'] = array('BLOCK' => base64_encode((string) json_encode($x)));
    $ok = sm_cfg_write($alles);
    if ($ok && is_array($r) && isset($r['teile']['VZLOGGER']) && is_array($r['teile']['VZLOGGER'])) {
        $cfg = sm_vz_read();
        foreach ($r['teile']['VZLOGGER'] as $k => $v) { $cfg[$k] = $v; }
        $cfg['uuids'] = sm_vz_uuids($cfg['channels']);
        $ok = sm_vz_write($cfg) && sm_vz_conf_schreiben(sm_vz_read());
    }
    sm_cache_verwerfen();
    return $ok;
}

/** Die Paare (Datei, Zweitschrift) fuer die Messung des Moduls - je vorhandene Datei. */
function sm_sicherung_zweitschrift_paare()
{
    $p = sm_paths();
    $aus = array();
    foreach (array('smartmeter.cfg', 'vzlogger.json', 'vzlogger.conf') as $n) {
        if (is_file($p['config'] . '/' . $n)) { $aus[] = array($p['config'] . '/' . $n, sm_zweitschrift_pfad($n)); }
    }
    return $aus;
}

/** "Einstellungen sichern": die Datei als JSON-Text (Kopf und Inhalt baut das Modul); '' wenn nicht bildbar. */
function sm_sicherung_json()
{
    return sicherung_json(sicherung_bauen(sm_sicherung_beschreibung(), sm_sicherung_jetzt(false),
                                          array('VZLOGGER' => sm_sicherung_vz_jetzt())));
}

/** X-3: bestuende die eigene Sicherung das Zurueckspielen? Dieselbe Pruefung wie dort; Namen, nie Werte. */
function sm_sicherung_selbstpruefung()
{
    $b = sm_sicherung_beschreibung();
    $jetzt = sm_sicherung_jetzt(false);
    return sicherung_selbstpruefung($b, sicherung_bauen($b, $jetzt, array('VZLOGGER' => sm_sicherung_vz_jetzt())), $jetzt);
}

/**
 * Eine Textsicherung bis 2.8.22 in die JSON-Form dieser Fassung umwandeln (Entscheidung des Hausherrn 18.11.2026,
 * "Linie wandelt vorweg um"). Erkannt wird sie an ihrer ersten Zeile, die jede Fassung 2.4.1 bis 2.8.22 schrieb
 * ("; Smartmeter classic - Sicherung der Einstellungen"; gemessen 18.11.2026 an den Archiven). Alles andere
 * (JSON, eine fremde Datei) geht unveraendert ans Modul. $umgewandelt sagt, ob umgewandelt wurde.
 *
 * Gelesen wird wie bis 2.8.22 (sm_sichern_einlesen_kern()): Zeilen mit ; oder # sind Kommentar, [Abschnitt],
 * schluessel=wert, Rand abgeschnitten. Die JSON-Datei traegt kein _plugin, sondern den Kopf _aus_textsicherung =
 * SM_TEXT_KOPF (daran erkennt sie der Uebersetzer sm_sicherung_alt_erkennen()) und _fassung aus der zweiten
 * Kopfzeile. Nichts wird verschluckt:
 *   - ein unbekannter Schluessel steht unter seinem Abschnitt, ein unbekannter Abschnitt als "[NAME]", ein
 *     Schluessel vor dem ersten Abschnitt oben - das Modul verwirft sie und nennt sie (Regel 2);
 *   - was sich nicht lesen laesst, steht mit Stelle und fester Kennung des Moduls in _text_mangel
 *     (sicherung_text_mangel_eintragen(), Modul 1.2.11): eine Zeile ohne = oder ohne gueltiges UTF-8 (ZEILE), ein
 *     Schluessel zweimal (DOPPELT), [KOPF] ohne Namen (ABSCHNITT), ein unlesbarer Block [ANSAGE] (WERT), ein
 *     Schluessel mit _ am Anfang vor dem ersten Abschnitt (KOPF; so heisst in der JSON-Datei nur der Kopf, das Modul
 *     ueberginge ihn still). Das Modul weist die ganze Datei dann ab - ein solcher Fehler laesst sich keinem Wert
 *     zuordnen; ihn als unbekannten Schluessel zu verwerfen und den Rest
 *     zurueckzuspielen, hiesse einen Wert still zu verlieren oder zu raten (bis 2.8.22 ebenso abgewiesen).
 * Werte bleiben Text; die Kanaele werden wie bis 2.8.22 an Komma und Leerraum getrennt.
 */
function sm_sicherung_text_wandeln($roh, &$umgewandelt = null)
{
    $umgewandelt = false;
    $t = (string) $roh;
    if (substr($t, 0, 3) === "\xEF\xBB\xBF") { $t = substr($t, 3); }
    $zeilen = preg_split('/\R/', $t);
    $erste = '';
    foreach ($zeilen as $z) {
        if (trim($z) !== '') { $erste = trim($z); break; }
    }
    if ($erste !== SM_TEXT_KOPF) { return $roh; }
    $umgewandelt = true;

    $d = array('_aus_textsicherung' => SM_TEXT_KOPF);
    $gesehen = array();
    $abschnitt = '';
    $nr = 0;
    foreach ($zeilen as $z) {
        $nr++;
        $s = trim($z);
        if ($s === '') { continue; }
        if ($s[0] === ';' || $s[0] === '#') {
            if (!isset($d['_fassung']) && preg_match('/Plugin-Fassung ([A-Za-z0-9_.\-]{1,40})\s*\z/', $s, $m)) {
                $d['_fassung'] = $m[1];
            }
            continue;
        }
        /* Modul 1.2.11: eine Zeile, die kein UTF-8 ist, liesse sich nicht als JSON schreiben - sie wird mit ihrer
         * Nummer genannt (bis dahin nur als Ganzes, Stelle "UTF-8"). */
        if (!preg_match('//u', $s)) {
            sicherung_text_mangel_eintragen($d, 'Zeile ' . $nr, 'ZEILE');
            continue;
        }
        if ($s[0] === '[' && substr($s, -1) === ']') {
            $abschnitt = trim(substr($s, 1, -1));
            if (strncmp($abschnitt, 'KOPF', 4) === 0 && trim(substr($abschnitt, 4)) === '') {
                sicherung_text_mangel_eintragen($d, 'Zeile ' . $nr, 'ABSCHNITT');
                $abschnitt = "\0";      // die Zeilen darunter gehoeren zu keinem Abschnitt, den es gibt
            }
            continue;
        }
        $pos = strpos($s, '=');
        if ($pos === false) {
            sicherung_text_mangel_eintragen($d, 'Zeile ' . $nr, 'ZEILE');
            continue;
        }
        $k = trim(substr($s, 0, $pos));
        $v = trim(substr($s, $pos + 1));
        if ($abschnitt === "\0") { continue; }
        // Wohin der Schluessel gehoert: Pfad in der JSON-Datei
        if ($abschnitt === '') {
            if (substr($k, 0, 1) === '_') {
                sicherung_text_mangel_eintragen($d, $k, 'KOPF');
                continue;
            }
            $pfad = array($k);
        } elseif (in_array($abschnitt, array('MAIN', 'VZLOGGER', 'ABGLEICH', 'KOSTEN'), true)) {
            $pfad = array($abschnitt, $k);
        } elseif (strncmp($abschnitt, 'KOPF ', 5) === 0) {
            $pfad = array('KOEPFE', trim(substr($abschnitt, 5)), $k);
        } elseif ($abschnitt === 'ANSAGE') {
            $pfad = array('ANSAGE', $k);
        } else {
            $pfad = array('[' . $abschnitt . ']', $k);
        }
        $schl = implode("\0", $pfad);
        if (isset($gesehen[$schl])) {
            sicherung_text_mangel_eintragen($d, implode('.', $pfad), 'DOPPELT');
            continue;
        }
        $gesehen[$schl] = true;
        if ($pfad === array('VZLOGGER', 'channels')) {
            $l = array();
            foreach (preg_split('/[\s,]+/', $v) as $c) { if ($c !== '') { $l[] = $c; } }
            $v = $l;
        }
        if (count($pfad) === 1) {
            $d[$pfad[0]] = $v;
        } elseif (count($pfad) === 2) {
            if (!isset($d[$pfad[0]]) || !is_array($d[$pfad[0]])) { $d[$pfad[0]] = array(); }
            $d[$pfad[0]][$pfad[1]] = $v;
        } else {
            if (!isset($d[$pfad[0]]) || !is_array($d[$pfad[0]])) { $d[$pfad[0]] = array(); }
            if (!isset($d[$pfad[0]][$pfad[1]]) || !is_array($d[$pfad[0]][$pfad[1]])) { $d[$pfad[0]][$pfad[1]] = array(); }
            $d[$pfad[0]][$pfad[1]][$pfad[2]] = $v;
        }
    }
    /* [KOPF <name>]: je Kopf ein Eintrag der Liste KOEPFE mit ABSCHNITT = <name>. */
    if (isset($d['KOEPFE']) && is_array($d['KOEPFE'])) {
        $l = array();
        foreach ($d['KOEPFE'] as $s => $felder) {
            if (array_key_exists('ABSCHNITT', $felder)) {
                sicherung_text_mangel_eintragen($d, 'KOPF ' . $s . '.ABSCHNITT', 'DOPPELT');
            }
            $l[] = array('ABSCHNITT' => (string) $s) + $felder;
        }
        $d['KOEPFE'] = $l;
    }
    /* [ANSAGE] BLOCK=<base64-JSON>: die Sprachansagen treten oben an ihren Platz (tts, ansage_regeln, ...). Ein
     * leerer BLOCK lasst den geltenden Stand (wie bis 2.8.22); ein unlesbarer weist die Datei ab; jeder andere
     * Schluessel in [ANSAGE] bleibt dort und wird als unbekannt genannt. */
    if (isset($d['ANSAGE']) && is_array($d['ANSAGE'])) {
        $a = $d['ANSAGE'];
        unset($d['ANSAGE']);
        if (array_key_exists('BLOCK', $a)) {
            $b64 = (string) $a['BLOCK'];
            unset($a['BLOCK']);
            if ($b64 !== '') {
                $j = base64_decode($b64, true);
                $j = is_string($j) ? json_decode($j, true) : null;
                if (!sm_sicherung_objekt($j)) {
                    sicherung_text_mangel_eintragen($d, 'ANSAGE.BLOCK', 'WERT');
                } else {
                    foreach ($j as $jk => $jw) {
                        $jk = (string) $jk;
                        if (in_array($jk, sm_ansage_schluessel(), true)) { $d[$jk] = $jw; } else { $d['ANSAGE.' . $jk] = $jw; }
                    }
                }
            }
        }
        if ($a) { $d['ANSAGE'] = $a; }
    }
    $js = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($js)) {
        /* Nicht als JSON darstellbar: nichts ersetzen, die Datei abweisen. Seit Modul 1.2.11 nennt die Pruefung je Zeile
         * oben jede Zeile ohne gueltiges UTF-8; dieser Rueckfall bleibt, falls dennoch etwas nicht darstellbar ist. */
        $f = array('_aus_textsicherung' => SM_TEXT_KOPF);
        sicherung_text_mangel_eintragen($f, 'UTF-8', 'ZEILE');
        $js = (string) json_encode($f);
    }
    return $js;
}

/**
 * Der Uebersetzer (Rueckruf 'uebersetzer' des Moduls, nur fuer eine Datei ohne _plugin): angenommen wird nur die
 * Umwandlung einer Textsicherung dieser Linie (Kopf _aus_textsicherung = SM_TEXT_KOPF, sm_sicherung_text_wandeln()).
 * Jede andere Datei ohne Kennzeichen weist das Modul ab (K_OHNE_KENNZEICHEN). Wie wi_sicherung_alt_erkennen() in
 * WOLF-ISM-NG 3.1.22 (Frage 37 der Nacht 18.11.2026).
 */
function sm_sicherung_alt_erkennen($d)
{
    return (is_array($d) && isset($d['_aus_textsicherung']) && $d['_aus_textsicherung'] === SM_TEXT_KOPF) ? $d : null;
}

/* ==================================================================
 * SEIT DEM DURCHGANG 01.10.2026: Einmalmeldung (PRG), MQTT-Themen und
 * Abraeumen, Praefix-Texte, Lebenszeichen in der Vorlage
 * ================================================================== */

/**
 * Die Einmalmeldung fuer PRG (O1; Regeln/04, Entscheidung 19).
 *
 * Jeder POST-Handler endet mit einer Umleitung (303). Was er zu sagen hat,
 * reist in dieser Datei: 0600 im Datenordner, hoechstens 120 s gueltig,
 * gelesen und geloescht nur beim folgenden GET. Bis 2.8.5 wurde nach jedem
 * POST unmittelbar gerendert - F5 wuerfelte das Token neu und machte damit
 * die Adresse im Miniserver ungueltig (gemessen, Oberflaechenbericht O1).
 */
function sm_flash_datei()
{
    return sm_paths()['datadir'] . '/einmalmeldung.json';
}

function sm_flash_schreiben($inhalt)
{
    $inhalt['zeit'] = time();
    $d = dirname(sm_flash_datei());
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return sm_json_schreiben(sm_flash_datei(), $inhalt, 0600);
}

function sm_flash_lesen()
{
    $f = sm_flash_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return array();
    }
    return $d;
}

/** Der Text zu einem Grundwort aus smg_praefix_fehler() - die Schluessel
 *  stehen ausgeschrieben, damit die Pruefung der Sprachschluessel sie findet. */
function sm_praefix_text($grund)
{
    $t = array(
        'leer'          => sm_t('FEHLER.PRAEFIX_LEER'),
        'steuerzeichen' => sm_t('FEHLER.PRAEFIX_STEUERZEICHEN'),
        'leerraum'      => sm_t('FEHLER.PRAEFIX_LEERRAUM'),
        'anfuehrung'    => sm_t('FEHLER.PRAEFIX_ANFUEHRUNG'),
        'platzhalter'   => sm_t('FEHLER.PRAEFIX_PLATZHALTER'),
        'schraegstrich' => sm_t('FEHLER.PRAEFIX_SCHRAEGSTRICH'),
        'lang'          => sm_t('FEHLER.PRAEFIX_LANG'),
    );
    return isset($t[$grund]) ? $t[$grund] : sm_t('FEHLER.PRAEFIX_LEER');
}

/**
 * Was nach einer Aenderung von "MQTT an" oder Praefix zu tun ist - aus dem
 * Reiter MQTT und nach dem Zurueckspielen einer Sicherung (M3; Entscheidungen
 * 3 und 26; Bauart mo_mqtt_nach_aenderung(), Robonect 1.1.15).
 *
 * War MQTT an und wechselt das Praefix oder wird MQTT abgeschaltet, werden
 * die retained Zustaende unter dem BISHERIGEN Praefix sofort geleert und
 * beim Broker nachgelesen. Ein gewechseltes Praefix wird vorgemerkt, bis der
 * Broker bestaetigt, dass nichts mehr darunter steht - die Deinstallation
 * leert vorgemerkte mit. Die Abodatei des Gateways folgt dem Praefix.
 *
 * Rueckgabe array('ok' => Meldungen, 'fehler' => Meldungen), maskiert.
 */
function sm_mqtt_nach_aenderung(array $alt, array $neu)
{
    $aus = array('ok' => array(), 'fehler' => array());
    if (!function_exists('smg_mqtt_raeumen')) {
        return $aus;
    }
    $p = sm_paths();
    $pa = trim((string) $alt['MQTTTOPIC']);
    $pn = trim((string) $neu['MQTTTOPIC']);
    $an_alt = ((string) $alt['SENDMQTT'] === '1');
    $an_neu = ((string) $neu['SENDMQTT'] === '1');
    $pa_gilt = (smg_praefix_fehler($pa) === '');
    if ($pa !== $pn) {
        smg_mqtt_vormerken($p['home'], $p['plugin'], $pn, true);
        if ($pa_gilt) {
            smg_mqtt_vormerken($p['home'], $p['plugin'], $pa);
        }
    }
    if ($an_alt && $pa_gilt && ($pa !== $pn || !$an_neu)) {
        $koepfe = smg_mqtt_koepfe($p['plugin'], smg_cfg_lesen($p['legacy']), smg_vz_json($p['vzjson']));
        $e = smg_mqtt_raeumen($p['home'], $pa, $koepfe,
                 smg_mqtt_ersatz($p['home'], $p['plugin'], $koepfe, $pa));
        foreach ($e['zeilen'] as $z) {
            sm_log('MQTT nach Aenderung: ' . preg_replace('/^<[A-Z]+> /', '', $z));
        }
        if (!$e['eingang']) {
            $aus['fehler'][] = sprintf(sm_t('MQ.RAEUMEN_KEIN_EINGANG'), sm_e($pa));
        } elseif ($e['nachgelesen'] && !$e['offen']) {
            $aus['ok'][] = sprintf(sm_t('MQ.RAEUMEN_OK'), sm_e($pa));
            if ($pa !== $pn) {
                smg_mqtt_vormerken($p['home'], $p['plugin'], $pa, true);
            }
        } elseif ($e['nachgelesen']) {
            $aus['fehler'][] = sprintf(sm_t('MQ.RAEUMEN_OFFEN'), count($e['offen']), sm_e($pa),
                sm_e(implode(', ', array_slice($e['offen'], 0, 5))));
        } else {
            $aus['fehler'][] = sprintf(sm_t('MQ.RAEUMEN_UNGEPRUEFT'), sm_e($pa));
        }
    }
    if ($an_neu) {
        list($pfad, , $neu_geschrieben) = smg_abo_datei($p['home'], $p['plugin'], $pn, true);
        if ($neu_geschrieben) {
            sm_log('MQTT: Abodatei des Gateways gesetzt: ' . $pn . '/# (' . $pfad . ').');
        }
    }
    return $aus;
}

/**
 * Die Themen, die dieses Plugin WIRKLICH sendet - je Leseweg, mit Spalte
 * retained (M1; Entscheidung 3).
 *
 * Bis 2.8.5 zeigte der Reiter MQTT immer die Themen des vzLogger-Weges
 * (smartmeter/vzlogger/...), auch wenn der klassische Leser unter
 * smartmeter/<kopf>/... sendete - keines der gezeigten Themen kam an; Kosten,
 * Abgleich und Lebenszeichen standen nirgends (gemessen, MQTT-Bericht Nr. 8).
 * Der klassische Weg zeigt je Lesekopf die Felder der letzten Messung
 * (dieselbe Auswahl wie die Namenstabelle, sm_namen_gruppen()).
 *
 * Rueckgabe: Liste von array(ueberschrift, zeilen, hinweis); eine Zeile ist
 * array(thema, einheit, bedeutung, retained, textfeld).
 */
function sm_mqtt_themen()
{
    $legacy = sm_legacy_read();
    $vz = sm_vz_read();
    $alles = sm_cfg_read();
    $pr = trim((string) $legacy['MQTTTOPIC'], '/');
    $gruppen = array();
    $leser = false;

    if ($legacy['READ'] === '1') {
        $leser = true;
        $z = array();
        foreach (sm_koepfe() as $k) {
            $s = $k['ABSCHNITT'];
            $m = isset($k['METER']) ? (string) $k['METER'] : '0';
            if ($m === '' || $m === '0') {
                continue;
            }
            $werte = sm_werte($s);
            if (!$werte) {
                $z[] = array($pr . '/' . $s . '/...', '', sm_t('MQ.T_NOCH_KEINE'), null, false);
                continue;
            }
            foreach ($werte as $pv) {
                $md = sm_feld($pv[0]);
                list($eh) = sm_einheit_fuer($md, 'legacy', $pv[0]);
                $z[] = array(sm_thema($pr, $s, $pv[0]), $eh, $md ? sm_t($md['bed']) : $pv[0],
                             smg_mqtt_retained($pv[0]), $md && $md['typ'] === 'text');
            }
        }
        $gruppen[] = array(sm_t('MQ.G_KLASSISCH'), $z, $z ? '' : sm_t('MQ.KEIN_KOPF'));
    } else {
        $gruppen[] = array(sm_t('MQ.G_KLASSISCH'), array(), sm_t('MQ.AUS_KLASSISCH'));
    }

    if (!empty($vz['enabled'])) {
        $leser = true;
        $z = array();
        foreach (sm_vz_felder($vz) as $feld) {
            $md = sm_feld($feld);
            list($eh) = sm_einheit_fuer($md, 'vz', $feld);
            $z[] = array(sm_thema($pr, $vz['serial'], $feld), $eh, $md ? sm_t($md['bed']) : $feld,
                         smg_mqtt_retained($feld), $md && $md['typ'] === 'text');
        }
        $gruppen[] = array(sm_t('MQ.G_VZ'), $z, '');
    } else {
        $gruppen[] = array(sm_t('MQ.G_VZ'), array(), sm_t('MQ.AUS_VZ'));
    }

    if ($leser) {
        $gruppen[] = array(sm_t('MQ.G_STATUS'), array(
            array($pr . '/status/ok', '', sm_t('MQ.B_STATUS_OK'), false, false),
            array($pr . '/status/ts', 's', sm_t('MQ.B_STATUS_TS'), false, false),
            array($pr . '/status/zaehler', '', sm_t('MQ.B_STATUS_ZAEHLER'), false, false),
        ), '');
    }

    if (sm_cfg_get($alles, 'KOSTEN', 'AKTIV', '0') === '1') {
        // Die Schluessel ausgeschrieben - die Pruefung der Sprachschluessel
        // findet nur, was woertlich dasteht.
        $z = array();
        foreach (array('quelle_ok'  => array('', sm_t('MQ.B_K_QUELLE_OK')),
                       'stunde_ct'  => array('ct', sm_t('MQ.B_K_STUNDE_CT')),
                       'heute_ct'   => array('ct', sm_t('MQ.B_K_HEUTE_CT')),
                       'heute_eur'  => array('EUR', sm_t('MQ.B_K_HEUTE_EUR')),
                       'heute_kwh'  => array('kWh', sm_t('MQ.B_K_HEUTE_KWH')),
                       'stunden'    => array('', sm_t('MQ.B_K_STUNDEN')),
                       'offen'      => array('', sm_t('MQ.B_K_OFFEN')),
                       'ohne_preis' => array('', sm_t('MQ.B_K_OHNE_PREIS')),
                       // Gruppe 35 (2.8.13): Tagesabschluss, nie retained.
                       'gestern_eur' => array('EUR', sm_t('MQ.B_K_GESTERN_EUR')),
                       'gestern_kwh' => array('kWh', sm_t('MQ.B_K_GESTERN_KWH')),
                       'gestern_ct'  => array('ct', sm_t('MQ.B_K_GESTERN_CT')),
                       'gestern_stunden' => array('', sm_t('MQ.B_K_GESTERN_STUNDEN'))) as $t => $eb) {
            $z[] = array($pr . '/kosten/' . $t, $eb[0], $eb[1], false, false);
        }
        $gruppen[] = array(sm_t('MQ.G_KOSTEN'), $z, '');
    }

    if (sm_cfg_get($alles, 'ABGLEICH', 'AKTIV', '0') === '1') {
        $z = array(array($pr . '/abgleich/quelle_ok', '', sm_t('MQ.B_A_QUELLE_OK'), false, false));
        foreach (array('aktiv'  => array('', sm_t('MQ.B_A_AKTIV')),
                       'soll'   => array('kWh', sm_t('MQ.B_A_SOLL')),
                       'ist'    => array('kWh', sm_t('MQ.B_A_IST')),
                       'fehlt'  => array('kWh', sm_t('MQ.B_A_FEHLT')),
                       'dauer'  => array('s', sm_t('MQ.B_A_DAUER')),
                       'sicher' => array('', sm_t('MQ.B_A_SICHER')),
                       'ok'     => array('', sm_t('MQ.B_A_OK')),
                       'speicher' => array('', sm_t('MQ.B_A_SPEICHER'))) as $t => $eb) {
            $z[] = array($pr . '/abgleich/<n>/' . $t, $eb[0], $eb[1], $t === 'aktiv', false);
        }
        $gruppen[] = array(sm_t('MQ.G_ABGLEICH'), $z, '');
    }

    /* 2.8.18: Anbieter haus/energie - ohne Praefix, alle fluechtig. Die Sprachschluessel woertlich
     * (Pruefung der Sprachschluessel); Reihenfolge und Einheiten aus smg_he_themen(). */
    if ($legacy['SENDMQTT'] === '1' && $legacy['HAUS_ENERGIE_EIN'] === '1' && function_exists('smg_he_themen')) {
        $sm_he_b = array('netz_w' => sm_t('MQ.B_HE_NETZ'), 'bezug_w' => sm_t('MQ.B_HE_BEZUG'),
                         'ueberschuss_w' => sm_t('MQ.B_HE_UEBERSCHUSS'), 'frei_w' => sm_t('MQ.B_HE_FREI'),
                         'frei_quelle' => sm_t('MQ.B_HE_FREI_QUELLE'), 'bezug_kwh' => sm_t('MQ.B_HE_BEZUG_KWH'),
                         'einspeisung_kwh' => sm_t('MQ.B_HE_EINSP_KWH'), 'stand' => sm_t('MQ.B_HE_STAND'),
                         'ok' => sm_t('MQ.B_HE_OK'), 'zaehler' => sm_t('MQ.B_HE_ZAEHLER'));
        $z = array();
        foreach (smg_he_themen() as $t => $eb) {
            $z[] = array('haus/energie/' . $t, $eb[0], isset($sm_he_b[$t]) ? $sm_he_b[$t] : $t, false, false);
        }
        $gruppen[] = array(sm_t('MQ.G_HAUS_ENERGIE'), $z, sm_t('MQ.HE_THEMEN_HINT'));
    }
    return $gruppen;
}

/**
 * 2.8.18: die Lage des Anbieters haus/energie als array(Zustand: ok|warn|fail|warte|'', Text).
 * '' = Schalter aus, warte = eingeschaltet, erster Minutenlauf steht noch aus. Liest den
 * Merker des letzten Minutenlaufs (bin/sm_historie.php -> smg_he_lauf).
 */
function sm_he_lage()
{
    $l = sm_legacy_read();
    if ($l['HAUS_ENERGIE_EIN'] !== '1') {
        return array('', sm_t('MQ.HE_L_AUS'));
    }
    if ($l['SENDMQTT'] !== '1') {
        return array('warn', sm_t('MQ.HE_L_OHNE_MQTT'));
    }
    // Erst nachsehen, dann lesen: vor dem ersten Minutenlauf (und nach einem Neustart) fehlt der Merker.
    $sm_he_datei = sm_paths()['shm'] . '/haus_energie.json';
    $d = is_file($sm_he_datei) ? json_decode((string) @file_get_contents($sm_he_datei), true) : null;
    if (!is_array($d) || !isset($d['ts'])) {
        return array('warte', sm_t('MQ.HE_L_NOCH_NICHT'));
    }
    $vor = time() - (int) $d['ts'];
    $wann = date('d.m.Y H:i:s', (int) $d['ts']);
    if ($vor < 0 || $vor > 150) {
        return array('fail', sprintf(sm_t('MQ.HE_L_STILL'), $wann));
    }
    if ((int) $d['gesendet'] < 0) {
        return array('fail', sprintf(sm_t('MQ.HE_L_BROKER'), $wann));
    }
    $grund = isset($d['grund']) ? (string) $d['grund'] : '';
    $gruende = array('kein_leser' => sm_t('MQ.HE_G_KEIN_LESER'), 'waehlen' => sm_t('MQ.HE_G_WAEHLEN'),
                     'weg' => sm_t('MQ.HE_G_WEG'), 'keine_messung' => sm_t('MQ.HE_G_KEINE_MESSUNG'),
                     'zu_alt' => sm_t('MQ.HE_G_ZU_ALT'), 'nur_start' => sm_t('MQ.HE_G_NUR_START'),
                     'keine_leistung' => sm_t('MQ.HE_G_KEINE_LEISTUNG'));
    if ((int) $d['ok'] !== 1) {
        return array('warn', sprintf(sm_t('MQ.HE_L_OHNE_WERTE'), $wann,
                                     isset($gruende[$grund]) ? $gruende[$grund] : $grund));
    }
    $w = (isset($d['werte']) && is_array($d['werte'])) ? $d['werte'] : array();
    $teile = array();
    foreach (array('netz_w' => 'W', 'bezug_w' => 'W', 'ueberschuss_w' => 'W') as $k => $e) {
        if (isset($w[$k])) { $teile[] = $k . ' ' . $w[$k] . ' ' . $e; }
    }
    return array('ok', sprintf(sm_t('MQ.HE_L_OK'), $wann, implode(', ', $teile),
                               isset($d['quelle']) ? (string) $d['quelle'] : '?'));
}

/**
 * 2.8.19: die Lage von haus/energie/frei_w als array(Zustand: ok|warn|fail|warte|'', Text) - fuer
 * den Reiter MQTT und die Pruefzeile im Reiter Test. Liest denselben Merker wie sm_he_lage().
 * '' = Schalter aus; warte = noch kein Minutenlauf. fail = frei_w wird gerade NICHT gesendet,
 * obwohl der Zaehler liefert (Akku war da und schweigt) - die Abnehmer fallen dann zurueck.
 */
function sm_he_frei_lage()
{
    list($z, $t) = sm_he_lage();
    if ($z === '' || $z === 'warte') {
        return array($z, $z === '' ? sm_t('MQ.HEF_L_AUS') : sm_t('MQ.HE_L_NOCH_NICHT'));
    }
    if ($z !== 'ok') {
        return array($z === 'warn' ? 'warn' : 'fail', sm_t('MQ.HEF_L_OHNE_ZAEHLER'));
    }
    $d = json_decode((string) @file_get_contents(sm_paths()['shm'] . '/haus_energie.json'), true);
    $f = (is_array($d) && isset($d['frei']) && is_array($d['frei'])) ? $d['frei'] : array();
    $w = (is_array($d) && isset($d['werte']) && is_array($d['werte'])) ? $d['werte'] : array();
    $grund = isset($f['grund']) ? (string) $f['grund'] : '';
    $sg = isset($f['speicher_grund']) ? (string) $f['speicher_grund'] : '';
    $sgruende = array('keine_lage' => sm_t('MQ.HEF_S_KEINE_LAGE'), 'quelle_schweigt' => sm_t('MQ.HEF_S_SCHWEIGT'),
                      'zu_alt' => sm_t('MQ.HEF_S_ZU_ALT'), 'ohne_aussage' => sm_t('MQ.HEF_S_OHNE_AUSSAGE'),
                      'leistung_fehlt' => sm_t('MQ.HEF_S_LEISTUNG'));
    $sg_text = isset($sgruende[$sg]) ? $sgruende[$sg] : $sg;
    if ($grund === 'netz+speicher' && isset($w['frei_w'])) {
        return array('ok', sprintf(sm_t('MQ.HEF_L_SPEICHER'), $w['frei_w'], isset($w['netz_w']) ? $w['netz_w'] : '?',
                                   isset($f['akku_w']) ? (int) $f['akku_w'] : 0));
    }
    if ($grund === 'netz' && isset($w['frei_w'])) {
        return array('ok', sprintf(sm_t('MQ.HEF_L_NETZ'), $w['frei_w']));
    }
    if ($grund === 'speicher_still') {
        $seit = (isset($f['gesehen']) && (int) $f['gesehen'] > 1) ? date('d.m.Y', (int) $f['gesehen']) : '?';
        return array('fail', sprintf(sm_t('MQ.HEF_L_STILL'), $seit, $sg_text));
    }
    if ($grund === 'kein_netz') {
        return array('fail', sm_t('MQ.HEF_L_KEIN_NETZ'));
    }
    return array('warte', sm_t('MQ.HE_L_NOCH_NICHT'));
}

/**
 * Die Lebenszeichen-Themen fuer die Loxone-Vorlagen (A7): Titel = Name des
 * virtuellen Eingangs, wie ihn das Gateway bildet; ohne Katalogeintrag, also
 * ohne erfundene Einheit, mit eigenem knappen Kommentar.
 */
function sm_vorlage_status($praefix)
{
    $aus = array();
    foreach (array('ok' => sm_t('LOX.VE_STATUS_OK'), 'ts' => sm_t('LOX.VE_STATUS_TS'),
                   'zaehler' => sm_t('LOX.VE_STATUS_ZAEHLER')) as $t => $kommentar) {
        // Anzeige "<v>": eine ganze Zahl ohne Einheit (ok 0/1, Unix-Sekunden,
        // Zaehler 0-999) - eine erfundene Einheit waere schlimmer als keine.
        $aus[] = array(str_replace(array('/', '%'), '_', trim((string) $praefix, '/') . '/status/' . $t),
                       'status_' . $t, $kommentar, '<v>');
    }
    return $aus;
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
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $v = (string) LBSystem::pluginversion(basename(__DIR__));
    }
    if ($v === '') {
        /* Gruppe 35 (2.8.13, T2-6): der Rueckfall ausserhalb der Weboberflaeche (Befehlszeile) fragt die
         * Plugindatenbank des LoxBerry - config/plugins/<ordner>/plugin.cfg gab es installiert nie. */
        $sm_db = json_decode((string) @file_get_contents(sm_paths()['home'] . '/data/system/plugindatabase.json'), true);
        if (is_array($sm_db) && isset($sm_db['plugins']) && is_array($sm_db['plugins'])) {
            foreach ($sm_db['plugins'] as $sm_e) {
                if (is_array($sm_e) && isset($sm_e['folder'], $sm_e['version'])
                    && (string) $sm_e['folder'] === sm_paths()['plugin']) {
                    $v = (string) $sm_e['version'];
                    break;
                }
            }
        }
    }
    if ($v === '') {
        // Die plugin.cfg kommentiert mit "#". PHPs INI-Zerleger kennt als
        // Kommentarzeichen nur ";" und bricht sonst ab - deshalb die
        // Kommentarzeilen vorher entfernen.
        // Nur noch der Archivfall (entpacktes Paket, Pruefstand).
        foreach (array(dirname(dirname(dirname(__FILE__))) . '/plugin.cfg') as $k) {
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
