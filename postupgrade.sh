#!/bin/bash
ARGV3=$3
ARGV5=$5
# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"
if [ -d "$SICHER" ]; then
    mkdir -p "$ARGV5/config/plugins/$ARGV3"
    cp -a "$SICHER/." "$ARGV5/config/plugins/$ARGV3/" 2>/dev/null
    rm -rf "$SICHER"
    chmod 0600 "$ARGV5/config/plugins/$ARGV3/signalbot.json" 2>/dev/null
    echo "<OK> Konfiguration zurueckgestellt."
fi
exit 0
