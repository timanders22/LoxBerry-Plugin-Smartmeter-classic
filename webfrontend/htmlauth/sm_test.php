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
        $add(sm_t('DIAG.BETRIEBSART'), 'warn', sm_t('DIAG.VZ_AUS'));
        return $d;
    }
    $add(sm_t('DIAG.BETRIEBSART'), 'ok', sm_t('DIAG.VZ_AN'));

    // 2 - Programm
    list($bin, $warum) = sm_vz_binary();
    $akt = sm_vz_paket('current');
    $verf = sm_vz_paket('available');
    if ($bin !== '') {
        list(, $v) = sm_sh(escapeshellarg($bin) . ' --version | head -1');
        $v = trim($v);
        $txt = $bin . ($v !== '' ? ' - ' . $v : '');
        if ($akt !== '') {
            $txt .= ' (' . sprintf(sm_t('DIAG.PAKET'), $akt)
                  . (($verf !== '' && $verf !== $akt)
                     ? ', ' . sprintf(sm_t('DIAG.PAKET_VERFUEGBAR'), $verf) : '') . ')';
        }
        $add(sm_t('DIAG.PROGRAMM'), ($verf !== '' && $akt !== '' && $verf !== $akt) ? 'warn' : 'ok', $txt);
    } else {
        $add(sm_t('DIAG.PROGRAMM'), 'fail', $warum);
    }

    // 3 - Prozess
    $pid = sm_vz_running();
    if ($pid !== '') {
        $add(sm_t('DIAG.PROZESS'), 'ok', sprintf(sm_t('DIAG.LAEUFT_PID'), $pid));
    } else {
        $add(sm_t('DIAG.PROZESS'), 'fail', sprintf(sm_t('DIAG.KEIN_PROZESS'), $p['vzconf']));
    }

    // 4 - Konfigurationsdatei
    if (!is_readable($p['vzconf'])) {
        $add(sm_t('DIAG.KONFIG'), 'fail', sprintf(sm_t('DIAG.KONFIG_FEHLT'), $p['vzconf']));
    } else {
        $txt = (string) @file_get_contents($p['vzconf']);
        $n = substr_count($txt, '"identifier"');
        if (strpos($txt, '"meters"') === false) {
            $add(sm_t('DIAG.KONFIG'), 'fail', sprintf(sm_t('DIAG.KONFIG_OHNE_METERS'), $p['vzconf']));
        } elseif (!$n) {
            $add(sm_t('DIAG.KONFIG'), 'fail', sm_t('DIAG.KONFIG_OHNE_KANAL'));
        } elseif (preg_match('/"interval"\s*:\s*-/', $txt)) {
            $add(sm_t('DIAG.KONFIG'), 'warn', sprintf(sm_t('DIAG.KONFIG_INTERVAL'), $n));
        } else {
            $add(sm_t('DIAG.KONFIG'), 'ok', sprintf(sm_t('DIAG.KONFIG_OK'), $n));
        }
    }

    // 5 - Lesekopf
    $dev = (string) $cfg['device'];
    if ($dev === '') {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sm_t('DIAG.KEIN_GERAET'));
    } elseif (!file_exists($dev)) {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sprintf(sm_t('DIAG.GERAET_FEHLT'), $dev));
    } elseif (!is_readable($dev)) {
        $add(sm_t('DIAG.LESEKOPF'), 'fail', sprintf(sm_t('DIAG.GERAET_RECHTE'), $dev));
    } else {
        $ziel = is_link($dev) ? readlink($dev) : '';
        $add(sm_t('DIAG.LESEKOPF'), 'ok', $dev . ($ziel !== '' ? ' -> ' . $ziel : ''));
    }

    // 6 - Belegung der Schnittstelle (der haeufigste Fallstrick)
    $busy = sm_vz_device_busy($dev);
    if (sm_legacy_aktiv()) {
        $add(sm_t('DIAG.BELEGT'), 'fail', sm_t('DIAG.BELEGT_LEGACY'));
    } elseif ($busy !== '') {
        $add(sm_t('DIAG.BELEGT'), 'warn', sprintf(sm_t('DIAG.BELEGT_FREMD'), $dev, $busy));
    } else {
        $add(sm_t('DIAG.BELEGT'), 'ok', sprintf(sm_t('DIAG.BELEGT_FREI'), $dev));
    }

    // 7 und 8 - HTTP-Schnittstelle und Messwerte
    $port = (int) $cfg['httpport'];
    list(, $roh) = sm_sh('curl -s -m 5 http://127.0.0.1:' . $port . '/');
    if (strpos($roh, 'vzlogger') === false) {
        $add(sm_t('DIAG.HTTP'), 'fail', sprintf(sm_t('DIAG.HTTP_STUMM'), $port));
        $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.KEINE_WERTE'));
        return $d;
    }

    $cnt = substr_count($roh, '"uuid"');
    preg_match_all('/"last"\s*:\s*(\d+)/', $roh, $m);
    $letzte = isset($m[1]) ? array_map('floatval', $m[1]) : array();
    $max = $letzte ? max($letzte) : 0;
    $gut = 0;
    foreach ($letzte as $x) { if ($x > 0) { $gut++; } }

    $add(sm_t('DIAG.HTTP'), 'ok', sprintf(sm_t('DIAG.HTTP_OK'), $port, $cnt));

    if (!$cnt) {
        $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.KEINE_KANAELE'));
    } elseif (!$gut) {
        $zeitfehler = false;
        if (is_readable($p['vzlog'])) {
            list(, $t) = sm_sh('tail -n 60 ' . escapeshellarg($p['vzlog']));
            $zeitfehler = (strpos($t, 'timestamp before 1990') !== false);
        }
        if ($zeitfehler) {
            $add(sm_t('DIAG.MESSWERTE'), 'fail', sm_t('DIAG.ZEITSTEMPEL')
                . (!$cfg['localtime'] ? ' ' . sm_t('DIAG.ZEITSTEMPEL_ZUSATZ') : ''));
        } else {
            $add(sm_t('DIAG.MESSWERTE'), 'fail', sprintf(sm_t('DIAG.ALLE_NULL'), $cnt));
        }
    } else {
        // vzlogger meldet je nach Fassung Sekunden oder Millisekunden.
        $alter = ($max > 1000000000000)
            ? (int) (time() - $max / 1000)
            : (int) (time() - $max);
        $add(sm_t('DIAG.MESSWERTE'), ($alter > 300 ? 'warn' : 'ok'),
            sprintf(sm_t('DIAG.WERTE_OK'), $gut, $cnt, $alter));
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
            return array(sm_t('TEST.K_HTTP'),
                sm_block(trim($roh) !== '' ? $roh
                    : sprintf(sm_t('TEST.HTTP_STUMM'), $port)));

        case 'umgebung':
            $z = array();
            $unbekannt = sm_t('TEST.UNBEKANNT');
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PHP'), PHP_VERSION);
            list(, $arch) = sm_sh('uname -m');
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_ARCH'), trim($arch));
            list($bin, $warum) = sm_vz_binary();
            $z[] = sprintf('%-18s: %s', 'vzlogger',
                           $bin !== '' ? $bin : sm_t('TEST.U_NICHT_LAUFFAEHIG'));
            if ($bin === '') { $z[] = sprintf('  %-16s: %s', sm_t('TEST.U_GRUND'), $warum); }
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PAKET_INST'), sm_vz_paket('current') ?: $unbekannt);
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_PAKET_VERF'), sm_vz_paket('available') ?: $unbekannt);
            $z[] = '';
            $koepfe = sm_lesekoepfe();
            $z[] = sprintf('%-18s: %s', sm_t('TEST.U_KOEPFE'),
                           $koepfe ? implode(', ', $koepfe) : sm_t('TEST.U_KEINER'));
            $z[] = '';
            foreach (array('vzjson' => 'vzlogger.json', 'vzconf' => 'vzlogger.conf',
                           'legacy' => 'smartmeter.cfg') as $k => $name) {
                $z[] = sprintf('%-18s: %s', $name, is_readable($p[$k])
                    ? sprintf(sm_t('TEST.U_VORHANDEN'),
                              number_format(filesize($p[$k]) / 1024, 1, ',', '.'))
                    : sm_t('TEST.U_NICHT_VORHANDEN'));
            }
            return array(sm_t('TEST.K_UMGEBUNG'), sm_block(implode("\n", $z)));

        case 'legacy':
            $l = sm_legacy_read();
            $z = array();
            foreach ($l as $k => $v) {
                $z[] = sprintf('%-12s: %s', $k, $v);
            }
            $z[] = '';
            $z[] = $l['READ'] === '1' ? sm_t('TEST.LG_AN') : sm_t('TEST.LG_AUS');
            return array(sm_t('TEST.K_LEGACY'), sm_block(implode("\n", $z)));
    }
    return array(sm_t('TEST.UNBEKANNTE_PRUEFUNG'),
        '<p class="sm-small">' . sm_t('TEST.GIBT_ES_NICHT') . '</p>');
}
