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
 * Seit 0.9.30 (Gruppe 32): fuer neue Anlagen ist der Endpunkt ab Werk AUS
 * (endpunkt_ein, E-E8) - Werte kommen ueber das MQTT-Gateway V2, Meldungen
 * ueber die Befehlsbruecke, beides ohne Token. Eine bestehende Anlage behaelt
 * ihn (Lese-Regel in sg_config, postinstall.sh). Offen bleibt nur ?selftest=1.
 * Die Antworten sind byte-gleich zu 0.9.29; der Ablauf steht in
 * sg_befehl_ausfuehren() - dieselbe Funktion ruft die Befehlsbruecke.
 *
 * BEWUSST NICHT MOEGLICH: einen Chat-Befehl von hier aus ausloesen. Der
 * Endpunkt darf melden und senden, aber nicht schalten - sonst waere die
 * ganze Absicherung des Bots ueber einen HTTP-Aufruf zu umgehen.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen html/ und htmlauth/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     <home>/webfrontend/html/plugins/<ordner>/index.php
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/sg_lib.php
 *
 * dirname(__DIR__) ergab dort .../webfrontend/html/plugins - gesucht wurde
 * also .../webfrontend/html/plugins/htmlauth/sg_lib.php, und die gibt es
 * nicht. Der Endpunkt antwortete deshalb auf JEDEN Aufruf des Miniservers
 * mit HTTP 500 und leerem Rumpf: ini_set('display_errors', '0') weiter oben
 * unterdrueckt die Meldung. In Loxone sieht das aus wie "kein Wert" und
 * nicht wie ein Defekt - der virtuelle Eingang behaelt seinen letzten Stand,
 * in der App wirkt alles normal. Damit war die ganze Richtung
 * Loxone -> Chat tot: senden, zustand und status gleichermassen.
 *
 * bin/sg_bot.php hat dieselbe Klasse seit 0.9.10 geloest, hier stand sie
 * noch. Nachgemessen am nachgebauten Installationsstand am 16.08.2026:
 * "Failed opening required '.../webfrontend/html/plugins/htmlauth/sg_lib.php'".
 */
$sg_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
/* G14 (0.9.30): ZUERST der Archivkandidat (html/ und htmlauth/ nebeneinander) - in der Installation gibt es
 * ihn nie; eine Archivkopie im Webserver laedt so ihre eigene Bibliothek und nicht die produktive. */
$sg_kandidaten = array(dirname(__DIR__) . '/htmlauth/sg_lib.php');
$sg_lb = getenv('LBHOMEDIR');
if ($sg_lb) {
    $sg_kandidaten[] = $sg_lb . '/webfrontend/htmlauth/plugins/' . $sg_ordner . '/sg_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../webfrontend/html/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$sg_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';

$sg_lib = '';
foreach ($sg_kandidaten as $sg_kand) {
    if (is_file($sg_kand)) { $sg_lib = $sg_kand; break; }
}
if ($sg_lib === '') {
    /* Abweisen und sagen, woran es liegt - nicht schweigen. Die durchsuchten
     * Pfade gehen in das Fehlerprotokoll des Webservers, nicht in die
     * Antwort: der Aufrufer hat sich an dieser Stelle noch nicht ueber das
     * Token ausgewiesen. */
    error_log('SignalBot: sg_lib.php nicht gefunden. Gesucht wurde in: '
              . implode(', ', $sg_kandidaten));
    http_response_code(500);
    echo "SIGNAL;OK=0;GRUND=BIBLIOTHEK_FEHLT
";
    exit;
}
require_once $sg_lib;

function sg_ende($code, $text)
{
    http_response_code($code);
    echo rtrim($text) . "\n";
    exit;
}

/** Ein Parameter als Zeichenkette - eine Liste (name[]=x) zaehlt als fehlend. */
function sg_par($k)
{
    return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : '';
}

$sg_selftest = isset($_GET['selftest']) && sg_par('selftest') !== '0';

/* ---------------- Nichts anlegen (C4) ----------------
 *
 * Der unangemeldete Endpunkt legt nichts an und wuerfelt nichts. Bis 0.9.25
 * rief er sg_config() VOR der Token-Pruefung: auf einem LoxBerry ohne
 * Konfiguration (Neuinstallation, Upgrade-Luecke) legte schon ein Abruf mit
 * FALSCHEM Token Konfiguration und Zweitschrift an, und ein kaputtes Token
 * wurde neu gewuerfelt und geschrieben (gemessen). Jetzt: ohne lesbare
 * Konfiguration mit gueltigem Token 503, und gelesen wird ohne Schreiben. */
if (sg_config_lage() !== 'ok') {
    if ($sg_selftest) { sg_ende(503, 'SELFTEST;OK=0;ERR=NICHT_EINGERICHTET'); }
    sg_ende(503, 'SIGNAL;OK=0;GRUND=NICHT_EINGERICHTET');
}
$cfg = sg_config(false);

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
$ist  = sg_par('token');

/* Ein Token muss sich pruefen lassen, ohne dass etwas passiert.
 *
 * Ohne diesen Zweig gibt es nur zwei schlechte Moeglichkeiten: entweder man
 * schickt wirklich eine Nachricht an alle Erlaubten, oder man erfaehrt nie,
 * ob die Adresse im Miniserver noch stimmt. Beides ist unbrauchbar, wenn man
 * eine Anlage pruefen will.
 *
 *     ?selftest=1&token=<TOKEN>
 *     richtiges Token:  SELFTEST;OK=1;TOKEN=OK
 *     falsches Token:   HTTP 403, SELFTEST;OK=0;ERR=TOKEN
 */
if ($soll === '' || !hash_equals($soll, $ist)) {
    if ($sg_selftest) { sg_ende(403, 'SELFTEST;OK=0;ERR=TOKEN'); }
    sg_ende(403, 'SIGNAL;OK=0;GRUND=TOKEN');
}
if ($sg_selftest) {
    sg_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

/* endpunkt_ein (0.9.30, E-E8): aus = der GANZE Endpunkt fuer Loxone (status, senden, zustand, sperren,
 * entsperren); offen bleibt nur ?selftest=1 oben. Nach der Token-Pruefung, damit ein Fremder nicht
 * erfaehrt, ob der Endpunkt an ist. */
if (!sg_endpunkt_ein($cfg)) {
    sg_log_gebremst('endpunkt_aus', 'Endpunkt: Aufruf abgewiesen (endpunkt_ein = 0) - Loxone ueber das MQTT-Gateway V2 '
        . 'und die Befehlsbruecke anbinden, oder im Reiter Einbindung den HTTP-Endpunkt einschalten.');
    sg_ende(403, 'SIGNAL;OK=0;GRUND=ENDPUNKT_AUS');
}

$erlaubte_aktionen = array('senden', 'zustand', 'status', 'sperren', 'entsperren');
$aktion = isset($_GET['aktion']) ? sg_par('aktion') : 'status';
if (!in_array($aktion, $erlaubte_aktionen, true)) {
    sg_ende(400, "SIGNAL;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', $erlaubte_aktionen));
}

/* ---------------- status ----------------
 *
 * OK heisst seit dem Durchgang 01.10.2026: signal-cli antwortet UND der Bot
 * hat hoechstens vor SG_HERZ_GRENZE (180) Sekunden ein Lebenszeichen
 * abgelegt. Seit 0.9.30 schreibt der Bot das Lebenszeichen nur bei nutzbarem
 * Ereignisstrom - OK=1 heisst damit "Nachrichten kommen an". Die Zahlen kommen
 * aus sg_status_werte() - derselben Quelle wie die Themen ueber das
 * MQTT-Gateway V2; die Zeile ist byte-gleich zu 0.9.29. */
if ($aktion === 'status') {
    echo sg_status_zeile(sg_status_werte($cfg, true)) . "\n";
    exit;
}

/* ---------------- sperren / entsperren / zustand / senden ----------------
 *
 * Seit 0.9.30 EIN Ablauf fuer Endpunkt und Befehlsbruecke: sg_befehl_ausfuehren()
 * (Quelle 'http' = wie bis 0.9.29: sofort senden, mit Bild sg_senden(); die
 * Antworten sind byte-gleich). Ein gesetztes, aber unbrauchbares &an= (auch
 * eine Liste an[]=) wird abgewiesen; ein LEERES &an= bleibt der Rundruf. */
if ($aktion === 'zustand') {
    $sg_par = array('name' => sg_par('name'), 'wert' => sg_par('wert'));
} elseif ($aktion === 'senden') {
    $sg_par = array(
        'text'     => sg_par('text'),
        'an_roh'   => isset($_GET['an']) ? (is_string($_GET['an']) ? trim($_GET['an']) : 'ungueltig') : '',
        'dringend' => (isset($_GET['dringend']) && sg_par('dringend') !== '0') ? 1 : 0,
        'bild'     => isset($_GET['bild']) ? sg_par('bild') : '',
    );
} else {
    $sg_par = array();
}
list($sg_code, $sg_zeile) = sg_befehl_ausfuehren($aktion, $sg_par, 'http');
sg_ende($sg_code, $sg_zeile);
