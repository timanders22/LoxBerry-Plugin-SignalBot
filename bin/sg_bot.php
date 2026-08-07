#!/usr/bin/env php
<?php
/**
 * Signal Bot fuer LoxBerry - der Empfaenger
 *
 * Haelt die Verbindung zum Ereignisstrom von signal-cli offen
 * (GET /api/v1/events, Server-Sent Events) und gibt jede eingehende
 * Nachricht an sg_verarbeite() weiter.
 *
 * WARUM EIN DAUERLAEUFER UND KEIN CRON-TAKT
 * Ein Bot, der die Alarmanlage schaltet, darf nicht bis zu einer Minute
 * brauchen. Und wichtiger: signal-cli holt Nachrichten dauerhaft vom
 * Signal-Server ab. Waere kein Zuhoerer am Ereignisstrom, waeren die
 * Nachrichten dieser Zeit verloren - der Befehl kaeme nie an, ohne dass es
 * jemand merkt. Deshalb bleibt dieses Skript verbunden.
 *
 * WARUM TROTZDEM KEIN EIGENER SYSTEMD-DIENST
 * Eine Sperrdatei verhindert Doppelstarts, und cron.01min ruft das Skript
 * jede Minute erneut auf. Laeuft es, ist der Aufruf wirkungslos; ist es
 * abgestuerzt, lebt es binnen einer Minute wieder. Das ist ein Dienst
 * weniger, der beim Plugin-Update haengen bleiben kann.
 *
 * Aufrufe:
 *   sg_bot.php            Dauerbetrieb (aus dem Cron)
 *   sg_bot.php einmal     30 Sekunden lauschen, dann Schluss - fuer den Test
 *   sg_bot.php test "+49..." "licht an"    eine Nachricht durchspielen,
 *                                          ohne dass jemand etwas schickt
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once dirname(__DIR__) . '/webfrontend/htmlauth/sg_lib.php';

$modus = isset($argv[1]) ? (string) $argv[1] : 'dauer';

/* ---- Trockenlauf: eine Nachricht durchspielen ---- */
if ($modus === 'test') {
    $von = isset($argv[2]) ? (string) $argv[2] : '';
    $text = isset($argv[3]) ? (string) $argv[3] : '';
    $erg = sg_verarbeite($von, $text);
    printf("Von     : %s\nText    : %s\nGrund   : %s\nAntwort : %s\n",
        sg_maske($von), $text, $erg['grund'],
        $erg['antwort'] === '' ? '(bewusst keine)' : $erg['antwort']);
    exit(0);
}

/* ---- Nur ein Lauf gleichzeitig ---- */
$sperre = sg_tmpdir() . '/bot.lock';
$fh = @fopen($sperre, 'c');
if ($fh === false) { exit(1); }
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    // Laeuft schon - das ist der Normalfall bei jedem Cron-Aufruf.
    exit(0);
}

$cfg = sg_config();
$ende = ($modus === 'einmal') ? time() + 30 : 0;   // 0 = ohne Ende

sg_log('Bot gestartet' . ($ende ? ' (Testlauf, 30 s)' : ''));

/** Eine Zeile des Ereignisstroms auswerten und gegebenenfalls antworten. */
function sg_ereignis($json)
{
    $d = json_decode($json, true);
    if (!is_array($d)) { return; }
    // Der Strom liefert JSON-RPC-Meldungen der Methode 'receive'.
    if (!isset($d['method']) || $d['method'] !== 'receive') { return; }
    $par = isset($d['params']) ? $d['params'] : array();
    // Zwei Formen: direkt, oder in ein Abonnement verpackt.
    $env = isset($par['envelope']) ? $par['envelope']
         : (isset($par['result']['envelope']) ? $par['result']['envelope'] : null);
    if (!is_array($env)) { return; }
    // Nur echte Textnachrichten. Lesebestaetigungen, Tippanzeigen und die
    // eigenen Nachrichten aus dem Abgleich werden uebergangen - sonst
    // antwortet der Bot auf sich selbst.
    if (!isset($env['dataMessage']['message'])) { return; }
    $text = (string) $env['dataMessage']['message'];
    if (trim($text) === '') { return; }
    $von = isset($env['sourceNumber']) ? (string) $env['sourceNumber']
         : (isset($env['source']) ? (string) $env['source'] : '');
    if ($von === '') { return; }

    $erg = sg_verarbeite($von, $text);
    if ($erg['antwort'] !== '') {
        sg_senden($von, $erg['antwort']);
    }
}

/** Einmal am Ereignisstrom hoeren, bis er abreisst. */
function sg_lauschen($ende)
{
    $cfg = sg_config();
    $url = $cfg['rpc_url'] . '/api/v1/events';
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET',
        'timeout' => 300,
        'header' => "Accept: text/event-stream\r\nUser-Agent: LoxBerry-SignalBot",
    )));
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) { return false; }
    stream_set_timeout($fp, 300);
    while (!feof($fp)) {
        if ($ende && time() > $ende) { break; }
        $zeile = fgets($fp);
        if ($zeile === false) {
            $st = stream_get_meta_data($fp);
            if (!empty($st['timed_out'])) { continue; }   // nur Stille
            break;
        }
        $zeile = trim($zeile);
        if ($zeile === '' || strpos($zeile, 'data:') !== 0) { continue; }
        sg_ereignis(trim(substr($zeile, 5)));
    }
    fclose($fp);
    return true;
}

$fehler_folge = 0;
while (true) {
    if ($ende && time() > $ende) { break; }
    if (!sg_lauschen($ende)) {
        $fehler_folge++;
        // Mit wachsendem Abstand erneut versuchen, statt dagegen anzurennen.
        // Beim ersten Mal laut, danach nur noch alle zehn Versuche - sonst
        // laeuft das Protokoll voll, waehrend signal-cli startet.
        if ($fehler_folge === 1 || $fehler_folge % 10 === 0) {
            sg_log('Ereignisstrom nicht erreichbar (Versuch ' . $fehler_folge . '). Laeuft signal-cli?');
        }
        $pause = min(60, 5 * $fehler_folge);
        for ($i = 0; $i < $pause; $i++) {
            if ($ende && time() > $ende) { break 2; }
            sleep(1);
        }
        continue;
    }
    if ($fehler_folge > 0) { sg_log('Ereignisstrom wieder da.'); }
    $fehler_folge = 0;
    // Sauber beendeter Strom: kurz durchatmen, dann neu verbinden.
    sleep(2);
}

sg_log('Bot beendet' . ($ende ? ' (Testlauf)' : ''));
flock($fh, LOCK_UN);
fclose($fh);
exit(0);
