<?php
/**
 * Signal Bot fuer LoxBerry - gemeinsame Bibliothek
 *
 * Ein Bot, dem man per Signal-Chat Befehle schicken kann, und der Meldungen
 * aus Loxone als Chat-Nachricht zustellt. Beide Richtungen.
 *
 * WARUM DIE ABSICHERUNG HIER SO VIEL PLATZ EINNIMMT
 * Ein Push-Plugin, das nur sendet, kann im schlimmsten Fall nerven. Ein Bot,
 * der die Alarmanlage unscharf schalten kann, ist ein Schluessel zum Haus -
 * und er liegt in einem Chat, der auf einem Handy in fremde Haende geraten
 * kann. Deshalb drei Schichten, die unabhaengig voneinander greifen:
 *
 *   1. Weissliste  Wer nicht daraufsteht, bekommt KEINE Antwort. Nicht
 *                  einmal ein "unbekannter Absender" - Fremde sollen nicht
 *                  erfahren, dass es hier einen Bot gibt.
 *   2. Stufe       Je Befehl: sofort, Rueckfrage oder PIN. Licht anschalten
 *                  ist etwas anderes als das Tor oeffnen.
 *   3. Bremse      Hoechstens X Befehle je Minute und Absender. Wer eine PIN
 *                  raten will, hat nur wenige Versuche, danach ist Ruhe.
 *
 * SIGNAL-ANBINDUNG
 * signal-cli laeuft als Dienst mit JSON-RPC ueber HTTP:
 *   POST /api/v1/rpc     Befehle absetzen (senden, verknuepfen)
 *   GET  /api/v1/events  Ereignisstrom (SSE) mit eingehenden Nachrichten
 *   GET  /api/v1/check   Lebenszeichen
 * Das Konto wird als ZWEITGERAET verknuepft, so wie Signal Desktop - eine
 * eigene Rufnummer ist nicht noetig.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Hoechstzahl der Befehlszeilen in der Tabelle. */
define('SG_BEFEHLE', 20);
/** So lange gilt eine Rueckfrage oder eine PIN-Anforderung (Sekunden). */
define('SG_WARTEZEIT', 90);

/* ==================================================================
 * Pfade und Protokoll
 * ================================================================== */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function sg_paths()
{
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) {
        /* Installiert liegt diese Datei in
         *     <home>/webfrontend/htmlauth/plugins/<ordner>/sg_lib.php
         * dort ist basename(__DIR__) der Ordnername. Im entpackten Archiv
         * liegt sie in webfrontend/htmlauth/, dort ist es der Ordner drei
         * Ebenen hoeher.
         *
         * Bis 0.9.11 stand hier nur der zweite Fall. Installiert ergab er
         * 'htmlauth'; gerettet hat das allein der feste Rueckfallwert
         * 'signalbot' - der bricht, sobald LoxBerry wegen einer
         * Namenskollision auf einen anderen Ordner ausweicht. Und er haette
         * den Ordner eines fremden Plugins benutzt, wenn es je ein
         * config/plugins/htmlauth gaebe.
         */
        $plugin = '';
        foreach (array(basename(__DIR__), basename(dirname(dirname(__DIR__)))) as $sg_kand) {
            if ($sg_kand === '' || in_array($sg_kand, array('htmlauth', 'html', 'plugins', 'webfrontend'), true)) { continue; }
            if (!$home || is_dir($home . '/config/plugins/' . $sg_kand)) { $plugin = $sg_kand; break; }
        }
        if ($plugin === '') { $plugin = 'signalbot'; }
    }
    if ($home) {
        return array(
            'home' => $home, 'plugin' => $plugin,
            'config'    => $home . '/config/plugins/' . $plugin . '/signalbot.json',
            /* Die Zweitschrift liegt NEBEN dem Plugin-Ordner, nicht darin.
             * LoxBerry entfernt config/plugins/<ordner>/ bei Deinstallation
             * und Neuinstallation - eine Sicherung im Ordner stirbt also
             * genau in dem Fall mit, fuer den es sie gibt. So halten es auch
             * Weissware, Kodi und die uebrigen 18 Linien mit Zweitschrift.
             * 'sicherung_alt' ist der frueher benutzte Ort; er wird beim
             * Heilen weiter gelesen, damit bestehende Anlagen ihre
             * vorhandene Sicherung nicht verlieren. */
            'sicherung' => $home . '/config/plugins/' . $plugin . '.backup.signalbot.json',
            'sicherung_alt' => $home . '/config/plugins/' . $plugin . '/signalbot.backup.json',
            'configdir' => $home . '/config/plugins/' . $plugin,
            'data'      => $home . '/data/plugins/' . $plugin,
            'log'       => $home . '/log/plugins/' . $plugin . '/signalbot.log',
            'tmp'       => '/tmp/' . $plugin,
        );
    }
    $eigen = dirname(dirname(__DIR__));
    return array('home' => '', 'plugin' => 'signalbot',
        'config' => $eigen . '/config/signalbot.json',
        'sicherung' => $eigen . '/config/signalbot.backup.json',
        'sicherung_alt' => $eigen . '/config/signalbot.backup.json',
        'configdir' => $eigen . '/config',
        'data' => sys_get_temp_dir() . '/signalbot',
        'log' => sys_get_temp_dir() . '/signalbot/signalbot.log',
        'tmp' => sys_get_temp_dir() . '/signalbot');
}

function sg_tmpdir()
{
    $p = sg_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    return $p['tmp'];
}

function sg_datadir()
{
    $p = sg_paths();
    if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
    return $p['data'];
}

/**
 * Das Protokoll auf einen Inhalt setzen - Leeren und Kuerzen laufen hier durch.
 *
 * Mit Sperre, weil bei diesem Plugin ein DAUERLAEUFER an derselben Datei
 * haengt: sg_bot.php schreibt jede eingehende Nachricht mit. Anhaengen mit
 * FILE_APPEND kann keine Zeile zerreissen (O_APPEND), aber wer zwischen dem
 * Lesen des Endstuecks und dem Zurueckschreiben anhaengt, schreibt in eine
 * Datei, die gleich ueberschrieben wird.
 *
 * ftruncate statt Neuanlage: dieselbe Datei, dieselbe Inode - wer sie offen
 * hat, schreibt weiter hinein statt in eine geloeschte Leiche.
 */
function sg_log_setzen($datei, $inhalt)
{
    $fp = @fopen($datei, 'c+');
    if (!$fp) { return false; }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $inhalt);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function sg_log($text)
{
    $p = sg_paths();
    $d = dirname($p['log']);
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        sg_log_setzen($p['log'], implode("\n", array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -300)) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Dieselbe Meldung hoechstens einmal je Zeitfenster.
 *
 * Der Bot laeuft dauerhaft; eine Meldung, die bei jeder stillen Runde
 * geschrieben wird, fuellt das Protokoll mit immer derselben Zeile und
 * verdeckt damit alles andere.
 */
function sg_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = sg_tmpdir() . '/meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        sg_log($text);
    }
}

function sg_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ==================================================================
 * Text in UTF-8 - ohne sich auf mbstring zu verlassen
 *
 * Bis 0.9.0 riefen fuenf Stellen mb_strtolower/mb_strlen/mb_substr direkt
 * auf. Fehlt php-mbstring, ist das kein Schoenheitsfehler, sondern ein
 * "Call to undefined function": weisse Seite in der Oberflaeche UND ein
 * toter Endpunkt - Loxone bekaeme auf jede Meldung eine leere Antwort.
 * Nachgestellt mit einem PHP ohne mbstring: genau das passiert.
 *
 * php-mbstring steht jetzt in dpkg/apt. Die Ersatzwege bleiben trotzdem:
 * Laenge und Ausschnitt brauchen mbstring gar nicht, das kann PCRE mit dem
 * Schalter /u genauso. Und wenn die Nachinstallation einmal scheitert,
 * bleibt der Bot benutzbar, statt stumm zu sein.
 * ================================================================== */

/**
 * Kleinschreibung fuer den Wortvergleich.
 * Ohne mbstring bleibt strtolower, das nur ASCII kann - deshalb der kleine
 * Zusatz fuer die Buchstaben, die hier tatsaechlich vorkommen koennen.
 */
function sg_klein($s)
{
    $s = (string) $s;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $gross = array('Ä','Ö','Ü','À','Á','Â','Ã','Å','Æ','Ç','È','É','Ê','Ë',
                   'Ì','Í','Î','Ï','Ñ','Ò','Ó','Ô','Õ','Ø','Ù','Ú','Û','Ý');
    $klein = array('ä','ö','ü','à','á','â','ã','å','æ','ç','è','é','ê','ë',
                   'ì','í','î','ï','ñ','ò','ó','ô','õ','ø','ù','ú','û','ý');
    return strtolower(str_replace($gross, $klein, $s));
}

/** Laenge in Zeichen, nicht in Bytes. */
function sg_laenge($s)
{
    return preg_match_all('/./us', (string) $s);
}

/** Die ersten $n Zeichen - schneidet keinen Umlaut in der Mitte durch. */
function sg_kuerzen($s, $n)
{
    $s = (string) $s;
    if (preg_match_all('/./us', $s, $m) === false) { return substr($s, 0, $n); }
    return implode('', array_slice($m[0], 0, $n));
}
function sg_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/**
 * Eine Rufnummer fuer das Protokoll unkenntlich machen.
 * Im Log stehen sonst dauerhaft vollstaendige Telefonnummern - auch von
 * Fremden, die nur einmal danebengetippt haben.
 */
function sg_maske($nummer)
{
    $n = (string) $nummer;
    if (strlen($n) < 6) { return '***'; }
    return substr($n, 0, 4) . str_repeat('*', max(0, strlen($n) - 6)) . substr($n, -2);
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function sg_befehl_vorgabe()
{
    return array(
        'aktiv' => 0,
        'wort' => '',        // was der Nutzer schreibt
        'thema' => '',       // MQTT-Thema, das gepulst wird
        'wert' => '1',       // Nutzlast
        'stufe' => 'sofort', // sofort | rueckfrage | pin
        'antwort' => '',     // Bestaetigungstext, leer = Vorgabetext
    );
}

function sg_vorgaben()
{
    return array(
        // Verbindung zu signal-cli
        'rpc_url'        => 'http://127.0.0.1:8095',
        'konto'          => '',        // die verknuepfte Rufnummer
        // Absender
        'erlaubt'        => array(),   // Liste erlaubter Rufnummern
        'pin'            => '',        // nur noch Altbestand, siehe pin_hash
        'pin_hash'       => '',        // die PIN als Hash (password_hash)
        'pin_versuche'   => 3,         // so viele Fehlversuche, dann Sperre
        'pin_sperre'     => 15,        // Minuten Sperre nach den Fehlversuchen
        'bremse'         => 10,        // Befehle je Minute und Absender
        'stille'         => 1,         // Unbekannte ohne Antwort abweisen
        // Befehle
        'befehle'        => array(),
        // Zustaende, die Loxone meldet
        'zustand_ein'    => 1,
        // MQTT
        'mqtt_ein'       => 1,
        'mqtt_topic'     => 'signalbot',
        // Endpunkt
        'aktionstoken'   => '',
    );
}

function sg_config()
{
    $p = sg_paths();
    /* Selbstheilung: zuerst am heutigen Ort (neben dem Plugin-Ordner), dann
     * am frueheren Ort darin - sonst verloere eine bestehende Anlage beim
     * Update ihre vorhandene Sicherung. */
    $sg_vorhanden = is_file($p['config']);
    $roh = $sg_vorhanden ? trim((string) @file_get_contents($p['config'])) : '';
    if ($roh === '' || $roh === '{}') {
        /* Nur eine BRAUCHBARE Sicherung zurueckholen.
         *
         * Bis 0.9.11 brach die Schleife bei der ersten Datei ab, die es GAB -
         * auch wenn sie leer war. Eine gute Sicherung am frueheren Ort wurde
         * dann nie gelesen, weil eine leere am heutigen Ort davorstand. */
        foreach (array($p['sicherung'], $p['sicherung_alt']) as $sg_quelle) {
            if ($sg_quelle === '' || !is_file($sg_quelle)) { continue; }
            $sg_probe = trim((string) @file_get_contents($sg_quelle));
            if ($sg_probe === '' || !is_array(json_decode($sg_probe, true))) { continue; }
            @mkdir($p['configdir'], 0775, true);
            @copy($sg_quelle, $p['config']);
            $roh = trim((string) @file_get_contents($p['config']));
            $sg_vorhanden = true;
            break;
        }
    }
    $cfg = $roh !== '' ? json_decode($roh, true) : array();
    /* Konnte die Datei NICHT gelesen werden, obwohl sie da ist, wird unten
     * nichts geschrieben. Sonst ginge genau der Fall schief, um dessentwillen
     * es die Sicherung gibt: aus einem misslungenen Lesen wuerden Vorgaben,
     * und die Vorgaben wuerden ueber die echte Konfiguration UND ueber die
     * Sicherung geschrieben - Weissliste, PIN und Token waeren fort.
     *
     * DIE LEERE DATEI ZAEHLT DAZU. Bis 0.9.11 hing der Schutz allein an
     * is_array($cfg) - und bei leerer Datei entsteht array(), was ein Feld
     * IST. Der Schutz griff also nur bei kaputtem JSON, nicht bei der
     * abgeschnittenen Datei. Nachgemessen am 16.08.2026 mit einer
     * Konfiguration von 0 Byte und einer ebenso leeren Sicherung: Weissliste
     * leer, PIN fort, NEUES Token - und beides ueber die Sicherung
     * geschrieben. Damit waren alle Adressen im Miniserver tot, und die
     * letzte Zuflucht war mit ueberschrieben. Ausloesen konnte das jeder
     * unangemeldete Aufruf des Endpunkts, denn der ruft sg_config() vor der
     * Token-Pruefung.
     *
     * Die Neuinstallation muss davon unberuehrt bleiben: dort gibt es die
     * Datei GAR NICHT, und dann soll das Token angelegt werden. Die
     * Unterscheidung haengt deshalb an is_file(), nicht am Inhalt.
     * Der Aktualisierungsfall (Datei enthaelt nur "{}") gilt weiter als
     * lesbar - das ist der Zustand jeder bestehenden Anlage nach einem
     * Update. */
    if ($roh === '' && $sg_vorhanden) {
        sg_log_gebremst('config_leer',
            'Konfiguration ist leer und es gibt keine brauchbare Sicherung - '
            . 'es wird nichts geschrieben. Weissliste, PIN und Token bleiben unangetastet, '
            . 'bis die Datei wieder Inhalt hat.');
        $lesbar = false;
    } else {
        $lesbar = is_array($cfg);
    }
    if (!$lesbar) {
        if ($roh !== '') { sg_log_gebremst('config_unlesbar', 'Konfiguration nicht lesbar - es wird nichts geschrieben.'); }
        $cfg = array();
    }
    $cfg = array_merge(sg_vorgaben(), $cfg);

    $cfg['rpc_url'] = rtrim(trim((string) $cfg['rpc_url']), '/');
    if ($cfg['rpc_url'] === '') { $cfg['rpc_url'] = 'http://127.0.0.1:8095'; }
    $cfg['bremse'] = max(1, min(60, (int) $cfg['bremse']));
    $cfg['pin_versuche'] = max(1, min(10, (int) $cfg['pin_versuche']));
    $cfg['pin_sperre'] = max(1, min(1440, (int) $cfg['pin_sperre']));
    $cfg['stille'] = empty($cfg['stille']) ? 0 : 1;
    $cfg['mqtt_ein'] = empty($cfg['mqtt_ein']) ? 0 : 1;
    $cfg['zustand_ein'] = empty($cfg['zustand_ein']) ? 0 : 1;
    $cfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    if ($cfg['mqtt_topic'] === '') { $cfg['mqtt_topic'] = 'signalbot'; }

    if (!is_array($cfg['erlaubt'])) { $cfg['erlaubt'] = array(); }
    $cfg['erlaubt'] = array_values(array_filter(array_map(function ($n) {
        $n = preg_replace('/[^0-9+]/', '', (string) $n);
        return preg_match('/^\+[0-9]{6,20}$/', $n) ? $n : '';
    }, $cfg['erlaubt'])));

    if (!is_array($cfg['befehle'])) { $cfg['befehle'] = array(); }
    for ($i = 0; $i < SG_BEFEHLE; $i++) {
        $b = isset($cfg['befehle'][$i]) && is_array($cfg['befehle'][$i]) ? $cfg['befehle'][$i] : array();
        $b += sg_befehl_vorgabe();
        $b['aktiv'] = empty($b['aktiv']) ? 0 : 1;
        // Das Wort wird kleingeschrieben verglichen - "Unscharf" und
        // "unscharf" sollen dasselbe tun.
        $b['wort'] = sg_klein(trim(preg_replace('/\s+/', ' ', (string) $b['wort'])));
        $b['thema'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $b['thema']);
        $b['wert'] = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $b['wert']));
        $b['stufe'] = in_array($b['stufe'], array('sofort', 'rueckfrage', 'pin'), true) ? $b['stufe'] : 'sofort';
        $b['antwort'] = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $b['antwort']));
        $cfg['befehle'][$i] = $b;
    }

    if ($lesbar && !preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken'])) {
        $cfg['aktionstoken'] = sg_token();
        sg_config_write($cfg);
    }
    return $cfg;
}

/**
 * Eine Datei unteilbar schreiben: Nebendatei, Rechte setzen, umbenennen.
 *
 * WARUM DAS HIER MEHR ZAEHLT ALS ANDERSWO
 * An dieser Konfiguration haengt ein DAUERLAEUFER: sg_bot.php ruft
 * sg_config() bei jeder eingehenden Nachricht auf, und sg_erlaubt(),
 * sg_bremse_frei() und sg_senden() rufen es jeweils erneut. Schreibt die
 * Oberflaeche in genau diesem Augenblick, laesst file_put_contents die
 * Datei zuerst auf null Byte schrumpfen.
 *
 * Nachgemessen mit einem Leser gegen einen Schreiber, Konfiguration von
 * 2400 Byte, 3000 Speichervorgaenge:
 *
 *     file_put_contents (truncate)   34 187 Lesevorgaenge, 19 375 davon leer
 *     Nebendatei + rename                        dieselbe Last, 0 leer
 *
 * Ein leeres Lesen ist nicht gefaehrlich - der Bot faellt auf die Vorgaben
 * zurueck, und die haben eine LEERE Weissliste und KEINE PIN, weisen also
 * ab. Es ist aber laestig: ein berechtigter Befehl wird nicht ausgefuehrt.
 * Und es kann Einstellungen kosten, weil sg_config() bei leerem Lesen die
 * Sicherung zurueckkopiert - mitten in das Speichern hinein.
 *
 * Die Rechte werden VOR dem Umbenennen gesetzt. Sonst stuende die Datei mit
 * PIN und Token einen Augenblick lang mit den Rechten aus der umask da.
 */
function sg_write_atomic($datei, $inhalt, $rechte = 0600)
{
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); return false; }
    @chmod($tmp, $rechte);
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}

function sg_config_write($cfg)
{
    $p = sg_paths();
    @mkdir($p['configdir'], 0775, true);
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    // Token, PIN und die Liste der erlaubten Rufnummern - nicht fuer alle.
    if ($js === false || !sg_write_atomic($p['config'], $js, 0600)) { return false; }
    // Die Sicherung erst NACH dem gelungenen Schreiben nachziehen, und
    // ebenfalls unteilbar: sie ist die letzte Zuflucht, wenn die
    // Konfiguration einmal nicht lesbar ist.
    sg_write_atomic($p['sicherung'], $js, 0600);
    return true;
}

function sg_token($laenge = 32)
{
    $zeichen = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    try { $roh = random_bytes($laenge); }
    catch (Exception $e) { $roh = ''; for ($i = 0; $i < $laenge; $i++) { $roh .= chr(mt_rand(0, 255)); } }
    $out = '';
    for ($i = 0; $i < $laenge; $i++) { $out .= $zeichen[ord($roh[$i]) % strlen($zeichen)]; }
    return $out;
}

/* ==================================================================
 * signal-cli ansprechen
 * ================================================================== */

/** Ein JSON-RPC-Aufruf. Rueckgabe: array(ok, result, fehler). */
function sg_rpc($methode, $params = array(), $zeit = 20)
{
    $cfg = sg_config();
    $rumpf = json_encode(array(
        'jsonrpc' => '2.0',
        'method' => $methode,
        'params' => (object) $params,
        'id' => sg_token(8),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $url = $cfg['rpc_url'] . '/api/v1/rpc';
    $antwort = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $zeit,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rumpf,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json',
                                        'User-Agent: LoxBerry-SignalBot'),
        ));
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlfehler = curl_error($ch);
        curl_close($ch);
        if ($antwort === false) {
            return array('ok' => 0, 'result' => null, 'fehler' => $curlfehler !== '' ? $curlfehler : 'keine Antwort');
        }
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'POST', 'timeout' => $zeit, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nUser-Agent: LoxBerry-SignalBot",
            'content' => $rumpf)));
        $antwort = @file_get_contents($url, false, $ctx);
        if ($antwort === false) {
            return array('ok' => 0, 'result' => null, 'fehler' => 'keine Antwort');
        }
    }
    $d = json_decode((string) $antwort, true);
    if (!is_array($d)) {
        return array('ok' => 0, 'result' => null, 'fehler' => 'Antwort ist kein JSON (HTTP ' . $code . ')');
    }
    if (isset($d['error'])) {
        $m = isset($d['error']['message']) ? $d['error']['message'] : json_encode($d['error']);
        return array('ok' => 0, 'result' => null, 'fehler' => $m);
    }
    return array('ok' => 1, 'result' => isset($d['result']) ? $d['result'] : null, 'fehler' => '');
}

/** Laeuft der signal-cli-Dienst? Fragt den Lebenszeichen-Endpunkt. */
/**
 * Laeuft der signal-cli-Dienst? Fragt den Lebenszeichen-Endpunkt.
 *
 * Ausgewertet wird die STATUSZEILE, nicht das blosse Ankommen einer Antwort.
 * Bis 0.9.11 endete die Funktion auf "return $a !== false;" - und weil zwei
 * Zeilen darueber schon bei $a === false zurueckgekehrt wird, war das ab
 * dort IMMER wahr. Mit ignore_errors kommt auch eine 404- oder 500-Seite als
 * Zeichenkette an: in Loxone stand SIGNAL;OK=1 und im Reiter Test ein gruenes
 * Haekchen, waehrend keine einzige Nachricht durchging.
 *
 * $http_response_header ist innerhalb der Funktion gueltig, die den Strom
 * oeffnet - in PHP 7.4 wie in 8.x. Bei einer Weiterleitung stehen mehrere
 * Statuszeilen darin; es gilt die letzte.
 */
function sg_daemon_lebt()
{
    $cfg = sg_config();
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
    $a = @file_get_contents($cfg['rpc_url'] . '/api/v1/check', false, $ctx);
    if ($a === false) { return false; }
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
        }
    }
    return $code >= 200 && $code < 300;
}

function sg_dienst_laeuft()
{
    $aus = array();
    @exec('systemctl is-active signal-cli-loxberry 2>/dev/null', $aus);
    return trim(implode('', $aus)) === 'active';
}

function sg_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'unbekannter Befehl');
    }
    $aus = array(); $rc = 0;
    @exec('sudo -n /bin/systemctl ' . $befehl . ' signal-cli-loxberry 2>&1', $aus, $rc);
    if ($rc !== 0) {
        $aus = array();
        @exec('sudo -n /usr/bin/systemctl ' . $befehl . ' signal-cli-loxberry 2>&1', $aus, $rc);
    }
    sg_log('Dienst ' . $befehl . ' -> ' . ($rc === 0 ? 'ok' : 'Fehler: ' . implode(' ', $aus)));
    return array($rc === 0 ? 1 : 0, trim(implode(' ', $aus)));
}

/** Die von signal-cli bekannten Konten. */
function sg_konten()
{
    $a = sg_rpc('listAccounts', array(), 8);
    if (!$a['ok'] || !is_array($a['result'])) { return array(); }
    $out = array();
    foreach ($a['result'] as $k) {
        if (is_array($k) && isset($k['number'])) { $out[] = (string) $k['number']; }
        elseif (is_string($k)) { $out[] = $k; }
    }
    return $out;
}

/** Eine Nachricht senden. */
function sg_senden($an, $text)
{
    $cfg = sg_config();
    $params = array('recipient' => array((string) $an), 'message' => (string) $text);
    if ((string) $cfg['konto'] !== '') { $params['account'] = (string) $cfg['konto']; }
    $a = sg_rpc('send', $params, 25);
    if (!$a['ok']) {
        sg_log('Senden an ' . sg_maske($an) . ' fehlgeschlagen: ' . $a['fehler']);
    }
    return $a['ok'] ? 1 : 0;
}

/* ==================================================================
 * Absicherung
 * ================================================================== */

/** Steht der Absender auf der Weissliste? */
function sg_erlaubt($nummer)
{
    $cfg = sg_config();
    $n = preg_replace('/[^0-9+]/', '', (string) $nummer);
    foreach ($cfg['erlaubt'] as $e) {
        // hash_equals auch hier - die Liste ist kurz, aber es kostet nichts.
        if (hash_equals($e, $n)) { return true; }
    }
    return false;
}

/**
 * Bremse: hoechstens X Befehle je Minute und Absender.
 * Zaehlt in einer Datei je Absender, nicht im Speicher - der Bot kann
 * jederzeit neu starten, die Bremse soll das ueberleben.
 */
/**
 * Darf dieser Absender noch? Zaehlt die Nachrichten der letzten 60 Sekunden.
 *
 * LESEN UND SCHREIBEN STEHEN UNTER EINER SPERRE - und das ist der Punkt.
 *
 * Bis 0.9.0 lief hier ein ungeschuetztes Lesen-Aendern-Schreiben:
 *
 *     $liste = json_decode(file_get_contents($f), true);
 *     ...
 *     $liste[] = $jetzt;
 *     @file_put_contents($f, json_encode($liste));
 *
 * Zwei Prozesse lesen beide "zwei Eintraege", beide haengen einen an, beide
 * schreiben "drei" - ein Zaehlschritt ist verloren. Nachgemessen mit vier
 * Prozessen, die je 400 Eintraege setzen (erwartet: 1600):
 *
 *     bisher, unmittelbar geschrieben        34 von 1600
 *     nur unteilbar umbenannt (.tmp+rename) 437 von 1600
 *     Lesen UND Schreiben unter Sperre      1600 von 1600
 *
 * Die mittlere Zeile ist wichtig: Unteilbares Umbenennen verhindert eine
 * ZERRISSENE Datei, aber keinen VERLORENEN Zaehlschritt. Wer nur das
 * einbaut, hat das Problem nicht geloest, sondern nur seltener gemacht.
 *
 * Folge des Fehlers war nicht, dass die Bremse "komplett ausfaellt" - eine
 * kaputte Datei faengt der is_array()-Test ab. Sie zaehlte zu niedrig, ein
 * Absender kam also durch mehr Nachrichten durch als eingestellt.
 *
 * ftruncate statt Neuanlage: So bleibt es dieselbe Datei mit derselben
 * Inode, und die Sperre gilt weiter fuer alle, die sie offen haben.
 */
function sg_bremse_frei($nummer)
{
    $cfg = sg_config();
    $f = sg_tmpdir() . '/bremse_' . md5((string) $nummer) . '.json';
    $fp = @fopen($f, 'c+');
    if ($fp === false) {
        /* FAIL CLOSED. Bis 0.9.11 wurde hier durchgelassen, mit der
         * Begruendung "die Bremse ist ein Schutz, kein Tor". Das ist falsch
         * herum: diese Bremse ist die EINZIGE Begrenzung fuer das
         * Durchprobieren der PIN (siehe Kopf dieser Datei). Wer den
         * Sperrordner unbrauchbar macht, haette damit die Begrenzung
         * abgeschaltet - und die Meldung stand bei jeder Nachricht neu im
         * Protokoll. Jetzt gilt: keine Sperrdatei, kein Befehl. */
        sg_log_gebremst('bremse_defekt',
            'Bremse: ' . $f . ' laesst sich nicht oeffnen - es werden KEINE Befehle mehr '
            . 'angenommen, bis das behoben ist. Rechte von ' . sg_tmpdir() . ' pruefen.');
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        sg_log_gebremst('bremse_sperre', 'Bremse: Sperre nicht zu bekommen - Befehl abgewiesen.');
        return false;
    }
    $jetzt = time();
    $roh = (string) stream_get_contents($fp);
    $liste = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($liste)) { $liste = array(); }
    $liste = array_values(array_filter($liste, function ($t) use ($jetzt) { return $t > $jetzt - 60; }));
    if (count($liste) >= (int) $cfg['bremse']) {
        // Auch den bereinigten Stand zurueckschreiben, sonst waechst die
        // Datei bei Dauerbeschuss unbegrenzt.
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($liste)); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        return false;
    }
    $liste[] = $jetzt;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($liste));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($f, 0600);
    return true;
}

/**
 * Stimmt die PIN? Vergleicht in gleichbleibender Zeit.
 *
 * Die PIN steht seit 0.9.12 als Hash in der Konfiguration. Eine noch im
 * Klartext vorliegende PIN aus einer aelteren Fassung wird weiter
 * angenommen, damit ein Update niemanden aussperrt; sie wird beim naechsten
 * Speichern in der Oberflaeche in einen Hash umgeschrieben.
 */
function sg_pin_stimmt($cfg, $eingabe)
{
    $hash = isset($cfg['pin_hash']) ? (string) $cfg['pin_hash'] : '';
    if ($hash !== '') { return password_verify((string) $eingabe, $hash); }
    $klar = (string) $cfg['pin'];
    if ($klar === '') { return false; }
    return hash_equals($klar, (string) $eingabe);
}

/** Ist ueberhaupt eine PIN gesetzt - gleich in welcher Form? */
function sg_pin_gesetzt($cfg)
{
    return (isset($cfg['pin_hash']) && (string) $cfg['pin_hash'] !== '')
        || (string) $cfg['pin'] !== '';
}

/**
 * Fehlversuche zaehlen und nach mehreren Versuchen sperren.
 *
 * WARUM DAS NOETIG IST
 * Bis 0.9.11 begrenzte allein die Bremse das Durchprobieren: ein Versuch
 * kostet zwei Nachrichten, die Vorgabe sind zehn je Minute - also rund fuenf
 * Versuche je Minute, dauerhaft. Eine vierstellige PIN ist damit in gut einem
 * Tag durchprobiert, und zwar von einem Absender, der ohnehin auf der
 * Weissliste steht: genau der Fall "Handy in fremden Haenden", fuer den es
 * die PIN ueberhaupt gibt. Der Kopf dieser Datei versprach "danach ist Ruhe" -
 * Ruhe wurde es nie.
 *
 * Rueckgabe: verbleibende Sperrzeit in Sekunden, 0 = nicht gesperrt.
 */
function sg_pin_fehlversuch($nummer)
{
    $cfg = sg_config();
    $f = sg_tmpdir() . '/pinfehl_' . md5((string) $nummer) . '.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($d)) { $d = array(); }
    $n = isset($d['n']) ? (int) $d['n'] + 1 : 1;
    $bis = 0;
    if ($n >= (int) $cfg['pin_versuche']) {
        $bis = time() + ((int) $cfg['pin_sperre'] * 60);
        $n = 0;
        sg_log('PIN-Sperre fuer ' . sg_maske($nummer) . ': ' . (int) $cfg['pin_sperre']
             . ' Minuten nach ' . (int) $cfg['pin_versuche'] . ' Fehlversuchen.');
    }
    @file_put_contents($f, json_encode(array('n' => $n, 'bis' => $bis)));
    @chmod($f, 0600);
    return $bis > 0 ? $bis - time() : 0;
}

/** Verbleibende Sperrzeit in Sekunden, 0 = frei. */
function sg_pin_gesperrt($nummer)
{
    $f = sg_tmpdir() . '/pinfehl_' . md5((string) $nummer) . '.json';
    if (!is_file($f)) { return 0; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || empty($d['bis'])) { return 0; }
    $offen = (int) $d['bis'] - time();
    return $offen > 0 ? $offen : 0;
}

function sg_pin_zuruecksetzen($nummer)
{
    @unlink(sg_tmpdir() . '/pinfehl_' . md5((string) $nummer) . '.json');
}

/** Offene Rueckfrage eines Absenders lesen, setzen oder loeschen. */
function sg_wartend($nummer, $setzen = null)
{
    $f = sg_tmpdir() . '/warte_' . md5((string) $nummer) . '.json';
    if ($setzen === false) { @unlink($f); return null; }
    if ($setzen !== null) {
        $setzen['ts'] = time();
        @file_put_contents($f, json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    if (!is_file($f)) { return null; }
    $d = json_decode((string) file_get_contents($f), true);
    if (!is_array($d) || !isset($d['ts']) || (time() - (int) $d['ts']) > SG_WARTEZEIT) {
        @unlink($f);
        return null;
    }
    return $d;
}

/* ==================================================================
 * Zustaende, die Loxone meldet
 * ================================================================== */

function sg_zustaende()
{
    $f = sg_datadir() . '/zustaende.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : array();
    return is_array($d) ? $d : array();
}

/**
 * Einen Zustand ablegen.
 *
 * Lesen-Aendern-Schreiben unter einer Sperre - dieselbe Ueberlegung wie bei
 * der Bremse weiter oben. Loxone schickt Zustaende gern mehrere auf einmal,
 * und jeder Aufruf landet in einem EIGENEN PHP-Prozess. Ohne Sperre lesen
 * zwei Prozesse denselben Stand, aendern je einen Namen und schreiben beide
 * zurueck - der zuerst geschriebene Zustand ist dann weg.
 *
 * Die Sperre liegt auf einer eigenen Datei, nicht auf zustaende.json selbst:
 * rename() haengt eine neue Datei in den Namen, eine Sperre auf der alten
 * Datei bewachte danach nichts mehr.
 */
function sg_zustand_setzen($name, $wert)
{
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $name);
    if ($name === '') { return false; }
    $ziel = sg_datadir() . '/zustaende.json';
    $sperre = @fopen(sg_tmpdir() . '/zustaende.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }

    $z = sg_zustaende();
    $z[$name] = array('wert' => (string) $wert, 'ts' => time());
    // Nur die letzten 50 - sonst waechst die Datei unbegrenzt, wenn Loxone
    // versehentlich immer neue Namen schickt.
    if (count($z) > 50) {
        uasort($z, function ($a, $b) { return $b['ts'] - $a['ts']; });
        $z = array_slice($z, 0, 50, true);
    }
    // 0644: hier stehen keine Geheimnisse, und der Bot laeuft unter einem
    // anderen Benutzer als die Oberflaeche.
    $ok = sg_write_atomic($ziel, json_encode($z), 0644);

    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $ok;
}

function sg_zustand_text()
{
    $z = sg_zustaende();
    if (!$z) { return sg_t('BOT.KEINE_ZUSTAENDE'); }
    ksort($z);
    $zeilen = array();
    foreach ($z as $name => $d) {
        $alter = time() - (int) $d['ts'];
        $zeilen[] = $name . ': ' . $d['wert']
            . ($alter > 300 ? ' (' . sprintf(sg_t('BOT.VOR_MINUTEN'), (int) round($alter / 60)) . ')' : '');
    }
    return implode("\n", $zeilen);
}

/* ==================================================================
 * MQTT ueber das LoxBerry-Gateway (UDP-Relay)
 * ================================================================== */

function sg_mqtt_zustand()
{
    $p = sg_paths();
    $out = array('gefunden' => 0, 'udpport' => 0, 'autostart' => 0);
    if ($p['home'] === '') { return $out; }
    $gen = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    if (!is_array($gen)) { return $out; }
    foreach (array('Mqtt', 'mqtt') as $k) {
        if (!isset($gen[$k]) || !is_array($gen[$k])) { continue; }
        $out['gefunden'] = 1;
        foreach (array('Udpinport', 'udpinport') as $pk) {
            if (isset($gen[$k][$pk])) { $out['udpport'] = (int) $gen[$k][$pk]; }
        }
        /* Der Schluessel heisst Gatewayautostart.
         *
         * Bis 0.9.11 wurde hier 'Autostart' gesucht. Den Namen gibt es in der
         * general.json nicht - config/system/general.json.default setzt ab
         * Werk "Gatewayautostart" : 1. Damit stand $out['autostart'] IMMER
         * auf 0, und zwar auch auf einer einwandfrei eingerichteten Anlage:
         * im Reiter MQTT erschien dauerhaft "Das MQTT-Gateway ist nicht auf
         * Autostart gestellt", im Reiter Test ein rotes Kreuz. Eine Anzeige,
         * die immer dasselbe sagt, sagt nichts - und sie schickt den
         * Anwender an eine Stelle, an der nichts zu richten ist.
         *
         * Nachgemessen am 16.08.2026 gegen eine general.json mit den
         * Vorgabewerten: 'Autostart' -> 0, 'Gatewayautostart' -> 1.
         *
         * Die alten Namen bleiben als Rueckfallebene stehen, kosten nichts
         * und tragen eine Anlage, die von Hand etwas anderes eingetragen hat.
         * Der erste vorhandene Schluessel gewinnt, deshalb das break.
         */
        foreach (array('Gatewayautostart', 'gatewayautostart', 'Autostart', 'autostart') as $ak) {
            if (isset($gen[$k][$ak])) { $out['autostart'] = (int) $gen[$k][$ak] ? 1 : 0; break; }
        }
    }
    return $out;
}

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung, einem Geraetenamen oder der Ausgabe eines Systembefehls -
 * zerlegt die Uebertragung, und aus den Bruchstuecken bildet das Gateway
 * erfundene Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und
 * Wert trennt.
 */
function sg_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function sg_mqtt_pulsen($thema, $wert)
{
    $cfg = sg_config();
    if (empty($cfg['mqtt_ein'])) { return 0; }
    $z = sg_mqtt_zustand();
    if (!$z['udpport']) {
        sg_log('MQTT: kein UDP-Eingangsport in der general.json - Gateway eingerichtet?');
        return 0;
    }
    $voll = $cfg['mqtt_topic'] . '/' . ltrim((string) $thema, '/');
    $msg = 'publish ' . $voll . ' ' . sg_mqtt_wert_saeubern($wert);
    return sg_udp_senden($z['udpport'], $msg);
}

/**
 * Eine Zeile an den UDP-Eingang des Gateways schicken.
 *
 * OHNE php-sockets, wenn es sein muss. Bis 0.9.11 stand hier ein blankes
 * @socket_create(...) - das Klammeraffen-Zeichen faengt aber keinen "Call to
 * undefined function" ab, und php-sockets stand in keinem dpkg/apt. Fehlt die
 * Erweiterung, starb der Dauerlaeufer beim ERSTEN ausgefuehrten Befehl mit
 * einem fatalen Fehler; der Cron startete ihn eine Minute spaeter neu, und
 * beim naechsten Befehl starb er wieder. Nach aussen sah das aus wie "der Bot
 * antwortet manchmal nicht". Nachgestellt am 16.08.2026 mit einem PHP ohne
 * die Erweiterung - genau so trat es ein.
 *
 * Ein UDP-Paket braucht die Erweiterung gar nicht; stream_socket_client()
 * gehoert zum Kern. Der Weg ueber sockets bleibt, wo er vorhanden ist.
 */
function sg_udp_senden($port, $msg)
{
    $port = (int) $port;
    if ($port <= 0) { return 0; }
    if (function_exists('socket_create')) {
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($s) {
            $ok = @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $port);
            @socket_close($s);
            if ($ok !== false) { return 1; }
        }
    }
    $fehler = 0; $text = '';
    $fp = @stream_socket_client('udp://127.0.0.1:' . $port, $fehler, $text, 2);
    if (!$fp) {
        sg_log_gebremst('udp_zu', 'MQTT: UDP-Eingang 127.0.0.1:' . $port . ' nicht erreichbar (' . $text . ')');
        return 0;
    }
    $ok = @fwrite($fp, $msg);
    fclose($fp);
    return $ok === false ? 0 : 1;
}

/* ==================================================================
 * Der Kern: eine eingehende Nachricht verarbeiten
 *
 * Getrennt vom Empfangen, damit der Reiter Test denselben Weg durchspielen
 * kann, ohne dass jemand eine echte Nachricht schicken muss.
 *
 * Rueckgabe: array('antwort' => Text oder '', 'grund' => Kurzwort)
 * Ein leerer Antworttext heisst: bewusst schweigen.
 *
 * $trocken = true heisst: NUR zeigen, was geschehen wuerde. Dann wird nichts
 * gesendet, nichts geschaltet, nichts gezaehlt und nichts gemerkt.
 *
 * WARUM ES DEN SCHALTER GEBEN MUSS
 * Bis 0.9.11 hatte diese Funktion ihn nicht, und der Knopf "Durchspielen" im
 * Reiter Test rief sie trotzdem auf. Ein Befehl der Stufe sofort wurde dabei
 * WIRKLICH ausgefuehrt - wer "tor auf" ins Testfeld tippte, machte das Tor
 * auf. Schlimmer: der Reiter benutzt die echte Rufnummer des ersten
 * erlaubten Absenders. Bei Stufe rueckfrage oder pin legte der Probelauf
 * fuer DIESE fremde Person eine Wartedatei an - antwortete sie danach aus
 * einem beliebigen anderen Grund "ja", fuehrte der Bot den Befehl aus, den
 * der Verwalter ins Testfeld getippt hatte. Nebenbei verbrauchte jeder
 * Probelauf ihren Bremszaehler und ueberschrieb ihre echte offene
 * Rueckfrage. Der Kommentar daneben versprach dabei "ohne dass etwas
 * geschaltet wird".
 * ================================================================== */

function sg_verarbeite($von, $text, $trocken = false)
{
    $cfg = sg_config();
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    $klein = sg_klein($text);

    /* ---- Schicht 1: Weissliste ---- */
    if (!sg_erlaubt($von)) {
        sg_log('Abgewiesen: ' . sg_maske($von) . ' (nicht auf der Weissliste)');
        // Bewusst schweigen: eine Antwort wuerde Fremden bestaetigen, dass
        // hier ein Bot horcht. Wer die Nummer nur vertippt hat, merkt es am
        // ausbleibenden Echo genauso.
        return array('antwort' => empty($cfg['stille']) ? sg_t('BOT.NICHT_ERLAUBT') : '',
                     'grund' => 'nicht_erlaubt');
    }

    /* ---- Schicht 3 (vorgezogen): Bremse ----
       Im Trockenlauf wird nicht gezaehlt: der Probelauf des Verwalters darf
       das Kontingent des Bewohners nicht aufbrauchen. */
    if (!$trocken && !sg_bremse_frei($von)) {
        sg_log('Gebremst: ' . sg_maske($von) . ' (mehr als ' . (int) $cfg['bremse'] . ' je Minute)');
        return array('antwort' => sg_t('BOT.GEBREMST'), 'grund' => 'gebremst');
    }

    /* ---- Laeuft eine Rueckfrage? ----
       Im Trockenlauf uebergangen. Sonst beantwortete der Probelauf eine
       offene Rueckfrage des Bewohners - oder loeschte sie. */
    $warte = $trocken ? null : sg_wartend($von);
    if ($warte !== null) {
        /* Die Rueckfrage haengt am WORT, nicht nur an der Zeilennummer.
         *
         * Bis 0.9.11 wurde allein der Tabellenindex gemerkt und beim
         * Ausfuehren die Stufe aus der Wartedatei genommen. Wer die
         * Befehlstabelle waehrend der 90 Sekunden umsortierte, liess ein
         * "ja" einen ANDEREN Befehl ausloesen; wer eine Zeile in dieser Zeit
         * von rueckfrage auf pin hochstufte, bekam sie trotzdem ohne PIN
         * ausgefuehrt. Jetzt muessen Wort UND Stufe noch passen, sonst wird
         * abgebrochen und gesagt, warum. */
        $bi = (int) $warte['befehl'];
        $b = isset($cfg['befehle'][$bi]) ? $cfg['befehle'][$bi] : null;
        $sg_wort = isset($warte['wort']) ? (string) $warte['wort'] : '';
        if ($sg_wort !== '' && ($b === null || $b['wort'] !== $sg_wort)) {
            $b = null;
            foreach ($cfg['befehle'] as $sg_bb) {
                if (!empty($sg_bb['aktiv']) && $sg_bb['wort'] === $sg_wort) { $b = $sg_bb; break; }
            }
        }
        if ($b === null || empty($b['aktiv'])) {
            sg_wartend($von, false);
            return array('antwort' => sg_t('BOT.ABGEBROCHEN'), 'grund' => 'abgebrochen');
        }
        if (isset($warte['stufe']) && $b['stufe'] !== $warte['stufe']) {
            sg_wartend($von, false);
            sg_log('Rueckfrage verworfen: Stufe von "' . $b['wort'] . '" hat sich waehrenddessen geaendert.');
            return array('antwort' => sg_t('BOT.GEAENDERT'), 'grund' => 'geaendert');
        }
        if (in_array($klein, array('nein', 'no', 'abbrechen', 'stop'), true)) {
            sg_wartend($von, false);
            sg_log('Abgebrochen von ' . sg_maske($von) . ': ' . $b['wort']);
            return array('antwort' => sg_t('BOT.ABGEBROCHEN'), 'grund' => 'abgebrochen');
        }
        if ($warte['art'] === 'pin') {
            // hash_equals gegen das Erraten ueber die Antwortzeit.
            if (!sg_pin_stimmt($cfg, $text)) {
                sg_log('Falsche PIN von ' . sg_maske($von) . ' fuer: ' . $b['wort']);
                sg_wartend($von, false);
                $sg_offen = sg_pin_fehlversuch($von);
                if ($sg_offen > 0) {
                    return array('antwort' => sprintf(sg_t('BOT.PIN_GESPERRT'), (int) ceil($sg_offen / 60)),
                                 'grund' => 'pin_gesperrt');
                }
                return array('antwort' => sg_t('BOT.PIN_FALSCH'), 'grund' => 'pin_falsch');
            }
            sg_pin_zuruecksetzen($von);
        } elseif (!in_array($klein, array('ja', 'yes', 'ok'), true)) {
            return array('antwort' => sg_t('BOT.BITTE_JA_NEIN'), 'grund' => 'unklar');
        }
        sg_wartend($von, false);
        return sg_ausfuehren($b, $von, $trocken);
    }

    /* ---- Eingebaute Woerter ---- */
    if (in_array($klein, array('hilfe', 'help', '?'), true)) {
        return array('antwort' => sg_hilfetext(), 'grund' => 'hilfe');
    }
    if (in_array($klein, array('status', 'zustand'), true)) {
        if (empty($cfg['zustand_ein'])) {
            return array('antwort' => sg_t('BOT.ZUSTAND_AUS'), 'grund' => 'zustand_aus');
        }
        return array('antwort' => sg_zustand_text(), 'grund' => 'status');
    }

    /* ---- Schicht 2: Befehl suchen und Stufe anwenden ---- */
    foreach ($cfg['befehle'] as $i => $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        if ($klein !== $b['wort']) { continue; }
        if ($b['stufe'] === 'pin') {
            if (!sg_pin_gesetzt($cfg)) {
                sg_log('Befehl ' . $b['wort'] . ' verlangt eine PIN, es ist aber keine gesetzt.');
                return array('antwort' => sg_t('BOT.KEINE_PIN_GESETZT'), 'grund' => 'keine_pin');
            }
            $sg_offen = sg_pin_gesperrt($von);
            if ($sg_offen > 0) {
                return array('antwort' => sprintf(sg_t('BOT.PIN_GESPERRT'), (int) ceil($sg_offen / 60)),
                             'grund' => 'pin_gesperrt');
            }
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'pin', 'wort' => $b['wort'], 'stufe' => $b['stufe'])); }
            return array('antwort' => sg_t('BOT.PIN_BITTE'), 'grund' => 'pin_angefordert');
        }
        if ($b['stufe'] === 'rueckfrage') {
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'rueckfrage', 'wort' => $b['wort'], 'stufe' => $b['stufe'])); }
            return array('antwort' => sprintf(sg_t('BOT.RUECKFRAGE'), $b['wort']), 'grund' => 'rueckfrage');
        }
        return sg_ausfuehren($b, $von, $trocken);
    }

    return array('antwort' => sg_t('BOT.UNBEKANNT'), 'grund' => 'unbekannt');
}

/**
 * Einen freigegebenen Befehl wirklich absetzen.
 *
 * $trocken = true haelt genau hier an: das Thema wird NICHT gepulst, und die
 * Rueckgabe traegt den Grund 'wuerde_ausfuehren' statt 'ausgefuehrt'. Der
 * Antworttext ist derselbe, den der Absender bekommen haette - darum geht es
 * im Probelauf ja.
 */
function sg_ausfuehren($b, $von, $trocken = false)
{
    if ($b['thema'] === '') {
        return array('antwort' => sg_t('BOT.KEIN_THEMA'), 'grund' => 'kein_thema');
    }
    if ($trocken) {
        sg_log('Trockenlauf: "' . $b['wort'] . '" wuerde ' . $b['thema'] . '=' . $b['wert']
             . ' senden (es wurde nichts gesendet)');
        $antwort = $b['antwort'] !== '' ? $b['antwort'] : sprintf(sg_t('BOT.ERLEDIGT'), $b['wort']);
        return array('antwort' => $antwort, 'grund' => 'wuerde_ausfuehren');
    }
    $ok = sg_mqtt_pulsen($b['thema'], $b['wert']);
    sg_log('Befehl "' . $b['wort'] . '" von ' . sg_maske($von) . ' -> '
         . $b['thema'] . '=' . $b['wert'] . ' (' . ($ok ? 'gesendet' : 'FEHLGESCHLAGEN') . ')');
    if (!$ok) {
        return array('antwort' => sg_t('BOT.MQTT_FEHLER'), 'grund' => 'mqtt_fehler');
    }
    $antwort = $b['antwort'] !== '' ? $b['antwort'] : sprintf(sg_t('BOT.ERLEDIGT'), $b['wort']);
    return array('antwort' => $antwort, 'grund' => 'ausgefuehrt');
}

/** Die Hilfe, die der Bot auf "hilfe" schickt. */
function sg_hilfetext()
{
    $cfg = sg_config();
    $zeilen = array(sg_t('BOT.HILFE_KOPF'));
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        $marke = '';
        if ($b['stufe'] === 'pin') { $marke = ' ' . sg_t('BOT.MARKE_PIN'); }
        elseif ($b['stufe'] === 'rueckfrage') { $marke = ' ' . sg_t('BOT.MARKE_RUECKFRAGE'); }
        $zeilen[] = '- ' . $b['wort'] . $marke;
    }
    if (!empty($cfg['zustand_ein'])) { $zeilen[] = '- status'; }
    $zeilen[] = '- hilfe';
    return implode("\n", $zeilen);
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprüfter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

/** Adresse des eigenen Endpunkts. */
function sg_endpunkt($aktion = 'status')
{
    $p = sg_paths();
    $cfg = sg_config();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php'
         . '?token=' . $cfg['aktionstoken'] . '&aktion=' . $aktion;
}

/** Die Felder der Statuszeile: name => array(analog, min, max, Sprachschluessel). */
function sg_felder()
{
    return array(
        'OK'        => array(0, 0, 1, 'FELD.OK'),
        'DAEMON'    => array(0, 0, 1, 'FELD.DAEMON'),
        'KONTO'     => array(0, 0, 1, 'FELD.KONTO'),
        'ERLAUBTE'  => array(1, 0, 100, 'FELD.ERLAUBTE'),
        'BEFEHLE'   => array(1, 0, SG_BEFEHLE, 'FELD.BEFEHLE'),
        'ZUSTAENDE' => array(1, 0, 50, 'FELD.ZUSTAENDE'),
    );
}

function sg_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . sg_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sg_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . sg_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . sg_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . sg_x($c['title']) . '" ';
        $o .= 'Comment="' . sg_x($c['comment']) . '" ';
        $o .= 'Check="' . sg_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Vorlage fuer Loxone Config.
 *
 * Nur EINGAENGE, bewusst. Fuer das Senden aus Loxone gibt es keinen
 * virtuellen Ausgang in der Vorlage: die Adresse traegt den Meldungstext,
 * und der ist je Anwendungsfall ein anderer. Ein vorgefertigter Ausgang
 * mit Platzhaltertext waere ein Baustein, den ohnehin jeder umschreibt -
 * die fertige Adresse steht statt dessen im Reiter zum Kopieren.
 */
function sg_vorlage()
{
    $cmds = array();
    foreach (sg_felder() as $name => $d) {
        list($analog, $min, $max, $schluessel) = $d;
        $cmds[] = array(
            'title' => 'SIGNAL_' . $name,
            'comment' => trim(strip_tags(html_entity_decode(sg_t($schluessel), ENT_QUOTES, 'UTF-8'))),
            'check' => '\i' . $name . '=\i\v',
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_signalbot.xml', sg_xml_virtual_in_http(array(
        'title'   => 'Signal Bot',
        'address' => sg_endpunkt('status'),
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Signal Bot (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch.
 * ================================================================== */

function sg_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function sg_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = sg_paths();
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) { $pfad = dirname(dirname(__DIR__)) . '/templates/lang'; }
        $texte = @parse_ini_file($pfad . '/language_' . sg_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
