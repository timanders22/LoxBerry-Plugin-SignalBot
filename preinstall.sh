#!/bin/bash
# Signal Bot - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Durchgang 01.10.2026 (I1, X-1, Entscheidung 1). Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990). Bauform:
# LoxBerry-Plugin-Abfahrtsassistent-1.6.19/preinstall.sh.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und Bestand werden
# gleich gebraucht.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.signalbot.json - mit Aktionstoken,
# PIN-Hash und Weissliste) und ein liegengebliebener Bestand
# (data/plugins/<ordner>.bestand - Ereignisprotokoll und Warteschlange)
# einer frueheren Installation gehen nach <name>.alt, gemeldet mit genau
# einer <WARNING>. Bis 0.9.25 holte die Selbstheilung der Bibliothek Token,
# PIN-Hash und Weissliste einer frueheren Installation zurueck (in WSL
# gemessen, Installer-Pruefer Faelle A2/A2L). Die Bibliothek liest .alt nie;
# die Deinstallation raeumt es ab.
#
# Der Ordner data/plugins/<ordner>.nativ (libsignal_jni.so) bleibt liegen -
# Entscheidung 27, Ausnahme wie Matter2Lox: sonst muesste die Bibliothek nach
# jeder Neuinstallation neu beschafft werden. Die Deinstallation entfernt ihn.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-signalbot}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche: ohne config/plugins, data/plugins UND config/system/general.json
# wird nichts angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt den Bestand zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.signalbot.json"
BESTAND="$BASE/data/plugins/$PFOLDER.bestand"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$BESTAND"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    SG_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && SG_TEXT="$SG_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && SG_TEXT="$SG_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$SG_TEXT"
fi
exit 0
