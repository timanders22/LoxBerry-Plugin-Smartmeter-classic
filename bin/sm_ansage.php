<?php
/**
 * Smartmeter classic - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 2.8.15).
 * Aufruf: php sm_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'zaehler'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$sm_a = isset($argv[1]) ? (string) $argv[1] : '';
$sm_g = isset($argv[2]) ? (string) $argv[2] : '';
$sm_o = isset($argv[3]) ? (string) $argv[3] : '';
$sm_n = isset($argv[4]) ? (string) $argv[4] : '';
$sm_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($sm_a, array('zaehler'), true) || ($sm_g !== '0' && $sm_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $sm_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$sm_h = getenv('LBHOMEDIR');
if (!$sm_h || !is_dir($sm_h . '/config/plugins')) {
    $sm_h = dirname(dirname(dirname(__DIR__)));
}
$sm_lib = $sm_h . '/webfrontend/htmlauth/plugins/' . $sm_o . '/sm_lib.php';
if (!is_file($sm_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $sm_h);
putenv('LBPPLUGINDIR=' . $sm_o);
require_once $sm_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) sm_ansage_flanke($sm_a, $sm_g === '1', $sm_n, $sm_f)) . "\n";
exit(0);
