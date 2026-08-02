<?php
/**
 * Smartmeter classic - Diagnose und Prüfungen
 *
 * Acht Prüfungen, die zusammen zeigen, wo es klemmt. Sie beantworten ohne
 * Loxone die Frage, ob der Zähler gelesen wird.
 */

require_once __DIR__ . '/sm_lib.php';

/**
 * Die Diagnose. Liefert eine Liste aus (Bezeichnung, Zustand, Text);
 * Zustand ist 'ok', 'warn' oder 'fail'.
 */
function sm_diagnose($cfg)
{
    $p = sm_paths();
    $d = array();
    $add = function ($label, $zustand, $text) use (&$d) {
        $d[] = array($label, $zustand, $text);
    };

    // 1 - Betriebsart
    if (!$cfg['enabled']) {
        $add('Betriebsart', 'warn', 'vzLogger ist ausgeschaltet. Es wird nichts gelesen.');
        return $d;
    }
    $add('Betriebsart', 'ok', 'vzLogger ist eingeschaltet.');

    // 2 - Programm
    list($bin, $warum) = sm_vz_binary();
    $akt = sm_vz_paket('current');
    $verf = sm_vz_paket('available');
    if ($bin !== '') {
        list(, $v) = sm_sh(escapeshellarg($bin) . ' --version | head -1');
        $v = trim($v);
        $txt = $bin . ($v !== '' ? ' - ' . $v : '');
        if ($akt !== '') {
            $txt .= ' (Paket ' . $akt
                  . (($verf !== '' && $verf !== $akt) ? ', verfuegbar ' . $verf : '') . ')';
        }
        $add('Programm', ($verf !== '' && $akt !== '' && $verf !== $akt) ? 'warn' : 'ok', $txt);
    } else {
        $add('Programm', 'fail', $warum);
    }

    // 3 - Prozess
    $pid = sm_vz_running();
    if ($pid !== '') {
        $add('Prozess', 'ok', 'laeuft, PID ' . $pid);
    } else {
        $add('Prozess', 'fail', 'Es laeuft kein vzlogger mit unserer Konfiguration ('
                              . $p['vzconf'] . ').');
    }

    // 4 - Konfigurationsdatei
    if (!is_readable($p['vzconf'])) {
        $add('Konfiguration', 'fail', $p['vzconf'] . ' fehlt. Einmal Speichern erzeugt sie neu.');
    } else {
        $txt = (string) @file_get_contents($p['vzconf']);
        $n = substr_count($txt, '"identifier"');
        if (strpos($txt, '"meters"') === false) {
            $add('Konfiguration', 'fail', 'In ' . $p['vzconf'] . ' steht kein meters-Abschnitt.');
        } elseif (!$n) {
            $add('Konfiguration', 'fail', 'Kein einziger Kanal in der Konfiguration. '
                                        . 'Ohne Kanal liest vzlogger nichts.');
        } elseif (preg_match('/"interval"\s*:\s*-/', $txt)) {
            $add('Konfiguration', 'warn', $n . ' Kanaele - aber es steht ein negatives '
                . 'interval darin. Bei sendenden SML-Zaehlern gehoert der Schluessel weggelassen.');
        } else {
            $add('Konfiguration', 'ok', $n . ' Kanaele, kein interval-Schluessel '
                . '(richtig fuer sendende SML-Zaehler).');
        }
    }

    // 5 - Lesekopf
    $dev = (string) $cfg['device'];
    if ($dev === '') {
        $add('Lesekopf', 'fail', 'Kein Geraet ausgewaehlt.');
    } elseif (!file_exists($dev)) {
        $add('Lesekopf', 'fail', $dev . ' gibt es nicht. Lesekopf abziehen und neu '
            . 'anstecken; die udev-Regel wird beim Start des Plugins angelegt.');
    } elseif (!is_readable($dev)) {
        $add('Lesekopf', 'fail', $dev . ' ist nicht lesbar (Rechte).');
    } else {
        $ziel = is_link($dev) ? readlink($dev) : '';
        $add('Lesekopf', 'ok', $dev . ($ziel !== '' ? ' -> ' . $ziel : ''));
    }

    // 6 - Belegung der Schnittstelle (der haeufigste Fallstrick)
    $busy = sm_vz_device_busy($dev);
    if (sm_legacy_aktiv()) {
        $add('Schnittstelle belegt', 'fail', 'Die Legacy-Abfrage ist eingeschaltet und '
            . 'greift auf dasselbe Geraet zu. Zwei Leser koennen sich eine serielle '
            . 'Schnittstelle nicht teilen. Entweder Legacy abschalten oder vzLogger.');
    } elseif ($busy !== '') {
        $add('Schnittstelle belegt', 'warn', 'Fremder Zugriff auf ' . $dev . ': ' . $busy);
    } else {
        $add('Schnittstelle belegt', 'ok', 'Niemand sonst greift auf ' . $dev . ' zu.');
    }

    // 7 und 8 - HTTP-Schnittstelle und Messwerte
    $port = (int) $cfg['httpport'];
    list(, $roh) = sm_sh('curl -s -m 5 http://127.0.0.1:' . $port . '/');
    if (strpos($roh, 'vzlogger') === false) {
        $add('HTTP-Schnittstelle', 'fail', 'Port ' . $port . ' antwortet nicht. Entweder '
            . 'laeuft vzlogger nicht, oder der Port ist belegt.');
        $add('Messwerte', 'fail', 'Keine Werte - ohne HTTP-Schnittstelle kann das Plugin '
            . 'sie nicht abholen.');
        return $d;
    }

    $cnt = substr_count($roh, '"uuid"');
    preg_match_all('/"last"\s*:\s*(\d+)/', $roh, $m);
    $letzte = isset($m[1]) ? array_map('floatval', $m[1]) : array();
    $max = $letzte ? max($letzte) : 0;
    $gut = 0;
    foreach ($letzte as $x) { if ($x > 0) { $gut++; } }

    $add('HTTP-Schnittstelle', 'ok', 'Port ' . $port . ' antwortet, ' . $cnt
       . ' Kanaele angemeldet.');

    if (!$cnt) {
        $add('Messwerte', 'fail', 'vzlogger laeuft, kennt aber keine Kanaele. Einmal '
            . 'Speichern und danach den Dienst neu starten.');
    } elseif (!$gut) {
        $zeitfehler = false;
        if (is_readable($p['vzlog'])) {
            list(, $t) = sm_sh('tail -n 60 ' . escapeshellarg($p['vzlog']));
            $zeitfehler = (strpos($t, 'timestamp before 1990') !== false);
        }
        if ($zeitfehler) {
            $add('Messwerte', 'fail', 'Der Zaehler wird gelesen, aber vzlogger verwirft '
                . 'jedes Telegramm: "timestamp before 1990, IGNORING". Dieser Zaehler '
                . 'sendet keine gestellte Uhr. Abhilfe: "Zeitstempel" auf '
                . '"Rechner-Uhrzeit" stellen und speichern'
                . (!$cfg['localtime'] ? ' - die Einstellung steht derzeit auf der Uhr des Zaehlers.' : '.'));
        } else {
            $add('Messwerte', 'fail', 'Alle ' . $cnt . ' Kanaele stehen auf last=0 - es ist '
                . 'noch kein einziges Telegramm angekommen. Pruefe Sitz des Lesekopfs, '
                . 'Baudrate und Protokoll. Das Protokoll unten zeigt, was vzlogger meldet.');
        }
    } else {
        // vzlogger meldet je nach Fassung Sekunden oder Millisekunden.
        $alter = ($max > 1000000000000)
            ? (int) (time() - $max / 1000)
            : (int) (time() - $max);
        $add('Messwerte', ($alter > 300 ? 'warn' : 'ok'),
            $gut . ' von ' . $cnt . ' Kanaelen liefern Werte, letzter Empfang vor '
          . $alter . ' Sekunden.');
    }

    return $d;
}

/** Die Farbe zu einem Diagnosezustand. */
function sm_farbe($zustand)
{
    $f = array('ok' => '#1a7f1a', 'warn' => '#b06000', 'fail' => '#b00000');
    return isset($f[$zustand]) ? $f[$zustand] : '';
}

function sm_zeichen($zustand)
{
    $z = array('ok' => '&#10004;', 'warn' => '!', 'fail' => '&#10008;');
    return isset($z[$zustand]) ? $z[$zustand] : '';
}

function sm_block($text)
{
    return '<div class="sm-log">' . sm_e($text) . '</div>';
}

/** Eine Aktion des Reiters Test. */
function sm_test_ausfuehren($was, $cfg)
{
    $p = sm_paths();
    switch ($was) {

        case 'http':
            $port = (int) $cfg['httpport'];
            list(, $roh) = sm_sh('curl -s -m 5 http://127.0.0.1:' . $port . '/');
            return array('Antwort der HTTP-Schnittstelle',
                sm_block(trim($roh) !== '' ? $roh
                    : 'Keine Antwort auf Port ' . $port . '.'));

        case 'umgebung':
            $z = array();
            $z[] = 'PHP-Fassung       : ' . PHP_VERSION;
            list(, $arch) = sm_sh('uname -m');
            $z[] = 'Architektur       : ' . trim($arch);
            list($bin, $warum) = sm_vz_binary();
            $z[] = 'vzlogger          : ' . ($bin !== '' ? $bin : 'nicht lauffaehig');
            if ($bin === '') { $z[] = '  Grund           : ' . $warum; }
            $z[] = 'Paket installiert : ' . (sm_vz_paket('current') ?: 'unbekannt');
            $z[] = 'Paket verfuegbar  : ' . (sm_vz_paket('available') ?: 'unbekannt');
            $z[] = '';
            $koepfe = sm_lesekoepfe();
            $z[] = 'Lesekoepfe        : ' . ($koepfe ? implode(', ', $koepfe) : 'keiner erkannt');
            $z[] = '';
            foreach (array('vzjson' => 'vzlogger.json', 'vzconf' => 'vzlogger.conf',
                           'legacy' => 'smartmeter.cfg') as $k => $name) {
                $z[] = sprintf('%-18s: %s', $name, is_readable($p[$k])
                    ? 'vorhanden (' . number_format(filesize($p[$k]) / 1024, 1, ',', '.') . ' kB)'
                    : 'nicht vorhanden');
            }
            return array('Umgebung', sm_block(implode("\n", $z)));

        case 'legacy':
            $l = sm_legacy_read();
            $z = array();
            foreach ($l as $k => $v) {
                $z[] = sprintf('%-12s: %s', $k, $v);
            }
            $z[] = '';
            $z[] = $l['READ'] === '1'
                ? 'Der Legacy-Leser ist EINGESCHALTET - er belegt die serielle Schnittstelle.'
                : 'Der Legacy-Leser ist ausgeschaltet.';
            return array('Legacy-Einstellungen', sm_block(implode("\n", $z)));
    }
    return array('Unbekannte Pr&uuml;fung',
        '<p class="sm-small">Diese Pr&uuml;fung gibt es nicht.</p>');
}
