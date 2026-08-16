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

function sg_pruefungen()
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
        $z[] = sg_pruefzeile(-1, sg_t('TEST.F_BOGEN'), sprintf(sg_t('TEST.A_BOGEN_ARM'), sg_e($bogen)));
    } elseif ($bogen !== '') {
        $z[] = sg_pruefzeile(1, sg_t('TEST.F_BOGEN'), sprintf(sg_t('TEST.A_BOGEN_OK'), sg_e($bogen)));
    }

    $laeuft = sg_dienst_laeuft();
    $z[] = sg_pruefzeile($laeuft ? 1 : ($vorhanden ? 0 : -1), sg_t('TEST.F_DIENST'),
        $laeuft ? sg_t('TEST.A_DIENST_LAEUFT') : sg_t('TEST.A_DIENST_TOT'));

    $lebt = sg_daemon_lebt();
    $z[] = sg_pruefzeile($lebt ? 1 : 0, sg_t('TEST.F_RPC'),
        $lebt ? sprintf(sg_t('TEST.A_RPC_OK'), sg_e($cfg['rpc_url']))
              : sprintf(sg_t('TEST.A_RPC_TOT'), sg_e($cfg['rpc_url'])));

    /* ---- Konto ---- */
    $konten = $lebt ? sg_konten() : array();
    if ((string) $cfg['konto'] === '') {
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
    $sperre = sg_tmpdir() . '/bot.lock';
    $botlaeuft = false;
    if (is_file($sperre)) {
        $fh = @fopen($sperre, 'c');
        if ($fh) {
            // Laesst sich die Sperre nehmen, laeuft niemand.
            $botlaeuft = !flock($fh, LOCK_EX | LOCK_NB);
            if (!$botlaeuft) { flock($fh, LOCK_UN); }
            fclose($fh);
        }
    }
    $z[] = sg_pruefzeile($botlaeuft ? 1 : 0, sg_t('TEST.F_BOT'),
        $botlaeuft ? sg_t('TEST.A_BOT_LAEUFT') : sg_t('TEST.A_BOT_TOT'));

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

    if ($mitpin > 0 && (string) $cfg['pin'] === '') {
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
    // Auch gegen die eingebauten Woerter pruefen.
    foreach (array('hilfe', 'help', '?', 'status', 'zustand', 'ja', 'nein') as $reserviert) {
        if (isset($woerter[$reserviert])) { $doppelt[] = $reserviert; }
    }
    $z[] = sg_pruefzeile($doppelt ? 0 : 1, sg_t('TEST.F_DOPPELT'),
        $doppelt ? sprintf(sg_t('TEST.A_DOPPELT'), sg_e(implode(', ', array_unique($doppelt))))
                 : sg_t('TEST.A_DOPPELT_KEINE'));

    /* ---- MQTT ---- */
    $m = sg_mqtt_zustand();
    if (empty($cfg['mqtt_ein'])) {
        $z[] = sg_pruefzeile(0, sg_t('TEST.F_MQTT'), sg_t('TEST.A_MQTT_AUS'));
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
    $xmlfehler = array();
    foreach (array('sg_vorlage', 'sg_vorlage_out') as $bau) {
        list($xname, $xinhalt) = $bau();
        $vorher = libxml_use_internal_errors(true);
        $ok = simplexml_load_string($xinhalt) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if (!$ok) { $xmlfehler[] = $xname; }
    }
    $z[] = sg_pruefzeile($xmlfehler ? 0 : 1, sg_t('TEST.F_XML'),
        $xmlfehler ? sprintf(sg_t('TEST.A_XML_FEHLER'), sg_e(implode(', ', $xmlfehler)))
                   : sg_t('TEST.A_XML_OK'));

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
    }

    return $z;
}

/**
 * Die Knopf-Aktionen des Reiters Test.
 * Rueckgabe: array(ok, Meldung)
 */
function sg_test_aktion($was, $zusatz = '')
{
    $cfg = sg_config();
    switch ($was) {
        case 'start':
        case 'stop':
        case 'restart':
            list($ok, $text) = sg_dienst($was);
            return array($ok, $ok ? sprintf(sg_t('TEST.M_DIENST_OK'), $was)
                                  : sprintf(sg_t('TEST.M_DIENST_FEHL'), $was, sg_e($text)));

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

        case 'token':
            $neu = sg_config();
            $neu['aktionstoken'] = sg_token();
            if (sg_config_write($neu)) {
                sg_log('Zugriffstoken neu erzeugt');
                return array(1, sg_t('TEST.M_TOKEN_OK'));
            }
            return array(0, sg_t('TEST.M_TOKEN_FEHL'));
    }
    return array(0, sg_t('TEST.M_UNBEKANNT'));
}
