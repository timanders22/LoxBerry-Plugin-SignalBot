<?php
/**
 * Signal Bot fuer LoxBerry - Bedienoberflaeche
 *
 * NUR Oberflaeche. Der Empfang laeuft in bin/sg_bot.php, der Endpunkt fuer
 * Loxone in webfrontend/html/index.php. Drei Aufgaben, drei Dateien.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/sg_lib.php';
require_once __DIR__ . '/sg_test.php';

$p = sg_paths();

/* Den LoxBerry-Kern laden - und zwar so, dass eine unbrauchbare Wurzel nicht
 * die ganze Oberflaeche mitnimmt.
 *
 * Bis 0.9.12 stand hier eine Bedingung, die NUR die erste der beiden Dateien
 * prueft, und danach zwei harte require_once. Auf einem Geraet, auf dem
 * $p['home'] leer blieb, endete das mit
 *     Failed opening required '/libs/phplib/loxberry_web.php'
 * und damit HTTP 500 fuer die gesamte Seite - obwohl der Kern fuer dieses
 * Plugin gar nicht zwingend ist: alle Aufrufe stehen ohnehin hinter
 * class_exists('LBWeb'). Ein optionaler Bestandteil darf nicht toedlich sein.
 *
 * Jede Datei wird jetzt EINZELN geprueft. Taugt die Wurzel nicht, greift der
 * include_path, den LoxBerry selbst setzt (.:<home>/libs/phplib) - dafuer
 * braucht es keinen festen Pfad nach /opt/loxberry. Und geladen wird mit
 * include_once: fehlt der Kern wirklich, laeuft die Seite ohne Kopf- und
 * Fusszeile weiter, statt gar nicht zu laufen. */
$sg_home = $p['home'];
foreach (array('loxberry_system.php', 'loxberry_web.php') as $sg_kern) {
    $sg_voll = $sg_home . '/libs/phplib/' . $sg_kern;
    if ($sg_home !== '' && is_file($sg_voll)) {
        require_once $sg_voll;
    } else {
        @include_once $sg_kern;
    }
}
/* $p NEU HOLEN - und $sg_home vorher sichern.
 *
 * DAS war der Fehler, an dem die Oberflaeche mit HTTP 500 starb, und zwar von
 * Anfang an: eine eingebundene Datei laeuft im Variablenraum des Aufrufers,
 * und loxberry_system.php setzt in Zeile 18 seines Dateikoerpers
 *
 *     $p = explode("/", substr($scriptPath, strlen(LBHOMEDIR)));
 *
 * Unser $p aus sg_paths() war damit nach der ersten der beiden Zeilen ein
 * Feld von Pfadstuecken. $p['home'] war leer, und die zweite Zeile suchte
 * '/libs/phplib/loxberry_web.php' - im Wurzelverzeichnis. Genau diese
 * Meldung stand am 17.08.2026 auf dem Geraet.
 *
 * Der Kern belegt im Aufrufer 31 Namen, darunter die kurzen $p, $cfg,
 * $format und $message. Nach dem Laden gilt deshalb: nichts von vorher
 * weiterbenutzen, sondern neu holen. Alles Eigene traegt ohnehin das
 * Praefix sg_. */
$p = sg_paths();

/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-seite mit
   gleicher id) und diese Positivliste. Fehlt ein Name hier, springt die Seite
   nach jedem Absenden zurueck auf Einstellungen. */
$sg_muster = '/^tab-(settings|befehle|mqtt|loxone|test|log)$/';
$sg_tab = preg_match($sg_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($sg_muster, 'tab-' . $_GET['form'])) {
    $sg_tab = 'tab-' . $_GET['form'];
}

$sg_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$sg_meldungen = array();
$sg_fehler = array();
$sg_qr = '';

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon geschrieben -
 * "Cannot modify header information", und "Einstellungen sichern" lieferte
 * eine Seite mit angehaengtem JSON statt einer Datei. Am PHP-CLI ist das
 * unsichtbar: header() ist dort wirkungslos.
 *
 * JEDER POST-HANDLER ENDET MIT EINER UMLEITUNG (U2, Regeln/04, seit dem
 * Durchgang 01.10.2026). Bis 0.9.25 wurde die Seite unmittelbar nach dem POST
 * gerendert: F5 wiederholte die Handlung - "Token neu erzeugen" wuerfelte ein
 * zweites Token, und die eben abgeschriebene Adresse war schon wieder
 * ungueltig (gemessen: drei Tokens bei zweimal Absenden). Das Ergebnis reist
 * als Einmalmeldung (sg_flash_*). Ausgenommen sind nur die Downloads
 * (Vorlagen, Sicherung): sie liefern ihre Datei unmittelbar.
 *
 * BEI EINER BEANSTANDUNG WIRD NICHTS GESPEICHERT (U3, Nr. 16) - auch nicht
 * die uebrigen, richtigen Felder. Die Eingaben kommen zurueck ins Formular
 * (U5, X-2), das Feld ist markiert. Bis 0.9.25 wurde "melden, nicht
 * blockieren" gespeichert: ein Tippfehler in "Wer darf" machte aus einem
 * Befehl, den nur eine Nummer ausloesen durfte, einen fuer alle Erlaubten,
 * und eine ungueltige 2. Freigabe wurde geleert - der Befehl blieb scharf
 * (gemessen).
 *
 * Jede Pruefung eines Wertes ist sg_config_maengel() - dieselbe fuer die
 * Formulare, das Zurueckspielen und die Warnung beim Sichern. Geschrieben
 * wird nur ueber sg_config_aendern() (Sperre, frisch gelesen; C9).
 * ================================================================== */

/** Ende jedes POST-Handlers: Ergebnis in die Einmalmeldung, 303 auf die Seite. */
function sg_umleiten($tab, $meldungen, $fehler, $zusatz = array())
{
    $inhalt = array('tab' => $tab, 'meldungen' => array_values($meldungen),
                    'fehler' => array_values(array_unique($fehler)));
    foreach ($zusatz as $k => $v) { $inhalt[$k] = $v; }
    sg_flash_schreiben($inhalt);
    header('Location: index.php?form=' . rawurlencode(preg_replace('/^tab-/', '', $tab)), true, 303);
    exit;
}

/** Ein Formularwert als Zeichenkette - eine Liste (feld[]=x) zaehlt als leer. */
function sg_post_text($k)
{
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? $_POST[$k] : '';
}

/** Ein Tabellenfeld b_xxx[i] als Zeichenkette ('' wenn es fehlt oder keine ist). */
function sg_post_zelle($feld, $i)
{
    if (!isset($_POST[$feld]) || !is_array($_POST[$feld]) || !isset($_POST[$feld][$i])) { return ''; }
    return is_string($_POST[$feld][$i]) ? $_POST[$feld][$i] : '';
}

/** Feldschluessel aus sg_config_maengel() -> Name des Formularfelds. */
function sg_formfeld($k)
{
    if (preg_match('/^befehle\.([0-9]+)\.([a-z_]+)$/', $k, $m)) { return 'b_' . $m[2] . '[' . $m[1] . ']'; }
    if ($k === 'pin_hash') { return 'pin'; }
    return $k;
}

/**
 * Die Eingaben eines beanstandeten Formulars fuer X-2: nur die genannten
 * Felder, nur Zeichenketten (oder Felder von Zeichenketten), gueltiges UTF-8,
 * hoechstens 2100 Byte je Wert. PIN und Konto reisen nie mit.
 */
function sg_eingaben_sammeln($formular, $felder, $falsch)
{
    $werte = array();
    foreach ($felder as $f) {
        if (!isset($_POST[$f])) { continue; }
        $w = $_POST[$f];
        if (is_string($w)) {
            if (strlen($w) <= 2100 && preg_match('//u', $w)) { $werte[$f] = $w; }
        } elseif (is_array($w)) {
            $liste = array();
            foreach ($w as $i => $x) {
                if (is_string($x) && strlen($x) <= 2100 && preg_match('//u', $x)) { $liste[(string) (int) $i] = $x; }
            }
            $werte[$f] = $liste;
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => array_values(array_unique($falsch)));
}

/* ---------------- Der Wachposten - EIN Posten, vor allen Handlern ----------------
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt; auch die
 * Abweisung endet mit einer Umleitung. */
if ($sg_post) {
    $sg_wache = sg_wachposten();
    if ($sg_wache !== '') {
        $_POST = array();
        sg_umleiten($sg_tab, array(), array($sg_wache));
    }
}

/* ================= Downloads (vor jeder Ausgabe, ohne Umleitung) ================= */
if ($sg_post && (isset($_POST['vorlage']) || isset($_POST['vorlage_out']))) {
    list($sg_vname, $sg_vinhalt) = isset($_POST['vorlage_out']) ? sg_vorlage_out() : sg_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $sg_vname . '"');
    echo $sg_vinhalt;
    exit;
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die volle Konfiguration - samt Aktionstoken - mit lesbarem
 * _-Kopf (U6). Bestuende sie das Zurueckspielen nicht, sagt es der Kopf
 * (_warnung, X-3) und die gelbe Warnung am Knopf; geliefert wird trotzdem. */
if ($sg_post && isset($_POST['sg_sichern'])) {
    $sg_js = sg_sicherung_json(sg_config());
    if ($sg_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="signalbot_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $sg_js;
        exit;
    }
    sg_umleiten('tab-settings', array(), array(sg_t('EINST.SICH_SCHREIBFEHLER')));
}

/* ================= Protokoll leeren ================= */
if ($sg_post && isset($_POST['clearlog'])) {
    $sg_lf = sg_paths()['log'];
    @mkdir(dirname($sg_lf), 0775, true);
    if (sg_log_setzen($sg_lf, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n")) {
        sg_umleiten('tab-log', array(sg_t('LOG.M_LEER')), array());
    }
    sg_umleiten('tab-log', array(), array(sg_t('LOG.M_NICHT_LEER')));
}

/* ================= Verknuepfung ================= */
if ($sg_post && isset($_POST['link_start'])) {
    $a = sg_rpc('startLink', array(), 30);
    if ($a['ok'] && isset($a['result']['deviceLinkUri'])) {
        sg_write_atomic(sg_tmpdir() . '/linkuri.txt', (string) $a['result']['deviceLinkUri'], 0600);
        sg_umleiten('tab-settings', array(sg_t('EINST.LINK_GESTARTET')), array());
    }
    sg_umleiten('tab-settings', array(), array(sprintf(sg_t('EINST.LINK_FEHLER'), sg_e($a['fehler']))));
}
if ($sg_post && isset($_POST['link_fertig'])) {
    $uri = @file_get_contents(sg_tmpdir() . '/linkuri.txt');
    if (!$uri) {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.LINK_KEINE_URI')));
    }
    // finishLink wartet auf das Handy - deshalb die lange Frist.
    $a = sg_rpc('finishLink', array('deviceLinkUri' => (string) $uri,
                                    'deviceName' => 'LoxBerry Signal Bot'), 150);
    if (!$a['ok']) {
        sg_umleiten('tab-settings', array(), array(sprintf(sg_t('EINST.LINK_FEHLER'), sg_e($a['fehler']))));
    }
    @unlink(sg_tmpdir() . '/linkuri.txt');
    // Das neue Konto gleich uebernehmen, wenn es genau eines gibt.
    $k = sg_konten();
    if (count($k) === 1 && sg_ist_nummer($k[0])) {
        sg_config_aendern(function ($c) use ($k) { $c['konto'] = $k[0]; return $c; });
    }
    sg_umleiten('tab-settings', array(sg_t('EINST.LINK_FERTIG')), array());
}

/* ================= Verknuepfung loesen =================
 *
 * WAS HIER BEWUSST NICHT PASSIERT: unregister (schaltet nur dieses Geraet ab,
 * mit --delete-account das ganze Konto) und removeDevice (nur vom Handy aus).
 * deleteLocalAccountData loescht die oertlichen Schluessel - genau das ist
 * hier richtig; die Verknuepfung selbst wird am Handy geloest. */
if ($sg_post && isset($_POST['link_loesen'])) {
    $sg_lcfg = sg_config();
    if (empty($_POST['loesen_ok'])) {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.M_LOESEN_UNBESTAETIGT')));
    }
    if ((string) $sg_lcfg['konto'] === '') {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.M_LOESEN_KEIN_KONTO')));
    }
    $sg_a = sg_rpc('deleteLocalAccountData',
                   array('account' => (string) $sg_lcfg['konto'], 'ignoreRegistered' => true), 60);
    if (!$sg_a['ok']) {
        sg_umleiten('tab-settings', array(), array(sprintf(sg_t('EINST.M_LOESEN_FEHLER'), sg_e($sg_a['fehler']))));
    }
    sg_log('Verknuepfung geloest: oertliche Schluessel fuer ' . sg_maske($sg_lcfg['konto']) . ' geloescht.');
    sg_ereignis_merken('Oberflaeche', 'Verknuepfung geloest', sg_maske($sg_lcfg['konto']));
    sg_config_aendern(function ($c) { $c['konto'] = ''; return $c; });
    @unlink(sg_tmpdir() . '/linkuri.txt');
    sg_umleiten('tab-settings', array(sg_t('EINST.M_LOESEN_OK')), array());
}

/* ================= Kill-Schalter ================= */
if ($sg_post && isset($_POST['sperre'])) {
    $sg_soll = sg_post_text('sperre');
    if ($sg_soll !== 'ein' && $sg_soll !== 'aus') {
        sg_umleiten('tab-settings', array(), array(sg_t('TEST.M_UNBEKANNT')));
    }
    $sg_neu = $sg_soll === 'ein' ? 1 : 0;
    list($sg_ok, ) = sg_config_aendern(function ($c) use ($sg_neu) { $c['gesperrt'] = $sg_neu; return $c; });
    if (!$sg_ok) {
        sg_umleiten('tab-settings', array(), array(sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']))));
    }
    sg_log($sg_neu ? 'Bot in der Oberflaeche gesperrt.' : 'Bot in der Oberflaeche entsperrt.');
    sg_ereignis_merken('Oberflaeche', $sg_neu ? 'gesperrt' : 'entsperrt', '');
    sg_umleiten('tab-settings', array($sg_neu ? sg_t('EINST.M_GESPERRT') : sg_t('EINST.M_ENTSPERRT')), array());
}

/* ================= Ereignisprotokoll leeren ================= */
if ($sg_post && isset($_POST['clearaudit'])) {
    if (sg_write_atomic(sg_datadir() . '/ereignisse.json', json_encode(array()), 0600)) {
        sg_umleiten('tab-log', array(sg_t('LOG.M_AUDIT_LEER')), array());
    }
    sg_umleiten('tab-log', array(), array(sg_t('LOG.M_AUDIT_NICHT_LEER')));
}

/* ================= Test-Aktionen ================= */
if ($sg_post && isset($_POST['testaktion'])) {
    $sg_zusatz = sg_post_text('trockentext');
    list($sg_ok, $sg_text) = sg_test_aktion(sg_post_text('testaktion'), $sg_zusatz);
    $sg_extra = array();
    if (sg_post_text('testaktion') === 'trocken' && strlen($sg_zusatz) <= 500 && preg_match('//u', $sg_zusatz)) {
        $sg_extra['trockentext'] = $sg_zusatz;
    }
    sg_umleiten('tab-test', $sg_ok ? array($sg_text) : array(), $sg_ok ? array() : array($sg_text), $sg_extra);
}

/* ================= Einstellungen speichern ================= */
if ($sg_post && isset($_POST['speichern'])) {
    $sg_formfelder = array('rpc_url', 'erlaubt', 'stille', 'bremse', 'pin_versuche', 'pin_sperre',
                           'zustand_ein', 'audit', 'herzschlag', 'gruppe', 'nacht_von', 'nacht_bis',
                           'quittung_takt', 'quittung_max', 'pin_loeschen');
    $sg_eigene = array('rpc_url', 'konto', 'erlaubt', 'pin', 'bremse', 'pin_versuche', 'pin_sperre',
                       'gruppe', 'nacht_von', 'nacht_bis', 'quittung_takt', 'quittung_max',
                       'stille', 'zustand_ein', 'audit', 'herzschlag');
    $sg_falsch = array();   // Formularfeld => Meldung
    $sg_werte = array();    // Schluessel => Wert

    /* Die Adresse ist Rechner und Port - KEIN Pfad. sg_rpc() haengt
     * '/api/v1/rpc' selbst an. Bis 0.9.25 wurde ein mitgeschriebener Pfad
     * still abgeschnitten (U4, Nr. 19); jetzt beanstandet. */
    $sg_werte['rpc_url'] = trim(sg_post_text('rpc_url'));

    /* Das Konto steht nicht mehr im Feld (U17): leer = unveraendert. */
    $sg_k = trim(sg_post_text('konto'));
    if ($sg_k !== '') {
        if (!sg_ist_nummer($sg_k)) { $sg_falsch['konto'] = sg_t('EINST.FEHLER_KONTO'); }
        else { $sg_werte['konto'] = $sg_k; }
    }

    /* Erlaubte Absender: eine je Zeile (auch Komma/Semikolon). Jede Zeile wird
     * geprueft, nicht gesaeubert - bis 0.9.25 wurde aus "+49 170-123x4567"
     * still "+491701234567" (U4). Doppelte werden beanstandet statt verworfen. */
    $sg_liste = array();
    $sg_schlecht = array();
    foreach (preg_split('/[\r\n,;]+/', sg_post_text('erlaubt')) as $sg_z) {
        $sg_z = trim($sg_z);
        if ($sg_z === '') { continue; }
        if (sg_ist_nummer($sg_z)) { $sg_liste[] = $sg_z; } else { $sg_schlecht[] = $sg_z; }
    }
    if ($sg_schlecht) {
        $sg_falsch['erlaubt'] = sprintf(sg_t('EINST.FEHLER_NUMMER'), sg_e(implode(', ', $sg_schlecht)));
    } elseif (count(array_unique($sg_liste)) !== count($sg_liste)) {
        $sg_falsch['erlaubt'] = sg_t('EINST.FEHLER_DOPPELT_NUMMER');
    } else {
        $sg_werte['erlaubt'] = $sg_liste;
    }

    /* PIN: leeres Feld laesst die gespeicherte unberuehrt (Geheimnisfeld).
     * Fremdzeichen werden beanstandet, nicht entfernt - bis 0.9.25 wurde aus
     * "12-34" still der Hash von "1234" (U4). */
    $sg_pin = sg_post_text('pin');
    if ($sg_pin !== '' && !preg_match('/^[0-9A-Za-z]{4,64}$/', $sg_pin)) {
        $sg_falsch['pin'] = sg_t('EINST.FEHLER_PIN_ZEICHEN');
    }

    foreach (array('bremse', 'pin_versuche', 'pin_sperre', 'quittung_takt', 'quittung_max') as $sg_zk) {
        $sg_v = sg_ganz_lesen(sg_post_text($sg_zk));
        if ($sg_v === null) {
            $sg_falsch[$sg_zk] = sprintf(sg_t('EINST.FEHLER_ZAHL'), sg_e(sg_feldname($sg_zk)));
        } else {
            $sg_werte[$sg_zk] = $sg_v;
        }
    }
    $sg_werte['gruppe'] = trim(sg_post_text('gruppe'));
    $sg_werte['nacht_von'] = trim(sg_post_text('nacht_von'));
    $sg_werte['nacht_bis'] = trim(sg_post_text('nacht_bis'));
    /* mqtt_ein und mqtt_topic wohnen im Reiter MQTT und haben dort ein eigenes
     * Formular; sie werden hier nicht angefasst. */
    foreach (array('stille', 'zustand_ein', 'audit', 'herzschlag') as $sg_hk) {
        $sg_werte[$sg_hk] = isset($_POST[$sg_hk]) ? 1 : 0;
    }

    list($sg_ok, ) = sg_config_aendern(function ($alt) use ($sg_werte, $sg_pin, $sg_eigene, &$sg_falsch) {
        $neu = $alt;
        foreach ($sg_werte as $k => $v) { $neu[$k] = $v; }
        if (!isset($sg_falsch['pin'])) {
            if (!empty($_POST['pin_loeschen'])) {
                $neu['pin'] = ''; $neu['pin_hash'] = '';
            } elseif ($sg_pin !== '') {
                /* Die PIN wird als Hash abgelegt, nicht im Klartext. */
                $neu['pin_hash'] = password_hash($sg_pin, PASSWORD_DEFAULT);
                $neu['pin'] = '';
            } elseif ((string) $neu['pin'] !== '' && (string) $neu['pin_hash'] === '') {
                // Altbestand: eine noch im Klartext gespeicherte PIN in einen Hash umschreiben.
                $neu['pin_hash'] = password_hash((string) $neu['pin'], PASSWORD_DEFAULT);
                $neu['pin'] = '';
            }
        }
        /* Beanstandet wird, was an DIESEM Formular haengt, und was die Aenderung
         * neu verursacht (etwa: eine Nummer aus der Weissliste nehmen, die eine
         * 2. Freigabe ist; die PIN loeschen, waehrend Befehle sie verlangen).
         * Alte Maengel anderer Reiter halten das Speichern nicht auf - sie stehen
         * im Reiter Test und als Warnung am Sichern-Knopf. */
        $ma = sg_config_maengel($alt);
        foreach (sg_config_maengel($neu) as $k => $t) {
            if (!in_array($k, $sg_eigene, true) && isset($ma[$k])) { continue; }
            $f = sg_formfeld($k);
            if (preg_match('/^b_zweit\[/', $f)) { $f = 'erlaubt'; }
            elseif (preg_match('/^b_stufe\[/', $f)) { $f = 'pin'; }
            if (!isset($sg_falsch[$f])) { $sg_falsch[$f] = $t; }
        }
        return $sg_falsch ? null : $neu;
    });
    if ($sg_falsch) {
        sg_umleiten('tab-settings', array(), array_merge(array(sg_t('EINST.NICHT_GESPEICHERT')), array_values($sg_falsch)),
            array('eingaben' => sg_eingaben_sammeln('settings', $sg_formfelder, array_keys($sg_falsch))));
    }
    if (!$sg_ok) {
        sg_umleiten('tab-settings', array(), array(sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']))));
    }
    sg_log('Einstellungen gespeichert');
    sg_umleiten('tab-settings', array(sg_t('EINST.GESPEICHERT')), array());
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 * Eigenes Formular UND eigener Handler: loesten beide Formulare denselben
 * Handler aus, setzte dieser die Haken des nicht abgeschickten Formulars auf 0. */
if ($sg_post && isset($_POST['save_mqtt'])) {
    $sg_falsch = array();
    /* Geprueft, nicht gesaeubert (U4, M4): bis 0.9.25 wurden Steuer- und
     * Anfuehrungszeichen still entfernt und Schraegstriche am Rand still
     * abgeschnitten. Der Punkt ist erlaubt. */
    $sg_mtopic = trim(sg_post_text('mqtt_topic'));
    if (!sg_thema_gueltig($sg_mtopic, 64)) {
        $sg_falsch['mqtt_topic'] = sg_t('EINST.FEHLER_TOPIC');
    }
    $sg_mein = isset($_POST['mqtt_ein']) ? 1 : 0;
    list($sg_ok, ) = sg_config_aendern(function ($alt) use ($sg_mtopic, $sg_mein, &$sg_falsch) {
        if ($sg_falsch) { return null; }
        $neu = $alt;
        $neu['mqtt_ein'] = $sg_mein;
        $neu['mqtt_topic'] = $sg_mtopic;
        $ma = sg_config_maengel($alt);
        foreach (sg_config_maengel($neu) as $k => $t) {
            if (in_array($k, array('mqtt_topic', 'mqtt_ein'), true) || !isset($ma[$k])) {
                if (!isset($sg_falsch[$k])) { $sg_falsch[$k] = $t; }
            }
        }
        return $sg_falsch ? null : $neu;
    });
    if ($sg_falsch) {
        sg_umleiten('tab-mqtt', array(), array_merge(array(sg_t('EINST.NICHT_GESPEICHERT')), array_values($sg_falsch)),
            array('eingaben' => sg_eingaben_sammeln('mqtt', array('mqtt_ein', 'mqtt_topic'), array_keys($sg_falsch))));
    }
    if (!$sg_ok) {
        sg_umleiten('tab-mqtt', array(), array(sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']))));
    }
    // Das Gateway-Abo auf den neuen Praefix bringen (M9).
    sg_abo_datei($sg_mtopic, true);
    sg_umleiten('tab-mqtt', array(sg_t('EINST.GESPEICHERT')), array());
}

/* ================= Befehlstabelle speichern ================= */
if ($sg_post && isset($_POST['befehle_speichern'])) {
    $sg_falsch = array();
    $sg_zeilen = array();
    $sg_alt = sg_config();
    for ($sg_i = 0; $sg_i < SG_BEFEHLE; $sg_i++) {
        $sg_v = sg_befehl_vorgabe();
        $sg_altz = isset($sg_alt['befehle'][$sg_i]) ? $sg_alt['befehle'][$sg_i] : $sg_v;
        $sg_v['aktiv'] = (isset($_POST['b_aktiv']) && is_array($_POST['b_aktiv']) && isset($_POST['b_aktiv'][$sg_i])) ? 1 : 0;

        /* Steuerzeichen werden beanstandet, nicht entfernt (U4). Das Wort
         * wird kleingeschrieben und sein Leerraum zusammengezogen - so wird
         * auch der Chattext verglichen (Vergleichsform, keine Aenderung der
         * Bedeutung; wie das Kleinschreiben der Themen). */
        $sg_roh = sg_post_zelle('b_wort', $sg_i);
        if (preg_match('/[\x00-\x1F\x7F]/', $sg_roh)) {
            $sg_falsch['b_wort[' . $sg_i . ']'] = sprintf(sg_t('BEF.FEHLER_WORT'), $sg_i + 1);
        }
        $sg_v['wort'] = sg_klein(trim(preg_replace('/\s+/', ' ', $sg_roh)));

        /* Das Thema wird geprueft, nicht gesaeubert (M4): bis 0.9.25 wurde aus
         * "garage.tor auf" still "garagetorauf" - in Loxone kam nie etwas an.
         * Der Punkt ist erlaubt. */
        $sg_v['thema'] = trim(sg_post_zelle('b_thema', $sg_i));
        $sg_v['wert'] = trim(sg_post_zelle('b_wert', $sg_i));
        $sg_v['antwort'] = trim(sg_post_zelle('b_antwort', $sg_i));
        foreach (array('wert_art' => array('fest', 'zahl'), 'stufe' => array('sofort', 'rueckfrage', 'pin')) as $sg_ak => $sg_erl) {
            $sg_w = sg_post_zelle('b_' . $sg_ak, $sg_i);
            if (in_array($sg_w, $sg_erl, true)) { $sg_v[$sg_ak] = $sg_w; }
            else {
                $sg_v[$sg_ak] = $sg_altz[$sg_ak];
                $sg_falsch['b_' . $sg_ak . '[' . $sg_i . ']'] = sprintf(sg_t('BEF.FEHLER_AUSWAHL'), $sg_i + 1);
            }
        }
        foreach (array('min', 'max') as $sg_mk) {
            $sg_z = sg_ganz_lesen(sg_post_zelle('b_' . $sg_mk, $sg_i));
            if ($sg_z === null) {
                $sg_v[$sg_mk] = $sg_altz[$sg_mk];
                $sg_falsch['b_' . $sg_mk . '[' . $sg_i . ']'] = sprintf(sg_t('BEF.FEHLER_GANZ'), $sg_i + 1);
            } else {
                $sg_v[$sg_mk] = $sg_z;
            }
        }
        /* Rufnummern werden GEPRUEFT. Eine ungueltige Nummer setzt das Feld
         * nie mehr auf leer (= alle Erlaubten) - es wird nichts gespeichert. */
        $sg_abs = trim(sg_post_zelle('b_absender', $sg_i));
        $sg_liste2 = array();
        if ($sg_abs !== '') {
            foreach (preg_split('/[\s,;]+/', $sg_abs) as $sg_nr) {
                if ($sg_nr === '') { continue; }
                if (sg_ist_nummer($sg_nr)) { $sg_liste2[] = $sg_nr; }
                elseif (!isset($sg_falsch['b_absender[' . $sg_i . ']'])) {
                    $sg_falsch['b_absender[' . $sg_i . ']'] = sprintf(sg_t('BEF.FEHLER_ABSENDER'), sg_e($sg_nr), $sg_i + 1);
                }
            }
        }
        $sg_v['absender'] = implode(',', $sg_liste2);
        $sg_v['zweit'] = trim(sg_post_zelle('b_zweit', $sg_i));
        $sg_zeilen[$sg_i] = $sg_v;
    }
    list($sg_ok, ) = sg_config_aendern(function ($alt) use ($sg_zeilen, &$sg_falsch) {
        $neu = $alt;
        $neu['befehle'] = $sg_zeilen;
        $ma = sg_config_maengel($alt);
        foreach (sg_config_maengel($neu) as $k => $t) {
            if (strpos($k, 'befehle') !== 0 && isset($ma[$k])) { continue; }
            $f = sg_formfeld($k);
            if (!isset($sg_falsch[$f])) { $sg_falsch[$f] = $t; }
        }
        return $sg_falsch ? null : $neu;
    });
    if ($sg_falsch) {
        sg_umleiten('tab-befehle', array(), array_merge(array(sg_t('BEF.NICHT_GESPEICHERT')), array_values($sg_falsch)),
            array('eingaben' => sg_eingaben_sammeln('befehle',
                array('b_aktiv', 'b_wort', 'b_thema', 'b_wert_art', 'b_wert', 'b_min', 'b_max', 'b_stufe',
                      'b_absender', 'b_zweit', 'b_antwort'), array_keys($sg_falsch))));
    }
    if (!$sg_ok) {
        sg_umleiten('tab-befehle', array(), array(sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']))));
    }
    sg_log('Befehlstabelle gespeichert');
    sg_umleiten('tab-befehle', array(sg_t('BEF.GESPEICHERT')), array());
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST, dann die Groessengrenze, dann JEDER Wert mit
 * derselben Pruefung wie die Formulare (C3). Eine halb gueltige Datei
 * aendert nichts. */
if ($sg_post && isset($_POST['sg_zurueck'])) {
    if (!isset($_FILES['sg_sicherung']) || !is_array($_FILES['sg_sicherung'])
        || !isset($_FILES['sg_sicherung']['tmp_name']) || !is_string($_FILES['sg_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['sg_sicherung']['tmp_name'])) {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.SICH_KEINE_DATEI')));
    }
    if ((int) $_FILES['sg_sicherung']['size'] > 262144) {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.SICH_ZU_GROSS')));
    }
    list($sg_neu, $sg_mangel, $sg_n) = sg_sicherung_lesen(
        (string) @file_get_contents($_FILES['sg_sicherung']['tmp_name']));
    if ($sg_neu === null) {
        /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird nichts. */
        sg_umleiten('tab-settings', array(), array_merge(array(sg_t('EINST.SICH_ABGELEHNT')), $sg_mangel));
    }
    list($sg_ok, ) = sg_config_aendern(function ($alt) use ($sg_neu) { return $sg_neu; });
    if (!$sg_ok) {
        sg_umleiten('tab-settings', array(), array(sg_t('EINST.SICH_SCHREIBFEHLER')));
    }
    sg_abo_datei($sg_neu['mqtt_topic'], true);
    sg_log('Einstellungen aus einer Sicherung zurueckgespielt (' . (int) $sg_n . ' Werte).');
    sg_umleiten('tab-settings', array(sprintf(sg_t('EINST.SICH_UEBERNOMMEN'), $sg_n)), array());
}

/* Ein POST, den kein Handler kennt, endet ebenfalls mit einer Umleitung. */
if ($sg_post) {
    sg_umleiten($sg_tab, array(), array(sg_t('TEST.M_UNBEKANNT')));
}

/* ---------------- GET: die Einmalmeldung lesen (und loeschen) ---------------- */
$sg_flash = sg_flash_lesen();
if (!empty($sg_flash['tab']) && is_string($sg_flash['tab']) && preg_match($sg_muster, $sg_flash['tab'])) {
    $sg_tab = $sg_flash['tab'];
}
foreach (array('meldungen' => 'sg_meldungen', 'fehler' => 'sg_fehler') as $sg_fk => $sg_fv) {
    if (isset($sg_flash[$sg_fk]) && is_array($sg_flash[$sg_fk])) {
        foreach ($sg_flash[$sg_fk] as $sg_ft) { if (is_string($sg_ft)) { ${$sg_fv}[] = $sg_ft; } }
    }
}
$sg_eingaben = (isset($sg_flash['eingaben']) && is_array($sg_flash['eingaben'])
    && isset($sg_flash['eingaben']['formular'], $sg_flash['eingaben']['werte'], $sg_flash['eingaben']['falsch'])
    && is_array($sg_flash['eingaben']['werte']) && is_array($sg_flash['eingaben']['falsch']))
    ? $sg_flash['eingaben'] : null;
$sg_trockentext = (isset($sg_flash['trockentext']) && is_string($sg_flash['trockentext'])) ? $sg_flash['trockentext'] : 'hilfe';

/* ---------------- X-2: Werte und Markierung nach einer Beanstandung ---------------- */
/** Ist dieses Formular das beanstandete? */
function sg_fa($formular)
{
    global $sg_eingaben;
    return is_array($sg_eingaben) && $sg_eingaben['formular'] === $formular;
}
/** Wert eines Feldes: nach einer Beanstandung die Eingabe, sonst der gespeicherte Wert. */
function sg_fw($formular, $feld, $gespeichert, $idx = null)
{
    global $sg_eingaben;
    if (sg_fa($formular)) {
        $w = isset($sg_eingaben['werte'][$feld]) ? $sg_eingaben['werte'][$feld] : null;
        if ($idx !== null) { $w = (is_array($w) && isset($w[(string) (int) $idx])) ? $w[(string) (int) $idx] : null; }
        if (is_string($w)) { return $w; }
    }
    return (string) $gespeichert;
}
/** Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function sg_fh($formular, $feld, $gespeichert, $idx = null)
{
    global $sg_eingaben;
    if (!sg_fa($formular)) { return (bool) $gespeichert; }
    $w = isset($sg_eingaben['werte'][$feld]) ? $sg_eingaben['werte'][$feld] : null;
    if ($idx !== null) { return is_array($w) && isset($w[(string) (int) $idx]); }
    return $w !== null;
}
/** Markierung eines beanstandeten Felds (Attribute, schon maskiert). */
function sg_fm($feld, $idx = null)
{
    global $sg_eingaben;
    $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
    return (is_array($sg_eingaben) && in_array($n, $sg_eingaben['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

$sg_cfg = sg_config();
$sg_pfade = sg_paths();
$sg_plugin = $sg_pfade['plugin'];

/* QR-Code fuer eine laufende Verknuepfung. qrencode zeichnet ihn, das Bild
   wird direkt eingebettet - so verlaesst die Verknuepfungsadresse den
   Server nicht als abrufbare Datei. */
$sg_uri = @file_get_contents(sg_tmpdir() . '/linkuri.txt');
if ($sg_uri) {
    $sg_png = @shell_exec('qrencode -t PNG -s 6 -o - ' . escapeshellarg($sg_uri) . ' 2>/dev/null');
    if ($sg_png) { $sg_qr = 'data:image/png;base64,' . base64_encode($sg_png); }
}


if (class_exists('LBWeb', false)) {
    LBWeb::lbheader(sg_t('ALLG.TITEL'), 'https://github.com/AsamK/signal-cli/wiki', 'help.html');
}

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 660px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-tbl input[type=text], .sm-tbl select { width: 100%; box-sizing: border-box; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 140px; }
.sm-kachel b { display: block; font-size: 1.25em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. Ohne diese zwei Zeilen stehen
   alle Reiter untereinander, sobald das Skript nicht laeuft. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* X-2 (Durchgang 01.10.2026): ein beanstandetes Feld ist markiert; die
   Markierung traegt zusaetzlich aria-invalid. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }

</style>

<div class="sm-wrap">

<?php if ($sg_meldungen) { ?>
<div class="sm-hinweis"><ul style="margin:0;padding-left:18px;">
<?php foreach ($sg_meldungen as $sg_m) { ?><li><?= $sg_m ?></li><?php } ?></ul></div>
<?php } ?>
<?php if ($sg_fehler) { ?>
<div class="sm-fehler"><ul style="margin:0;padding-left:18px;">
<?php foreach ($sg_fehler as $sg_f) { ?><li><?= $sg_f ?></li><?php } ?></ul></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Der Link
     traegt die Adresse - jeder Reiter ist verlinkbar, die Zurueck-Taste tut
     das Erwartete, und faellt das Skript aus, bleibt die Seite bedienbar. -->
<?php
/*
 * Die Reiter waren schon echte Verweise - was fehlte, war die Klasse
 * sm-active AUF DEM SERVER.
 *
 * .sm-seite steht auf display:none, sichtbar wird eine Flaeche erst durch
 * .sm-active. Diese Klasse vergab bis 0.9.0 ausschliesslich das JavaScript
 * am Seitenende; im ausgelieferten HTML kam sm-active gar nicht vor. Ohne
 * JavaScript standen Kopfzeile und Reiterleiste da, darunter nichts.
 *
 * $sg_tab wurde serverseitig laengst ermittelt und nur ans JavaScript
 * weitergereicht. Diese Liste, die Positivliste in $sg_muster und die id
 * der Flaechen muessen deckungsgleich bleiben - alle drei.
 */
$sg_reiter = array(
    'tab-settings' => sg_t('REITER.EINSTELLUNGEN'),
    'tab-befehle'  => sg_t('REITER.BEFEHLE'),
    'tab-mqtt'     => sg_t('REITER.MQTT'),
    'tab-loxone'   => sg_t('REITER.LOXONE'),
    'tab-test'     => sg_t('REITER.TEST'),
    'tab-log'      => sg_t('REITER.LOG'),
);
?>
<?php
/* Die Reiter stehen AUSGESCHRIEBEN da, nicht in einer Schleife.
 *
 * Das ist Absicht und kostet ein paar Zeilen: hausstandard_pruefen.py sucht
 * die Leiste ueber data-ziel="tab-..." im Quelltext. Eine Schleife erzeugt
 * dasselbe HTML, aber das Werkzeug findet nichts mehr und meldet die Reiter
 * als "trifft nicht zu" - eine Pruefung, die nichts prueft. Genau dieser
 * Fehler steht in REGELN_1 zweimal in der Liste eigener Fehler; die
 * Aufloesung dort lautet: ausschreiben UND die Uebereinstimmung im Reiter
 * Test pruefen lassen. Beides ist jetzt so.
 *
 * Gemessen am 16.08.2026: mit Schleife meldet das Werkzeug "-", mit
 * ausgeschriebener Leiste "TAB". */
?>
<div class="sm-tabs">
	<a class="sm-tab<?= $sg_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= sg_e($sg_reiter['tab-settings']) ?></a>
	<a class="sm-tab<?= $sg_tab === 'tab-befehle' ? ' sm-active' : '' ?>" data-ziel="tab-befehle" href="index.php?form=befehle"><?= sg_e($sg_reiter['tab-befehle']) ?></a>
	<a class="sm-tab<?= $sg_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt"><?= sg_e($sg_reiter['tab-mqtt']) ?></a>
	<a class="sm-tab<?= $sg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= sg_e($sg_reiter['tab-loxone']) ?></a>
	<a class="sm-tab<?= $sg_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= sg_e($sg_reiter['tab-test']) ?></a>
	<a class="sm-tab<?= $sg_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= sg_e($sg_reiter['tab-log']) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<?php $sg_lebt = sg_daemon_lebt(); $sg_konten = $sg_lebt ? sg_konten() : array(); ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><?= sg_e(sg_t('KACHEL.DIENST')) ?>
    <b class="<?= sg_dienst_laeuft() ? 'sm-an' : 'sm-aus' ?>"><?= sg_e(sg_dienst_laeuft() ? sg_t('ALLG.LAEUFT') : sg_t('ALLG.GESTOPPT')) ?></b></div>
  <div class="sm-kachel"><?= sg_e(sg_t('KACHEL.KONTO')) ?>
    <b class="<?= (string) $sg_cfg['konto'] !== '' ? 'sm-an' : 'sm-aus' ?>" style="font-size:1.0em;"><?= sg_e((string) $sg_cfg['konto'] !== '' ? sg_maske($sg_cfg['konto']) : sg_t('ALLG.KEINS')) ?></b></div>
  <div class="sm-kachel"><?= sg_e(sg_t('KACHEL.ERLAUBTE')) ?>
    <b class="<?= count($sg_cfg['erlaubt']) ? 'sm-an' : 'sm-aus' ?>"><?= count($sg_cfg['erlaubt']) ?></b></div>
  <div class="sm-kachel"><?= sg_e(sg_t('KACHEL.SPERRE')) ?>
    <b class="<?= empty($sg_cfg['gesperrt']) ? 'sm-an' : 'sm-aus' ?>"><?= sg_e(empty($sg_cfg['gesperrt']) ? sg_t('ALLG.FREI') : sg_t('ALLG.GESPERRT')) ?></b></div>
</div>

<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sg_t('LEGENDE.LESEN') ?></span> <span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<h2><?= sg_e(sg_t('EINST.H_SPERRE')) ?></h2>
<div class="sm-warnung"><?= sg_t('EINST.SPERRE_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="sperre" value="<?= empty($sg_cfg['gesperrt']) ? 'ein' : 'aus' ?>">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(empty($sg_cfg['gesperrt']) ? sg_t('EINST.K_SPERREN') : sg_t('EINST.K_ENTSPERREN')) ?></button>
</form>
</div>

<h2><?= sg_e(sg_t('EINST.H_VERKNUEPFUNG')) ?></h2>
<div class="sm-hinweis"><?= sg_t('EINST.VERKNUEPFUNG_TEXT') ?></div>
<?php if ($sg_qr !== '') { ?>
<div class="sm-step">
  <b><?= sg_e(sg_t('EINST.QR_TITEL')) ?></b><br>
  <?= sg_t('EINST.QR_TEXT') ?>
  <div style="margin:12px 0;"><img src="<?= $sg_qr ?>" alt="QR" style="image-rendering:pixelated;"></div>
  <div class="sm-warnung"><?= sg_t('EINST.QR_WARNUNG') ?></div>
  <form action="index.php" method="post">
    <?php echo sg_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="link_fertig" value="1">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('EINST.K_LINK_FERTIG')) ?></button>
  </form>
</div>
<?php } else { ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="link_start" value="1">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" <?= $sg_lebt ? '' : 'disabled' ?>><?= sg_e(sg_t('EINST.K_LINK_START')) ?></button>
</form>
</div>
<?php if (!$sg_lebt) { ?><div class="sm-warnung"><?= sg_t('EINST.LINK_KEIN_DIENST') ?></div><?php } ?>
<?php } ?>

<?php if ((string) $sg_cfg['konto'] !== '') { ?>
<h2><?= sg_e(sg_t('EINST.H_LOESEN')) ?></h2>
<div class="sm-warnung"><?= sg_t('EINST.LOESEN_TEXT') ?></div>
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="link_loesen" value="1">
  <div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
      <input data-role="none" type="checkbox" name="loesen_ok" value="1">
      <?= sg_e(sg_t('EINST.L_LOESEN_OK')) ?>
    </label>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('EINST.K_LOESEN')) ?></button>
  </div>
</form>
<?php } ?>

<form action="index.php" method="post" autocomplete="off">
  <?php echo sg_fmt(); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= sg_e(sg_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-row">
  <div class="sm-feld">
    <label for="rpc_url"><?= sg_e(sg_t('EINST.L_RPC')) ?></label>
    <input data-role="none" type="text" id="rpc_url" name="rpc_url" value="<?= sg_e(sg_fw('settings', 'rpc_url', $sg_cfg['rpc_url'])) ?>"<?= sg_fm('rpc_url') ?> placeholder="http://127.0.0.1:8095">
    <div class="sm-hilfe"><?= sg_t('EINST.H_RPC') ?></div>
  </div>
  <div class="sm-feld">
    <label for="konto"><?= sg_e(sg_t('EINST.L_KONTO')) ?></label>
    <input data-role="none" type="text" id="konto" name="konto" value=""<?= sg_fm('konto') ?> placeholder="<?= sg_e((string) $sg_cfg['konto'] !== '' ? sprintf(sg_t('EINST.P_KONTO'), sg_maske($sg_cfg['konto'])) : '+49...') ?>">
    <div class="sm-hilfe"><?= $sg_konten ? sprintf(sg_t('EINST.H_KONTO_GEFUNDEN'), sg_e(implode(', ', $sg_konten))) : sg_t('EINST.H_KONTO') ?></div>
  </div>
</div>

<h2><?= sg_e(sg_t('EINST.H_ABSICHERUNG')) ?></h2>
<div class="sm-warnung"><?= sg_t('EINST.ABSICHERUNG_TEXT') ?></div>
<div class="sm-feld">
  <label for="erlaubt"><?= sg_e(sg_t('EINST.L_ERLAUBT')) ?></label>
  <textarea data-role="none" id="erlaubt" name="erlaubt" rows="4" style="width:100%;max-width:520px;" placeholder="+491701234567"<?= sg_fm('erlaubt') ?>><?= sg_e(sg_fw('settings', 'erlaubt', implode("\n", $sg_cfg['erlaubt']))) ?></textarea>
  <div class="sm-hilfe"><?= sg_t('EINST.H_ERLAUBT') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="stille" value="1" <?= sg_fh('settings', 'stille', !empty($sg_cfg['stille'])) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_STILLE')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_STILLE') ?></div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="pin"><?= sg_e(sg_t('EINST.L_PIN')) ?></label>
    <input data-role="none" type="password" id="pin" name="pin" value=""<?= sg_fm('pin') ?> placeholder="<?= sg_e(sg_pin_gesetzt($sg_cfg) ? sg_t('EINST.P_GESETZT') : sg_t('EINST.P_LEER')) ?>">
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN') ?></div>
    <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
      <input data-role="none" type="checkbox" name="pin_loeschen" value="1" <?= sg_fh('settings', 'pin_loeschen', false) ? 'checked' : '' ?>> <?= sg_e(sg_t('EINST.L_PIN_LOESCHEN')) ?>
    </label>
  </div>
  <div class="sm-feld">
    <label for="bremse"><?= sg_e(sg_t('EINST.L_BREMSE')) ?></label>
    <input data-role="none" type="text" inputmode="numeric" id="bremse" name="bremse" value="<?= sg_e(sg_fw('settings', 'bremse', (int) $sg_cfg['bremse'])) ?>"<?= sg_fm('bremse') ?>>
    <div class="sm-hilfe"><?= sg_t('EINST.H_BREMSE') ?></div>
  </div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="pin_versuche"><?= sg_e(sg_t('EINST.L_PIN_VERSUCHE')) ?></label>
    <input data-role="none" type="text" inputmode="numeric" id="pin_versuche" name="pin_versuche" value="<?= sg_e(sg_fw('settings', 'pin_versuche', (int) $sg_cfg['pin_versuche'])) ?>"<?= sg_fm('pin_versuche') ?>>
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN_VERSUCHE') ?></div>
  </div>
  <div class="sm-feld">
    <label for="pin_sperre"><?= sg_e(sg_t('EINST.L_PIN_SPERRE')) ?></label>
    <input data-role="none" type="text" inputmode="numeric" id="pin_sperre" name="pin_sperre" value="<?= sg_e(sg_fw('settings', 'pin_sperre', (int) $sg_cfg['pin_sperre'])) ?>"<?= sg_fm('pin_sperre') ?>>
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN_SPERRE') ?></div>
  </div>
</div>

<h2><?= sg_e(sg_t('EINST.H_WEITERES')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="zustand_ein" value="1" <?= sg_fh('settings', 'zustand_ein', !empty($sg_cfg['zustand_ein'])) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_ZUSTAND')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_ZUSTAND') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="audit" value="1" <?= sg_fh('settings', 'audit', !empty($sg_cfg['audit'])) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_AUDIT')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_AUDIT') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="herzschlag" value="1" <?= sg_fh('settings', 'herzschlag', !empty($sg_cfg['herzschlag'])) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_HERZSCHLAG')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_HERZSCHLAG') ?></div>
</div>

<h2><?= sg_e(sg_t('EINST.H_MELDEWEGE')) ?></h2>
<div class="sm-feld">
  <label for="gruppe"><?= sg_e(sg_t('EINST.L_GRUPPE')) ?></label>
  <input data-role="none" type="text" id="gruppe" name="gruppe" value="<?= sg_e(sg_fw('settings', 'gruppe', $sg_cfg['gruppe'])) ?>"<?= sg_fm('gruppe') ?>>
  <div class="sm-hilfe"><?= sg_t('EINST.H_GRUPPE') ?></div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="nacht_von"><?= sg_e(sg_t('EINST.L_NACHT_VON')) ?></label>
    <input data-role="none" type="text" id="nacht_von" name="nacht_von" value="<?= sg_e(sg_fw('settings', 'nacht_von', $sg_cfg['nacht_von'])) ?>"<?= sg_fm('nacht_von') ?> placeholder="22:00">
  </div>
  <div class="sm-feld">
    <label for="nacht_bis"><?= sg_e(sg_t('EINST.L_NACHT_BIS')) ?></label>
    <input data-role="none" type="text" id="nacht_bis" name="nacht_bis" value="<?= sg_e(sg_fw('settings', 'nacht_bis', $sg_cfg['nacht_bis'])) ?>"<?= sg_fm('nacht_von') ?> placeholder="07:00">
  </div>
</div>
<div class="sm-hilfe"><?= sg_t('EINST.H_NACHT') ?></div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="quittung_takt"><?= sg_e(sg_t('EINST.L_QUITTUNG_TAKT')) ?></label>
    <input data-role="none" type="text" inputmode="numeric" id="quittung_takt" name="quittung_takt" value="<?= sg_e(sg_fw('settings', 'quittung_takt', (int) $sg_cfg['quittung_takt'])) ?>"<?= sg_fm('quittung_takt') ?>>
  </div>
  <div class="sm-feld">
    <label for="quittung_max"><?= sg_e(sg_t('EINST.L_QUITTUNG_MAX')) ?></label>
    <input data-role="none" type="text" inputmode="numeric" id="quittung_max" name="quittung_max" value="<?= sg_e(sg_fw('settings', 'quittung_max', (int) $sg_cfg['quittung_max'])) ?>"<?= sg_fm('quittung_max') ?>>
  </div>
</div>
<div class="sm-hilfe"><?= sg_t('EINST.H_QUITTUNG') ?></div>
<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= sg_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= sg_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= sg_t('EINST.SICH_WARNUNG') ?></div>
<?php
/* X-3: Bestuende die eigene Sicherung das Zurueckspielen nicht, steht es
 * hier - geprueft mit derselben Funktion wie beim Zurueckspielen. Genannt
 * werden die Einstellungen, nicht ihre Werte. */
$sg_sich_mangel = sg_sicherung_eigene_maengel($sg_cfg);
if ($sg_sich_mangel) { ?>
<div class="sm-warnung" id="sg-sicherung-warnung"><?= sprintf(sg_t('EINST.SICH_EIGEN_WARNUNG'), sg_e(implode(', ', $sg_sich_mangel))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo sg_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sg_sichern" value="1"><?= sg_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo sg_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="sg_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sg_zurueck" value="1"><?= sg_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Befehle ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-befehle' ? ' sm-active' : '' ?>" id="tab-befehle">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<h2><?= sg_e(sg_t('BEF.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sg_t('BEF.ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= sg_t('BEF.STUFEN') ?></div>

<form action="index.php" method="post" autocomplete="off">
  <?php echo sg_fmt(); ?>
<input data-role="none" type="hidden" name="befehle_speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-befehle">
<div style="overflow-x:auto;">
<table class="sm-tbl">
<tr>
  <th style="width:30px;"><?= sg_e(sg_t('BEF.T_AN')) ?></th>
  <th style="width:15%;"><?= sg_e(sg_t('BEF.T_WORT')) ?></th>
  <th style="width:17%;"><?= sg_e(sg_t('BEF.T_THEMA')) ?></th>
  <th style="width:15%;"><?= sg_e(sg_t('BEF.T_WERT')) ?></th>
  <th style="width:12%;"><?= sg_e(sg_t('BEF.T_STUFE')) ?></th>
  <th style="width:13%;"><?= sg_e(sg_t('BEF.T_WER')) ?></th>
  <th style="width:13%;"><?= sg_e(sg_t('BEF.T_ZWEIT')) ?></th>
  <th><?= sg_e(sg_t('BEF.T_ANTWORT')) ?></th>
</tr>
<?php for ($sg_i = 0; $sg_i < SG_BEFEHLE; $sg_i++) { $sg_b = $sg_cfg['befehle'][$sg_i]; ?>
<tr>
  <td style="text-align:center;"><input data-role="none" type="checkbox" name="b_aktiv[<?= $sg_i ?>]" value="1" <?= sg_fh('befehle', 'b_aktiv', !empty($sg_b['aktiv']), $sg_i) ? 'checked' : '' ?><?= sg_fm('b_aktiv', $sg_i) ?>></td>
  <td><input data-role="none" type="text" name="b_wort[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_wort', $sg_b['wort'], $sg_i)) ?>"<?= sg_fm('b_wort', $sg_i) ?> placeholder="<?= $sg_i === 0 ? 'licht an' : '' ?>"></td>
  <td><input data-role="none" type="text" name="b_thema[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_thema', $sg_b['thema'], $sg_i)) ?>"<?= sg_fm('b_thema', $sg_i) ?> placeholder="<?= $sg_i === 0 ? 'licht/wohnen' : '' ?>"></td>
  <td>
<?php $sg_wa_ist = sg_fw('befehle', 'b_wert_art', $sg_b['wert_art'], $sg_i); ?>
    <select data-role="none" name="b_wert_art[<?= $sg_i ?>]"<?= sg_fm('b_wert_art', $sg_i) ?>>
<?php foreach (array('fest', 'zahl') as $sg_wa) { ?>
      <option value="<?= $sg_wa ?>"<?= $sg_wa_ist === $sg_wa ? ' selected' : '' ?>><?= sg_e(sg_t('BEF.WERT_' . strtoupper($sg_wa))) ?></option>
<?php } ?>
    </select>
    <input data-role="none" type="text" name="b_wert[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_wert', $sg_b['wert'], $sg_i)) ?>"<?= sg_fm('b_wert', $sg_i) ?> placeholder="1">
    <span class="sm-hilfe" style="display:block;">
      <input data-role="none" type="text" inputmode="numeric" name="b_min[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_min', (int) $sg_b['min'], $sg_i)) ?>"<?= sg_fm('b_min', $sg_i) ?> style="width:45%;">
      <input data-role="none" type="text" inputmode="numeric" name="b_max[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_max', (int) $sg_b['max'], $sg_i)) ?>"<?= sg_fm('b_max', $sg_i) ?> style="width:45%;">
    </span>
  </td>
<?php $sg_st_ist = sg_fw('befehle', 'b_stufe', $sg_b['stufe'], $sg_i); ?>
  <td><select data-role="none" name="b_stufe[<?= $sg_i ?>]"<?= sg_fm('b_stufe', $sg_i) ?>>
<?php foreach (array('sofort', 'rueckfrage', 'pin') as $sg_s) { ?>
    <option value="<?= $sg_s ?>"<?= $sg_st_ist === $sg_s ? ' selected' : '' ?>><?= sg_e(sg_t('BEF.STUFE_' . strtoupper($sg_s))) ?></option>
<?php } ?>
  </select></td>
  <td><input data-role="none" type="text" name="b_absender[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_absender', $sg_b['absender'], $sg_i)) ?>"<?= sg_fm('b_absender', $sg_i) ?> placeholder="<?= sg_e(sg_t('BEF.P_ALLE')) ?>"></td>
  <td><input data-role="none" type="text" name="b_zweit[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_zweit', $sg_b['zweit'], $sg_i)) ?>"<?= sg_fm('b_zweit', $sg_i) ?> placeholder="+49..."></td>
  <td><input data-role="none" type="text" name="b_antwort[<?= $sg_i ?>]" value="<?= sg_e(sg_fw('befehle', 'b_antwort', $sg_b['antwort'], $sg_i)) ?>"<?= sg_fm('b_antwort', $sg_i) ?> placeholder="<?= sg_e(sg_t('BEF.P_ANTWORT')) ?>"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= sg_t('BEF.SPALTEN_TEXT') ?></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= sg_e(sg_t('BEF.H_EINGEBAUT')) ?></h3>
<p class="sm-hilfe"><?= sg_t('BEF.EINGEBAUT_TEXT') ?></p>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>

<h2><?= sg_e(sg_t('MQTT.H_EINSTELLUNG')) ?></h2>
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= sg_fh('mqtt', 'mqtt_ein', !empty($sg_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= sg_e(sg_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= sg_e(sg_fw('mqtt', 'mqtt_topic', $sg_cfg['mqtt_topic'])) ?>"<?= sg_fm('mqtt_topic') ?> placeholder="signalbot">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= sg_e(sg_t('MQTT.H_TITEL')) ?></h2>
<?php /* Hier stand bis 0.9.11 eine zweite, eigene Autostart-Pruefung mit
         festem Pfad nach /opt/loxberry. Sie sagte dasselbe wie die Zeilen
         darunter - nur mit dem richtigen Schluessel, waehrend
         sg_mqtt_zustand() den falschen las. Zwei Quellen fuer dieselbe
         Auskunft, die sich widersprechen konnten. Die Pruefung wohnt jetzt
         allein in sg_mqtt_zustand(); der Text MQTT.W_AUTOSTART ist damit
         entfallen. */ ?>
<?php $sg_m = sg_mqtt_zustand(); ?>
<?php if (!$sg_m['gefunden']) { ?>
<div class="sm-fehler"><?= sg_t('MQTT.KEIN_ABSCHNITT') ?></div>
<?php } elseif (!$sg_m['autostart']) { ?>
<div class="sm-warnung"><?= sg_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(sg_t('MQTT.OK'), (int) $sg_m['udpport']) ?></div>
<?php } ?>

<div class="sm-step"><b><?= sg_e(sg_t('MQTT.H_ABO')) ?></b><br>
<?= sg_t('MQTT.ABO_TEXT') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/#</div>
<?php list($sg_abo_pfad, $sg_abo_da) = sg_abo_datei($sg_cfg['mqtt_topic']); ?>
<?= $sg_abo_da ? sg_t('MQTT.ABO_MITGELIEFERT') : sg_abo_text() ?>
</div>

<h3><?= sg_e(sg_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= sg_t('MQTT.THEMEN_TEXT') ?></p>
<?php /* Aus sg_mqtt_themen() - derselben Liste, aus der der Sendecode das
         Thema baut (U18, M8). Bis 0.9.25 zeigte die Spalte "Wert" bei einer
         Zahl-Zeile den festen Wert 1, gesendet wurde aber die mitgeschickte
         Zahl; eine Spalte "retained" fehlte (Entscheidung 3). */ ?>
<table class="sm-tbl">
<tr><th><?= sg_e(sg_t('MQTT.T_THEMA')) ?></th><th><?= sg_e(sg_t('MQTT.T_WERT')) ?></th><th><?= sg_e(sg_t('MQTT.T_RETAINED')) ?></th><th><?= sg_e(sg_t('MQTT.T_BEFEHL')) ?></th></tr>
<?php $sg_leer = true; foreach (sg_mqtt_themen($sg_cfg) as $sg_th) {
    if ($sg_th['art'] === 'fest' || $sg_th['art'] === 'zahl') { $sg_leer = false; } ?>
<tr><td><span class="sm-mono"><?= sg_e($sg_th['thema']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_th['wert']) ?></span></td>
    <td><?= sg_e($sg_th['retained'] ? sg_t('MQTT.JA') : sg_t('MQTT.NEIN')) ?></td>
    <td><?= $sg_th['art'] === 'online' ? sg_t('MQTT.T_ONLINE') : ($sg_th['art'] === 'selbsttest' ? sg_t('MQTT.T_SELBSTTEST') : '<span class="sm-mono">' . sg_e($sg_th['quelle']) . '</span>') ?></td></tr>
<?php } ?>
<?php if ($sg_leer) { ?><tr><td colspan="4"><?= sg_t('MQTT.KEINE_BEFEHLE') ?></td></tr><?php } ?>
</table>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= sg_t('LEGENDE.TECHNIK') ?></span></div>
<h2><?= sg_e(sg_t('LOX.H_RICHTUNGEN')) ?></h2>
<div class="sm-hinweis"><?= sg_t('LOX.RICHTUNGEN_TEXT') ?></div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S0_T')) ?></b><br>
<?= sg_t('LOX.S0') ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.SABO_T')) ?></b><br>
<?= sg_t('LOX.SABO') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/#</div>
<?= sg_abo_text() ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S1_T')) ?></b><br>
<?= sg_t('LOX.S1') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/&lt;<?= sg_e(sg_t('LOX.THEMA_PLATZHALTER')) ?>&gt;</div>
<?= sg_t('LOX.S1_IMPULS') ?>
<table class="sm-tbl">
<tr><th><?= sg_e(sg_t('MQTT.T_THEMA')) ?></th><th><?= sg_e(sg_t('MQTT.T_WERT')) ?></th><th><?= sg_e(sg_t('MQTT.T_BEFEHL')) ?></th></tr>
<?php $sg_leer2 = true; foreach (sg_mqtt_themen($sg_cfg) as $sg_th2) {
    if ($sg_th2['art'] !== 'fest' && $sg_th2['art'] !== 'zahl') { continue; }
    $sg_leer2 = false; ?>
<tr><td><span class="sm-mono"><?= sg_e($sg_th2['thema']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_th2['wert']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_th2['quelle']) ?></span></td></tr>
<?php } ?>
<?php if ($sg_leer2) { ?><tr><td colspan="3"><?= sg_t('MQTT.KEINE_BEFEHLE') ?></td></tr><?php } ?>
</table>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.SVI_T')) ?></b><br>
<?= sg_t('LOX.SVI') ?>
<table class="sm-tbl">
<tr><th style="width:170px;"><?= sg_e(sg_t('LOX.T_TITEL')) ?></th><th style="width:70px;"><?= sg_e(sg_t('LOX.T_ART')) ?></th><th style="width:90px;"><?= sg_e(sg_t('LOX.T_BEREICH')) ?></th><th><?= sg_e(sg_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (sg_felder() as $sg_fn => $sg_fd) {
    list($sg_analog, $sg_min, $sg_max, $sg_fs) = $sg_fd; ?>
<tr><td><span class="sm-mono">SIGNAL_<?= sg_e($sg_fn) ?></span></td>
    <td><?= sg_e($sg_analog ? sg_t('LOX.ANALOG') : sg_t('LOX.DIGITAL')) ?></td>
    <td><span class="sm-mono"><?= (int) $sg_min ?>..<?= (int) $sg_max ?></span></td>
    <td><?= sg_t($sg_fs) ?></td></tr>
<?php } ?>
</table>
<?= sg_t('LOX.SVI_ADRESSE') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('status')) ?></div>
<?= sg_t('LOX.SVI_HOST') ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S2_T')) ?></b><br>
<?= sg_t('LOX.S2') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('senden')) ?>&amp;text=Alarm%20ausgeloest</div>
<?= sg_t('LOX.S2_HINWEIS') ?>
<br><br><?= sg_t('LOX.S2_DRINGEND') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('senden')) ?>&amp;dringend=1&amp;text=Alarm%20ausgeloest</div>
<?= sg_t('LOX.S2_BILD') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('senden')) ?>&amp;text=Bewegung&amp;bild=<?= sg_e(sg_datadir()) ?>/schnappschuss.jpg</div>
<br><?= sg_t('LOX.S2_SPERRE') ?>
<?php /* ZWEI Bloecke (U1). Bis 0.9.25 standen beide Adressen in einem Block,
         getrennt nur durch den Zeilenumbruch nach "?>" - und den verschluckt
         PHP. Heraus kam EINE Zeile "...aktion=sperrenhttp://...aktion=entsperren";
         wer sie abschrieb und aufrief, ENTSPERRTE den Bot (gemessen). */ ?>
<div><b><?= sg_e(sg_t('LOX.L_SPERREN')) ?></b></div>
<div class="sm-pre"><?= sg_e(sg_endpunkt('sperren')) ?></div>
<div><b><?= sg_e(sg_t('LOX.L_ENTSPERREN')) ?></b></div>
<div class="sm-pre"><?= sg_e(sg_endpunkt('entsperren')) ?></div>
</div>

<?php if (!empty($sg_cfg['zustand_ein'])) { ?>
<div class="sm-step"><b><?= sg_e(sg_t('LOX.S3_T')) ?></b><br>
<?= sg_t('LOX.S3') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('zustand')) ?>&amp;name=alarm&amp;wert=scharf</div>
<?= sg_t('LOX.S3_HINWEIS') ?>
</div>
<?php } ?>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.SAUS_T')) ?></b><br>
<?= sg_t('LOX.SAUS') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/online</div>
<?= sg_t('LOX.SAUS_HINWEIS') ?>
</div>

<h2><?= sg_e(sg_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-hinweis"><?= sg_t('LOX.VORLAGE_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= sg_e(sg_t('LOX.K_VORLAGE')) ?></button>
</form>
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage_out" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= sg_e(sg_t('LOX.K_VORLAGE_OUT')) ?></button>
</form>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S4_T')) ?></b><br>
<?= sg_t('LOX.S4') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= sg_e(sg_t('LOX.T_BAUSTEIN')) ?></th><th><?= sg_e(sg_t('LOX.T_NAME')) ?></th><th><?= sg_e(sg_t('LOX.T_PARAMETER')) ?></th><th><?= sg_e(sg_t('LOX.T_VERBINDEN')) ?></th></tr>
<tr><td>1</td><td><?= sg_t('BAUSTEIN.B1_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B1_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B1_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B1_VERB') ?></td></tr>
<tr><td>2</td><td><?= sg_t('BAUSTEIN.B2_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B2_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B2_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B2_VERB') ?></td></tr>
<tr><td>3</td><td><?= sg_t('BAUSTEIN.B3_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B3_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B3_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B3_VERB') ?></td></tr>
<tr><td>4</td><td><?= sg_t('BAUSTEIN.B4_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B4_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B4_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B4_VERB') ?></td></tr>
<tr><td>5</td><td><?= sg_t('BAUSTEIN.B5_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B5_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B5_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B5_VERB') ?></td></tr>
<tr><td>6</td><td><?= sg_t('BAUSTEIN.B6_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B6_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B6_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B6_VERB') ?></td></tr>
<tr><td>7</td><td><?= sg_t('BAUSTEIN.B7_TYP') ?></td><td><span class="sm-mono"><?= sg_e(sg_t('BAUSTEIN.B7_NAME')) ?></span></td><td><?= sg_t('BAUSTEIN.B7_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B7_VERB') ?></td></tr>
</table>
<?= sg_t('LOX.S4_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.SGEGEN_T')) ?></b><br>
<?= sg_t('LOX.SGEGEN') ?>
<div class="sm-pre"><?= sg_e(sg_endpunkt('status')) ?>&amp;selftest=1</div>
<?= sg_t('LOX.SGEGEN_2') ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= sg_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= sg_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span>
</div>
<h2><?= sg_e(sg_t('TEST.H_SELBSTTEST')) ?></h2>
<p class="sm-hilfe"><?= sg_t('TEST.SELBSTTEST_TEXT') ?></p>
<?php
$sg_pr = sg_pruefungen($sg_tab === 'tab-test');
$sg_schlecht = 0;
foreach ($sg_pr as $sg_z) { if ($sg_z[0] === 0) { $sg_schlecht++; } }
?>
<div class="<?= $sg_schlecht ? 'sm-warnung' : 'sm-hinweis' ?>">
<?= sprintf(sg_t($sg_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'), count($sg_pr) - $sg_schlecht, count($sg_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;">&nbsp;</th><th><?= sg_e(sg_t('TEST.T_FRAGE')) ?></th><th><?= sg_e(sg_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($sg_pr as $sg_z) { ?>
<tr><td style="text-align:center;"><?= $sg_z[0] === 1 ? '<span class="sm-an">&#10003;</span>' : ($sg_z[0] === 0 ? '<span class="sm-aus">&#10007;</span>' : '<span style="color:#888;">i</span>') ?></td>
    <td><?= $sg_z[1] ?></td><td><?= $sg_z[2] ?></td></tr>
<?php } ?>
</table>

<h3><?= sg_e(sg_t('TEST.H_TROCKEN')) ?></h3>
<p class="sm-hilfe"><?= sg_t('TEST.TROCKEN_TEXT') ?></p>
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="trocken">
  <div class="sm-feld">
    <label for="trockentext"><?= sg_e(sg_t('TEST.L_TROCKEN')) ?></label>
    <input data-role="none" type="text" id="trockentext" name="trockentext" value="<?= sg_e($sg_trockentext) ?>">
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= sg_e(sg_t('TEST.K_TROCKEN')) ?></button>
  </div>
</form>

<h3><?= sg_e(sg_t('TEST.H_KNOEPFE')) ?></h3>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?= sg_e($sg_plugin) ?>/index.php?token=<?= sg_e($sg_cfg['aktionstoken']) ?>&amp;aktion=status" target="_blank"><?= sg_e(sg_t('TEST.K_STATUS')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?= sg_e($sg_plugin) ?>/index.php?token=<?= sg_e($sg_cfg['aktionstoken']) ?>&amp;selftest=1" target="_blank"><?= sg_e(sg_t('TEST.K_SELFTEST')) ?></a>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="start">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_t('TEST.K_START')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="<?= sg_dienst_autostart() === 'enabled' ? 'disable' : 'enable' ?>">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_dienst_autostart() === 'enabled' ? sg_t('TEST.K_AUTOSTART_AUS') : sg_t('TEST.K_AUTOSTART_EIN')) ?></button></form>
</div>
<?php /* Bis 0.9.25 ein Link auf rpc_url + /api/v1/check: im Browser heisst
         127.0.0.1 der PC des Anwenders, der Knopf scheiterte dort immer
         (Regeln/04), und eine untergeschobene Sicherung konnte hier einen
         javascript:-Link einsetzen. Jetzt fragt der Server (U19). */ ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="check">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= sg_e(sg_t('TEST.K_CHECK')) ?></button></form>
</div>

<h3><?= sg_e(sg_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= sg_t('TEST.SCHALTEN_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="probe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_PROBE')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="restart">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_RESTART')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="stop">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_STOP')) ?></button></form>
<?php if (sg_bogen() !== '' && sg_bogen() !== 'amd64' && !is_file(sg_nativ_datei())) { ?>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="nativ">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_NATIV')) ?></button></form>
<?php } ?>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="token">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_TOKEN')) ?></button></form>
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<h2><?= sg_e(sg_t('LOG.H_EREIGNIS')) ?></h2>
<div class="sm-hilfe"><?= sg_t('LOG.EREIGNIS_TEXT') ?></div>
<?php $sg_ev = array_reverse(sg_ereignisse()); ?>
<?php if (!$sg_ev) { ?>
<div class="sm-hinweis"><?= sg_t('LOG.EREIGNIS_LEER') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:150px;"><?= sg_e(sg_t('LOG.T_ZEIT')) ?></th><th style="width:130px;"><?= sg_e(sg_t('LOG.T_WER')) ?></th><th style="width:130px;"><?= sg_e(sg_t('LOG.T_WAS')) ?></th><th><?= sg_e(sg_t('LOG.T_DETAIL')) ?></th></tr>
<?php foreach (array_slice($sg_ev, 0, 60) as $sg_x) { ?>
<tr><td><?= sg_e(date('d.m.Y H:i:s', (int) $sg_x['ts'])) ?></td>
    <td><?= sg_e($sg_x['wer']) ?></td>
    <td><?= sg_e($sg_x['was']) ?></td>
    <td><?= sg_e($sg_x['detail']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <input data-role="none" type="hidden" name="clearaudit" value="1">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('LOG.K_AUDIT_LEEREN')) ?></button>
</form>
</div>
<?php } ?>

<h2><?= sg_e(sg_t('LOG.H_TITEL')) ?></h2>
<div class="sm-hilfe"><?= sg_t('LOG.MASKE_HINWEIS') ?></div>
<?php
$sg_lf = $sg_pfade['log'];
$sg_zeilen = is_file($sg_lf) ? array_slice(file($sg_lf, FILE_IGNORE_NEW_LINES) ?: array(), -250) : array();
?>
<?php if (!$sg_zeilen) { ?>
<div class="sm-hinweis"><?= sg_t('LOG.LEER') ?></div>
<?php } else { ?>
<div class="sm-pre" style="max-height:480px;"><?= sg_e(implode("\n", $sg_zeilen)) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo sg_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <input data-role="none" type="hidden" name="clearlog" value="1">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('LOG.K_LEEREN')) ?></button>
</form>
</div>
<div class="sm-hilfe"><?= sprintf(sg_t('LOG.CLI_HINWEIS'), '<span class="sm-mono">journalctl -u signal-cli-loxberry -n 100</span>') ?></div>

<?php
/* Die Logdateiliste des Kerns - Pflichtinhalt dieses Reiters laut
   Hausstandard. Sie zeigt die Dateien mit Datum und Groesse und traegt die
   Logstufen-Einstellung des Plugins; die Anzeige darueber bleibt daneben
   stehen, weil sie ohne Umweg das zeigt, was gerade passiert. */
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo '<h2>' . sg_e(sg_t('LOG.H_DATEIEN')) . '</h2>';
    echo LBWeb::loglist_html();
}
?>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($sg_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) { LBWeb::lbfooter(); }
