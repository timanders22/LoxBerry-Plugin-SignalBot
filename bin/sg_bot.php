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
/* G14 (0.9.30, Bauart aWATTar/Saugroboter): ZUERST der Archivkandidat - bin/ und webfrontend/ nebeneinander.
 * In der Installation gibt es ihn nie; eine Archivkopie mit gesetztem LBHOMEDIR laedt so ihre eigene
 * Bibliothek und nicht die produktive. */
$sg_kandidaten = array(dirname(__DIR__) . '/webfrontend/htmlauth/sg_lib.php');
if ($sg_lb) {
    $sg_kandidaten[] = $sg_lb . '/webfrontend/htmlauth/plugins/' . $sg_ordner . '/sg_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../bin/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$sg_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';

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

/* Nie als root (G11, Haertung; 0.9.30): bot.lock, herz und die Warteschlange gehoerten sonst root, und der
 * Wecker (als loxberry) kaeme nicht mehr an sie heran. */
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "SignalBot: nicht als root starten - der Bot laeuft als loxberry (Wecker cron.01min).\n");
    exit(1);
}

$modus = isset($argv[1]) ? (string) $argv[1] : 'dauer';
if (!in_array($modus, array('dauer', 'einmal', 'test'), true)) {
    fwrite(STDERR, "Aufruf: sg_bot.php [einmal | test <+49...> <text>]\n");
    exit(2);
}

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
/* 'ce' (G12, 0.9.30): close-on-exec - ein Kindprozess des Bots (sudo -n systemctl restart der Selbstheilung)
 * erbt die Sperre nicht (Gedaechtnis sperre-vererbt-sich-an-kinder). */
$fh = @fopen($sperre, 'ce');
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
/* Das Gateway-Abo (V1-Rueckfall) auf den aktuellen Praefix bringen (M9, sg_abo_datei). Seit 0.9.30 KEIN
 * Lebenszeichen beim Start: erst ein nutzbarer Ereignisstrom schreibt es (Entscheidung 1, eigener Fund). */
sg_abo_datei($cfg['mqtt_topic'], true);
/* G4 (E-E11, 0.9.30): eine Klartext-PIN aus einer Fassung vor 0.9.12, deren Einstellungen seitdem nie
 * gespeichert wurden, einmal beim Start in einen Hash ueberfuehren. */
$sg_pin_neu = false;
sg_config_aendern(function ($c) use (&$sg_pin_neu) {
    list($n, $g) = sg_pin_migrieren($c);
    $sg_pin_neu = $g;
    return $g ? $n : null;
});
if ($sg_pin_neu) { sg_log('Klartext-PIN in einen Hash ueberfuehrt (G4).'); }

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
    /* Ereignisformat (gemessen am Geraet 27.10.2026, eigener Mitschnitt von GET <rpc_url>/api/v1/events): signal-cli
     * im HTTP-Betrieb schickt je SSE-Zeile den Umschlag OHNE JSON-RPC-Huelle - {"envelope": {...}, "account": "<konto>"}.
     * Bis zum Nachbau 27.10. verlangte diese Funktion method = 'receive' und verwarf damit JEDE Zeile des echten Stroms
     * (weder "Notiz an mich" noch Nachrichten anderer Absender kamen an); die Attrappen sendeten die JSON-RPC-Form.
     * Angenommen werden jetzt drei Formen:
     *   (a) {"envelope": {...}, "account": "..."}                      SSE von signal-cli HTTP (gemessen)
     *   (b) {"method": "receive", "params": {"envelope": {...}}}      JSON-RPC-Benachrichtigung
     *   (c) {"method": "receive", "params": {"result": {"envelope": {...}}}}   in ein Abonnement verpackt */
    $sg_strom_konto = '';
    if (isset($d['envelope'])) {
        $env = $d['envelope'];
        if (isset($d['account']) && is_string($d['account'])) { $sg_strom_konto = $d['account']; }
    } elseif (isset($d['method']) && $d['method'] === 'receive') {
        $par = (isset($d['params']) && is_array($d['params'])) ? $d['params'] : array();
        $env = isset($par['envelope']) ? $par['envelope']
             : (isset($par['result']['envelope']) ? $par['result']['envelope'] : null);
        if (isset($par['account']) && is_string($par['account'])) { $sg_strom_konto = $par['account']; }
        elseif (isset($par['result']['account']) && is_string($par['result']['account'])) { $sg_strom_konto = $par['result']['account']; }
    } else {
        return;
    }
    if (!is_array($env)) { return; }
    /* Meldet die Zeile ein Konto und ist eines eingestellt, muss es dasselbe sein - ein signal-cli mit mehreren
     * Konten liefert sonst Nachrichten an ein fremdes Konto; die bleiben still (Protokoll gebremst, ohne Nummer). */
    if ($sg_strom_konto !== '') {
        $sg_cfg_strom = sg_config();
        $sg_mein = is_string($sg_cfg_strom['konto']) ? $sg_cfg_strom['konto'] : '';
        if ($sg_mein !== '' && !hash_equals($sg_mein, $sg_strom_konto)) {
            sg_log_gebremst('strom_fremdes_konto', 'Ereignis fuer ein anderes Konto im Strom - nicht ausgewertet.', 600);
            return;
        }
    }
    /* Abgleich (syncMessage): gemessen am Geraet 27.10. - laeuft der Bot als verknuepftes Geraet unter der
     * eigenen Nummer, kommt "Notiz an mich" so an (Quelle und Ziel = eigenes Konto). Ausgewertet wird nur das
     * (sg_notiz_pruefen: Haken notiz_ein, eigene Nummer erlaubt, keine Gruppe, kein Dritter, keine eigene
     * Sendung des Bots); jeder andere Abgleich - an Dritte, an Gruppen, Lesebestaetigungen - bleibt still,
     * sonst antwortete der Bot auf sich selbst. */
    if (isset($env['syncMessage'])) {
        $sg_n = sg_notiz_pruefen($env);
        if (!$sg_n['ok']) {
            if ($sg_n['grund'] === 'aus' || $sg_n['grund'] === 'nicht_erlaubt') {
                sg_log_gebremst('notiz_' . $sg_n['grund'], 'Notiz an mich uebergangen: ' . ($sg_n['grund'] === 'aus'
                    ? 'Haken "Notiz an mich als Befehlsweg" ist aus.'
                    : 'die eigene Kontonummer steht nicht unter den erlaubten Absendern.'));
            } elseif ($sg_n['grund'] === 'eigene') {
                sg_log_gebremst('notiz_eigene', 'Eigene Sendung des Bots als Abgleich zurueck - nicht ausgewertet.');
            }
            return;
        }
        sg_notiz_merken($sg_n['geraet']);
        sg_log('Notiz an mich (' . sg_maske($sg_n['von']) . '): ' . sg_kuerzen($sg_n['text'], 40));
        $GLOBALS['sg_notiz_quelle'] = true;
        try {
            $erg = sg_verarbeite($sg_n['von'], $sg_n['text']);
        } finally {
            $GLOBALS['sg_notiz_quelle'] = false;
        }
        if ($erg['antwort'] !== '') {
            sg_senden($sg_n['von'], $erg['antwort']);
        }
        return;
    }
    // Sonst nur echte Textnachrichten. Lesebestaetigungen und Tippanzeigen werden uebergangen.
    if (!isset($env['dataMessage']['message'])) { return; }
    // Aus Gruppen nur die Quittungswoerter, alles andere still (0.9.29).
    if (!sg_nachricht_beachten($env['dataMessage'])) { return; }
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
 *   1. LEBENSZEICHEN fuer den Endpunkt: <tmp>/herz bekommt den Zeitstempel -
 *      seit 0.9.30 NUR, solange der Ereignisstrom nutzbar ist (G13). Daraus
 *      meldet der Endpunkt BOT und ALTER, und OK=0, wenn es aelter als 180 s
 *      ist (Entscheidung 4). Seit 0.9.30 dazu einmal je 60 s der Status ueber
 *      das MQTT-Gateway V2 (status/zaehler, status/ok ...; sg_mqtt_status).
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
    static $letzter = 0, $sg_status_letzt = 0, $sg_voll_letzt = 0;
    $cfg = sg_config();
    $jetzt = time();
    $sg_nutzbar = !empty($GLOBALS['sg_strom_nutzbar']);
    /* Lebenszeichen nur bei nutzbarem Ereignisstrom (0.9.30, Entscheidung 1). */
    if ($sg_nutzbar) { sg_herz_schreiben(); }
    if ($jetzt - $sg_status_letzt >= 60) {
        $sg_status_letzt = $jetzt;
        /* Status ueber das MQTT-Gateway V2: voll nach dem Start und alle 30 min, sonst Aenderungen und das
         * Lebenszeichen (Entwurf 2.4). */
        $sg_voll = ($jetzt - $sg_voll_letzt >= SG_STATUS_VOLL);
        if ($sg_voll) { $sg_voll_letzt = $jetzt; }
        sg_mqtt_status($cfg, $sg_nutzbar, $sg_voll);
        sg_gaeste_takt();
        sg_anhang_aufraeumen();
    }
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
    /* G13 (0.9.30): eine Verbindung zaehlt erst als Erfolg, wenn eine Datenzeile kam oder sie >= 10 s steht.
     * Antwortet signal-cli mit 200 und schliesst sofort (kein Konto, Dienst startet, Proxy), war das bis
     * 0.9.29 ein "Erfolg" mit 2 s Pause - Dauerschleife ohne Protokollzeile und mit frischem Lebenszeichen. */
    $beginn = time();
    $GLOBALS['sg_strom_nutzbar'] = false;
    while (!feof($fp)) {
        if ($ende && time() > $ende) { break; }
        $zeile = fgets($fp);
        if (time() - $beginn >= 10) { $GLOBALS['sg_strom_nutzbar'] = true; }
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
        if (strpos($zeile, 'data:') === 0) { $GLOBALS['sg_strom_nutzbar'] = true; }
        $zeile = trim($zeile);
        if ($zeile === '' || strpos($zeile, 'data:') !== 0) { continue; }
        sg_strom_zeile(trim(substr($zeile, 5)));
    }
    fclose($fp);
    $nutzbar = !empty($GLOBALS['sg_strom_nutzbar']) || (time() - $beginn >= 10);
    $GLOBALS['sg_strom_nutzbar'] = false;
    if (!$nutzbar) {
        sg_log_gebremst('strom_sofort_zu', 'Ereignisstrom schliesst sofort nach dem Kopf (kein Konto, signal-cli startet, '
            . 'Proxy?) - gilt als Fehlschlag.', 600);
    }
    return $nutzbar;
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
