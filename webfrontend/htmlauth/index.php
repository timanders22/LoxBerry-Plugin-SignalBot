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
if ($p['home'] !== '' && file_exists($p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $p['home'] . '/libs/phplib/loxberry_web.php';
}

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

/* ================= Vorlage herunterladen (vor jeder Ausgabe) ================= */
if ($sg_post && isset($_POST['vorlage']) || $sg_post && isset($_POST['vorlage_out'])) {
    list($sg_vname, $sg_vinhalt) = isset($_POST['vorlage_out']) ? sg_vorlage_out() : sg_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $sg_vname . '"');
    echo $sg_vinhalt;
    exit;
}

/* ================= Protokoll leeren ================= */
if ($sg_post && isset($_POST['clearlog'])) {
    $sg_lf = sg_paths()['log'];
    @mkdir(dirname($sg_lf), 0775, true);
    sg_log_setzen($sg_lf, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n");
    $sg_tab = 'tab-log';
}

/* ================= Verknuepfung ================= */
if ($sg_post && isset($_POST['link_start'])) {
    $a = sg_rpc('startLink', array(), 30);
    if ($a['ok'] && isset($a['result']['deviceLinkUri'])) {
        @file_put_contents(sg_tmpdir() . '/linkuri.txt', (string) $a['result']['deviceLinkUri']);
        @chmod(sg_tmpdir() . '/linkuri.txt', 0600);
        $sg_meldungen[] = sg_t('EINST.LINK_GESTARTET');
    } else {
        $sg_fehler[] = sprintf(sg_t('EINST.LINK_FEHLER'), sg_e($a['fehler']));
    }
    $sg_tab = 'tab-settings';
}
if ($sg_post && isset($_POST['link_fertig'])) {
    $uri = @file_get_contents(sg_tmpdir() . '/linkuri.txt');
    if (!$uri) {
        $sg_fehler[] = sg_t('EINST.LINK_KEINE_URI');
    } else {
        // finishLink wartet auf das Handy - deshalb die lange Frist.
        $a = sg_rpc('finishLink', array('deviceLinkUri' => (string) $uri,
                                        'deviceName' => 'LoxBerry Signal Bot'), 150);
        if ($a['ok']) {
            @unlink(sg_tmpdir() . '/linkuri.txt');
            $sg_meldungen[] = sg_t('EINST.LINK_FERTIG');
            // Das neue Konto gleich uebernehmen, wenn es genau eines gibt.
            $k = sg_konten();
            if (count($k) === 1) {
                $c = sg_config();
                $c['konto'] = $k[0];
                sg_config_write($c);
            }
        } else {
            $sg_fehler[] = sprintf(sg_t('EINST.LINK_FEHLER'), sg_e($a['fehler']));
        }
    }
    $sg_tab = 'tab-settings';
}

/* ================= Kill-Schalter ================= */
if ($sg_post && isset($_POST['sperre'])) {
    $sg_scfg = sg_config();
    $sg_scfg['gesperrt'] = $_POST['sperre'] === 'ein' ? 1 : 0;
    if (sg_config_write($sg_scfg)) {
        sg_log($sg_scfg['gesperrt'] ? 'Bot in der Oberflaeche gesperrt.' : 'Bot in der Oberflaeche entsperrt.');
        sg_ereignis_merken('Oberflaeche', $sg_scfg['gesperrt'] ? 'gesperrt' : 'entsperrt', '');
        $sg_meldungen[] = $sg_scfg['gesperrt'] ? sg_t('EINST.M_GESPERRT') : sg_t('EINST.M_ENTSPERRT');
    } else {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']));
    }
    $sg_tab = 'tab-settings';
}

/* ================= Ereignisprotokoll leeren ================= */
if ($sg_post && isset($_POST['clearaudit'])) {
    sg_write_atomic(sg_datadir() . '/ereignisse.json', json_encode(array()), 0600);
    $sg_meldungen[] = sg_t('LOG.M_AUDIT_LEER');
    $sg_tab = 'tab-log';
}

/* ================= Test-Aktionen ================= */
if ($sg_post && isset($_POST['testaktion'])) {
    $sg_zusatz = isset($_POST['trockentext']) ? (string) $_POST['trockentext'] : '';
    list($sg_ok, $sg_text) = sg_test_aktion((string) $_POST['testaktion'], $sg_zusatz);
    if ($sg_ok) { $sg_meldungen[] = $sg_text; } else { $sg_fehler[] = $sg_text; }
    $sg_tab = 'tab-test';
}

/* ================= Einstellungen speichern =================
   Beanstandungen werden GESAMMELT, nicht ueberschrieben. */
if ($sg_post && isset($_POST['speichern'])) {
    $sg_cfg = sg_config();

    /* Die Adresse ist Rechner und Port - KEIN Pfad.
     *
     * Bis 0.9.0 liess das Muster einen beliebigen Pfad zu ((/\S*)?). Das
     * hatte zwei Folgen, und die zweite ist die haeufigere:
     *
     * 1. sg_rpc() haengt selbst '/api/v1/rpc' an. Wer die Adresse aus der
     *    Anleitung von signal-cli abschreibt und dabei den Pfad mitnimmt,
     *    bekommt 'http://127.0.0.1:8095/api/v1/rpc/api/v1/rpc' - und eine
     *    Fehlermeldung, die nicht sagt warum.
     * 2. Ein Pfad im Feld liesse Abrufe an andere oertliche Dienste
     *    zusammenbauen. Das setzt zwar einen angemeldeten LoxBerry-Verwalter
     *    voraus, der ohnehin mehr darf - ein Eingabefeld, das nur eine Sache
     *    annimmt, ist trotzdem das bessere Feld.
     *
     * Ein versehentlich mitgeschriebener Pfad wird nicht abgewiesen, sondern
     * abgeschnitten: Das ist die haeufigste Eingabe, und eine Fehlermeldung
     * dafuer waere unfreundlich.
     */
    $sg_url = trim((string) (isset($_POST['rpc_url']) ? $_POST['rpc_url'] : ''));
    if (preg_match('#^(https?://[A-Za-z0-9\.\-]+(:[0-9]{1,5})?)(/.*)?$#', $sg_url, $sg_tr)) {
        $sg_url = $sg_tr[1];
    }
    if ($sg_url === '' || !preg_match('#^https?://[A-Za-z0-9\.\-]+(:[0-9]{1,5})?$#', $sg_url)) {
        $sg_fehler[] = sg_t('EINST.FEHLER_URL');
    } else {
        $sg_cfg['rpc_url'] = $sg_url;
    }

    $sg_k = preg_replace('/[^0-9+]/', '', (string) (isset($_POST['konto']) ? $_POST['konto'] : ''));
    if ($sg_k !== '' && !preg_match('/^\+[0-9]{6,20}$/', $sg_k)) {
        $sg_fehler[] = sg_t('EINST.FEHLER_KONTO');
    } else {
        $sg_cfg['konto'] = $sg_k;
    }

    // Erlaubte Absender: eine je Zeile. Jede Zeile wird geprueft und die
    // fehlerhaften einzeln gemeldet - nicht die ganze Eingabe verworfen.
    $sg_roh = (string) (isset($_POST['erlaubt']) ? $_POST['erlaubt'] : '');
    $sg_liste = array();
    $sg_schlecht = array();
    foreach (preg_split('/[\r\n,;]+/', $sg_roh) as $sg_z) {
        $sg_z = preg_replace('/[^0-9+]/', '', trim($sg_z));
        if ($sg_z === '') { continue; }
        if (preg_match('/^\+[0-9]{6,20}$/', $sg_z)) { $sg_liste[] = $sg_z; }
        else { $sg_schlecht[] = $sg_z; }
    }
    if ($sg_schlecht) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_NUMMER'), sg_e(implode(', ', $sg_schlecht)));
    } else {
        $sg_cfg['erlaubt'] = array_values(array_unique($sg_liste));
    }

    // PIN: leeres Feld laesst die gespeicherte PIN unberuehrt, sonst waere
    // sie nach jedem Speichern weg - sie wird ja nicht angezeigt.
    if (isset($_POST['pin']) && (string) $_POST['pin'] !== '') {
        $sg_pin = preg_replace('/[^0-9A-Za-z]/', '', (string) $_POST['pin']);
        if (strlen($sg_pin) < 4) {
            $sg_fehler[] = sg_t('EINST.FEHLER_PIN_KURZ');
        } else {
            /* Die PIN wird als Hash abgelegt, nicht im Klartext.
             * Sie steht sonst lesbar in einer Datei, die auch die Zweitschrift
             * mitfuehrt - und ein Klartext-Geheimnis ist eines, das man
             * versehentlich weitergibt. */
            $sg_cfg['pin_hash'] = password_hash($sg_pin, PASSWORD_DEFAULT);
            $sg_cfg['pin'] = '';
        }
    }
    if (!empty($_POST['pin_loeschen'])) { $sg_cfg['pin'] = ''; $sg_cfg['pin_hash'] = ''; }
    // Altbestand: eine noch im Klartext gespeicherte PIN beim ersten
    // Speichern still in einen Hash umschreiben.
    if ((string) $sg_cfg['pin'] !== '' && (string) $sg_cfg['pin_hash'] === '') {
        $sg_cfg['pin_hash'] = password_hash((string) $sg_cfg['pin'], PASSWORD_DEFAULT);
        $sg_cfg['pin'] = '';
    }

    $sg_br = (int) (isset($_POST['bremse']) ? $_POST['bremse'] : 10);
    if ($sg_br < 1 || $sg_br > 60) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_t('EINST.L_BREMSE'), 1, 60);
    } else {
        $sg_cfg['bremse'] = $sg_br;
    }

    /* mqtt_ein und mqtt_topic werden hier NICHT mehr angefasst: sie
     * wohnen im Reiter MQTT und haben dort ein eigenes Formular. Die
     * Konfiguration kommt aus sg_config(), die Werte ueberleben also
     * unveraendert. */

    $sg_cfg['stille'] = isset($_POST['stille']) ? 1 : 0;
    $sg_cfg['zustand_ein'] = isset($_POST['zustand_ein']) ? 1 : 0;
    $sg_cfg['audit'] = isset($_POST['audit']) ? 1 : 0;
    $sg_cfg['herzschlag'] = isset($_POST['herzschlag']) ? 1 : 0;

    // PIN-Sperre
    $sg_pv = (int) (isset($_POST['pin_versuche']) ? $_POST['pin_versuche'] : 3);
    if ($sg_pv < 1 || $sg_pv > 10) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_t('EINST.L_PIN_VERSUCHE'), 1, 10);
    } else { $sg_cfg['pin_versuche'] = $sg_pv; }
    $sg_ps = (int) (isset($_POST['pin_sperre']) ? $_POST['pin_sperre'] : 15);
    if ($sg_ps < 1 || $sg_ps > 1440) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_t('EINST.L_PIN_SPERRE'), 1, 1440);
    } else { $sg_cfg['pin_sperre'] = $sg_ps; }

    // Gruppen-Kennung: signal-cli gibt sie als Base64 aus.
    $sg_gr = trim((string) (isset($_POST['gruppe']) ? $_POST['gruppe'] : ''));
    if ($sg_gr !== '' && !preg_match('#^[A-Za-z0-9+/=_\-]{10,}$#', $sg_gr)) {
        $sg_fehler[] = sg_t('EINST.FEHLER_GRUPPE');
    } else { $sg_cfg['gruppe'] = $sg_gr; }

    // Nachtruhe: entweder beide Zeiten oder keine.
    $sg_nv = trim((string) (isset($_POST['nacht_von']) ? $_POST['nacht_von'] : ''));
    $sg_nb = trim((string) (isset($_POST['nacht_bis']) ? $_POST['nacht_bis'] : ''));
    $sg_zeitform = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    if ($sg_nv === '' && $sg_nb === '') {
        $sg_cfg['nacht_von'] = ''; $sg_cfg['nacht_bis'] = '';
    } elseif (preg_match($sg_zeitform, $sg_nv) && preg_match($sg_zeitform, $sg_nb) && $sg_nv !== $sg_nb) {
        $sg_cfg['nacht_von'] = $sg_nv; $sg_cfg['nacht_bis'] = $sg_nb;
    } else {
        $sg_fehler[] = sg_t('EINST.FEHLER_NACHT');
    }

    $sg_qt = (int) (isset($_POST['quittung_takt']) ? $_POST['quittung_takt'] : 5);
    if ($sg_qt < 1 || $sg_qt > 120) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_t('EINST.L_QUITTUNG_TAKT'), 1, 120);
    } else { $sg_cfg['quittung_takt'] = $sg_qt; }
    $sg_qm = (int) (isset($_POST['quittung_max']) ? $_POST['quittung_max'] : 3);
    if ($sg_qm < 0 || $sg_qm > 20) {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_BEREICH'), sg_t('EINST.L_QUITTUNG_MAX'), 0, 20);
    } else { $sg_cfg['quittung_max'] = $sg_qm; }

    /* GESPEICHERT WIRD AUCH DANN, WENN EINZELNE FELDER BEANSTANDET SIND.
     *
     * Bis 0.9.11 stand hier "if (!$sg_fehler)". Eine halb ausgefuellte Zeile
     * in der Weissliste verhinderte damit das Speichern ALLER Felder - der
     * Benutzer aenderte die Bremse, bekam eine Meldung ueber eine Rufnummer
     * und wunderte sich, warum die Bremse alt blieb. Genau dieser Fehler
     * steht in REGELN_1 als eigener Punkt: melden ist richtig, blockieren
     * nicht. Die beanstandeten Felder behalten ihren alten Wert, alle
     * uebrigen werden uebernommen. */
    if (sg_config_write($sg_cfg)) {
        $sg_meldungen[] = $sg_fehler ? sg_t('EINST.GESPEICHERT_TEIL') : sg_t('EINST.GESPEICHERT');
        sg_log('Einstellungen gespeichert' . ($sg_fehler ? ' (mit Beanstandungen)' : ''));
    } else {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']));
    }
    $sg_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. */
if ($sg_post && isset($_POST['save_mqtt'])) {
    $sg_mcfg = sg_config();
    $sg_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $sg_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($sg_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $sg_mtopic)) {
        $sg_fehler[] = sg_t('EINST.FEHLER_TOPIC');
    } else {
        $sg_mcfg['mqtt_topic'] = trim($sg_mtopic, '/');
    }
    if (!$sg_fehler) {
        if (sg_config_write($sg_mcfg)) {
            $sg_meldungen[] = sg_t('EINST.GESPEICHERT');
        }
    }
    $sg_tab = 'tab-mqtt';
}

/* ================= Befehlstabelle speichern ================= */
if ($sg_post && isset($_POST['befehle_speichern'])) {
    $sg_cfg = sg_config();
    $sg_neu = array();
    $sg_gesehen = array();
    $sg_reserviert = array('hilfe', 'help', '?', 'status', 'zustand', 'ja', 'nein', 'yes', 'no', 'ok');
    for ($sg_i = 0; $sg_i < SG_BEFEHLE; $sg_i++) {
        $sg_g = function ($feld, $def = '') use ($sg_i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            return isset($a[$sg_i]) ? $a[$sg_i] : $def;
        };
        $sg_wort = sg_klein(trim(preg_replace('/\s+/', ' ',
            preg_replace('/[\x00-\x1F\x7F]/', '', (string) $sg_g('b_wort')))));
        $sg_stufe = (string) $sg_g('b_stufe', 'sofort');
        $sg_wart = (string) $sg_g('b_wert_art', 'fest');
        $sg_b = array(
            'aktiv' => (int) $sg_g('b_aktiv', 0) ? 1 : 0,
            'wort' => $sg_wort,
            'thema' => preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $sg_g('b_thema')),
            'wert' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $sg_g('b_wert', '1'))),
            'wert_art' => in_array($sg_wart, array('fest', 'zahl'), true) ? $sg_wart : 'fest',
            'min' => (int) $sg_g('b_min', 0),
            'max' => (int) $sg_g('b_max', 100),
            'stufe' => in_array($sg_stufe, array('sofort', 'rueckfrage', 'pin'), true) ? $sg_stufe : 'sofort',
            'absender' => trim((string) $sg_g('b_absender')),
            'zweit' => trim((string) $sg_g('b_zweit')),
            'antwort' => trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $sg_g('b_antwort'))),
        );
        // Rufnummern in den beiden neuen Feldern werden GEPRUEFT, nicht
        // zurechtgebogen - eine stillschweigend verstuemmelte Nummer waere
        // eine Berechtigung, die niemand mehr nachvollziehen kann.
        if ($sg_b['absender'] !== '') {
            $sg_liste2 = array();
            foreach (preg_split('/[\s,;]+/', $sg_b['absender']) as $sg_nr) {
                if ($sg_nr === '') { continue; }
                if (preg_match('/^\+[0-9]{6,20}$/', $sg_nr)) { $sg_liste2[] = $sg_nr; }
                else { $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_ABSENDER'), sg_e($sg_nr), $sg_i + 1); }
            }
            $sg_b['absender'] = implode(',', $sg_liste2);
        }
        if ($sg_b['zweit'] !== '' && !preg_match('/^\+[0-9]{6,20}$/', $sg_b['zweit'])) {
            $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_ZWEIT'), sg_e($sg_b['zweit']), $sg_i + 1);
            $sg_b['zweit'] = '';
        }
        if ($sg_b['aktiv'] && $sg_b['wert_art'] === 'zahl' && $sg_b['max'] <= $sg_b['min']) {
            $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_BEREICH'), $sg_i + 1);
        }
        if ($sg_b['aktiv'] && $sg_b['wort'] !== '') {
            if (in_array($sg_b['wort'], $sg_reserviert, true)) {
                $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_RESERVIERT'), sg_e($sg_b['wort']), $sg_i + 1);
            }
            if (isset($sg_gesehen[$sg_b['wort']])) {
                $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_DOPPELT'), sg_e($sg_b['wort']), $sg_i + 1);
            }
            $sg_gesehen[$sg_b['wort']] = 1;
            if ($sg_b['thema'] === '') {
                $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_THEMA'), $sg_i + 1);
            }
            if ($sg_b['stufe'] === 'pin' && !sg_pin_gesetzt($sg_cfg)) {
                $sg_fehler[] = sprintf(sg_t('BEF.FEHLER_KEINE_PIN'), sg_e($sg_b['wort']));
            }
        }
        $sg_neu[$sg_i] = $sg_b;
    }
    /* Auch hier gilt: melden, nicht blockieren. Bis 0.9.11 verwarf ein
     * doppeltes Wort in Zeile 17 die Bearbeitung aller zwanzig Zeilen. */
    $sg_cfg['befehle'] = $sg_neu;
    if (sg_config_write($sg_cfg)) {
        $sg_meldungen[] = $sg_fehler ? sg_t('BEF.GESPEICHERT_TEIL') : sg_t('BEF.GESPEICHERT');
        sg_log('Befehlstabelle gespeichert' . ($sg_fehler ? ' (mit Beanstandungen)' : ''));
    } else {
        $sg_fehler[] = sprintf(sg_t('EINST.FEHLER_SPEICHERN'), sg_e(sg_paths()['config']));
    }
    $sg_tab = 'tab-befehle';
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

<h2><?= sg_e(sg_t('EINST.H_SPERRE')) ?></h2>
<div class="sm-warnung"><?= sg_t('EINST.SPERRE_TEXT') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
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
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="link_fertig" value="1">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('EINST.K_LINK_FERTIG')) ?></button>
  </form>
</div>
<?php } else { ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="link_start" value="1">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" <?= $sg_lebt ? '' : 'disabled' ?>><?= sg_e(sg_t('EINST.K_LINK_START')) ?></button>
</form>
</div>
<?php if (!$sg_lebt) { ?><div class="sm-warnung"><?= sg_t('EINST.LINK_KEIN_DIENST') ?></div><?php } ?>
<?php } ?>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= sg_e(sg_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-row">
  <div class="sm-feld">
    <label for="rpc_url"><?= sg_e(sg_t('EINST.L_RPC')) ?></label>
    <input data-role="none" type="text" id="rpc_url" name="rpc_url" value="<?= sg_e($sg_cfg['rpc_url']) ?>" placeholder="http://127.0.0.1:8095">
    <div class="sm-hilfe"><?= sg_t('EINST.H_RPC') ?></div>
  </div>
  <div class="sm-feld">
    <label for="konto"><?= sg_e(sg_t('EINST.L_KONTO')) ?></label>
    <input data-role="none" type="text" id="konto" name="konto" value="<?= sg_e($sg_cfg['konto']) ?>" placeholder="+49...">
    <div class="sm-hilfe"><?= $sg_konten ? sprintf(sg_t('EINST.H_KONTO_GEFUNDEN'), sg_e(implode(', ', $sg_konten))) : sg_t('EINST.H_KONTO') ?></div>
  </div>
</div>

<h2><?= sg_e(sg_t('EINST.H_ABSICHERUNG')) ?></h2>
<div class="sm-warnung"><?= sg_t('EINST.ABSICHERUNG_TEXT') ?></div>
<div class="sm-feld">
  <label for="erlaubt"><?= sg_e(sg_t('EINST.L_ERLAUBT')) ?></label>
  <textarea data-role="none" id="erlaubt" name="erlaubt" rows="4" style="width:100%;max-width:520px;" placeholder="+491701234567"><?= sg_e(implode("\n", $sg_cfg['erlaubt'])) ?></textarea>
  <div class="sm-hilfe"><?= sg_t('EINST.H_ERLAUBT') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="stille" value="1" <?= !empty($sg_cfg['stille']) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_STILLE')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_STILLE') ?></div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="pin"><?= sg_e(sg_t('EINST.L_PIN')) ?></label>
    <input data-role="none" type="password" id="pin" name="pin" value="" placeholder="<?= sg_e((string) $sg_cfg['pin'] !== '' ? sg_t('EINST.P_GESETZT') : sg_t('EINST.P_LEER')) ?>">
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN') ?></div>
    <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
      <input data-role="none" type="checkbox" name="pin_loeschen" value="1"> <?= sg_e(sg_t('EINST.L_PIN_LOESCHEN')) ?>
    </label>
  </div>
  <div class="sm-feld">
    <label for="bremse"><?= sg_e(sg_t('EINST.L_BREMSE')) ?></label>
    <input data-role="none" type="number" id="bremse" name="bremse" value="<?= (int) $sg_cfg['bremse'] ?>" min="1" max="60">
    <div class="sm-hilfe"><?= sg_t('EINST.H_BREMSE') ?></div>
  </div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="pin_versuche"><?= sg_e(sg_t('EINST.L_PIN_VERSUCHE')) ?></label>
    <input data-role="none" type="number" id="pin_versuche" name="pin_versuche" value="<?= (int) $sg_cfg['pin_versuche'] ?>" min="1" max="10">
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN_VERSUCHE') ?></div>
  </div>
  <div class="sm-feld">
    <label for="pin_sperre"><?= sg_e(sg_t('EINST.L_PIN_SPERRE')) ?></label>
    <input data-role="none" type="number" id="pin_sperre" name="pin_sperre" value="<?= (int) $sg_cfg['pin_sperre'] ?>" min="1" max="1440">
    <div class="sm-hilfe"><?= sg_t('EINST.H_PIN_SPERRE') ?></div>
  </div>
</div>

<h2><?= sg_e(sg_t('EINST.H_WEITERES')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="zustand_ein" value="1" <?= !empty($sg_cfg['zustand_ein']) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_ZUSTAND')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_ZUSTAND') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="audit" value="1" <?= !empty($sg_cfg['audit']) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_AUDIT')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_AUDIT') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="herzschlag" value="1" <?= !empty($sg_cfg['herzschlag']) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_HERZSCHLAG')) ?>
  </label>
  <div class="sm-hilfe"><?= sg_t('EINST.H_HERZSCHLAG') ?></div>
</div>

<h2><?= sg_e(sg_t('EINST.H_MELDEWEGE')) ?></h2>
<div class="sm-feld">
  <label for="gruppe"><?= sg_e(sg_t('EINST.L_GRUPPE')) ?></label>
  <input data-role="none" type="text" id="gruppe" name="gruppe" value="<?= sg_e($sg_cfg['gruppe']) ?>">
  <div class="sm-hilfe"><?= sg_t('EINST.H_GRUPPE') ?></div>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="nacht_von"><?= sg_e(sg_t('EINST.L_NACHT_VON')) ?></label>
    <input data-role="none" type="text" id="nacht_von" name="nacht_von" value="<?= sg_e($sg_cfg['nacht_von']) ?>" placeholder="22:00">
  </div>
  <div class="sm-feld">
    <label for="nacht_bis"><?= sg_e(sg_t('EINST.L_NACHT_BIS')) ?></label>
    <input data-role="none" type="text" id="nacht_bis" name="nacht_bis" value="<?= sg_e($sg_cfg['nacht_bis']) ?>" placeholder="07:00">
  </div>
</div>
<div class="sm-hilfe"><?= sg_t('EINST.H_NACHT') ?></div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="quittung_takt"><?= sg_e(sg_t('EINST.L_QUITTUNG_TAKT')) ?></label>
    <input data-role="none" type="number" id="quittung_takt" name="quittung_takt" value="<?= (int) $sg_cfg['quittung_takt'] ?>" min="1" max="120">
  </div>
  <div class="sm-feld">
    <label for="quittung_max"><?= sg_e(sg_t('EINST.L_QUITTUNG_MAX')) ?></label>
    <input data-role="none" type="number" id="quittung_max" name="quittung_max" value="<?= (int) $sg_cfg['quittung_max'] ?>" min="0" max="20">
  </div>
</div>
<div class="sm-hilfe"><?= sg_t('EINST.H_QUITTUNG') ?></div>
<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Befehle ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-befehle' ? ' sm-active' : '' ?>" id="tab-befehle">
<h2><?= sg_e(sg_t('BEF.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sg_t('BEF.ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= sg_t('BEF.STUFEN') ?></div>

<form action="index.php" method="post" autocomplete="off">
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
  <td style="text-align:center;"><input data-role="none" type="checkbox" name="b_aktiv[<?= $sg_i ?>]" value="1" <?= !empty($sg_b['aktiv']) ? 'checked' : '' ?>></td>
  <td><input data-role="none" type="text" name="b_wort[<?= $sg_i ?>]" value="<?= sg_e($sg_b['wort']) ?>" placeholder="<?= $sg_i === 0 ? 'licht an' : '' ?>"></td>
  <td><input data-role="none" type="text" name="b_thema[<?= $sg_i ?>]" value="<?= sg_e($sg_b['thema']) ?>" placeholder="<?= $sg_i === 0 ? 'licht/wohnen' : '' ?>"></td>
  <td>
    <select data-role="none" name="b_wert_art[<?= $sg_i ?>]">
<?php foreach (array('fest', 'zahl') as $sg_wa) { ?>
      <option value="<?= $sg_wa ?>"<?= $sg_b['wert_art'] === $sg_wa ? ' selected' : '' ?>><?= sg_e(sg_t('BEF.WERT_' . strtoupper($sg_wa))) ?></option>
<?php } ?>
    </select>
    <input data-role="none" type="text" name="b_wert[<?= $sg_i ?>]" value="<?= sg_e($sg_b['wert']) ?>" placeholder="1">
    <span class="sm-hilfe" style="display:block;">
      <input data-role="none" type="number" name="b_min[<?= $sg_i ?>]" value="<?= (int) $sg_b['min'] ?>" style="width:45%;">
      <input data-role="none" type="number" name="b_max[<?= $sg_i ?>]" value="<?= (int) $sg_b['max'] ?>" style="width:45%;">
    </span>
  </td>
  <td><select data-role="none" name="b_stufe[<?= $sg_i ?>]">
<?php foreach (array('sofort', 'rueckfrage', 'pin') as $sg_s) { ?>
    <option value="<?= $sg_s ?>"<?= $sg_b['stufe'] === $sg_s ? ' selected' : '' ?>><?= sg_e(sg_t('BEF.STUFE_' . strtoupper($sg_s))) ?></option>
<?php } ?>
  </select></td>
  <td><input data-role="none" type="text" name="b_absender[<?= $sg_i ?>]" value="<?= sg_e($sg_b['absender']) ?>" placeholder="<?= sg_e(sg_t('BEF.P_ALLE')) ?>"></td>
  <td><input data-role="none" type="text" name="b_zweit[<?= $sg_i ?>]" value="<?= sg_e($sg_b['zweit']) ?>" placeholder="+49..."></td>
  <td><input data-role="none" type="text" name="b_antwort[<?= $sg_i ?>]" value="<?= sg_e($sg_b['antwort']) ?>" placeholder="<?= sg_e(sg_t('BEF.P_ANTWORT')) ?>"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= sg_t('BEF.SPALTEN_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sg_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= sg_e(sg_t('BEF.H_EINGEBAUT')) ?></h3>
<p class="sm-hilfe"><?= sg_t('BEF.EINGEBAUT_TEXT') ?></p>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2><?= sg_e(sg_t('MQTT.H_EINSTELLUNG')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($sg_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= sg_e(sg_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= sg_e(sg_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= sg_e($sg_cfg['mqtt_topic']) ?>" placeholder="signalbot">
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
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
<?= sg_t('MQTT.ABO_WARNUNG') ?>
</div>

<h3><?= sg_e(sg_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= sg_t('MQTT.THEMEN_TEXT') ?></p>
<table class="sm-tbl">
<tr><th><?= sg_e(sg_t('MQTT.T_THEMA')) ?></th><th><?= sg_e(sg_t('MQTT.T_WERT')) ?></th><th><?= sg_e(sg_t('MQTT.T_BEFEHL')) ?></th></tr>
<?php $sg_leer = true; foreach ($sg_cfg['befehle'] as $sg_b) {
    if (empty($sg_b['aktiv']) || $sg_b['wort'] === '' || $sg_b['thema'] === '') { continue; }
    $sg_leer = false; ?>
<tr><td><span class="sm-mono"><?= sg_e($sg_cfg['mqtt_topic']) ?>/<?= sg_e($sg_b['thema']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_b['wert']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_b['wort']) ?></span></td></tr>
<?php } ?>
<?php if ($sg_leer) { ?><tr><td colspan="3"><?= sg_t('MQTT.KEINE_BEFEHLE') ?></td></tr><?php } ?>
<?php if (!empty($sg_cfg['herzschlag'])) { ?>
<tr><td><span class="sm-mono"><?= sg_e($sg_cfg['mqtt_topic']) ?>/online</span></td>
    <td><span class="sm-mono"><?= sg_e(sg_t('MQTT.W_ZEITSTEMPEL')) ?></span></td>
    <td><?= sg_t('MQTT.T_ONLINE') ?></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= sg_e(sg_t('LOX.H_RICHTUNGEN')) ?></h2>
<div class="sm-hinweis"><?= sg_t('LOX.RICHTUNGEN_TEXT') ?></div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S0_T')) ?></b><br>
<?= sg_t('LOX.S0') ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.SABO_T')) ?></b><br>
<?= sg_t('LOX.SABO') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/#</div>
<?= sg_t('MQTT.ABO_WARNUNG') ?>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S1_T')) ?></b><br>
<?= sg_t('LOX.S1') ?>
<div class="sm-pre"><?= sg_e($sg_cfg['mqtt_topic']) ?>/&lt;Thema aus der Befehlstabelle&gt;</div>
<?= sg_t('LOX.S1_IMPULS') ?>
<table class="sm-tbl">
<tr><th><?= sg_e(sg_t('MQTT.T_THEMA')) ?></th><th><?= sg_e(sg_t('MQTT.T_WERT')) ?></th><th><?= sg_e(sg_t('MQTT.T_BEFEHL')) ?></th></tr>
<?php $sg_leer2 = true; foreach ($sg_cfg['befehle'] as $sg_b2) {
    if (empty($sg_b2['aktiv']) || $sg_b2['wort'] === '' || $sg_b2['thema'] === '') { continue; }
    $sg_leer2 = false; ?>
<tr><td><span class="sm-mono"><?= sg_e($sg_cfg['mqtt_topic']) ?>/<?= sg_e($sg_b2['thema']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_b2['wert_art'] === 'zahl'
        ? (int) $sg_b2['min'] . '..' . (int) $sg_b2['max'] : $sg_b2['wert']) ?></span></td>
    <td><span class="sm-mono"><?= sg_e($sg_b2['wort']) ?></span></td></tr>
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
<div class="sm-pre"><?= sg_e(sg_endpunkt('sperren')) ?>
<?= sg_e(sg_endpunkt('entsperren')) ?></div>
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sg_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_t('LOX.K_VORLAGE')) ?></button>
</form>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage_out" value="1">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_t('LOX.K_VORLAGE_OUT')) ?></button>
</form>
</div>

<div class="sm-step"><b><?= sg_e(sg_t('LOX.S4_T')) ?></b><br>
<?= sg_t('LOX.S4') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= sg_e(sg_t('LOX.T_BAUSTEIN')) ?></th><th><?= sg_e(sg_t('LOX.T_NAME')) ?></th><th><?= sg_e(sg_t('LOX.T_PARAMETER')) ?></th><th><?= sg_e(sg_t('LOX.T_VERBINDEN')) ?></th></tr>
<tr><td>1</td><td><?= sg_t('BAUSTEIN.B1_TYP') ?></td><td><span class="sm-mono">Signal Befehl</span></td><td><?= sg_t('BAUSTEIN.B1_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B1_VERB') ?></td></tr>
<tr><td>2</td><td><?= sg_t('BAUSTEIN.B2_TYP') ?></td><td><span class="sm-mono">Alarmanlage</span></td><td><?= sg_t('BAUSTEIN.B2_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B2_VERB') ?></td></tr>
<tr><td>3</td><td><?= sg_t('BAUSTEIN.B3_TYP') ?></td><td><span class="sm-mono">Signal Meldung</span></td><td><?= sg_t('BAUSTEIN.B3_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B3_VERB') ?></td></tr>
<tr><td>4</td><td><?= sg_t('BAUSTEIN.B4_TYP') ?></td><td><span class="sm-mono">Zustand melden</span></td><td><?= sg_t('BAUSTEIN.B4_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B4_VERB') ?></td></tr>
<tr><td>5</td><td><?= sg_t('BAUSTEIN.B5_TYP') ?></td><td><span class="sm-mono">Bot antwortet nicht</span></td><td><?= sg_t('BAUSTEIN.B5_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B5_VERB') ?></td></tr>
<tr><td>6</td><td><?= sg_t('BAUSTEIN.B6_TYP') ?></td><td><span class="sm-mono">Signal Bot sperren</span></td><td><?= sg_t('BAUSTEIN.B6_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B6_VERB') ?></td></tr>
<tr><td>7</td><td><?= sg_t('BAUSTEIN.B7_TYP') ?></td><td><span class="sm-mono">Meldung unquittiert</span></td><td><?= sg_t('BAUSTEIN.B7_PARAM') ?></td><td><?= sg_t('BAUSTEIN.B7_VERB') ?></td></tr>
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
<h2><?= sg_e(sg_t('TEST.H_SELBSTTEST')) ?></h2>
<p class="sm-hilfe"><?= sg_t('TEST.SELBSTTEST_TEXT') ?></p>
<?php
$sg_pr = sg_pruefungen();
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sg_t('LEGENDE.LESEN') ?></span></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="trocken">
  <div class="sm-feld">
    <label for="trockentext"><?= sg_e(sg_t('TEST.L_TROCKEN')) ?></label>
    <input data-role="none" type="text" id="trockentext" name="trockentext" value="hilfe">
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_t('TEST.K_TROCKEN')) ?></button>
  </div>
</form>

<h3><?= sg_e(sg_t('TEST.H_KNOEPFE')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= sg_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= sg_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?= sg_e($sg_plugin) ?>/index.php?token=<?= sg_e($sg_cfg['aktionstoken']) ?>&amp;aktion=status" target="_blank"><?= sg_e(sg_t('TEST.K_STATUS')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="/plugins/<?= sg_e($sg_plugin) ?>/index.php?token=<?= sg_e($sg_cfg['aktionstoken']) ?>&amp;selftest=1" target="_blank"><?= sg_e(sg_t('TEST.K_SELFTEST')) ?></a>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="start">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_t('TEST.K_START')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="<?= sg_dienst_autostart() === 'enabled' ? 'disable' : 'enable' ?>">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sg_e(sg_dienst_autostart() === 'enabled' ? sg_t('TEST.K_AUTOSTART_AUS') : sg_t('TEST.K_AUTOSTART_EIN')) ?></button></form>
</div>
<div class="sm-knopfreihe">
<a data-role="none" class="sm-btn sm-b-technik" href="<?= sg_e($sg_cfg['rpc_url']) ?>/api/v1/check" target="_blank"><?= sg_e(sg_t('TEST.K_CHECK')) ?></a>
</div>

<h3><?= sg_e(sg_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= sg_t('TEST.SCHALTEN_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="probe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_PROBE')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="restart">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_RESTART')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="stop">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_STOP')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="token">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sg_e(sg_t('TEST.K_TOKEN')) ?></button></form>
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $sg_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sg_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
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
