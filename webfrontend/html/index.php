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
$sg_kandidaten = array();
$sg_lb = getenv('LBHOMEDIR');
if ($sg_lb) {
    $sg_kandidaten[] = $sg_lb . '/webfrontend/htmlauth/plugins/' . $sg_ordner . '/sg_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../webfrontend/html/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$sg_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';
// entpacktes Archiv: html/ und htmlauth/ liegen nebeneinander
$sg_kandidaten[] = dirname(__DIR__) . '/htmlauth/sg_lib.php';

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

$cfg = sg_config();

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';

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
$sg_selftest = isset($_GET['selftest']) && (string) $_GET['selftest'] !== '0';

if ($soll === '' || !hash_equals($soll, $ist)) {
    if ($sg_selftest) { sg_ende(403, 'SELFTEST;OK=0;ERR=TOKEN'); }
    sg_ende(403, 'SIGNAL;OK=0;GRUND=TOKEN');
}
if ($sg_selftest) {
    sg_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

$erlaubte_aktionen = array('senden', 'zustand', 'status', 'sperren', 'entsperren');
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, $erlaubte_aktionen, true)) {
    sg_ende(400, "SIGNAL;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', $erlaubte_aktionen));
}

/* ---------------- status ---------------- */
if ($aktion === 'status') {
    $z = sg_zustaende();
    printf("SIGNAL;OK=%d;DAEMON=%d;KONTO=%d;ERLAUBTE=%d;BEFEHLE=%d;ZUSTAENDE=%d;GESPERRT=%d;OFFEN=%d;LETZTER=%d;ABGEWIESEN=%d;PINFEHL=%d\n",
        sg_daemon_lebt() ? 1 : 0,
        sg_dienst_laeuft() ? 1 : 0,
        (string) $cfg['konto'] !== '' ? 1 : 0,
        count($cfg['erlaubt']),
        count(array_filter($cfg['befehle'], function ($b) { return !empty($b['aktiv']) && $b['wort'] !== ''; })),
        count($z),
        !empty($cfg['gesperrt']) ? 1 : 0,
        sg_offene_meldungen(),
        sg_letzter_befehl(),
        sg_zaehle_ereignisse('abgewiesen'),
        sg_zaehle_ereignisse('PIN falsch'));
    exit;
}

/* ---------------- sperren / entsperren ----------------
 *
 * Der Kill-Schalter fuer den Fall, dass ein Handy abhandenkommt. Ohne ihn
 * muesste man erst die LoxBerry-Oberflaeche oeffnen; so genuegt ein Taster
 * oder ein Baustein im Miniserver. */
if ($aktion === 'sperren' || $aktion === 'entsperren') {
    $neu = $aktion === 'sperren' ? 1 : 0;
    if ((int) $cfg['gesperrt'] !== $neu) {
        $cfg['gesperrt'] = $neu;
        if (!sg_config_write($cfg)) {
            sg_ende(500, 'SIGNAL;OK=0;GRUND=NICHT_GESPEICHERT');
        }
        sg_log($neu ? 'Bot ueber den Endpunkt GESPERRT.' : 'Bot ueber den Endpunkt entsperrt.');
        sg_ereignis_merken('Loxone', $neu ? 'gesperrt' : 'entsperrt', '');
    }
    printf("SIGNAL;OK=1;AKTION=%s;GESPERRT=%d\n", $aktion, $neu);
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
    if (sg_laenge($wert) > 120) { $wert = sg_kuerzen($wert, 120); }
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
if (sg_laenge($text) > 2000) { $text = sg_kuerzen($text, 2000); }

/* Ein gesetztes, aber unbrauchbares &an= wird ABGEWIESEN.
 *
 * Bis 0.9.11 fiel es hier durch preg_replace auf '' und landete im
 * else-Zweig: aus einem Tippfehler in der Loxone-Adresse ("&an=Papa") wurde
 * ein Rundruf an ALLE freigegebenen Nummern, und die Antwort lautete
 * OK=1;EMPFAENGER=4. Eine vertrauliche Meldung ging damit an den ganzen
 * Haushalt, ohne dass irgendetwas darauf hinwies.
 *
 * Ein LEERES &an= bleibt der ausdrueckliche Rundruf - dieser Fall steht so
 * in der Anleitung und in bestehenden Projektdateien. */
$sg_an_roh = isset($_GET['an']) ? trim((string) $_GET['an']) : '';
$an = preg_replace('/[^0-9+]/', '', $sg_an_roh);
if ($sg_an_roh !== '' && !preg_match('/^\+[0-9]{6,20}$/', $an)) {
    sg_log('Senden abgewiesen: "' . sg_maske($sg_an_roh) . '" ist keine gueltige Rufnummer');
    sg_ende(400, 'SIGNAL;OK=0;GRUND=EMPFAENGER_UNGUELTIG');
}
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

/* Dringend? Dann geht die Meldung durch die Nachtruhe hindurch und wird
 * wiederholt, bis jemand "quittiert" schreibt. */
$dringend = isset($_GET['dringend']) && (string) $_GET['dringend'] !== '0' ? 1 : 0;

/* Ein Anhang - etwa der Kamerabild-Schnappschuss zum Alarm. Nur Pfade
 * unterhalb der Datenordner des Plugins und der ueblichen Ablagen sind
 * zugelassen; ein Endpunkt im unangemeldeten Bereich darf nicht zum
 * Dateibetrachter fuer das ganze Geraet werden. */
$anhang = '';
if (isset($_GET['bild']) && (string) $_GET['bild'] !== '') {
    $roh = (string) $_GET['bild'];
    $echt = realpath($roh);
    $erlaubte_orte = array(realpath(sg_datadir()), realpath('/tmp'), realpath('/var/tmp'));
    $drin = false;
    foreach ($erlaubte_orte as $ort) {
        if ($ort && $echt && strpos($echt, $ort) === 0) { $drin = true; break; }
    }
    if (!$echt || !$drin || !is_file($echt)) {
        sg_ende(400, 'SIGNAL;OK=0;GRUND=ANHANG_UNZULAESSIG');
    }
    $anhang = $echt;
}

$ok = 0;
foreach ($ziele as $ziel) {
    if ($anhang !== '') {
        // Mit Anhang wird unmittelbar gesendet - eine Bilddatei in die
        // Warteschlange zu legen hiesse, auf eine Datei zu warten, die es
        // spaeter vielleicht nicht mehr gibt.
        if (sg_senden($ziel, $text, $anhang)) { $ok++; }
    } else {
        $art = sg_melden($ziel, $text, $dringend);
        if ($art !== 'fehler') { $ok++; }
    }
}
sg_log('Meldung aus Loxone an ' . $ok . ' von ' . count($ziele) . ' Empfaenger'
     . ($dringend ? ' (dringend)' : '') . ($anhang !== '' ? ' (mit Anhang)' : ''));
if ($ok === 0) {
    sg_ende(502, 'SIGNAL;OK=0;GRUND=SENDEN_FEHLGESCHLAGEN');
}
printf("SIGNAL;OK=1;AKTION=senden;EMPFAENGER=%d;DRINGEND=%d\n", $ok, $dringend);
