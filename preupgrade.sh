#!/bin/bash
# Laeuft als Benutzer loxberry, als ALLERERSTER Schritt einer Aktualisierung
# (vor preinstall.sh) und nur dann - bei einer Neuinstallation ruft LoxBerry
# dieses Skript nicht auf.
#
# Aufrufform: command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Rueckgabewert 0 = in Ordnung, 1 = Warnung, aber weiter, 2 = Installation
# abbrechen. Dieses Skript endet immer mit 0: es soll eine Aktualisierung
# nie verhindern.
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset). Das
# fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
[ -n "$ARGV5" ] || ARGV5="$LBHOMEDIR"
[ -n "$ARGV3" ] || ARGV3="signalbot"

# ---- Marke "Aktualisierung laeuft" ----
#
# Als Erstes, vor jedem anderen Schritt.
#
# WARUM ES SIE GIBT
# Der Installer legt die Datei unter cron.01min neu an, lange bevor
# postinstall.sh den Bot startet - am Geraet an der Einspeisebremse gemessen:
# fast eine Minute (Regeln/06). Der Wecker lief in dieser Luecke und startete
# den Bot, waehrend purge_installation config/plugins/<ordner>/ und
# data/plugins/<ordner>/ gerade geloescht hatte.
#
# In WSL Ubuntu nachgestellt am 18.09.2026 (Pruefung-SignalBot-0.9.23):
#   Fall G1, Zweitschrift vorhanden - der Bot lief an (1 Prozess) und legte
#   die Konfiguration aus der Zweitschrift neu an; Token und Weissliste
#   blieben erhalten.
#   Fall G2, Zweitschrift fehlt - der Bot schrieb eine Vorgabe mit NEUEM
#   Aktionstoken und LEERER Weissliste und zog die Zweitschrift darauf nach.
#   Alle Adressen im Miniserver, die das alte Token tragen, waeren tot.
#
# WIE SIE WIRKT
# cron/cron.01min startet nicht, solange die Marke juenger als 3600 s ist.
# Aelter, aus der Zukunft oder unlesbar: sie gilt nicht - eine abgebrochene
# Installation darf den Bot nicht fuer immer stilllegen. postroot.sh, das
# letzte Hakenskript dieser Linie, entfernt sie ueber einen EXIT-Trap;
# uninstall raeumt sie ebenfalls weg.
#
# Sie liegt NEBEN dem Datenordner, nicht darin: purge_installation loescht
# data/plugins/<ordner>/ bei jedem Upgrade vollstaendig und naehme sie mit.
MARKE="$ARGV5/data/plugins/$ARGV3.upgrade_laeuft"
mkdir -p "$ARGV5/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Der Wecker startet den Bot bis zum Ende der Installation nicht."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - der Wecker"
    echo "<WARNING> kann den Bot waehrend der Installation starten."
fi

# Ordnername ohne Pfadtrenner, sonst griffe rm/cp daneben.
case "$ARGV3" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$ARGV3' - kein Bestand angelegt."; exit 0 ;;
esac

# ---- Bestand: Ereignisprotokoll und Warteschlange ueber das Update (I3) ----
#
# purge_installation loescht data/plugins/<ordner>/ bei jedem Update. Darin
# liegen das Ereignisprotokoll (ereignisse.json, "wer hat wann was
# ausgeloest" - laut Bibliothek "auch nach Wochen beantwortbar"), die
# Ausgangswarteschlange (ausgang.json: Meldungen aus der Nachtruhe,
# unquittierte dringende Meldungen) und letzter.json. Bis 0.9.25 waren alle
# drei nach jedem Update weg (Installer-Pruefstand B8: "Wasser im Keller",
# unquittiert, verschwunden).
#
# Der Bestand liegt NEBEN dem Ordner. Ein alter Bestand wird vorher
# weggeraeumt (Entscheidung 1: nie einen Bestand aus einem frueheren Vorgang
# einspielen). postinstall.sh spielt ihn bei liegender Marke zurueck.
DATEN="$ARGV5/data/plugins/$ARGV3"
BESTAND="$ARGV5/data/plugins/$ARGV3.bestand"
rm -rf "${BESTAND:?}" 2>/dev/null
if mkdir -m 0700 "$BESTAND" 2>/dev/null; then
    SG_N=0
    for SG_D in ereignisse.json ausgang.json letzter.json; do
        if [ -f "$DATEN/$SG_D" ] && cp "$DATEN/$SG_D" "$BESTAND/$SG_D" 2>/dev/null; then
            chmod 600 "$BESTAND/$SG_D" 2>/dev/null
            SG_N=$((SG_N + 1))
        fi
    done
    echo "<OK> Ereignisprotokoll und Warteschlange fuer das Update gesichert ($SG_N Dateien)."
else
    echo "<WARNING> Der Bestand $BESTAND liess sich nicht anlegen - Ereignisprotokoll und Warteschlange gehen beim Update verloren."
fi

# ---- Zweitschrift anlegen, falls sie fehlt (I4) ----
#
# Ist die Konfiguration ein lesbares JSON-Objekt mit gueltigem Token und
# fehlt die Zweitschrift oder ist sie unbrauchbar, wird sie daraus angelegt
# (Nebendatei, 0600, umbenennen). Eine brauchbare Zweitschrift wird NIE
# ueberschrieben - auch nicht von einer kaputten Konfiguration
# (Fehlerklasse 6). Anlass, gemessen: ohne Zweitschrift schrieb schon der
# noch laufende alte Bot in der Upgrade-Luecke ein NEUES Token und eine LEERE
# Weissliste - alle Adressen im Miniserver waren tot. Ausgegeben wird nur das
# Ergebnis, nie ein Wert.
KONF="$ARGV5/config/plugins/$ARGV3/signalbot.json"
ZWEIT="$ARGV5/config/plugins/$ARGV3.backup.signalbot.json"
if [ -f "$KONF" ] && command -v php >/dev/null 2>&1; then
    SG_ERG=$(php -r '
        function sg_gut($d) {
            return is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                && preg_match("/^[A-Za-z0-9]{24,}$/", $d["aktionstoken"]);
        }
        $k = @file_get_contents($argv[1]);
        if (!sg_gut(json_decode((string) $k, true))) { echo "konfiguration_unbrauchbar"; exit; }
        $z = @file_get_contents($argv[2]);
        if ($z !== false && sg_gut(json_decode((string) $z, true))) { echo "zweitschrift_da"; exit; }
        $t = $argv[2] . "." . getmypid() . ".tmp";
        $f = @fopen($t, "xb");
        if ($f === false) { echo "fehler"; exit; }
        @chmod($t, 0600);
        $n = fwrite($f, $k);
        fclose($f);
        if ($n !== strlen($k) || !@rename($t, $argv[2])) { @unlink($t); echo "fehler"; exit; }
        echo "angelegt";
    ' "$KONF" "$ZWEIT" 2>/dev/null)
    case "$SG_ERG" in
        angelegt) echo "<OK> Die Zweitschrift fehlte oder war unbrauchbar - sie wurde aus der Konfiguration angelegt." ;;
        zweitschrift_da) echo "<OK> Zweitschrift der Konfiguration liegt bereit." ;;
        konfiguration_unbrauchbar) echo "<INFO> Konfiguration ohne gueltiges Token - die Zweitschrift bleibt, wie sie ist." ;;
        *) echo "<WARNING> Die Zweitschrift liess sich nicht anlegen ($ZWEIT)." ;;
    esac
fi

# Den laufenden Bot beendet weiterhin postinstall.sh, nicht dieses Skript:
# solange die Aktualisierung laeuft, soll der alte Bot noch am
# Ereignisstrom haengen, damit keine Nachricht verlorengeht. Die Marke
# sorgt nur dafuer, dass in dieser Zeit kein ZWEITER dazukommt.

exit 0
