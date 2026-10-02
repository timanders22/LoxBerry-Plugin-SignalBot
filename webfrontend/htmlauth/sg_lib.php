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
/** So viele Zeilen haelt das Ereignisprotokoll. */
define('SG_EREIGNISSE', 300);

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
            /* Seit dem Durchgang 01.10.2026 (I8, Regeln/06 Raumklima-Vorfall) auch
             * config/system/general.json: auf einem Pruefrechner mit Resten eines
             * Pruefstands hielt die Suche sonst einen fremden Baum fuer die Wurzel. */
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
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
        /* Kein fester Rueckfall mehr auf /home/loxberry/loxberry (I8): ein
         * fester Systempfad trifft auf einem Pruefrechner die Anlage, auf dem
         * Geraet findet die Wurzelsuche dieselbe Wurzel. */
        $home = lb_wurzel_ermitteln();
    }
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) {
        /* Installiert liegt diese Datei in
         *     <home>/webfrontend/htmlauth/plugins/<ordner>/sg_lib.php
         * dort ist basename(__DIR__) der Ordnername - OHNE weitere Bedingung.
         * Bis 0.9.25 galt er nur, wenn config/plugins/<ordner> schon da war;
         * in der Upgrade-Luecke (purge_installation hat den Ordner geraeumt)
         * fiel die Bibliothek dann auf 'signalbot' zurueck und haette bei einer
         * Namenskollision den Ordner eines anderen Plugins benutzt (I7, im
         * Pruefstand gemessen: plugin=signalbot statt des eigenen Ordners).
         * Im entpackten Archiv liegt sie in webfrontend/htmlauth/, dort ist es
         * der Ordner drei Ebenen hoeher; nur dort gilt der Rueckfall. */
        $plugin = '';
        if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
            $plugin = basename(__DIR__);
        } else {
            foreach (array(basename(__DIR__), basename(dirname(dirname(__DIR__)))) as $sg_kand) {
                if ($sg_kand === '' || in_array($sg_kand, array('htmlauth', 'html', 'plugins', 'webfrontend'), true)) { continue; }
                if (!$home || is_dir($home . '/config/plugins/' . $sg_kand)) { $plugin = $sg_kand; break; }
            }
        }
        if ($plugin === '') { $plugin = 'signalbot'; }
    }
    if ($home) {
        return array(
            'home' => $home, 'plugin' => $plugin,
            'config'    => $home . '/config/plugins/' . $plugin . '/signalbot.json',
            /* Die Zweitschrift liegt NEBEN dem Plugin-Ordner, nicht darin.
             * LoxBerry entfernt config/plugins/<ordner>/ bei jedem Update -
             * eine Sicherung im Ordner stuerbe genau in dem Fall mit, fuer den
             * es sie gibt. Eingespielt wird sie seit dem Durchgang 01.10.2026
             * nur, wenn die Upgrade-Marke liegt (Entscheidung 1). */
            'sicherung' => $home . '/config/plugins/' . $plugin . '.backup.signalbot.json',
            'sicherung_alt' => $home . '/config/plugins/' . $plugin . '/signalbot.backup.json',
            'configdir' => $home . '/config/plugins/' . $plugin,
            'datadir'      => $home . '/data/plugins/' . $plugin,
            'marke'     => $home . '/data/plugins/' . $plugin . '.upgrade_laeuft',
            'bestand'   => $home . '/data/plugins/' . $plugin . '.bestand',
            'abo'       => $home . '/config/plugins/' . $plugin . '/mqtt_subscriptions.cfg',
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
        'datadir' => sys_get_temp_dir() . '/signalbot',
        'marke' => '', 'bestand' => '', 'abo' => '',
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
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    return $p['datadir'];
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
        'wert' => '1',       // Nutzlast bei fester Nutzlast
        'wert_art' => 'fest',// fest | zahl - bei 'zahl' schickt der Absender ihn mit
        'min' => 0,          // nur bei 'zahl': erlaubter Bereich
        'max' => 100,
        'stufe' => 'sofort', // sofort | rueckfrage | pin
        'absender' => '',    // leer = alle Erlaubten, sonst Liste von Rufnummern
        'zweit' => '',       // Vier-Augen: diese Nummer muss zusaetzlich freigeben
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
        // Betrieb
        'gesperrt'       => 0,         // Kill-Schalter: nimmt keine Befehle mehr an
        'audit'          => 1,         // Ereignisprotokoll fuehren
        'herzschlag'     => 1,         // Lebenszeichen auf MQTT
        'gruppe'         => '',        // Gruppen-Kennung fuer Meldungen (leer = einzeln)
        'nacht_von'      => '',        // Nachtruhe, z. B. 22:00 - leer = aus
        'nacht_bis'      => '',        //             z. B. 07:00
        'quittung_takt'  => 5,         // Minuten zwischen Wiederholungen (dringend)
        'quittung_max'   => 3,         // so oft hoechstens wiederholen
        // MQTT
        'mqtt_ein'       => 1,
        'mqtt_topic'     => 'signalbot',
        // Endpunkt
        'aktionstoken'   => '',
    );
}

/* ==================================================================
 * Eine Liste fuer alles, was ein Befehl nicht sein darf (U10, M6)
 *
 * Bis 0.9.25 standen die reservierten Woerter zweimal da: im Formular
 * (zehn Woerter) und in der Pruefzeile des Reiters Test (sieben, ohne yes, no,
 * ok). "ok" wurde beim Speichern beanstandet, die Pruefzeile zeigte trotzdem
 * einen Haken. Jetzt gibt es diese eine Liste fuer Formular, Zurueckspielen
 * und Pruefzeile - dazu die eingebauten Woerter quittiert/quittieren/ack und
 * abbrechen/stop, die ein Befehl gleichen Namens nie erreichen wuerde.
 * ================================================================== */
function sg_reserviert()
{
    return array('hilfe', 'help', '?', 'status', 'zustand', 'ja', 'nein', 'yes', 'no', 'ok',
                 'quittiert', 'quittieren', 'ack', 'abbrechen', 'stop');
}

/** Ein Befehlswort, das der Bot schon selbst belegt - auch "status xyz". */
function sg_wort_reserviert($wort)
{
    $w = (string) $wort;
    return in_array($w, sg_reserviert(), true)
        || strpos($w, 'status ') === 0 || strpos($w, 'zustand ') === 0;
}

/** Themen, die der Bot selbst sendet (Lebenszeichen, Selbstpruefung). */
function sg_thema_reserviert($thema)
{
    $t = strtolower(trim((string) $thema, '/'));
    foreach (array('online', 'selbsttest') as $r) {
        if ($t === $r || strpos($t, $r . '/') === 0) { return true; }
    }
    return false;
}

/* ---- Einzelpruefungen: dieselben fuer Formular und Zurueckspielen ---- */
function sg_ist_nummer($n) { return is_string($n) && preg_match('/^\+[0-9]{6,20}$/', $n) === 1; }
function sg_ist_ganz($v, $min, $max) { return is_int($v) && $v >= $min && $v <= $max; }
function sg_ist_schalter($v) { return $v === 0 || $v === 1 || $v === true || $v === false; }
function sg_ist_text($v, $max)
{
    return is_string($v) && strlen($v) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $v);
}
/** Rechner und Port, KEIN Pfad - sg_rpc() haengt /api/v1/... selbst an. */
function sg_rpc_url_gueltig($u)
{
    return is_string($u) && preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#', $u) === 1;
}
/** Ein MQTT-Thema(stueck): Buchstaben, Ziffern, _ . - und / als Trenner, ohne leere Stufe. */
function sg_thema_gueltig($t, $max = 128)
{
    return is_string($t) && strlen($t) <= $max
        && preg_match('#^[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)*$#', $t) === 1;
}
/** Ein echter Hash aus password_hash() - eine Liste oder ein Wort ist keiner. */
function sg_pin_hash_gueltig($h)
{
    if ($h === '') { return true; }
    if (!is_string($h) || strlen($h) > 255) { return false; }
    $i = password_get_info($h);
    return !empty($i['algo']);
}
/** Eine Zahl, wie ein Mensch sie tippt: nur Ziffern (und ein Minus), sonst null. */
function sg_ganz_lesen($s)
{
    if (!is_string($s)) { return null; }
    $s = trim($s);
    return preg_match('/^-?[0-9]{1,9}$/', $s) ? (int) $s : null;
}

/** Beschriftung eines Schluessels fuer Meldungen. */
function sg_feldname($k)
{
    $karte = array(
        'rpc_url' => 'EINST.L_RPC', 'konto' => 'EINST.L_KONTO', 'erlaubt' => 'EINST.L_ERLAUBT',
        'pin' => 'EINST.L_PIN', 'pin_hash' => 'EINST.L_PIN', 'pin_versuche' => 'EINST.L_PIN_VERSUCHE',
        'pin_sperre' => 'EINST.L_PIN_SPERRE', 'bremse' => 'EINST.L_BREMSE', 'stille' => 'EINST.L_STILLE',
        'zustand_ein' => 'EINST.L_ZUSTAND', 'gesperrt' => 'EINST.H_SPERRE', 'audit' => 'EINST.L_AUDIT',
        'herzschlag' => 'EINST.L_HERZSCHLAG', 'gruppe' => 'EINST.L_GRUPPE', 'nacht_von' => 'EINST.L_NACHT_VON',
        'nacht_bis' => 'EINST.L_NACHT_BIS', 'quittung_takt' => 'EINST.L_QUITTUNG_TAKT',
        'quittung_max' => 'EINST.L_QUITTUNG_MAX', 'mqtt_ein' => 'EINST.L_MQTT_EIN',
        'mqtt_topic' => 'EINST.L_MQTT_TOPIC', 'aktionstoken' => 'TEST.F_TOKEN', 'befehle' => 'BEF.H_TITEL',
    );
    return isset($karte[$k]) ? sg_t($karte[$k]) : (string) $k;
}

/**
 * Was an einer (vollstaendigen) Konfiguration nicht stimmt - EINE Pruefung
 * fuer die drei Formulare, das Zurueckspielen und die Warnung beim Sichern
 * (C3, U3, U6, X-3). Streng nach Typ: eine Liste ist kein Token, "nein" ist
 * kein Schalter, 999 ist keine Bremse.
 *
 * Anlass (Durchgang 01.10.2026, gemessen): Eine Sicherung mit dem Token als
 * Liste, einem leeren Token, "erlaubt" als Text, "befehle" als Text,
 * "bremse 999", "pin_hash" als Liste, "stufe" als Liste, "gesperrt":"nein"
 * oder "rpc_url":"javascript:..." wurde mit "22 Werte uebernommen" quittiert;
 * danach waren die Adressen im Miniserver tot, die Weissliste leer, der Bot
 * gesperrt oder der PIN-Schutz weg, und im Reiter Test stand ein
 * javascript:-Link.
 *
 * Rueckgabe: array(Feldschluessel => Meldung). Befehlszeilen tragen den
 * Schluessel "befehle.<zeile>.<feld>" (Zeile ab 0). Meldungen sind HTML,
 * eingesetzte Werte maskiert.
 */
function sg_config_maengel($c)
{
    $m = array();
    $setze = function ($k, $text) use (&$m) { if (!isset($m[$k])) { $m[$k] = $text; } };
    if (!is_array($c)) { return array('*' => sg_t('EINST.SICH_KEIN_JSON')); }
    $hol = function ($k) use ($c) { return array_key_exists($k, $c) ? $c[$k] : null; };

    if (!sg_rpc_url_gueltig($hol('rpc_url'))) { $setze('rpc_url', sg_t('EINST.FEHLER_URL')); }
    $k = $hol('konto');
    if (!($k === '' || sg_ist_nummer($k))) { $setze('konto', sg_t('EINST.FEHLER_KONTO')); }

    $e = $hol('erlaubt');
    if (!is_array($e) || ($e !== array() && array_keys($e) !== range(0, count($e) - 1))) {
        $setze('erlaubt', sprintf(sg_t('EINST.FEHLER_NUMMER'), sg_e(is_array($e) ? '…' : (is_scalar($e) ? (string) $e : '…'))));
        $e = array();
    } else {
        $schlecht = array();
        foreach ($e as $n) { if (!sg_ist_nummer($n)) { $schlecht[] = is_scalar($n) ? (string) $n : '…'; } }
        if ($schlecht) {
            $setze('erlaubt', sprintf(sg_t('EINST.FEHLER_NUMMER'), sg_e(implode(', ', $schlecht))));
        } elseif (count(array_unique($e)) !== count($e)) {
            $setze('erlaubt', sg_t('EINST.FEHLER_DOPPELT_NUMMER'));
        }
    }
    $p = $hol('pin');
    if (!($p === '' || (is_string($p) && preg_match('/^[0-9A-Za-z]{4,64}$/', $p)))) {
        $setze('pin', sg_t('EINST.FEHLER_PIN_ZEICHEN'));
    }
    if (!sg_pin_hash_gueltig($hol('pin_hash'))) { $setze('pin', sg_t('EINST.FEHLER_PIN_HASH')); }

    foreach (array('pin_versuche' => array(1, 10), 'pin_sperre' => array(1, 1440), 'bremse' => array(1, 60),
                   'quittung_takt' => array(1, 120), 'quittung_max' => array(0, 20)) as $bk => $gr) {
        if (!sg_ist_ganz($hol($bk), $gr[0], $gr[1])) {
            $setze($bk, sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_e(sg_feldname($bk)), $gr[0], $gr[1]));
        }
    }
    foreach (array('stille', 'zustand_ein', 'gesperrt', 'audit', 'herzschlag', 'mqtt_ein') as $sk) {
        if (!sg_ist_schalter($hol($sk))) {
            $setze($sk, sprintf(sg_t('EINST.FEHLER_SCHALTER'), sg_e(sg_feldname($sk))));
        }
    }
    $g = $hol('gruppe');
    if (!($g === '' || (is_string($g) && preg_match('#^[A-Za-z0-9+/=_\-]{10,}$#', $g)))) {
        $setze('gruppe', sg_t('EINST.FEHLER_GRUPPE'));
    }
    $nv = $hol('nacht_von'); $nb = $hol('nacht_bis');
    $zf = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    if (!(($nv === '' && $nb === '')
          || (is_string($nv) && is_string($nb) && preg_match($zf, $nv) && preg_match($zf, $nb) && $nv !== $nb))) {
        $setze('nacht_von', sg_t('EINST.FEHLER_NACHT'));
    }
    if (!sg_thema_gueltig($hol('mqtt_topic'), 64)) { $setze('mqtt_topic', sg_t('EINST.FEHLER_TOPIC')); }
    $t = $hol('aktionstoken');
    if (!is_string($t) || !preg_match('/^[A-Za-z0-9]{24,}$/', $t)) {
        $setze('aktionstoken', sg_t('EINST.FEHLER_TOKEN'));
    }

    /* ---- Befehlstabelle ---- */
    $bef = $hol('befehle');
    if (!is_array($bef) || count($bef) > SG_BEFEHLE
        || ($bef !== array() && array_keys($bef) !== range(0, count($bef) - 1))) {
        $setze('befehle', sprintf(sg_t('BEF.FEHLER_TABELLE'), SG_BEFEHLE));
        return $m;
    }
    $pin_da = sg_pin_gesetzt(array('pin' => is_string($p) ? $p : '',
                                   'pin_hash' => is_string($hol('pin_hash')) ? $hol('pin_hash') : ''));
    $erlaubt = is_array($e) ? $e : array();
    $gesehen = array();
    $vorgabe = sg_befehl_vorgabe();
    foreach ($bef as $i => $b) {
        $z = $i + 1;
        $s = 'befehle.' . $i . '.';
        if (!is_array($b)) { $setze($s . 'wort', sprintf(sg_t('BEF.FEHLER_ZEILE'), $z)); continue; }
        $fremd = array_diff(array_keys($b), array_keys($vorgabe));
        $fehlt = array_diff(array_keys($vorgabe), array_keys($b));
        if ($fremd || $fehlt) { $setze($s . 'wort', sprintf(sg_t('BEF.FEHLER_ZEILE'), $z)); continue; }
        if (!sg_ist_schalter($b['aktiv'])) { $setze($s . 'aktiv', sprintf(sg_t('BEF.FEHLER_ZEILE'), $z)); }
        if (!sg_ist_text($b['wort'], 60)
            || $b['wort'] !== sg_klein(trim(preg_replace('/\s+/', ' ', $b['wort'])))) {
            $setze($s . 'wort', sprintf(sg_t('BEF.FEHLER_WORT'), $z));
        }
        if (!($b['thema'] === '' || sg_thema_gueltig($b['thema']))) {
            $setze($s . 'thema', sprintf(sg_t('BEF.FEHLER_THEMA_ZEICHEN'), $z));
        }
        if (!sg_ist_text($b['wert'], 200)) { $setze($s . 'wert', sprintf(sg_t('BEF.FEHLER_WERT_ZEICHEN'), $z)); }
        if (!in_array($b['wert_art'], array('fest', 'zahl'), true)) {
            $setze($s . 'wert_art', sprintf(sg_t('BEF.FEHLER_AUSWAHL'), $z));
        }
        if (!sg_ist_ganz($b['min'], -1000000, 1000000)) { $setze($s . 'min', sprintf(sg_t('BEF.FEHLER_GANZ'), $z)); }
        if (!sg_ist_ganz($b['max'], -1000000, 1000000)) { $setze($s . 'max', sprintf(sg_t('BEF.FEHLER_GANZ'), $z)); }
        if (!in_array($b['stufe'], array('sofort', 'rueckfrage', 'pin'), true)) {
            $setze($s . 'stufe', sprintf(sg_t('BEF.FEHLER_AUSWAHL'), $z));
        }
        if (!is_string($b['absender'])) {
            $setze($s . 'absender', sprintf(sg_t('BEF.FEHLER_ABSENDER'), '…', $z));
        } elseif ($b['absender'] !== '') {
            foreach (explode(',', $b['absender']) as $nr) {
                if (!sg_ist_nummer($nr)) {
                    $setze($s . 'absender', sprintf(sg_t('BEF.FEHLER_ABSENDER'), sg_e($nr), $z));
                }
            }
        }
        if (!($b['zweit'] === '' || sg_ist_nummer($b['zweit']))) {
            $setze($s . 'zweit', sprintf(sg_t('BEF.FEHLER_ZWEIT'), sg_e(is_scalar($b['zweit']) ? (string) $b['zweit'] : '…'), $z));
        }
        if (!sg_ist_text($b['antwort'], 500)) { $setze($s . 'antwort', sprintf(sg_t('BEF.FEHLER_ANTWORT'), $z)); }

        /* Inhaltliche Regeln nur fuer eingeschaltete Zeilen mit Wort - wie bisher. */
        if (empty($b['aktiv']) || !is_string($b['wort']) || $b['wort'] === '') { continue; }
        if (sg_wort_reserviert($b['wort'])) {
            $setze($s . 'wort', sprintf(sg_t('BEF.FEHLER_RESERVIERT'), sg_e($b['wort']), $z));
        }
        if (isset($gesehen[$b['wort']])) {
            $setze($s . 'wort', sprintf(sg_t('BEF.FEHLER_DOPPELT'), sg_e($b['wort']), $z));
        }
        $gesehen[$b['wort']] = 1;
        if ($b['thema'] === '') {
            $setze($s . 'thema', sprintf(sg_t('BEF.FEHLER_THEMA'), $z));
        } elseif (is_string($b['thema']) && sg_thema_reserviert($b['thema'])) {
            $setze($s . 'thema', sprintf(sg_t('BEF.FEHLER_THEMA_RESERVIERT'), sg_e($b['thema']), $z));
        }
        if ($b['wert_art'] === 'fest' && $b['wert'] === '') {
            $setze($s . 'wert', sprintf(sg_t('BEF.FEHLER_WERT_LEER'), $z));
        }
        if ($b['wert_art'] === 'zahl' && is_int($b['min']) && is_int($b['max']) && $b['max'] <= $b['min']) {
            $setze($s . 'max', sprintf(sg_t('BEF.FEHLER_BEREICH'), $z));
        }
        if ($b['stufe'] === 'pin' && !$pin_da) {
            $setze($s . 'stufe', sprintf(sg_t('BEF.FEHLER_KEINE_PIN'), sg_e($b['wort'])));
        }
        if (is_string($b['zweit']) && $b['zweit'] !== '' && !in_array($b['zweit'], $erlaubt, true)) {
            $setze($s . 'zweit', sprintf(sg_t('BEF.FEHLER_ZWEIT_LISTE'), sg_e($b['zweit']), $z));
        }
    }
    return $m;
}

/** Liegt die Marke einer laufenden Aktualisierung? (Entscheidung 1: ohne Altersvergleich) */
function sg_upgrade_marke()
{
    $p = sg_paths();
    if (!isset($p['marke']) || $p['marke'] === '') { return false; }
    clearstatcache(true, $p['marke']);
    return is_file($p['marke']);
}

/**
 * Die Lage der Konfigurationsdatei, OHNE etwas zu schreiben (C4, U12):
 * 'ok' | 'fehlt' | 'leer' | 'kaputt' | 'token' (lesbar, aber kein gueltiges Token).
 */
function sg_config_lage()
{
    $p = sg_paths();
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) { return 'fehlt'; }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') { return 'leer'; }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return 'kaputt'; }
    if (!isset($d['aktionstoken']) || !is_string($d['aktionstoken'])
        || !preg_match('/^[A-Za-z0-9]{24,}$/', $d['aktionstoken'])) { return 'token'; }
    return 'ok';
}

/** Wann die Konfiguration zuletzt aus der Zweitschrift geheilt wurde (0 = nie). */
function sg_config_geheilt()
{
    $p = sg_paths();
    $f = $p['tmp'] . '/geheilt';
    return is_file($f) ? (int) @file_get_contents($f) : 0;
}

/**
 * Die Konfiguration lesen.
 *
 * $anlegen = false (C4): nur lesen, NIE schreiben - weder heilen noch ein
 * Token wuerfeln. So ruft der unangemeldete Endpunkt. Bis 0.9.25 legte jeder
 * Abruf des Miniservers auf einem leeren LoxBerry Konfiguration und
 * Zweitschrift an und wuerfelte bei kaputtem Token ein neues (gemessen).
 *
 * Selbstheilung aus der Zweitschrift (I1, Entscheidung 1):
 *  - Datei FEHLT: nur, wenn die Upgrade-Marke liegt (Aktualisierung). Ohne
 *    Marke ist es eine Neuinstallation (oder die Datei wurde von Hand
 *    entfernt): eine liegengebliebene Zweitschrift wird nie eingespielt,
 *    sondern nach <name>.alt gelegt. Bis 0.9.25 holte eine Neuinstallation
 *    Token, PIN-Hash und Weissliste einer frueheren Installation zurueck
 *    (Installer-Pruefstand, Faelle A2/A2L).
 *  - Datei leer oder "{}": Laufzeitschaden an einer bestehenden Anlage; dann
 *    ist die Zweitschrift die laufende dieser Installation und wird gelesen.
 * Die Heilung schreibt mit 0600 (I2; bis 0.9.25 copy() mit den Rechten der
 * umask, gemessen 644). .alt wird nie gelesen.
 */
function sg_config($anlegen = true)
{
    $p = sg_paths();
    clearstatcache(true, $p['config']);
    $sg_vorhanden = is_file($p['config']);
    $roh = $sg_vorhanden ? trim((string) @file_get_contents($p['config'])) : '';
    $sg_beiseite_misslungen = false;
    if ($anlegen && ($roh === '' || $roh === '{}')) {
        $sg_quellen = array();
        if ($sg_vorhanden || sg_upgrade_marke()) {
            $sg_quellen = array($p['sicherung'], $p['sicherung_alt']);
        } elseif ($p['sicherung'] !== '' && is_file($p['sicherung'])) {
            $sg_alt = $p['sicherung'] . '.alt';
            if (@rename($p['sicherung'], $sg_alt)) {
                @chmod($sg_alt, 0600);
                sg_log('Keine Konfiguration und keine Upgrade-Marke: die liegengebliebene Zweitschrift '
                     . 'wird NICHT eingespielt, sie liegt jetzt als ' . $sg_alt . ' (die Deinstallation raeumt sie ab).');
            } else {
                $sg_beiseite_misslungen = true;
                sg_log_gebremst('zweitschrift_fest', 'Zweitschrift ' . $p['sicherung']
                    . ' liess sich nicht beiseitelegen - es wird nichts geschrieben.');
            }
        }
        /* Nur eine BRAUCHBARE Sicherung zurueckholen (seit 0.9.12: eine leere
         * am heutigen Ort darf eine gute am frueheren nicht verdecken). */
        foreach ($sg_quellen as $sg_quelle) {
            if ($sg_quelle === '' || !is_file($sg_quelle)) { continue; }
            $sg_probe = trim((string) @file_get_contents($sg_quelle));
            if ($sg_probe === '' || !is_array(json_decode($sg_probe, true))) { continue; }
            @mkdir($p['configdir'], 0775, true);
            if (!sg_write_atomic($p['config'], $sg_probe, 0600)) { continue; }
            $roh = $sg_probe;
            $sg_vorhanden = true;
            @mkdir($p['tmp'], 0775, true);
            @file_put_contents($p['tmp'] . '/geheilt', (string) time());
            sg_log_gebremst('geheilt', 'Konfiguration aus der Zweitschrift ' . $sg_quelle . ' zurueckgeholt.', 600);
            break;
        }
    }
    $cfg = $roh !== '' ? json_decode($roh, true) : array();
    /* Konnte die Datei NICHT gelesen werden, obwohl sie da ist, wird unten
     * nichts geschrieben (seit 0.9.12; eine leere Datei zaehlt dazu). Sonst
     * wuerden aus einem misslungenen Lesen Vorgaben mit neuem Token, und die
     * Vorgaben ueberschrieben Konfiguration UND Zweitschrift. */
    if ($roh === '' && $sg_vorhanden) {
        if ($anlegen) {
            sg_log_gebremst('config_leer',
                'Konfiguration ist leer und es gibt keine brauchbare Sicherung - '
                . 'es wird nichts geschrieben. Weissliste, PIN und Token bleiben unangetastet, '
                . 'bis die Datei wieder Inhalt hat.');
        }
        $lesbar = false;
    } else {
        $lesbar = is_array($cfg) && !$sg_beiseite_misslungen;
    }
    if (!is_array($cfg)) {
        if ($anlegen && $roh !== '') { sg_log_gebremst('config_unlesbar', 'Konfiguration nicht lesbar - es wird nichts geschrieben.'); }
        $cfg = array();
    }
    $cfg = array_merge(sg_vorgaben(), $cfg);

    /* Beim LESEN wird weiter auf brauchbare Werte gebracht - das ist das Netz
     * fuer eine von Hand verbogene Datei. Die Eingaenge (Formulare,
     * Zurueckspielen) weisen solche Werte seit dem Durchgang 01.10.2026 ab
     * (sg_config_maengel), statt sie still zurechtzubiegen. */
    $cfg['rpc_url'] = rtrim(trim(is_string($cfg['rpc_url']) ? $cfg['rpc_url'] : ''), '/');
    if ($cfg['rpc_url'] === '') { $cfg['rpc_url'] = 'http://127.0.0.1:8095'; }
    foreach (array('bremse' => array(1, 60), 'pin_versuche' => array(1, 10), 'quittung_takt' => array(1, 120),
                   'quittung_max' => array(0, 20), 'pin_sperre' => array(1, 1440)) as $sg_k => $sg_gr) {
        $cfg[$sg_k] = max($sg_gr[0], min($sg_gr[1], is_scalar($cfg[$sg_k]) ? (int) $cfg[$sg_k] : $sg_gr[0]));
    }
    foreach (array('gesperrt', 'audit', 'herzschlag', 'stille', 'mqtt_ein', 'zustand_ein') as $sg_k) {
        $cfg[$sg_k] = empty($cfg[$sg_k]) ? 0 : 1;
    }
    foreach (array('konto', 'pin', 'pin_hash', 'gruppe', 'mqtt_topic', 'aktionstoken', 'nacht_von', 'nacht_bis') as $sg_k) {
        if (!is_string($cfg[$sg_k])) { $cfg[$sg_k] = ''; }
    }
    $cfg['gruppe'] = preg_replace('#[^A-Za-z0-9+/=_\-]#', '', $cfg['gruppe']);
    foreach (array('nacht_von', 'nacht_bis') as $sg_nz) {
        $cfg[$sg_nz] = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $cfg[$sg_nz]) ? $cfg[$sg_nz] : '';
    }
    $cfg['mqtt_topic'] = trim(preg_replace('#[^A-Za-z0-9_./\-]#', '', $cfg['mqtt_topic']), '/');
    if ($cfg['mqtt_topic'] === '') { $cfg['mqtt_topic'] = 'signalbot'; }

    if (!is_array($cfg['erlaubt'])) { $cfg['erlaubt'] = array(); }
    $cfg['erlaubt'] = array_values(array_filter(array_map(function ($n) {
        $n = preg_replace('/[^0-9+]/', '', is_scalar($n) ? (string) $n : '');
        return preg_match('/^\+[0-9]{6,20}$/', $n) ? $n : '';
    }, $cfg['erlaubt'])));

    if (!is_array($cfg['befehle'])) { $cfg['befehle'] = array(); }
    for ($i = 0; $i < SG_BEFEHLE; $i++) {
        $b = isset($cfg['befehle'][$i]) && is_array($cfg['befehle'][$i]) ? $cfg['befehle'][$i] : array();
        $b += sg_befehl_vorgabe();
        foreach (array('wort', 'thema', 'wert', 'stufe', 'wert_art', 'absender', 'zweit', 'antwort') as $sg_k) {
            if (!is_scalar($b[$sg_k])) { $b[$sg_k] = ''; }
        }
        $b['aktiv'] = empty($b['aktiv']) ? 0 : 1;
        // Das Wort wird kleingeschrieben verglichen - "Unscharf" und
        // "unscharf" sollen dasselbe tun.
        $b['wort'] = sg_klein(trim(preg_replace('/\s+/', ' ', (string) $b['wort'])));
        $b['thema'] = preg_replace('#[^A-Za-z0-9_./\-]#', '', (string) $b['thema']);
        $b['wert'] = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $b['wert']));
        $b['stufe'] = in_array($b['stufe'], array('sofort', 'rueckfrage', 'pin'), true) ? $b['stufe'] : 'sofort';
        $b['wert_art'] = in_array($b['wert_art'], array('fest', 'zahl'), true) ? $b['wert_art'] : 'fest';
        $b['min'] = is_scalar($b['min']) ? (int) $b['min'] : 0;
        $b['max'] = is_scalar($b['max']) ? (int) $b['max'] : 100;
        if ($b['max'] < $b['min']) { $b['max'] = $b['min']; }
        $b['absender'] = implode(',', array_filter(array_map(function ($n) {
            $n = preg_replace('/[^0-9+]/', '', (string) $n);
            return preg_match('/^\+[0-9]{6,20}$/', $n) ? $n : '';
        }, explode(',', (string) $b['absender']))));
        $sg_z = preg_replace('/[^0-9+]/', '', (string) $b['zweit']);
        $b['zweit'] = preg_match('/^\+[0-9]{6,20}$/', $sg_z) ? $sg_z : '';
        $b['antwort'] = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $b['antwort']));
        $cfg['befehle'][$i] = $b;
    }
    $cfg['befehle'] = array_slice(array_values($cfg['befehle']), 0, SG_BEFEHLE);

    if ($anlegen && $lesbar && !preg_match('/^[A-Za-z0-9]{24,}$/', $cfg['aktionstoken'])) {
        $cfg['aktionstoken'] = sg_token();
        sg_config_write($cfg);
    }
    return $cfg;
}

/**
 * Sperre fuer jede Lese-Aendern-Schreib-Folge der Konfiguration (C9).
 *
 * Bis 0.9.25 lasen Oberflaeche und Endpunkt (Kill-Schalter aus Loxone) die
 * ganze Datei, aenderten und schrieben sie ganz zurueck - ohne gemeinsame
 * Sperre. Kam "sperren" waehrend des Speicherns an, schrieb die Oberflaeche
 * gesperrt=0 zurueck: die Sperre war still aufgehoben. Verschachtelte
 * Aufrufe im selben Prozess sind harmlos (Zaehler).
 */
function sg_config_sperre($nehmen)
{
    static $fp = null, $tiefe = 0;
    if ($nehmen) {
        if ($tiefe > 0) { $tiefe++; return true; }
        $p = sg_paths();
        if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
        $fp = @fopen($p['tmp'] . '/config.lock', 'c');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if ($fp) { fclose($fp); }
            $fp = null;
            return false;
        }
        $tiefe = 1;
        return true;
    }
    if ($tiefe > 0) {
        $tiefe--;
        if ($tiefe === 0 && $fp) { flock($fp, LOCK_UN); fclose($fp); $fp = null; }
    }
    return true;
}

/**
 * Lesen - aendern - schreiben unter EINER Sperre, mit frischem Lesen (C9).
 * $aendern bekommt die frisch gelesene Konfiguration und gibt die neue zurueck,
 * oder null fuer "nichts schreiben". Rueckgabe: array(geschrieben/ok, Konfiguration).
 * Ohne Sperre wird nicht geschrieben (faellt geschlossen aus).
 */
function sg_config_aendern($aendern)
{
    if (!sg_config_sperre(true)) {
        sg_log_gebremst('config_sperre', 'Konfiguration: Sperre nicht zu bekommen - nichts geschrieben.');
        return array(false, sg_config());
    }
    $cfg = sg_config();
    $neu = $aendern($cfg);
    $ok = true;
    if (is_array($neu)) {
        $ok = sg_config_write($neu);
        if ($ok) { $cfg = $neu; }
    }
    sg_config_sperre(false);
    return array($ok, $cfg);
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
    /* Seit dem Durchgang 01.10.2026 (C7): die Nebendatei wird leer angelegt
     * ("x": nie eine fremde uebernehmen), DANN bekommt sie ihre Rechte, erst
     * danach ihren Inhalt - vorher stand der Inhalt einen Augenblick mit den
     * Rechten der umask da. */
    $fp = @fopen($tmp, 'xb');
    if ($fp === false) { return false; }
    @chmod($tmp, $rechte);
    $n = @fwrite($fp, $inhalt);
    $gut = @fflush($fp);
    @fclose($fp);
    if ($n !== strlen($inhalt) || !$gut) { @unlink($tmp); return false; }
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
    if ($js === false) { return false; }
    if (!sg_config_sperre(true)) { return false; }
    $ok = sg_write_atomic($p['config'], $js, 0600);
    // Die Sicherung erst NACH dem gelungenen Schreiben nachziehen, und
    // ebenfalls unteilbar: sie ist die letzte Zuflucht, wenn die
    // Konfiguration einmal nicht lesbar ist. Misslingt sie, steht es im
    // Protokoll (bis 0.9.25 blieb das still; I4).
    if ($ok && !sg_write_atomic($p['sicherung'], $js, 0600)) {
        sg_log_gebremst('zweitschrift', 'Zweitschrift ' . $p['sicherung'] . ' liess sich nicht schreiben.');
    }
    sg_config_sperre(false);
    return $ok;
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
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
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

/**
 * Eine Adresse abrufen und den HTTP-Code aus den Kopfzeilen lesen (C6, Bauart A).
 * Rueckgabe: array(Inhalt oder false, Code; 0 = kein Code erkennbar).
 *
 * Ueber fopen() und stream_get_meta_data() statt ueber die alte
 * Kopfzeilen-Variable von PHP: 8.5 meldet sie als ueberholt (gemessen, auch
 * schon beim Laden der Bibliothek), und mit PHP 9 hiesse jeder Code 0 - dann
 * stuende OK=0 dauerhaft. Bauform ap_http_abruf() (APC-UPS 1.2.17). Bei einer
 * Weiterleitung stehen mehrere Statuszeilen darin; es gilt die letzte.
 */
function sg_http_abruf($url, $ctx)
{
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(false, 0);
    }
    $meta = @stream_get_meta_data($fp);
    $t = @stream_get_contents($fp, 65536);
    @fclose($fp);
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    return array($t, $code);
}

/**
 * Laeuft der signal-cli-Dienst? Fragt den Lebenszeichen-Endpunkt.
 *
 * Ausgewertet wird die STATUSZEILE, nicht das blosse Ankommen einer Antwort
 * (seit 0.9.12: mit ignore_errors kommt auch eine 404- oder 500-Seite an).
 * Rueckgabe von sg_daemon_pruefen(): array(lebt, HTTP-Code; 0 = keine Antwort).
 */
function sg_daemon_pruefen()
{
    $cfg = sg_config();
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
    list($a, $code) = sg_http_abruf($cfg['rpc_url'] . '/api/v1/check', $ctx);
    if ($a === false) { return array(false, 0); }
    return array($code >= 200 && $code < 300, $code);
}

function sg_daemon_lebt()
{
    list($lebt, ) = sg_daemon_pruefen();
    return $lebt;
}

function sg_dienst_laeuft()
{
    $aus = array();
    @exec('systemctl is-active signal-cli-loxberry 2>/dev/null', $aus);
    return trim(implode('', $aus)) === 'active';
}

/** Startet der Dienst beim Hochfahren mit? enabled | disabled | unbekannt */
function sg_dienst_autostart()
{
    $aus = array(); $rc = 0;
    @exec('systemctl is-enabled signal-cli-loxberry 2>/dev/null', $aus, $rc);
    $t = trim(implode('', $aus));
    return in_array($t, array('enabled', 'disabled', 'static', 'masked'), true) ? $t : 'unbekannt';
}

/**
 * Den Dienst steuern - ohne root.
 *
 * postroot.sh legt eine sudo-Regel fuer genau diese Unit und genau diese
 * Unterbefehle an. Der Benutzer loxberry hat auf einem LoxBerry nicht
 * zwangslaeufig allgemeine sudo-Rechte; ohne die Regel liesse sich der Dienst
 * weder aus der Oberflaeche noch ueber SSH steuern.
 */
function sg_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart', 'enable', 'disable'), true)) {
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
/**
 * Eine Nachricht senden - an eine Rufnummer, an die Gruppe, mit Anhang.
 *
 * $an = '' zusammen mit einer eingetragenen Gruppen-Kennung schickt in die
 * Gruppe. $anhang ist ein Dateipfad auf dem LoxBerry (etwa ein Kamerabild,
 * das Loxone gerade abgelegt hat); er wird nur mitgegeben, wenn die Datei
 * wirklich da und lesbar ist - sonst ginge die ganze Meldung verloren, und
 * das waere im Alarmfall der schlechteste Tausch.
 */
function sg_senden($an, $text, $anhang = '')
{
    $cfg = sg_config();
    $params = array('message' => (string) $text);
    if ((string) $an !== '') {
        $params['recipient'] = array((string) $an);
    } elseif ((string) $cfg['gruppe'] !== '') {
        $params['groupId'] = (string) $cfg['gruppe'];
    } else {
        sg_log('Senden ohne Empfaenger und ohne Gruppe - nichts zu tun.');
        return 0;
    }
    if ((string) $anhang !== '') {
        if (is_file($anhang) && is_readable($anhang)) {
            $params['attachments'] = array((string) $anhang);
        } else {
            sg_log('Anhang ' . $anhang . ' fehlt oder ist nicht lesbar - Meldung geht ohne ihn raus.');
        }
    }
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
    $js = json_encode($liste);
    ftruncate($fp, 0);
    rewind($fp);
    $n = fwrite($fp, $js);
    $gut = fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($f, 0600);
    /* Ein Zaehlschritt, der nicht geschrieben wurde, ist keiner (C8): bei
     * vollem /tmp blieb der Zaehler bis 0.9.25 stehen, und die Bremse - die
     * einzige Grenze fuer das PIN-Raten - fiel offen aus. */
    if ($n !== strlen($js) || !$gut) {
        sg_log_gebremst('bremse_schreiben', 'Bremse: ' . $f . ' liess sich nicht schreiben - Befehl abgewiesen.');
        return false;
    }
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
    $notfall = max(60, (int) $cfg['pin_sperre'] * 60);
    /* Unter Sperre und mit Laengenpruefung (C8), wie die Bremse. Bis 0.9.25
     * wurde ungeprueft geschrieben; scheiterte das, zaehlte nichts mit, und
     * das Raten war unbegrenzt. Jetzt gilt: nicht gezaehlt = gesperrt. */
    $fp = @fopen($f, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if ($fp) { fclose($fp); }
        sg_log_gebremst('pinfehl_defekt', 'PIN-Fehlzaehler ' . $f . ' nicht nutzbar - PIN-Befehle sind gesperrt.');
        return $notfall;
    }
    $roh = (string) stream_get_contents($fp);
    $d = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($d)) { $d = array(); }
    $n = isset($d['n']) ? (int) $d['n'] + 1 : 1;
    $bis = 0;
    if ($n >= (int) $cfg['pin_versuche']) {
        $bis = time() + ((int) $cfg['pin_sperre'] * 60);
        $n = 0;
        sg_log('PIN-Sperre fuer ' . sg_maske($nummer) . ': ' . (int) $cfg['pin_sperre']
             . ' Minuten nach ' . (int) $cfg['pin_versuche'] . ' Fehlversuchen.');
    }
    $js = json_encode(array('n' => $n, 'bis' => $bis));
    ftruncate($fp, 0);
    rewind($fp);
    $w = fwrite($fp, $js);
    $gut = fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($f, 0600);
    if ($w !== strlen($js) || !$gut) {
        sg_log_gebremst('pinfehl_schreiben', 'PIN-Fehlzaehler ' . $f . ' liess sich nicht schreiben - gesperrt.');
        return $notfall;
    }
    return $bis > 0 ? $bis - time() : 0;
}

/**
 * Laesst sich der PIN-Fehlzaehler dieses Absenders fuehren? (C8)
 * Vor einer PIN-Anforderung gefragt: ohne Zaehler keine PIN-Abfrage.
 */
function sg_pin_zaehler_bereit($nummer)
{
    $f = sg_tmpdir() . '/pinfehl_' . md5((string) $nummer) . '.json';
    $fp = @fopen($f, 'c+');
    if ($fp === false) { return false; }
    $ok = flock($fp, LOCK_EX);
    if ($ok) { flock($fp, LOCK_UN); }
    fclose($fp);
    @chmod($f, 0600);
    return $ok;
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

function sg_zustand_text($nur = '')
{
    $z = sg_zustaende();
    if (!$z) { return sg_t('BOT.KEINE_ZUSTAENDE'); }
    ksort($z);
    if ($nur !== '') {
        // Ein einzelner Zustand - "status alarm" statt der ganzen Liste.
        foreach ($z as $name => $d) {
            if (sg_klein($name) === sg_klein($nur)) { $z = array($name => $d); $nur = ''; break; }
        }
        if ($nur !== '') { return sprintf(sg_t('BOT.KEIN_ZUSTAND'), $nur); }
    }
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
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        $out['fassung'] = isset($gen[$k]['Gatewayversion'])
            ? (int) $gen[$k]['Gatewayversion'] : 0;
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
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function sg_abo_text()
{
    $m = sg_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return sg_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(sg_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return sg_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
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

/** Das volle Thema, wie es hinausgeht - EINE Stelle fuer Sendecode und Themenliste. */
function sg_mqtt_thema_voll($cfg, $thema)
{
    return $cfg['mqtt_topic'] . '/' . ltrim((string) $thema, '/');
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
    $voll = sg_mqtt_thema_voll($cfg, $thema);
    $w = sg_mqtt_wert_saeubern($wert);
    /* Ein leerer Wert geht nicht hinaus (M5): am Miniserver kommt er als 0
     * an, ein Befehl loeste also nie aus - und der Bot meldete Erfolg. */
    if ($w === '') {
        sg_log('MQTT: leerer Wert fuer ' . $voll . ' - nicht gesendet.');
        return 0;
    }
    return sg_udp_senden($z['udpport'], 'publish ' . $voll . ' ' . $w);
}

/**
 * Ein echter Impuls fuer einen festen Befehl (M3, Entscheidung 28): Wert
 * senden, nach einer Sekunde 0 - beides fluechtig (publish).
 *
 * Bis 0.9.25 blieb der Wert stehen. Ein virtueller Eingang reagiert auf eine
 * Aenderung; ab dem zweiten gleichen Befehl ("licht an" zweimal) sah Loxone
 * nichts mehr, und der Bot meldete trotzdem "erledigt" (gemessen an der
 * Gateway-Attrappe: 1, 1 ohne 0 dazwischen). Ein fester Wert 0 hat keine
 * Rueckstellung - er ist schon die Ruhelage.
 */
function sg_mqtt_impuls($thema, $wert)
{
    $ok = sg_mqtt_pulsen($thema, $wert);
    if (!$ok || sg_mqtt_wert_saeubern($wert) === '0') { return $ok; }
    usleep(1000000);
    if (!sg_mqtt_pulsen($thema, '0')) {
        sg_log('MQTT: Rueckstellung von ' . $thema . ' auf 0 nicht gesendet - der naechste gleiche Befehl kann in Loxone ausbleiben.');
    }
    return $ok;
}

/**
 * Was der Bot auf MQTT sendet - EINE Liste fuer Sendecode, Themenliste im
 * Reiter MQTT und Pruefzeile (M8, U12, U18). Je Eintrag: thema (voll),
 * wert (Anzeige), retained (immer 0 - Impulse, Lebenszeichen und Probe sind
 * fluechtig, Entscheidung 3), quelle (Befehlswort, 'online' oder 'selbsttest').
 */
function sg_mqtt_themen($cfg)
{
    $aus = array();
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '' || $b['thema'] === '') { continue; }
        $aus[] = array(
            'thema' => sg_mqtt_thema_voll($cfg, $b['thema']),
            'wert' => $b['wert_art'] === 'zahl'
                ? (int) $b['min'] . '..' . (int) $b['max']
                : ($b['wert'] === '0' ? '0' : sprintf(sg_t('MQTT.W_IMPULS'), $b['wert'])),
            'retained' => 0, 'quelle' => $b['wort'], 'art' => $b['wert_art'],
        );
    }
    if (!empty($cfg['herzschlag'])) {
        $aus[] = array('thema' => sg_mqtt_thema_voll($cfg, 'online'), 'wert' => sg_t('MQTT.W_ZEITSTEMPEL'),
                       'retained' => 0, 'quelle' => 'online', 'art' => 'online');
    }
    $aus[] = array('thema' => sg_mqtt_thema_voll($cfg, 'selbsttest'), 'wert' => sg_t('MQTT.W_PROBE'),
                   'retained' => 0, 'quelle' => 'selbsttest', 'art' => 'selbsttest');
    return $aus;
}

/**
 * Die Abo-Datei des MQTT-Gateways: config/plugins/<ordner>/mqtt_subscriptions.cfg (M9).
 *
 * Das Gateway V1 liest sie selbst und abonniert jede Zeile (am Geraet belegt
 * 13.09.2026 an Midea2Lox, Bauform Einspeisebremse 0.9.20 eb_abo_datei()).
 * Der Praefix ist einstellbar - deshalb wird sie beim Speichern im Reiter MQTT
 * und beim Start des Bots auf den aktuellen Praefix nachgeschrieben, wenn sie
 * abweicht. Rueckgabe: array(Pfad, traegt das Abo).
 */
function sg_abo_datei($praefix, $schreiben = false)
{
    $p = sg_paths();
    $pfad = isset($p['abo']) ? $p['abo'] : '';
    if ($pfad === '') { return array('', false); }
    $soll = trim((string) $praefix, '/') . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $roh !== $soll . "\n" && is_dir($p['configdir'])) {
        if (sg_write_atomic($pfad, $soll . "\n", 0644)) {
            sg_log('Gateway-Abo gesetzt: ' . $soll);
            $da = true;
        }
    }
    return array($pfad, $da);
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
 * Befehle direkt an den Broker, mit Bestaetigung (SignalBot-q1)
 *
 * Bis 0.9.26 ging jeder Befehl an den UDP-Eingang des MQTT-Gateways. Ein
 * UDP-Paket hat keine Quittung: der Eingang verwirft unter Last Pakete, ohne
 * dass der Absender etwas merkt (Regeln/07, am Geraet 17-70 %). Der Bot
 * antwortete deshalb nur "an Loxone uebergeben" (Entscheidung 28).
 *
 * Jetzt spricht der Bot fuer einen Befehl selbst MQTT 3.1.1 mit dem Broker
 * des LoxBerry: Anmeldung mit Brokeruser/Brokerpass aus der general.json
 * (Regeln/07, Abschnitt 2), PUBLISH mit QoS 1 und retain 0 (Befehle sind
 * Impulse, Entscheidung 3), und erst das PUBACK des Brokers gilt als
 * Bestaetigung. Abonniert hat das Thema das MQTT-Gateway (Abodatei
 * <praefix>/#, Gateway V2 von selbst) - es reicht den Befehl genauso an den
 * Miniserver weiter wie einen, der ueber seinen UDP-Eingang kam.
 *
 * Rueckfall auf den UDP-Eingang NUR, wenn nachweislich nichts beim Broker
 * angekommen sein kann: keine Verbindung, Anmeldung abgewiesen (CONNACK
 * ungleich 0) oder keine Antwort auf CONNECT, PUBLISH nicht vollstaendig
 * geschrieben. Jeder Rueckfall steht im Protokoll. Fehlt dagegen nur das
 * PUBACK, kann der Befehl beim Broker sein; ein zweites Senden koennte ihn
 * doppelt ausloesen. Dann wird nichts nachgeschickt, und der Bot sagt, dass
 * er es nicht weiss.
 *
 * Das Kennwort steht nur im CONNECT-Paket - nie in einem Protokoll, einer
 * Ausgabe oder auf einer Kommandozeile.
 * ================================================================== */

/** Sekunden je Antwort des Brokers (CONNACK, PUBACK) und fuer den Verbindungsaufbau. */
define('SG_BROKER_FRIST', 3);

/** Der Broker des LoxBerry aus config/system/general.json (nur gelesen). */
function sg_broker_zugang()
{
    $leer = array('host' => '', 'port' => 0, 'user' => '', 'pass' => '', 'ziel' => '');
    $p = sg_paths();
    if ($p['home'] === '') { return $leer; }
    $gen = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    if (!is_array($gen)) { return $leer; }
    $m = isset($gen['Mqtt']) && is_array($gen['Mqtt']) ? $gen['Mqtt']
       : (isset($gen['mqtt']) && is_array($gen['mqtt']) ? $gen['mqtt'] : null);
    if ($m === null) { return $leer; }
    $hol = function ($a, $b) use ($m) {
        if (isset($m[$a]) && is_scalar($m[$a])) { return trim((string) $m[$a]); }
        return (isset($m[$b]) && is_scalar($m[$b])) ? trim((string) $m[$b]) : '';
    };
    $host = $hol('Brokerhost', 'brokerhost');
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    if (!preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host)) { return $leer; }
    $port = $hol('Brokerport', 'brokerport');
    $port = preg_match('/^[0-9]{1,5}$/', $port) ? (int) $port : 1883;
    if ($port < 1 || $port > 65535) { $port = 1883; }
    return array('host' => $host, 'port' => $port, 'user' => $hol('Brokeruser', 'brokeruser'),
                 'pass' => $hol('Brokerpass', 'brokerpass'), 'ziel' => $host . ':' . $port);
}

/** Ein MQTT-Paket lesen: array(Kopfbyte, Rumpf) oder null (Frist abgelaufen, Verbindung zu). */
function sg_broker_paket($s)
{
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $k = $lies(1);
    if ($k === null) { return null; }
    $n = 0; $mult = 1;
    for ($i = 0; $i < 4; $i++) {
        $b = $lies(1);
        if ($b === null) { return null; }
        $n += (ord($b) & 127) * $mult;
        $mult *= 128;
        if (!(ord($b) & 128)) { break; }
    }
    $r = ($n > 0) ? $lies($n) : '';
    return ($r === null) ? null : array(ord($k), $r);
}

/** Restlaenge nach MQTT 3.1.1, Abschnitt 2.2.3. */
function sg_broker_laenge($n)
{
    $o = '';
    do {
        $b = $n % 128;
        $n = intdiv($n, 128);
        if ($n > 0) { $b |= 128; }
        $o .= chr($b);
    } while ($n > 0);
    return $o;
}

/** Ein Paket VOLLSTAENDIG schreiben - eine teilweise geschriebene Folge ist kein Erfolg. */
function sg_broker_schreiben($s, $paket)
{
    $n = @fwrite($s, $paket);
    return $n !== false && $n === strlen($paket);
}

/**
 * Verbinden und anmelden. Rueckgabe: array('s' => Verbindung oder null,
 * 'grund' => Klartext fuers Protokoll, warum nicht, 'code' => Kennung fuer
 * die Oberflaeche (kein_broker, verbindung, connect, antwort, connack),
 * 'detail' => Fehlertext bzw. CONNACK-Code, 'anmeldung' => 1, wenn ein
 * Benutzer mitging, 'ziel' => host:port).
 */
function sg_broker_verbinden()
{
    $z = sg_broker_zugang();
    $aus = array('s' => null, 'grund' => '', 'code' => '', 'detail' => '',
                 'anmeldung' => $z['user'] !== '' ? 1 : 0, 'ziel' => $z['ziel']);
    if ($z['host'] === '') {
        $aus['grund'] = 'in der general.json steht kein MQTT-Abschnitt mit Broker';
        $aus['code'] = 'kein_broker';
        return $aus;
    }
    /* Ohne stream_socket_client (disable_functions) gaebe es unter PHP 8 einen
       fatalen Fehler statt eines Rueckfalls. */
    if (!function_exists('stream_socket_client')) {
        $aus['detail'] = 'stream_socket_client fehlt';
        $aus['grund'] = 'keine Verbindung (' . $aus['detail'] . ')';
        $aus['code'] = 'verbindung';
        return $aus;
    }
    $errno = 0; $errstr = '';
    $s = @stream_socket_client('tcp://' . $z['host'] . ':' . $z['port'], $errno, $errstr, SG_BROKER_FRIST);
    if (!$s) {
        $aus['detail'] = sg_kuerzen(sg_mqtt_wert_saeubern($errstr !== '' ? $errstr : 'errno ' . $errno), 120);
        $aus['grund'] = 'keine Verbindung (' . $aus['detail'] . ')';
        $aus['code'] = 'verbindung';
        return $aus;
    }
    stream_set_timeout($s, SG_BROKER_FRIST);
    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('sgbot' . getmypid() . 'q' . mt_rand(100, 999));
    if ($z['user'] !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($z['pass'] !== '') { $flags |= 0x40; }
        $nutz .= $zk($z['user']);
        if ($z['pass'] !== '') { $nutz .= $zk($z['pass']); }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 30);
    if (!sg_broker_schreiben($s, chr(0x10) . sg_broker_laenge(strlen($kopf . $nutz)) . $kopf . $nutz)) {
        fclose($s);
        $aus['grund'] = 'CONNECT liess sich nicht senden';
        $aus['code'] = 'connect';
        return $aus;
    }
    $ack = sg_broker_paket($s);
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) {
        fclose($s);
        $aus['grund'] = 'der Broker hat auf CONNECT nicht in ' . SG_BROKER_FRIST . ' s geantwortet';
        $aus['code'] = 'antwort';
        return $aus;
    }
    $cn = ord($ack[1][1]);
    if ($cn !== 0) {
        fclose($s);
        $aus['code'] = 'connack';
        $aus['detail'] = (string) $cn;
        /* Rueckgabecodes nach MQTT 3.1.1, Abschnitt 3.2.2.3. */
        $ct = array(1 => 'Protokollfassung abgelehnt', 2 => 'Client-Kennung abgelehnt',
                    3 => 'Broker nicht verfuegbar', 4 => 'Benutzername oder Kennwort falsch',
                    5 => 'nicht autorisiert');
        $aus['grund'] = 'CONNACK ' . $cn . ' (' . (isset($ct[$cn]) ? $ct[$cn] : 'unbekannter Code') . ')'
                      . ($z['user'] === '' ? ', ohne Anmeldung' : ', mit Anmeldung');
        return $aus;
    }
    $aus['s'] = $s;
    return $aus;
}

/**
 * Eine Nachricht mit QoS 1 und retain 0 senden und auf das PUBACK mit derselben
 * Paketkennung warten. Rueckgabe:
 *   'bestaetigt'     PUBACK erhalten
 *   'unbestaetigt'   PUBLISH ganz geschrieben, PUBACK blieb aus - kann angekommen sein
 *   'nicht_gesendet' PUBLISH nicht vollstaendig geschrieben - beim Broker ist nichts
 */
function sg_broker_publish($s, $thema, $wert, $kennung)
{
    $kennung = max(1, min(65535, (int) $kennung));
    $rumpf = pack('n', strlen($thema)) . $thema . pack('n', $kennung) . $wert;
    // 0x32: PUBLISH, QoS 1, DUP 0, RETAIN 0.
    if (!sg_broker_schreiben($s, chr(0x32) . sg_broker_laenge(strlen($rumpf)) . $rumpf)) {
        return 'nicht_gesendet';
    }
    $ende = microtime(true) + SG_BROKER_FRIST;
    while (microtime(true) < $ende) {
        $pk = sg_broker_paket($s);
        if ($pk === null) { break; }
        if (($pk[0] >> 4) === 4 && strlen($pk[1]) >= 2) {
            $k = unpack('n', substr($pk[1], 0, 2));
            if ((int) $k[1] === $kennung) { return 'bestaetigt'; }
        }
    }
    return 'unbestaetigt';
}

/** Der Grund aus sg_broker_verbinden() in der Sprache der Oberflaeche (Reiter Test). */
function sg_broker_grund_anzeige($v)
{
    switch ((string) $v['code']) {
        case 'kein_broker': return sg_t('TEST.B_KEIN_BROKER');
        case 'verbindung':  return sprintf(sg_t('TEST.B_VERBINDUNG'), (string) $v['detail']);
        case 'connect':     return sg_t('TEST.B_CONNECT');
        case 'antwort':     return sprintf(sg_t('TEST.B_ANTWORT'), SG_BROKER_FRIST);
        case 'connack':     return sprintf(sg_t('TEST.B_CONNACK'), (int) $v['detail'],
                                           sg_t(!empty($v['anmeldung']) ? 'TEST.B_MIT' : 'TEST.B_OHNE'));
    }
    return '-';
}

function sg_broker_trennen($s)
{
    if (is_resource($s)) {
        @fwrite($s, chr(0xE0) . chr(0));
        @fclose($s);
    }
}

/**
 * Verbinden, EINE Nachricht senden, trennen (Probe im Reiter Test).
 * Rueckgabe: array('erg' => 'bestaetigt'|'unbestaetigt'|'nicht_gesendet'|'zu',
 * 'grund' => Klartext bei 'zu', 'ziel' => host:port).
 */
function sg_broker_einmal($thema, $wert)
{
    $v = sg_broker_verbinden();
    if ($v['s'] === null) {
        return array('erg' => 'zu', 'grund' => $v['grund'], 'ziel' => $v['ziel']);
    }
    $erg = sg_broker_publish($v['s'], $thema, $wert, 1);
    sg_broker_trennen($v['s']);
    return array('erg' => $erg, 'grund' => '', 'ziel' => $v['ziel']);
}

/**
 * Einen Befehl hinausschicken - der Sendeweg aller Befehle (SignalBot-q1).
 *
 * $impuls: fester Befehl, nach 1 s geht 0 hinterher (Entscheidung 28); der
 * Wert 0 selbst hat keine Rueckstellung. Beides auf DERSELBEN Verbindung,
 * beides mit QoS 1. Bleibt nur die Bestaetigung der Rueckstellung aus, geht
 * die 0 zusaetzlich ueber den UDP-Eingang - eine doppelte 0 schadet nicht.
 *
 * Rueckgabe: array('ok' => 1, wenn bestaetigt oder an den UDP-Eingang
 * uebergeben, 'weg' => 'broker'|'udp'|'', 'unklar' => 1, wenn der Broker das
 * PUBLISH bekommen, aber nicht bestaetigt hat).
 */
function sg_mqtt_befehl($thema, $wert, $impuls)
{
    $aus = array('ok' => 0, 'weg' => '', 'unklar' => 0);
    $cfg = sg_config();
    if (empty($cfg['mqtt_ein'])) { return $aus; }
    $voll = sg_mqtt_thema_voll($cfg, $thema);
    $w = sg_mqtt_wert_saeubern($wert);
    /* Ein leerer Wert geht nicht hinaus (M5), auf keinem Weg. */
    if ($w === '') {
        sg_log('MQTT: leerer Wert fuer ' . $voll . ' - nicht gesendet.');
        return $aus;
    }
    $v = sg_broker_verbinden();
    $erg = 'zu';
    if ($v['s'] !== null) {
        $erg = sg_broker_publish($v['s'], $voll, $w, 1);
        if ($erg === 'nicht_gesendet') {
            $v['grund'] = 'PUBLISH liess sich nicht vollstaendig senden';
        }
    }
    if ($erg === 'zu' || $erg === 'nicht_gesendet') {
        if ($v['s'] !== null) { sg_broker_trennen($v['s']); }
        sg_log('MQTT: Broker ' . ($v['ziel'] !== '' ? $v['ziel'] : '(unbekannt)') . ' nicht nutzbar - '
             . $v['grund'] . '. Rueckfall: ' . $voll . ' geht ueber den UDP-Eingang des Gateways (unbestaetigt).');
        $ok = $impuls ? sg_mqtt_impuls($thema, $wert) : sg_mqtt_pulsen($thema, $wert);
        return array('ok' => $ok ? 1 : 0, 'weg' => $ok ? 'udp' : '', 'unklar' => 0);
    }
    if ($erg === 'unbestaetigt') {
        sg_broker_trennen($v['s']);
        sg_log('MQTT: der Broker ' . $v['ziel'] . ' hat ' . $voll . '=' . $w . ' nicht in ' . SG_BROKER_FRIST
             . ' s bestaetigt (kein PUBACK). Nicht erneut gesendet - der Befehl koennte sonst doppelt ankommen.');
        return array('ok' => 0, 'weg' => 'broker', 'unklar' => 1);
    }
    if ($impuls && $w !== '0') {
        usleep(1000000);
        $r0 = sg_broker_publish($v['s'], $voll, '0', 2);
        if ($r0 !== 'bestaetigt') {
            sg_log('MQTT: Rueckstellung von ' . $voll . ' auf 0 vom Broker nicht bestaetigt (' . $r0
                 . ') - die 0 geht zusaetzlich ueber den UDP-Eingang des Gateways.');
            if (!sg_mqtt_pulsen($thema, '0')) {
                sg_log('MQTT: Rueckstellung von ' . $thema . ' auf 0 nicht gesendet - der naechste gleiche Befehl kann in Loxone ausbleiben.');
            }
        }
    }
    sg_broker_trennen($v['s']);
    return array('ok' => 1, 'weg' => 'broker', 'unklar' => 0);
}

/* ==================================================================
 * Ereignisprotokoll, Kill-Schalter, Nachtruhe, Ausgangswarteschlange
 * ================================================================== */

/**
 * Das Ereignisprotokoll: wer hat wann was ausgeloest.
 *
 * Getrennt vom Betriebsprotokoll, weil dieses gekuerzt wird, sobald es gross
 * wird - und weil bei einem Baustein, der die Alarmanlage schaltet, die Frage
 * "wer war das" auch noch nach Wochen beantwortbar sein muss. Eine Zeile je
 * Vorgang, aelteste fallen nach SG_EREIGNISSE heraus.
 */
function sg_ereignis_merken($wer, $was, $detail = '')
{
    $cfg = sg_config();
    if (empty($cfg['audit'])) { return false; }
    $f = sg_datadir() . '/ereignisse.json';
    $sperre = @fopen(sg_tmpdir() . '/ereignisse.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($liste)) { $liste = array(); }
    $liste[] = array('ts' => time(), 'wer' => (string) $wer, 'was' => (string) $was,
                     'detail' => (string) $detail);
    if (count($liste) > SG_EREIGNISSE) { $liste = array_slice($liste, -SG_EREIGNISSE); }
    $ok = sg_write_atomic($f, json_encode($liste), 0600);
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $ok;
}

function sg_ereignisse()
{
    $f = sg_datadir() . '/ereignisse.json';
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

/** Ist gerade Nachtruhe? Leere Zeiten heissen: keine. */
function sg_nachtruhe($cfg, $zeit = null)
{
    $von = (string) $cfg['nacht_von'];
    $bis = (string) $cfg['nacht_bis'];
    if ($von === '' || $bis === '' || $von === $bis) { return false; }
    $jetzt = $zeit === null ? (int) date('H') * 60 + (int) date('i') : $zeit;
    list($vh, $vm) = array_map('intval', explode(':', $von));
    list($bh, $bm) = array_map('intval', explode(':', $bis));
    $a = $vh * 60 + $vm;
    $b = $bh * 60 + $bm;
    // Ueber Mitternacht hinweg ist der Normalfall (22:00 bis 07:00).
    return $a < $b ? ($jetzt >= $a && $jetzt < $b) : ($jetzt >= $a || $jetzt < $b);
}

/* ---- Ausgangswarteschlange ----
 *
 * Sie traegt zwei Dinge, die ohne sie nicht gehen:
 *   1. Nachtruhe - eine nicht dringende Meldung wartet bis zum Morgen,
 *      statt jemanden um drei Uhr zu wecken.
 *   2. Dringend mit Quittung - eine Meldung wird wiederholt, bis der
 *      Empfaenger "quittiert" schreibt oder der Zaehler abgelaufen ist.
 * Zugestellt wird aus dem Dauerlaeufer heraus (sg_warteschlange_arbeiten).
 */
function sg_warteschlange()
{
    $f = sg_datadir() . '/ausgang.json';
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function sg_warteschlange_schreiben($liste)
{
    return sg_write_atomic(sg_datadir() . '/ausgang.json', json_encode(array_values($liste)), 0600);
}

/** Eine Meldung einreihen. $ab = fruehester Zeitpunkt, 0 = sofort. */
function sg_warteschlange_ein($an, $text, $dringend = 0, $ab = 0)
{
    $sperre = @fopen(sg_tmpdir() . '/ausgang.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = sg_warteschlange();
    $liste[] = array(
        'id' => sg_token(8), 'an' => (string) $an, 'text' => (string) $text,
        'dringend' => $dringend ? 1 : 0, 'ab' => (int) $ab,
        'versuche' => 0, 'ts' => time(),
    );
    if (count($liste) > 200) { $liste = array_slice($liste, -200); }
    $ok = sg_warteschlange_schreiben($liste);
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $ok;
}

/** Offene dringende Meldungen eines Empfaengers quittieren. */
function sg_quittieren($an)
{
    $sperre = @fopen(sg_tmpdir() . '/ausgang.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = sg_warteschlange();
    $n = 0;
    foreach ($liste as $k => $e) {
        if ($e['an'] === $an && !empty($e['dringend'])) { unset($liste[$k]); $n++; }
    }
    if ($n) { sg_warteschlange_schreiben($liste); }
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $n;
}

/**
 * Faellige Meldungen zustellen. Wird vom Dauerlaeufer regelmaessig gerufen.
 * Rueckgabe: Zahl der zugestellten Meldungen.
 */
function sg_warteschlange_arbeiten()
{
    $cfg = sg_config();
    $liste = sg_warteschlange();
    if (!$liste) { return 0; }
    $jetzt = time();
    $gesendet = 0;
    $sperre = @fopen(sg_tmpdir() . '/ausgang.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = sg_warteschlange();
    foreach ($liste as $k => $e) {
        if ((int) $e['ab'] > $jetzt) { continue; }
        if (empty($e['dringend'])) {
            // Einmal senden, dann ist sie erledigt - gleich ob es klappt.
            sg_senden($e['an'], $e['text']);
            unset($liste[$k]);
            $gesendet++;
            continue;
        }
        // Dringend: wiederholen, bis quittiert wird oder der Zaehler leer ist.
        if ((int) $e['versuche'] >= (int) $cfg['quittung_max'] + 1) {
            sg_log('Dringende Meldung an ' . sg_maske($e['an']) . ' bleibt unquittiert - aufgegeben.');
            sg_ereignis_merken(sg_maske($e['an']), 'unquittiert', sg_kuerzen($e['text'], 60));
            unset($liste[$k]);
            continue;
        }
        $text = $e['versuche'] > 0
            ? sg_t('BOT.WIEDERHOLUNG') . ' ' . $e['text']
            : $e['text'];
        sg_senden($e['an'], $text . "\n" . sg_t('BOT.BITTE_QUITTIEREN'));
        $liste[$k]['versuche'] = (int) $e['versuche'] + 1;
        $liste[$k]['ab'] = $jetzt + ((int) $cfg['quittung_takt'] * 60);
        $gesendet++;
    }
    sg_warteschlange_schreiben($liste);
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $gesendet;
}

/**
 * Meldung nach draussen geben - der eine Weg fuer Loxone und den Bot.
 *
 * Entscheidet, ob sofort gesendet oder eingereiht wird: Nachtruhe haelt eine
 * nicht dringende Meldung zurueck, eine dringende geht immer sofort raus und
 * wird bis zur Quittung wiederholt.
 */
function sg_melden($an, $text, $dringend = 0)
{
    $cfg = sg_config();
    if ($dringend) {
        sg_warteschlange_ein($an, $text, 1, 0);
        return 'dringend';
    }
    if (sg_nachtruhe($cfg)) {
        // bis zum Ende der Nachtruhe zurueckhalten
        list($bh, $bm) = array_map('intval', explode(':', $cfg['nacht_bis']));
        $ziel = mktime($bh, $bm, 0);
        if ($ziel <= time()) { $ziel += 86400; }
        sg_warteschlange_ein($an, $text, 0, $ziel);
        return 'nachtruhe';
    }
    return sg_senden($an, $text) ? 'gesendet' : 'fehler';
}

/**
 * Der naechstliegende Befehl zu einem unbekannten Wort.
 * Ein Tippfehler soll nicht in einer Sackgasse enden.
 */
function sg_vorschlag($wort, $cfg)
{
    $bester = ''; $abstand = 99;
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        $d = levenshtein($wort, $b['wort']);
        if ($d < $abstand) { $abstand = $d; $bester = $b['wort']; }
    }
    // Nur vorschlagen, wenn es wirklich nah dran ist.
    return ($bester !== '' && $abstand <= max(2, (int) floor(strlen($bester) / 3))) ? $bester : '';
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
        /* Der Trockenlauf merkt nichts (I3): bis 0.9.25 trug der Probelauf
         * von postinstall.sh bei jeder Installation ein erfundenes
         * "abgewiesen" ins Ereignisprotokoll ein (Installer-Pruefstand B8). */
        if (!$trocken) { sg_ereignis_merken(sg_maske($von), 'abgewiesen', 'nicht auf der Weissliste'); }
        // Bewusst schweigen: eine Antwort wuerde Fremden bestaetigen, dass
        // hier ein Bot horcht. Wer die Nummer nur vertippt hat, merkt es am
        // ausbleibenden Echo genauso.
        return array('antwort' => empty($cfg['stille']) ? sg_t('BOT.NICHT_ERLAUBT') : '',
                     'grund' => 'nicht_erlaubt');
    }

    /* ---- Kill-Schalter ----
       Ist der Bot gesperrt, wird gar nichts mehr ausgefuehrt. Gedacht fuer
       den Fall, dass ein Handy abhandenkommt: sperren laesst sich das aus
       Loxone heraus (Endpunkt, Aktion sperren) und in der Oberflaeche. Die
       eingebauten Woerter bleiben erreichbar, sonst wuesste niemand, warum
       nichts passiert. */
    if (!empty($cfg['gesperrt']) && !in_array($klein, array('hilfe', 'help', '?'), true)) {
        sg_log('Abgewiesen (Bot gesperrt): ' . sg_maske($von) . ' - ' . sg_kuerzen($text, 40));
        return array('antwort' => sg_t('BOT.GESPERRT'), 'grund' => 'gesperrt');
    }

    /* ---- Schicht 3 (vorgezogen): Bremse ----
       Im Trockenlauf wird nicht gezaehlt: der Probelauf des Verwalters darf
       das Kontingent des Bewohners nicht aufbrauchen. */
    if (!$trocken && !sg_bremse_frei($von)) {
        sg_log('Gebremst: ' . sg_maske($von) . ' (mehr als ' . (int) $cfg['bremse'] . ' je Minute)');
        sg_ereignis_merken(sg_maske($von), 'abgewiesen', 'Bremse');
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
            /* Bei einer Vier-Augen-Freigabe wartet jemand anders auf die
             * Antwort. Ohne diese Nachricht bliebe er ratlos zurueck: sein
             * Befehl wurde nie ausgefuehrt, und niemand hat ihm gesagt warum. */
            if (!empty($warte['fuer'])) {
                sg_senden((string) $warte['fuer'], sg_t('BOT.ZWEIT_ABGELEHNT'));
                sg_ereignis_merken(sg_maske($von), 'Freigabe abgelehnt',
                    $b['wort'] . ' fuer ' . sg_maske($warte['fuer']));
            }
            return array('antwort' => sg_t('BOT.ABGEBROCHEN'), 'grund' => 'abgebrochen');
        }
        if ($warte['art'] === 'pin') {
            // hash_equals gegen das Erraten ueber die Antwortzeit.
            if (!sg_pin_stimmt($cfg, $text)) {
                sg_log('Falsche PIN von ' . sg_maske($von) . ' fuer: ' . $b['wort']);
                sg_ereignis_merken(sg_maske($von), 'PIN falsch', $b['wort']);
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
        /* Wer hier bestaetigt, kann der Absender selbst sein - oder der
         * Zweite bei einem Befehl mit Vier-Augen-Freigabe. Im zweiten Fall
         * steht in der Wartedatei, fuer wen freigegeben wird; dann ist die
         * Freigabe erteilt und wird nicht noch einmal verlangt. */
        $sg_fuer = isset($warte['fuer']) ? (string) $warte['fuer'] : '';
        $sg_mit = isset($warte['wert']) ? (string) $warte['wert'] : null;
        if ($sg_fuer !== '') {
            sg_ereignis_merken(sg_maske($von), 'Freigabe erteilt', $b['wort'] . ' fuer ' . sg_maske($sg_fuer));
        }
        return sg_ausfuehren($b, $sg_fuer !== '' ? $sg_fuer : $von, $trocken, $sg_fuer !== '', $sg_mit);
    }

    /* ---- Eingebaute Woerter ---- */
    if (in_array($klein, array('hilfe', 'help', '?'), true)) {
        return array('antwort' => sg_hilfetext($von), 'grund' => 'hilfe');
    }
    if (in_array($klein, array('quittiert', 'quittieren', 'ack'), true)) {
        if ($trocken) { return array('antwort' => sg_t('BOT.NICHTS_ZU_QUITTIEREN'), 'grund' => 'quittiert'); }
        $n = sg_quittieren($von);
        sg_ereignis_merken(sg_maske($von), 'quittiert', (string) $n);
        return array('antwort' => $n > 0 ? sprintf(sg_t('BOT.QUITTIERT'), $n) : sg_t('BOT.NICHTS_ZU_QUITTIEREN'),
                     'grund' => 'quittiert');
    }
    if ($klein === 'status' || $klein === 'zustand'
        || strpos($klein, 'status ') === 0 || strpos($klein, 'zustand ') === 0) {
        if (empty($cfg['zustand_ein'])) {
            return array('antwort' => sg_t('BOT.ZUSTAND_AUS'), 'grund' => 'zustand_aus');
        }
        $sg_teil = strpos($klein, ' ') !== false ? trim(substr($klein, strpos($klein, ' ') + 1)) : '';
        return array('antwort' => sg_zustand_text($sg_teil), 'grund' => 'status');
    }

    /* ---- Schicht 2: Befehl suchen und Stufe anwenden ---- */
    foreach ($cfg['befehle'] as $i => $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }

        /* Ein Befehl kann einen WERT mitbekommen: "heizung 21".
         * Erwartet wird er nur, wenn die Zeile auf 'zahl' steht - sonst ist
         * alles hinter dem Wort ein anderer Befehl oder ein Vertipper. */
        $sg_mit = null;
        if ($klein === $b['wort']) {
            if ($b['wert_art'] === 'zahl') {
                return array('antwort' => sprintf(sg_t('BOT.WERT_FEHLT'), $b['wort'],
                                 (int) $b['min'], (int) $b['max'], $b['wort']),
                             'grund' => 'wert_fehlt');
            }
        } else {
            if ($b['wert_art'] !== 'zahl') { continue; }
            $sg_vor = $b['wort'] . ' ';
            if (strpos($klein, $sg_vor) !== 0) { continue; }
            $sg_rest = str_replace(',', '.', trim(substr($klein, strlen($sg_vor))));
            if (!preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $sg_rest)) {
                return array('antwort' => sprintf(sg_t('BOT.WERT_UNGUELTIG'), (int) $b['min'], (int) $b['max']),
                             'grund' => 'wert_ungueltig');
            }
            $sg_zahl = (float) $sg_rest;
            if ($sg_zahl < (float) $b['min'] || $sg_zahl > (float) $b['max']) {
                return array('antwort' => sprintf(sg_t('BOT.WERT_BEREICH'), (int) $b['min'], (int) $b['max']),
                             'grund' => 'wert_bereich');
            }
            // Ganze Zahlen ohne Nachkomma schreiben - das erwartet Loxone.
            $sg_mit = (floor($sg_zahl) == $sg_zahl) ? (string) (int) $sg_zahl : (string) $sg_zahl;
        }

        /* Berechtigung je Befehl. Die Weissliste sagt, WER ueberhaupt reden
         * darf; hier steht, wer DIESEN Befehl ausloesen darf. Ohne das duerfte
         * jeder Erlaubte alles - auch das Tor und die Alarmanlage. */
        if ((string) $b['absender'] !== '') {
            $sg_n = preg_replace('/[^0-9+]/', '', (string) $von);
            $sg_darf = false;
            foreach (explode(',', (string) $b['absender']) as $sg_nr) {
                if (hash_equals(trim($sg_nr), $sg_n)) { $sg_darf = true; break; }
            }
            if (!$sg_darf) {
                sg_log('Nicht zustaendig: ' . sg_maske($von) . ' fuer "' . $b['wort'] . '"');
                if (!$trocken) { sg_ereignis_merken(sg_maske($von), 'abgewiesen', $b['wort'] . ' (nicht zustaendig)'); }
                return array('antwort' => sg_t('BOT.NICHT_ZUSTAENDIG'), 'grund' => 'nicht_zustaendig');
            }
        }

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
            if (!$trocken && !sg_pin_zaehler_bereit($von)) {
                return array('antwort' => sg_t('BOT.PIN_STOERUNG'), 'grund' => 'pin_stoerung');
            }
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'pin', 'wort' => $b['wort'], 'stufe' => $b['stufe'], 'wert' => $sg_mit)); }
            return array('antwort' => sg_t('BOT.PIN_BITTE'), 'grund' => 'pin_angefordert');
        }
        if ($b['stufe'] === 'rueckfrage') {
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'rueckfrage', 'wort' => $b['wort'], 'stufe' => $b['stufe'], 'wert' => $sg_mit)); }
            return array('antwort' => sprintf(sg_t('BOT.RUECKFRAGE'), $b['wort']
                         . ($sg_mit === null ? '' : ' ' . $sg_mit)), 'grund' => 'rueckfrage');
        }
        return sg_ausfuehren($b, $von, $trocken, false, $sg_mit);
    }

    // Der Vergleich laeuft am ERSTEN WORT. Wer "heizzung 21" schreibt, hat
    // sich im Befehl vertippt, nicht in der Zahl.
    $sg_erstes = strpos($klein, ' ') === false ? $klein : substr($klein, 0, strpos($klein, ' '));
    $sg_nah = sg_vorschlag($sg_erstes, $cfg);
    if ($sg_nah !== '') {
        return array('antwort' => sprintf(sg_t('BOT.VORSCHLAG'), $sg_nah), 'grund' => 'vorschlag');
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
function sg_ausfuehren($b, $von, $trocken = false, $freigegeben = false, $wert = null)
{
    if ($b['thema'] === '') {
        return array('antwort' => sg_t('BOT.KEIN_THEMA'), 'grund' => 'kein_thema');
    }
    // Der mitgeschickte Wert schlaegt die feste Nutzlast der Zeile.
    $nutz = $wert === null ? (string) $b['wert'] : (string) $wert;
    $wortzeile = $b['wort'] . ($wert === null ? '' : ' ' . $wert);
    /* Fester Befehl = Impuls (Wert, nach 1 s 0); Zahl-Befehl = Wert ohne
     * Rueckstellung (Entscheidung 28). */
    $impuls = ($wert === null);

    if ($trocken) {
        sg_log('Trockenlauf: "' . $wortzeile . '" wuerde ' . $b['thema'] . '=' . $nutz
             . ($impuls && $nutz !== '0' ? ', nach 1 s ' . $b['thema'] . '=0' : '')
             . ' senden (es wurde nichts gesendet)');
        $antwort = $b['antwort'] !== '' ? $b['antwort'] : sprintf(sg_t('BOT.UEBERGEBEN'), $wortzeile);
        return array('antwort' => $antwort, 'grund' => 'wuerde_ausfuehren');
    }

    /* ---- Vier-Augen ----
     * Steht eine zweite Nummer in der Zeile, wird nicht ausgefuehrt, sondern
     * dort gefragt. Erst deren "ja" fuehrt aus - dann kommt dieser Aufruf mit
     * $freigegeben = true noch einmal hier an. */
    if (!$freigegeben && (string) $b['zweit'] !== '') {
        sg_wartend($b['zweit'], array('befehl' => -1, 'art' => 'rueckfrage', 'wort' => $b['wort'],
                                      'stufe' => $b['stufe'], 'fuer' => $von, 'wert' => $wert));
        sg_senden($b['zweit'], sprintf(sg_t('BOT.ZWEIT_FRAGE'), sg_maske($von), $wortzeile));
        sg_log('Vier-Augen: ' . sg_maske($von) . ' will "' . $wortzeile . '", gefragt wird '
             . sg_maske($b['zweit']));
        sg_ereignis_merken(sg_maske($von), 'Freigabe angefragt', $wortzeile);
        return array('antwort' => sprintf(sg_t('BOT.ZWEIT_WARTET'), sg_maske($b['zweit'])),
                     'grund' => 'zweitfreigabe');
    }

    $sg_erg = sg_mqtt_befehl($b['thema'], $nutz, $impuls);
    $ok = $sg_erg['ok'];
    $sg_broker = ($sg_erg['weg'] === 'broker');
    /* WAS "ok" HEISST (M2, Entscheidung 28; SignalBot-q1):
     *   weg broker - der Broker hat den Befehl bestaetigt (PUBACK, QoS 1).
     *                Weiter zum Miniserver reicht ihn das MQTT-Gateway, das
     *                das Thema abonniert hat; das sieht der Bot nicht.
     *   weg udp    - Rueckfall: das Paket ist an den UDP-Eingang des Gateways
     *                UEBERGEBEN, unbestaetigt (Regeln/07). Antwort wie bis
     *                0.9.26: "an Loxone uebergeben".
     *   unklar     - der Broker hat das PUBLISH bekommen, aber nicht
     *                bestaetigt. Nicht nachgeschickt; die Antwort sagt es.
     * letzter.json ist der Zeitpunkt der letzten Uebergabe. */
    if ($ok) {
        sg_write_atomic(sg_datadir() . '/letzter.json',
            json_encode(array('ts' => time(), 'wort' => $wortzeile)), 0644);
    }
    $sg_wie = $ok ? ($sg_broker ? 'vom Broker bestaetigt, QoS 1' : 'an das Gateway uebergeben, unbestaetigt')
                  : (!empty($sg_erg['unklar']) ? 'an den Broker gesendet, NICHT bestaetigt' : 'FEHLGESCHLAGEN');
    sg_log('Befehl "' . $wortzeile . '" von ' . sg_maske($von) . ' -> '
         . $b['thema'] . '=' . $nutz . ($impuls && $nutz !== '0' ? ', dann 0' : '')
         . ' (' . $sg_wie . ')');
    sg_ereignis_merken(sg_maske($von),
                $ok ? ($sg_broker ? 'bestaetigt' : 'uebergeben') : (!empty($sg_erg['unklar']) ? 'unbestaetigt' : 'fehlgeschlagen'),
                $wortzeile . ' -> ' . $b['thema'] . '=' . $nutz);
    if (!$ok && !empty($sg_erg['unklar'])) {
        return array('antwort' => sprintf(sg_t('BOT.UNBESTAETIGT'), $wortzeile), 'grund' => 'unbestaetigt');
    }
    if (!$ok) {
        return array('antwort' => sg_t('BOT.MQTT_FEHLER'), 'grund' => 'mqtt_fehler');
    }
    $antwort = $b['antwort'] !== '' ? $b['antwort']
             : sprintf(sg_t($sg_broker ? 'BOT.BESTAETIGT' : 'BOT.UEBERGEBEN'), $wortzeile);
    return array('antwort' => $antwort, 'grund' => $sg_broker ? 'bestaetigt' : 'uebergeben');
}

/** Die Hilfe, die der Bot auf "hilfe" schickt. */
function sg_hilfetext($fuer = '')
{
    $cfg = sg_config();
    $zeilen = array(sg_t('BOT.HILFE_KOPF'));
    $n = preg_replace('/[^0-9+]/', '', (string) $fuer);
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        // Was jemand nicht ausloesen darf, gehoert auch nicht in seine Liste.
        if ($n !== '' && (string) $b['absender'] !== '') {
            $darf = false;
            foreach (explode(',', (string) $b['absender']) as $nr) {
                if (hash_equals(trim($nr), $n)) { $darf = true; break; }
            }
            if (!$darf) { continue; }
        }
        $marke = '';
        if ($b['stufe'] === 'pin') { $marke = ' ' . sg_t('BOT.MARKE_PIN'); }
        elseif ($b['stufe'] === 'rueckfrage') { $marke = ' ' . sg_t('BOT.MARKE_RUECKFRAGE'); }
        if ((string) $b['zweit'] !== '') { $marke .= ' ' . sg_t('BOT.MARKE_ZWEIT'); }
        $wort = $b['wort'] . ($b['wert_art'] === 'zahl'
            ? ' <' . (int) $b['min'] . '..' . (int) $b['max'] . '>' : '');
        $zeilen[] = '- ' . $wort . $marke;
    }
    if (!empty($cfg['zustand_ein'])) { $zeilen[] = '- status'; }
    $zeilen[] = '- hilfe';
    if (!empty($cfg['gesperrt'])) { $zeilen[] = ''; $zeilen[] = sg_t('BOT.GESPERRT'); }
    return implode("\n", $zeilen);
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprüfter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

/**
 * Wie oft ist $was in den letzten $sekunden vorgekommen?
 *
 * Damit sieht Loxone, was sonst nur im Protokoll steht: eine Haeufung von
 * Abweisungen oder falschen PINs ist der erste sichtbare Hinweis darauf, dass
 * jemand am Bot herumprobiert. Ohne eine solche Zahl merkt das niemand, denn
 * jeder einzelne Vorgang sieht harmlos aus.
 */
function sg_zaehle_ereignisse($was, $sekunden = 3600)
{
    $grenze = time() - (int) $sekunden;
    $n = 0;
    foreach (sg_ereignisse() as $x) {
        if ((int) $x['ts'] >= $grenze && $x['was'] === $was) { $n++; }
    }
    return $n;
}

/* ==================================================================
 * Die native Bibliothek libsignal (nur auf ARM ein Thema)
 *
 * signal-cli bringt libsignal-client nur fuer x86_64 mit. Auf ARM muss die
 * Datei nachgereicht werden - ins JAR muss dafuer niemand hinein: libsignal
 * faellt auf System.loadLibrary("signal_jni") zurueck und durchsucht den
 * java.library.path, und den erweitert LD_LIBRARY_PATH. Genau das traegt
 * postroot.sh in die Unit ein, mit einem Ordner, der dem Benutzer loxberry
 * gehoert - alles Weitere geht deshalb ohne root.
 * Am Geraet gemessen am 23.08.2026 (Raspberry Pi 4, LoxBerry 4.0.0.13).
 * ================================================================== */

/** Die Architektur, wie dpkg sie nennt (arm64, armhf, amd64 ...). */
function sg_bogen()
{
    static $b = null;
    if ($b === null) { $b = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null')); }
    return $b;
}

/**
 * Wo die nachgereichte Bibliothek liegt: NEBEN dem Datenordner des Plugins.
 *
 * Nicht darin. plugininstall.pl ruft bei jedem Update purge_installation, und
 * das entfernt data/plugins/<ordner>/ vollstaendig - die 28 MB grosse Datei
 * waere nach jeder Aktualisierung weg, und der Dienst startete nicht mehr.
 * Dieselbe Ueberlegung wie bei der Zweitschrift der Konfiguration, die aus
 * demselben Grund neben dem Ordner liegt. Das uninstall-Skript raeumt hier
 * deshalb selbst auf.
 */
function sg_nativ_ordner()
{
    $p = sg_paths();
    return $p['datadir'] . '.nativ';
}
function sg_nativ_datei()  { return sg_nativ_ordner() . '/libsignal_jni.so'; }

/**
 * Welche libsignal-Fassung verlangt das installierte signal-cli?
 *
 * Aus dem Dateinamen des mitgelieferten JAR gelesen, nicht geraten - eine
 * nachgereichte Bibliothek muss dazu passen. Leer, wenn nichts gefunden wird.
 */
function sg_libsignal_fassung()
{
    $jar = sg_libsignal_jar();
    if ($jar !== '' && preg_match('/libsignal-client-([0-9]+\.[0-9]+\.[0-9]+)\.jar$/', $jar, $m)) {
        return $m[1];
    }
    return '';
}

/** Das mitgelieferte libsignal-client-JAR; leer, wenn keines gefunden wird. */
function sg_libsignal_jar()
{
    $orte = array();
    $link = @readlink('/usr/local/bin/signal-cli');
    if ($link) { $orte[] = dirname(dirname($link)) . '/lib'; }
    foreach (glob('/opt/signal-cli-*/lib') ?: array() as $o) { $orte[] = $o; }
    foreach ($orte as $o) {
        foreach (glob($o . '/libsignal-client-*.jar') ?: array() as $jar) {
            if (preg_match('/libsignal-client-([0-9]+\.[0-9]+\.[0-9]+)\.jar$/', $jar)) {
                return $jar;
            }
        }
    }
    return '';
}

/**
 * Fehlt die native Bibliothek, ohne die signal-cli auf diesem Geraet nicht
 * startet?
 *
 * Dieselbe Frage, die postroot.sh stellt, bevor es die Unit schreibt: eine
 * andere Architektur als amd64, keine libsignal_jni.so im Ordner nativ und
 * - nur auf arm64 nachgemessen - auch keine passende Datei im JAR.
 *
 * Seit 0.9.22 fragen das auch der Bot, bevor er den Dienst neu startet, und
 * die Knoepfe des Reiters Test, bevor sie einen Start melden. Anlass, am
 * Geraet gemessen am 17.09.2026: ohne die Datei hing der Dienst in einer
 * Absturzschleife, 212 Neustarts in 56 Minuten, jeder 11 bis 21 Sekunden
 * Rechenzeit - angestossen alle drei Stunden von der Selbstheilung des Bots.
 */
function sg_nativ_fehlt()
{
    static $im_jar = null;
    $b = sg_bogen();
    if ($b === '' || $b === 'amd64' || is_file(sg_nativ_datei())) { return false; }
    if ($im_jar === null) {
        $im_jar = false;
        $jar = sg_libsignal_jar();
        if ($b === 'arm64' && $jar !== '') {
            $aus = array(); $rc = 0;
            @exec('unzip -l ' . escapeshellarg($jar) . ' 2>/dev/null', $aus, $rc);
            $im_jar = $rc === 0 && strpos(implode("\n", $aus), 'libsignal_jni_aarch64.so') !== false;
        }
    }
    return !$im_jar;
}

/** Der Dreiklang, unter dem der Fremdbau die Datei fuehrt. */
function sg_nativ_ziel()
{
    $karte = array(
        'arm64' => 'aarch64-unknown-linux-gnu',
        'armhf' => 'armv7-unknown-linux-gnueabihf',
        'amd64' => 'x86_64-unknown-linux-gnu',
        'i386'  => 'i686-unknown-linux-gnu',
    );
    $b = sg_bogen();
    return isset($karte[$b]) ? $karte[$b] : '';
}

/** Die Seite, auf der die Baue liegen - fuer den Weg von Hand. */
function sg_nativ_seite()
{
    $f = sg_libsignal_fassung();
    return $f === ''
        ? 'https://github.com/exquo/signal-libs-build/releases'
        : 'https://github.com/exquo/signal-libs-build/releases/tag/libsignal_v' . $f;
}

/** Sekunden seit dem letzten ausgefuehrten Befehl; -1 = noch keiner. */
function sg_letzter_befehl()
{
    $f = sg_datadir() . '/letzter.json';
    if (!is_file($f)) { return -1; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || empty($d['ts'])) { return -1; }
    return max(0, time() - (int) $d['ts']);
}

/** Offene, noch nicht quittierte dringende Meldungen. */
function sg_offene_meldungen()
{
    $n = 0;
    foreach (sg_warteschlange() as $x) { if (!empty($x['dringend'])) { $n++; } }
    return $n;
}

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

/* ---- Herzschlag des Bots (C2, Entscheidung 4) ----
 *
 * Der Bot legt in jedem Takt (nach der Uhr, hoechstens alle 20 s) den
 * Zeitstempel in <tmp>/herz ab. Der Endpunkt meldet daraus BOT und ALTER, und
 * OK=1 nur, wenn signal-cli antwortet UND das Lebenszeichen hoechstens
 * SG_HERZ_GRENZE Sekunden alt ist (3 x 60 s Takt). Bis 0.9.25 hiess OK nur
 * "signal-cli antwortet auf /check" - ob jemand den Ereignisstrom abhoert,
 * stand nirgends (gemessen: OK=1 ohne laufenden Bot). Lesen legt nichts an.
 */
define('SG_HERZ_GRENZE', 180);

function sg_herz_datei()
{
    $p = sg_paths();
    return $p['tmp'] . '/herz';
}

function sg_herz_schreiben()
{
    return sg_write_atomic(sg_tmpdir() . '/herz', (string) time(), 0644);
}

/** Sekunden seit dem letzten Lebenszeichen des Bots; -1 = keines. */
function sg_herz_alter()
{
    $f = sg_herz_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) { return -1; }
    $t = (int) trim((string) @file_get_contents($f));
    return $t > 0 ? max(0, time() - $t) : -1;
}

/** Haelt ein Bot die Sperre? Legt nichts an, wenn es die Sperrdatei nicht gibt. */
function sg_bot_laeuft()
{
    $p = sg_paths();
    $sperre = $p['tmp'] . '/bot.lock';
    if (!is_file($sperre)) { return false; }
    $fh = @fopen($sperre, 'r');
    if (!$fh) { return false; }
    // Laesst sich die Sperre nehmen, laeuft niemand.
    $laeuft = !flock($fh, LOCK_EX | LOCK_NB);
    if (!$laeuft) { flock($fh, LOCK_UN); }
    fclose($fh);
    return $laeuft;
}

/**
 * Die Felder der Statuszeile: name => array(analog, min, max, Sprachschluessel, Einheit).
 * Die Spalte "Bedeutung" (FELD.*) steht in der Oberflaeche und als HintText
 * in der Vorlage; der Kommentar der Vorlage ist ein eigener kurzer Text
 * (VORLAGE.C_*, hoechstens 40 Zeichen - laengere schneidet Loxone Config ab).
 */
function sg_felder()
{
    return array(
        'OK'        => array(0, 0, 1, 'FELD.OK', ''),
        'DAEMON'    => array(0, 0, 1, 'FELD.DAEMON', ''),
        'KONTO'     => array(0, 0, 1, 'FELD.KONTO', ''),
        'ERLAUBTE'  => array(1, 0, 100, 'FELD.ERLAUBTE', ''),
        'BEFEHLE'   => array(1, 0, SG_BEFEHLE, 'FELD.BEFEHLE', ''),
        'ZUSTAENDE' => array(1, 0, 50, 'FELD.ZUSTAENDE', ''),
        'GESPERRT'  => array(0, 0, 1, 'FELD.GESPERRT', ''),
        'OFFEN'     => array(1, 0, 200, 'FELD.OFFEN', ''),
        /* LETZTER und ALTER bis ein Jahr (bis 0.9.25 LETZTER bis 86400: nach
         * einem Tag ohne Befehl lag der Wert ueber MaxVal - Klasse 9). */
        'LETZTER'   => array(1, -1, 31536000, 'FELD.LETZTER', 's'),
        'ABGEWIESEN'=> array(1, 0, 500, 'FELD.ABGEWIESEN', ''),
        'PINFEHL'   => array(1, 0, 500, 'FELD.PINFEHL', ''),
        'BOT'       => array(0, 0, 1, 'FELD.BOT', ''),
        'ALTER'     => array(1, -1, 31536000, 'FELD.ALTER', 's'),
    );
}

/** Ein Sprachwert als reiner Text (fuer Vorlagen und HintText). */
function sg_klartext($schluessel)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode(sg_t($schluessel), ENT_QUOTES, 'UTF-8'))));
}

function sg_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . sg_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . sg_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sg_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . sg_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . sg_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    // Bauform APC-UPS 1.2.17 (U15): Info als erstes Kind, Unit und HintText je Eintrag.
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . sg_x($c['unit']) . '" ';
        $o .= 'HintText="' . sg_x(isset($c['hint']) ? $c['hint'] : '') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Vorlage fuer Loxone Config: die Statuszeile als virtueller HTTP-Eingang.
 * Kommentare hoechstens 40 Zeichen (U15; bis 0.9.25 fuenf von zwoelf
 * laenger, der Kopf 163), die Erklaerung steht im HintText. Titel und Texte
 * aus der Sprachdatei (U16).
 */
function sg_vorlage()
{
    $cmds = array();
    foreach (sg_felder() as $name => $d) {
        list($analog, $min, $max, $schluessel, $einheit) = $d;
        $cmds[] = array(
            'title' => 'SIGNAL_' . $name,
            'comment' => sg_klartext('VORLAGE.C_' . $name),
            'hint' => sg_klartext($schluessel),
            'check' => '\i' . $name . '=\i\v',
            'analog' => $analog, 'min' => $min, 'max' => $max,
            'unit' => $einheit === '' ? '<v>' : '<v> ' . $einheit,
        );
    }
    return array('VI_signalbot.xml', sg_xml_virtual_in_http(array(
        'title'   => sg_klartext('VORLAGE.ITITEL'),
        'address' => sg_endpunkt('status'),
        'polling' => '60',
        'comment' => sg_klartext('VORLAGE.IKOPF'),
        'hint'    => sprintf(sg_klartext('VORLAGE.HINT_KOPF'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Die zweite Vorlage: ein virtueller AUSGANG zum Senden.
 *
 * Aufbau und Attributreihenfolge aus dem gemessenen Muster VQ_KEBA_P30_UDP.xml;
 * dazu (U15) Info templateType 3 (virtueller Ausgang, Bauform Midea2Lox 4.5.12)
 * und HintText am Wurzelelement und je Befehl.
 */
function sg_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="' . sg_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . sg_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sg_x($kopf['comment']) . '" ';
    $o .= 'Address="' . sg_x($kopf['address']) . '" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . sg_x($c['title']) . '" ';
        $o .= 'Comment="' . sg_x($c['comment']) . '" ';
        $o .= 'CmdOn="' . sg_x($c['on']) . '" ';
        if (isset($c['off']) && $c['off'] !== '') {
            $o .= 'CmdOff="' . sg_x($c['off']) . '" ';
        }
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'HintText="' . sg_x(isset($c['hint']) ? $c['hint'] : '') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

function sg_vorlage_out()
{
    $p = sg_paths();
    $cfg = sg_config();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $pfad = '/plugins/' . $p['plugin'] . '/index.php?token=' . $cfg['aktionstoken'] . '&aktion=';
    $text = function ($k) { return rawurlencode(sg_klartext($k)); };
    $cmds = array(
        array('title' => sg_klartext('VORLAGE.T1'), 'comment' => sg_klartext('VORLAGE.Q1'),
              'hint' => sg_klartext('VORLAGE.H1'),
              'on' => $pfad . 'senden&text=' . $text('VORLAGE.TEXT1'), 'analog' => 0),
        array('title' => sg_klartext('VORLAGE.T2'), 'comment' => sg_klartext('VORLAGE.Q2'),
              'hint' => sg_klartext('VORLAGE.H2'),
              'on' => $pfad . 'senden&text=' . $text('VORLAGE.TEXT2') . '%20<v.0>', 'analog' => 1),
        array('title' => sg_klartext('VORLAGE.T3'), 'comment' => sg_klartext('VORLAGE.Q3'),
              'hint' => sg_klartext('VORLAGE.H3'),
              'on' => $pfad . 'senden&dringend=1&text=' . $text('VORLAGE.TEXT3'), 'analog' => 0),
        array('title' => sg_klartext('VORLAGE.T4'), 'comment' => sg_klartext('VORLAGE.Q4'),
              'hint' => sg_klartext('VORLAGE.H4'),
              'on' => $pfad . 'zustand&name=alarm&wert=<v.0>', 'analog' => 1),
        array('title' => sg_klartext('VORLAGE.T5'), 'comment' => sg_klartext('VORLAGE.Q5'),
              'hint' => sg_klartext('VORLAGE.H5'),
              'on' => $pfad . 'sperren', 'off' => $pfad . 'entsperren', 'analog' => 0),
    );
    return array('VQ_signalbot.xml', sg_xml_virtual_out(array(
        'title'   => sg_klartext('VORLAGE.QTITEL'),
        'address' => 'http://' . $host,
        'comment' => sg_klartext('VORLAGE.QKOPF'),
        'hint'    => sg_klartext('VORLAGE.HINT_QKOPF'),
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Eine halb gueltige Datei ueberschreibt GAR NICHTS. Unbekannte und fehlende
 * Schluessel sind eine Beanstandung (seit 0.9.12 bzw. 07.09.2026).
 *
 * Seit dem Durchgang 01.10.2026 (C3, Bauart E, Klasse 12) wird jeder WERT
 * mit derselben Pruefung wie die Formulare geprueft (sg_config_maengel):
 * Typ, Muster, Bereich, Befehlszeilen. Bis 0.9.25 ging hier jeder Wert durch,
 * und erst sg_config() bog ihn beim Lesen zurecht: ein Token als Liste wurde
 * zu einem neuen Token, eine Weissliste als Text zu einer leeren, "gesperrt":
 * "nein" sperrte den Bot, "rpc_url":"javascript:..." stand als Link im
 * Reiter Test - alles mit "22 Werte uebernommen" quittiert (gemessen).
 *
 * Schluessel mit "_" vorn sind der lesbare Kopf (_plugin, _stand, _hinweis,
 * _warnung; Kernschicht 9) und werden uebersprungen (U6; bis 0.9.25
 * "Unbekannte Einstellung in der Datei: _hinweis").
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function sg_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(sg_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = sg_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    $gesehen = array();
    foreach ($daten as $k => $w) {
        if (is_string($k) && $k !== '' && $k[0] === '_') { continue; }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(sg_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $gesehen[] = $k;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = sg_t('EINST.SICH_LEER');
    }
    $fehlend = array_values(array_diff($bekannt, $gesehen));
    if ($fehlend) {
        $mangel[] = sprintf(sg_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if (!$mangel) {
        foreach (sg_config_maengel($neu) as $feld => $text) {
            $mangel[] = '<span class="sm-mono">' . sg_e($feld) . '</span>: ' . $text;
        }
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/**
 * Die Sicherungsdatei: die Konfiguration mit lesbarem _-Kopf (U6). Genau diese
 * Datei nimmt sg_sicherung_lesen() wieder an. Bestuende sie das Zurueckspielen
 * nicht (X-3), steht es im Kopf (_warnung, nur Namen) - geliefert wird trotzdem.
 */
function sg_sicherung_json($cfg)
{
    $aus = array(
        '_plugin'  => 'Signal Bot (' . sg_paths()['plugin'] . ')',
        '_stand'   => date('Y-m-d H:i:s'),
        '_hinweis' => sg_klartext('EINST.SICH_KOPF_HINWEIS'),
    );
    $m = sg_sicherung_eigene_maengel($cfg);
    if ($m) {
        $aus['_warnung'] = sprintf(sg_klartext('EINST.SICH_KOPF_WARNUNG'), implode(', ', $m));
    }
    foreach (array_keys(sg_vorgaben()) as $k) {
        $aus[$k] = $cfg[$k];
    }
    return json_encode($aus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * X-3: Bestuende die eigene Sicherung das Zurueckspielen? Geprueft mit
 * sg_config_maengel() - derselben Funktion wie beim Zurueckspielen.
 * Rueckgabe: die beanstandeten Schluessel (nur Namen), leer = besteht.
 */
function sg_sicherung_eigene_maengel($cfg)
{
    $werte = array();
    foreach (array_keys(sg_vorgaben()) as $k) { $werte[$k] = $cfg[$k]; }
    return array_keys(sg_config_maengel($werte));
}

/* ==================================================================
 * Einmalmeldung nach einer Umleitung (U2, Regeln/04)
 *
 * Jeder POST-Handler endet seit dem Durchgang 01.10.2026 mit 303 auf die
 * Seite. Das Ergebnis reist in data/plugins/<ordner>/einmalmeldung.json,
 * Rechte 0600, wird NUR beim GET gelesen und dabei geloescht; aelter als
 * 120 s wird verworfen. Nie darin: PIN, Konto, Token.
 * ================================================================== */
function sg_flash_datei()
{
    return sg_datadir() . '/einmalmeldung.json';
}

function sg_flash_schreiben($inhalt)
{
    $inhalt['zeit'] = time();
    $js = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && sg_write_atomic(sg_flash_datei(), $js, 0600);
}

function sg_flash_lesen()
{
    $f = sg_flash_datei();
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > 120) { return array(); }
    return $d;
}

/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function sg_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = sg_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Ueber sg_write_atomic() (C7): Nebendatei mit PID, Rechte VOR dem
     * Inhalt, Laengenvergleich. Bis 0.9.25 hiess die Nebendatei fest
     * formmerkwort.tmp, "!== false" galt als Erfolg, und chmod kam nach dem
     * Inhalt - entgegen dem Kommentar an dieser Stelle. */
    sg_write_atomic($datei, $neu, 0600);
    $wort = $neu;
    return $wort;
}

function sg_formtoken()
{
    $grund = sg_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function sg_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(sg_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function sg_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = sg_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return sg_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return sg_t('WACHE.FALSCH');
    }
    return '';
}
