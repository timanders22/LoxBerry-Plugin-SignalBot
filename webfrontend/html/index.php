<?php
/**
 * Signal Bot fuer LoxBerry - Endpunkt fuer den Miniserver
 *
 * Liegt im UNANGEMELDETEN Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals - ein einfaches == liesse sich ueber die Antwortzeit Zeichen
 * fuer Zeichen erraten.
 *
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Aktionen:
 *   senden    eine Nachricht an einen erlaubten Empfaenger schicken
 *             &text=...  und wahlweise &an=+49...  (sonst an alle Erlaubten)
 *   zustand   einen Zustand melden, den der Bot auf "status" ausgibt
 *             &name=alarm&wert=scharf
 *   status    Kurzauskunft ueber den Bot selbst, fuer einen virtuellen
 *             HTTP-Eingang
 *
 * BEWUSST NICHT MOEGLICH: einen Chat-Befehl von hier aus ausloesen. Der
 * Endpunkt darf melden und senden, aber nicht schalten - sonst waere die
 * ganze Absicherung des Bots ueber einen HTTP-Aufruf zu umgehen.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/htmlauth/sg_lib.php';

function sg_ende($code, $text)
{
    http_response_code($code);
    echo rtrim($text) . "\n";
    exit;
}

$cfg = sg_config();

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($soll === '' || !hash_equals($soll, $ist)) {
    sg_ende(403, 'SIGNAL;OK=0;GRUND=TOKEN');
}

$erlaubte_aktionen = array('senden', 'zustand', 'status');
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, $erlaubte_aktionen, true)) {
    sg_ende(400, "SIGNAL;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', $erlaubte_aktionen));
}

/* ---------------- status ---------------- */
if ($aktion === 'status') {
    $z = sg_zustaende();
    printf("SIGNAL;OK=%d;DAEMON=%d;KONTO=%d;ERLAUBTE=%d;BEFEHLE=%d;ZUSTAENDE=%d\n",
        sg_daemon_lebt() ? 1 : 0,
        sg_dienst_laeuft() ? 1 : 0,
        (string) $cfg['konto'] !== '' ? 1 : 0,
        count($cfg['erlaubt']),
        count(array_filter($cfg['befehle'], function ($b) { return !empty($b['aktiv']) && $b['wort'] !== ''; })),
        count($z));
    exit;
}

/* ---------------- zustand ---------------- */
if ($aktion === 'zustand') {
    if (empty($cfg['zustand_ein'])) {
        sg_ende(403, 'SIGNAL;OK=0;GRUND=ZUSTAENDE_AUS');
    }
    $name = isset($_GET['name']) ? (string) $_GET['name'] : '';
    $wert = isset($_GET['wert']) ? (string) $_GET['wert'] : '';
    // Name hart auf harmlose Zeichen begrenzen - er landet im Dateinamen
    // nicht, aber im Chat, und ein Zeilenumbruch dort waere unschoen.
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $name);
    $wert = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $wert));
    if ($name === '' || $wert === '') {
        sg_ende(400, 'SIGNAL;OK=0;GRUND=NAME_ODER_WERT_FEHLT');
    }
    if (mb_strlen($wert, 'UTF-8') > 120) { $wert = mb_substr($wert, 0, 120, 'UTF-8'); }
    if (!sg_zustand_setzen($name, $wert)) {
        sg_ende(500, 'SIGNAL;OK=0;GRUND=NICHT_GESPEICHERT');
    }
    printf("SIGNAL;OK=1;AKTION=zustand;NAME=%s\n", $name);
    exit;
}

/* ---------------- senden ---------------- */
$text = isset($_GET['text']) ? (string) $_GET['text'] : '';
// Steuerzeichen raus, Zeilenumbruch als \n zugelassen: Loxone kann keinen
// echten Umbruch in eine URL schreiben.
$text = str_replace('\n', "\n", $text);
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
$text = trim($text);
if ($text === '') {
    sg_ende(400, 'SIGNAL;OK=0;GRUND=TEXT_FEHLT');
}
if (mb_strlen($text, 'UTF-8') > 2000) { $text = mb_substr($text, 0, 2000, 'UTF-8'); }

$an = isset($_GET['an']) ? preg_replace('/[^0-9+]/', '', (string) $_GET['an']) : '';
if ($an !== '') {
    // Auch beim Senden gilt die Weissliste. Sonst waere der Endpunkt ein
    // offener Nachrichtenversand: wer das Token kennt, koennte ueber das
    // Signal-Konto des Hauses an beliebige Nummern schreiben.
    if (!sg_erlaubt($an)) {
        sg_log('Senden abgewiesen: ' . sg_maske($an) . ' steht nicht auf der Weissliste');
        sg_ende(403, 'SIGNAL;OK=0;GRUND=EMPFAENGER_NICHT_ERLAUBT');
    }
    $ziele = array($an);
} else {
    $ziele = $cfg['erlaubt'];
}
if (!$ziele) {
    sg_ende(400, 'SIGNAL;OK=0;GRUND=KEIN_EMPFAENGER');
}

$ok = 0;
foreach ($ziele as $ziel) {
    if (sg_senden($ziel, $text)) { $ok++; }
}
sg_log('Meldung aus Loxone an ' . $ok . ' von ' . count($ziele) . ' Empfaenger gesendet');
if ($ok === 0) {
    sg_ende(502, 'SIGNAL;OK=0;GRUND=SENDEN_FEHLGESCHLAGEN');
}
printf("SIGNAL;OK=1;AKTION=senden;EMPFAENGER=%d\n", $ok);
