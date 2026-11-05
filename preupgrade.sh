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
# 05.11.2026: ein Ordnername mit "/" oder "." waere ein Pfad hinaus (dieselbe Formel wie MGiSmart und Govee).
case "$ARGV3" in
    ''|.|..|*/*) echo "<FAIL> Unbrauchbarer Ordnername '$ARGV3' - nichts angefasst."; exit 2 ;;
esac
# 05.11.2026: ohne Wurzel wird nichts gesichert; Rueckgabewert 2, sonst liefe der Purge weiter.
if [ -z "$ARGV5" ] || [ ! -d "$ARGV5/config/plugins" ] || [ ! -d "$ARGV5/data/plugins" ] \
   || [ ! -f "$ARGV5/config/system/general.json" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt ('$ARGV5') - nichts gesichert, das Update wird abgebrochen."
    exit 2
fi
MARKE="$ARGV5/data/plugins/$ARGV3.upgrade_laeuft"
mkdir -p "$ARGV5/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Der Wecker startet den Bot bis zum Ende der Installation nicht."
else
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$MARKE" 2>/dev/null
    exit 2
}

# Ordnername ohne Pfadtrenner, sonst griffe rm/cp daneben.
# (Die Pruefung des Ordnernamens steht seit 05.11.2026 vor der Marke.)

# ---- Befehlsbruecke anhalten (seit 0.9.30; Bauart Ferien/ACTi preupgrade) ----
#
# NACH der Marke: der Waechter im Minutentakt startet sie bis zum Ende der
# Installation (postroot.sh raeumt die Marke als Letztes ab) nicht neu; danach holt
# er den Start binnen 60 s nach. Bis 0.9.29 gab es keine Bruecke (dann fehlt die
# Datei). Der Haus-Leser (Vorschlag 3) endet selbst, sobald die Marke liegt.
BBSH="$ARGV5/bin/plugins/$ARGV3/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    echo "<INFO> Befehlsbruecke: $(timeout 40 bash "$BBSH" stop 2>&1 | head -n 1)"
fi

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
        [ -f "$DATEN/$SG_D" ] || continue
        if zs_hinlegen "$DATEN/$SG_D" "$BESTAND/$SG_D"; then
            SG_N=$((SG_N + 1))
        else
            ZS_FEHL="$ZS_FEHL $SG_D"
        fi
    done
    echo "<OK> Ereignisprotokoll und Warteschlange fuer das Update gesichert ($SG_N Dateien)."
else
    echo "<WARNING> Der Bestand $BESTAND liess sich nicht anlegen - Ereignisprotokoll und Warteschlange gehen beim Update verloren."
    [ -d "$DATEN" ] && ZS_FEHL="$ZS_FEHL Bestand"
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
        *) echo "<WARNING> Die Zweitschrift liess sich nicht anlegen ($ZWEIT)."; ZS_FEHL="$ZS_FEHL signalbot.json" ;;
    esac
fi

# Den laufenden Bot beendet weiterhin postinstall.sh, nicht dieses Skript:
# solange die Aktualisierung laeuft, soll der alte Bot noch am
# Ereignisstrom haengen, damit keine Nachricht verlorengeht. Die Marke
# sorgt nur dafuer, dass in dieser Zeit kein ZWEITER dazukommt.

zs_schluss
exit 0
