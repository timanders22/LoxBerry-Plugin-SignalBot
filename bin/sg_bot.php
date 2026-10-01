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
 *                                          und ohne dass etwas geschaltet wird
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     /opt/loxberry/bin/plugins/<ordner>/sg_bot.php
 *     /opt/loxberry/webfrontend/htmlauth/plugins/<ordner>/sg_lib.php
 *
 * dirname(__DIR__) ergibt dort /opt/loxberry/bin/plugins - gesucht wurde also
 * /opt/loxberry/bin/plugins/webfrontend/htmlauth/sg_lib.php. Die gibt es nicht: der
 * Dienst brach bei JEDEM Cron-Lauf mit einem fatalen Fehler ab, und weil die
 * Cron-Zeile nach /dev/null schreibt, stand das nirgends.
 *
 * Gefunden am 16.08.2026 mit Werkzeuge/installationslage_pruefen.py, nachdem
 * dieselbe Zeile den Hintergrunddienst des Abfahrts-Assistenten von 1.5.0 bis
 * 1.5.7 lahmgelegt hatte.
 */
$sg_lb = getenv('LBHOMEDIR');
$sg_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
$sg_kandidaten = array();
if ($sg_lb) {
    $sg_kandidaten[] = $sg_lb . '/webfrontend/htmlauth/plugins/' . $sg_ordner . '/sg_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../bin/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$sg_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';
// entpacktes Archiv: bin/ und webfrontend/ liegen nebeneinander
$sg_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/sg_lib.php';

$sg_lib = '';
foreach ($sg_kandidaten as $sg_kand) {
    if (is_file($sg_kand)) { $sg_lib = $sg_kand; break; }
}
if ($sg_lib === '') {
    fwrite(STDERR, "SignalBot: sg_lib.php nicht gefunden. Gesucht wurde in:\n");
    foreach ($sg_kandidaten as $sg_kand) { fwrite(STDERR, '  ' . $sg_kand . "\n"); }
    exit(1);
}
require_once $sg_lib;

$modus = isset($argv[1]) ? (string) $argv[1] : 'dauer';

/* ---- Trockenlauf: eine Nachricht durchspielen ---- */
if ($modus === 'test') {
    $von = isset($argv[2]) ? (string) $argv[2] : '';
    $text = isset($argv[3]) ? (string) $argv[3] : '';
    // Drittes Argument: Trockenlauf. Der Testzweig zeigt nur, was geschehen
    // wuerde - er schaltet nicht. Der echte Empfang unten laeuft ohne.
    $erg = sg_verarbeite($von, $text, true);
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
/* Die eigene Prozessnummer in die Sperrdatei schreiben.
 *
 * Ohne sie kann niemand den laufenden Bot gezielt beenden: das Update tauscht
 * die Dateien unter ihm aus, der alte Prozess haelt die Sperre weiter, und
 * der naechste Cron-Aufruf endet mit "laeuft schon". postinstall.sh und das
 * uninstall-Skript lesen die Nummer hier heraus - und pruefen vor dem Beenden
 * in /proc, dass sie wirklich zu sg_bot.php gehoert. */
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, (string) getmypid() . "\n");
fflush($fh);

$cfg = sg_config();
$ende = ($modus === 'einmal') ? time() + 30 : 0;   // 0 = ohne Ende

sg_log('Bot gestartet' . ($ende ? ' (Testlauf, 30 s)' : ''));
/* Das Gateway-Abo auf den aktuellen Praefix bringen (M9, sg_abo_datei) und
 * gleich ein erstes Lebenszeichen ablegen (C2). */
sg_abo_datei($cfg['mqtt_topic'], true);
sg_herz_schreiben();

/** Eine Zeile des Ereignisstroms auswerten und gegebenenfalls antworten.
 *
 * Hiess bis 0.9.12 sg_ereignis() - denselben Namen traegt seither die
 * Protokollfunktion in sg_lib.php, und beide Dateien treffen sich im selben
 * Prozess. Der Dauerlaeufer starb dann sofort mit "Cannot redeclare".
 * Gefunden von installationslage_pruefen.py. */
function sg_strom_zeile($json)
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

/**
 * Der Takt des Bots - NACH DER UHR (C1, M1).
 *
 * Drei Aufgaben:
 *
 *   1. LEBENSZEICHEN fuer den Endpunkt: <tmp>/herz bekommt in jedem Takt den
 *      Zeitstempel. Daraus meldet der Endpunkt BOT und ALTER, und OK=0, wenn
 *      es aelter als 180 s ist (Entscheidung 4).
 *   2. HERZSCHLAG auf MQTT: <praefix>/online mit dem Zeitstempel, hoechstens
 *      einmal je 60 s. In Loxone wird daraus mit I1-I2 (aktuelle Zeit minus
 *      online) und einem Vergleicher eine echte Ausfallmeldung.
 *   3. WARTESCHLANGE: Meldungen aus der Nachtruhe und unquittierte dringende.
 *
 * Bis 0.9.25 lief der Takt nur, wenn der Ereignisstrom 20 s lang schwieg.
 * Kam oefter irgendeine Zeile - Wachhaltezeilen des Servers, Lesebestaetigungen,
 * Tippanzeigen -, liefen Herzschlag und Warteschlange NIE (gemessen mit
 * Attrappen: Kommentarzeile alle 15 s oder Datenzeile alle 10 s -> 0
 * Herzschlaege in 95 s, die Meldung aus der Nachtruhe blieb liegen). Im
 * Ruhefall lag der Takt bei etwa 78 s statt "jede Minute".
 */
$sg_takt_letzt = 0;

function sg_takt()
{
    static $letzter = 0;
    $cfg = sg_config();
    $jetzt = time();
    sg_herz_schreiben();
    if (!empty($cfg['herzschlag']) && $jetzt - $letzter >= 60) {
        $letzter = $jetzt;
        sg_mqtt_pulsen('online', (string) $jetzt);
    }
    sg_warteschlange_arbeiten();
}

/** Den Takt rufen, sobald er faellig ist - hoechstens alle 10 s. */
function sg_takt_wenn_faellig()
{
    global $sg_takt_letzt;
    if (time() - $sg_takt_letzt >= 10) {
        $sg_takt_letzt = time();
        sg_takt();
    }
}

/** Einmal am Ereignisstrom hoeren, bis er abreisst. */
function sg_lauschen($ende)
{
    $cfg = sg_config();
    $url = $cfg['rpc_url'] . '/api/v1/events';
    /* Zehn Sekunden Zeitgrenze: so kommt die Schleife auch bei voelliger
     * Stille spaetestens alle 10 s an der Uhr vorbei. fgets blockiert nach
     * einer Zeitgrenze erneut, der Socket bleibt brauchbar (seit 0.9.0 in
     * PHP 7.4 und 8.1 gemessen). */
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET',
        'timeout' => 10,
        'header' => "Accept: text/event-stream\r\nUser-Agent: LoxBerry-SignalBot",
    )));
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) { return false; }
    stream_set_timeout($fp, 10);
    $letztes_byte = time();
    while (!feof($fp)) {
        if ($ende && time() > $ende) { break; }
        $zeile = fgets($fp);
        /* Nach JEDER Rueckkehr - Zeile oder Zeitgrenze - die Uhr fragen. */
        sg_takt_wenn_faellig();
        if ($zeile === false) {
            $st = stream_get_meta_data($fp);
            if (!empty($st['timed_out'])) {
                if (time() - $letztes_byte < 300) { continue; }
                /* Neu verbunden wird nach fuenf Minuten ohne ein einziges Byte:
                 * der Strom kann tot sein, ohne dass das Betriebssystem es
                 * merkt (signal-cli neu gestartet, Container weg, NAT-Tabelle
                 * abgelaufen). Gezaehlt wird die Zeit, nicht die Zahl der
                 * stillen Runden - bis 0.9.25 wurde der Zaehler nie
                 * zurueckgesetzt, und eine Zeile zwischendurch verschob die
                 * Neuverbindung nicht. */
                sg_log_gebremst('strom_still',
                    'Ereignisstrom fuenf Minuten still - Verbindung wird erneuert.');
                break;
            }
            break;
        }
        $letztes_byte = time();
        $zeile = trim($zeile);
        if ($zeile === '' || strpos($zeile, 'data:') !== 0) { continue; }
        sg_strom_zeile(trim(substr($zeile, 5)));
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
        /* Selbstheilung.
         *
         * Nach fuenf vergeblichen Anlaeufen wird der signal-cli-Dienst einmal
         * neu gestartet - die sudo-Regel dafuer legt postroot.sh ohnehin an.
         * Danach hoechstens noch einmal je halber Stunde, damit daraus keine
         * Startschleife wird. Ohne das klopft der Bot stundenlang an eine
         * Tuer, hinter der niemand mehr ist, und niemand merkt es. */
        if (($fehler_folge === 5 || ($fehler_folge > 5 && $fehler_folge % 180 === 0))
            && sg_nativ_fehlt()) {
            /* Ohne libsignal_jni.so ist ein Neustart keine Heilung, sondern der
             * Anstoss zu einer Absturzschleife: bis 0.9.21 lief der Dienst
             * danach bis zum naechsten Eingriff alle zwanzig Sekunden an und
             * stuerzte wieder ab (gemessen am 17.09.2026). */
            sg_log_gebremst('nativ_fehlt', 'Selbstheilung ausgesetzt: libsignal_jni.so fehlt in '
                . sg_nativ_ordner() . ' - Reiter Test, Knopf "Bibliothek libsignal holen".', 21600);
        } elseif ($fehler_folge === 5 || ($fehler_folge > 5 && $fehler_folge % 180 === 0)) {
            $sg_marke = sg_tmpdir() . '/dienst_neustart';
            $sg_letzt = is_file($sg_marke) ? (int) @file_get_contents($sg_marke) : 0;
            if (time() - $sg_letzt > 1800) {
                @file_put_contents($sg_marke, (string) time());
                sg_log('Selbstheilung: signal-cli-Dienst wird neu gestartet.');
                list($sg_ok, $sg_txt) = sg_dienst('restart');
                sg_log('Selbstheilung: ' . ($sg_ok ? 'Neustart angestossen.' : 'Neustart misslungen: ' . $sg_txt));
            }
        }
        $pause = min(60, 5 * $fehler_folge);
        for ($i = 0; $i < $pause; $i++) {
            if ($ende && time() > $ende) { break 2; }
            sleep(1);
            // Auch ohne Ereignisstrom weiterarbeiten: Lebenszeichen und
            // Warteschlange laufen nach der Uhr weiter (C1).
            sg_takt_wenn_faellig();
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
