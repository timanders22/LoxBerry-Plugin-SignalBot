#!/usr/bin/env php
<?php
/**
 * Signal Bot - Meldungs-Leser (seit 0.9.44; Statusmeldungen aller Plugins, Entscheidung des Hausherrn 23.11.2026
 * "haus/meldung (Empfehlung)")
 *
 * Begleitprozess fuer die Statusmeldungen der Plugins: haelt eine Dauerverbindung zum Broker des LoxBerry, abonniert
 * haus/meldung/+ und reiht jede Meldung eines freigegebenen Absenders wie eine Meldung unter <praefix>/befehl/plugin/
 * <ordner> ein (sg_meldung_behandeln()); Quittung auf haus/meldung/<ordner>/quittung, nie retained.
 *
 * Vom Wecker cron.01min gestartet wie der Haus-Leser - NICHT als Kind des Bots. Eigene Sperre <tmp>/meldung.lock mit
 * 'ce'. Ohne den Haken "Meldungen unter haus/meldung annehmen" endet er sofort, still; wird der Haken abgeschaltet,
 * endet er binnen 60 s. Schreibt unter haus/ nur Quittungen (nie retained).
 *
 * Aufrufe: sg_meldung.php            Dauerbetrieb (aus dem Cron)
 *          sg_meldung.php einmal <s> <s> Sekunden lauschen, dann Schluss (Probe)
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
    fwrite(STDERR, "SignalBot Meldungs-Leser: sg_lib.php nicht gefunden.\n");
    exit(1);
}
require_once $sg_lib;

/* Nie als root (G11-Haertung): Dateien in tmp/ gehoerten sonst root, und der Lauf als loxberry kaeme nicht mehr hin. */
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "SignalBot Meldungs-Leser: nicht als root starten (laeuft als loxberry aus dem Cron).\n");
    exit(1);
}

$sg_modus = isset($argv[1]) ? (string) $argv[1] : 'dauer';
$sg_ende = 0;
if ($sg_modus === 'einmal') {
    $sg_ende = time() + max(1, min(600, isset($argv[2]) ? (int) $argv[2] : 30));
} elseif ($sg_modus !== 'dauer') {
    fwrite(STDERR, "Aufruf: sg_meldung.php [einmal <sekunden>]\n");
    exit(2);
}

/* Ohne Haken still zurueck - der Wecker ruft jede Minute. Gelesen wird ohne Anlegen. */
$sg_cfg = sg_config(false);
if (empty($sg_cfg['meldung_haus_ein']) || sg_upgrade_marke()) { exit(0); }

$sg_sperre = @fopen(sg_tmpdir() . '/meldung.lock', 'ce');
if ($sg_sperre === false) { exit(1); }
if (!flock($sg_sperre, LOCK_EX | LOCK_NB)) { exit(0); }   // laeuft schon - der Normalfall
ftruncate($sg_sperre, 0);
rewind($sg_sperre);
fwrite($sg_sperre, (string) getmypid() . "\n");
fflush($sg_sperre);

$sg_rc = sg_meldung_lauf($sg_ende);
flock($sg_sperre, LOCK_UN);
fclose($sg_sperre);
exit((int) $sg_rc);
