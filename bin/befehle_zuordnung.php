<?php
/* SignalBot - Zuordnung der Befehlsbruecke (linieneigen; die Bruecke selbst ist byte-gleich mit der Stammfassung
 * Werkzeuge/gemeinsam/befehlsbruecke.php 1.0.1). Seit 0.9.30 (Gruppe 32, 26.10.2026; Entscheidung 2 + Vorschlag 1,
 * Entwurf entwurf_gruppe32_sg.txt 3.1, E-E4 bis E-E7, E-E12). Weg: Loxone -> UDP-Eingang des MQTT-Gateways V2
 * -> Broker -> Bruecke (mosquitto_sub) -> tun() -> sg_befehl_ausfuehren(); Quittung <p>/befehl/<name>/quittung.
 *
 * Richtung Loxone -> Chat. Alle Befehle laufen ueber DIESELBE Funktion wie der HTTP-Endpunkt
 * (sg_befehl_ausfuehren(), Quelle 'mqtt'). Die Bruecke sendet NIE selbst an signal-cli: sie reiht die Meldung in die
 * Ausgangswarteschlange (ausgang.json) ein, zugestellt wird vom Bot (sg_bot.php, Takt <= 10 s). So haengt ein
 * langsames signal-cli (bis 25 s je Empfaenger) nie die Bruecke auf, und 'sperren' kommt immer durch.
 * entsperren ist eingreifend (zweiter Haken befehle_entsperren_ein, ab Werk aus): ueber den UDP-Eingang des Gateways
 * kann jeder im Heimnetz veroeffentlichen - den Kill-Schalter loesen darf nur, wer es im Plugin erlaubt hat.
 */
if (!function_exists('sg_befehl_ausfuehren')) {
    $sg_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $sg_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/sg_lib.php';
    } else {
        $sg_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/sg_lib.php';
    }
    foreach ($sg_kand as $sg_k) {
        if (is_file($sg_k)) { require_once $sg_k; break; }
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'signalbot',
    'plugin'  => 'SignalBot',
    'geladen' => function_exists('sg_config') && function_exists('sg_befehl_ausfuehren')
                 && function_exists('sg_befehl_bruecke') && function_exists('sg_befehle_einstellungen'),
    'zweiter_haken' => 'befehle_entsperren_ein',
    'teile' => array('an' => '[0-9]{6,20}', 'name' => '[a-z0-9_]{1,40}', 'absender' => '[a-z0-9_]{1,40}'),
    'ausser' => array('name' => array('quittung'), 'absender' => array('quittung')),
    'einstellungen' => function () {
        return sg_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        // bb_log() setzt "Befehlsbruecke: " schon vor den Text - hier nur die Stufe.
        sg_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'befehle' => array(
        'senden'          => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => false,
                                   'titel' => 'SignalBot Meldung senden (MQTT)', 'kachel' => 'BEFEHL.K_SENDEN',
                                   'quittung' => 'uebergeben',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('senden', array('text' => $w), 'mqtt'));
                                   }),
        'senden/{an}'     => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => false,
                                   'titel' => 'SignalBot Meldung an eine Nummer (MQTT)', 'kachel' => 'BEFEHL.K_SENDEN_AN',
                                   'quittung' => 'uebergeben',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('senden', array('text' => $w, 'an' => '+' . $t['an']), 'mqtt'));
                                   }),
        'dringend'        => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => false,
                                   'titel' => 'SignalBot dringende Meldung (MQTT)', 'kachel' => 'BEFEHL.K_DRINGEND',
                                   'quittung' => 'uebergeben',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('senden', array('text' => $w, 'dringend' => 1), 'mqtt'));
                                   }),
        'dringend/{an}'   => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => false,
                                   'titel' => 'SignalBot dringende Meldung an eine Nummer (MQTT)', 'kachel' => 'BEFEHL.K_DRINGEND_AN',
                                   'quittung' => 'uebergeben',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('senden', array('text' => $w, 'dringend' => 1, 'an' => '+' . $t['an']), 'mqtt'));
                                   }),
        'sperren'         => array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 0,
                                   'begruendung' => 'Kill-Schalter muss jederzeit wirken; ein wiederholtes Sperren aendert nichts',
                                   'titel' => 'SignalBot Bot sperren (MQTT)', 'kachel' => 'BEFEHL.K_SPERREN',
                                   'quittung' => 'ausgefuehrt',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('sperren', array(), 'mqtt'));
                                   }),
        'entsperren'      => array('art' => 'puls', 'eingreifend' => true,
                                   'titel' => 'SignalBot Bot entsperren (MQTT)', 'kachel' => 'BEFEHL.K_ENTSPERREN',
                                   'quittung' => 'ausgefuehrt',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('entsperren', array(), 'mqtt'));
                                   }),
        'zustand/{name}'  => array('art' => 'text', 'max_byte' => 480, 'eingreifend' => false, 'sperre_s' => 0,
                                   'begruendung' => 'Ein Zustand ist ein Wert, keine Handlung; die Wiederholung erneuert nur seine Zeit',
                                   'titel' => 'SignalBot Zustand melden (MQTT)', 'kachel' => 'BEFEHL.K_ZUSTAND',
                                   'quittung' => 'ausgefuehrt',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('zustand', array('name' => $t['name'], 'wert' => $w), 'mqtt'));
                                   }),
        // Vorschlag 1: Meldungen anderer Plugins (Absender = Ordnername, muss im Plugin freigegeben sein)
        'plugin/{absender}' => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => false,
                                   'titel' => 'SignalBot Meldung eines Plugins (MQTT)', 'kachel' => 'BEFEHL.K_PLUGIN',
                                   'quittung' => 'uebergeben',
                                   'tun' => function ($w, $t) {
                                       return sg_befehl_bruecke(sg_befehl_ausfuehren('plugin', array('absender' => $t['absender'], 'roh' => $w), 'mqtt'));
                                   }),
    ),
);
