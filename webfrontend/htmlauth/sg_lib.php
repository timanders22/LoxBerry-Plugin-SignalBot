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
        if ($plugin === '') {
            /* G6 (0.9.30, Haertung): der Rueckfall 'signalbot' gilt nur, wenn diese Datei
             * wirklich unter <home>/webfrontend/htmlauth/plugins/ liegt - sonst Archivmodus
             * (kein Griff in config/plugins/signalbot eines fremden Baums). */
            $sg_rp = realpath(__DIR__);
            $sg_hp = $home ? realpath($home . '/webfrontend/htmlauth/plugins') : false;
            if ($sg_rp && $sg_hp && strpos($sg_rp, $sg_hp . DIRECTORY_SEPARATOR) === 0) { $plugin = 'signalbot'; }
            else { $home = ''; }
        }
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
        'herzschlag'     => 1,         // Lebenszeichen auf MQTT (<praefix>/online, seit 0.9.30 veraltet)
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
        /* Seit 0.9.30 (Gruppe 32). Alle sieben stehen in der Spaetliste der
         * Sicherung (sg_sicherung_spaet): fehlen sie beim Zurueckspielen, gilt
         * der geltende Wert. */
        'befehle_mqtt_ein'       => 0,        // Befehlsbruecke 1.0.1: Meldungen aus Loxone ueber das MQTT-Gateway V2
        'befehle_entsperren_ein' => 0,        // zweiter Haken: entsperren aus Loxone (N9, E-E6)
        'notiz_ein'              => 1,        // Weg A: "Notiz an mich" als Befehlsweg (Nachtrag 27.10.; wirkt nur,
                                              // wenn die eigene Kontonummer unter den erlaubten Absendern steht)
        'endpunkt_ein'           => 0,        // HTTP-Endpunkt fuer Loxone (Bestand: Lese-Regel -> 1; E-E8)
        'plugin_absender'        => array(),  // Vorschlag 1: freigegebene Absender-Plugins (Ordnernamen)
        'haus_status_ein'        => 0,        // Vorschlag 3: Haus-Werte im Chat
        'haus_bereiche'          => array('strom', 'speicher', 'kalender', 'abfuhr', 'usv', 'abfahrt'),
        'gaeste'                 => array(),  // Vorschlag 4: befristete Absender
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
    foreach (array('online', 'selbsttest', 'status', 'befehl', 'rpc', 'daemon', 'konto', 'erlaubte', 'gaeste',
                   'befehle', 'gesperrt', 'abgewiesen', 'pinfehl', 'offen', 'zustaende', 'letzter_min') as $r) {
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
        'befehle_mqtt_ein' => 'BEFEHL.L_ANNEHMEN', 'befehle_entsperren_ein' => 'BEFEHL.L_ENTSPERREN',
        'endpunkt_ein' => 'LOX.L_ENDPUNKT', 'plugin_absender' => 'EINST.L_PLUGINS',
        'haus_status_ein' => 'EINST.L_HAUS', 'notiz_ein' => 'EINST.L_NOTIZ', 'haus_bereiche' => 'EINST.L_HAUS_BEREICHE', 'gaeste' => 'GAST.H_TITEL',
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
    foreach (array('stille', 'zustand_ein', 'gesperrt', 'audit', 'herzschlag', 'mqtt_ein',
                   'befehle_mqtt_ein', 'befehle_entsperren_ein', 'endpunkt_ein', 'haus_status_ein', 'notiz_ein') as $sk) {
        if (!sg_ist_schalter($hol($sk))) {
            $setze($sk, sprintf(sg_t('EINST.FEHLER_SCHALTER'), sg_e(sg_feldname($sk))));
        }
    }
    /* Seit 0.9.30: Vorschlag 1 (freigegebene Absender-Plugins), Vorschlag 3 (Bereiche), Vorschlag 4 (Gaeste). */
    $pa = $hol('plugin_absender');
    if (!is_array($pa) || ($pa !== array() && array_keys($pa) !== range(0, count($pa) - 1)) || count($pa) > 50
        || count(array_unique(array_map('strval', $pa))) !== count($pa)) {
        $setze('plugin_absender', sg_t('EINST.FEHLER_PLUGINS'));
    } else {
        foreach ($pa as $x) {
            if (!is_string($x) || !preg_match('/^[a-z0-9_]{1,40}$/', $x)) { $setze('plugin_absender', sg_t('EINST.FEHLER_PLUGINS')); }
        }
    }
    $hb = $hol('haus_bereiche');
    if (!is_array($hb) || ($hb !== array() && array_keys($hb) !== range(0, count($hb) - 1))
        || count(array_unique(array_map('strval', $hb))) !== count($hb)
        || array_diff(array_map('strval', $hb), array_keys(sg_haus_bereiche_alle()))) {
        $setze('haus_bereiche', sg_t('EINST.FEHLER_HAUS_BEREICHE'));
    }
    $gm = sg_gaeste_maengel($hol('gaeste'), is_array($e) ? $e : array());
    if ($gm !== '') { $setze('gaeste', $gm); }
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
    /* Lese-Regel endpunkt_ein (0.9.30, N8, E-E8): eine eingerichtete Anlage (Datei mit
     * Schluesseln ausser aktionstoken und dem _-Kopf) ohne den Schluessel behaelt ihren
     * HTTP-Endpunkt (1); keine Datei oder {} ist eine Neuinstallation (Vorgabe 0). Ohne
     * diese Regel fiele der Bestand in jeder Upgrade-Luecke auf 0 (Bauart G27 N5). */
    if (!array_key_exists('endpunkt_ein', $cfg)) {
        foreach (array_keys($cfg) as $sg_k) {
            if ($sg_k !== 'aktionstoken' && !(is_string($sg_k) && $sg_k !== '' && $sg_k[0] === '_')) {
                $cfg['endpunkt_ein'] = 1;
                break;
            }
        }
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
    foreach (array('gesperrt', 'audit', 'herzschlag', 'stille', 'mqtt_ein', 'zustand_ein',
                   'befehle_mqtt_ein', 'befehle_entsperren_ein', 'endpunkt_ein', 'haus_status_ein', 'notiz_ein') as $sg_k) {
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
    /* Seit 0.9.30: Listen als Listen erzwingen (Vorschlag 1, 3, 4). */
    $cfg['plugin_absender'] = is_array($cfg['plugin_absender']) ? array_values(array_unique(array_filter($cfg['plugin_absender'],
        function ($x) { return is_string($x) && preg_match('/^[a-z0-9_]{1,40}$/', $x); }))) : array();
    $cfg['haus_bereiche'] = is_array($cfg['haus_bereiche'])
        ? array_values(array_intersect(array_keys(sg_haus_bereiche_alle()), $cfg['haus_bereiche'])) : sg_haus_bereiche_werk();
    $sg_gl = array();
    foreach (is_array($cfg['gaeste']) ? $cfg['gaeste'] : array() as $sg_g) {
        $sg_g = sg_gast_normal($sg_g);
        if ($sg_g !== null && count($sg_gl) < SG_GAESTE_MAX) { $sg_gl[] = $sg_g; }
    }
    $cfg['gaeste'] = $sg_gl;

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

/* ---- Ziel "Gruppe" (0.9.29, E13) ----
 *
 * Ein Ziel ist eine Rufnummer oder "gruppe:<Kennung>". Die Kennung steht im
 * Ziel selbst - auch in der Warteschlange -, damit eine spaeter geaenderte
 * Einstellung eine wartende Meldung nicht in eine andere Gruppe umleitet.
 * Eine Kennung von signal-cli enthaelt nie einen Doppelpunkt, eine Rufnummer
 * auch nicht: die Vorsilbe ist eindeutig. */
define('SG_GRUPPE_VOR', 'gruppe:');

/** Ist dieses Ziel eine Gruppe? */
function sg_ist_gruppe($ziel)
{
    return strpos((string) $ziel, SG_GRUPPE_VOR) === 0;
}

/** Ist der Text eines der Quittungswoerter? */
function sg_quittungswort($text)
{
    $klein = sg_klein(trim(preg_replace('/\s+/', ' ', (string) $text)));
    return in_array($klein, array('quittiert', 'quittieren', 'ack'), true);
}

/**
 * Soll der Bot eine eingegangene Nachricht auswerten? $daten ist
 * dataMessage aus dem Ereignisstrom von signal-cli.
 *
 * Aus einer GRUPPE (groupInfo gesetzt) nur die Quittungswoerter (0.9.29,
 * Entscheidung des Hausherrn zu E13). Bis 0.9.28 wertete der Bot jede
 * Gruppenzeile aus wie eine private Nachricht: jeder Satz im Familienchat
 * bekam privat "Das kenne ich nicht", jedes Mitglied ohne Weissliste
 * "nicht freigegeben" und einen Eintrag "abgewiesen". Alles andere aus
 * Gruppen wird still uebergangen - ohne Antwort, ohne Protokoll. Befehle
 * gehen weiter im privaten Chat.
 */
function sg_nachricht_beachten($daten)
{
    if (!is_array($daten) || !isset($daten['message'])) { return false; }
    if (isset($daten['groupInfo']) && $daten['groupInfo'] !== null) {
        return sg_quittungswort($daten['message']);
    }
    return true;
}

/** Fuer Protokoll und Ereignisse: "Gruppe" statt der Kennung, sonst die maskierte Nummer. */
function sg_ziel_name($ziel)
{
    return sg_ist_gruppe($ziel) ? 'Gruppe' : sg_maske($ziel);
}

/**
 * Eine Nachricht senden - an eine Rufnummer, an die Gruppe, mit Anhang.
 *
 * $an ist eine Rufnummer oder "gruppe:<Kennung>" (sg_ist_gruppe). Bis 0.9.28
 * kam die Gruppe nur bei $an = '' zum Zug, und das rief niemand: die
 * Einstellung wirkte nie (E13). '' schickt weiter in die eingestellte Gruppe.
 * $anhang ist ein Dateipfad auf dem LoxBerry (etwa ein Kamerabild,
 * das Loxone gerade abgelegt hat); er wird nur mitgegeben, wenn die Datei
 * wirklich da und lesbar ist - sonst ginge die ganze Meldung verloren, und
 * das waere im Alarmfall der schlechteste Tausch.
 */
function sg_senden($an, $text, $anhang = '')
{
    $cfg = sg_config();
    $params = array('message' => (string) $text);
    if (sg_ist_gruppe($an)) {
        $params['groupId'] = (string) substr((string) $an, strlen(SG_GRUPPE_VOR));
    } elseif ((string) $an !== '') {
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
    /* Schleifenschutz "Notiz an mich" (Nachtrag 27.10.): den Zeitstempel jeder eigenen Sendung merken - kaeme sie
     * als Abgleich zurueck, wertet sg_notiz_pruefen() sie nie aus. */
    if ($a['ok']) { sg_eigene_sendung_merken($a['result']); }
    if (!$a['ok']) {
        sg_log('Senden an ' . sg_ziel_name($an) . ' fehlgeschlagen: ' . $a['fehler']);
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
function sg_bremse_frei($nummer, $grenze = null)
{
    /* Seit 0.9.30 mit eigener Grenze: die Befehlsbruecke bremst alle Meldungen
     * zusammen auf SG_BRUECKE_JE_MINUTE (Schluessel 'bruecke'). */
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
    if (count($liste) >= ($grenze === null ? (int) $cfg['bremse'] : (int) $grenze)) {
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
         * Anwender eintragen muss: unter V1 das Abo <praefix>/# (traegt das
         * Plugin selbst ein), unter Gateway V2 je Thema ein Haken in der
         * Hakenliste (Opt-In je Thema, gemessen am Geraet 18.10.2026; N1).
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
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1 (Abo
 * <praefix>/#). Unter Gateway V2 ist nichts einzutragen, aber je Thema ein
 * Haken zu setzen (Opt-In je Thema; bis 0.9.29 stand hier "von selbst" -
 * das Gegenteil des gemessenen Verhaltens, N1). Die Liste steht im Reiter MQTT.
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
 * Bestaetigung. Abonniert hat das Thema das MQTT-Gateway (V1: Abodatei
 * <praefix>/#; Gateway V2: Haken je Thema, Noncached - N1, N3) - es reicht den
 * Befehl genauso an den Miniserver weiter wie einen, der ueber seinen
 * UDP-Eingang kam.
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
function sg_broker_verbinden($vor = 'sgbot', $keepalive = 30)
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
    $nutz = $zk(preg_replace('/[^a-z]/', '', (string) $vor) . getmypid() . 'q' . mt_rand(100, 999));
    if ($z['user'] !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($z['pass'] !== '') { $flags |= 0x40; }
        $nutz .= $zk($z['user']);
        if ($z['pass'] !== '') { $nutz .= $zk($z['pass']); }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', max(10, min(600, (int) $keepalive)));
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
    /* Kam der Vorgang ueber "Notiz an mich" (sg_bot.php setzt den Merker nur fuer diesen einen Aufruf)? */
    if (!empty($GLOBALS['sg_notiz_quelle'])) { $wer = (string) $wer . ' (' . SG_NOTIZ_KENNUNG . ')'; }
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

/** Eine Meldung einreihen. $ab = fruehester Zeitpunkt, 0 = sofort. $anhang (seit 0.9.30): Bildkopie in anhang/. */
function sg_warteschlange_ein($an, $text, $dringend = 0, $ab = 0, $anhang = '')
{
    $sperre = @fopen(sg_tmpdir() . '/ausgang.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = sg_warteschlange();
    $liste[] = array(
        'id' => sg_token(8), 'an' => (string) $an, 'text' => (string) $text,
        'dringend' => $dringend ? 1 : 0, 'ab' => (int) $ab,
        'versuche' => 0, 'ts' => time(),
    );
    if ((string) $anhang !== '') { $liste[count($liste) - 1]['anhang'] = (string) $anhang; }
    if (count($liste) > 200) { $liste = array_slice($liste, -200); }
    $ok = sg_warteschlange_schreiben($liste);
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $ok;
}

/**
 * Offene dringende Meldungen eines Empfaengers quittieren.
 *
 * Seit 0.9.29 (E13, Weg A) auch die dringenden GRUPPENmeldungen: eine
 * Gruppe steht fuer einen gemeinsamen Alarm, die erste Quittung eines
 * erlaubten Absenders beendet die Wiederholung fuer alle. Wer quittieren
 * darf, entscheidet vorher die Weissliste in sg_verarbeite(). $gruppen
 * bekommt die Ziele der quittierten Gruppenmeldungen (je Gruppe einmal) -
 * dorthin geht die Zeile "Quittiert von ...".
 */
function sg_quittieren($an, &$gruppen = null)
{
    $gruppen = array();
    $sperre = @fopen(sg_tmpdir() . '/ausgang.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = sg_warteschlange();
    $n = 0;
    foreach ($liste as $k => $e) {
        if (empty($e['dringend'])) { continue; }
        if (sg_ist_gruppe($e['an'])) {
            if (!in_array($e['an'], $gruppen, true)) { $gruppen[] = $e['an']; }
            unset($liste[$k]); $n++;
        } elseif ($e['an'] === $an) {
            unset($liste[$k]); $n++;
        }
    }
    if ($n) {
        $sg_vorher = sg_warteschlange();
        sg_warteschlange_schreiben($liste);
        /* Seit 0.9.30: Bildkopien quittierter Meldungen entfernen (Vorschlag 1). */
        foreach ($sg_vorher as $sg_x) {
            if (!empty($sg_x['anhang'])) { sg_anhang_weg_falls_frei((string) $sg_x['anhang'], $liste); }
        }
    }
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
    $weg = array();
    foreach ($liste as $k => $e) {
        if ((int) $e['ab'] > $jetzt) { continue; }
        $anhang = isset($e['anhang']) ? (string) $e['anhang'] : '';
        if (empty($e['dringend'])) {
            // Einmal senden, dann ist sie erledigt - gleich ob es klappt.
            sg_senden($e['an'], $e['text'], $anhang);
            unset($liste[$k]);
            if ($anhang !== '') { $weg[] = $anhang; }
            $gesendet++;
            continue;
        }
        // Dringend: wiederholen, bis quittiert wird oder der Zaehler leer ist.
        if ((int) $e['versuche'] >= (int) $cfg['quittung_max'] + 1) {
            sg_log('Dringende Meldung an ' . sg_ziel_name($e['an']) . ' bleibt unquittiert - aufgegeben.');
            sg_ereignis_merken(sg_ziel_name($e['an']), 'unquittiert', sg_kuerzen($e['text'], 60));
            unset($liste[$k]);
            if ($anhang !== '') { $weg[] = $anhang; }
            continue;
        }
        $text = $e['versuche'] > 0
            ? sg_t('BOT.WIEDERHOLUNG') . ' ' . $e['text']
            : $e['text'];
        sg_senden($e['an'], $text . "\n" . sg_t('BOT.BITTE_QUITTIEREN'), $anhang);
        $liste[$k]['versuche'] = (int) $e['versuche'] + 1;
        $liste[$k]['ab'] = $jetzt + ((int) $cfg['quittung_takt'] * 60);
        $gesendet++;
    }
    sg_warteschlange_schreiben($liste);
    foreach (array_unique($weg) as $a) { sg_anhang_weg_falls_frei($a, $liste); }
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
        // bis zum Ende der Nachtruhe zurueckhalten (seit 0.9.30 sg_nachtruhe_ende, auch fuer die Bruecke)
        sg_warteschlange_ein($an, $text, 0, sg_nachtruhe_ende($cfg));
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

    /* ---- Schicht 1: Weissliste ----
       Seit 0.9.30 (Vorschlag 4) dazu befristete Gaeste: nur Absender, nie
       Empfaenger; nur die Woerter ihrer Liste und "hilfe"; kein status, kein
       Quittieren. Abgelaufen = wie ein Fremder (Ereignis einmal je Gast). */
    $gast = null;
    if (!sg_erlaubt($von)) {
        $gast = sg_gast_finden($cfg, $von);
        if ($gast === null || !sg_gast_gueltig($gast)) {
            $sg_abgelaufen = ($gast !== null);
            sg_log('Abgewiesen: ' . sg_maske($von) . ($sg_abgelaufen ? ' (Gast abgelaufen)' : ' (nicht auf der Weissliste)'));
            /* Der Trockenlauf merkt nichts (I3). */
            if (!$trocken) {
                if ($sg_abgelaufen) { sg_gast_abgewiesen_merken($gast); }
                else { sg_ereignis_merken(sg_maske($von), 'abgewiesen', 'nicht auf der Weissliste'); }
            }
            // Bewusst schweigen: eine Antwort wuerde Fremden bestaetigen, dass
            // hier ein Bot horcht.
            return array('antwort' => empty($cfg['stille']) ? sg_t('BOT.NICHT_ERLAUBT') : '',
                         'grund' => $sg_abgelaufen ? 'gast_abgelaufen' : 'nicht_erlaubt');
        }
    }
    $sg_gastwort = function ($wort) use ($gast) {
        return $gast === null || in_array((string) $wort, $gast['befehle'], true);
    };
    $sg_gastnein = function () use ($gast) {
        return array('antwort' => sprintf(sg_t('BOT.GAST_NICHT'), $gast['befehle'] ? implode(', ', $gast['befehle']) : '-'),
                     'grund' => 'gast_nicht');
    };

    /* ---- Kill-Schalter ----
       Ist der Bot gesperrt, wird gar nichts mehr ausgefuehrt. Sperren laesst
       sich das aus Loxone heraus (Endpunkt bzw. Befehlsbruecke) und in der
       Oberflaeche. Die eingebauten Woerter bleiben erreichbar. */
    if (!empty($cfg['gesperrt']) && !in_array($klein, array('hilfe', 'help', '?'), true)) {
        sg_log('Abgewiesen (Bot gesperrt): ' . sg_maske($von) . ' - ' . sg_kuerzen($text, 40));
        return array('antwort' => sg_t('BOT.GESPERRT'), 'grund' => 'gesperrt');
    }

    /* ---- Schicht 3 (vorgezogen): Bremse ----
       Im Trockenlauf wird nicht gezaehlt. Gilt fuer Gaeste genauso. */
    if (!$trocken && !sg_bremse_frei($von)) {
        sg_log('Gebremst: ' . sg_maske($von) . ' (mehr als ' . (int) $cfg['bremse'] . ' je Minute)');
        sg_ereignis_merken(sg_maske($von), 'abgewiesen', 'Bremse');
        return array('antwort' => sg_t('BOT.GEBREMST'), 'grund' => 'gebremst');
    }

    /* ---- Laeuft eine Rueckfrage? ----
       Im Trockenlauf uebergangen. */
    $warte = $trocken ? null : sg_wartend($von);
    if ($warte !== null) {
        /* Die Rueckfrage haengt am WORT, nicht nur an der Zeilennummer. */
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
        /* Ein Gast darf nur bestaetigen, was auf seiner Liste steht (seit 0.9.30). */
        if (!$sg_gastwort($b['wort'])) {
            sg_wartend($von, false);
            return $sg_gastnein();
        }
        if (isset($warte['stufe']) && $b['stufe'] !== $warte['stufe']) {
            sg_wartend($von, false);
            sg_log('Rueckfrage verworfen: Stufe von "' . $b['wort'] . '" hat sich waehrenddessen geaendert.');
            return array('antwort' => sg_t('BOT.GEAENDERT'), 'grund' => 'geaendert');
        }
        if (in_array($klein, array('nein', 'no', 'abbrechen', 'stop'), true)) {
            sg_wartend($von, false);
            sg_log('Abgebrochen von ' . sg_maske($von) . ': ' . $b['wort']);
            /* Bei einer Vier-Augen-Freigabe wartet jemand anders auf die Antwort. */
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
        /* Wer hier bestaetigt, kann der Absender selbst sein - oder der Zweite
         * bei einem Befehl mit Vier-Augen-Freigabe. */
        $sg_fuer = isset($warte['fuer']) ? (string) $warte['fuer'] : '';
        $sg_mit = isset($warte['wert']) ? (string) $warte['wert'] : null;
        if ($sg_fuer !== '') {
            sg_ereignis_merken(sg_maske($von), 'Freigabe erteilt', $b['wort'] . ' fuer ' . sg_maske($sg_fuer));
        }
        $sg_alsgast = $sg_fuer !== '' ? !empty($warte['gast']) : ($gast !== null);
        return sg_ausfuehren($b, $sg_fuer !== '' ? $sg_fuer : $von, $trocken, $sg_fuer !== '', $sg_mit, $sg_alsgast);
    }

    /* ---- Eingebaute Woerter ---- */
    if (in_array($klein, array('hilfe', 'help', '?'), true)) {
        return array('antwort' => sg_hilfetext($von, $gast), 'grund' => 'hilfe');
    }
    if (sg_quittungswort($klein)) {
        if ($gast !== null) { return $sg_gastnein(); }
        if ($trocken) { return array('antwort' => sg_t('BOT.NICHTS_ZU_QUITTIEREN'), 'grund' => 'quittiert'); }
        $sg_gruppen = array();
        $n = sg_quittieren($von, $sg_gruppen);
        sg_ereignis_merken(sg_maske($von), 'quittiert', (string) $n);
        /* Die anderen in der Gruppe sollen sehen, dass sich jemand kuemmert (E13). */
        foreach ($sg_gruppen as $sg_g) {
            sg_senden($sg_g, sprintf(sg_t('BOT.GRUPPE_QUITTIERT'), sg_maske($von)));
        }
        return array('antwort' => $n > 0 ? sprintf(sg_t('BOT.QUITTIERT'), $n) : sg_t('BOT.NICHTS_ZU_QUITTIEREN'),
                     'grund' => 'quittiert');
    }
    if ($klein === 'status' || $klein === 'zustand'
        || strpos($klein, 'status ') === 0 || strpos($klein, 'zustand ') === 0) {
        if ($gast !== null) { return $sg_gastnein(); }
        $sg_teil = strpos($klein, ' ') !== false ? trim(substr($klein, strpos($klein, ' ') + 1)) : '';
        /* Vorschlag 3 (seit 0.9.30, N7): "status <wort>" fragt Haus-Werte ab -
           ein von Loxone gemeldeter Zustand gleichen Namens gewinnt (Bestand),
           sonst das Haus-Wort, sonst wie bisher "nicht bekannt". */
        if ($sg_teil !== '' && strpos($klein, 'status ') === 0 && sg_haus_wort_an($cfg, $sg_teil)
            && (empty($cfg['zustand_ein']) || !sg_zustand_da($sg_teil))) {
            $sg_h = sg_haus_antwort($sg_teil, $cfg);
            if ($sg_h !== null) { return array('antwort' => $sg_h, 'grund' => 'haus'); }
        }
        if (empty($cfg['zustand_ein'])) {
            return array('antwort' => sg_t('BOT.ZUSTAND_AUS'), 'grund' => 'zustand_aus');
        }
        return array('antwort' => sg_zustand_text($sg_teil), 'grund' => 'status');
    }

    /* ---- Schicht 2: Befehl suchen und Stufe anwenden ---- */
    foreach ($cfg['befehle'] as $i => $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }

        /* Ein Befehl kann einen WERT mitbekommen: "heizung 21". */
        $sg_mit = null;
        if ($klein === $b['wort']) {
            if ($b['wert_art'] === 'zahl') {
                if (!$sg_gastwort($b['wort'])) { return $sg_gastnein(); }
                return array('antwort' => sprintf(sg_t('BOT.WERT_FEHLT'), $b['wort'],
                                 (int) $b['min'], (int) $b['max'], $b['wort']),
                             'grund' => 'wert_fehlt');
            }
        } else {
            if ($b['wert_art'] !== 'zahl') { continue; }
            $sg_vor = $b['wort'] . ' ';
            if (strpos($klein, $sg_vor) !== 0) { continue; }
            if (!$sg_gastwort($b['wort'])) { return $sg_gastnein(); }
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
        /* Gast: nur die Woerter seiner Liste (seit 0.9.30, Vorschlag 4). */
        if (!$sg_gastwort($b['wort'])) { return $sg_gastnein(); }

        /* Berechtigung je Befehl. Die Weissliste sagt, WER ueberhaupt reden
         * darf; hier steht, wer DIESEN Befehl ausloesen darf. */
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
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'pin', 'wort' => $b['wort'], 'stufe' => $b['stufe'], 'wert' => $sg_mit, 'gast' => $gast !== null ? 1 : 0)); }
            return array('antwort' => sg_t('BOT.PIN_BITTE'), 'grund' => 'pin_angefordert');
        }
        if ($b['stufe'] === 'rueckfrage') {
            if (!$trocken) { sg_wartend($von, array('befehl' => $i, 'art' => 'rueckfrage', 'wort' => $b['wort'], 'stufe' => $b['stufe'], 'wert' => $sg_mit, 'gast' => $gast !== null ? 1 : 0)); }
            return array('antwort' => sprintf(sg_t('BOT.RUECKFRAGE'), $b['wort']
                         . ($sg_mit === null ? '' : ' ' . $sg_mit)), 'grund' => 'rueckfrage');
        }
        return sg_ausfuehren($b, $von, $trocken, false, $sg_mit, $gast !== null);
    }

    if ($gast !== null) { return $sg_gastnein(); }
    // Der Vergleich laeuft am ERSTEN WORT.
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
function sg_ausfuehren($b, $von, $trocken = false, $freigegeben = false, $wert = null, $gast = false)
{
    if ($b['thema'] === '') {
        return array('antwort' => sg_t('BOT.KEIN_THEMA'), 'grund' => 'kein_thema');
    }
    if (sg_thema_reserviert($b['thema'])) {
        sg_log('Befehl "' . $b['wort'] . '" nicht gesendet: das Thema ' . $b['thema'] . ' ist seit 0.9.30 reserviert - bitte umbenennen.');
        return array('antwort' => sprintf(sg_t('BOT.THEMA_RESERVIERT'), $b['wort']), 'grund' => 'thema_reserviert');
    }
    $sg_wer = sg_maske($von) . ($gast ? ' (Gast)' : '');
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
     * dort gefragt. Erst deren "ja" fuehrt aus. */
    if (!$freigegeben && (string) $b['zweit'] !== '') {
        sg_wartend($b['zweit'], array('befehl' => -1, 'art' => 'rueckfrage', 'wort' => $b['wort'],
                                      'stufe' => $b['stufe'], 'fuer' => $von, 'wert' => $wert, 'gast' => $gast ? 1 : 0));
        sg_senden($b['zweit'], sprintf(sg_t('BOT.ZWEIT_FRAGE'), sg_maske($von), $wortzeile));
        sg_log('Vier-Augen: ' . $sg_wer . ' will "' . $wortzeile . '", gefragt wird '
             . sg_maske($b['zweit']));
        sg_ereignis_merken($sg_wer, 'Freigabe angefragt', $wortzeile);
        return array('antwort' => sprintf(sg_t('BOT.ZWEIT_WARTET'), sg_maske($b['zweit'])),
                     'grund' => 'zweitfreigabe');
    }

    $sg_erg = sg_mqtt_befehl($b['thema'], $nutz, $impuls);
    $ok = $sg_erg['ok'];
    $sg_broker = ($sg_erg['weg'] === 'broker');
    /* WAS "ok" HEISST (M2, Entscheidung 28; SignalBot-q1):
     *   weg broker - der Broker hat den Befehl bestaetigt (PUBACK, QoS 1).
     *                Weiter zum Miniserver reicht ihn das MQTT-Gateway V2,
     *                wenn das Thema in seiner Hakenliste angehakt ist (V1:
     *                Abo <praefix>/#); das sieht der Bot nicht.
     *   weg udp    - Rueckfall: das Paket ist an den UDP-Eingang des Gateways
     *                UEBERGEBEN, unbestaetigt (Regeln/07).
     *   unklar     - der Broker hat das PUBLISH bekommen, aber nicht
     *                bestaetigt. Nicht nachgeschickt; die Antwort sagt es.
     * letzter.json ist der Zeitpunkt der letzten Uebergabe. */
    if ($ok) {
        sg_write_atomic(sg_datadir() . '/letzter.json',
            json_encode(array('ts' => time(), 'wort' => $wortzeile)), 0644);
    }
    $sg_wie = $ok ? ($sg_broker ? 'vom Broker bestaetigt, QoS 1' : 'an das Gateway uebergeben, unbestaetigt')
                  : (!empty($sg_erg['unklar']) ? 'an den Broker gesendet, NICHT bestaetigt' : 'FEHLGESCHLAGEN');
    sg_log('Befehl "' . $wortzeile . '" von ' . $sg_wer . ' -> '
         . $b['thema'] . '=' . $nutz . ($impuls && $nutz !== '0' ? ', dann 0' : '')
         . ' (' . $sg_wie . ')');
    sg_ereignis_merken($sg_wer,
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
function sg_hilfetext($fuer = '', $gast = null)
{
    $cfg = sg_config();
    $zeilen = array(sg_t('BOT.HILFE_KOPF'));
    $n = preg_replace('/[^0-9+]/', '', (string) $fuer);
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        if ($gast !== null && !in_array($b['wort'], $gast['befehle'], true)) { continue; }
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
    if ($gast === null) {
        if (!empty($cfg['zustand_ein'])) { $zeilen[] = '- status'; }
        foreach (sg_haus_woerter($cfg) as $sg_hw) { $zeilen[] = '- status ' . $sg_hw; }
        if (sg_haus_woerter($cfg)) { $zeilen[] = '- status haus'; }
    } else {
        $zeilen[] = sprintf(sg_t('BOT.GAST_BIS'), sg_gast_zeit($gast['bis']));
    }
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
 * Seit 0.9.30 (Entscheidung 1, eigener Fund des Pruefberichts) legt der Bot
 * den Zeitstempel in <tmp>/herz nur ab, solange der Ereignisstrom NUTZBAR ist
 * (eine Datenzeile kam oder die Verbindung steht >= 10 s; G13) - OK=1 heisst
 * damit "Nachrichten kommen an". Bis 0.9.29 in jedem Takt, auch in den
 * Rueckzugspausen. Der Endpunkt meldet daraus BOT und ALTER, und
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
 * laenger, der Kopf 163), die Erklaerung steht im HintText. Kommentare und
 * Texte aus der Sprachdatei (U16); der Behaeltertitel steht seit 0.9.28 fest
 * deutsch im Code (Sprachregel 13.10.2026, Welle Bild 18 E6) - er lautete in
 * beiden Sprachdateien schon "Signal Bot".
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
        'title'   => 'Signal Bot',
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
    /* Welle Bild 18 (0.9.28, Entwurf Welle 18 sb, E5 und E6; Sprachregel 13.10.2026, Regeln/07):
     *   - Behaelter- und Befehlstitel stehen fest deutsch im Code; Kommentare, HintTexte und die
     *     Meldungstexte bleiben aus der Sprachdatei. Bis 0.9.27 kamen auch die Titel daher, und eine
     *     englische Oberflaeche legte "Signal Bot commands" / "Signal - send message" an - Baustein-Liste,
     *     Bild und Musterprojekt passten dann nicht mehr. Der Behaelter heisst jetzt "Signal Bot Meldungen"
     *     (bis 0.9.27 "Signal Bot Befehle" - "Befehle" sind in diesem Plugin die Chat-Befehle). Config
     *     benennt Importiertes nicht um; bestehende Projekte behalten ihre Namen.
     *   - Neu ist die sechste Zeile "Signal - Tuerklingel" mit festem Klingeltext (VORLAGE.TEXT6). T1 bis T5
     *     und ihre Adressen sind unveraendert, bestehende Ausgaenge arbeiten weiter. */
    $p = sg_paths();
    $cfg = sg_config();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $pfad = '/plugins/' . $p['plugin'] . '/index.php?token=' . $cfg['aktionstoken'] . '&aktion=';
    $text = function ($k) { return rawurlencode(sg_klartext($k)); };
    $cmds = array(
        array('title' => 'Signal - Meldung senden', 'comment' => sg_klartext('VORLAGE.Q1'),
              'hint' => sg_klartext('VORLAGE.H1'),
              'on' => $pfad . 'senden&text=' . $text('VORLAGE.TEXT1'), 'analog' => 0),
        array('title' => 'Signal - Meldung mit Wert', 'comment' => sg_klartext('VORLAGE.Q2'),
              'hint' => sg_klartext('VORLAGE.H2'),
              'on' => $pfad . 'senden&text=' . $text('VORLAGE.TEXT2') . '%20<v.0>', 'analog' => 1),
        array('title' => 'Signal - dringende Meldung', 'comment' => sg_klartext('VORLAGE.Q3'),
              'hint' => sg_klartext('VORLAGE.H3'),
              'on' => $pfad . 'senden&dringend=1&text=' . $text('VORLAGE.TEXT3'), 'analog' => 0),
        array('title' => 'Signal - Zustand melden', 'comment' => sg_klartext('VORLAGE.Q4'),
              'hint' => sg_klartext('VORLAGE.H4'),
              'on' => $pfad . 'zustand&name=alarm&wert=<v.0>', 'analog' => 1),
        array('title' => 'Signal - Bot sperren', 'comment' => sg_klartext('VORLAGE.Q5'),
              'hint' => sg_klartext('VORLAGE.H5'),
              'on' => $pfad . 'sperren', 'off' => $pfad . 'entsperren', 'analog' => 0),
        array('title' => "Signal - T\u{fc}rklingel", 'comment' => sg_klartext('VORLAGE.Q6'),
              'hint' => sg_klartext('VORLAGE.H6'),
              'on' => $pfad . 'senden&text=' . $text('VORLAGE.TEXT6'), 'analog' => 0),
    );
    return array('VQ_signalbot.xml', sg_xml_virtual_out(array(
        'title'   => 'Signal Bot Meldungen',
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
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(sg_t('EINST.SICH_KEIN_JSON')), 0, array());
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
    $spaet = array_values(array_intersect($fehlend, sg_sicherung_spaet()));
    $fehlend = array_values(array_diff($fehlend, $spaet));
    if ($fehlend) {
        $mangel[] = sprintf(sg_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if (!$mangel && $spaet) {
        $gilt = sg_config();
        foreach ($spaet as $k) { $neu[$k] = $gilt[$k]; }
        $hinweise[] = sprintf(sg_t('EINST.SICH_SPAET'), htmlspecialchars(implode(', ', $spaet), ENT_QUOTES, 'UTF-8'));
    }
    if (!$mangel) {
        foreach (sg_config_maengel($neu) as $feld => $text) {
            $mangel[] = '<span class="sm-mono">' . sg_e($feld) . '</span>: ' . $text;
        }
    }
    if (!$mangel) {
        $hatte_pin = sg_pin_gesetzt(sg_config());
        list($neu, $mig) = sg_pin_migrieren($neu);
        if ($mig) { $hinweise[] = sg_t('EINST.SICH_PIN_GEHASHT'); }
        if ($hatte_pin && !sg_pin_gesetzt($neu)) { $hinweise[] = sg_t('EINST.SICH_PIN_ENTFERNT'); }
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
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
    list($cfg, ) = sg_pin_migrieren($cfg);
    $m = sg_sicherung_eigene_maengel($cfg);
    if ($m) {
        $aus['_warnung'] = sprintf(sg_klartext('EINST.SICH_KOPF_WARNUNG'), implode(', ', $m));
    }
    foreach (array_keys(sg_vorgaben()) as $k) {
        $aus[$k] = $cfg[$k];
    }
    $aus['pin'] = '';
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
    /* Seit 0.9.30 (G1) nur aus $_POST - alle Formulare schicken POST; ein
     * Merkmal in der Adresse stuende im Verlauf und im Protokoll des Webservers. */
    $ist = isset($_POST['fmt']) ? $_POST['fmt'] : null;
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return sg_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return sg_t('WACHE.FALSCH');
    }
    return '';
}

/* ==================================================================
 * Seit 0.9.30 (Gruppe 32, 26.10.2026) - Status ueber das MQTT-Gateway V2
 * ==================================================================
 *
 * Hausform = MQTT-Gateway V2 (Entscheidung 2; Entwurf entwurf_gruppe32_sg.txt
 * Abschnitt 2): der Bot sendet seinen Zustand im Takt an den UDP-Eingang des
 * Gateways (fluechtig, nie retained). Gateway V2 abonniert genau die Themen,
 * die in seiner Hakenliste angehakt sind (Opt-In je Thema, gemessen am Geraet
 * 18.10.2026) - daher die Namenstabelle und die V2-Hakenliste mit
 * "Noncached" fuer status/zaehler, status/ok und die Chat-Befehle. Gateway V1
 * (Abo <praefix>/# ueber mqtt_subscriptions.cfg) bleibt nur Rueckfall.
 *
 * EINE Quelle fuer Endpunktzeile und MQTT: sg_status_werte(). EINE Liste fuer
 * Sendecode, Namenstabelle, V2-Hakenliste und Reiter Test:
 * sg_mqtt_status_themen().
 */

/** Mindestzeit zwischen zwei vollen Saetzen (Sekunden) - dazwischen nur Aenderungen und das Lebenszeichen. */
define('SG_STATUS_VOLL', 1800);

/** Der Webserver-Port des LoxBerry (general.json Webserver.Port, Rueckfall 80; G10). */
function sg_webport()
{
    $p = sg_paths();
    if ($p['home'] === '') { return 80; }
    $gen = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    $port = 0;
    if (is_array($gen)) {
        foreach (array('Webserver', 'webserver') as $k) {
            if (!isset($gen[$k]) || !is_array($gen[$k])) { continue; }
            foreach (array('Port', 'port') as $pk) {
                if (isset($gen[$k][$pk]) && is_scalar($gen[$k][$pk]) && preg_match('/^[0-9]{1,5}$/', trim((string) $gen[$k][$pk]))) {
                    $port = (int) trim((string) $gen[$k][$pk]);
                    break 2;
                }
            }
        }
    }
    return ($port > 0 && $port <= 65535) ? $port : 80;
}

/**
 * Der Name, den das MQTT-Gateway (V1 wie V2, build_vi_name) einem Thema als
 * Virtuellen Eingang gibt: "/", "%" und Leerzeichen werden "_". EINE Stelle
 * fuer Namenstabelle, Hakenliste und Bausteinliste.
 */
function sg_gateway_name($praefix, $thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $praefix . '_' . (string) $thema);
}

/** Kennt systemd den Dienst signal-cli-loxberry? (fuer daemon = -1: kein eigener Dienst, etwa Docker) */
function sg_dienst_bekannt()
{
    $aus = array();
    @exec('systemctl show -p LoadState --value signal-cli-loxberry 2>/dev/null', $aus);
    return in_array(trim(implode('', $aus)), array('loaded', 'masked'), true);
}

/**
 * Die Zahlen des Bots - EINE Quelle fuer die Statuszeile des Endpunkts
 * (byte-gleich zu 0.9.29) und die Themen ueber das MQTT-Gateway V2.
 * $mit_netz: true = auch signal-cli fragen (/api/v1/check) und systemd
 * (Endpunkt, Takt des Bots); false = nur Dateien (healthcheck, Oberflaeche).
 */
function sg_status_werte(array $cfg, $mit_netz)
{
    $z = sg_zustaende();
    $alter = sg_herz_alter();
    $frisch = $alter >= 0 && $alter <= SG_HERZ_GRENZE;
    $w = array();
    if ($mit_netz) {
        $lebt = sg_daemon_lebt();
        $w['ok_http'] = ($lebt && $frisch) ? 1 : 0;
        $w['rpc'] = $lebt ? 1 : 0;
        $w['daemon'] = sg_dienst_laeuft() ? 1 : 0;
    }
    $w['konto'] = (string) $cfg['konto'] !== '' ? 1 : 0;
    $w['erlaubte'] = count($cfg['erlaubt']);
    $w['befehle'] = count(array_filter($cfg['befehle'], function ($b) { return !empty($b['aktiv']) && $b['wort'] !== ''; }));
    $w['zustaende'] = count($z);
    $w['gesperrt'] = !empty($cfg['gesperrt']) ? 1 : 0;
    $w['offen'] = sg_offene_meldungen();
    $w['letzter'] = sg_letzter_befehl();
    $w['abgewiesen'] = sg_zaehle_ereignisse('abgewiesen');
    $w['pinfehl'] = sg_zaehle_ereignisse('PIN falsch');
    $w['bot'] = sg_bot_laeuft() ? 1 : 0;
    $w['alter'] = $alter;
    $w['gaeste'] = count(sg_gaeste_gueltig($cfg));
    $w['letzter_min'] = $w['letzter'] < 0 ? -1 : intdiv($w['letzter'], 60);
    return $w;
}

/** Die Statuszeile des Endpunkts (aktion=status) - Wortlaut und Reihenfolge wie bis 0.9.29. */
function sg_status_zeile(array $w)
{
    return sprintf("SIGNAL;OK=%d;DAEMON=%d;KONTO=%d;ERLAUBTE=%d;BEFEHLE=%d;ZUSTAENDE=%d;GESPERRT=%d;OFFEN=%d;LETZTER=%d;ABGEWIESEN=%d;PINFEHL=%d;BOT=%d;ALTER=%d",
        $w['ok_http'], $w['daemon'], $w['konto'], $w['erlaubte'], $w['befehle'], $w['zustaende'], $w['gesperrt'],
        $w['offen'], $w['letzter'], $w['abgewiesen'], $w['pinfehl'], $w['bot'], $w['alter']);
}

/**
 * Die Status-Themen ueber das MQTT-Gateway V2 (Entwurf 2.2) - EINE Liste fuer
 * Sendecode (sg_mqtt_status), Namenstabelle, V2-Hakenliste und Reiter Test.
 * Je Eintrag: thema (unter dem Praefix), schl (Feld aus sg_status_werte bzw.
 * vom Takt), leben (geht jede Minute mit, am Filter vorbei), haken (V2-Haken
 * fuers Musterprojekt), nc (Noncached), mp (Stelle im Musterprojekt), bez
 * (Sprachschluessel), bereich, fehl (Fehlwert-Sprachschluessel oder '').
 * BOT und ALTER bleiben nur HTTP (Regeln/07: ueber MQTT gibt es kein Alter).
 */
function sg_mqtt_status_themen($cfg = null)
{
    $m1 = 'SignalBot';
    $m2 = 'SignalBot Befehle und Meldungen';
    return array(
        array('thema' => 'status/zaehler', 'schl' => 'zaehler', 'leben' => true, 'haken' => true, 'nc' => true,
              'mp' => $m1 . ' #2', 'bez' => 'MQTT.T_ZAEHLER', 'bereich' => '0 … 999', 'fehl' => ''),
        array('thema' => 'status/ok', 'schl' => 'ok', 'leben' => true, 'haken' => true, 'nc' => true,
              'mp' => $m1 . ' #1', 'bez' => 'MQTT.T_OK', 'bereich' => '0 … 1', 'fehl' => ''),
        array('thema' => 'status/ts', 'schl' => 'ts', 'leben' => true, 'haken' => false, 'nc' => false,
              'mp' => '', 'bez' => 'MQTT.T_TS', 'bereich' => 'Unix', 'fehl' => ''),
        array('thema' => 'rpc', 'schl' => 'rpc', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #4', 'bez' => 'MQTT.T_RPC', 'bereich' => '0 … 1', 'fehl' => ''),
        array('thema' => 'daemon', 'schl' => 'daemon_mqtt', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #3', 'bez' => 'MQTT.T_DAEMON', 'bereich' => '-1 … 1', 'fehl' => 'MQTT.F_DAEMON'),
        array('thema' => 'konto', 'schl' => 'konto', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #5', 'bez' => 'MQTT.T_KONTO', 'bereich' => '0 … 1', 'fehl' => ''),
        array('thema' => 'erlaubte', 'schl' => 'erlaubte', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #6', 'bez' => 'MQTT.T_ERLAUBTE', 'bereich' => '0 … 100', 'fehl' => ''),
        array('thema' => 'gaeste', 'schl' => 'gaeste', 'leben' => false, 'haken' => false, 'nc' => false,
              'mp' => '', 'bez' => 'MQTT.T_GAESTE', 'bereich' => '0 … 10', 'fehl' => ''),
        array('thema' => 'befehle', 'schl' => 'befehle', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #7', 'bez' => 'MQTT.T_BEFEHLE', 'bereich' => '0 … ' . SG_BEFEHLE, 'fehl' => ''),
        array('thema' => 'gesperrt', 'schl' => 'gesperrt', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #8, ' . $m2 . ' #20', 'bez' => 'MQTT.T_GESPERRT', 'bereich' => '0 … 1', 'fehl' => ''),
        array('thema' => 'abgewiesen', 'schl' => 'abgewiesen', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #9', 'bez' => 'MQTT.T_ABGEWIESEN', 'bereich' => '0 … 500', 'fehl' => ''),
        array('thema' => 'pinfehl', 'schl' => 'pinfehl', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m1 . ' #10', 'bez' => 'MQTT.T_PINFEHL', 'bereich' => '0 … 500', 'fehl' => ''),
        array('thema' => 'offen', 'schl' => 'offen', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m2 . ' #21', 'bez' => 'MQTT.T_OFFEN', 'bereich' => '0 … 200', 'fehl' => ''),
        array('thema' => 'zustaende', 'schl' => 'zustaende', 'leben' => false, 'haken' => true, 'nc' => false,
              'mp' => $m2 . ' #22', 'bez' => 'MQTT.T_ZUSTAENDE', 'bereich' => '0 … 50', 'fehl' => ''),
        array('thema' => 'letzter_min', 'schl' => 'letzter_min', 'leben' => true, 'haken' => true, 'nc' => false,
              'mp' => $m2 . ' #5', 'bez' => 'MQTT.T_LETZTER_MIN', 'bereich' => '-1 … 525600', 'fehl' => 'MQTT.F_LETZTER'),
    );
}

/**
 * Die Namenstabelle (Reiter Einbindung) und die V2-Hakenliste (Reiter MQTT):
 * Status-Themen, die Themen der eingeschalteten Chat-Befehle (Haken und
 * Noncached, N3), das alte Lebenszeichen online (veraltet, E-E3) und die
 * Brueckenthemen (Quittungen, status/befehle - ohne Haken).
 * Je Zeile: thema, voll, name, bez (fertiger Text, HTML), bereich, haken, nc, mp, retain.
 */
function sg_mqtt_namenstabelle(array $cfg)
{
    $pf = (string) $cfg['mqtt_topic'];
    $aus = array();
    foreach (sg_mqtt_status_themen($cfg) as $t) {
        $bez = sg_t($t['bez']) . ($t['fehl'] !== '' ? ' ' . sg_t($t['fehl']) : '');
        $aus[] = array('thema' => $t['thema'], 'voll' => sg_mqtt_thema_voll($cfg, $t['thema']),
                       'name' => sg_gateway_name($pf, $t['thema']), 'bez' => $bez, 'bereich' => $t['bereich'],
                       'haken' => $t['haken'], 'nc' => $t['nc'], 'mp' => $t['mp'], 'retain' => false, 'art' => 'status');
    }
    foreach (sg_mqtt_themen($cfg) as $th) {
        if ($th['art'] !== 'fest' && $th['art'] !== 'zahl') { continue; }
        $thema = substr($th['thema'], strlen($pf) + 1);
        $aus[] = array('thema' => $thema, 'voll' => $th['thema'], 'name' => sg_gateway_name($pf, $thema),
                       'bez' => sprintf(sg_t('MQTT.T_CHATBEFEHL'), sg_e($th['quelle'])),
                       'bereich' => $th['art'] === 'zahl' ? $th['wert'] : '0 … 1', 'haken' => true, 'nc' => true,
                       'mp' => '', 'retain' => false, 'art' => 'befehl');
    }
    if (!empty($cfg['herzschlag'])) {
        $aus[] = array('thema' => 'online', 'voll' => sg_mqtt_thema_voll($cfg, 'online'), 'name' => sg_gateway_name($pf, 'online'),
                       'bez' => sg_t('MQTT.T_ONLINE_VERALTET'), 'bereich' => 'Unix', 'haken' => false, 'nc' => false,
                       'mp' => '', 'retain' => false, 'art' => 'online');
    }
    $aus[] = array('thema' => 'befehl/<name>/quittung', 'voll' => $pf . '/befehl/<name>/quittung', 'name' => '–',
                   'bez' => sg_t('MQTT.T_QUITTUNG'), 'bereich' => sg_t('MQTT.B_TEXT'), 'haken' => false, 'nc' => false,
                   'mp' => '', 'retain' => false, 'art' => 'bruecke');
    $aus[] = array('thema' => 'status/befehle', 'voll' => $pf . '/status/befehle', 'name' => sg_gateway_name($pf, 'status/befehle'),
                   'bez' => sg_t('MQTT.T_STATUS_BEFEHLE'), 'bereich' => '-1 … 1', 'haken' => false, 'nc' => false,
                   'mp' => '', 'retain' => false, 'art' => 'bruecke');
    return $aus;
}

/**
 * Stehen unter Gateway V2 die Haken? (Reiter Test, healthcheck; N1) Gelesen
 * wird nur config/system/subscriptions.json (Subscriptions[].Id je Thema,
 * Noncached; Form wie aWATTar spot_mqtt_v2_haken_lage()). Rueckgabe
 * array(1|0|2, Klartext, gesetzt, soll, fehlende Status-Haken): 1 alle
 * Haken der Hakenliste gesetzt und Noncached wie markiert, 0 es fehlt etwas,
 * 2 nicht feststellbar (Gateway V1, Fassung oder Datei nicht lesbar).
 */
function sg_mqtt_v2_haken_lage(array $cfg)
{
    $m = sg_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) { return array(2, sg_t('TEST.A_V2_UNKLAR'), 0, 0, array()); }
    if ($f < 2) { return array(2, sg_t('TEST.A_V2_V1'), 0, 0, array()); }
    $p = sg_paths();
    $d = @json_decode((string) @file_get_contents($p['home'] . '/config/system/subscriptions.json'), true);
    if (!is_array($d)) { return array(2, sg_t('TEST.A_V2_UNKLAR'), 0, 0, array()); }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($d[$s]) && is_array($d[$s])) { $liste = $d[$s]; break; }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) { continue; }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) { $id = $e[$k]; break; }
        }
        if ($id === '') { continue; }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    $soll = 0; $da = 0; $fehlt = array(); $ohne_nc = array(); $status_fehlt = array();
    foreach (sg_mqtt_namenstabelle($cfg) as $r) {
        if (!$r['haken']) { continue; }
        $soll++;
        if (!array_key_exists($r['voll'], $abo)) {
            $fehlt[] = $r['voll'];
            if ($r['art'] === 'status') { $status_fehlt[] = $r['voll']; }
            continue;
        }
        $da++;
        if ($r['nc'] && !$abo[$r['voll']]) {
            $ohne_nc[] = $r['voll'];
            if ($r['art'] === 'status') { $status_fehlt[] = $r['voll'] . ' (Noncached)'; }
        }
    }
    if (!$fehlt && !$ohne_nc) {
        return array(1, sprintf(sg_t('TEST.A_V2_OK'), $da, $soll), $da, $soll, array());
    }
    $txt = sprintf(sg_t('TEST.A_V2_FEHLT'), $da, $soll);
    if ($fehlt) { $txt .= ' ' . sprintf(sg_t('TEST.A_V2_FEHLEN'), sg_e(implode(', ', array_slice($fehlt, 0, 6))) . (count($fehlt) > 6 ? ' …' : '')); }
    if ($ohne_nc) { $txt .= ' ' . sprintf(sg_t('TEST.A_V2_NONCACHED'), sg_e(implode(', ', $ohne_nc))); }
    return array(0, $txt, $da, $soll, $status_fehlt);
}

/**
 * Eine Runde an den UDP-Eingang des MQTT-Gateways: alle Paare thema => wert
 * hintereinander, ab dem zweiten Datagramm 5 ms Pause IN der Sendefunktion
 * (Regeln/07). Nur mit mqtt_ein und erkanntem UDP-Port; ohne Gateway still
 * (gebremste Protokollzeile). Rueckgabe: Zahl der gesendeten Datagramme.
 */
function sg_mqtt_runde(array $cfg, array $paare)
{
    if (empty($cfg['mqtt_ein']) || !$paare) { return 0; }
    $z = sg_mqtt_zustand();
    if (!$z['udpport']) {
        sg_log_gebremst('status_kein_port', 'MQTT-Gateway V2: kein UDP-Eingangsport in der general.json - der Status geht nicht hinaus.');
        return 0;
    }
    $n = 0;
    $erst = true;
    foreach ($paare as $thema => $wert) {
        $w = sg_mqtt_wert_saeubern($wert);
        if ($w === '') { continue; }
        if (!$erst) { usleep(5000); }
        $erst = false;
        $n += sg_udp_senden($z['udpport'], 'publish ' . sg_mqtt_thema_voll($cfg, (string) $thema) . ' ' . $w);
    }
    return $n;
}

/** Lebenszeichen-Zaehler 0...999 in tmp/status_zaehler (weiter ab dem gemerkten Wert; fehlt die Datei: 0). */
function sg_status_zaehler_weiter()
{
    $f = sg_tmpdir() . '/status_zaehler';
    $roh = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    $n = preg_match('/^[0-9]{1,3}$/', $roh) ? ((int) $roh + 1) % 1000 : 0;
    sg_write_atomic($f, (string) $n, 0644);
    return $n;
}

/**
 * Den Status ueber das MQTT-Gateway V2 senden (vom Takt des Bots, einmal je
 * 60 s; Entwurf 2.4): Lebenszeichen status/zaehler, status/ok, status/ts und
 * letzter_min IMMER, die uebrigen bei Aenderung gegen tmp/mqtt_letzte.json,
 * voll bei $voll (Start des Bots, alle 30 min). $ok = 1 heisst "Nachrichten
 * kommen an" (der laufende Ereignisstrom ist nutzbar, 2.3). Alles fluechtig.
 * Rueckgabe: Zahl der gesendeten Datagramme.
 */
function sg_mqtt_status(array $cfg, $ok, $voll)
{
    if (empty($cfg['mqtt_ein'])) { return 0; }
    $w = sg_status_werte($cfg, true);
    $w['ok'] = $ok ? 1 : 0;
    $w['ts'] = time();
    $w['daemon_mqtt'] = $w['daemon'] ? 1 : (sg_dienst_bekannt() ? 0 : -1);
    $w['zaehler'] = sg_status_zaehler_weiter();
    $merker = sg_tmpdir() . '/mqtt_letzte.json';
    $alt = array();
    if (!$voll && is_file($merker)) {
        $alt = json_decode((string) @file_get_contents($merker), true);
        if (!is_array($alt)) { $alt = array(); }
    }
    $paare = array();
    $neu = array();
    foreach (sg_mqtt_status_themen($cfg) as $t) {
        $v = (string) $w[$t['schl']];
        $neu[$t['thema']] = $v;
        if ($t['leben'] || $voll || !array_key_exists($t['thema'], $alt) || (string) $alt[$t['thema']] !== $v) {
            $paare[$t['thema']] = $v;
        }
    }
    $n = sg_mqtt_runde($cfg, $paare);
    // Nur nach einer ganz gesendeten Runde merken - sonst gehen Aenderungen der naechsten Minute mit.
    if ($n === count($paare)) { sg_write_atomic($merker, json_encode($neu), 0644); }
    return $n;
}

/* ==================================================================
 * Seit 0.9.30 (Gruppe 32) - Meldungen aus Loxone: EIN Ablauf fuer den
 * HTTP-Endpunkt und die Befehlsbruecke 1.0.1 (Entscheidung 2, E-E4 bis E-E7)
 * ==================================================================
 *
 * Der Endpunkt (Quelle 'http') antwortet byte-gleich zu 0.9.29 und sendet wie
 * bisher sofort. Die Bruecke (Quelle 'mqtt') sendet NIE selbst an signal-cli:
 * sie reiht in die Ausgangswarteschlange ein, zugestellt wird vom Bot (Takt
 * <= 10 s). sg_senden() wartet bis 25 s je Ziel - synchron in der Bruecke
 * gesendet, hielte ein Rundruf bei haengendem signal-cli den Brueckendienst
 * Minuten an, und kein "sperren" kaeme durch (N6).
 */

/** Plugin-Bremse fuer Meldungen ueber die Bruecke (alle Namen zusammen, je 60 s; die Warteschlange haelt 200). */
define('SG_BRUECKE_JE_MINUTE', 20);
/** Vorschlag 1: Bild eines Plugins hoechstens 5 MB, hoechstens 20 Kopien liegen in anhang/. */
define('SG_ANHANG_MAX', 5242880);
define('SG_ANHANG_ANZAHL', 20);

/** Endpunkt fuer Loxone an? (Lese-Regel in sg_config: Bestand 1, Neuinstallation 0; E-E8) */
function sg_endpunkt_ein(array $cfg)
{
    return !empty($cfg['endpunkt_ein']);
}

/** Text aus Loxone/Plugin: woertliches \n -> Umbruch, Steuerzeichen raus, hoechstens 2000 Zeichen (wie der Endpunkt). */
function sg_meldetext($roh, &$gekuerzt)
{
    $t = str_replace('\n', "\n", (string) $roh);
    $t = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t));
    $gekuerzt = 0;
    if (sg_laenge($t) > 2000) { $t = sg_kuerzen($t, 2000); $gekuerzt = 1; }
    return $t;
}

/** Ziele wie der Endpunkt: an = gepruefte Nummer der Weissliste, sonst die Gruppe, sonst alle Erlaubten. array(ziele, grund). */
function sg_meldeziele(array $cfg, $an)
{
    if ((string) $an !== '') {
        if (!preg_match('/^\+[0-9]{6,20}$/', (string) $an)) { return array(array(), 'EMPFAENGER_UNGUELTIG'); }
        if (!sg_erlaubt($an)) { return array(array(), 'EMPFAENGER_NICHT_ERLAUBT'); }
        return array(array((string) $an), '');
    }
    if ((string) $cfg['gruppe'] !== '') { return array(array(SG_GRUPPE_VOR . $cfg['gruppe']), ''); }
    if (!$cfg['erlaubt']) { return array(array(), 'KEIN_EMPFAENGER'); }
    return array($cfg['erlaubt'], '');
}

/** Ende der Nachtruhe als Unix-Zeit (herausgeloest aus sg_melden(); auch fuer die Bruecke). */
function sg_nachtruhe_ende(array $cfg)
{
    list($bh, $bm) = array_map('intval', explode(':', $cfg['nacht_bis']));
    $ziel = mktime($bh, $bm, 0);
    if ($ziel <= time()) { $ziel += 86400; }
    return $ziel;
}

/**
 * Eine Aktion aus Loxone ausfuehren - EINE Stelle fuer den Endpunkt ('http')
 * und die Befehlsbruecke ('mqtt'). Rueckgabe array(HTTP-Code, Zeile ohne
 * Zeilenende); fuer den Endpunkt byte-gleich zu 0.9.29.
 * $par: sperren/entsperren -; zustand name, wert; senden text, dringend,
 * an (mqtt: +Nummer) bzw. an_roh und bild (http); plugin absender, roh.
 */
function sg_befehl_ausfuehren($aktion, array $par, $quelle)
{
    $cfg = $quelle === 'http' ? sg_config(false) : sg_config();
    if ($aktion === 'sperren' || $aktion === 'entsperren') {
        $neu = $aktion === 'sperren' ? 1 : 0;
        /* Unter der Konfigurationssperre, mit frischem Lesen (C9). */
        $geaendert = false;
        list($ok, ) = sg_config_aendern(function ($c) use ($neu, &$geaendert) {
            if ((int) $c['gesperrt'] === $neu) { return null; }
            $c['gesperrt'] = $neu;
            $geaendert = true;
            return $c;
        });
        if (!$ok) { return array(500, 'SIGNAL;OK=0;GRUND=NICHT_GESPEICHERT'); }
        if ($geaendert) {
            if ($quelle === 'http') {
                sg_log($neu ? 'Bot ueber den Endpunkt GESPERRT.' : 'Bot ueber den Endpunkt entsperrt.');
                sg_ereignis_merken('Loxone', $neu ? 'gesperrt' : 'entsperrt', '');
            } else {
                sg_log($neu ? 'Bot ueber MQTT (Befehlsbruecke) GESPERRT.' : 'Bot ueber MQTT (Befehlsbruecke) entsperrt.');
                sg_ereignis_merken('Loxone (MQTT)', $neu ? 'gesperrt' : 'entsperrt', '');
            }
        }
        return array(200, sprintf('SIGNAL;OK=1;AKTION=%s;GESPERRT=%d', $aktion, $neu));
    }
    if ($aktion === 'zustand') {
        if (empty($cfg['zustand_ein'])) { return array(403, 'SIGNAL;OK=0;GRUND=ZUSTAENDE_AUS'); }
        $name = trim((string) (isset($par['name']) ? $par['name'] : ''));
        $wert = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) (isset($par['wert']) ? $par['wert'] : '')));
        if ($name === '' || $wert === '') { return array(400, 'SIGNAL;OK=0;GRUND=NAME_ODER_WERT_FEHLT'); }
        /* Der Name wird geprueft, nicht gesaeubert (C11, Nr. 19). */
        if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $name)) { return array(400, 'SIGNAL;OK=0;GRUND=NAME_UNGUELTIG'); }
        $gek = 0;
        if (sg_laenge($wert) > 120) { $wert = sg_kuerzen($wert, 120); $gek = 1; }
        if (!sg_zustand_setzen($name, $wert)) { return array(500, 'SIGNAL;OK=0;GRUND=NICHT_GESPEICHERT'); }
        return array(200, sprintf('SIGNAL;OK=1;AKTION=zustand;NAME=%s%s', $name, $gek ? ';GEKUERZT=1' : ''));
    }
    if ($aktion === 'senden' && $quelle === 'http') {
        return sg_befehl_senden_http($cfg, $par);
    }
    if ($aktion === 'senden' || $aktion === 'plugin') {
        return sg_befehl_senden_mqtt($cfg, $aktion, $par);
    }
    return array(400, 'SIGNAL;OK=0;GRUND=UNBEKANNTE_AKTION');
}

/**
 * senden ueber den Endpunkt - der Ablauf von 0.9.29 (webfrontend/html/index.php
 * :228-322), unveraendert hierher verlegt: sofort senden (sg_melden), mit Bild
 * sg_senden(), Antwortzeilen und Protokollzeilen wie bisher.
 */
function sg_befehl_senden_http(array $cfg, array $par)
{
    $text = isset($par['text']) ? (string) $par['text'] : '';
    // Steuerzeichen raus, Zeilenumbruch als \n zugelassen: Loxone kann keinen
    // echten Umbruch in eine URL schreiben.
    $text = str_replace('\n', "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    $text = trim($text);
    if ($text === '') {
        return array(400, 'SIGNAL;OK=0;GRUND=TEXT_FEHLT');
    }
    /* Gekuerzt wird weiter (eine Alarmmeldung soll hinausgehen), aber gemeldet (C11). */
    $sg_gekuerzt = 0;
    if (sg_laenge($text) > 2000) { $text = sg_kuerzen($text, 2000); $sg_gekuerzt = 1; }

    /* Ein gesetztes, aber unbrauchbares &an= wird ABGEWIESEN (seit 0.9.12); ein
     * LEERES &an= bleibt der ausdrueckliche Rundruf. */
    $sg_an_roh = isset($par['an_roh']) ? (string) $par['an_roh'] : '';
    $an = preg_replace('/[^0-9+]/', '', $sg_an_roh);
    if ($sg_an_roh !== '' && !preg_match('/^\+[0-9]{6,20}$/', $an)) {
        sg_log('Senden abgewiesen: "' . sg_maske($sg_an_roh) . '" ist keine gueltige Rufnummer');
        return array(400, 'SIGNAL;OK=0;GRUND=EMPFAENGER_UNGUELTIG');
    }
    if ($an !== '') {
        // Auch beim Senden gilt die Weissliste.
        if (!sg_erlaubt($an)) {
            sg_log('Senden abgewiesen: ' . sg_maske($an) . ' steht nicht auf der Weissliste');
            return array(403, 'SIGNAL;OK=0;GRUND=EMPFAENGER_NICHT_ERLAUBT');
        }
        $ziele = array($an);
    } elseif ((string) $cfg['gruppe'] !== '') {
        /* Der Rundruf geht EINMAL in die eingestellte Gruppe (0.9.29, E13). */
        $ziele = array(SG_GRUPPE_VOR . $cfg['gruppe']);
    } else {
        $ziele = $cfg['erlaubt'];
    }
    $sg_gruppe = ($ziele && sg_ist_gruppe($ziele[0])) ? 1 : 0;
    if (!$ziele) {
        return array(400, 'SIGNAL;OK=0;GRUND=KEIN_EMPFAENGER');
    }
    $dringend = !empty($par['dringend']) ? 1 : 0;

    /* Ein Anhang NUR aus dem eigenen Datenordner, verglichen MIT Trenner, nur mit Bildendung (C5). */
    $anhang = '';
    $sg_bild = isset($par['bild']) ? (string) $par['bild'] : '';
    if ($sg_bild !== '') {
        $echt = realpath($sg_bild);
        $ort = realpath(sg_datadir());
        $drin = $ort && $echt && strpos($echt, rtrim($ort, '/\\') . DIRECTORY_SEPARATOR) === 0;
        $bild = $echt && preg_match('/\.(jpe?g|png|gif|webp)$/i', $echt);
        if (!$drin || !$bild || !is_file($echt)) {
            return array(400, 'SIGNAL;OK=0;GRUND=ANHANG_UNZULAESSIG');
        }
        $anhang = $echt;
    }

    $ok = 0;
    foreach ($ziele as $ziel) {
        if ($anhang !== '') {
            // Mit Anhang wird unmittelbar gesendet.
            if (sg_senden($ziel, $text, $anhang)) { $ok++; }
        } else {
            $art = sg_melden($ziel, $text, $dringend);
            if ($art !== 'fehler') { $ok++; }
        }
    }
    sg_log('Meldung aus Loxone ' . ($sg_gruppe ? 'in die Gruppe' : 'an ' . $ok . ' von ' . count($ziele) . ' Empfaenger')
         . ($dringend ? ' (dringend)' : '') . ($anhang !== '' ? ' (mit Anhang)' : ''));
    if ($ok === 0) {
        return array(502, 'SIGNAL;OK=0;GRUND=SENDEN_FEHLGESCHLAGEN');
    }
    return array(200, sprintf('SIGNAL;OK=1;AKTION=senden;EMPFAENGER=%d;DRINGEND=%d%s%s', $ok, $dringend,
        $sg_gruppe ? ';GRUPPE=1' : '', $sg_gekuerzt ? ';GEKUERZT=1' : ''));
}

/**
 * senden/dringend/plugin ueber die Befehlsbruecke: IMMER einreihen (der Bot
 * stellt zu), Bremse 20 je Minute ueber alle Meldungen der Bruecke, Ziele wie
 * der Endpunkt (Plugins nur Gruppe bzw. alle Erlaubten, nie eine Einzelnummer).
 */
function sg_befehl_senden_mqtt(array $cfg, $aktion, array $par)
{
    $absender = '';
    $anhang = '';
    if ($aktion === 'plugin') {
        $absender = isset($par['absender']) ? (string) $par['absender'] : '';
        list($par, $grund) = sg_plugin_nachricht($cfg, $absender, isset($par['roh']) ? (string) $par['roh'] : '');
        if ($grund !== '') {
            sg_plugin_abgelehnt_merken($absender, $grund);
            return array(403, 'SIGNAL;OK=0;GRUND=' . $grund);
        }
        $anhang = isset($par['anhang']) ? (string) $par['anhang'] : '';
        unset($par['an']);   // Plugins: nie eine Einzelnummer (Rufnummern gehoeren nicht in fremde Plugins)
    }
    $gek = 0;
    $text = sg_meldetext(isset($par['text']) ? $par['text'] : '', $gek);
    if ($text === '') { sg_anhang_weg_falls_frei($anhang); return array(400, 'SIGNAL;OK=0;GRUND=TEXT_FEHLT'); }
    list($ziele, $grund) = sg_meldeziele($cfg, isset($par['an']) ? (string) $par['an'] : '');
    if ($grund !== '') {
        sg_anhang_weg_falls_frei($anhang);
        return array($grund === 'KEIN_EMPFAENGER' ? 400 : 403, 'SIGNAL;OK=0;GRUND=' . $grund);
    }
    $dringend = !empty($par['dringend']) ? 1 : 0;
    if (!sg_bremse_frei('bruecke', SG_BRUECKE_JE_MINUTE)) {
        sg_anhang_weg_falls_frei($anhang);
        sg_log_gebremst('bruecke_bremse', 'Befehlsbruecke: mehr als ' . SG_BRUECKE_JE_MINUTE
            . ' Meldungen je Minute - weitere werden abgelehnt (GEBREMST).', 60);
        if ($absender !== '') { sg_plugin_abgelehnt_merken($absender, 'GEBREMST'); }
        return array(429, 'SIGNAL;OK=0;GRUND=GEBREMST');
    }
    $vor = $absender !== '' ? '[' . $absender . '] ' : '';
    $ab = (!$dringend && sg_nachtruhe($cfg)) ? sg_nachtruhe_ende($cfg) : 0;
    $n = 0;
    foreach ($ziele as $z) {
        if (sg_warteschlange_ein($z, $vor . $text, $dringend, $ab, $anhang)) { $n++; }
    }
    if ($n === 0) { sg_anhang_weg_falls_frei($anhang); return array(502, 'SIGNAL;OK=0;GRUND=NICHT_EINGEREIHT'); }
    sg_log('Meldung aus ' . ($absender !== '' ? 'Plugin ' . $absender : 'Loxone') . ' (MQTT) eingereiht fuer ' . $n . ' Ziel(e)'
         . ($dringend ? ' (dringend)' : '') . ($gek ? ' (gekuerzt)' : '') . ($anhang !== '' ? ' (mit Bild)' : ''));
    if ($absender !== '') {
        sg_ereignis_merken('Plugin ' . $absender, 'Meldung', sg_kuerzen($text, 60) . ($anhang !== '' ? ' (mit Bild)' : ''));
    }
    return array(200, sprintf('SIGNAL;OK=1;AKTION=senden;EMPFAENGER=%d;DRINGEND=%d%s', $n, $dringend, $gek ? ';GEKUERZT=1' : ''));
}

/**
 * Vorschlag 1: Nutzlast eines Plugins pruefen. Klartext ODER JSON
 * {"text": "...", "dringend": 0|1, "bild": "<pfad>"}. Der Absender steht im
 * Thema (plugin/<ordner>) und muss in plugin_absender freigegeben sein.
 * Rueckgabe array(par, grund); par traegt bei einem Bild 'anhang' (Kopie).
 */
function sg_plugin_nachricht(array $cfg, $absender, $roh)
{
    $frei = isset($cfg['plugin_absender']) && is_array($cfg['plugin_absender']) ? $cfg['plugin_absender'] : array();
    if (!preg_match('/^[a-z0-9_]{1,40}$/', (string) $absender) || !in_array($absender, $frei, true)) {
        return array(array(), 'ABSENDER_NICHT_FREIGEGEBEN');
    }
    $par = array('absender' => $absender, 'text' => $roh, 'dringend' => 0);
    if (substr(ltrim($roh), 0, 1) === '{') {
        $j = json_decode($roh, true);
        if (!is_array($j) || !isset($j['text']) || !is_string($j['text'])) { return array(array(), 'NUTZLAST_UNGUELTIG'); }
        $par['text'] = $j['text'];
        $par['dringend'] = !empty($j['dringend']) ? 1 : 0;
        if (isset($j['bild']) && $j['bild'] !== '' && $j['bild'] !== null) {
            if (!is_string($j['bild'])) { return array(array(), 'BILD_UNZULAESSIG'); }
            list($kopie, $g) = sg_plugin_bild($absender, $j['bild']);
            if ($g !== '') { return array(array(), $g); }
            $par['anhang'] = $kopie;
        }
    }
    return array($par, '');
}

/** Der Ordner fuer Bildkopien (data/plugins/<ordner>/anhang, 0700). */
function sg_anhang_ordner()
{
    $d = sg_datadir() . '/anhang';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    @chmod($d, 0700);
    return $d;
}

/**
 * Vorschlag 1, Weg A (E-E13): ein Bild aus dem Datenordner des Absenders.
 * Nach realpath unter <home>/data/plugins/<absender>/ (Vergleich MIT Trenner),
 * regulaere Datei, Endung jpg/jpeg/png/gif/webp, hoechstens 5 MB, die ersten
 * Byte passen zur Endung. Sofort kopiert nach anhang/<id>.<endung> (0600) -
 * die Warteschlange stellt bis 10 s spaeter zu, und ein Kamera-Plugin
 * ueberschreibt sein "letztes Bild" vielleicht vorher. Rueckgabe array(Kopie, grund).
 */
function sg_plugin_bild($absender, $pfad)
{
    $p = sg_paths();
    if ($p['home'] === '' || !preg_match('/^[a-z0-9_]{1,40}$/', (string) $absender)) { return array('', 'BILD_UNZULAESSIG'); }
    $basis = realpath($p['home'] . '/data/plugins/' . $absender);
    $echt = realpath((string) $pfad);
    if (!$basis || !$echt || strpos($echt, rtrim($basis, '/\\') . DIRECTORY_SEPARATOR) !== 0) { return array('', 'BILD_UNZULAESSIG'); }
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $echt, $m) || !is_file($echt) || !is_readable($echt)) {
        return array('', 'BILD_UNZULAESSIG');
    }
    $groesse = @filesize($echt);
    if ($groesse === false || $groesse <= 0 || $groesse > SG_ANHANG_MAX) { return array('', 'BILD_ZU_GROSS'); }
    $fp = @fopen($echt, 'rb');
    $kopf = $fp ? (string) fread($fp, 12) : '';
    if ($fp) { fclose($fp); }
    $endung = strtolower($m[1]);
    $passt = array('jpg' => "\xFF\xD8", 'jpeg' => "\xFF\xD8", 'png' => "\x89PNG", 'gif' => 'GIF');
    if ($endung === 'webp') {
        $gut = substr($kopf, 0, 4) === 'RIFF' && substr($kopf, 8, 4) === 'WEBP';
    } else {
        $gut = strpos($kopf, $passt[$endung]) === 0;
    }
    if (!$gut) { return array('', 'BILD_UNZULAESSIG'); }
    $ziel = sg_anhang_ordner() . '/' . sg_token(16) . '.' . ($endung === 'jpeg' ? 'jpg' : $endung);
    if (!@copy($echt, $ziel) || @filesize($ziel) !== $groesse) {
        @unlink($ziel);
        return array('', 'BILD_NICHT_KOPIERT');
    }
    @chmod($ziel, 0600);
    sg_anhang_aufraeumen();
    return array($ziel, '');
}

/** Ist $datei eine Kopie in anhang/ (nur solche raeumt der Bot weg)? */
function sg_anhang_eigen($datei)
{
    $d = (string) $datei;
    if ($d === '') { return false; }
    $ordner = realpath(sg_datadir() . '/anhang');
    $echt = realpath($d);
    return $ordner && $echt && dirname($echt) === $ordner && preg_match('/^[A-Za-z0-9]{16}\.(jpg|png|gif|webp)$/', basename($echt));
}

/** Hoechstens SG_ANHANG_ANZAHL Kopien liegen; die aeltesten fallen weg. */
function sg_anhang_aufraeumen()
{
    $liste = glob(sg_datadir() . '/anhang/*') ?: array();
    if (count($liste) <= SG_ANHANG_ANZAHL) { return; }
    usort($liste, function ($a, $b) { return (int) @filemtime($a) - (int) @filemtime($b); });
    foreach (array_slice($liste, 0, count($liste) - SG_ANHANG_ANZAHL) as $f) {
        if (sg_anhang_eigen($f)) { @unlink($f); }
    }
}

/** Eine Kopie entfernen, wenn kein Eintrag der Warteschlange sie mehr traegt. */
function sg_anhang_weg_falls_frei($datei, $liste = null)
{
    if (!sg_anhang_eigen($datei)) { return; }
    if ($liste === null) { $liste = sg_warteschlange(); }
    foreach ($liste as $e) {
        if (isset($e['anhang']) && (string) $e['anhang'] === (string) $datei) { return; }
    }
    @unlink($datei);
}

/** Abgelehnte Plugin-Meldung ins Ereignisprotokoll - je Absender und Grund hoechstens einmal je 60 s. */
function sg_plugin_abgelehnt_merken($absender, $grund)
{
    $f = sg_tmpdir() . '/plugin_ab_' . md5((string) $absender . '|' . (string) $grund);
    $letzt = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzt < 60) { return; }
    @file_put_contents($f, (string) time());
    sg_ereignis_merken('Plugin ' . sg_kuerzen(preg_replace('/[^a-z0-9_]/', '', (string) $absender), 40), 'Plugin abgelehnt', (string) $grund);
}

/** Rueckgabe des Ablaufs -> Quittung der Bruecke (Bauart spot_befehl_bruecke). array(bool, GRUND). */
function sg_befehl_bruecke($r)
{
    $z = (is_array($r) && isset($r[1])) ? (string) $r[1] : '';
    $code = (is_array($r) && isset($r[0])) ? (int) $r[0] : 0;
    if ($code === 200 && strpos($z, ';OK=1;') !== false) { return array(true, ''); }
    if (preg_match('/;GRUND=([A-Z0-9_]+)/', $z, $m)) { return array(false, $m[1]); }
    return array(false, 'FEHLER');
}

/** Einstellungen fuer die Bruecke (bin/befehle_zuordnung.php). */
function sg_befehle_einstellungen()
{
    $c = sg_config();
    $m = !empty($c['mqtt_ein']);
    return array('praefix' => (string) $c['mqtt_topic'], 'annehmen' => !empty($c['befehle_mqtt_ein']) && $m,
                 'eingreifend' => !empty($c['befehle_entsperren_ein']), 'mqtt_ein' => $m);
}

/**
 * Die Befehlsbruecke als Bibliothek laden (BB_NUR_LADEN; Bauart
 * cam_bruecke_laden(), fer_bruecke_laden()). Installiert unter
 * bin/plugins/<ordner>/, im Archiv unter bin/ neben webfrontend/.
 */
function sg_bruecke_laden()
{
    if (function_exists('bb_waechter')) { return true; }
    $p = sg_paths();
    $kand = array();
    if ($p['home'] !== '') { $kand[] = $p['home'] . '/bin/plugins/' . $p['plugin'] . '/befehlsbruecke.php'; }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste(): EINE Quelle fuer Reiter und Vorlage). array(Liste|null, Fehlertext). */
function sg_bruecke_liste()
{
    if (!sg_bruecke_laden()) { return array(null, sg_t('BEFEHL.T_NICHT_DA')); }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) { return array(null, $fehler); }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(null, $fehl[0]); }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/**
 * Die Bruecke sofort schalten - nach dem Speichern im Reiter MQTT und nach dem
 * Zurueckspielen (Bauart cam_bruecke_schalten()). Rueckgabe array(rc oder null
 * = nichts zu tun, 'start'|'stop'|'', Ausgabe).
 */
function sg_bruecke_schalten(array $cfg)
{
    if (!sg_bruecke_laden()) { return array(1, '', sg_t('BEFEHL.T_NICHT_DA')); }
    $p = bb_pfade();
    if (!$p) { return array(1, '', sg_t('BEFEHL.T_KEINE_WURZEL')); }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) { return array(null, '', ''); }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Meldungen nach sg_bruecke_schalten(): array(ok-Meldungen, Fehlermeldungen), maskiert. */
function sg_bruecke_meldungen(array $r)
{
    list($rc, $art, $aus) = $r;
    if ($art === 'start') {
        return $rc === 0 ? array(array(sg_t('BEFEHL.M_EIN') . ' ' . sg_e($aus)), array())
                         : array(array(), array(sprintf(sg_t('BEFEHL.M_START_FEHL'), sg_e($aus))));
    }
    if ($art === 'stop') {
        return $rc === 0 ? array(array(sg_t('BEFEHL.M_AUS')), array())
                         : array(array(), array(sprintf(sg_t('BEFEHL.M_STOP_FEHL'), sg_e($aus))));
    }
    if ($rc !== null && $rc !== 0) { return array(array(), array(sprintf(sg_t('BEFEHL.M_START_FEHL'), sg_e($aus)))); }
    return array(array(), array());
}

/**
 * Lage der Befehlsbruecke fuer Reiter Test und healthcheck (Bauart
 * cam_bruecke_lage()): Dienst laeuft (argumentweise), zustand.json juenger als
 * 180 s, Abo steht. Rueckgabe array(true|false|null, Text) - null heisst "aus".
 */
function sg_bruecke_lage()
{
    if (!sg_bruecke_laden()) { return array(false, sg_t('BEFEHL.T_NICHT_DA')); }
    if (!bb_pfade()) { return array(null, sg_t('BEFEHL.T_KEINE_WURZEL')); }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) { return array(null, sg_t('BEFEHL.T_AUS')); }
    if ($soll !== 0) { return array(false, sprintf(sg_t('BEFEHL.T_DEFEKT'), sg_e($grund))); }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(sg_t('BEFEHL.T_LAEUFT'), sg_e(implode(' ', $pids)), sg_e(is_string($zu['abo']) ? $zu['abo'] : ''),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = (isset($zu['letzter']) && is_array($zu['letzter'])) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(sg_t('BEFEHL.T_LETZTER'), sg_e((string) $lz['name']),
                sg_e(isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-'), isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . sg_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? sg_t('BEFEHL.W_KEIN_DIENST') : ($alter === null ? sg_t('BEFEHL.W_KEIN_ZUSTAND') : ($alter > BB_WACHE_GRENZE
        ? sprintf(sg_t('BEFEHL.W_ALT'), $alter) : sg_t('BEFEHL.W_OHNE_ABO') . (is_array($zu) && !empty($zu['fehler']) ? ': ' . $zu['fehler'] : '')));
    return array(false, sprintf(sg_t('BEFEHL.T_STEHT'), sg_e($wie)));
}

/**
 * Die Befehle der Vorlage "Befehle (MQTT)" - die sechs Befehle des
 * Musterprojekts (Seite "SignalBot Befehle und Meldungen" #8, #12, #14, #16,
 * #17, #19), jetzt "publish <praefix>/befehl/..." an den UDP-Eingang des
 * MQTT-Gateways. Titel wie bis 0.9.29 (Gedaechtnis gateway-werte-virtueller-
 * eingang: kein Eingang des Gateways heisst so). bb_vorlage() der
 * Stammfassung schriebe Textbefehle nur als Kommentar - deshalb eigene Liste.
 */
function sg_bruecke_vorlagenbefehle()
{
    $cfg = sg_config();
    $w = (string) $cfg['mqtt_topic'] . '/befehl/';
    return array(
        array('title' => 'Signal - Meldung senden', 'kom' => 'SignalBot: Meldung senden (MQTT)',
              'on' => 'publish ' . $w . 'senden ' . sg_klartext('VORLAGE.TEXT1'), 'off' => '', 'analog' => false),
        array('title' => "Signal - T\u{fc}rklingel", 'kom' => "SignalBot: T\u{fc}rklingel (MQTT)",
              'on' => 'publish ' . $w . 'senden ' . sg_klartext('VORLAGE.TEXT6'), 'off' => '', 'analog' => false),
        array('title' => 'Signal - dringende Meldung', 'kom' => 'SignalBot: dringende Meldung (MQTT)',
              'on' => 'publish ' . $w . 'dringend ' . sg_klartext('VORLAGE.TEXT3'), 'off' => '', 'analog' => false),
        array('title' => 'Signal - Zustand melden', 'kom' => 'SignalBot: Zustand alarm (MQTT)',
              'on' => 'publish ' . $w . 'zustand/alarm <v.0>', 'off' => '', 'analog' => true),
        array('title' => 'Signal - Meldung mit Wert', 'kom' => 'SignalBot: Meldung mit Wert (MQTT)',
              'on' => 'publish ' . $w . 'senden ' . sg_klartext('VORLAGE.TEXT2') . ' <v.0>', 'off' => '', 'analog' => true),
        array('title' => 'Signal - Bot sperren', 'kom' => 'SignalBot: Bot sperren/entsperren (MQTT)',
              'on' => 'publish ' . $w . 'sperren 1', 'off' => 'publish ' . $w . 'entsperren 1', 'analog' => false),
    );
}

/** Die Vorlage VQ_signalbot_mqtt.xml: eigener Ausgang am UDP-Eingang des MQTT-Gateways (Form bb_vorlage()).
 * Seit 0.9.31 (Nachtwelle N1) CRLF wie bb_vorlage() der Bruecke 1.0.2 und die _Test.xml-Ausfuhren der
 * Loxone Config (Regeln/07); Inhalt sonst byte-gleich zu 0.9.30. */
function sg_bruecke_vorlage()
{
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/:[0-9]+$/', '', preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']))
        : (gethostname() ?: 'loxberry');
    $m = sg_mqtt_zustand();
    $port = $m['udpport'] ? (int) $m['udpport'] : 11884;
    $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $z = array('<?xml version="1.0" encoding="utf-8"?>');
    $z[] = '<VirtualOut HintText="" Title="SignalBot MQTT-Befehle" Comment="'
         . $x('UDP-Eingang des MQTT-Gateways V2 auf dem LoxBerry; wirkt nur mit Haken "Befehle ueber MQTT annehmen"')
         . '" Address="' . $x('/dev/udp/' . $host . '/' . $port) . '" CmdInit="" CloseAfterSend="true" CmdSep="">';
    $z[] = "\t" . '<Info templateType="3" minVersion="17010727"/>';
    foreach (sg_bruecke_vorlagenbefehle() as $b) {
        $z[] = "\t" . '<VirtualOutCmd Title="' . $x($b['title']) . '" Comment="' . $x($b['kom']) . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="'
             . $x($b['on']) . '" CmdOnHTTP="" CmdOnPost="" CmdOff="' . $x($b['off']) . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer=""'
             . ($b['analog'] ? ' Analog="true"' : '') . ' Repeat="0" RepeatRate="0"'
             . ($b['analog'] ? ' SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10"' : '') . ' HintText=""/>';
    }
    $z[] = '</VirtualOut>';
    return array('VQ_signalbot_mqtt.xml', implode("\r\n", $z) . "\r\n");
}

/**
 * Vorschlag 1: die Ordnernamen der installierten Plugins (data/system/plugindatabase.json, nur gelesen; duldsam:
 * {"plugins": {md5: {...}}}, eine Liste oder die Eintraege ohne "plugins"). Ohne den eigenen Ordner. Nur Namen
 * aus [a-z0-9_]{1,40} - so steht ein Absender im Thema plugin/<ordner>.
 */
function sg_plugins_installiert()
{
    $p = sg_paths();
    if ($p['home'] === '') { return array(); }
    $d = json_decode((string) @file_get_contents($p['home'] . '/data/system/plugindatabase.json'), true);
    if (!is_array($d)) { return array(); }
    $liste = (isset($d['plugins']) && is_array($d['plugins'])) ? $d['plugins'] : $d;
    $aus = array();
    foreach ($liste as $e) {
        if (!is_array($e) || !isset($e['folder']) || !is_string($e['folder'])) { continue; }
        $f = $e['folder'];
        if ($f !== $p['plugin'] && preg_match('/^[a-z0-9_]{1,40}$/', $f)) { $aus[] = $f; }
    }
    $aus = array_values(array_unique($aus));
    sort($aus);
    return $aus;
}

/* ==================================================================
 * Seit 0.9.30 (Gruppe 32) - PIN-Migration (G4), Spaetliste der Sicherung,
 * Gaeste mit "gueltig bis" (Vorschlag 4, E-E17)
 * ================================================================== */

/** Hoechstzahl der Gaeste und laengste Frist ab dem Speichern (Tage). */
define('SG_GAESTE_MAX', 10);
define('SG_GAST_TAGE', 30);

/**
 * G4: eine Klartext-PIN (Altbestand vor 0.9.12, Sicherung, Handeintrag) in
 * einen Hash ueberfuehren. Ein vorhandener Hash gewinnt (sg_pin_stimmt()
 * prueft ihn zuerst) - der Klartext faellt in jedem Fall weg.
 * Rueckgabe array(Konfiguration, geaendert).
 */
function sg_pin_migrieren(array $c)
{
    $klar = isset($c['pin']) && (is_string($c['pin']) || is_int($c['pin'])) ? (string) $c['pin'] : '';
    if ($klar === '') { return array($c, false); }
    if (!isset($c['pin_hash']) || (string) $c['pin_hash'] === '') { $c['pin_hash'] = password_hash($klar, PASSWORD_DEFAULT); }
    $c['pin'] = '';
    return array($c, true);
}

/**
 * Die Spaetliste der Sicherung (E-E19, Bauart G25 N2): Schluessel, die eine
 * Sicherung von 0.9.29 nicht kennt. Fehlen sie beim Zurueckspielen, gilt der
 * GELTENDE Wert und der Hinweis sagt es - sonst wiese das Einspielen jeder
 * aelteren Sicherung ab ("fehlend").
 */
function sg_sicherung_spaet()
{
    return array('befehle_mqtt_ein', 'befehle_entsperren_ein', 'endpunkt_ein', 'plugin_absender',
                 'haus_status_ein', 'haus_bereiche', 'gaeste', 'notiz_ein');
}

/** Ein Gast-Eintrag in der gespeicherten Form - oder null. */
function sg_gast_normal($g)
{
    if (!is_array($g) || !isset($g['nummer'], $g['bis']) || !sg_ist_nummer($g['nummer'])) { return null; }
    $bef = array();
    if (isset($g['befehle']) && is_array($g['befehle'])) {
        foreach ($g['befehle'] as $w) {
            if (is_string($w) && $w !== '' && sg_ist_text($w, 60)) { $bef[] = sg_klein(trim(preg_replace('/\s+/', ' ', $w))); }
        }
    }
    $name = (isset($g['name']) && is_string($g['name']) && !preg_match('/[\x00-\x1F\x7F]/', $g['name'])) ? sg_kuerzen(trim($g['name']), 30) : '';
    return array('nummer' => $g['nummer'], 'name' => $name, 'bis' => is_int($g['bis']) ? $g['bis'] : (int) $g['bis'],
                 'befehle' => array_values(array_unique($bef)), 'angelegt' => isset($g['angelegt']) ? (int) $g['angelegt'] : 0);
}

/** Pruefung der Gaesteliste fuer sg_config_maengel(): '' = in Ordnung, sonst die Meldung. */
function sg_gaeste_maengel($g, array $erlaubt)
{
    if (!is_array($g) || ($g !== array() && array_keys($g) !== range(0, count($g) - 1)) || count($g) > SG_GAESTE_MAX) {
        return sprintf(sg_t('GAST.FEHLER_LISTE'), SG_GAESTE_MAX);
    }
    $vorgabe = array('nummer', 'name', 'bis', 'befehle', 'angelegt');
    $gesehen = array();
    foreach ($g as $i => $e) {
        $z = $i + 1;
        if (!is_array($e) || array_diff(array_keys($e), $vorgabe) || array_diff($vorgabe, array_keys($e))) {
            return sprintf(sg_t('GAST.FEHLER_ZEILE'), $z);
        }
        if (!sg_ist_nummer($e['nummer'])) { return sprintf(sg_t('GAST.FEHLER_NUMMER'), $z); }
        if (in_array($e['nummer'], $erlaubt, true)) { return sprintf(sg_t('GAST.FEHLER_SCHON_ERLAUBT'), $z); }
        if (isset($gesehen[$e['nummer']])) { return sprintf(sg_t('GAST.FEHLER_DOPPELT'), $z); }
        $gesehen[$e['nummer']] = 1;
        if (!is_string($e['name']) || sg_laenge($e['name']) > 30 || preg_match('/[\x00-\x1F\x7F]/', $e['name'])) {
            return sprintf(sg_t('GAST.FEHLER_NAME'), $z);
        }
        if (!is_int($e['bis']) || $e['bis'] <= 0 || !is_int($e['angelegt']) || $e['angelegt'] < 0) {
            return sprintf(sg_t('GAST.FEHLER_ZEIT'), $z);
        }
        if (!is_array($e['befehle']) || count($e['befehle']) > SG_BEFEHLE
            || ($e['befehle'] !== array() && array_keys($e['befehle']) !== range(0, count($e['befehle']) - 1))) {
            return sprintf(sg_t('GAST.FEHLER_BEFEHLE'), $z);
        }
        foreach ($e['befehle'] as $w) {
            if (!sg_ist_text($w, 60) || $w === '') { return sprintf(sg_t('GAST.FEHLER_BEFEHLE'), $z); }
        }
    }
    return '';
}

/** Der Gast zu einer Rufnummer (Eintrag) oder null - abgelaufen oder nicht. */
function sg_gast_finden(array $cfg, $nummer)
{
    $n = preg_replace('/[^0-9+]/', '', (string) $nummer);
    if (!isset($cfg['gaeste']) || !is_array($cfg['gaeste']) || $n === '') { return null; }
    foreach ($cfg['gaeste'] as $g) {
        if (is_array($g) && isset($g['nummer']) && hash_equals((string) $g['nummer'], $n)) { return $g; }
    }
    return null;
}

/** Gilt der Gast jetzt noch? Sekundengenau gegen time() (kein Takt noetig). */
function sg_gast_gueltig($g, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    return is_array($g) && isset($g['bis']) && $jetzt < (int) $g['bis'];
}

/** Die gerade gueltigen Gaeste. */
function sg_gaeste_gueltig(array $cfg)
{
    $aus = array();
    if (isset($cfg['gaeste']) && is_array($cfg['gaeste'])) {
        foreach ($cfg['gaeste'] as $g) { if (sg_gast_gueltig($g)) { $aus[] = $g; } }
    }
    return $aus;
}

/** Fuer Protokoll und Ereignisse: maskierte Nummer und Name eines Gastes. */
function sg_gast_wer($g)
{
    return sg_maske($g['nummer']) . ((string) $g['name'] !== '' ? ' (' . $g['name'] . ')' : '');
}

/** Merker je Gast (tmp/gaeste_gemeldet.json): Schluessel Nummer|bis|Art. */
function sg_gast_merker($schluessel, $setzen = false)
{
    $f = sg_tmpdir() . '/gaeste_gemeldet.json';
    $sperre = @fopen(sg_tmpdir() . '/gaeste.lock', 'c');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($d)) { $d = array(); }
    $da = isset($d[$schluessel]);
    if ($setzen && !$da) {
        $d[$schluessel] = time();
        if (count($d) > 200) { asort($d); $d = array_slice($d, -200, null, true); }
        sg_write_atomic($f, json_encode($d), 0600);
    }
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $da;
}

/** Abgelaufener Gast schreibt: Ereignis "abgewiesen: Gast abgelaufen" einmal je Gast, nicht je Nachricht. */
function sg_gast_abgewiesen_merken($g)
{
    $k = $g['nummer'] . '|' . (int) $g['bis'] . '|abgewiesen';
    if (!sg_gast_merker($k)) {
        sg_gast_merker($k, true);
        sg_ereignis_merken(sg_maske($g['nummer']), 'abgewiesen', 'Gast abgelaufen');
    }
}

/**
 * Vom Takt des Bots (einmal je Minute): "Gast ... abgelaufen" einmal je Gast
 * ins Ereignisprotokoll; abgelaufene Eintraege bleiben 7 Tage grau in der
 * Liste (zum Verlaengern), danach entfernt der Takt sie (Ereignis "Gast entfernt").
 */
function sg_gaeste_takt()
{
    $cfg = sg_config();
    if (empty($cfg['gaeste'])) { return; }
    $jetzt = time();
    $weg = false;
    foreach ($cfg['gaeste'] as $g) {
        if (sg_gast_gueltig($g, $jetzt)) { continue; }
        $k = $g['nummer'] . '|' . (int) $g['bis'] . '|abgelaufen';
        if (!sg_gast_merker($k)) {
            sg_gast_merker($k, true);
            sg_log('Gast ' . sg_gast_wer($g) . ' abgelaufen.');
            sg_ereignis_merken('Bot', 'Gast abgelaufen', sg_gast_wer($g));
        }
        if ($jetzt - (int) $g['bis'] > 7 * 86400) { $weg = true; }
    }
    if (!$weg) { return; }
    $entfernt = array();
    sg_config_aendern(function ($c) use ($jetzt, &$entfernt) {
        $neu = array();
        foreach ($c['gaeste'] as $g) {
            if (!sg_gast_gueltig($g, $jetzt) && $jetzt - (int) $g['bis'] > 7 * 86400) { $entfernt[] = $g; continue; }
            $neu[] = $g;
        }
        if (!$entfernt) { return null; }
        $c['gaeste'] = $neu;
        return $c;
    });
    foreach ($entfernt as $g) {
        sg_log('Gast ' . sg_gast_wer($g) . ' entfernt (seit 7 Tagen abgelaufen).');
        sg_ereignis_merken('Bot', 'Gast entfernt', sg_gast_wer($g));
    }
}

/**
 * Eingabe aus <input type="datetime-local"> (Ortszeit Europe/Berlin) ->
 * array(Unix-Zeit|null, Grund). Grund '' | 'FORM' | 'DATUM' | 'VERGANGEN' |
 * 'ZU_LANG' | 'VERSCHOBEN_AUF <Y-m-dTH:i>' (N12: die fehlende Fruehjahrsstunde
 * legt PHP auf die naechste gueltige; die doppelte Herbststunde auf die zweite).
 */
function sg_gast_bis_lesen($ein, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    if (!is_string($ein) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $ein)) { return array(null, 'FORM'); }
    $d = DateTime::createFromFormat('!Y-m-d\TH:i', $ein, new DateTimeZone('Europe/Berlin'));
    $e = DateTime::getLastErrors();
    if ($d === false || ($e && ($e['warning_count'] || $e['error_count']))) { return array(null, 'DATUM'); }
    $u = $d->getTimestamp();
    $rueck = $d->format('Y-m-d\TH:i');
    if ($u <= $jetzt) { return array(null, 'VERGANGEN'); }
    if ($u > $jetzt + SG_GAST_TAGE * 86400) { return array(null, 'ZU_LANG'); }
    return array($u, $rueck === $ein ? '' : 'VERSCHOBEN_AUF ' . $rueck);
}

/** Eine Unix-Zeit als Ortszeit mit (MEZ/MESZ). */
function sg_gast_zeit($u)
{
    $u = (int) $u;
    return date('d.m.Y H:i', $u) . ' (' . (date('I', $u) ? 'MESZ' : 'MEZ') . ')';
}

/** Wert fuer <input type="datetime-local"> (Ortszeit). */
function sg_gast_eingabe($u)
{
    return (int) $u > 0 ? date('Y-m-d\TH:i', (int) $u) : '';
}

/** Die Befehle, die ein Gast bekommen kann: aktiv, mit Wort, ohne Einschraenkung "Wer darf". */
function sg_gast_befehlsauswahl(array $cfg)
{
    $aus = array();
    foreach ($cfg['befehle'] as $b) {
        if (!empty($b['aktiv']) && $b['wort'] !== '' && (string) $b['absender'] === '') { $aus[] = $b['wort']; }
    }
    return array_values(array_unique($aus));
}

/* ==================================================================
 * Seit 0.9.30 (Gruppe 32) - Vorschlag 3: Haus-Werte im Chat
 * ("status strom", "status speicher" ...; E-E15, E-E16)
 * ==================================================================
 *
 * SignalBot ist hier nur ABNEHMER der Haus-Themen (Regeln/07 Nr. 15); unter
 * haus/ schreibt er nichts. Die Zaehler der Anbieter kommen einmal je Minute
 * in fester Sekunde (Gedaechtnis abnehmer-phasenfalle) - deshalb haelt ein
 * eigener Begleitprozess bin/sg_haus.php eine Dauerverbindung zum Broker und
 * schreibt tmp/haus_werte.json; der Bot liest beim Chat-Befehl nur diese
 * Datei (kein Netz im Bot). Ein Bereich zaehlt nur, wenn sein Zaehler binnen
 * 120 s LIVE gewechselt hat (ein zurueckbehaltener Zaehler beim Anmelden
 * zaehlt nie).
 */

/** Frist fuer einen live gesehenen Zaehler (Sekunden). */
define('SG_HAUS_FRIST', 120);

/** Die Haus-Woerter: Wort => array(Bereich unter haus/, Themen ohne "haus/<bereich>/"). */
function sg_haus_bereiche_alle()
{
    return array(
        'strom'    => array('strompreis', array('aktuell', 'vorschau', 'ab', 'raster', 'anbieter', 'zaehler')),
        'speicher' => array('speicher', array('soc', 'kwh', 'leistung', 'richtung', 'zaehler')),
        'kalender' => array('kalender', array('datum', 'heute/frei', 'heute/feiertag', 'morgen/frei', 'ferien_in', 'zaehler')),
        'abfuhr'   => array('abfuhr', array('heute_satz', 'morgen_satz', 'quittiert', 'zaehler')),
        'usv'      => array('usv', array('stufe', 'zaehler')),
        'abfahrt'  => array('abfahrt', array('in_min', 'zaehler')),
        'zuhause'  => array('anwesenheit', array('jemand', 'zaehler')),
    );
}

/** Werksauswahl der Bereiche: zuhause aus (Aufenthalt im Chat nur, wenn ausdruecklich gewollt). */
function sg_haus_bereiche_werk()
{
    return array('strom', 'speicher', 'kalender', 'abfuhr', 'usv', 'abfahrt');
}

/** Eingeschaltete Haus-Woerter (leer, wenn der Haken aus ist). */
function sg_haus_woerter(array $cfg)
{
    if (empty($cfg['haus_status_ein']) || !isset($cfg['haus_bereiche']) || !is_array($cfg['haus_bereiche'])) { return array(); }
    return array_values(array_intersect(array_keys(sg_haus_bereiche_alle()), $cfg['haus_bereiche']));
}

/** Ist $wort ein eingeschaltetes Haus-Wort ("haus" = Uebersicht)? */
function sg_haus_wort_an(array $cfg, $wort)
{
    $w = sg_haus_woerter($cfg);
    return $w && ($wort === 'haus' || in_array($wort, $w, true));
}

/** Die einzeln zu abonnierenden Themen der eingeschalteten Bereiche (nie haus/#, nie #). */
function sg_haus_themen(array $cfg)
{
    $alle = sg_haus_bereiche_alle();
    $aus = array();
    foreach (sg_haus_woerter($cfg) as $w) {
        foreach ($alle[$w][1] as $t) { $aus[] = 'haus/' . $alle[$w][0] . '/' . $t; }
    }
    return $aus;
}

/** Das Abbild der Dauerverbindung (tmp/haus_werte.json) - leer, wenn es keines gibt. */
function sg_haus_lesen()
{
    $f = sg_paths()['tmp'] . '/haus_werte.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($d) ? $d : array();
}

function sg_haus_w(array $h, $t)
{
    return (isset($h['werte'][$t]['w']) && is_scalar($h['werte'][$t]['w'])) ? (string) $h['werte'][$t]['w'] : null;
}

/** Zahl mit Komma im Antworttext (deutsch) bzw. Punkt (englisch) - nie in einem MQTT-Wert. */
function sg_haus_zahl($v, $n)
{
    return number_format((float) $v, $n, sg_sprache() === 'de' ? ',' : '.', '');
}

/** Der Antwortsatz zu einem Haus-Wort (Entwurf 7.3); null = kein Haus-Wort. */
function sg_haus_antwort($wort, array $cfg, $h = null, $jetzt = null)
{
    if ($h === null) { $h = sg_haus_lesen(); }
    if ($jetzt === null) { $jetzt = time(); }
    if ($wort === 'haus') {
        $z = array();
        foreach (sg_haus_woerter($cfg) as $w) { $z[] = sg_haus_antwort($w, $cfg, $h, $jetzt); }
        return $z ? implode("\n", $z) : null;
    }
    $alle = sg_haus_bereiche_alle();
    if (!isset($alle[$wort])) { return null; }
    $b = $alle[$wort][0];
    $live = isset($h['live'][$b]) ? (int) $h['live'][$b] : 0;
    if ($live <= 0) { return sprintf(sg_t('HAUS.SENDET_NICHT'), 'haus/' . $b); }
    if ($jetzt - $live > SG_HAUS_FRIST) {
        return sprintf(sg_t('HAUS.SCHWEIGT'), (int) round(($jetzt - $live) / 60), 'haus/' . $b);
    }
    switch ($b) {
        case 'strompreis':
            $a = sg_haus_w($h, 'haus/strompreis/aktuell');
            $v = sg_haus_w($h, 'haus/strompreis/vorschau');
            $ab = (int) sg_haus_w($h, 'haus/strompreis/ab');
            $r = (int) sg_haus_w($h, 'haus/strompreis/raster');
            if ($a === null || $a === '-' || $v === null || $v === '-' || $ab <= 0 || ($r !== 3600 && $r !== 900)) {
                return sg_t('HAUS.STROM_KEINE');
            }
            $l = array_map('floatval', explode(';', $v));
            $n = min(count($l), (int) (12 * 3600 / $r));
            $min = 0;
            for ($i = 1; $i < $n; $i++) { if ($l[$i] < $l[$min]) { $min = $i; } }
            return sprintf(sg_t('HAUS.STROM'), sg_haus_zahl($a, 1), (string) sg_haus_w($h, 'haus/strompreis/anbieter'),
                (int) round($n * $r / 3600), date('H:i', $ab + $min * $r), sg_haus_zahl($l[$min], 1));
        case 'speicher':
            $s = sg_haus_w($h, 'haus/speicher/soc');
            if ($s === null || (int) $s < 0) { return sg_t('HAUS.SPEICHER_KEINE'); }
            $k = sg_haus_w($h, 'haus/speicher/kwh');
            $p = (int) sg_haus_w($h, 'haus/speicher/leistung');
            $ri = (int) sg_haus_w($h, 'haus/speicher/richtung');
            $rt = $ri === 1 ? sprintf(sg_t('HAUS.LAEDT'), $p) : ($ri === 2 ? sprintf(sg_t('HAUS.ENTLAEDT'), $p) : sg_t('HAUS.RUHT'));
            return sprintf(sg_t('HAUS.SPEICHER'), (int) $s,
                ($k !== null && (float) $k >= 0) ? ' (' . sg_haus_zahl($k, 1) . ' kWh)' : '', $rt);
        case 'kalender':
            if (sg_haus_w($h, 'haus/kalender/datum') !== date('Ymd', $jetzt)) { return sg_t('HAUS.KALENDER_KEINE'); }
            $ja = function ($x) { return $x === '1' ? sg_t('HAUS.JA') : ($x === '0' ? sg_t('HAUS.NEIN') : '?'); };
            $f = (int) sg_haus_w($h, 'haus/kalender/ferien_in');
            return sprintf(sg_t('HAUS.KALENDER'), $ja(sg_haus_w($h, 'haus/kalender/heute/frei')),
                $ja(sg_haus_w($h, 'haus/kalender/heute/feiertag')), $ja(sg_haus_w($h, 'haus/kalender/morgen/frei')))
                . ($f < 0 ? '' : ($f === 0 ? ' ' . sg_t('HAUS.FERIEN_LAUFEN') : ' ' . sprintf(sg_t('HAUS.FERIEN_IN'), $f)));
        case 'abfuhr':
            $m = sg_haus_w($h, 'haus/abfuhr/morgen_satz');
            $hs = sg_haus_w($h, 'haus/abfuhr/heute_satz');
            $q = sg_haus_w($h, 'haus/abfuhr/quittiert');
            $teile = array();
            if ($hs !== null && $hs !== '-' && $hs !== '') { $teile[] = $hs; }
            if ($m !== null && $m !== '-' && $m !== '') { $teile[] = $m; }
            if (!$teile) { return sg_t('HAUS.ABFUHR_KEINE'); }
            return implode(' ', $teile) . ($q === '1' ? ' ' . sg_t('HAUS.DRAUSSEN') : ($q === '0' ? ' ' . sg_t('HAUS.NICHT_QUITTIERT') : ''));
        case 'usv':
            $s = sg_haus_w($h, 'haus/usv/stufe');
            $t = array('0' => 'HAUS.USV_0', '1' => 'HAUS.USV_1', '2' => 'HAUS.USV_2', '3' => 'HAUS.USV_3');
            return sprintf(sg_t('HAUS.USV'), isset($t[$s]) ? sg_t($t[$s]) : sg_t('HAUS.KEINE_AUSSAGE'));
        case 'abfahrt':
            $m = sg_haus_w($h, 'haus/abfahrt/in_min');
            if ($m === null || (int) $m < 0) { return sg_t('HAUS.ABFAHRT_KEINE'); }
            return (int) $m === 0 ? sg_t('HAUS.ABFAHRT_JETZT') : sprintf(sg_t('HAUS.ABFAHRT'), (int) $m);
        case 'anwesenheit':
            $j = sg_haus_w($h, 'haus/anwesenheit/jemand');
            return $j === '1' ? sg_t('HAUS.ZUHAUSE_JA') : ($j === '0' ? sg_t('HAUS.ZUHAUSE_NEIN') : sg_t('HAUS.ZUHAUSE_KEINE'));
    }
    return null;
}

/** Hat Loxone einen Zustand dieses Namens gemeldet? (N7: dann gewinnt der Zustand) */
function sg_zustand_da($wort)
{
    foreach (array_keys(sg_zustaende()) as $n) {
        if (sg_klein($n) === sg_klein($wort)) { return true; }
    }
    return false;
}

/** Laeuft der Begleitprozess? (Sperre tmp/haus.lock gehalten; legt nichts an) */
function sg_haus_laeuft()
{
    $f = sg_paths()['tmp'] . '/haus.lock';
    if (!is_file($f)) { return false; }
    $fh = @fopen($f, 'r');
    if (!$fh) { return false; }
    $laeuft = !flock($fh, LOCK_EX | LOCK_NB);
    if (!$laeuft) { flock($fh, LOCK_UN); }
    fclose($fh);
    return $laeuft;
}

/**
 * Lage des Haus-Lesers fuer Reiter Test und healthcheck: array(1|0|-1|null, Text).
 * null = aus; 1 = verbunden, alle Bereiche frisch; -1 = verbunden, ein Bereich
 * schweigt (Hinweis); 0 = Begleitprozess steht.
 */
function sg_haus_lage(array $cfg)
{
    $w = sg_haus_woerter($cfg);
    if (!$w) { return array(null, sg_t('HAUS.L_AUS')); }
    if (!sg_haus_laeuft()) { return array(0, sg_t('HAUS.L_STEHT')); }
    $h = sg_haus_lesen();
    $jetzt = time();
    $alle = sg_haus_bereiche_alle();
    $teile = array();
    $still = 0;
    foreach ($w as $x) {
        $b = $alle[$x][0];
        $live = isset($h['live'][$b]) ? (int) $h['live'][$b] : 0;
        if ($live > 0 && $jetzt - $live <= SG_HAUS_FRIST) {
            $teile[] = sprintf(sg_t('HAUS.L_FRISCH'), $x, $jetzt - $live);
        } else {
            $still++;
            $teile[] = $live > 0 ? sprintf(sg_t('HAUS.L_SCHWEIGT'), $x, (int) round(($jetzt - $live) / 60)) : sprintf(sg_t('HAUS.L_NIE'), $x);
        }
    }
    $seit = isset($h['verbunden']) ? (int) $h['verbunden'] : 0;
    $txt = sprintf(sg_t('HAUS.L_VERBUNDEN'), $seit > 0 ? date('d.m. H:i', $seit) : '-') . ' ' . implode(', ', $teile);
    return array($still ? -1 : 1, $txt);
}

/**
 * SUBSCRIBE (QoS 0) auf ein oder mehrere Themen, warten auf das SUBACK
 * derselben Kennung (G9, Entwurf 3.6). Rueckgabe true, wenn kein Thema mit
 * 0x80 abgelehnt wurde.
 */
function sg_broker_abonnieren($s, $themen, $kennung)
{
    $themen = is_array($themen) ? array_values($themen) : array((string) $themen);
    if (!$themen) { return false; }
    $rumpf = pack('n', (int) $kennung);
    foreach ($themen as $t) { $rumpf .= pack('n', strlen($t)) . $t . chr(0); }
    // 0x82: SUBSCRIBE (Pflichtbits 0010)
    if (!sg_broker_schreiben($s, chr(0x82) . sg_broker_laenge(strlen($rumpf)) . $rumpf)) { return false; }
    $ende = microtime(true) + SG_BROKER_FRIST;
    while (microtime(true) < $ende) {
        $pk = sg_broker_paket($s);
        if ($pk === null) { return false; }
        if (($pk[0] >> 4) === 9 && strlen($pk[1]) >= 3) {
            $k = unpack('n', substr($pk[1], 0, 2));
            if ((int) $k[1] !== (int) $kennung) { continue; }
            return strpos(substr($pk[1], 2), chr(0x80)) === false;
        }
    }
    return false;
}

/** Ein PUBLISH-Paket zerlegen: array(Thema, Nutzlast, retain) oder null. */
function sg_broker_publish_lesen(array $pk)
{
    if (($pk[0] >> 4) !== 3 || strlen($pk[1]) < 2) { return null; }
    $t = unpack('n', substr($pk[1], 0, 2));
    $n = (int) $t[1];
    if (strlen($pk[1]) < 2 + $n) { return null; }
    $thema = substr($pk[1], 2, $n);
    $qos = ($pk[0] >> 1) & 3;
    $rest = substr($pk[1], 2 + $n + ($qos ? 2 : 0));
    return array($thema, (string) $rest, ($pk[0] & 1) === 1);
}

/**
 * Der Begleitprozess bin/sg_haus.php (E-E16): Dauerverbindung zum Broker des
 * LoxBerry (Client-ID sghaus<pid>, saubere Sitzung, Keepalive 60 s mit
 * PINGREQ, kein Letzter Wille), abonniert NUR die Themen der eingeschalteten
 * Bereiche einzeln, Nutzlast hoechstens 4096 Byte, schreibt hoechstens alle 2 s
 * atomar tmp/haus_werte.json (0600). Ein Zaehler setzt "live" nur, wenn er
 * NICHT retained kam und sich geaendert hat. Verbindungsverlust: Rueckzug
 * 5/10/.../60 s, gebremste Protokollzeile. Endet, sobald der Haken aus ist
 * (Blick in die Konfiguration je 60 s) oder die Upgrade-Marke liegt.
 * $ende > 0 nur fuer Proben (Laufzeit begrenzen).
 */
function sg_haus_lauf($ende = 0)
{
    $p = sg_paths();
    $datei = sg_tmpdir() . '/haus_werte.json';
    $folge = 0;
    $gelaufen = false;   // die Zeile "beendet" nur, wenn er wirklich lief (der Wecker ruft jede Minute)
    while (true) {
        if ($ende && time() >= $ende) { return 0; }
        $cfg = sg_config();
        $themen = sg_haus_themen($cfg);
        if (!$themen) { if ($gelaufen) { sg_log('Haus-Leser beendet (Haken aus oder kein Bereich).'); } return 0; }
        if (sg_upgrade_marke()) { if ($gelaufen) { sg_log('Haus-Leser beendet (Aktualisierung laeuft).'); } return 0; }
        if (!$gelaufen) { sg_log('Haus-Leser gestartet (' . count($themen) . ' Themen).'); }
        $gelaufen = true;
        $v = sg_broker_verbinden('sghaus', 60);
        $ok = false;
        if ($v['s'] !== null) {
            stream_set_timeout($v['s'], SG_BROKER_FRIST);
            $ok = sg_broker_abonnieren($v['s'], $themen, 1);
            if (!$ok) { $v['grund'] = 'der Broker nimmt das Abo nicht an'; sg_broker_trennen($v['s']); }
        }
        if (!$ok) {
            $folge++;
            if ($folge === 1 || $folge % 10 === 0) {
                sg_log_gebremst('haus_zu', 'Haus-Leser: Broker ' . ($v['ziel'] !== '' ? $v['ziel'] : '(unbekannt)')
                    . ' nicht nutzbar - ' . $v['grund'] . ' (Versuch ' . $folge . ').', 600);
            }
            $pause = min(60, 5 * $folge);
            for ($i = 0; $i < $pause; $i++) {
                if ($ende && time() >= $ende) { return 0; }
                sleep(1);
            }
            continue;
        }
        if ($folge > 0) { sg_log('Haus-Leser: Broker wieder erreichbar.'); }
        $folge = 0;
        $s = $v['s'];
        stream_set_timeout($s, 1);
        $h = sg_haus_lesen();
        $werte = isset($h['werte']) && is_array($h['werte']) ? $h['werte'] : array();
        $live = isset($h['live']) && is_array($h['live']) ? $h['live'] : array();
        $verbunden = time();
        $letzt_paket = time();
        $letzt_ping = time();
        $letzt_blick = time();
        $letzt_schreiben = 0;
        $schmutzig = true;
        $alle = sg_haus_bereiche_alle();
        $bereich_von = array();
        foreach ($alle as $w => $d) { $bereich_von['haus/' . $d[0] . '/zaehler'] = $d[0]; }
        $gleich = $themen;
        while (true) {
            $jetzt = time();
            if ($ende && $jetzt >= $ende) { break; }
            $r = array($s); $wx = null; $ex = null;
            $n = @stream_select($r, $wx, $ex, 1);
            if ($n === false) { break; }
            if ($n > 0) {
                $pk = sg_broker_paket($s);
                if ($pk === null) {
                    $meta = stream_get_meta_data($s);
                    if (!empty($meta['eof']) || feof($s)) { sg_log_gebremst('haus_abbruch', 'Haus-Leser: Verbindung zum Broker abgebrochen - neu verbinden.', 600); break; }
                } else {
                    $letzt_paket = time();
                    $pub = sg_broker_publish_lesen($pk);
                    if ($pub !== null && in_array($pub[0], $themen, true) && strlen($pub[1]) <= 4096) {
                        list($t, $w, $retain) = $pub;
                        $vorher = isset($werte[$t]['w']) ? (string) $werte[$t]['w'] : null;
                        $werte[$t] = array('w' => $w, 't' => time());
                        if (isset($bereich_von[$t]) && !$retain && $vorher !== $w) {
                            $live[$bereich_von[$t]] = time();
                        }
                        $schmutzig = true;
                    }
                }
            }
            $jetzt = time();
            if ($jetzt - $letzt_ping >= 30) {
                if (!sg_broker_schreiben($s, chr(0xC0) . chr(0))) { break; }
                $letzt_ping = $jetzt;
            }
            if ($jetzt - $letzt_paket > 90) {
                sg_log_gebremst('haus_still', 'Haus-Leser: 90 s keine Antwort des Brokers - neu verbinden.', 600);
                break;
            }
            if ($schmutzig && $jetzt - $letzt_schreiben >= 2) {
                $js = json_encode(array('verbunden' => $verbunden, 'ts' => $jetzt, 'werte' => $werte, 'live' => $live),
                                  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($js !== false && sg_write_atomic($datei, $js, 0600)) { $schmutzig = false; $letzt_schreiben = $jetzt; }
            }
            if ($jetzt - $letzt_blick >= 60) {
                $letzt_blick = $jetzt;
                $cfg = sg_config();
                if (sg_upgrade_marke() || sg_haus_themen($cfg) !== $gleich) { break; }
            }
        }
        sg_broker_trennen($s);
        if ($ende && time() >= $ende) { return 0; }
        $cfg = sg_config();
        if (!sg_haus_themen($cfg)) { sg_log('Haus-Leser beendet (Haken aus oder kein Bereich).'); return 0; }
        sleep(2);
    }
}

/* ==================================================================
 * Seit 0.9.30 (Gruppe 32) - bin/healthcheck (Vorschlag 5, E-E9)
 * ================================================================== */

/**
 * Der Zustand fuer den LoxBerry-Healthcheck aus billigen Groessen: Bot-Sperre,
 * Alter des Lebenszeichens, Konfiguration, Konto, Kill-Schalter, offene
 * dringende Meldungen, Befehlsbruecke (nur mit Haken), Haus-Leser (nur mit
 * Haken), V2-Haken (nur lesen). KEIN Abruf bei signal-cli, kein Broker, nichts
 * angelegt (sg_config(false); sg_pruefungen() wird nicht gerufen - der Reiter
 * Test liest index.php mit und macht Netzproben). Stufen nach LoxBerry:
 * 3 Fehler, 4 Warnung, 6 Hinweis, 5 in Ordnung. Rueckgabe array(Stufe, Text).
 */
function sg_gesundheit()
{
    $lage = sg_config_lage();
    if ($lage !== 'ok') {
        return array(3, sprintf(sg_t('HEALTH.CONFIG'), sg_t('TEST.A_CONFIG_' . strtoupper($lage))));
    }
    $cfg = sg_config(false);
    if (!sg_bot_laeuft()) { return array(3, sg_t('HEALTH.BOT_TOT')); }
    $warn = array();
    $hinweis = array();
    $alter = sg_herz_alter();
    if ($alter < 0 || $alter > SG_HERZ_GRENZE) {
        $warn[] = $alter < 0 ? sg_t('HEALTH.HERZ_NIE') : sprintf(sg_t('HEALTH.HERZ_ALT'), $alter);
    }
    if ((string) $cfg['konto'] === '') { $warn[] = sg_t('HEALTH.KEIN_KONTO'); }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        list($bl, $bt) = sg_bruecke_lage();
        if ($bl === false) { $warn[] = sprintf(sg_t('HEALTH.BRUECKE'), strip_tags($bt)); }
    }
    $alt_dringend = 0;
    foreach (sg_warteschlange() as $e) {
        if (!empty($e['dringend']) && isset($e['ts']) && time() - (int) $e['ts'] > 900) { $alt_dringend++; }
    }
    if ($alt_dringend) { $warn[] = sprintf(sg_t('HEALTH.DRINGEND'), $alt_dringend); }
    if (!empty($cfg['gesperrt'])) { $hinweis[] = sg_t('HEALTH.GESPERRT'); }
    if (!empty($cfg['mqtt_ein'])) {
        $v2 = sg_mqtt_v2_haken_lage($cfg);
        if ($v2[0] === 0 && $v2[4]) { $hinweis[] = sprintf(sg_t('HEALTH.V2_HAKEN'), implode(', ', $v2[4])); }
    }
    list($hl, $ht) = sg_haus_lage($cfg);
    if ($hl === 0 || $hl === -1) { $hinweis[] = sprintf(sg_t('HEALTH.HAUS'), strip_tags($ht)); }
    if ($warn) { return array(4, implode(' ', $warn) . ($hinweis ? ' ' . implode(' ', $hinweis) : '')); }
    if ($hinweis) { return array(6, implode(' ', $hinweis)); }
    $l = sg_letzter_befehl();
    return array(5, sprintf(sg_t('HEALTH.OK'), count($cfg['erlaubt']),
        count(array_filter($cfg['befehle'], function ($b) { return !empty($b['aktiv']) && $b['wort'] !== ''; })),
        $l < 0 ? sg_t('HEALTH.NOCH_KEINE') : sprintf(sg_t('HEALTH.VOR_MIN'), intdiv($l, 60))));
}

/* ==================================================================
 * Notiz an mich als Befehlsweg (Weg A, Nachtrag 27.10.2026)
 * ==================================================================
 *
 * Gemessen am Geraet (Probe 98d6c720): Laeuft der Bot als VERKNUEPFTES
 * Geraet unter der eigenen Nummer des Hausherrn, kommt eine Nachricht im
 * Chat "Notiz an mich" nicht als dataMessage, sondern als Abgleich:
 * envelope.syncMessage.sentMessage, Quelle = eigenes Konto (Geraet 1),
 * Ziel (destinationNumber/destination) = eigenes Konto. Bis zum Nachbau
 * wurde das uebergangen - der Ein-Nummer-Betrieb hatte keinen Chat-Weg.
 *
 * Ausgewertet wird NUR, wenn alles zutrifft:
 *   - Haken notiz_ein (ab Werk an) und die eigene Kontonummer steht unter
 *     den erlaubten Absendern (sonst wirkt nichts - wie bisher);
 *   - Ziel ist das eigene Konto (Nummer gleich oder destinationUuid gleich
 *     sourceUuid); KEINE Gruppe; ein Abgleich an Dritte wird nie ausgewertet;
 *   - es ist keine eigene Sendung des Bots (Zeitstempel aus sg_senden,
 *     sg_eigene_sendung_merken) - Schleifenschutz.
 * Dann gilt der Text als Nachricht des Kontoinhabers: gleiche Befehlstabelle,
 * PIN-Stufen, Bremse; das Ereignisprotokoll traegt "(Notiz an mich)".
 */
define('SG_NOTIZ_KENNUNG', 'Notiz an mich');
define('SG_EIGENE_HALTEN', 86400);   // so lange (s) bleibt ein eigener Zeitstempel gemerkt
define('SG_EIGENE_MAX', 500);        // hoechstens so viele

/** Ein Signal-Zeitstempel (ms) als Ziffernfolge - auch auf 32-Bit-PHP, wo er als float ankommt. '' = keiner. */
function sg_ts_text($v)
{
    if (is_int($v)) { $t = (string) $v; }
    elseif (is_float($v)) { $t = sprintf('%.0f', $v); }
    elseif (is_string($v)) { $t = $v; }
    else { return ''; }
    return (preg_match('/^[1-9][0-9]{0,19}$/', $t) === 1) ? $t : '';
}

/** Der Zeitstempel einer eigenen Sendung (Ergebnis von send) - fuer den Schleifenschutz. */
function sg_eigene_sendung_merken($ergebnis)
{
    $ts = (is_array($ergebnis) && isset($ergebnis['timestamp'])) ? sg_ts_text($ergebnis['timestamp']) : '';
    if ($ts === '') { return false; }
    $f = sg_tmpdir() . '/eigene_sendungen.json';
    $sperre = @fopen(sg_tmpdir() . '/eigene_sendungen.lock', 'ce');
    if ($sperre !== false) { flock($sperre, LOCK_EX); }
    $liste = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($liste)) { $liste = array(); }
    $jetzt = time();
    foreach ($liste as $k => $bei) {
        if (!is_int($bei) || $jetzt - $bei > SG_EIGENE_HALTEN) { unset($liste[$k]); }
    }
    $liste[$ts] = $jetzt;
    if (count($liste) > SG_EIGENE_MAX) { $liste = array_slice($liste, -SG_EIGENE_MAX, null, true); }
    $ok = sg_write_atomic($f, json_encode((object) $liste), 0600);
    if ($sperre !== false) { flock($sperre, LOCK_UN); fclose($sperre); }
    return $ok;
}

/** Hat der Bot selbst unter diesem Zeitstempel gesendet? */
function sg_eigene_sendung($ts)
{
    $ts = sg_ts_text($ts);
    if ($ts === '') { return false; }
    $f = sg_tmpdir() . '/eigene_sendungen.json';
    if (!is_file($f)) { return false; }
    $liste = json_decode((string) @file_get_contents($f), true);
    return is_array($liste) && isset($liste[$ts]);
}

/**
 * Einen Abgleich (envelope mit syncMessage) pruefen. Rueckgabe:
 * array('ok' => bool, 'grund' => Kurzwort, 'von' => eigenes Konto, 'text' => Text, 'geraet' => sourceDevice).
 * Reine Entscheidung ohne Senden - der Bot ruft danach sg_verarbeite().
 */
function sg_notiz_pruefen($env)
{
    $cfg = sg_config();
    $nein = function ($g) { return array('ok' => false, 'grund' => $g, 'von' => '', 'text' => '', 'geraet' => 0); };
    $sm = (is_array($env) && isset($env['syncMessage']['sentMessage']) && is_array($env['syncMessage']['sentMessage']))
        ? $env['syncMessage']['sentMessage'] : null;
    if ($sm === null) { return $nein('kein_sent'); }
    if (isset($sm['groupInfo']) && $sm['groupInfo'] !== null) { return $nein('gruppe'); }
    if (!isset($sm['message']) || !is_string($sm['message']) || trim($sm['message']) === '') { return $nein('kein_text'); }
    $konto = is_string($cfg['konto']) ? $cfg['konto'] : '';
    if ($konto === '') { return $nein('kein_konto'); }
    $s = function ($a, $k) { return (isset($a[$k]) && is_string($a[$k])) ? $a[$k] : ''; };
    $ziel = $s($sm, 'destinationNumber') !== '' ? $s($sm, 'destinationNumber') : $s($sm, 'destination');
    $ziel_uuid = $s($sm, 'destinationUuid');
    $quelle = $s($env, 'sourceNumber') !== '' ? $s($env, 'sourceNumber') : $s($env, 'source');
    $quelle_uuid = $s($env, 'sourceUuid');
    $selbst = ($ziel !== '' && hash_equals($konto, $ziel))
           || ($ziel_uuid !== '' && $quelle_uuid !== '' && hash_equals($quelle_uuid, $ziel_uuid)
               && ($ziel === '' || $ziel === $ziel_uuid));
    if (!$selbst) { return $nein('dritter'); }
    if ($quelle !== '' && !hash_equals($konto, $quelle) && $quelle !== $quelle_uuid) { return $nein('fremde_quelle'); }
    $ts = isset($sm['timestamp']) ? sg_ts_text($sm['timestamp']) : '';
    if ($ts === '' && isset($env['timestamp'])) { $ts = sg_ts_text($env['timestamp']); }
    if ($ts !== '' && sg_eigene_sendung($ts)) { return $nein('eigene'); }
    if (empty($cfg['notiz_ein'])) { return $nein('aus'); }
    if (!sg_erlaubt($konto)) { return $nein('nicht_erlaubt'); }
    return array('ok' => true, 'grund' => 'notiz', 'von' => $konto, 'text' => (string) $sm['message'],
                 'geraet' => isset($env['sourceDevice']) ? (int) $env['sourceDevice'] : 0);
}

/** Eine ausgewertete Notiz merken (Zeit, Geraet) - fuer den Reiter Test und die Anzeige des Wegs. */
function sg_notiz_merken($geraet)
{
    return sg_write_atomic(sg_datadir() . '/notiz.json',
        json_encode(array('ts' => time(), 'geraet' => (int) $geraet)), 0600);
}

/** array('ts' => letzte ausgewertete Notiz oder 0, 'geraet' => sourceDevice). */
function sg_notiz_stand()
{
    $f = sg_datadir() . '/notiz.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d) || !isset($d['ts'])) { return array('ts' => 0, 'geraet' => 0); }
    return array('ts' => (int) $d['ts'], 'geraet' => isset($d['geraet']) ? (int) $d['geraet'] : 0);
}

/**
 * Welcher Weg ist aktiv? 'A' = eigene Nummer, Bot als verknuepftes Geraet ("Notiz an mich");
 * 'B' = eigene Nummer fuer den Bot (registriert, Hauptgeraet); 'keins' = kein Konto; 'unbekannt'.
 * Gemessen ueber listDevices (signal-cli), sonst aus einer ausgewerteten Notiz (sie kam von einem
 * anderen Geraet desselben Kontos - also ist der Bot verknuepft). Nur gelesen, nichts geaendert.
 */
function sg_konto_weg($cfg, $lebt)
{
    $konto = is_string($cfg['konto']) ? $cfg['konto'] : '';
    if ($konto === '') { return 'keins'; }
    if ($lebt) {
        $a = sg_rpc('listDevices', array('account' => $konto), 8);
        if ($a['ok'] && is_array($a['result'])) {
            $ids = array();
            $botname = false;
            foreach ($a['result'] as $d) {
                if (!is_array($d) || !isset($d['id'])) { continue; }
                $id = (int) $d['id'];
                if (!empty($d['isThisDevice']) || !empty($d['thisDevice'])) { return $id === 1 ? 'B' : 'A'; }
                $ids[] = $id;
                if ($id > 1 && isset($d['name']) && $d['name'] === 'LoxBerry Signal Bot') { $botname = true; }
            }
            if ($botname) { return 'A'; }
            if ($ids === array(1)) { return 'B'; }
        }
    }
    $n = sg_notiz_stand();
    if ($n['ts'] > 0) { return 'A'; }
    return 'unbekannt';
}
