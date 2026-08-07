#!/bin/bash
# Laeuft als Benutzer loxberry, vor der Installation.
ARGV1=$1
if [ -n "$ARGV1" ] && [ -d "$ARGV1" ]; then
    find "$ARGV1" -type f \( -name '*.sh' -o -name '*.php' -o -name '*.ini' -o -name '*.cfg' \) \
        -print0 | xargs -0 -r dos2unix -q 2>/dev/null
    echo "<OK> Zeilenenden geprueft."
else
    echo "<WARNING> Temporaerordner nicht gefunden - Zeilenenden nicht geprueft."
fi
exit 0
