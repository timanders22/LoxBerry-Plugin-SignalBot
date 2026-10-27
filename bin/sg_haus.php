#!/usr/bin/env php
<?php
/**
 * Signal Bot - Haus-Leser (seit 0.9.30, Gruppe 32; Vorschlag 3, Entwurf 7.3, E-E15/E-E16)
 *
 * Begleitprozess fuer "status strom", "status speicher" ... im Chat: haelt eine Dauerverbindung zum Broker des
 * LoxBerry und schreibt die Haus-Themen der eingeschalteten Bereiche nach <tmp>/haus_werte.json; der Bot liest beim
 * Chat-Befehl nur diese Datei. Eine kurze Sitzung je Chat-Befehl muesste bis 65 s warten, um einen Zaehler live zu
 * sehen (Gedaechtnis abnehmer-phasenfalle).
 *
 * Vom Wecker cron.01min gestartet wie der Bot - NICHT als Kind des Bots (eine Sperre vererbt sich an Kinder). Eigene
 * Sperre <tmp>/haus.lock mit 'ce'. Ohne den Haken "Werte anderer Plugins im Chat abfragen" endet er sofort, still;
 * wird der Haken abgeschaltet, endet er binnen 60 s. Schreibt nichts unter haus/ (nur Abnehmer).
 *
 * Aufrufe: sg_haus.php            Dauerbetrieb (aus dem Cron)
 *          sg_haus.php einmal <s> <s> Sekunden lauschen, dann Schluss (Probe)
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek ueber eine Kandidatenliste (G14): zuerst der Archivkandidat, dann LBHOMEDIR/LBPPLUGINDIR, dann
 * der Installationspfad. */
$sg_kand = array(dirname(__DIR__) . '/webfrontend/htmlauth/sg_lib.php');
$sg_lb = getenv('LBHOMEDIR');
$sg_ordner = getenv('LBPPLUGINDIR') ? basename(rtrim((string) getenv('LBPPLUGINDIR'), '/')) : basename(__DIR__);
if ($sg_lb) { $sg_kand[] = $sg_lb . '/webfrontend/htmlauth/plugins/' . $sg_ordner . '/sg_lib.php'; }
$sg_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';
$sg_lib = '';
foreach ($sg_kand as $sg_k) {
    if (is_file($sg_k)) { $sg_lib = $sg_k; break; }
}
if ($sg_lib === '') {
    fwrite(STDERR, "SignalBot Haus-Leser: sg_lib.php nicht gefunden.\n");
    exit(1);
}
require_once $sg_lib;

/* Nie als root (G11-Haertung): Dateien in tmp/ gehoerten sonst root, und der Lauf als loxberry kaeme nicht mehr hin. */
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "SignalBot Haus-Leser: nicht als root starten (laeuft als loxberry aus dem Cron).\n");
    exit(1);
}

$sg_modus = isset($argv[1]) ? (string) $argv[1] : 'dauer';
$sg_ende = 0;
if ($sg_modus === 'einmal') {
    $sg_ende = time() + max(1, min(600, isset($argv[2]) ? (int) $argv[2] : 30));
} elseif ($sg_modus !== 'dauer') {
    fwrite(STDERR, "Aufruf: sg_haus.php [einmal <sekunden>]\n");
    exit(2);
}

/* Ohne Haken still zurueck - der Wecker ruft jede Minute. Gelesen wird ohne Anlegen. */
$sg_cfg = sg_config(false);
if (!sg_haus_themen($sg_cfg) || sg_upgrade_marke()) { exit(0); }

$sg_sperre = @fopen(sg_tmpdir() . '/haus.lock', 'ce');
if ($sg_sperre === false) { exit(1); }
if (!flock($sg_sperre, LOCK_EX | LOCK_NB)) { exit(0); }   // laeuft schon - der Normalfall
ftruncate($sg_sperre, 0);
rewind($sg_sperre);
fwrite($sg_sperre, (string) getmypid() . "\n");
fflush($sg_sperre);

$sg_rc = sg_haus_lauf($sg_ende);
flock($sg_sperre, LOCK_UN);
fclose($sg_sperre);
exit((int) $sg_rc);
