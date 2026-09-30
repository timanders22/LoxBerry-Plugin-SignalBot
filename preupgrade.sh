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

# Den laufenden Bot beendet weiterhin postinstall.sh, nicht dieses Skript:
# solange die Aktualisierung laeuft, soll der alte Bot noch am
# Ereignisstrom haengen, damit keine Nachricht verlorengeht. Die Marke
# sorgt nur dafuer, dass in dieser Zeit kein ZWEITER dazukommt.

exit 0
