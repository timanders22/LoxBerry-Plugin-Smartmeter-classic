#!/usr/bin/env php
<?php
/**
 * Smartmeter classic - Abholer des Legacy-Lesewegs
 *
 * Ersetzt das bisherige fetch.pl. Ruft je Lesekopf bin/sm_logger.pl auf
 * (der bleibt Perl - er spricht ueber Device::SerialPort mit dem Zaehler)
 * und verteilt die gelesenen Werte per MQTT und UDP.
 *
 * Aufruf:
 *   php fetch.php            vom Cron
 *   php fetch.php --verbose  von Hand, mit Ausgabe
 *
 * Wird als Verknuepfung in system/cron/cron.XXmin/ gelegt und dort DIREKT
 * ausgefuehrt - die Shebang-Zeile oben zaehlt also.
 *
 * ZEILENENDEN: LF, und das ist kein Geschmack.
 * Bis 2.3.14 war diese Datei als einzige PHP-Datei des Plugins durchgehend
 * CRLF. Der Kernel nimmt aus "#!/usr/bin/env php\r" den Interpreter
 * /usr/bin/env mit dem Argument "php\r" und findet den nicht - der
 * Cron-Eintrag des klassischen Lesers konnte nie laufen. Der Aufruf ueber
 * "Jetzt einmal abfragen" ging weiter, weil dort php ausdruecklich
 * davorsteht; deshalb ist es nie aufgefallen.
 */

// ---------------------------------------------------------------- Pfade
/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt.
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

$sm_home = getenv('LBHOMEDIR');
if (!$sm_home || !is_dir($sm_home)) {
    foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
        if (is_dir($k)) { $sm_home = $k; break; }
    }
}
$sm_home = $sm_home ? $sm_home : lb_wurzel_ermitteln();
// Der Pluginordner ergibt sich aus dem Ablageort dieser Datei
// (<home>/bin/plugins/<ordner>/fetch.php).
$sm_ordner = basename(dirname(__FILE__));
$sm_cfgdatei = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$sm_shm      = '/dev/shm/' . $sm_ordner;
$sm_logdatei = $sm_shm . '/fetch.log';
$sm_bin      = $sm_home . '/bin/plugins/' . $sm_ordner;

$sm_argv = isset($argv) ? (array) $argv : array();
$sm_laut = in_array('--verbose', $sm_argv, true);

/* Ein unbekannter Schalter darf nicht stillschweigend durchfallen: wer ein
 * Werkzeug von Hand aufruft, soll bei einem Tippfehler eine Antwort sehen,
 * keinen Lauf. */
foreach ($sm_argv as $sm_i => $sm_a) {
    if ($sm_i === 0 || strncmp((string) $sm_a, '--', 2) !== 0) { continue; }
    if (!in_array($sm_a, array('--verbose'), true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $sm_a . "\n");
        exit(2);
    }
}

@mkdir($sm_shm, 0775, true);

// Der gemeinsame Vorlauf - dieselbe Datei, die auch Oberflaeche und
// Endpunkt lesen. Ohne ihn stuenden Altersgrenze, Wertsaeuberung und der
// Zaehler dreimal im Plugin.
$sm_gemein = __DIR__ . '/sm_gemein.php';
if (!is_file($sm_gemein)) {
    fwrite(STDERR, "sm_gemein.php fehlt: $sm_gemein\n");
    exit(1);
}
require_once $sm_gemein;

/* ------------------------------------------------------------------
 * Nur ein Lauf gleichzeitig - eine Sperrdatei je DATENBESTAND
 *
 * Bei einem Takt von einer Minute kann der naechste Cron-Aufruf kommen,
 * bevor der vorige fertig ist - zwei Prozesse greifen dann auf DENSELBEN
 * Lesekopf zu. Eine serielle Schnittstelle laesst sich nicht teilen: beide
 * bekommen Bruchstuecke, und beide schreiben sie in dieselbe Datendatei.
 *
 * Die Sperre heisst seit 2.3.14 daten.lock und NICHT mehr fetch.lock:
 * bin/fetch_vzlogger.pl sperrte fetch_vzlogger.lock und schrieb dieselben
 * Dateien - die beiden sperrten sich also gerade nicht gegeneinander. Eine
 * Sperrdatei gehoert zum Datenbestand, nicht zum Skript. Sichtbar wird das,
 * sobald jemand der Empfehlung aus dem README folgt und im vzLogger-Reiter
 * DIESELBE Zaehlernummer eintraegt wie beim klassischen Leser.
 *
 * flock mit LOCK_NB: laeuft schon einer, endet dieser Aufruf sofort und
 * ohne Aufhebens - das ist der Normalfall und kein Fehler.
 * ------------------------------------------------------------------ */
$sm_sperre = @fopen($sm_shm . '/daten.lock', 'c');
if ($sm_sperre === false) {
    // Ohne Sperrdatei lieber lesen als gar nicht lesen.
    $sm_sperre = null;
} elseif (!flock($sm_sperre, LOCK_EX | LOCK_NB)) {
    fclose($sm_sperre);
    exit(0);
}

@unlink($sm_logdatei);

/**
 * Eine Zeile ins Protokoll DIESES Laufs.
 *
 * Hiess bis 2.4.1 sm_log() - genau wie die Funktion in
 * webfrontend/htmlauth/sm_lib.php, aber mit anderem Rumpf und anderem
 * ZIEL: hier /dev/shm/<ordner>/fetch.log, das oben bei jedem Lauf geleert
 * wird, dort das dauerhafte log/plugins/<ordner>/smartmeter.log. Auf dem
 * Geraet liegen bin/ und webfrontend/ in getrennten Baeumen, die beiden
 * trafen sich also nie - im entpackten Archiv aber schon, und zwei Rumpfe
 * unter einem Namen sind zwei Wahrheiten. Deshalb ein eigener Name statt
 * einer function_exists-Wache: die haette nur die Ladereihenfolge
 * darueber entscheiden lassen, in welche Datei protokolliert wird.
 *
 * Die Kappung steht in smg_log_kappen() (bin/sm_gemein.php) - sie
 * betrifft beide Protokolle, und wortgleich ausgeschrieben stand sie
 * bis 2.4.1 zweimal da.
 */
function sm_fetch_log($text, $stufe = 'INFO')
{
    global $sm_logdatei, $sm_laut;
    $zeile = date('Y-m-d H:i:s') . " <$stufe> $text";
    smg_log_kappen($sm_logdatei);
    @file_put_contents($sm_logdatei, $zeile . "\n", FILE_APPEND);
    if ($sm_laut) {
        echo $zeile . "\n";
    }
}

/**
 * Ein Abbruch geht AUCH auf die Fehlerausgabe.
 *
 * Der Cron schreibt nach /dev/null, das Protokoll liegt auf der Ramdisk -
 * ein Lauf, der mit 1 endet und nur dorthin schreibt, ist von aussen
 * stumm. installationslage_pruefen.py meldete genau das als
 * "Abbruch ohne Meldung, Rueckgabewert 1".
 */
function sm_abbruch($text)
{
    sm_fetch_log($text, 'ERROR');
    fwrite(STDERR, $text . "\n");
    exit(1);
}

$sm_cfg = smg_cfg_lesen($sm_cfgdatei);
if (!$sm_cfg) {
    sm_abbruch('Konfiguration nicht lesbar: ' . $sm_cfgdatei);
}

if (smg_wert($sm_cfg, 'MAIN', 'READ', '0') !== '1') {
    sm_fetch_log('Der Legacy-Leser ist abgeschaltet (READ=0). Nichts zu tun.', 'INFO');
    exit(0);
}

/* Die Vorgaben stehen an EINER Stelle - in sm_vorgaben() der Bibliothek.
 * Diese Datei kann sie nicht aufrufen (getrennte Baeume), sie liest
 * deshalb dieselben Werte aus der Datei und faellt auf dieselben Vorgaben
 * zurueck. Bis 2.3.14 stand SENDMQTT hier auf '1' und in der Oberflaeche
 * auf '0': fehlte der Schluessel, zeigte die Oberflaeche den Haken AUS,
 * waehrend dieser Lauf sendete. */
$sm_sendudp  = smg_wert($sm_cfg, 'MAIN', 'SENDUDP', '0') === '1';
$sm_udpport  = (int) smg_wert($sm_cfg, 'MAIN', 'UDPPORT', '7000');
$sm_sendmqtt = smg_wert($sm_cfg, 'MAIN', 'SENDMQTT', '1') === '1';
$sm_topic    = trim(smg_wert($sm_cfg, 'MAIN', 'MQTTTOPIC', 'smartmeter'), '/');

// ------------------------------------------------- Miniserver ermitteln
/**
 * Die Miniserver aus general.json. Die Perl-Fassung nahm dafuer
 * LoxBerry::System::get_miniservers(); in PHP lesen wir dieselbe Datei.
 */
function sm_miniserver($home)
{
    $datei = $home . '/config/system/general.json';
    if (!is_readable($datei)) {
        return array();
    }
    $alles = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($alles) || !isset($alles['Miniserver']) || !is_array($alles['Miniserver'])) {
        return array();
    }
    $liste = array();
    foreach ($alles['Miniserver'] as $ms) {
        if (!is_array($ms)) { continue; }
        $ip = isset($ms['Ipaddress']) ? $ms['Ipaddress']
            : (isset($ms['IPAddress']) ? $ms['IPAddress'] : '');
        if ($ip !== '') { $liste[] = $ip; }
    }
    return $liste;
}

/** Den UDP-In-Port des MQTT-Gateways lesen. */
function sm_gateway_udpin($home)
{
    $datei = $home . '/config/system/general.json';
    if (!is_readable($datei)) {
        return 0;
    }
    $roh = (string) @file_get_contents($datei);
    // Beide Schreibweisen pruefen - die Gateway-Konfiguration ist uneinheitlich.
    if (preg_match('/"Udpinport"\s*:\s*"?(\d+)"?/i', $roh, $m)) {
        return (int) $m[1];
    }
    return 0;
}

/** Eine Zeile per UDP schicken. */
function sm_udp($ziel, $port, $text)
{
    $s = @fsockopen('udp://' . $ziel, $port, $errno, $errstr, 2);
    if (!$s) {
        return false;
    }
    /* Den Rueckgabewert ansehen. Bis 2.4.2 lieferte sm_udp() bedingungslos
     * true, sobald der Socket stand - eine abgewiesene oder gekuerzte
     * Schreibung erschien danach als "UDP an ... gesendet." auf Stufe OK. */
    $n = @fwrite($s, $text);
    @fclose($s);
    return $n === strlen($text);
}

// ------------------------------------------------------- Hauptdurchlauf
$sm_gelesen = 0;
$sm_versucht = 0;
$sm_gescheitert = 0;

foreach ($sm_cfg as $sm_abschnitt => $sm_werte) {
    if ($sm_abschnitt === 'MAIN' || !isset($sm_werte['DEVICE'])) {
        continue;
    }
    $sm_serial = $sm_abschnitt;
    $sm_device = $sm_werte['DEVICE'];
    $sm_meter  = isset($sm_werte['METER']) ? $sm_werte['METER'] : '0';

    if ($sm_meter === '' || $sm_meter === '0') {
        sm_fetch_log($sm_serial . ': kein Zaehlerprofil festgelegt - uebersprungen.', 'WARN');
        continue;
    }
    if (!file_exists($sm_device)) {
        sm_fetch_log($sm_serial . ': ' . $sm_device . ' gibt es nicht - Lesekopf abgezogen?', 'WARN');
        continue;
    }

    // --- den Perl-Leser aufrufen
    $sm_logger = $sm_bin . '/sm_logger.pl';
    if (!is_readable($sm_logger)) {
        sm_fetch_log('sm_logger.pl fehlt: ' . $sm_logger, 'ERROR');
        continue;
    }
    $sm_befehl = escapeshellarg($sm_logger) . ' --device ' . escapeshellarg($sm_device);
    if ($sm_meter === 'manual') {
        // Alle Einzelwerte durchreichen.
        foreach (array('protocol' => 'PROTOCOL', 'startbaudrate' => 'STARTBAUDRATE',
                       'baudrate' => 'BAUDRATE', 'timeout' => 'TIMEOUT',
                       'delay' => 'DELAY', 'handshake' => 'HANDSHAKE',
                       'databits' => 'DATABITS', 'stopbits' => 'STOPBITS',
                       'parity' => 'PARITY', 'crc' => 'CRC') as $sm_opt => $sm_k) {
            $sm_v = isset($sm_werte[$sm_k]) ? $sm_werte[$sm_k] : '';
            if ($sm_v !== '') {
                $sm_befehl .= ' --' . $sm_opt . ' ' . escapeshellarg($sm_v);
            }
        }
    } else {
        $sm_befehl .= ' --protocol ' . escapeshellarg($sm_meter);
    }
    if ($sm_laut) {
        $sm_befehl .= ' --verbose';
    }

    sm_fetch_log($sm_serial . ': lese ueber ' . $sm_device . ' (Profil ' . $sm_meter . ')');
    /* A3 (Durchgang 01.10.2026): der Zeitstempel der Messung VOR dem Lesen.
     * Nur wenn er danach gewachsen ist, hat dieser Lauf etwas gemessen. */
    $sm_ts_vorher = smg_ts_datei($sm_shm . '/' . $sm_serial . '.data');
    $sm_aus = array(); $sm_rc = 0;
    @exec('perl ' . $sm_befehl . ' 2>&1', $sm_aus, $sm_rc);
    if ($sm_rc !== 0) {
        sm_fetch_log($sm_serial . ': sm_logger.pl endete mit ' . $sm_rc . ' - '
             . substr(implode(' ', $sm_aus), 0, 200), 'ERROR');
    }

    // --- Ergebnis einlesen
    $sm_datendatei = $sm_shm . '/' . $sm_serial . '.data';
    $sm_zeilen = is_readable($sm_datendatei)
        ? (array) @file($sm_datendatei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : array();

    /* SEIT DEM DURCHGANG 01.10.2026 (A3): gesendet wird nur eine NEUE
     * Messung. Bis 2.8.5 ging die Datendatei bei jedem Lauf hinaus, auch
     * wenn sm_logger.pl sie gar nicht erneuert hatte - scheiterte der
     * SML-Zerleger, sendete jeder Minutenlauf die zwei Stunden alten Werte
     * als frische Nachrichten, und das Protokoll sagte "<OK> ... mit Daten"
     * (gemessen, MQTT-Bericht Nr. 2). */
    $sm_ts_nachher = smg_ts_datei($sm_datendatei);
    $sm_frisch = ($sm_zeilen && $sm_ts_nachher > $sm_ts_vorher);
    $sm_senden = $sm_frisch ? $sm_zeilen : array();
    if (!$sm_zeilen) {
        sm_fetch_log($sm_serial . ': keine Daten gelesen.', 'WARN');
    } elseif (!$sm_frisch) {
        sm_fetch_log($sm_serial . ': keine neue Messung in diesem Lauf - die Werte von '
             . ($sm_ts_nachher > 0 ? 'vor ' . max(0, time() - $sm_ts_nachher) . ' s' : 'unbekanntem Alter')
             . ' werden nicht erneut gesendet.', 'WARN');
    } else {
        $sm_gelesen++;
    }

    // --- UDP an die Miniserver
    if ($sm_sendudp) {
        $sm_text = $sm_senden
            ? implode('; ', $sm_senden) . '; '
            : $sm_serial . ': No data found';
        $sm_ms = sm_miniserver($sm_home);
        if (!$sm_ms) {
            sm_fetch_log('UDP: kein Miniserver in general.json gefunden.', 'WARN');
        }
        foreach ($sm_ms as $sm_ziel) {
            $sm_versucht++;
            if (sm_udp($sm_ziel, $sm_udpport, $sm_text)) {
                sm_fetch_log('UDP an ' . $sm_ziel . ':' . $sm_udpport . ' gesendet.', 'OK');
            } else {
                $sm_gescheitert++;
                sm_fetch_log('UDP an ' . $sm_ziel . ':' . $sm_udpport . ' fehlgeschlagen.', 'WARN');
            }
        }
    }

    // --- MQTT ueber das Gateway-Relais
    if ($sm_sendmqtt) {
        $sm_udpin = sm_gateway_udpin($sm_home);
        if (!$sm_udpin) {
            sm_fetch_log('MQTT: Kein UDP-In-Port des MQTT Gateways gefunden - uebersprungen.', 'WARN');
        } else {
            $sm_anzahl = 0;
            $sm_fehl = 0;
            $sm_zustaende = array();
            foreach ($sm_senden as $sm_z) {
                if ($sm_z === '' || $sm_z[0] === '#') {
                    continue;
                }
                // Zeilenform: SERIAL:Schluessel:Wert
                $sm_teile = explode(':', $sm_z, 3);
                if (count($sm_teile) < 3 || $sm_teile[0] !== $sm_serial) {
                    continue;
                }
                $sm_k = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $sm_teile[1]);
                $sm_v = smg_wert_saeubern($sm_teile[2]);
                if ($sm_k === '' || $sm_v === '') {
                    continue;
                }
                /* M3 (Durchgang 01.10.2026, Entscheidung 3): Zustaende
                 * retained, alles andere fluechtig. Leer geht nie hinaus
                 * (zwei Zeilen hoeher) - eine leere retain-Nutzlast loeschte. */
                $sm_verb = smg_mqtt_retained($sm_k) ? 'retain' : 'publish';
                if ($sm_verb === 'retain') {
                    $sm_zustaende[] = $sm_k;
                }
                if (sm_udp('127.0.0.1', $sm_udpin,
                           $sm_verb . ' ' . $sm_topic . '/' . $sm_serial . '/' . $sm_k . ' ' . $sm_v)) {
                    $sm_anzahl++;
                } else {
                    $sm_fehl++;
                }
            }
            /* Ein frueher retained gesendetes Zustandsfeld, das diese
             * ERFOLGREICHE Messung nicht mehr liefert, bekommt einmal "-"
             * retained (Entscheidungen 5 und 8) - sonst stuende der alte
             * Zustand fuer immer im Broker. Schweigt der Zaehler, bleibt er
             * stehen; dann gilt status/ok. Der Merker liegt auf der Ramdisk. */
            if ($sm_frisch) {
                $sm_merker = $sm_shm . '/' . $sm_serial . '.retained';
                $sm_vorher_r = is_file($sm_merker)
                    ? array_filter(array_map('trim', (array) @file($sm_merker))) : array();
                foreach (array_diff($sm_vorher_r, $sm_zustaende) as $sm_weg) {
                    if (!smg_mqtt_retained($sm_weg)) {
                        continue;
                    }
                    if (sm_udp('127.0.0.1', $sm_udpin,
                               'retain ' . $sm_topic . '/' . $sm_serial . '/' . $sm_weg . ' -')) {
                        $sm_anzahl++;
                        sm_fetch_log($sm_serial . ': ' . $sm_weg . ' fehlt in dieser Messung - "-" retained gesendet.', 'INFO');
                    } else {
                        $sm_fehl++;
                    }
                }
                $sm_zustaende = array_values(array_unique($sm_zustaende));
                sort($sm_zustaende);
                if ($sm_zustaende) {
                    @file_put_contents($sm_merker, implode("\n", $sm_zustaende) . "\n");
                } elseif (is_file($sm_merker)) {
                    @unlink($sm_merker);
                }
            }
            $sm_versucht += $sm_anzahl + $sm_fehl;
            $sm_gescheitert += $sm_fehl;
            // Ein Zaehler zaehlt Zustellungen, nicht Schleifendurchlaeufe -
            // und die Schlussmeldung nennt beides getrennt.
            sm_fetch_log('MQTT: ' . $sm_anzahl . ' Werte an ' . $sm_topic . '/' . $sm_serial
                 . ($sm_fehl ? ', ' . $sm_fehl . ' gescheitert' : '')
                 . ' (Gateway-Relais 127.0.0.1:' . $sm_udpin . ')',
                 $sm_fehl ? 'WARN' : 'OK');
        }
    }
}

/* Das Lebenszeichen. Es geht bei JEDEM abgeschlossenen Durchlauf eine
 * Stelle weiter - auch dann, wenn kein Zaehler geantwortet hat. Ob WERTE
 * ankamen, beantwortet der Zeitstempel Last_UpdateUnix, den sm_logger.pl
 * nur nach einer erfolgreichen Messung schreibt. Zwei verschiedene Fragen,
 * zwei verschiedene Groessen. */
$sm_stand = smg_zaehler_weiter($sm_shm . '/zaehler');

/* A7 (Durchgang 01.10.2026, Entscheidung 8): das Lebenszeichen auch ueber
 * MQTT - bis 2.8.5 stand es nur in der Schlusszeile des Endpunkts.
 * status/ok traegt dasselbe Urteil wie OK im Endpunkt (jeder eingeschaltete
 * Lesekopf juenger als 3 x Takt), status/ts die Zeit dieses Laufs,
 * status/zaehler den umlaufenden Zaehler. Alle drei fluechtig: ein
 * zurueckbehaltenes ok=1 sagte nach einem Neustart "in Ordnung" ueber einen
 * toten Dienst. Gesendet in JEDEM Lauf, auch wenn kein Zaehler antwortete. */
if ($sm_sendmqtt) {
    $sm_udpin = sm_gateway_udpin($sm_home);
    if ($sm_udpin) {
        $sm_lage = smg_kopf_lage($sm_shm, smg_koepfe($sm_cfg, null),
                                 smg_alter_grenze(smg_wert($sm_cfg, 'MAIN', 'CRON', '5'), false));
        $sm_status_ok = $sm_lage ? 1 : 0;
        foreach ($sm_lage as $sm_l) {
            if (!$sm_l['ok']) { $sm_status_ok = 0; }
        }
        $sm_st_fehl = 0;
        foreach (array('status/ok' => $sm_status_ok, 'status/ts' => time(),
                       'status/zaehler' => $sm_stand) as $sm_k => $sm_v) {
            $sm_versucht++;
            if (!sm_udp('127.0.0.1', $sm_udpin, 'publish ' . $sm_topic . '/' . $sm_k . ' ' . $sm_v)) {
                $sm_st_fehl++;
            }
        }
        $sm_gescheitert += $sm_st_fehl;
        sm_fetch_log('MQTT: Lebenszeichen status/ok=' . $sm_status_ok . ' gesendet'
             . ($sm_st_fehl ? ', ' . $sm_st_fehl . ' von 3 gescheitert' : '') . '.',
             $sm_st_fehl ? 'WARN' : 'OK');
        /* M3: die Abodatei des Gateways folgt dem Praefix (Regeln/07). */
        list($sm_abo_pfad, , $sm_abo_neu) = smg_abo_datei($sm_home, $sm_ordner, $sm_topic, true);
        if ($sm_abo_neu) {
            sm_fetch_log('MQTT: Abodatei des Gateways gesetzt: ' . $sm_topic . '/# (' . $sm_abo_pfad . ').', 'INFO');
        }
    }
}

sm_fetch_log('Durchlauf beendet, ' . $sm_gelesen . ' Lesekopf/Lesekoepfe mit neuer Messung, '
     . 'Zaehler ' . $sm_stand
     . ($sm_gescheitert ? ', ' . $sm_gescheitert . ' von ' . $sm_versucht . ' Zustellungen gescheitert' : ''),
     $sm_gescheitert ? 'WARN' : 'OK');
exit(0);
