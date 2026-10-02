<?php
/**
 * Signal Bot fuer LoxBerry - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne dass jemand eine
 * Nachricht schickt: traegt die Einrichtung?
 */

function sg_pruefzeile($stand, $frage, $antwort)
{
    return array((int) $stand, $frage, $antwort);
}

/**
 * Die Selbstpruefung. $mit_netz = true nur, wenn der Reiter Test serverseitig
 * der offene ist (Regeln/04): dann laufen die beiden Zeilen, die etwas
 * kosten - der Aufruf des eigenen Endpunkts und die MQTT-Probe.
 */
function sg_pruefungen($mit_netz = false)
{
    $cfg = sg_config();
    $z = array();

    /* ---- Die Kette bis Signal ---- */
    $vorhanden = trim(shell_exec('command -v signal-cli 2>/dev/null') ?: '') !== '';
    $z[] = sg_pruefzeile($vorhanden ? 1 : -1, sg_t('TEST.F_CLI'),
        $vorhanden ? sg_t('TEST.A_CLI_DA') : sg_t('TEST.A_CLI_FEHLT'));

    // Java: signal-cli verlangt Fassung 25. Das wird geprueft, nicht
    // angenommen - eine zu alte Laufzeit ist der haeufigste Grund, warum
    // der Dienst startet und sofort wieder faellt.
    $jv = 0;
    $ja = shell_exec('java -version 2>&1 | head -1') ?: '';
    if (preg_match('/"(\d+)/', $ja, $m)) { $jv = (int) $m[1]; }
    if ($jv === 0) {
        $z[] = sg_pruefzeile($vorhanden ? 0 : -1, sg_t('TEST.F_JAVA'), sg_t('TEST.A_JAVA_KEINE'));
    } elseif ($jv < 25) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_JAVA'), sprintf(sg_t('TEST.A_JAVA_ALT'), $jv));
    } else {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_JAVA'), sprintf(sg_t('TEST.A_JAVA_OK'), $jv));
    }

    // Die native Bibliothek fehlt auf ARM. Das ist keine Vermutung, sondern
    // steht so in den Systemanforderungen von signal-cli.
    $bogen = trim(shell_exec('dpkg --print-architecture 2>/dev/null') ?: '');
    if ($bogen !== '' && $bogen !== 'amd64') {
        /* Auf ARM entscheidet die nachgereichte Bibliothek. Sie liegt im
         * Ordner nativ des Plugins; der Dienst findet sie ueber
         * LD_LIBRARY_PATH, das postroot.sh in die Unit schreibt. */
        $nativ = sg_nativ_datei();
        if (is_file($nativ)) {
            $z[] = sg_pruefzeile(1, sg_t('TEST.F_BOGEN'),
                sprintf(sg_t('TEST.A_BOGEN_NATIV'), sg_e($bogen),
                        number_format(filesize($nativ) / 1048576, 1, ',', '.')));
        } else {
            $sg_f = sg_libsignal_fassung();
            $z[] = sg_pruefzeile(-1, sg_t('TEST.F_BOGEN'),
                sprintf(sg_t('TEST.A_BOGEN_ARM'), sg_e($bogen))
                . ' ' . sprintf(sg_t('TEST.A_BOGEN_ORT'),
                    $sg_f !== '' ? sg_e($sg_f) : sg_t('TEST.A_BOGEN_UNBEKANNT'), sg_e($bogen),
                    sg_e(sg_nativ_seite()), sg_e(sg_nativ_ordner())));
        }
    } elseif ($bogen !== '') {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_BOGEN'), sprintf(sg_t('TEST.A_BOGEN_OK'), sg_e($bogen)));
    }

    $laeuft = sg_dienst_laeuft();
    $z[] = sg_pruefzeile($laeuft ? 1 : ($vorhanden ? 0 : -1), sg_t('TEST.F_DIENST'),
        $laeuft ? sg_t('TEST.A_DIENST_LAEUFT') : sg_t('TEST.A_DIENST_TOT'));

    // Startet der Dienst beim Hochfahren mit? Ohne Autostart laeuft der Bot
    // nach einem Stromausfall erst wieder, wenn ihn jemand von Hand startet -
    // oder bis die Selbstheilung greift.
    $auto = sg_dienst_autostart();
    if ($auto === 'enabled') {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_AUTOSTART'), sg_t('TEST.A_AUTOSTART_EIN'));
    } elseif ($auto === 'unbekannt') {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_AUTOSTART'), sg_t('TEST.A_AUTOSTART_UNBEKANNT'));
    } else {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_AUTOSTART'), sg_t('TEST.A_AUTOSTART_AUS'));
    }

    $lebt = sg_daemon_lebt();
    $z[] = sg_pruefzeile($lebt ? 1 : 0, sg_t('TEST.F_RPC'),
        $lebt ? sprintf(sg_t('TEST.A_RPC_OK'), sg_e($cfg['rpc_url']))
              : sprintf(sg_t('TEST.A_RPC_TOT'), sg_e($cfg['rpc_url'])));

    /* ---- Konto ----
       Ohne Antwort von signal-cli ist nicht feststellbar, ob das Konto
       verknuepft ist: grau statt Haken (U9). Bis 0.9.25 stand hier ein Haken
       "Konto ... ist verknuepft", obwohl nichts gemessen war. */
    $konten = $lebt ? sg_konten() : array();
    if (!$lebt && (string) $cfg['konto'] !== '') {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_KONTO'),
            sprintf(sg_t('TEST.A_KONTO_UNGEMESSEN'), sg_maske($cfg['konto'])));
    } elseif ((string) $cfg['konto'] === '') {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_KONTO'),
            $konten ? sprintf(sg_t('TEST.A_KONTO_NICHT_GEWAEHLT'), sg_e(implode(', ', array_map('sg_maske', $konten))))
                    : sg_t('TEST.A_KONTO_KEINS'));
    } elseif ($konten && !in_array($cfg['konto'], $konten, true)) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_KONTO'),
            sprintf(sg_t('TEST.A_KONTO_UNBEKANNT'), sg_maske($cfg['konto'])));
    } else {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_KONTO'),
            sprintf(sg_t('TEST.A_KONTO_OK'), sg_maske($cfg['konto'])));
    }

    /* ---- Der Bot selbst ---- */
    $botlaeuft = sg_bot_laeuft();
    $z[] = sg_pruefzeile($botlaeuft ? 1 : 0, sg_t('TEST.F_BOT'),
        $botlaeuft ? sg_t('TEST.A_BOT_LAEUFT') : sg_t('TEST.A_BOT_TOT'));
    /* Arbeitet er noch? (Pflichtzeile, Entscheidung 4) - ein Prozess kann
       dastehen und nichts tun. Ueber einen Bot, der nicht laeuft, wird kein
       Herzschlag beurteilt. */
    $alter = sg_herz_alter();
    if (!$botlaeuft) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_HERZ'), sg_t('TEST.A_HERZ_KEIN_BOT'));
    } elseif ($alter >= 0 && $alter <= SG_HERZ_GRENZE) {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_HERZ'), sprintf(sg_t('TEST.A_HERZ_OK'), $alter, SG_HERZ_GRENZE));
    } else {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_HERZ'), $alter < 0 ? sg_t('TEST.A_HERZ_NIE')
            : sprintf(sg_t('TEST.A_HERZ_ALT'), $alter, SG_HERZ_GRENZE));
    }

    /* ---- Absicherung: die drei Schichten ---- */
    $n = count($cfg['erlaubt']);
    $z[] = sg_pruefzeile($n > 0 ? 1 : 0, sg_t('TEST.F_WEISSLISTE'),
        $n > 0 ? sprintf(sg_t('TEST.A_WEISSLISTE_OK'), $n) : sg_t('TEST.A_WEISSLISTE_LEER'));

    $mitpin = 0;
    $aktiv = 0;
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        $aktiv++;
        if ($b['stufe'] === 'pin') { $mitpin++; }
    }
    $z[] = sg_pruefzeile($aktiv > 0 ? 1 : -1, sg_t('TEST.F_BEFEHLE'),
        $aktiv > 0 ? sprintf(sg_t('TEST.A_BEFEHLE_OK'), $aktiv) : sg_t('TEST.A_BEFEHLE_KEINE'));

    /* sg_pin_gesetzt() kennt beide Formen (U8). Bis 0.9.25 wurde nur der
       Klartext gefragt, und seit 0.9.12 steht die PIN als Hash da: auf jeder
       Anlage mit PIN stand hier ein Kreuz. */
    if ($mitpin > 0 && !sg_pin_gesetzt($cfg)) {
        // Der gefaehrlichste Zustand ueberhaupt: ein Befehl ist als
        // PIN-pflichtig gekennzeichnet, aber es gibt keine PIN.
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_PIN'), sprintf(sg_t('TEST.A_PIN_FEHLT'), $mitpin));
    } elseif ($mitpin > 0) {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_PIN'), sprintf(sg_t('TEST.A_PIN_OK'), $mitpin));
    } else {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_PIN'), sg_t('TEST.A_PIN_UNGENUTZT'));
    }

    // Doppelte Befehlswoerter: der erste Treffer gewinnt, der zweite wird
    // nie erreicht - das faellt sonst erst im Betrieb auf.
    $woerter = array();
    $doppelt = array();
    foreach ($cfg['befehle'] as $b) {
        if (empty($b['aktiv']) || $b['wort'] === '') { continue; }
        if (isset($woerter[$b['wort']])) { $doppelt[] = $b['wort']; }
        $woerter[$b['wort']] = 1;
    }
    // Auch gegen die eingebauten Woerter pruefen - aus DERSELBEN Liste wie das
    // Formular (U10; bis 0.9.25 fehlten hier yes, no und ok).
    foreach (array_keys($woerter) as $w) {
        if (sg_wort_reserviert($w)) { $doppelt[] = $w; }
    }
    $z[] = sg_pruefzeile($doppelt ? 0 : 1, sg_t('TEST.F_DOPPELT'),
        $doppelt ? sprintf(sg_t('TEST.A_DOPPELT'), sg_e(implode(', ', array_unique($doppelt))))
                 : sg_t('TEST.A_DOPPELT_KEINE'));

    /* ---- MQTT ----
       Ausgeschaltet ist eine Entscheidung, kein Fehler: grau (U11, Regeln/04). */
    $m = sg_mqtt_zustand();
    if (empty($cfg['mqtt_ein'])) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_MQTT'), sg_t('TEST.A_MQTT_AUS'));
    } elseif (!$m['gefunden']) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT'), sg_t('TEST.A_MQTT_KEIN_ABSCHNITT'));
    } elseif (!$m['udpport']) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT'), sg_t('TEST.A_MQTT_KEIN_PORT'));
    } elseif (!$m['autostart']) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT'), sg_t('TEST.A_MQTT_KEIN_AUTOSTART'));
    } else {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_MQTT'),
            sprintf(sg_t('TEST.A_MQTT_OK'), (int) $m['udpport'], sg_e($cfg['mqtt_topic'])));
    }
    /* Auf welchem Weg gehen Befehle hinaus? (SignalBot-q1) Gemessen wird die
       Anmeldung beim Broker mit derselben Funktion, die jeder Befehl benutzt
       (sg_broker_verbinden), nur bei offenem Reiter Test. Kreuz heisst: Befehle
       gehen ueber den UDP-Eingang des Gateways, unbestaetigt (Entscheidung 28). */
    if (!empty($cfg['mqtt_ein']) && $m['gefunden']) {
        if (!$mit_netz) {
            $z[] = sg_pruefzeile(-1, sg_t('TEST.F_MQTT_WEG'), sg_t('TEST.A_MQTT_PROBE_SPAETER'));
        } else {
            $sg_v = sg_broker_verbinden();
            if ($sg_v['s'] !== null) {
                sg_broker_trennen($sg_v['s']);
                $z[] = sg_pruefzeile(1, sg_t('TEST.F_MQTT_WEG'),
                    sprintf(sg_t('TEST.A_MQTT_WEG_BROKER'), sg_e($sg_v['ziel'])));
            } elseif ($m['udpport']) {
                $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT_WEG'),
                    sprintf(sg_t('TEST.A_MQTT_WEG_UDP'), sg_e($sg_v['ziel'] !== '' ? $sg_v['ziel'] : '-'),
                            sg_e(sg_broker_grund_anzeige($sg_v)), (int) $m['udpport']));
            } else {
                $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT_WEG'),
                    sprintf(sg_t('TEST.A_MQTT_WEG_KEINER'), sg_e(sg_broker_grund_anzeige($sg_v))));
            }
        }
    }
    /* Kommt eine Probe wirklich beim Broker an? (M7) Bis 0.9.25 wurde die
       Zeile oben gruen, sobald die general.json passte - gesendet oder
       empfangen wurde nichts. Gemessen wird nur bei offenem Reiter Test. */
    if (!empty($cfg['mqtt_ein']) && $m['udpport']) {
        if (!$mit_netz) {
            $z[] = sg_pruefzeile(-1, sg_t('TEST.F_MQTT_PROBE'), sg_t('TEST.A_MQTT_PROBE_SPAETER'));
        } else {
            list($st, $tx) = sg_mqtt_probe($cfg);
            $z[] = sg_pruefzeile($st, sg_t('TEST.F_MQTT_PROBE'), $tx);
        }
    }

    /* ---- Token ---- */
    $gut = preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken']) ? 1 : 0;
    $z[] = sg_pruefzeile($gut, sg_t('TEST.F_TOKEN'),
        $gut ? sg_t('TEST.A_TOKEN_OK') : sg_t('TEST.A_TOKEN_FEHLT'));

    /* ---- Kill-Schalter ----
       Der gefaehrlichste stille Zustand: der Bot ist gesperrt, und niemand
       weiss mehr, warum er nicht antwortet. */
    if (!empty($cfg['gesperrt'])) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_SPERRE'), sg_t('TEST.A_SPERRE_EIN'));
    } else {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_SPERRE'), sg_t('TEST.A_SPERRE_AUS'));
    }

    /* ---- Offene Meldungen ---- */
    $offen = sg_offene_meldungen();
    $z[] = sg_pruefzeile($offen > 0 ? 0 : 1, sg_t('TEST.F_OFFEN'),
        $offen > 0 ? sprintf(sg_t('TEST.A_OFFEN'), $offen) : sg_t('TEST.A_OFFEN_KEINE'));

    /* ---- Nachtruhe ---- */
    if ((string) $cfg['nacht_von'] === '') {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_NACHT'), sg_t('TEST.A_NACHT_AUS'));
    } else {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_NACHT'),
            sprintf(sg_t('TEST.A_NACHT_EIN'), sg_e($cfg['nacht_von']), sg_e($cfg['nacht_bis']),
                sg_nachtruhe($cfg) ? sg_t('TEST.A_NACHT_JETZT') : sg_t('TEST.A_NACHT_NICHT')));
    }

    /* ---- Die Loxone-Vorlagen ----
       Wohlgeformt oder nicht - das ist nicht verhandelbar, und der Anwender
       soll es hier erfahren und nicht erst in Loxone Config, wo er den Fehler
       bei sich sucht. */
    /* SimpleXML ist eine EIGENE Erweiterung (Debian: php<X.Y>-xml) und auf
     * einem LoxBerry nicht garantiert. Ohne diese Abfrage waere der Aufruf
     * ein "Call to undefined function" - und weil sg_pruefungen() bei JEDEM
     * Rendern laeuft, haette das die ganze Oberflaeche mit HTTP 500
     * erschlagen, nicht nur diese Pruefzeile. Dieselbe Klasse wie
     * socket_create() und mb_strtolower(): erst fragen, dann rufen. */
    if (!function_exists('simplexml_load_string')) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_XML'), sg_t('TEST.A_XML_UNPRUEFBAR'));
    } else {
        $xmlfehler = array();
        foreach (array('sg_vorlage', 'sg_vorlage_out') as $bau) {
            list($xname, $xinhalt) = $bau();
            $vorher = function_exists('libxml_use_internal_errors') ? libxml_use_internal_errors(true) : false;
            $ok = simplexml_load_string($xinhalt) !== false;
            if (function_exists('libxml_clear_errors')) { libxml_clear_errors(); }
            if (function_exists('libxml_use_internal_errors')) { libxml_use_internal_errors($vorher); }
            if (!$ok) { $xmlfehler[] = $xname; }
        }
        $z[] = sg_pruefzeile($xmlfehler ? 0 : 1, sg_t('TEST.F_XML'),
            $xmlfehler ? sprintf(sg_t('TEST.A_XML_FEHLER'), sg_e(implode(', ', $xmlfehler)))
                       : sg_t('TEST.A_XML_OK'));
    }

    /* ---- Reiterleiste, Bereiche und Positivliste ----
       Drei Stellen, die zusammenpassen muessen. Fehlt ein Name in der
       Positivliste, ist der Reiter sichtbar und anklickbar - aber nach jedem
       Absenden springt die Seite zurueck auf Einstellungen. Diese Pruefung
       gehoert laut REGELN_1 in den Reiter Test, damit das Ausschreiben der
       Leiste nachpruefbar bleibt. */
    $eigen = @file_get_contents(__DIR__ . '/index.php');
    if ($eigen === false) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_REITER'), sg_t('TEST.A_REITER_UNBEKANNT'));
    } else {
        preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $eigen, $m1);
        preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $eigen, $m2);
        $liste = array();
        if (preg_match('/\^tab-\(([a-z0-9|]+)\)/', $eigen, $m3)) {
            foreach (explode('|', $m3[1]) as $x) { $liste[] = 'tab-' . $x; }
        }
        $leiste = array_unique($m1[1]);
        $flaechen = array_unique($m2[1]);
        sort($leiste); sort($flaechen); sort($liste);
        $passt = ($leiste === $flaechen && $leiste === $liste && count($leiste) > 0);
        $z[] = sg_pruefzeile($passt ? 1 : 0, sg_t('TEST.F_REITER'),
            sprintf(sg_t($passt ? 'TEST.A_REITER_OK' : 'TEST.A_REITER_FEHL'),
                count($leiste), count($flaechen), count($liste)));

        /* Setzt der Server sm-active? (Pflichtzeile) Gezaehlt wird, wie oft
           Leiste und Bereiche den Vergleich mit $sg_tab tragen. */
        $n_leiste = preg_match_all('/class="sm-tab<\?= \$sg_tab === \'tab-[a-z0-9]+\' \? \' sm-active\'/', $eigen);
        $n_seite = preg_match_all('/class="sm-seite<\?= \$sg_tab === \'tab-[a-z0-9]+\' \? \' sm-active\'/', $eigen);
        $gut = count($leiste) > 0 && $n_leiste === count($leiste) && $n_seite === count($leiste);
        $z[] = sg_pruefzeile($gut ? 1 : 0, sg_t('TEST.F_AKTIV'),
            sprintf(sg_t($gut ? 'TEST.A_AKTIV_OK' : 'TEST.A_AKTIV_FEHL'), $n_leiste, $n_seite, count($leiste)));

        /* Tragen alle Formulare das Merkmal? (Pflichtzeile) Gezaehlt in der
           eigenen Datei: jedes POST-Formular und jedes sg_fmt(). */
        // (?:form) statt des Wortes am Stueck: sonst zaehlt hausstandard_pruefen
        // dieses Suchmuster selbst als Formular.
        $n_form = preg_match_all('/<(?:form) [^>]*method="post"/', $eigen);
        $n_fmt = preg_match_all('/<\?php echo sg_fmt\(\); \?>/', $eigen);
        $gut = $n_form > 0 && $n_form === $n_fmt;
        $z[] = sg_pruefzeile($gut ? 1 : 0, sg_t('TEST.F_MERKMAL'),
            sprintf(sg_t($gut ? 'TEST.A_MERKMAL_OK' : 'TEST.A_MERKMAL_FEHL'), $n_fmt, $n_form));
    }

    /* ---- Ist die Konfiguration heil? (Pflichtzeile) ---- */
    $lage = sg_config_lage();
    $geheilt = sg_config_geheilt();
    if ($lage === 'ok' && $geheilt > 0 && time() - $geheilt < 86400) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_CONFIG'),
            sprintf(sg_t('TEST.A_CONFIG_ZWEITSCHRIFT'), sg_e(date('d.m.Y H:i', $geheilt))));
    } elseif ($lage === 'ok') {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_CONFIG'), sg_t('TEST.A_CONFIG_OK'));
    } else {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_CONFIG'), sg_t('TEST.A_CONFIG_' . strtoupper($lage)));
    }

    /* ---- Stimmt die Themenliste mit dem Sendecode ueberein? (Pflichtzeile)
       Die Themenliste im Reiter MQTT kommt aus sg_mqtt_themen(); gesendet wird
       ueber sg_mqtt_thema_voll(). Verglichen werden die Themen jedes
       eingeschalteten Befehls. */
    $liste_t = array();
    foreach (sg_mqtt_themen($cfg) as $th) {
        if ($th['art'] === 'fest' || $th['art'] === 'zahl') { $liste_t[] = $th['thema']; }
    }
    $sende_t = array();
    foreach ($cfg['befehle'] as $b) {
        if (!empty($b['aktiv']) && $b['wort'] !== '' && $b['thema'] !== '') { $sende_t[] = sg_mqtt_thema_voll($cfg, $b['thema']); }
    }
    if (!$sende_t) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_THEMEN'), sg_t('TEST.A_THEMEN_LEER'));
    } else {
        $gut = $liste_t === $sende_t;
        $z[] = sg_pruefzeile($gut ? 1 : 0, sg_t('TEST.F_THEMEN'),
            sprintf(sg_t($gut ? 'TEST.A_THEMEN_OK' : 'TEST.A_THEMEN_FEHL'), count($liste_t), count($sende_t)));
    }

    /* ---- Antwortet der eigene Endpunkt? (Pflichtzeile, drei Ausgaenge) ----
       Ein echter Aufruf ueber 127.0.0.1 - nur dieser findet getrennte Baeume
       (html/ und htmlauth/) und einen Endpunkt, der mit 500 antwortet. Er
       schaltet nichts (selftest). Nur bei offenem Reiter Test. */
    if (!$mit_netz) {
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_ENDPUNKT'), sg_t('TEST.A_ENDPUNKT_SPAETER'));
    } else {
        $p = sg_paths();
        $url = 'http://127.0.0.1/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1&token='
             . rawurlencode((string) $cfg['aktionstoken']);
        $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
        list($t, $code) = ini_get('allow_url_fopen') ? sg_http_abruf($url, $ctx) : array(false, 0);
        if ($t === false || $code === 0) {
            $z[] = sg_pruefzeile(-1, sg_t('TEST.F_ENDPUNKT'), sg_t('TEST.A_ENDPUNKT_KEINE'));
        } elseif ($code === 200 && strpos((string) $t, 'SELFTEST;OK=1') === 0) {
            $z[] = sg_pruefzeile(1, sg_t('TEST.F_ENDPUNKT'), sg_t('TEST.A_ENDPUNKT_OK'));
        } else {
            $z[] = sg_pruefzeile(0, sg_t('TEST.F_ENDPUNKT'),
                sprintf(sg_t('TEST.A_ENDPUNKT_FEHL'), $code, sg_e(sg_kuerzen(trim((string) $t), 60))));
        }
    }

    return $z;
}

/** Der Broker des LoxBerry aus der general.json (nur gelesen, nie ausgegeben). */
function sg_broker()
{
    $p = sg_paths();
    $leer = array('host' => '', 'port' => 0, 'user' => '', 'pass' => '');
    if ($p['home'] === '') { return $leer; }
    $gen = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    if (!is_array($gen)) { return $leer; }
    $m = isset($gen['Mqtt']) && is_array($gen['Mqtt']) ? $gen['Mqtt']
       : (isset($gen['mqtt']) && is_array($gen['mqtt']) ? $gen['mqtt'] : array());
    $hol = function ($a, $b) use ($m) {
        if (isset($m[$a]) && is_scalar($m[$a])) { return (string) $m[$a]; }
        return (isset($m[$b]) && is_scalar($m[$b])) ? (string) $m[$b] : '';
    };
    $host = $hol('Brokerhost', 'brokerhost');
    $port = (int) $hol('Brokerport', 'brokerport');
    return array('host' => $host !== '' ? $host : 'localhost', 'port' => $port > 0 ? $port : 1883,
                 'user' => $hol('Brokeruser', 'brokeruser'), 'pass' => $hol('Brokerpass', 'brokerpass'));
}

/**
 * Die MQTT-Probe (M7): mosquitto_sub hoert auf <praefix>/selbsttest, dann geht
 * ueber den UDP-Eingang "publish <praefix>/selbsttest <Zufallswort>" hinaus
 * (fluechtig). Haken nur, wenn genau dieses Wort beim Broker ankommt.
 * Was die Probe NICHT zeigt, sagt der Text: ob ein einzelner Befehl ankommt
 * (der UDP-Eingang verwirft unter Last ohne Rueckmeldung) und ob das Abo des
 * Gateways V1 zum Miniserver traegt.
 * Rueckgabe: array(Stand 1/0/-1, Text).
 */
function sg_mqtt_probe($cfg)
{
    $m = sg_mqtt_zustand();
    /* Im Suchpfad gesucht, ohne Schale: eine Umleitung nach /dev/null waere
       auf einem Pruefrechner ohne /dev/null selbst eine Datei. */
    $sub = '';
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $sg_d) {
        if ($sg_d !== '' && is_file($sg_d . '/mosquitto_sub') && is_executable($sg_d . '/mosquitto_sub')) {
            $sub = $sg_d . '/mosquitto_sub';
            break;
        }
    }
    if ($sub === '' || !function_exists('proc_open')) {
        return array(-1, sg_t('TEST.A_MQTT_PROBE_OHNE'));
    }
    $b = sg_broker();
    if ($b['host'] === '') { return array(-1, sg_t('TEST.A_MQTT_PROBE_OHNE')); }
    $thema = sg_mqtt_thema_voll($cfg, 'selbsttest');
    $wort = sg_token(16);
    $argv = array($sub, '-h', $b['host'], '-p', (string) $b['port'], '-t', $thema, '-C', '1', '-W', '4');
    if ($b['user'] !== '') { $argv[] = '-u'; $argv[] = $b['user']; }
    if ($b['pass'] !== '') { $argv[] = '-P'; $argv[] = $b['pass']; }
    $ph = @proc_open(implode(' ', array_map('escapeshellarg', $argv)),
                     array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $rohre);
    if (!is_resource($ph)) { return array(-1, sg_t('TEST.A_MQTT_PROBE_OHNE')); }
    usleep(700000);
    /* SignalBot-q1: die Probe geht den Weg der Befehle - zuerst direkt an den
       Broker (QoS 1), nur wenn der nicht nutzbar ist ueber den UDP-Eingang. */
    $sg_b = sg_broker_einmal($thema, $wort);
    $sg_weg = 'broker';
    $gesendet = 1;
    if ($sg_b['erg'] === 'zu' || $sg_b['erg'] === 'nicht_gesendet') {
        $sg_weg = 'udp';
        $gesendet = sg_udp_senden($m['udpport'], 'publish ' . $thema . ' ' . $wort);
    }
    $aus = (string) stream_get_contents($rohre[1]);
    $fehler = (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    $rc = proc_close($ph);
    if ($sg_weg === 'broker') {
        if (strpos($aus, $wort) !== false && $sg_b['erg'] === 'bestaetigt') {
            return array(1, sprintf(sg_t('TEST.A_MQTT_PROBE_OK_BROKER'), sg_e($thema)));
        }
        if ($sg_b['erg'] !== 'bestaetigt') {
            return array(0, sprintf(sg_t('TEST.A_MQTT_PROBE_KEIN_PUBACK'), sg_e($thema),
                                    strpos($aus, $wort) !== false ? sg_t('TEST.A_MQTT_PROBE_KAM') : sg_t('TEST.A_MQTT_PROBE_KAM_NICHT')));
        }
        return array(0, sprintf(sg_t('TEST.A_MQTT_PROBE_BROKER_NICHT'), sg_e($thema)));
    }
    if (strpos($aus, $wort) !== false) {
        return array(1, sprintf(sg_t('TEST.A_MQTT_PROBE_OK'), sg_e($thema)));
    }
    if (!$gesendet) {
        return array(0, sprintf(sg_t('TEST.A_MQTT_PROBE_UDP'), (int) $m['udpport']));
    }
    if ($rc === 27 || trim($fehler) === '') {
        return array(0, sprintf(sg_t('TEST.A_MQTT_PROBE_NICHT'), sg_e($thema), (int) $m['udpport']));
    }
    return array(-1, sprintf(sg_t('TEST.A_MQTT_PROBE_BROKER'), sg_e(sg_kuerzen(trim($fehler), 80))));
}

/**
 * Die native Bibliothek beschaffen und ablegen.
 *
 * BEWUSST EIN KNOPF UND KEIN AUTOMATISMUS. Es ist ein Bau eines Dritten
 * (exquo/signal-libs-build), den das signal-cli-Wiki unter "Provide native
 * lib for libsignal" verlinkt - nicht von den signal-cli-Entwicklern. Wer
 * ihn holt, soll das entscheiden und nicht nebenbei bekommen; deshalb steht
 * der Knopf bei den orangen und die Herkunft im Text daneben.
 *
 * Geholt wird ueber curl und tar - beides ist da (curl steht in dpkg/apt).
 * Die Fassung stammt aus dem Dateinamen des mitgelieferten JAR, wird also
 * nicht geraten. Nach dem Auspacken wird geprueft, ob wirklich eine
 * ELF-Bibliothek angekommen ist; eine Fehlerseite von GitHub waere sonst
 * eine Datei, die "da" ist und nichts taugt.
 */
function sg_nativ_holen()
{
    $bogen = sg_bogen();
    $ziel3 = sg_nativ_ziel();
    $fassung = sg_libsignal_fassung();
    if ($ziel3 === '' || $fassung === '') {
        return array(0, sprintf(sg_t('TEST.M_NATIV_UNBEKANNT'), sg_e($bogen)));
    }
    $ordner = sg_nativ_ordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true)) {
        return array(0, sprintf(sg_t('TEST.M_NATIV_ORDNER'), sg_e($ordner)));
    }
    if (!is_writable($ordner)) {
        return array(0, sprintf(sg_t('TEST.M_NATIV_ORDNER'), sg_e($ordner)));
    }
    $url = 'https://github.com/exquo/signal-libs-build/releases/download/libsignal_v'
         . $fassung . '/libsignal_jni.so-v' . $fassung . '-' . $ziel3 . '.tar.gz';
    $tmp = sg_tmpdir() . '/libsignal_jni.tar.gz';
    @unlink($tmp);
    $rc = 0; $aus = array();
    @exec('curl -sSL --max-time 300 --retry 2 -o ' . escapeshellarg($tmp) . ' '
          . escapeshellarg($url) . ' 2>&1', $aus, $rc);
    if ($rc !== 0 || !is_file($tmp) || filesize($tmp) < 100000) {
        @unlink($tmp);
        sg_log('Native Bibliothek: Download fehlgeschlagen (' . $url . ')');
        return array(0, sprintf(sg_t('TEST.M_NATIV_LADEN'), sg_e($url)));
    }
    $aus = array(); $rc = 0;
    @exec('tar xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($ordner)
          . ' libsignal_jni.so 2>&1', $aus, $rc);
    @unlink($tmp);
    $datei = sg_nativ_datei();
    if ($rc !== 0 || !is_file($datei)) {
        return array(0, sg_t('TEST.M_NATIV_PACKEN'));
    }
    // Wirklich eine Bibliothek? Die ersten vier Byte einer ELF-Datei.
    $fp = @fopen($datei, 'rb');
    $kopf = $fp ? fread($fp, 4) : '';
    if ($fp) { fclose($fp); }
    if ($kopf !== "\x7fELF") {
        @unlink($datei);
        return array(0, sg_t('TEST.M_NATIV_KEINE_LIB'));
    }
    @chmod($datei, 0644);
    sg_log('Native Bibliothek libsignal ' . $fassung . ' fuer ' . $bogen . ' abgelegt: ' . $datei);
    sg_ereignis_merken('Oberflaeche', 'libsignal geholt', $fassung . ' / ' . $bogen);
    return array(1, sprintf(sg_t('TEST.M_NATIV_OK'), sg_e($fassung),
        number_format(filesize($datei) / 1048576, 1, ',', '.')));
}

/**
 * Die Knopf-Aktionen des Reiters Test.
 * Rueckgabe: array(ok, Meldung)
 */
function sg_test_aktion($was, $zusatz = '')
{
    $cfg = sg_config();
    switch ($was) {
        case 'enable':
        case 'disable':
        case 'start':
        case 'stop':
        case 'restart':
            // Ein Start ohne die Bibliothek meldet systemctl als gelungen -
            // die Unit ueberspringt ihn aber wegen ihrer Startbedingung. Das
            // hier zu sagen, statt "ausgefuehrt" zu melden, ist die Wirkung.
            if (($was === 'start' || $was === 'restart') && sg_nativ_fehlt()) {
                return array(0, sprintf(sg_t('TEST.M_DIENST_NATIV'), sg_e(sg_nativ_ordner())));
            }
            list($ok, $text) = sg_dienst($was);
            /* Je Vorgang ein eigener Satz (U13): bis 0.9.25 stand
               "Dienst enable fehlgeschlagen" - ein englisches Verb aus %s
               im deutschen Satz (Regeln/04). */
            $sg_k = strtoupper($was);
            return array($ok, $ok ? sg_t('TEST.M_DIENST_OK_' . $sg_k)
                                  : sprintf(sg_t('TEST.M_DIENST_FEHL_' . $sg_k), sg_e($text)));

        case 'check':
            /* Lebenszeichen von signal-cli - vom SERVER gefragt (U19). */
            list($lebt, $code) = sg_daemon_pruefen();
            return array($lebt ? 1 : 0, $lebt ? sprintf(sg_t('TEST.M_CHECK_OK'), sg_e($cfg['rpc_url']), $code)
                : ($code > 0 ? sprintf(sg_t('TEST.M_CHECK_CODE'), sg_e($cfg['rpc_url']), $code)
                             : sprintf(sg_t('TEST.M_CHECK_KEINE'), sg_e($cfg['rpc_url']))));

        case 'probe':
            // Eine Nachricht an den ersten erlaubten Empfaenger.
            if (!$cfg['erlaubt']) { return array(0, sg_t('TEST.M_KEIN_EMPFAENGER')); }
            $an = $cfg['erlaubt'][0];
            $ok = sg_senden($an, sg_t('TEST.M_PROBETEXT'));
            return array($ok, $ok ? sprintf(sg_t('TEST.M_PROBE_OK'), sg_maske($an))
                                  : sg_t('TEST.M_PROBE_FEHL'));

        case 'trocken':
            // Einen Befehl durchspielen, ohne dass jemand etwas schickt und
            // ohne dass etwas geschaltet wird - die Antwort wird nur gezeigt.
            // Das dritte Argument ist der Trockenlauf; ohne es hat dieser
            // Knopf bis 0.9.11 wirklich geschaltet (siehe sg_verarbeite).
            $von = $cfg['erlaubt'] ? $cfg['erlaubt'][0] : '+490000000000';
            $erg = sg_verarbeite($von, $zusatz, true);
            return array(1, sprintf(sg_t('TEST.M_TROCKEN'), sg_e($zusatz), sg_e($erg['grund']),
                $erg['antwort'] === '' ? sg_t('TEST.M_TROCKEN_STILL') : nl2br(sg_e($erg['antwort']))));

        case 'nativ':
            return sg_nativ_holen();

        case 'token':
            /* Unter der Konfigurationssperre (C9). F5 wiederholt das nicht
               mehr: der Handler leitet um (U2). */
            list($sg_ok, ) = sg_config_aendern(function ($c) { $c['aktionstoken'] = sg_token(); return $c; });
            if ($sg_ok) {
                sg_log('Zugriffstoken neu erzeugt');
                return array(1, sg_t('TEST.M_TOKEN_OK'));
            }
            return array(0, sg_t('TEST.M_TOKEN_FEHL'));
    }
    return array(0, sg_t('TEST.M_UNBEKANNT'));
}
