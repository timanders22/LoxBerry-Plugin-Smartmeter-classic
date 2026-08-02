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

// Der Reiter kommt entweder aus einem abgesendeten Formular (activetab) oder
// als Adresse - die Legacy-Seite verlinkt so hierher.
$sm_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
$sm_tab = preg_match('/^tab-(vzlogger|legacy|mqtt|loxone|test|log)$/', $sm_wunsch)
    ? $sm_wunsch : 'tab-vzlogger';

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
        $sm_fehler[] = 'Die Baudrate ist eine Zahl zwischen 300 und 921600.';
    } else {
        $neu['baudrate'] = (int) $baud;
    }

    $par = isset($_POST['vz_parity']) ? (string) $_POST['vz_parity'] : '';
    if (!in_array($par, array('8n1', '7n1', '7e1', '8e1'), true)) {
        $sm_fehler[] = 'Die Zeichenrahmung muss 8n1, 7n1, 7e1 oder 8e1 sein.';
    } else {
        $neu['parity'] = $par;
    }

    $neu['localtime'] = (isset($_POST['vz_localtime']) && $_POST['vz_localtime'] === '0') ? 0 : 1;
    $neu['sendudp']   = isset($_POST['vz_sendudp']) ? 1 : 0;

    foreach (array('udpport' => 'vz_udpport', 'httpport' => 'vz_httpport') as $k => $feld) {
        $w = isset($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
        if (!ctype_digit($w) || (int) $w < 1 || (int) $w > 65535) {
            $sm_fehler[] = 'Der ' . ($k === 'udpport' ? 'UDP-Port' : 'HTTP-Port')
                         . ' ist eine Zahl zwischen 1 und 65535.';
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
            $sm_fehler[] = 'Der Kanal <span class="sm-mono">' . sm_e($c)
                         . '</span> sieht nicht wie eine OBIS-Kennzahl aus.';
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
            $sm_meldung = 'Gespeichert. Die Konfiguration wurde neu erzeugt'
                        . ($sm_hinweis === '' ? ' und vzlogger neu gestartet.' : '.');
        } else {
            $sm_fehler[] = 'Die Datei <span class="sm-mono">vzlogger.json</span> liess sich '
                         . 'nicht schreiben. Rechte im Konfigurationsordner pr&uuml;fen.';
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
    $sm_meldung = ($sm_hinweis === '') ? 'vzlogger wurde neu gestartet.' : '';
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
        $sm_meldung = 'Die MQTT-Einstellungen wurden gespeichert.';
    } else {
        $sm_fehler[] = 'Die Datei <span class="sm-mono">smartmeter.cfg</span> liess sich '
                     . 'nicht schreiben.';
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
        $sm_fehler[] = 'Der Abfragetakt muss aus der Liste gew&auml;hlt werden.';
    }
    $port = isset($_POST['lg_udpport']) ? trim((string) $_POST['lg_udpport']) : '';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $sm_fehler[] = 'Der UDP-Port des Legacy-Lesers ist eine Zahl zwischen 1 und 65535.';
    }
    // Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen.
    if ($lesen && $sm_cfg['enabled']) {
        $sm_fehler[] = 'vzLogger ist eingeschaltet. Bitte dort zuerst abschalten &ndash; '
                     . 'beide Leser greifen auf dieselbe serielle Schnittstelle zu.';
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
                    $sm_fehler[] = 'Unbekanntes Z&auml;hlerprofil f&uuml;r ' . sm_e($s) . '.';
                } else {
                    $neu['METER'] = $m;
                }
            }
            if ($neu && !sm_cfg_set($s, $neu)) {
                $ok = false;
            }
        }

        if (!$ok) {
            $sm_fehler[] = 'Die Datei <span class="sm-mono">smartmeter.cfg</span> liess sich '
                         . 'nicht vollst&auml;ndig schreiben.';
        } elseif (!$sm_fehler) {
            list($cron_ok, $cron_text) = sm_cron_setzen($lesen, $takt);
            if ($cron_ok) {
                $sm_meldung = 'Gespeichert. ' . $cron_text;
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

if (isset($_POST['lg_cache'])) {
    $n = sm_cache_leeren();
    $sm_meldung = 'Zwischenspeicher geleert (' . $n . ' Datei(en) entfernt).';
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

$sm_version = '';
if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
    $sm_version = (string) LBSystem::pluginversion();
}

LBWeb::lbheader('Smartmeter' . ($sm_version !== '' ? ' V' . $sm_version : ''),
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
<div class="sm-alert sm-warn"><b>Nicht gespeichert:</b><ul>
<?php foreach ($sm_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($sm_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $sm_meldung; ?></div>
<?php } ?>
<?php if ($sm_hinweis !== '') { ?>
<div class="sm-alert sm-warn"><?php echo sm_e($sm_hinweis); ?></div>
<?php } ?>

<div class="sm-tabs">
  <div class="sm-tab" data-ziel="tab-vzlogger">Smartmeter (vzLogger)</div>
  <div class="sm-tab" data-ziel="tab-legacy">Smartmeter (Legacy)</div>
  <div class="sm-tab" data-ziel="tab-mqtt">MQTT</div>
  <div class="sm-tab" data-ziel="tab-loxone">Einbindung in Loxone</div>
  <div class="sm-tab" data-ziel="tab-test">Test</div>
  <div class="sm-tab" data-ziel="tab-log">Logdateien</div>
</div>

<!-- ============================== vzLogger ============================== -->
<div class="sm-pane" id="tab-vzlogger">

<?php if ($sm_installout !== '') { ?>
<h2>Ausgabe der Installation</h2>
<div class="sm-log"><?php echo sm_e($sm_installout); ?></div>
<?php } ?>

<h2>Zustand</h2>
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
    <input type="hidden" name="activetab" value="tab-vzlogger">
    <button type="submit" name="vz_install" value="1">vzlogger installieren</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Richtet eine Paketquelle ein und installiert ein Paket</span>
</div>
<?php } else { ?>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-vzlogger">
    <button type="submit" name="vz_neustart" value="1">vzlogger neu starten</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Unterbricht das Lesen kurz</span>
</div>
<?php } ?>

<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-vzlogger">

<h2>Leseweg</h2>
<div class="sm-row">
  <label><input type="checkbox" name="vz_enabled" value="1"<?php
    echo $sm_cfg['enabled'] ? ' checked' : ''; ?>> vzLogger einschalten</label>
  <p class="sm-small">Es darf immer nur <b>ein</b> Leser laufen. Ist die
  Legacy-Abfrage eingeschaltet, belegt sie dieselbe serielle Schnittstelle
  &ndash; die Diagnose oben weist darauf hin.</p>
</div>

<h2>Lesekopf</h2>
<?php if (!$sm_koepfe) { ?>
<div class="sm-alert sm-warn">Es wurde kein Lesekopf erkannt
(<span class="sm-mono">/dev/serial/smartmeter/*</span>). Lesekopf abziehen und
neu anstecken &ndash; die udev-Regel wird beim Start des Plugins angelegt.</div>
<?php } ?>
<div class="sm-row">
  <label for="vz_device">Ger&auml;t</label>
  <select id="vz_device" name="vz_device">
    <option value="">&ndash; keines &ndash;</option>
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
       . sm_e($sm_cfg['device']) . ' (derzeit nicht vorhanden)</option>';
}
?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_protocol">Protokoll</label>
  <select id="vz_protocol" name="vz_protocol">
    <option value="sml"<?php echo $sm_cfg['protocol'] === 'sml' ? ' selected' : ''; ?>>SML &ndash; der Z&auml;hler sendet von selbst</option>
    <option value="d0"<?php echo $sm_cfg['protocol'] === 'd0' ? ' selected' : ''; ?>>D0 &ndash; der Z&auml;hler muss gefragt werden</option>
  </select>
</div>
<div class="sm-row">
  <label for="vz_baudrate">Baudrate</label>
  <input type="text" id="vz_baudrate" name="vz_baudrate"
         value="<?php echo sm_e($sm_cfg['baudrate']); ?>">
  <p class="sm-small">9600 bei SML, oft 300 bei D0.</p>
</div>
<div class="sm-row">
  <label for="vz_parity">Zeichenrahmung</label>
  <select id="vz_parity" name="vz_parity">
<?php foreach (array('8n1', '7n1', '7e1', '8e1') as $par) { ?>
    <option value="<?php echo $par; ?>"<?php
      echo $sm_cfg['parity'] === $par ? ' selected' : ''; ?>><?php echo $par; ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-row">
  <label for="vz_localtime">Zeitstempel</label>
  <select id="vz_localtime" name="vz_localtime">
    <option value="1"<?php echo $sm_cfg['localtime'] ? ' selected' : ''; ?>>Rechner-Uhrzeit (empfohlen)</option>
    <option value="0"<?php echo !$sm_cfg['localtime'] ? ' selected' : ''; ?>>Uhr des Z&auml;hlers</option>
  </select>
  <p class="sm-small">Viele Haushaltsz&auml;hler senden keine gestellte Uhr.
  vzlogger verwirft solche Telegramme dann mit
  <span class="sm-mono">timestamp before 1990, IGNORING</span> &ndash; der
  Z&auml;hler wird gelesen, aber kein einziger Wert kommt an.</p>
</div>

<h2>Kan&auml;le</h2>
<div class="sm-row">
  <label for="vz_channels">OBIS-Kennzahlen, eine je Zeile</label>
  <textarea id="vz_channels" name="vz_channels"><?php
    echo sm_e(implode("\n", $sm_cfg['channels'])); ?></textarea>
  <p class="sm-small">Vorgabe: <span class="sm-mono">1-0:1.8.0</span> (Bezug),
  <span class="sm-mono">1-0:2.8.0</span> (Einspeisung),
  <span class="sm-mono">1-0:16.7.0</span> (aktuelle Leistung).</p>
</div>

<h2>Weitergabe</h2>
<div class="sm-row">
  <label for="vz_serial">Z&auml;hlernummer</label>
  <input type="text" id="vz_serial" name="vz_serial"
         value="<?php echo sm_e($sm_cfg['serial']); ?>">
  <p class="sm-small">Erscheint im MQTT-Thema und im UDP-Satz.</p>
</div>
<div class="sm-row">
  <label><input type="checkbox" name="vz_sendudp" value="1"<?php
    echo $sm_cfg['sendudp'] ? ' checked' : ''; ?>> zus&auml;tzlich per UDP senden</label>
  <p class="sm-small">MQTT ist der Regelweg. UDP nur, wo Werte anders nicht
  ankommen.</p>
</div>
<div class="sm-row">
  <label for="vz_udpport">UDP-Port</label>
  <input type="text" id="vz_udpport" name="vz_udpport"
         value="<?php echo sm_e($sm_cfg['udpport']); ?>">
</div>
<div class="sm-row">
  <label for="vz_httpport">HTTP-Port von vzlogger</label>
  <input type="text" id="vz_httpport" name="vz_httpport"
         value="<?php echo sm_e($sm_cfg['httpport']); ?>">
  <p class="sm-small">&Uuml;ber diesen Port holt das Plugin die Messwerte ab.
  Nur &auml;ndern, wenn der Port schon belegt ist.</p>
</div>

<div class="sm-knopfreihe sm-b-aktion">
  <button type="submit" name="vz_speichern" value="1">Speichern und vzlogger neu starten</button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Schreibt die Konfiguration neu und unterbricht das Lesen kurz</span>
</div>
</form>
</div>

<!-- =============================== Legacy =============================== -->
<div class="sm-pane" id="tab-legacy">

<h2>Der klassische Leser</h2>
<p class="sm-small">Fragt den Z&auml;hler &uuml;ber ein <b>Z&auml;hlerprofil</b>
ab &ndash; 41 Modelle sind hinterlegt. Der Weg ist &auml;lter als vzLogger,
funktioniert aber auch dort, wo vzLogger nichts liefert.</p>

<div class="sm-alert sm-info"><b>Es darf immer nur ein Leser laufen.</b> Beide
greifen auf dieselbe serielle Schnittstelle zu.
<?php echo $sm_cfg['enabled']
    ? ' vzLogger ist derzeit <b>eingeschaltet</b> &ndash; bitte zuerst dort abschalten.'
    : ' vzLogger ist derzeit ausgeschaltet.'; ?></div>

<?php $sm_lpid = sm_logger_pid(); ?>
<p><span class="sm-scheibe <?php echo $sm_lpid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo $sm_lpid !== null
    ? 'Der Leser l&auml;uft gerade (PID ' . sm_e($sm_lpid) . ').'
    : 'Der Leser l&auml;uft gerade nicht.'; ?>
<span class="sm-small">Abfragetakt laut Verkn&uuml;pfung:
<?php $sm_ist = sm_cron_ist();
      $sm_takte = sm_takte();
      echo $sm_ist !== '' ? $sm_takte[$sm_ist][1] : 'keine eingerichtet'; ?></span></p>

<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-legacy">

<h2>Abfrage</h2>
<div class="sm-row">
  <label><input type="checkbox" name="lg_read" value="1"<?php
    echo $sm_lcfg_read === '1' ? ' checked' : ''; ?>> Legacy-Leser einschalten</label>
</div>
<div class="sm-row">
  <label for="lg_cron">Abfragetakt</label>
  <select id="lg_cron" name="lg_cron">
<?php foreach (sm_takte() as $wert => $t) { ?>
    <option value="<?php echo $wert; ?>"<?php
      echo $sm_lcfg_cron === $wert ? ' selected' : ''; ?>><?php echo $t[1]; ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><i>dauerhaft</i> l&auml;sst den Leser st&auml;ndig
  mitlaufen &ndash; sinnvoll bei Z&auml;hlern, die von selbst senden.</p>
</div>
<div class="sm-row">
  <label><input type="checkbox" name="lg_sendudp" value="1"<?php
    echo $sm_lcfg_udp === '1' ? ' checked' : ''; ?>> zus&auml;tzlich per UDP senden</label>
</div>
<div class="sm-row">
  <label for="lg_udpport">UDP-Port</label>
  <input type="text" id="lg_udpport" name="lg_udpport"
         value="<?php echo sm_e($sm_lcfg_udpport); ?>">
  <p class="sm-small">MQTT wird im Reiter <i>MQTT</i> eingestellt &ndash; er
  gilt f&uuml;r beide Lesewege.</p>
</div>

<h2>Leseköpfe</h2>
<?php $sm_koepfe_liste = sm_koepfe(); ?>
<?php if (!$sm_koepfe_liste) { ?>
<div class="sm-alert sm-warn">Es ist kein Lesekopf eingerichtet. Lesekopf
anstecken und die Seite neu laden &ndash; er wird dann selbst&auml;ndig
eingetragen.</div>
<?php } else { foreach ($sm_koepfe_liste as $sm_k) {
    $sm_s = $sm_k['ABSCHNITT']; ?>
<h3 class="sm-h3"><?php echo sm_e($sm_s); ?>
<?php if (!$sm_k['ANGESTECKT']) { ?>
  <span class="sm-small">&ndash; derzeit nicht angesteckt</span>
<?php } ?></h3>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_name">Bezeichnung</label>
  <input type="text" id="<?php echo sm_e($sm_s); ?>_name"
         name="lg_<?php echo sm_e($sm_s); ?>_name"
         value="<?php echo sm_e(isset($sm_k['NAME']) ? $sm_k['NAME'] : $sm_s); ?>">
</div>
<div class="sm-row">
  <label for="<?php echo sm_e($sm_s); ?>_meter">Z&auml;hlerprofil</label>
  <select id="<?php echo sm_e($sm_s); ?>_meter" name="lg_<?php echo sm_e($sm_s); ?>_meter">
<?php $sm_akt = isset($sm_k['METER']) ? $sm_k['METER'] : '0';
      foreach (sm_profile() as $sm_pk => $sm_pn) { ?>
    <option value="<?php echo sm_e($sm_pk); ?>"<?php
      echo $sm_akt === $sm_pk ? ' selected' : ''; ?>><?php echo $sm_pn; ?></option>
<?php } ?>
  </select>
  <p class="sm-small">Ger&auml;t: <span class="sm-mono"><?php
    echo sm_e($sm_k['DEVICE']); ?></span></p>
</div>
<?php $sm_w = sm_werte($sm_s); if ($sm_w) { ?>
<p class="sm-small">Zuletzt gelesen:</p>
<table class="sm-tbl">
<tr><th style="width:46%">Gr&ouml;&szlig;e</th><th>Wert</th></tr>
<?php foreach ($sm_w as $sm_pv) { ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_pv[0]); ?></td>
    <td class="sm-mono"><?php echo sm_e($sm_pv[1]); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php } } ?>

<div class="sm-knopfreihe sm-b-aktion">
  <button type="submit" name="lg_speichern" value="1">Speichern und Abfragetakt setzen</button>
</div>
</form>

<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-legacy">
    <button type="submit" name="lg_abfragen" value="1">Jetzt einmal abfragen</button>
  </form>
</div>
<div class="sm-knopfreihe sm-b-technik">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-legacy">
    <button type="submit" name="lg_cache" value="1">Zwischenspeicher leeren</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> Ansehen &mdash; fragt den Z&auml;hler einmal ab</span>
<span><i class="sm-punkt sm-b-technik"></i> Technischer Eingriff &mdash; verwirft zwischengespeicherte Werte</span>
<span><i class="sm-punkt sm-b-aktion"></i> &Auml;ndert den Abfragetakt</span>
</div>

<?php if ($sm_lg_ausgabe !== '') { ?>
<h2>Ausgabe</h2>
<div class="sm-log"><?php echo sm_e($sm_lg_ausgabe); ?></div>
<?php } ?>

<h2>Wie der Leser arbeitet</h2>
<p class="sm-small">Die Oberfl&auml;che und der Abholer
(<span class="sm-mono">bin/fetch.php</span>) sind PHP. Der eigentliche Leser
<span class="sm-mono">bin/sm_logger.pl</span> ist bewusst Perl geblieben: er
spricht &uuml;ber <span class="sm-mono">Device::SerialPort</span> mit dem
Z&auml;hler und wechselt bei D0-Z&auml;hlern mitten in der Sitzung die
Baudrate. Daf&uuml;r gibt es in PHP kein verl&auml;ssliches Gegenst&uuml;ck,
und die 41 Profile liessen sich ohne 41 Z&auml;hler auch nicht nachpr&uuml;fen.</p>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane" id="tab-mqtt">

<h2>Zustand des MQTT-Gateways</h2>
<p class="sm-small">Das MQTT-Gateway ist seit LoxBerry&nbsp;3 <b>Bestandteil des
Systems</b> und kein Plugin. Es wird unter <i>System &rarr; MQTT Gateway</i>
eingerichtet.</p>

<h2>Einstellungen</h2>
<p class="sm-small">Dies ist die <b>einzige</b> Stelle, an der MQTT eingestellt
wird &ndash; beide Lesewege benutzen sie.</p>
<form method="post" action="index.php">
<input type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row">
  <label><input type="checkbox" name="mq_an" value="1"<?php
    echo $sm_legacy['SENDMQTT'] === '1' ? ' checked' : ''; ?>> Werte per MQTT senden</label>
</div>
<div class="sm-row">
  <label for="mq_topic">Themenpr&auml;fix</label>
  <input type="text" id="mq_topic" name="mq_topic"
         value="<?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>">
</div>
<div class="sm-knopfreihe sm-b-aktion">
  <button type="submit" name="mq_speichern" value="1">Speichern</button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> &Auml;ndert, wohin die Werte gemeldet werden</span>
</div>
</form>

<h2>Das einzutragende Abo</h2>
<p class="sm-small"><b>Ohne diesen Eintrag kommt am Miniserver nichts an.</b>
Einzutragen unter <i>System &rarr; MQTT Gateway &rarr; Abonnements</i>:</p>
<pre class="sm-pre"><?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>/#</pre>

<h2>Ver&ouml;ffentlichte Themen</h2>
<table class="sm-tbl">
<tr><th style="width:52%">Thema</th><th>Bedeutung</th></tr>
<?php foreach ($sm_cfg['channels'] as $c) { ?>
<tr><td class="sm-mono"><?php echo sm_e($sm_legacy['MQTTTOPIC'] . '/'
      . $sm_cfg['serial'] . '/' . $c); ?></td>
    <td>OBIS <?php echo sm_e($c); ?><?php
      if ($c === '1-0:1.8.0') { echo ' &ndash; Z&auml;hlerstand Bezug'; }
      elseif ($c === '1-0:2.8.0') { echo ' &ndash; Z&auml;hlerstand Einspeisung'; }
      elseif ($c === '1-0:16.7.0') { echo ' &ndash; aktuelle Wirkleistung'; }
    ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane" id="tab-loxone">

<h2>Einbindung in Loxone &ndash; Schritt f&uuml;r Schritt</h2>

<div class="sm-step">
<b>Schritt 1: Weg festlegen</b><br><br>
<b>MQTT ist der Regelweg.</b> Das Gateway legt die Namen selbst an; in Loxone
braucht man nur virtuelle Eing&auml;nge mit passendem Titel. UDP steht als
Ausweichweg bereit &ndash; einzuschalten im Reiter <i>Smartmeter (vzLogger)</i>.
</div>

<div class="sm-step">
<b>Schritt 2: Abo im MQTT-Gateway eintragen</b><br><br>
<b>Ohne diesen Eintrag kommt am Miniserver nichts an.</b> Unter
<i>System &rarr; MQTT Gateway &rarr; Abonnements</i>:
<pre class="sm-pre"><?php echo sm_e($sm_legacy['MQTTTOPIC']); ?>/#</pre>
</div>

<div class="sm-step">
<b>Schritt 3: Virtuelle Eing&auml;nge anlegen</b><br><br>
<table class="sm-tbl">
<tr><th>Titel des virtuellen Eingangs</th><th style="width:16%">Einheit</th><th style="width:30%">Bedeutung</th></tr>
<?php foreach ($sm_cfg['channels'] as $c) {
    $titel = str_replace(array('/', ':', '-', '.'), '_',
        $sm_legacy['MQTTTOPIC'] . '_' . $sm_cfg['serial'] . '_' . $c);
    $einheit = ($c === '1-0:16.7.0') ? '&lt;v.0&gt;&nbsp;W' : '&lt;v.3&gt;&nbsp;kWh';
    $bed = ($c === '1-0:1.8.0') ? 'Z&auml;hlerstand Bezug'
         : (($c === '1-0:2.8.0') ? 'Z&auml;hlerstand Einspeisung'
         : (($c === '1-0:16.7.0') ? 'aktuelle Wirkleistung' : 'OBIS ' . sm_e($c))); ?>
<tr><td class="sm-mono"><?php echo sm_e($titel); ?></td>
    <td><?php echo $einheit; ?></td><td><?php echo $bed; ?></td></tr>
<?php } ?>
</table>
<p class="sm-small">Die endg&uuml;ltigen Namen zeigt das Gateway unter
<i>Eingehende Daten</i> &ndash; weichen sie ab, gelten die dort angezeigten.</p>
</div>

<div class="sm-step">
<b>Schritt 4: Der UDP-Weg</b><br><br>
<?php if ($sm_cfg['sendudp']) { ?>
Ein <i>virtueller UDP-Eingang</i> auf Port
<b><?php echo sm_e($sm_cfg['udpport']); ?></b>, je Wert ein Befehl mit dieser
Erkennung:
<pre class="sm-pre">\i<?php echo sm_e($sm_cfg['serial']); ?>/1-0:1.8.0,\i\v</pre>
<?php } else { ?>
UDP ist derzeit <b>ausgeschaltet</b>. Einschalten im Reiter
<i>Smartmeter (vzLogger)</i>.
<?php } ?>
</div>

<div class="sm-step">
<b>Schritt 5: Ausfallerkennung</b><br><br>
Schweigt der Z&auml;hler, behalten die virtuellen Eing&auml;nge ihren
<b>letzten Wert</b> &ndash; in der App sieht dann alles normal aus. Deshalb die
aktuelle Wirkleistung mitf&uuml;hren: sie &auml;ndert sich st&auml;ndig. Bleibt
sie exakt stehen, kommt nichts mehr an.
</div>

<div class="sm-step">
<b>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br><br>
Von oben nach unten abarbeiten. Die Bausteine findet man in Loxone Config
&uuml;ber die Baustein-Suche (F5):
<table class="sm-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td>Strom_Bezug</td><td>Einheit <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td>MQTT <span class="sm-mono">1-0:1.8.0</span></td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td>Strom_Einspeisung</td><td>Einheit <span class="sm-mono">&lt;v.3&gt; kWh</span></td><td>MQTT <span class="sm-mono">1-0:2.8.0</span></td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td>Strom_Leistung</td><td>Einheit <span class="sm-mono">&lt;v.0&gt; W</span></td><td>MQTT <span class="sm-mono">1-0:16.7.0</span></td></tr>
<tr><td>4</td><td>Z&auml;hler</td><td>Verbrauch_Tag</td><td>R&uuml;cksetzen um Mitternacht</td><td>Eingang &larr; #1</td></tr>
<tr><td>5</td><td>Statistik</td><td>Strom_Verlauf</td><td>Aufzeichnung analog</td><td>Eingang &larr; #3</td></tr>
<tr><td>6</td><td>Vergleicher</td><td>Einspeisung_aktiv</td><td>Schwelle 0&nbsp;W, Richtung kleiner</td><td>Eingang &larr; #3</td></tr>
<tr><td>7</td><td>Analogspeicher</td><td>Leistung_Vorwert</td><td>&mdash;</td><td>Eingang &larr; #3</td></tr>
<tr><td>8</td><td>Formel</td><td>Leistung_Aenderung</td><td><span class="sm-mono">ABS(I1-I2)</span></td><td>I1 = #3, I2 = #7</td></tr>
<tr><td>9</td><td>Einschaltverz&ouml;gerung</td><td>Zaehler_schweigt</td><td>Verz&ouml;gerung <b>900</b> s</td><td>Eingang &larr; #8 = 0</td></tr>
<tr><td>10</td><td>ODER</td><td>Strom_Meldungen</td><td>&mdash;</td><td>Eing&auml;nge &larr; #9 und weitere</td></tr>
<tr><td>11</td><td>Benachrichtigung</td><td>Stromz&auml;hler pr&uuml;fen</td><td>Text frei</td><td>Eingang &larr; #10</td></tr>
<tr><td>12 <i>(optional)</i></td><td>Status</td><td>Strom aktuell</td><td>Statustext siehe unten</td><td>v1 = #3, v2 = #1</td></tr>
</table>
<br>
<b>Statustext f&uuml;r #12:</b>
<pre class="sm-pre">&lt;v1.0&gt; W &middot; Z&auml;hlerstand &lt;v2.3&gt; kWh</pre>
<b>Zu #9:</b> Die Schwelle muss deutlich &uuml;ber dem Sendetakt des
Z&auml;hlers liegen &ndash; 900 Sekunden sind ein ruhiger Wert, damit ein
einzelnes verpasstes Telegramm keine Meldung ausl&ouml;st.<br>
<b>Zu #10 und #11:</b> Der Benachrichtigungs-Baustein sendet nur beim Wechsel
von Aus auf Ein. <b>Niemals mehrere Quellen direkt an seinen Eingang</b> &ndash;
erst &uuml;ber ODER zusammenf&uuml;hren, sonst verschluckt eine dauerhaft
aktive Quelle alle &uuml;brigen.
</div>

<div class="sm-step">
<b>Schritt 7: Gegenprobe</b><br><br>
Im Reiter <i>Test</i> die <i>Antwort der HTTP-Schnittstelle</i> ansehen. Stehen
dort Werte mit <span class="sm-mono">"last"</span> gr&ouml;&szlig;er null,
liest das Plugin. Kommen sie in Loxone trotzdem nicht an, fehlt fast immer das
Abo aus Schritt&nbsp;2.
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane" id="tab-test">

<?php if ($sm_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $sm_test_titel; ?></b></div>
<?php echo $sm_test_text; ?>
<?php } ?>

<h2>Diagnose</h2>
<table class="sm-tbl sm-diag">
<?php foreach ($sm_diag as $z) { ?>
<tr><td><?php echo sm_e($z[0]); ?></td>
    <td style="color:<?php echo sm_farbe($z[1]); ?>"><?php echo sm_zeichen($z[1]); ?></td>
    <td><?php echo sm_e($z[2]); ?></td></tr>
<?php } ?>
</table>

<h2>Nachsehen</h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php foreach (array('umgebung' => 'Umgebung pr&uuml;fen',
                     'http'     => 'Antwort der HTTP-Schnittstelle',
                     'legacy'   => 'Legacy-Einstellungen') as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="<?php echo sm_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane" id="tab-log">
<h2>Protokolle</h2>
<p class="sm-small">Die letzten Zeilen aus
<span class="sm-mono">vzlogger.log</span> und
<span class="sm-mono">vzlogger_fetch.log</span>.</p>
<div class="sm-log"><?php echo sm_e($sm_logtext); ?></div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo '<h2>Logdateien des LoxBerry</h2>';
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
