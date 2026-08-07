#!/bin/bash
# Laeuft als ROOT, nach der Installation.
#
# Richtet signal-cli ein. Zwei Huerden sind dabei zu nehmen, und beide werden
# GEPRUEFT statt angenommen - ein Plugin, das hier stillschweigend scheitert,
# laesst den Anwender mit einem Bot zurueck, der nie antwortet:
#
#   1. signal-cli verlangt laut Projekt eine Java-Laufzeit ab Fassung 25.
#      Debian 12 (Bookworm) liefert 17, Debian 13 (Trixie) liefert 25.
#   2. Die native Bibliothek libsignal-client ist im Archiv NUR fuer x86_64
#      enthalten. Auf ARM - also auf jedem Raspberry Pi - fehlt sie und muss
#      getrennt beschafft werden.
#
# Geht eines davon nicht, wird das deutlich gesagt. Das Plugin bleibt
# benutzbar: es kann sich auch mit einem signal-cli verbinden, das anderswo
# laeuft (etwa im Docker-Abbild mit ARM-Unterstuetzung).
ARGV3=$3
ARGV5=$5
ZIEL=/opt

echo "<INFO> Pruefe, ob signal-cli bereits vorhanden ist"
if command -v signal-cli >/dev/null 2>&1; then
    echo "<OK> signal-cli ist bereits installiert. Es wird nichts veraendert."
    exit 0
fi

BOGEN=$(dpkg --print-architecture 2>/dev/null)
echo "<INFO> Architektur: $BOGEN"

# ---- Java ----
echo "<INFO> Suche eine passende Java-Laufzeit"
JAVA_OK=0
for PAKET in openjdk-25-jre-headless openjdk-24-jre-headless openjdk-21-jre-headless default-jre-headless; do
    if apt-cache show "$PAKET" >/dev/null 2>&1; then
        echo "<INFO> Installiere $PAKET"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$PAKET" && { JAVA_OK=1; break; }
    fi
done
if [ "$JAVA_OK" != "1" ]; then
    echo "<FAIL> Keine Java-Laufzeit installierbar."
    echo "<INFO> Das Plugin bleibt nutzbar: im Reiter Einstellungen die Adresse"
    echo "<INFO> eines signal-cli eintragen, das anderswo laeuft."
    exit 0
fi

JVER=$(java -version 2>&1 | head -1 | sed -E 's/.*"([0-9]+).*/\1/')
echo "<INFO> Java-Fassung: $JVER"
if [ -n "$JVER" ] && [ "$JVER" -lt 25 ] 2>/dev/null; then
    echo "<WARNING> signal-cli verlangt Java 25, gefunden wurde $JVER."
    echo "<WARNING> Es wird trotzdem eingerichtet - laeuft es nicht, steht der"
    echo "<WARNING> Grund im Reiter Test. Abhilfe: Java 25 aus den Backports,"
    echo "<WARNING> oder ein signal-cli auf einem anderen Rechner benutzen."
fi

# ---- signal-cli ----
echo "<INFO> Ermittle die neueste Fassung von signal-cli"
VERSION=$(curl -Ls -o /dev/null -w '%{url_effective}' https://github.com/AsamK/signal-cli/releases/latest | sed -e 's|^.*/v||')
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "<FAIL> Die Fassungsnummer liess sich nicht ermitteln ('$VERSION'). Internetverbindung pruefen."
    exit 0
fi
echo "<INFO> Lade signal-cli $VERSION (Java-Fassung, rund 120 MB)"
if ! curl -Lf --retry 2 -o "/tmp/signal-cli-$VERSION.tar.gz" \
     "https://github.com/AsamK/signal-cli/releases/download/v$VERSION/signal-cli-$VERSION.tar.gz"; then
    echo "<FAIL> Der Download ist fehlgeschlagen."
    exit 0
fi
tar xf "/tmp/signal-cli-$VERSION.tar.gz" -C "$ZIEL"
rm -f "/tmp/signal-cli-$VERSION.tar.gz"
ln -sf "$ZIEL/signal-cli-$VERSION/bin/signal-cli" /usr/local/bin/signal-cli
echo "<OK> signal-cli $VERSION nach $ZIEL/signal-cli-$VERSION entpackt"

# ---- Die native Bibliothek: der eigentliche Stolperstein auf ARM ----
if [ "$BOGEN" != "amd64" ]; then
    echo "<WARNING> ============================================================"
    echo "<WARNING> Die native Bibliothek libsignal-client ist im Archiv NUR fuer"
    echo "<WARNING> x86_64 enthalten. Auf '$BOGEN' fehlt sie, und signal-cli wird"
    echo "<WARNING> beim ersten Aufruf mit einem UnsatisfiedLinkError abbrechen."
    echo "<WARNING>"
    echo "<WARNING> Zwei Wege:"
    echo "<WARNING>  a) libsignal_jni.so fuer $BOGEN beschaffen und in das JAR"
    echo "<WARNING>     legen - Anleitung im signal-cli-Wiki unter"
    echo "<WARNING>     'Provide native lib for libsignal'"
    echo "<WARNING>  b) signal-cli in einem Docker-Abbild mit ARM-Unterstuetzung"
    echo "<WARNING>     betreiben und im Reiter Einstellungen dessen Adresse"
    echo "<WARNING>     eintragen"
    echo "<WARNING>"
    echo "<WARNING> Der Reiter Test sagt jederzeit, woran es gerade haengt."
    echo "<WARNING> ============================================================"
fi

# ---- Dienst ----
# Eigener Systembenutzer: der Bot haelt Signal-Schluessel, die haben in
# keinem Heimatverzeichnis eines Menschen etwas zu suchen.
if ! id signalcli >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/signal-cli --create-home --shell /usr/sbin/nologin signalcli
fi
mkdir -p /var/lib/signal-cli
chown -R signalcli:signalcli /var/lib/signal-cli
chmod 0700 /var/lib/signal-cli

cat > /etc/systemd/system/signal-cli-loxberry.service <<'UNIT'
[Unit]
Description=signal-cli JSON-RPC daemon for the LoxBerry Signal Bot
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=signalcli
Group=signalcli
Environment=XDG_DATA_HOME=/var/lib/signal-cli
# Mehrkontenbetrieb (ohne -a): nur so gibt es startLink und finishLink,
# und nur damit laesst sich das Konto aus der Oberflaeche verknuepfen.
ExecStart=/usr/local/bin/signal-cli --config /var/lib/signal-cli daemon --http=127.0.0.1:8095
Restart=always
RestartSec=10
# Der Dienst braucht nichts vom uebrigen System.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/signal-cli

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable signal-cli-loxberry >/dev/null 2>&1
systemctl start signal-cli-loxberry >/dev/null 2>&1

# Der Benutzer loxberry muss den Dienst aus der Oberflaeche steuern koennen -
# aber nur diesen einen und nur diese Unterbefehle.
cat > /etc/sudoers.d/loxberry-signalbot <<'SUDO'
loxberry ALL=(root) NOPASSWD: /bin/systemctl start signal-cli-loxberry, /bin/systemctl stop signal-cli-loxberry, /bin/systemctl restart signal-cli-loxberry
loxberry ALL=(root) NOPASSWD: /usr/bin/systemctl start signal-cli-loxberry, /usr/bin/systemctl stop signal-cli-loxberry, /usr/bin/systemctl restart signal-cli-loxberry
SUDO
chmod 0440 /etc/sudoers.d/loxberry-signalbot
if ! visudo -cf /etc/sudoers.d/loxberry-signalbot >/dev/null 2>&1; then
    echo "<WARNING> Die sudo-Regel war fehlerhaft und wurde wieder entfernt."
    rm -f /etc/sudoers.d/loxberry-signalbot
fi

echo "<OK> Dienst signal-cli-loxberry eingerichtet, JSON-RPC auf 127.0.0.1:8095"
echo "<INFO> Naechster Schritt: im Plugin den Reiter Einstellungen oeffnen und"
echo "<INFO> das Signal-Konto als Zweitgeraet verknuepfen."
exit 0
