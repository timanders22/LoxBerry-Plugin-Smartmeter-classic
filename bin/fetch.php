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
 * Wird als Verknuepfung in system/cron/cron.XXmin/ gelegt.
 */

// ---------------------------------------------------------------- Pfade
$sm_home = getenv('LBHOMEDIR');
if (!$sm_home || !is_dir($sm_home)) {
    foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
        if (is_dir($k)) { $sm_home = $k; break; }
    }
}
$sm_home = $sm_home ? $sm_home : '/opt/loxberry';
// Der Pluginordner ergibt sich aus dem Ablageort dieser Datei
// (<home>/bin/plugins/<ordner>/fetch.php).
$sm_ordner = basename(dirname(__FILE__));
$sm_cfgdatei = $sm_home . '/config/plugins/' . $sm_ordner . '/smartmeter.cfg';
$sm_shm      = '/dev/shm/' . $sm_ordner;
$sm_logdatei = $sm_shm . '/fetch.log';
$sm_bin      = $sm_home . '/bin/plugins/' . $sm_ordner;

$sm_argv = isset($argv) ? (array) $argv : array();
$sm_laut = in_array('--verbose', $sm_argv, true);

@mkdir($sm_shm, 0775, true);
@unlink($sm_logdatei);

function sm_log($text, $stufe = 'INFO')
{
    global $sm_logdatei, $sm_laut;
    $zeile = date('Y-m-d H:i:s') . " <$stufe> $text";
    @file_put_contents($sm_logdatei, $zeile . "\n", FILE_APPEND);
    if ($sm_laut) {
        echo $zeile . "\n";
    }
}

// ------------------------------------------------------ Konfiguration
function sm_cfg_lesen($datei)
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

function sm_wert($alles, $abschnitt, $schluessel, $vorgabe = '')
{
    return (isset($alles[$abschnitt][$schluessel]) && $alles[$abschnitt][$schluessel] !== '')
        ? $alles[$abschnitt][$schluessel] : $vorgabe;
}

$sm_cfg = sm_cfg_lesen($sm_cfgdatei);
if (!$sm_cfg) {
    sm_log('Konfiguration nicht lesbar: ' . $sm_cfgdatei, 'ERROR');
    exit(1);
}

if (sm_wert($sm_cfg, 'MAIN', 'READ', '0') !== '1') {
    sm_log('Der Legacy-Leser ist abgeschaltet (READ=0). Nichts zu tun.', 'INFO');
    exit(0);
}

$sm_sendudp  = sm_wert($sm_cfg, 'MAIN', 'SENDUDP', '0') === '1';
$sm_udpport  = (int) sm_wert($sm_cfg, 'MAIN', 'UDPPORT', '7000');
$sm_sendmqtt = sm_wert($sm_cfg, 'MAIN', 'SENDMQTT', '1') === '1';
$sm_topic    = sm_wert($sm_cfg, 'MAIN', 'MQTTTOPIC', 'smartmeter');

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
    @fwrite($s, $text);
    @fclose($s);
    return true;
}

// ------------------------------------------------------- Hauptdurchlauf
$sm_gelesen = 0;

foreach ($sm_cfg as $sm_abschnitt => $sm_werte) {
    if ($sm_abschnitt === 'MAIN' || !isset($sm_werte['DEVICE'])) {
        continue;
    }
    $sm_serial = $sm_abschnitt;
    $sm_device = $sm_werte['DEVICE'];
    $sm_meter  = isset($sm_werte['METER']) ? $sm_werte['METER'] : '0';

    if ($sm_meter === '' || $sm_meter === '0') {
        sm_log($sm_serial . ': kein Zaehlerprofil festgelegt - uebersprungen.', 'WARN');
        continue;
    }
    if (!file_exists($sm_device)) {
        sm_log($sm_serial . ': ' . $sm_device . ' gibt es nicht - Lesekopf abgezogen?', 'WARN');
        continue;
    }

    // --- den Perl-Leser aufrufen
    $sm_logger = $sm_bin . '/sm_logger.pl';
    if (!is_readable($sm_logger)) {
        sm_log('sm_logger.pl fehlt: ' . $sm_logger, 'ERROR');
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

    sm_log($sm_serial . ': lese ueber ' . $sm_device . ' (Profil ' . $sm_meter . ')');
    $sm_aus = array(); $sm_rc = 0;
    @exec('perl ' . $sm_befehl . ' 2>&1', $sm_aus, $sm_rc);
    if ($sm_rc !== 0) {
        sm_log($sm_serial . ': sm_logger.pl endete mit ' . $sm_rc . ' - '
             . substr(implode(' ', $sm_aus), 0, 200), 'ERROR');
    }

    // --- Ergebnis einlesen
    $sm_datendatei = $sm_shm . '/' . $sm_serial . '.data';
    $sm_zeilen = is_readable($sm_datendatei)
        ? (array) @file($sm_datendatei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : array();

    if (!$sm_zeilen) {
        sm_log($sm_serial . ': keine Daten gelesen.', 'WARN');
    } else {
        $sm_gelesen++;
    }

    // --- UDP an die Miniserver
    if ($sm_sendudp) {
        $sm_text = $sm_zeilen
            ? implode('; ', $sm_zeilen) . '; '
            : $sm_serial . ': No data found';
        $sm_ms = sm_miniserver($sm_home);
        if (!$sm_ms) {
            sm_log('UDP: kein Miniserver in general.json gefunden.', 'WARN');
        }
        foreach ($sm_ms as $sm_ziel) {
            if (sm_udp($sm_ziel, $sm_udpport, $sm_text)) {
                sm_log('UDP an ' . $sm_ziel . ':' . $sm_udpport . ' gesendet.', 'OK');
            } else {
                sm_log('UDP an ' . $sm_ziel . ':' . $sm_udpport . ' fehlgeschlagen.', 'WARN');
            }
        }
    }

    // --- MQTT ueber das Gateway-Relais
    if ($sm_sendmqtt) {
        $sm_udpin = sm_gateway_udpin($sm_home);
        if (!$sm_udpin) {
            sm_log('MQTT: Kein UDP-In-Port des MQTT Gateways gefunden - uebersprungen.', 'WARN');
        } else {
            $sm_anzahl = 0;
            foreach ($sm_zeilen as $sm_z) {
                if ($sm_z === '' || $sm_z[0] === '#') {
                    continue;
                }
                // Zeilenform: SERIAL:Schluessel:Wert
                $sm_teile = explode(':', $sm_z, 3);
                if (count($sm_teile) < 3 || $sm_teile[0] !== $sm_serial) {
                    continue;
                }
                $sm_k = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $sm_teile[1]);
                $sm_v = $sm_teile[2];
                if ($sm_k === '' || $sm_v === '') {
                    continue;
                }
                if (sm_udp('127.0.0.1', $sm_udpin,
                           'publish ' . $sm_topic . '/' . $sm_serial . '/' . $sm_k . ' ' . $sm_v)) {
                    $sm_anzahl++;
                }
            }
            sm_log('MQTT: ' . $sm_anzahl . ' Werte an ' . $sm_topic . '/' . $sm_serial
                 . ' (Gateway-Relais 127.0.0.1:' . $sm_udpin . ')', 'OK');
        }
    }
}

sm_log('Durchlauf beendet, ' . $sm_gelesen . ' Lesekopf/Lesekoepfe mit Daten.', 'OK');
exit(0);
