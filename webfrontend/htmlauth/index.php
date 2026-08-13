<?php
/**
 * Smartmeter classic - Bedienoberflaeche
 *
 * Das Plugin kennt zwei Lesewege: vzLogger (modern) und den Legacy-Leser mit
 * Zaehlerprofilen. Beide teilen sich die serielle Schnittstelle, deshalb darf
 * immer nur einer eingeschaltet sein - darauf weist die Diagnose hin.
 */

require_once 'loxberry_web.php';
require_once __DIR__ . '/sm_lib.php';
require_once __DIR__ . '/sm_test.php';
require_once __DIR__ . '/sm_legacy.php';

$sm_p       = sm_paths();
$sm_meldung = '';
$sm_fehler  = array();
$sm_hinweis = '';

/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung.
 *
 * Bis 2.3.2 standen die Reiternamen an zwei Stellen: in diesem Muster und
 * weiter unten im Feld $sm_reiter. Die Flaechen-ids kamen als dritte dazu.
 * Wer einen Reiter ergaenzt und eine davon vergisst, bekommt keinen Fehler,
 * sondern eine Seite, die nach jedem Absenden auf den ersten Reiter
 * zurueckspringt - und sucht den Grund an der falschen Stelle. Die
 * Beschriftungen brauchen sm_t() und kommen deshalb weiter unten dazu,
 * wenn die Sprachdatei geladen ist. */
$sm_reiter_ids = array('vzlogger', 'legacy', 'mqtt', 'loxone', 'test', 'log');

// Der Reiter kommt entweder aus einem abgesendeten Formular (activetab) oder
// als Adresse - die Legacy-Seite verlinkt so hierher.
$sm_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
$sm_tab = preg_match('/^tab-(' . implode('|', $sm_reiter_ids) . ')$/', $sm_wunsch)
    ? $sm_wunsch : 'tab-' . $sm_reiter_ids[0];

$sm_cfg    = sm_vz_read();
$sm_legacy = sm_legacy_read();

$sm_test_titel = '';
$sm_test_text  = '';
$sm_installout = '';

/* ---------------------------------------------------------------- *
 * Formulare
 * ---------------------------------------------------------------- */
if (isset($_POST['vz_speichern'])) {
    $neu = $sm_cfg;
    $neu['enabled'] = isset($_POST['vz_enabled']) ? 1 : 0;
    $neu['device']  = isset($_POST['vz_device']) ? trim((string) $_POST['vz_device']) : '';

    $prot = isset($_POST['vz_protocol']) ? (string) $_POST['vz_protocol'] : 'sml';
    $neu['protocol'] = ($prot === 'd0') ? 'd0' : 'sml';

    $baud = isset($_POST['vz_baudrate']) ? trim((string) $_POST['vz_baudrate']) : '';
    if (!ctype_digit($baud) || (int) $baud < 300 || (int) $baud > 921600) {
        $sm_fehler[] = sm_t('FEHLER.BAUDRATE');
    } else {
        $neu['baudrate'] = (int) $baud;
    }

    $par = isset($_POST['vz_parity']) ? (string) $_POST['vz_parity'] : '';
    if (!in_array($par, array('8n1', '7n1', '7e1', '8e1'), true)) {
        $sm_fehler[] = sm_t('FEHLER.RAHMUNG');
    } else {
        $neu['parity'] = $par;
    }

    $neu['localtime'] = (isset($_POST['vz_localtime']) && $_POST['vz_localtime'] === '0') ? 0 : 1;
    $neu['sendudp']   = isset($_POST['vz_sendudp']) ? 1 : 0;

    foreach (array('udpport' => 'vz_udpport', 'httpport' => 'vz_httpport') as $k => $feld) {
        $w = isset($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
        if (!ctype_digit($w) || (int) $w < 1 || (int) $w > 65535) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.PORT'),
                                   $k === 'udpport' ? 'UDP' : 'HTTP');
        } else {
            $neu[$k] = (int) $w;
        }
    }

    // Zaehlernummer: erscheint im MQTT-Thema und im UDP-Satz. Nach der
    // Hausregel nicht hart filtern, nur Unbrauchbares entfernen.
    $ser = isset($_POST['vz_serial']) ? (string) $_POST['vz_serial'] : '';
    $ser = preg_replace('/[^A-Za-z0-9_\-]/', '', $ser);
    $neu['serial'] = ($ser !== '') ? $ser : 'vzlogger';

    // OBIS-Kanaele: einer je Zeile (oder durch Komma getrennt)
    $kanaele = array();
    foreach (preg_split('/[\r\n,]+/', isset($_POST['vz_channels']) ? (string) $_POST['vz_channels'] : '') as $c) {
        $c = trim($c);
        if ($c === '') { continue; }
        if (!preg_match('/^[\d\.:\-\*]+$/', $c)) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.OBIS'),
                                   '<span class="sm-mono">' . sm_e($c) . '</span>');
            continue;
        }
        $kanaele[] = $c;
    }
    if (!$kanaele) {
        $kanaele = sm_vz_vorgaben()['channels'];
    }
    $neu['channels'] = $kanaele;
    $neu['uuids']    = sm_vz_uuids($kanaele);

    if (!$sm_fehler) {
        if (sm_vz_write($neu)) {
            $sm_cfg = sm_vz_read();
            sm_vz_conf_schreiben($sm_cfg);
            $sm_hinweis = sm_vz_restart($sm_cfg);
            $sm_meldung = ($sm_hinweis === '')
                        ? sm_t('MELD.VZ_GESPEICHERT_NEUSTART')
                        : sm_t('MELD.VZ_GESPEICHERT');
        } else {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_RECHTE'),
                                   '<span class="sm-mono">vzlogger.json</span>');
        }
    }
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_install'])) {
    $sm_installout = sm_vz_install();
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['vz_neustart'])) {
    $sm_hinweis = sm_vz_restart($sm_cfg);
    $sm_meldung = ($sm_hinweis === '') ? sm_t('MELD.VZ_NEUSTART') : '';
    $sm_tab = 'tab-vzlogger';
}

if (isset($_POST['mq_speichern'])) {
    // Hausregel: Eingaben nicht hart filtern. Nur Steuerzeichen,
    // Anfuehrungszeichen und Leerraum entfernen - alles andere ist in einem
    // MQTT-Thema erlaubt.
    $t = isset($_POST['mq_topic']) ? (string) $_POST['mq_topic'] : '';
    $t = preg_replace('/[\x00-\x1f"\x27\s]/', '', $t);
    $t = trim($t, '/');
    if ($t === '') { $t = 'smartmeter'; }
    // Abschnittsbewusst schreiben: seit die Lesekoepfe eigene Abschnitte
    // haben, darf nicht mehr zeilenweise nach dem Schluessel gesucht werden.
    if (sm_cfg_set('MAIN', array('SENDMQTT' => isset($_POST['mq_an']) ? '1' : '0',
                                 'MQTTTOPIC' => $t))) {
        $sm_legacy = sm_legacy_read();
        $sm_meldung = sm_t('MELD.MQTT_GESPEICHERT');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
                               '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-mqtt';
}

if (isset($_POST['test'])) {
    list($sm_test_titel, $sm_test_text) = sm_test_ausfuehren((string) $_POST['test'], $sm_cfg);
    $sm_tab = 'tab-test';
}

/* ---------------------------------------------------------------- *
 * Legacy-Leser
 * ---------------------------------------------------------------- */
$sm_lg_ausgabe = '';

if (isset($_POST['lg_speichern'])) {
    $lesen = isset($_POST['lg_read']);
    $takt  = isset($_POST['lg_cron']) ? (string) $_POST['lg_cron'] : '5';
    if (!array_key_exists($takt, sm_takte())) {
        $sm_fehler[] = sm_t('FEHLER.TAKT');
    }
    $port = isset($_POST['lg_udpport']) ? trim((string) $_POST['lg_udpport']) : '';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $sm_fehler[] = sm_t('FEHLER.LG_UDPPORT');
    }
    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    if ($lesen && $sm_cfg['enabled']) {
        $sm_fehler[] = sm_t('FEHLER.BEIDE_LESER');
    }

    if (!$sm_fehler) {
        $paare = array('READ' => $lesen ? '1' : '0', 'CRON' => $takt,
                       'SENDUDP' => isset($_POST['lg_sendudp']) ? '1' : '0',
                       'UDPPORT' => $port);
        $ok = sm_cfg_set('MAIN', $paare);

        // Je Lesekopf Bezeichnung und Profil
        $profile = sm_profile();
        foreach (sm_koepfe() as $k) {
            $s = $k['ABSCHNITT'];
            $neu = array();
            if (isset($_POST['lg_' . $s . '_name'])) {
                $neu['NAME'] = trim((string) $_POST['lg_' . $s . '_name']);
            }
            if (isset($_POST['lg_' . $s . '_meter'])) {
                $m = (string) $_POST['lg_' . $s . '_meter'];
                if (!array_key_exists($m, $profile)) {
                    $sm_fehler[] = sprintf(sm_t('FEHLER.PROFIL'), sm_e($s));
                } else {
                    $neu['METER'] = $m;
                }
            }
            if ($neu && !sm_cfg_set($s, $neu)) {
                $ok = false;
            }
        }

        if (!$ok) {
            $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN_TEIL'),
                                   '<span class="sm-mono">smartmeter.cfg</span>');
        } elseif (!$sm_fehler) {
            list($cron_ok, $cron_text) = sm_cron_setzen($lesen, $takt);
            if ($cron_ok) {
                $sm_meldung = sm_t('MELD.GESPEICHERT') . ' ' . $cron_text;
            } else {
                $sm_fehler[] = $cron_text;
            }
        }
    }
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lg_abfragen'])) {
    $sm_lg_ausgabe = sm_manuell_abfragen();
    $sm_tab = 'tab-legacy';
}

if (isset($_POST['lox_token_neu'])) {
    if (sm_cfg_set('MAIN', array('TOKEN' => sm_token_erzeugen()))) {
        $sm_meldung = sm_t('LOX.TOKEN_NEU');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
            '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-loxone';
}

if (isset($_POST['lox_token_weg'])) {
    if (sm_cfg_set('MAIN', array('TOKEN' => ''))) {
        $sm_meldung = sm_t('LOX.TOKEN_WEG');
    } else {
        $sm_fehler[] = sprintf(sm_t('FEHLER.SCHREIBEN'),
            '<span class="sm-mono">smartmeter.cfg</span>');
    }
    $sm_tab = 'tab-loxone';
}

if (isset($_POST['lg_cache'])) {
    $n = sm_cache_leeren();
    $sm_meldung = sprintf(sm_t('MELD.CACHE'), $n);
    $sm_tab = 'tab-legacy';
}

// Angesteckte Lesekoepfe eintragen, falls neu
sm_koepfe_anlegen();

$sm_lcfg        = sm_cfg_read();
$sm_lcfg_read   = sm_cfg_get($sm_lcfg, 'MAIN', 'READ', '0');
$sm_lcfg_cron   = sm_cfg_get($sm_lcfg, 'MAIN', 'CRON', '5');
$sm_lcfg_udp    = sm_cfg_get($sm_lcfg, 'MAIN', 'SENDUDP', '0');
$sm_lcfg_udpport = sm_cfg_get($sm_lcfg, 'MAIN', 'UDPPORT', '7000');

$sm_koepfe  = sm_lesekoepfe();
list($sm_bin, $sm_binwarum) = sm_vz_binary();
$sm_pid     = sm_vz_running();
$sm_diag    = sm_diagnose($sm_cfg);
$sm_logtext = sm_logtail();
$sm_host    = sm_hostname();

// Adresse des Endpunkts und Zustand des freiwilligen Tokens.
$sm_token = sm_cfg_get($sm_lcfg, 'MAIN', 'TOKEN', '');
$sm_wirt = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : $sm_host;
$sm_endpunkt = 'http://' . $sm_wirt . '/plugins/' . sm_paths()['plugin'] . '/index.php'
    . ($sm_token !== '' ? '?token=' . $sm_token : '');

$sm_version = '';
if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
    $sm_version = (string) LBSystem::pluginversion();
}

LBWeb::lbheader(sm_t('ALLG.TITEL') . ($sm_version !== '' ? ' V' . $sm_version : ''),
                'https://wiki.loxberry.de/plugins/smartmeter/start', 'help.html');
?>

<style>
.sm-wrap { max-width: 1100px; }
.sm-wrap h3.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-wrap h2 { color: #4f7d17; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px;
  font-size: 1.15em; margin: 22px 0 8px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-mono { font-family: monospace; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; }
.sm-tbl td, .sm-tbl th { border: 1px solid #ddd; padding: 6px 9px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-row { margin: 8px 0; }
.sm-row label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 2px; }
.sm-row input[type=text], .sm-row select, .sm-row textarea {
  width: 100%; max-width: 420px; padding: 7px; box-sizing: border-box; }
.sm-row textarea { font-family: monospace; height: 80px; }
.sm-alert { padding: 10px 12px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; }
.sm-ok   { background: #eaf5e0; border: 1px solid #6dac20; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-info { background: #eef3f7; border: 1px solid #546e7a; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe button, .sm-wrap .sm-btn {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important; box-shadow: none !important;
  opacity: 1 !important; margin: 0 !important; text-decoration: none; display: inline-block; }
.sm-wrap .sm-b-lesen button,   .sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-b-lesen button:hover,   .sm-wrap .sm-b-lesen button:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-b-technik button, .sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-b-technik button:hover, .sm-wrap .sm-b-technik button:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-b-aktion button,  .sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-b-aktion button:hover,  .sm-wrap .sm-b-aktion button:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-step { border-left: 3px solid #6dac20; padding: 2px 0 2px 12px; margin: 14px 0; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-family: monospace;
  white-space: pre-wrap; font-size: 0.86em; }
.sm-diag td:first-child { width: 22%; font-weight: 600; }
.sm-diag td:nth-child(2) { width: 4%; text-align: center; font-weight: 700; }
</style>

<div class="sm-wrap">

<?php if ($sm_fehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo sm_t('ALLG.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($sm_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($sm_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $sm_meldung; ?></div>
<?php } ?>
<?php if ($sm_hinweis !== '') { ?>
<div class="sm-alert sm-warn"><?php echo sm_e($sm_hinweis); ?></div>
<?php } ?>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-ziel="..."> - und weil alle Flaechen bis zum
 * Lauf des JavaScripts auf display:none stehen, war die Seite ohne
 * JavaScript vollstaendig leer. Jetzt setzt der Server die Klasse
 * sm-active mit, das JavaScript spart nur den Seitenaufbau.
 */
$sm_beschriftung = array(
    'vzlogger' => 'TAB.VZ',   'legacy' => 'TAB.LEGACY', 'mqtt' => 'TAB.MQTT',
    'loxone'   => 'TAB.LOXONE', 'test'  => 'TAB.TEST',  'log'  => 'TAB.LOG',
);
$sm_reiter = array();
foreach ($sm_reiter_ids as $sm_i) {
    // Faellt eine Beschriftung aus, steht dort die Kennung - ein Reiter ohne
    // Aufschrift waere schlimmer als einer mit einem haesslichen Namen.
    $sm_reiter['tab-' . $sm_i] = isset($sm_beschriftung[$sm_i])
        ? sm_t($sm_beschriftung[$sm_i]) : $sm_i;
}
?>
<div class="sm-tabs">
<?php foreach ($sm_reiter as $sm_id => $sm_bez) { ?>
  <a class="sm-tab<?php echo $sm_tab === $sm_id ? ' sm-active' : ''; ?>"
     data-ziel="<?php echo sm_e($sm_id); ?>"
     href="index.php?tab=<?php echo sm_e(substr($sm_id, 4)); ?>"><?php echo $sm_bez; ?></a>
<?php } ?>
</div>

<!-- ============================== vzLogger ============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-vzlogger' ? ' sm-active' : ''; ?>" id="tab-vzlogger">

<?php if ($sm_installout !== '') { ?>
<h2><?php echo sm_t('VZ.H_INSTALLAUSGABE'); ?></h2>
<div class="sm-log"><?php echo sm_e($sm_installout); ?></div>
<?php } ?>

<h2><?php echo sm_t('VZ.H_ZUSTAND'); ?></h2>
<table class="sm-tbl sm-diag">
<?php foreach ($sm_diag as $z) { ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo sm_farbe($z[1]); ?>"><?php echo sm_zeichen($z[1]); ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>

<?php if ($sm_bin === '') { ?>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <button data-role="none" type="submit" name="vz_install" value="1"><?php echo sm_t('VZ.K_INSTALL'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_INSTALL'); ?></span>
</div>
<?php } else { ?>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">
    <button data-role="none" type="submit" name="vz_neustart" value="1"><?php echo sm_t('VZ.K_NEUSTART'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_NEUSTART'); ?></span>
</div>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-vzlogger">

<h2><?php echo sm_t('VZ.H_LESEWEG'); ?></h2>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_enabled" value="1"<?php
    echo $sm_cfg['enabled'] ? ' checked' : ''; ?>> <?php echo sm_t('VZ.LABEL_ENABLED'); ?></label>
  <p class="sm-small"><?php echo sm_t('VZ.HINT_EINLESER'); ?></p>
</div>

<h2><?php echo sm_t('VZ.H_LESEKOPF'); ?></h2>
<?php if (!$sm_koepfe) { ?>
<div class="sm-alert sm-warn"><?php printf(sm_t('VZ.WARN_KEINKOPF'),
  '<span class="sm-mono">/dev/serial/smartmeter/*</span>'); ?></div>
<?php } ?>
<div class="sm-row">
  <label for="vz_device"><?php echo sm_t('ALLG.GERAET'); ?></label>
  <select data-role="none" id="vz_device" name="vz_device">
    <option value="">&ndash; <?php echo sm_t('ALLG.KEINES'); ?> &ndash;</option>
<?php
$gefunden = false;
foreach ($sm_koepfe as $d) {
    if ($d === $sm_cfg['device']) { $gefunden = true; }
    echo '<option value="' . sm_e($d) . '"'
       . ($d === $sm_cfg['device'] ? ' selected' : '') . '>' . sm_e($d) . '</option>';
}
// Ein gespeichertes, gerade nicht angestecktes Geraet nicht stillschweigend
// verlieren.
if (!$gefunden && $sm_cfg['device'] !== '') {
    echo '<option value="' . sm_e($sm_cfg['device']) . '" selected>'
       . sm_e($sm_cfg['device']) . ' (' . sm_t('VZ.NICHT_VORHANDEN') . ')</option>';
}
?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_protocol"><?php echo sm_t('VZ.LABEL_PROTOCOL'); ?></label>
  <select data-role="none" id="vz_protocol" name="vz_protocol">
    <option value="sml"<?php echo $sm_cfg['protocol'] === 'sml' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_SML'); ?></option>
    <option value="d0"<?php echo $sm_cfg['protocol'] === 'd0' ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_D0'); ?></option>
  </select>
</div>
<div class="sm-row">
  <label for="vz_baudrate"><?php echo sm_t('VZ.LABEL_BAUDRATE'); ?></label>
  <input data-role="none" type="text" id="vz_baudrate" name="vz_baudrate"
         value="<?php echo sm_e($sm_cfg['baudrate']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_BAUDRATE'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_parity"><?php echo sm_t('VZ.LABEL_PARITY'); ?></label>
  <select data-role="none" id="vz_parity" name="vz_parity">
<?php foreach (array('8n1', '7n1', '7e1', '8e1') as $par) { ?>
    <option value="<?php echo $par; ?>"<?php
      echo $sm_cfg['parity'] === $par ? ' selected' : ''; ?>><?php echo $par; ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_localtime"><?php echo sm_t('VZ.LABEL_LOCALTIME'); ?></label>
  <select data-role="none" id="vz_localtime" name="vz_localtime">
    <option value="1"<?php echo $sm_cfg['localtime'] ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_LOKALZEIT'); ?></option>
    <option value="0"<?php echo !$sm_cfg['localtime'] ? ' selected' : ''; ?>><?php echo sm_t('VZ.OPT_ZAEHLERZEIT'); ?></option>
  </select>
  <p class="sm-small"><?php printf(sm_t('VZ.HINT_LOCALTIME'),
    '<span class="sm-mono">timestamp before 1990, IGNORING</span>'); ?></p>
</div>

<h2><?php echo sm_t('VZ.H_KANAELE'); ?></h2>
<div class="sm-row">
  <label for="vz_channels"><?php echo sm_t('VZ.LABEL_CHANNELS'); ?></label>
  <textarea data-role="none" id="vz_channels" name="vz_channels"><?php
    echo sm_e(implode("\n", $sm_cfg['channels'])); ?></textarea>
  <p class="sm-small"><?php echo sm_t('ALLG.VORGABE'); ?>:
  <span class="sm-mono">1-0:1.8.0</span> (<?php echo sm_t('OBIS.BEZUG'); ?>),
  <span class="sm-mono">1-0:2.8.0</span> (<?php echo sm_t('OBIS.EINSPEISUNG'); ?>),
  <span class="sm-mono">1-0:16.7.0</span> (<?php echo sm_t('OBIS.LEISTUNG'); ?>).</p>
</div>

<h2><?php echo sm_t('VZ.H_WEITERGABE'); ?></h2>
<div class="sm-row">
  <label for="vz_serial"><?php echo sm_t('VZ.LABEL_SERIAL'); ?></label>
  <input data-role="none" type="text" id="vz_serial" name="vz_serial"
         value="<?php echo sm_e($sm_cfg['serial']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_SERIAL'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="vz_sendudp" value="1"<?php
    echo $sm_cfg['sendudp'] ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
  <p class="sm-small"><?php echo sm_t('VZ.HINT_UDP'); ?></p>
</div>
<div class="sm-row">
  <label for="vz_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text" id="vz_udpport" name="vz_udpport"
         value="<?php echo sm_e($sm_cfg['udpport']); ?>">
</div>
<div class="sm-row">
  <label for="vz_httpport"><?php echo sm_t('VZ.LABEL_HTTPPORT'); ?></label>
  <input data-role="none" type="text" id="vz_httpport" name="vz_httpport"
         value="<?php echo sm_e($sm_cfg['httpport']); ?>">
  <p class="sm-small"><?php echo sm_t('VZ.HINT_HTTPPORT'); ?></p>
</div>

<div class="sm-knopfreihe sm-b-aktion">
  <button data-role="none" type="submit" name="vz_speichern" value="1"><?php echo sm_t('VZ.K_SPEICHERN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.VZ_SPEICHERN'); ?></span>
</div>
</form>
</div>

<!-- =============================== Legacy =============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-legacy' ? ' sm-active' : ''; ?>" id="tab-legacy">

<h2><?php echo sm_t('LG.H_LESER'); ?></h2>
<p class="sm-small"><?php echo sm_t('LG.HINT_LESER'); ?></p>

<div class="sm-alert sm-info"><b><?php echo sm_t('LG.WARN_EINLESER'); ?></b>
<?php echo $sm_cfg['enabled'] ? ' ' . sm_t('LG.VZ_AN') : ' ' . sm_t('LG.VZ_AUS'); ?></div>

<?php $sm_lpid = sm_logger_pid(); ?>
<p><span class="sm-scheibe <?php echo $sm_lpid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo $sm_lpid !== null
    ? sprintf(sm_t('LG.LAEUFT'), sm_e($sm_lpid))
    : sm_t('LG.LAEUFT_NICHT'); ?>
<span class="sm-small"><?php echo sm_t('LG.TAKT_LAUT_LINK'); ?>:
<?php $sm_ist = sm_cron_ist();
      $sm_takte = sm_takte();
      echo $sm_ist !== '' ? $sm_takte[$sm_ist][1] : sm_t('LG.KEIN_TAKT'); ?></span></p>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-legacy">

<h2><?php echo sm_t('LG.H_ABFRAGE'); ?></h2>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="lg_read" value="1"<?php
    echo $sm_lcfg_read === '1' ? ' checked' : ''; ?>> <?php echo sm_t('LG.LABEL_ENABLED'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_cron"><?php echo sm_t('LG.LABEL_TAKT'); ?></label>
  <select data-role="none" id="lg_cron" name="lg_cron">
<?php foreach (sm_takte() as $wert => $t) { ?>
    <option value="<?php echo $wert; ?>"<?php
      echo $sm_lcfg_cron === $wert ? ' selected' : ''; ?>><?php echo $t[1]; ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><?php echo sm_t('LG.HINT_TAKT'); ?></p>
</div>
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="lg_sendudp" value="1"<?php
    echo $sm_lcfg_udp === '1' ? ' checked' : ''; ?>> <?php echo sm_t('ALLG.UDP_ZUSAETZLICH'); ?></label>
</div>
<div class="sm-row">
  <label for="lg_udpport"><?php echo sm_t('ALLG.UDPPORT'); ?></label>
  <input data-role="none" type="text" id="lg_udpport" name="lg_udpport"
         value="<?php echo sm_e($sm_lcfg_udpport); ?>">
  <p class="sm-small"><?php echo sm_t('LG.HINT_MQTT'); ?></p>
</div>

<h2><?php echo sm_t('LG.H_KOEPFE'); ?></h2>
<?php $sm_koepfe_liste = sm_koepfe(); ?>
<?php if (!$sm_koepfe_liste) { ?>
<div class="sm-alert sm-warn"><?php echo sm_t('LG.WARN_KEINKOPF'); ?></div>
<?php } else { foreach ($sm_koepfe_liste as $sm_k) {
    $sm_s = $sm_k['ABSCHNITT']; ?>
<h3 class="sm-h3"><?php echo sm_e($sm_s); ?>
<?php if (!$sm_k['ANGESTECKT']) { ?>
  <span class="sm-small">&ndash; <?php echo sm_t('LG.NICHT_ANGESTECKT'); ?></span>
<?php } ?></h3>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_name"><?php echo sm_t('LG.LABEL_NAME'); ?></label>
  <input data-role="none" type="text" id="<?php echo sm_e($sm_s); ?>_name"
         name="lg_<?php echo sm_e($sm_s); ?>_name"
         value="<?php echo sm_e(isset($sm_k['NAME']) ? $sm_k['NAME'] : $sm_s); ?>">
</div>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_meter"><?php echo sm_t('LG.LABEL_PROFIL'); ?></label>
  <select data-role="none" id="<?php echo sm_e($sm_s); ?>_meter" name="lg_<?php echo sm_e($sm_s); ?>_meter">
<?php $sm_akt = isset($sm_k['METER']) ? $sm_k['METER'] : '0';
      foreach (sm_profile() as $sm_pk => $sm_pn) { ?>
    <option value="<?php echo sm_e($sm_pk); ?>"<?php
      echo $sm_akt === $sm_pk ? ' selected' : ''; ?>><?php echo $sm_pn; ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><?php echo sm_t('ALLG.GERAET'); ?>: <span class="sm-mono"><?php
    echo sm_e($sm_k['DEVICE']); ?></span></p>
</div>
<?php $sm_w = sm_werte($sm_s); if ($sm_w) { ?>
<p class="sm-small"><?php echo sm_t('LG.ZULETZT'); ?>:</p>
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo sm_t('ALLG.GROESSE'); ?></th><th><?php echo sm_t('ALLG.WERT'); ?></th></tr>
<?php foreach ($sm_w as $sm_pv) { ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_pv[0]); ?></td>
    <td class="sm-mono"><?php echo sm_e($sm_pv[1]); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php } } ?>

<div class="sm-knopfreihe sm-b-aktion">
  <button data-role="none" type="submit" name="lg_speichern" value="1"><?php echo sm_t('LG.K_SPEICHERN'); ?></button>
</div>
</form>

<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-legacy">
    <button data-role="none" type="submit" name="lg_abfragen" value="1"><?php echo sm_t('LG.K_ABFRAGEN'); ?></button>
  </form>
</div>
<div class="sm-knopfreihe sm-b-technik">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-legacy">
    <button data-role="none" type="submit" name="lg_cache" value="1"><?php echo sm_t('LG.K_CACHE'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo sm_t('LEGENDE.LG_ABFRAGEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo sm_t('LEGENDE.LG_CACHE'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.LG_SPEICHERN'); ?></span>
</div>

<?php if ($sm_lg_ausgabe !== '') { ?>
<h2><?php echo sm_t('ALLG.AUSGABE'); ?></h2>
<div class="sm-log"><?php echo sm_e($sm_lg_ausgabe); ?></div>
<?php } ?>

<h2><?php echo sm_t('LG.H_WIE'); ?></h2>
<p class="sm-small"><?php printf(sm_t('LG.TEXT_WIE'),
  '<span class="sm-mono">bin/fetch.php</span>',
  '<span class="sm-mono">bin/sm_logger.pl</span>',
  '<span class="sm-mono">Device::SerialPort</span>'); ?></p>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<?php if (!function_exists('sm_hs_autostart')) { function sm_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (sm_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo sm_t('MQ.W_AUTOSTART'); ?></div><?php } ?>

<h2><?php echo sm_t('MQ.H_ZUSTAND'); ?></h2>
<p class="sm-small"><?php echo sm_t('MQ.HINT_GATEWAY'); ?></p>

<h2><?php echo sm_t('MQ.H_EINSTELLUNGEN'); ?></h2>
<p class="sm-small"><?php echo sm_t('MQ.HINT_EINZIGE'); ?></p>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row">
  <label><input data-role="none" type="checkbox" name="mq_an" value="1"<?php
    echo $sm_legacy['SENDMQTT'] === '1' ? ' checked' : ''; ?>> <?php echo sm_t('MQ.LABEL_AN'); ?></label>
</div>
<div class="sm-row">
  <label for="mq_topic"><?php echo sm_t('MQ.LABEL_TOPIC'); ?></label>
  <input data-role="none" type="text" id="mq_topic" name="mq_topic"
         value="<?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>">
</div>
<div class="sm-knopfreihe sm-b-aktion">
  <button data-role="none" type="submit" name="mq_speichern" value="1"><?php echo sm_t('ALLG.SPEICHERN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo sm_t('LEGENDE.MQ_SPEICHERN'); ?></span>
</div>
</form>

<h2><?php echo sm_t('MQ.H_ABO'); ?></h2>
<p class="sm-small"><b><?php echo sm_t('MQ.OHNE_ABO'); ?></b>
<?php echo sm_t('MQ.ABO_WO'); ?>:</p>
<pre class="sm-pre"><?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>/#</pre>

<h2><?php echo sm_t('MQ.H_THEMEN'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:52%"><?php echo sm_t('MQ.SP_THEMA'); ?></th><th><?php echo sm_t('ALLG.BEDEUTUNG'); ?></th></tr>
<?php foreach ($sm_cfg['channels'] as $c) { ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_legacy['MQTTTOPIC'] . '/'
      . $sm_cfg['serial'] . '/' . $c); ?></td>
    <td>OBIS <?php echo sm_e($c); ?><?php
      if ($c === '1-0:1.8.0') { echo ' &ndash; ' . sm_t('OBIS.STAND_BEZUG'); }
      elseif ($c === '1-0:2.8.0') { echo ' &ndash; ' . sm_t('OBIS.STAND_EINSPEISUNG'); }
      elseif ($c === '1-0:16.7.0') { echo ' &ndash; ' . sm_t('OBIS.WIRKLEISTUNG'); }
    ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo sm_t('LOX.H_TITEL'); ?></h2>

<div class="sm-step">
<b><?php echo sm_t('LOX.S1_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S1_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S2_TITEL'); ?></b><br><br>
<b><?php echo sm_t('MQ.OHNE_ABO'); ?></b> <?php echo sm_t('MQ.ABO_WO'); ?>:
<pre class="sm-pre"><?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>/#</pre>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S3_TITEL'); ?></b><br><br>
<table class="sm-tbl">
<tr><th><?php echo sm_t('LOX.SP_TITEL_VE'); ?></th><th style="width:16%"><?php echo sm_t('ALLG.EINHEIT'); ?></th><th style="width:30%"><?php echo sm_t('ALLG.BEDEUTUNG'); ?></th></tr>
<?php foreach ($sm_cfg['channels'] as $c) {
    $titel = str_replace(array('/', ':', '-', '.'), '_',
        $sm_legacy['MQTTTOPIC'] . '_' . $sm_cfg['serial'] . '_' . $c);
    $einheit = ($c === '1-0:16.7.0') ? '&lt;v.0&gt;&nbsp;W' : '&lt;v.3&gt;&nbsp;kWh';
    $bed = ($c === '1-0:1.8.0') ? sm_t('OBIS.STAND_BEZUG')
         : (($c === '1-0:2.8.0') ? sm_t('OBIS.STAND_EINSPEISUNG')
         : (($c === '1-0:16.7.0') ? sm_t('OBIS.WIRKLEISTUNG') : 'OBIS ' . sm_e($c))); ?>
<tr><td class="sm-mono"><?php echo sm_e($titel); ?></td>
    <td><?php echo $einheit; ?></td><td><?php echo $bed; ?></td></tr>
<?php } ?>
</table>
<p class="sm-small"><?php echo sm_t('LOX.S3_HINT'); ?></p>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S4_TITEL'); ?></b><br><br>
<?php if ($sm_cfg['sendudp']) { ?>
<?php printf(sm_t('LOX.S4_AN'), '<b>' . sm_e($sm_cfg['udpport']) . '</b>'); ?>
<pre class="sm-pre">\i<?php echo sm_e($sm_cfg['serial']); ?>/1-0:1.8.0,\i\v</pre>
<?php } else { ?>
<?php echo sm_t('LOX.S4_AUS'); ?>
<?php } ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S5_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S5_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S6_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S6_TEXT'); ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo sm_t('LOX.SP_BAUSTEIN'); ?></th><th><?php echo sm_t('LOX.SP_NAME'); ?></th><th><?php echo sm_t('LOX.SP_PARAMETER'); ?></th><th><?php echo sm_t('LOX.SP_EINGAENGE'); ?></th></tr>
<tr><td>1</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td>Strom_Bezug</td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td>MQTT <span class="sm-mono">1-0:1.8.0</span></td></tr>
<tr><td>2</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td>Strom_Einspeisung</td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td>MQTT <span class="sm-mono">1-0:2.8.0</span></td></tr>
<tr><td>3</td><td><?php echo sm_t('BAUSTEIN.VE'); ?></td><td>Strom_Leistung</td><td><?php echo sm_t('ALLG.EINHEIT'); ?> <span class="sm-mono">&lt;v.0&gt; W</span></td><td>MQTT <span class="sm-mono">1-0:16.7.0</span></td></tr>
<tr><td>4</td><td><?php echo sm_t('BAUSTEIN.ZAEHLER'); ?></td><td>Verbrauch_Tag</td><td><?php echo sm_t('LOX.P_MITTERNACHT'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #1</td></tr>
<tr><td>5</td><td><?php echo sm_t('BAUSTEIN.STATISTIK'); ?></td><td>Strom_Verlauf</td><td><?php echo sm_t('LOX.P_ANALOG'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #3</td></tr>
<tr><td>6</td><td><?php echo sm_t('BAUSTEIN.VERGLEICHER'); ?></td><td>Einspeisung_aktiv</td><td><?php echo sm_t('LOX.P_SCHWELLE0'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #3</td></tr>
<tr><td>7</td><td><?php echo sm_t('BAUSTEIN.ANALOGSPEICHER'); ?></td><td>Leistung_Vorwert</td><td>&mdash;</td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #3</td></tr>
<tr><td>8</td><td><?php echo sm_t('BAUSTEIN.FORMEL'); ?></td><td>Leistung_Aenderung</td><td><span class="sm-mono">ABS(I1-I2)</span></td><td>I1 = #3, I2 = #7</td></tr>
<tr><td>9</td><td><?php echo sm_t('BAUSTEIN.EVZ'); ?></td><td>Zaehler_schweigt</td><td><?php echo sm_t('LOX.P_VERZOEGERUNG'); ?> <b>900</b> s</td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #8 = 0</td></tr>
<tr><td>10</td><td><?php echo sm_t('BAUSTEIN.ODER'); ?></td><td>Strom_Meldungen</td><td>&mdash;</td><td><?php echo sm_t('LOX.EINGAENGE'); ?> &larr; #9 &hellip;</td></tr>
<tr><td>11</td><td><?php echo sm_t('BAUSTEIN.BENACHRICHTIGUNG'); ?></td><td><?php echo sm_t('LOX.N_ZAEHLER_PRUEFEN'); ?></td><td><?php echo sm_t('LOX.P_TEXT_FREI'); ?></td><td><?php echo sm_t('LOX.EINGANG'); ?> &larr; #10</td></tr>
<tr><td>12 <i>(<?php echo sm_t('ALLG.OPTIONAL'); ?>)</i></td><td><?php echo sm_t('BAUSTEIN.STATUS'); ?></td><td>Strom_aktuell</td><td><?php echo sm_t('LOX.P_STATUSTEXT'); ?></td><td>v1 = #3, v2 = #1</td></tr>
</table>
<br>
<b><?php echo sm_t('LOX.S6_STATUSTEXT'); ?></b>
<pre class="sm-pre">&lt;v1.0&gt; W &middot; <?php echo sm_t('OBIS.ZAEHLERSTAND'); ?> &lt;v2.3&gt; kWh</pre>
<b><?php echo sm_t('LOX.S6_ZU9'); ?></b> <?php echo sm_t('LOX.S6_ZU9_TEXT'); ?><br>
<b><?php echo sm_t('LOX.S6_ZU1011'); ?></b> <?php echo sm_t('LOX.S6_ZU1011_TEXT'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S7_TITEL'); ?></b><br><br>
<?php printf(sm_t('LOX.S7_TEXT'), '<span class="sm-mono">"last"</span>'); ?>
</div>

<div class="sm-step">
<b><?php echo sm_t('LOX.S8_TITEL'); ?></b><br><br>
<?php echo sm_t('LOX.S8_TEXT'); ?>
<pre class="sm-pre"><?php echo sm_e($sm_endpunkt); ?></pre>
<?php if ($sm_token === '') { ?>
<div class="sm-alert sm-warn"><?php echo sm_t('LOX.TOKEN_OFFEN'); ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<button data-role="none" class="sm-btn" type="submit" name="lox_token_neu" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_SETZEN')); ?></button>
</form>
<?php } else { ?>
<div class="sm-alert sm-ok"><?php echo sm_t('LOX.TOKEN_AKTIV'); ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<button data-role="none" class="sm-btn" type="submit" name="lox_token_neu" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_ERNEUERN')); ?></button>
<button data-role="none" class="sm-btn" type="submit" name="lox_token_weg" value="1"><?php echo sm_e(sm_t('LOX.TOKEN_ENTFERNEN')); ?></button>
</form>
<?php } ?>
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane<?php echo $sm_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<?php if ($sm_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $sm_test_titel; ?></b></div>
<?php echo $sm_test_text; ?>
<?php } ?>

<h2><?php echo sm_t('TEST.H_DIAGNOSE'); ?></h2>
<table class="sm-tbl sm-diag">
<?php foreach ($sm_diag as $z) { ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo sm_farbe($z[1]); ?>"><?php echo sm_zeichen($z[1]); ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>

<h2><?php echo sm_t('TEST.H_NACHSEHEN'); ?></h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php foreach (array('umgebung' => sm_t('TEST.K_UMGEBUNG'),
                     'http'     => sm_t('TEST.K_HTTP'),
                     'legacy'   => sm_t('TEST.K_LEGACY')) as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="<?php echo sm_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo sm_t('LEGENDE.LESEN'); ?></span>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo $sm_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo sm_t('LOG.H_PROTOKOLLE'); ?></h2>
<p class="sm-small"><?php printf(sm_t('LOG.HINT'),
  '<span class="sm-mono">vzlogger.log</span>',
  '<span class="sm-mono">vzlogger_fetch.log</span>'); ?></p>
<div class="sm-log"><?php echo sm_e($sm_logtext); ?></div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo '<h2>' . sm_t('LOG.H_LOXBERRY') . '</h2>';
    echo LBWeb::loglist_html();
}
?>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab[data-ziel]');
    var seiten = document.querySelectorAll('.sm-pane');
    function zeige(ziel) {
        for (var i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active',
                reiter[i].getAttribute('data-ziel') === ziel);
        }
        for (var j = 0; j < seiten.length; j++) {
            seiten[j].classList.toggle('sm-active', seiten[j].id === ziel);
        }
    }
    for (var k = 0; k < reiter.length; k++) {
        reiter[k].addEventListener('click', function () {
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($sm_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
