#!/bin/bash
ARGV3=$3
ARGV5=$5
mkdir -p "$ARGV5/data/plugins/$ARGV3" "$ARGV5/log/plugins/$ARGV3" "$ARGV5/config/plugins/$ARGV3"
chmod 0775 "$ARGV5/data/plugins/$ARGV3" "$ARGV5/log/plugins/$ARGV3"
# Die Konfiguration traegt Token, PIN und die Liste erlaubter Rufnummern.
[ -f "$ARGV5/config/plugins/$ARGV3/signalbot.json" ] && chmod 0600 "$ARGV5/config/plugins/$ARGV3/signalbot.json"
echo "<OK> Fertig. Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an."
exit 0
