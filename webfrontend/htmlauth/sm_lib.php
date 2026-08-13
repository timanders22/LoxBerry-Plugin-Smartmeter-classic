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
        'log'     => $home . '/log/plugins/' . $ordner,
        'shm'     => '/dev/shm/' . $ordner,
        'vzlog'   => '/dev/shm/' . $ordner . '/vzlogger.log',
        'fetchlog' => '/dev/shm/' . $ordner . '/vzlogger_fetch.log',
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
    $datei = sm_paths()['vzjson'];
    @mkdir(dirname($datei), 0775, true);
    $tmp = $datei . '.tmp';
    $z = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($z === false || @file_put_contents($tmp, $z) === false) {
        return false;
    }
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
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
    return @file_put_contents($p['vzconf'],
        json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

/* ==================================================================
 * Legacy-Konfiguration (smartmeter.cfg) - hier steht auch MQTT
 * ================================================================== */

/**
 * MQTT steht in der Legacy-Konfigurationsdatei, weil beide Betriebsarten
 * denselben Weg benutzen. Gelesen wird an mehreren Stellen, geschrieben
 * nur im Reiter MQTT.
 */
function sm_legacy_read()
{
    $cfg = array('SENDMQTT' => '0', 'MQTTTOPIC' => 'smartmeter',
                 'SENDUDP' => '0', 'UDPPORT' => '7000',
                 'CRON' => '5', 'READ' => '0');
    $datei = sm_paths()['legacy'];
    if (!is_readable($datei)) {
        return $cfg;
    }
    foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES) as $z) {
        if (preg_match('/^\s*([A-Z]+)\s*=\s*(\S*)\s*$/', $z, $m)
            && array_key_exists($m[1], $cfg)) {
            $cfg[$m[1]] = $m[2];
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

/* ==================================================================
 * vzlogger: Programm, Prozess, Schnittstelle
 * ================================================================== */

/**
 * Ein lauffaehiges vzlogger-Programm suchen.
 * Liefert (Pfad, Grund). Pfad ist '' wenn nichts laeuft; der Grund wird
 * dem Benutzer woertlich angezeigt.
 */
function sm_vz_binary()
{
    $p = sm_paths();
    list(, $arch) = sm_sh('uname -m');
    $arch = trim($arch);
    list(, $sys) = sm_sh('command -v vzlogger');
    $sys = trim($sys);

    if ($sys === '') {
        return array('', 'Es ist kein vzlogger installiert. Der Knopf "vzlogger installieren" '
                       . 'richtet die Paketquelle von volkszaehler.org ein und installiert das '
                       . 'zu dieser Architektur (' . $arch . ') passende Paket. Bis dahin liefert '
                       . 'die Legacy-Betriebsart Werte - die braucht kein vzlogger.');
    }
    if (!is_executable($sys)) {
        return array('', $sys . ' ist nicht ausfuehrbar (Dateirechte).');
    }
    if (sm_vz_binary_laeuft($sys)) {
        return array($sys, '');
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
        $bericht = 'startet nicht (rc=' . $rc . '): '
                 . substr(preg_replace('/\s+/', ' ', $out), 0, 160);
    }
    return array('', $sys . ' ist vorhanden, startet aber nicht. ' . $bericht);
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

/** Fassung laut Paketverwaltung. */
function sm_vz_paket($was)
{
    $h = sm_paths()['bin'] . '/vzlogger_pkg.sh';
    if (!is_executable($h)) {
        return '';
    }
    list(, $v) = sm_sh(escapeshellarg($h) . ' ' . escapeshellarg($was));
    return trim($v);
}

/** Installation anstossen (ueber sudoers freigegeben). */
function sm_vz_install()
{
    $h = sm_paths()['bin'] . '/vzlogger_pkg.sh';
    if (!is_executable($h)) {
        return 'Paket-Helfer fehlt: ' . $h;
    }
    list(, $out) = sm_sh('sudo -n ' . escapeshellarg($h) . ' install');
    return trim($out) !== '' ? $out : 'Keine Ausgabe.';
}

/**
 * PIDs unseres vzlogger - erkannt an unserer eigenen Konfigurationsdatei,
 * damit ein vzlogger eines anderen Plugins nie mitgezaehlt wird.
 */
function sm_vz_running()
{
    // pgrep -f findet auch die Shell, die pgrep aufruft - ihre Befehlszeile
    // enthaelt das Suchmuster. Deshalb jede Fundstelle gegen den echten
    // Programmnamen pruefen.
    list(, $roh) = sm_sh('pgrep -f -- ' . escapeshellarg('-c ' . sm_paths()['vzconf']));
    $ok = array();
    foreach (preg_split('/\s+/', trim($roh)) as $pid) {
        if ($pid === '' || !ctype_digit($pid)) {
            continue;
        }
        list(, $comm) = sm_sh('ps -p ' . (int) $pid . ' -o comm=');
        if (preg_match('/vzlogger/i', $comm)) {
            $ok[] = $pid;
        }
    }
    return implode(' ', $ok);
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
    if (!$cfg['enabled']) {
        return '';
    }
    list($bin, $warum) = sm_vz_binary();
    if ($bin === '') {
        sm_vz_note('START ABGEBROCHEN: ' . $warum);
        return $warum;
    }
    // Startfehler landen im Protokoll statt in /dev/null.
    sm_sh('nohup ' . escapeshellarg($bin) . ' -c ' . escapeshellarg($p['vzconf'])
        . ' >> ' . escapeshellarg($p['vzlog']) . ' 2>&1 &');
    sleep(2);
    $pid = sm_vz_running();
    if ($pid === '') {
        sm_vz_note('START FEHLGESCHLAGEN: ' . $bin . ' -c ' . $p['vzconf']
                 . ' lief nach 2 Sekunden nicht mehr. Ursache siehe Zeilen darueber.');
        return 'vzlogger wurde gestartet, lief aber nach zwei Sekunden nicht mehr. '
             . 'Einzelheiten stehen im Protokoll unten.';
    }
    sm_vz_note('gestartet: ' . $bin . ' (PID ' . $pid . ')');
    return '';
}

/** Die erkannten Lesekoepfe. */
function sm_lesekoepfe()
{
    $d = glob('/dev/serial/smartmeter/*');
    return is_array($d) ? $d : array();
}

/** Letzte Zeilen der beiden Protokolle. */
function sm_logtail()
{
    $p = sm_paths();
    $aus = array();
    foreach (array($p['vzlog'], $p['fetchlog']) as $f) {
        if (!is_readable($f)) {
            continue;
        }
        list(, $t) = sm_sh('tail -n 25 ' . escapeshellarg($f));
        if (trim($t) === '') {
            continue;
        }
        $aus[] = '--- ' . $f . " ---\n" . $t;
    }
    return $aus ? implode("\n", $aus) : 'Noch keine Protokolleintraege vorhanden.';
}

function sm_hostname()
{
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1.
 *  Titel und Einheiten exakt wie die Tabelle im Reiter "Einbindung in Loxone". */
function sm_vorlage()
{
    $cfg    = sm_vz_read();
    $legacy = sm_legacy_read();
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Smartmeter Zaehlerwerte" Comment="Erzeugt vom LoxBerry-Plugin Smartmeter classic (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . htmlspecialchars($legacy['MQTTTOPIC'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cfg['channels'] as $c) {
        $titel = str_replace(array('/', ':', '-', '.'), '_',
            $legacy['MQTTTOPIC'] . '_' . $cfg['serial'] . '_' . $c);
        if ($c === '1-0:16.7.0') {
            $einheit = '<v.0> W';
            $bed     = 'aktuelle Wirkleistung';
            $signed  = 'true';
            $min     = '-100000';
            $max     = '100000';
        } else {
            $einheit = '<v.3> kWh';
            $bed     = ($c === '1-0:1.8.0') ? 'Zaehlerstand Bezug'
                     : (($c === '1-0:2.8.0') ? 'Zaehlerstand Einspeisung' : 'OBIS ' . $c);
            $signed  = 'false';
            $min     = '0';
            $max     = '1000000';
        }
        $o .= "\t" . '<VirtualInHttpCmd Title="' . htmlspecialchars($titel, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
        $o .= 'Comment="' . htmlspecialchars($bed, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Check=" " ';
        $o .= 'Signed="' . $signed . '" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $min . '" MaxVal="' . $max . '" Unit="' . htmlspecialchars($einheit, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_smartmeter.xml', $o);
}
