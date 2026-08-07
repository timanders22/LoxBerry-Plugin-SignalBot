# LoxBerry-Plugin Signal Bot

Ein **vollwertiger, lokaler Messenger-Bot** fuer LoxBerry. Nutzer schicken per
Signal einen Befehl im Klartext — `alarmanlage unscharf` — und der Miniserver
fuehrt ihn aus. Umgekehrt meldet Loxone in denselben Chat zurueck.

Alles laeuft auf dem eigenen Geraet: signal-cli haengt sich als Zweitgeraet an
ein bestehendes Signal-Konto, die Nachrichten sind Ende-zu-Ende verschluesselt,
ein Cloud-Dienst ist nicht beteiligt.

## Zwei Richtungen

| Richtung | Weg | Beispiel |
|---|---|---|
| Chat &rarr; Loxone | MQTT-Impuls auf ein Thema | `licht aus` &rarr; `signalbot/befehl/licht_aus` = 1 |
| Loxone &rarr; Chat | Token-Endpunkt, den ein virtueller Ausgang aufruft | Alarm ausgeloest &rarr; Nachricht an alle Erlaubten |

## Die drei Sicherungsschichten

Ein Bot, der die Alarmanlage entschaerfen darf, ist ein Schluessel — und er
liegt in einem Chat auf einem Handy, das verloren gehen kann. Drei Schichten
greifen unabhaengig voneinander:

1. **Weissliste.** Nur eingetragene Rufnummern werden ueberhaupt gehoert. Wer
   nicht daraufsteht, bekommt wahlweise gar keine Antwort — eine Antwort wuerde
   Fremden bestaetigen, dass hier ein Bot lauscht.
2. **Stufe je Befehl.** `sofort` fuer Licht und Rollaeden, `rueckfrage` fuer
   alles, was nicht versehentlich passieren soll, `pin` fuer Alarmanlage und
   Haustuer. Eine offene Rueckfrage verfaellt nach 90 Sekunden.
3. **Bremse.** Mehr als N Befehle je Minute und Absender werden abgewiesen.
   Das begrenzt vor allem das Durchprobieren einer PIN.

Der HTTP-Endpunkt fuer Loxone kann **bewusst keine Chat-Befehle ausloesen**. Er
darf melden und senden, nicht schalten — sonst waere die ganze Absicherung
ueber einen einzigen HTTP-Aufruf zu umgehen.

## Vor dem Einbau lesen: zwei harte Voraussetzungen

Diese beiden Punkte entscheiden, ob das Plugin auf dem jeweiligen Geraet
ueberhaupt laufen kann. Beide werden bei der Installation und im Reiter *Test*
geprueft und benannt — sie scheitern nicht stillschweigend.

* **signal-cli verlangt Java 25.** Aeltere Laufzeiten starten den Dienst und
  lassen ihn sofort wieder fallen. `postroot.sh` installiert die beste
  verfuegbare Laufzeit und warnt, wenn sie zu alt ist.
* **Die native Bibliothek `libsignal-client` liegt dem Archiv nur fuer x86_64
  bei.** Auf jedem Raspberry Pi fehlt sie, und signal-cli bricht beim ersten
  Zugriff mit einem `UnsatisfiedLinkError` ab. Zwei Wege: die Bibliothek fuer
  die eigene Architektur beschaffen (signal-cli-Wiki, *Provide native lib for
  libsignal*), oder signal-cli in einem ARM-faehigen Docker-Abbild betreiben
  und im Plugin nur dessen Adresse eintragen (`rpc_url`).

Der zweite Weg ist auf dem Pi der bequemere und wird vom Plugin vollstaendig
unterstuetzt: dann ist LoxBerry Bedienoberflaeche und Bruecke, signal-cli
laeuft daneben.

## Einrichten

1. *Einstellungen* &rarr; **Verknuepfung starten**, QR-Code am Handy scannen
   (Signal &rarr; Einstellungen &rarr; Gekoppelte Geraete), dann **Verknuepfung
   abschliessen**.
2. Erlaubte Absender eintragen, eine Rufnummer je Zeile, international.
3. PIN vergeben, wenn kritische Befehle geplant sind.
4. *Befehle* &rarr; Tabelle fuellen: Wort, MQTT-Thema, Stufe.
5. *MQTT* &rarr; das angezeigte Abo ins MQTT-Gateway eintragen
   (System &rarr; MQTT Gateway &rarr; Subscriptions). **Ohne diesen Eintrag
   kommt am Miniserver nichts an** — das ist die haeufigste Fehlerursache.
6. *Einbindung in Loxone* &rarr; virtuelle Eingaenge auf die Themen legen, die
   Melde-Adresse kopieren, wahlweise die XML-Vorlage herunterladen.
7. *Test* &rarr; Selbstpruefung ansehen, einen Befehl trocken durchspielen.

## Eingebaute Woerter

`hilfe` listet alle aktiven Befehle, `status` die gemeldeten Zustaende,
`ja` / `nein` beantworten eine Rueckfrage. Diese Woerter sind nicht
ueberschreibbar; die Selbstpruefung meldet es, wenn ein Befehl sie belegt.

## Datenschutz

Rufnummern erscheinen im Protokoll nur verkuerzt. Ein Log, das monatelang
vollstaendige Rufnummern aufhebt — auch die von Fremden, die sich verwaehlt
haben — waere ein Datenbestand, den niemand anlegen wollte.

## Verzeichnis

```
bin/sg_bot.php                     Dauerlaeufer am Ereignisstrom
cron/cron.01min                    Wiederbeleber (kein Taktgeber)
webfrontend/htmlauth/index.php     Bedienoberflaeche, sechs Reiter
webfrontend/htmlauth/sg_lib.php    Konfiguration, Befehlslogik, RPC, Vorlage
webfrontend/htmlauth/sg_test.php   Selbstpruefung und Test-Aktionen
webfrontend/html/index.php         Token-Endpunkt fuer den Miniserver
postroot.sh                        Java, signal-cli, systemd-Dienst, sudoers
```

## Lizenz

Dieses Plugin steht unter der **GPL-3.0**. signal-cli ist ein eigenstaendiges
Projekt (ebenfalls GPL-3.0) und wird bei der Installation heruntergeladen, nicht
mitgeliefert.
