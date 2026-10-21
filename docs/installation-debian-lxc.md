# Installation auf Debian 13 im Proxmox-LXC

Zielbild: ein eigener LXC-Container mit Debian 13 („Trixie“), in dem alles
läuft, was PowerManagement braucht. Die Oberfläche ist danach **im lokalen
Netz unter der IP des Containers** erreichbar.

```
 Shelly Gen1/Gen2+ ─────── MQTT 1883 ───────┐
 (LAN oder hinter WireGuard)                │
                                            ▼
 ┌──────────────────── LXC: Debian 13 ───────────────────────────────┐
 │                                                                   │
 │  Mosquitto ◄──── pm/messwert/… ──── Sammler (bin/power sammler)   │
 │     │                                 │  Modbus TCP ──► Victron GX, ComAp IG200
 │     │                                 │  (im LAN oder über WireGuard)
 │     ▼                                 └─ HTTPS ─────► ComAp WebSupervisor, Victron VRM
 │  Node-RED ──────► InfluxDB 2 (Bucket „strom“)                     │
 │                        ▲                                          │
 │                        │ InfluxQL                                 │
 │  nginx + PHP-FPM ──────┘  Oberfläche  http://<IP>/                │
 │                           Node-RED    http://<IP>/node-red/       │
 └───────────────────────────────────────────────────────────────────┘
```

* **Mosquitto** nimmt die Shelly-Geräte und den Sammler entgegen.
* **Node-RED** übersetzt die Shelly-Themen in ein einheitliches Schema und
  schreibt alles gebündelt in die InfluxDB. Fällt die Datenbank kurz aus,
  puffert Node-RED bis zu fünf Minuten.
* **Der Sammler** fragt Victron und ComAp ab (Modbus TCP, Cloud) und
  veröffentlicht die Werte auf MQTT.
* **Die InfluxDB** hält die Messwerte; die Oberfläche liest sie mit
  InfluxQL. Ein vorhandenes Grafana kann parallel dieselbe Datenbank lesen.

Soll die Oberfläche auch von außen erreichbar sein, übernimmt das ein
Tunnel oder Reverse-Proxy (etwa Pangolin), der **getrennt davon** läuft –
siehe [Abschnitt 9](#9--von-außen-erreichbar-machen-optional).

---

## 1 · Container anlegen

Auf dem Proxmox-Host (oder in der Oberfläche: Vorlage
*debian-13-standard*, „Unprivilegierter Container“ angehakt):

```bash
pveam update
pveam available --section system | grep debian-13
pveam download local debian-13-standard_13.1-2_amd64.tar.zst   # Name laut Liste

pct create 150 local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst \
  --hostname strom \
  --cores 2 --memory 3072 --swap 512 \
  --rootfs local-lvm:20 \
  --net0 name=eth0,bridge=vmbr0,ip=10.10.0.60/24,gw=10.10.0.1 \
  --unprivileged 1 --features nesting=1 \
  --onboot 1 --start 1
```

Empfehlung: **2 Kerne, 2–4 GB RAM, 20 GB Platte.**

| Ressource | Warum |
|-----------|-------|
| 2 Kerne | InfluxDB, Node-RED und PHP arbeiten gleichzeitig; mit einem Kern stockt die Oberfläche, wenn die Datenbank verdichtet. |
| 2–4 GB RAM | InfluxDB hält Index und Cache im Speicher (etwa 0,5–1 GB), Node-RED rund 150 MB, der Rest ist Luft für Abfragen über lange Zeiträume. Ab einigen Dutzend Messstellen mit hoher Auflösung 4 GB. |
| 20 GB Platte | Fast alles davon ist InfluxDB. Wie viel, hängt an der Meldefrequenz: Ein Pro 3EM schickt seinen Status etwa sekündlich (rund 20 Werte je Meldung) – das sind grob 1–2 GB im Jahr. Relais und Zwischenstecker, die nur bei Änderungen melden, fallen kaum ins Gewicht. Bei vielen sekündlich meldenden Geräten die Platte größer wählen oder mit `--aufbewahrung` begrenzen (Abschnitt 3). Nachmessen: `du -sh /var/lib/influxdb2/engine`. |

Zu den Optionen:

* **unprivilegiert** ist richtig. PowerManagement braucht keine besonderen
  Rechte – auch WireGuard im Container geht unprivilegiert (Abschnitt 7).
* **Nesting** braucht PowerManagement selbst nicht (kein Docker). Proxmox
  setzt es für unprivilegierte Container in der Oberfläche aber ohnehin vor
  und warnt bei Debian 13 (systemd 257), dass systemd ohne Nesting Probleme
  haben kann. Die Dienste von PowerManagement sind gehärtet (`PrivateTmp`,
  `ProtectSystem`) und brauchen dafür eigene Mount-Namensräume. **Also so
  lassen.** Wer es abschaltet und Dienste mit `status=226/NAMESPACE`
  scheitern sieht, schaltet es wieder ein
  (`pct set 150 --features nesting=1`, Container neu starten).
* Die **IP** gehört in das Netz, aus dem die Oberfläche aufgerufen wird und
  in dem die Shellys sitzen – oder in ein Netz, das beide erreichen. Im
  Beispiel `10.10.0.60`; diese Adresse gleich notieren, sie kommt in jeden
  Shelly.
* Eine **feste Adresse** ist Pflicht: Die Shellys haben sie als MQTT-Server
  eingetragen. Wer DHCP nutzt, reserviert die Adresse im Router.

Danach in den Container:

```bash
pct enter 150
```

## 2 · Grundsystem

```bash
apt update && apt full-upgrade -y
apt install -y ca-certificates curl git locales tzdata

timedatectl set-timezone Europe/Berlin   # im LXC ggf. vom Host vorgegeben
sed -i 's/# de_DE.UTF-8/de_DE.UTF-8/' /etc/locale.gen && locale-gen

apt install -y unattended-upgrades       # automatische Sicherheitsupdates
dpkg-reconfigure -plow unattended-upgrades
```

Debian 13 bringt **PHP 8.4** und **Node.js 20** mit – es wird kein
Fremdrepository für PHP oder Node gebraucht. Nur die InfluxDB kommt aus dem
Repository von InfluxData; das richtet das Skript selbst ein.

## 3 · Installation

### Öffentliches Repository

```bash
git clone https://github.com/agrotevent/powermanagement.git /opt/powermanagement
/opt/powermanagement/deploy/install-debian.sh --name="Stromversorgung Messe Nord"
```

Liegt der Checkout woanders, klont das Skript ihn nach `--ziel`
(Vorgabe `/opt/powermanagement`). Ebenso geht es, nur das Skript zu holen
und ihm das Repository zu nennen:

```bash
curl -fsSLO https://raw.githubusercontent.com/agrotevent/powermanagement/main/deploy/install-debian.sh
bash install-debian.sh --repo=https://github.com/agrotevent/powermanagement.git
```

### Privates Repository

Der Container braucht ein Zugangstoken. Es wird **einmal** in eine Textdatei
geschrieben; Installation und alle späteren Updates finden sie von allein.

Token erzeugen: GitHub → *Settings → Developer settings → Personal access
tokens → Fine-grained tokens*, **Repository access: Only select repositories
→ Contents: Read-only**. Ein klassisches Token mit dem Haken `repo` geht
ebenso.

```bash
install -d -m 750 /etc/powermanagement
cat > /etc/powermanagement/git.conf <<'EOF'
GITHUB_TOKEN=github_pat_…
GIT_REPO=https://github.com/agrotevent/powermanagement.git
GIT_BRANCH=main
EOF
chmod 600 /etc/powermanagement/git.conf
```

Dann nur das Skript holen – das Token geht dabei über die Standardeingabe an
curl, nicht über die Kommandozeile, wo es in der Prozessliste stünde:

```bash
sed -n 's/^GITHUB_TOKEN=/Authorization: Bearer /p' /etc/powermanagement/git.conf \
  | curl -fsSL -H @- -H 'Accept: application/vnd.github.raw' \
      -o /root/install-debian.sh \
      'https://api.github.com/repos/agrotevent/powermanagement/contents/deploy/install-debian.sh?ref=main'

bash /root/install-debian.sh --name="Stromversorgung Messe Nord"
```

Repository und Zweig nimmt das Skript aus `git.conf`. Es legt daraus
`/etc/powermanagement/git-credentials` an und hängt es als
`credential.helper` an das Arbeitsverzeichnis. Das Token steht damit **nie**
in der Prozessliste, nie in `.git/config` und nie in der Adresse des Remotes:

```bash
git -C /opt/powermanagement remote get-url origin
# → https://github.com/agrotevent/powermanagement.git  (ohne Token)
```

> **Token abgelaufen?** Neuen Wert in dieselbe Datei schreiben – der nächste
> Lauf übernimmt ihn.

### Optionen

| Option | Wofür |
|--------|-------|
| `--name="Titel"` | Titel der Oberfläche (später unter System → Einstellungen änderbar) |
| `--repo=<url>` / `--zweig=main` | woher installiert wird |
| `--ziel=/opt/powermanagement` | Installationsverzeichnis |
| `--port=80` | Port der Oberfläche, falls 80 belegt ist |
| `--admin=admin` | Name des ersten Administrators |
| `--aufbewahrung=0` | wie lange Messwerte bleiben: `0` = unbegrenzt, sonst Tage (`800`) oder `104w` |
| `--ohne-influx --influx-url=… --influx-token=…` | eine **vorhandene** InfluxDB nutzen (1.8, 2.x oder 3.x) |
| `--influx-db=strom` | Name der Datenbank bzw. des Buckets |
| `--ohne-mosquitto --mqtt-host=… --mqtt-benutzer=… --mqtt-kennwort=…` | einen vorhandenen Broker nutzen (eigene Zugänge je Shelly dann von Hand, Abschnitt 5) |
| `--ohne-nodered` | kein Node-RED (dann muss etwas anderes Shelly-Werte übersetzen und in die InfluxDB schreiben) |
| `--flows-ersetzen` | vorhandene Node-RED-Flows durch die mitgelieferten ersetzen |
| `--git-zugang=<datei>` | Token-Datei an anderer Stelle |

Bei einer vorhandenen **InfluxDB 1.8** mit Anmeldung ist das „Token“
`benutzer:kennwort` – so versteht es 1.8 im `Authorization`-Kopf.

### Was das Skript tut

1. **Pakete:** nginx, PHP 8.4 (FPM, SQLite, GD, cURL, mbstring, XML),
   poppler-utils (Grundrisse), git, Mosquitto, Node.js/npm,
   wireguard-tools. InfluxDB 2 aus dem Repository von InfluxData – der
   Paketschlüssel wird über seinen Fingerabdruck geprüft.
2. **Quellcode** nach `/opt/powermanagement`. Der Code gehört root, nur
   `storage/` gehört `www-data` – die Anwendung kann ihren eigenen Code nicht
   verändern.
3. **`config/config.php`** aus der Vorlage, darin nur ein neuer `app.key`.
   Er verschlüsselt die hinterlegten Zugänge – mitsichern!
4. **Mosquitto:** `/etc/mosquitto/conf.d/powermanagement.conf` (Port 1883
   auf allen Schnittstellen, keine anonyme Anmeldung), drei Benutzer mit
   zufälligen Kennwörtern – und später je Shelly ein eigener:

   | Benutzer | darf | wer |
   |----------|------|-----|
   | `shelly` | alles außer `pm/#` und den Themenbäumen der Shellys mit eigenem Zugang | Shellys ohne eigenen Zugang (gemeinsamer Zugang) |
   | `<präfix>` (Gen1: Geräte-ID) | nur den eigenen Themenbaum `<präfix>/#` (Gen1 `shellies/<id>/#`), dazu `shellies/command` lesen | je ein Shelly mit eigenem Zugang (Abschnitt 5) |
   | `nodered` | alles | Node-RED |
   | `powermanagement` | alles | Oberfläche und Sammler |

   `shelly` kann so nichts direkt unter `pm/` schreiben. **Grenze:** Alle
   Shellys ohne eigenen Zugang teilen sich diesen einen, und er darf die
   Themen all dieser Geräte lesen und schreiben. Wer ihn kennt (aus einem
   Shelly ausgelesen oder unverschlüsselt im Netz mitgeschnitten – Port
   1883 ist ohne TLS), kann jedes Relais daran schalten und über die rohen
   Geräte-Themen, die Node-RED weiterverarbeitet, Messwerte vortäuschen.
   Deshalb: die Shellys nach und nach auf **eigene Zugänge** umstellen
   (Abschnitt 5) – ein umgestelltes Gerät erreicht der gemeinsame Zugang
   nicht mehr –, den Broker per Firewall nur aus den Netzen der Geräte
   erreichbar machen (Abschnitt 8) und das Kennwort wie einen Schlüssel zur
   Anlage behandeln.
   Die Kennwörter werden gehasht eingetragen, ohne je auf der Kommandozeile
   (und damit in der Prozessliste) zu stehen.

   Die Rechte stehen in `/etc/mosquitto/powermanagement.acl` (Grundlage).
   Mosquitto liest daraus die **wirksame** Fassung
   `/etc/mosquitto/powermanagement-wirksam.acl`, die
   `deploy/mqtt-zugaenge.sh` baut: Grundlage plus je Shelly mit eigenem
   Zugang eine Sperre `topic deny <präfix>/#` für `shelly`. Der Helfer läuft
   als root, gestartet von `powermanagement-mqtt-zugaenge.path` (sobald die
   Oberfläche etwas ändert) und `.timer` (alle zwei Minuten); die Oberfläche
   selbst darf `/etc/mosquitto` nicht anfassen.
5. **InfluxDB 2:** Bis die Ersteinrichtung gemacht ist, lauscht sie nur
   auf `127.0.0.1` – sonst könnte ihr im Netz jemand zuvorkommen und
   Administrator und Token anlegen. Danach ist sie wie gewohnt unter
   `http://<IP>:8086` erreichbar. Ersteinrichtung mit Organisation `powermanagement`,
   Bucket `strom`, Benutzer `admin` (zufälliges Kennwort), eine DBRP-Zuordnung
   `strom/autogen → strom` für InfluxQL, und zwei Tokens: eines zum Lesen und
   Schreiben für Oberfläche und Sammler, eines nur zum Schreiben für
   Node-RED.
6. **Node-RED 4** in `/opt/node-red` (eigene `package.json`, gehört root),
   Flows und Einstellungen im userDir `/var/lib/powermanagement/node-red`
   (gehört dem Systembenutzer `nodered`). Editor nur über nginx unter
   `/node-red/`, Anmeldung `admin` mit zufälligem Kennwort; nginx lässt je
   Adresse nur fünf Anmeldeversuche in der Minute durch.
7. **Oberfläche:** PHP-FPM-Pool, nginx-Site, Datenbank, Administrator,
   InfluxDB- und MQTT-Zugang (verschlüsselt in der Datenbank).
8. **Dienste:** `powermanagement-nodered`, `powermanagement-sammler`,
   `powermanagement-wartung.timer` (täglich),
   `powermanagement-mqtt-zugaenge.path` und `.timer` (eigene MQTT-Zugänge,
   nur mit Mosquitto im Container), `powermanagement-update.path` und
   `.timer` sowie `powermanagement-update-zeitplan.timer` (Update aus der
   Oberfläche und automatisch, Abschnitt 10), Logrotate.
9. **Prüfung** mit `bin/power pruefen` und eine Zusammenfassung mit allen
   Zugängen.

Alle erzeugten Kennwörter und Tokens stehen zusätzlich in
**`/etc/powermanagement/zugaenge.txt`** (nur root). Das Skript ist
wiederholbar: Ein zweiter Lauf verwendet die Kennwörter aus dieser Datei
weiter, ändert nichts an Datenbank und `config.php` und überschreibt keine
eigenen Änderungen an Flows, Node-RED-Einstellungen, nginx-Site oder
Mosquitto-ACL – neue Fassungen solcher Dateien legt es mit
`powermanagement-neu` im Namen daneben. Einen Administrator legt es nur an,
solange die Datenbank noch keinen Benutzer hat – etwa wenn der erste Lauf
vorher abbrach (dann mit neuem Erstkennwort in der Zusammenfassung). Gibt es
Benutzer, legt es niemanden an, auch keinen gelöschten oder umbenannten
`admin`.

| Datei | Inhalt |
|-------|--------|
| `/etc/powermanagement/zugaenge.txt` | alle Zugänge (600, root) |
| `/etc/powermanagement/node-red.env` | Umgebung für Node-RED: Broker, InfluxDB-Adresse und Token (640, root:nodered) |
| `/etc/powermanagement/installation.conf` | was installiert wurde – liest das Update-Skript |
| `/var/lib/powermanagement/node-red/` | Flows, `flows_cred.json` (Broker-Anmeldung, 600), `settings.js`, `admin-kennwort.bcrypt` |
| `/etc/mosquitto/powermanagement.passwd` | Broker-Benutzer (nur Hashes); oben der Block der eigenen Zugänge – den schreibt nur der Helfer |
| `/etc/mosquitto/powermanagement.acl` | Grundlage der Rechte – Änderungen von Hand nur hier |
| `/etc/mosquitto/powermanagement-wirksam.acl` | was Mosquitto liest: Grundlage + Sperren für den gemeinsamen Zugang; wird bei jedem Lauf des Helfers neu geschrieben |
| `/opt/powermanagement/storage/mqtt/` | `soll/zugaenge.txt` (schreibt die Oberfläche: Konten, Hashes, Sperren), `ist/ergebnis.txt` (schreibt der Helfer: was gilt, Fehler) |
| `/opt/powermanagement/deploy/mqtt-zugaenge.sh` | der Helfer (root): prüft das Soll und überträgt es nach `/etc/mosquitto` |
| `/etc/systemd/system/powermanagement-mqtt-zugaenge.{service,path,timer}` | starten den Helfer bei jeder Änderung und alle zwei Minuten |
| `/etc/mosquitto/powermanagement-mqtt-zugaenge.lock` | Sperre: Helfer und Installationsskript schreiben die Kennwortdatei nie gleichzeitig (nur root) |

## 4 · Erste Anmeldung

| Was | Adresse | Anmeldung |
|-----|---------|-----------|
| Oberfläche | `http://10.10.0.60/` | `admin` + Erstkennwort aus der Zusammenfassung – bei der ersten Anmeldung ein eigenes vergeben |
| Node-RED-Editor | `http://10.10.0.60/node-red/` | `admin` + Kennwort aus `zugaenge.txt` |
| InfluxDB | `http://10.10.0.60:8086/` | `admin` + Kennwort aus `zugaenge.txt` |

Danach in der Oberfläche unter **System → Einstellungen** Titel und
Grenzwerte prüfen.

Im Node-RED-Editor gibt es vier Tabs: **PowerManagement** (Überblick,
Eingang `pm/messwert/#`), **Shelly Gen2+**, **Shelly Gen1** und
**Schreiben**. Am Node *InfluxDB-Schreiber* steht, wie viele Zeilen
geschrieben wurden oder welcher Fehler zuletzt auftrat. Eigene Flows
dürfen dazukommen – Updates überschreiben nichts.

Das Kennwort des Node-RED-Editors ändern:

```bash
KW='NeuesKennwort'   # nur Buchstaben/Ziffern vermeidet Ärger mit der Shell
PM_KENNWORT="$KW" php -r '$h = password_hash(getenv("PM_KENNWORT"), PASSWORD_BCRYPT); echo "\$2b\$", substr($h, 4), "\n";' \
  > /var/lib/powermanagement/node-red/admin-kennwort.bcrypt
systemctl restart powermanagement-nodered
```

(PHP schreibt bcrypt als `$2y$`, Node-RED erwartet `$2b$` – das Verfahren
ist dasselbe.) Danach den Wert in `zugaenge.txt` nachtragen.

## 5 · Shelly-Geräte anbinden

Zum Anbinden verwenden alle Shellys denselben, **gemeinsamen** Zugang:
Server `10.10.0.60:1883`, Benutzer **`shelly`**, Kennwort
`MQTT_SHELLY_KENNWORT` aus `zugaenge.txt`. Ohne TLS – der Broker ist nur im
LAN bzw. über WireGuard erreichbar (Abschnitt 8). Danach lässt sich jedes
Gerät einzeln auf einen **eigenen Zugang** umstellen (weiter unten).

### Gen2+ (Plus, Pro, Gen3, Gen4 – etwa Pro 3EM, Plus 1PM, Plug S)

In der Weboberfläche des Geräts unter **Einstellungen → MQTT** (je nach
Firmware *Settings → Connectivity → MQTT*):

1. **Enable MQTT** einschalten
2. **Server:** `10.10.0.60:1883`
3. **Username / Password:** `shelly` / Kennwort
4. **MQTT prefix:** Vorgabe (die Geräte-ID) lassen – oder `shelly/<name>`,
   etwa `shelly/hauptverteilung`. Der Gerätename in PowerManagement ist das
   letzte Segment, also `shellypro3em-a0b1c2d3e4f5` bzw. `hauptverteilung`.
5. **„Generic status update over MQTT“** einschalten – darüber kommen die
   Messwerte.
6. **„RPC status notifications over MQTT“** einschalten.
7. **„RPC over MQTT“ bzw. „Enable MQTT Control“** eingeschaltet lassen
   (Vorgabe): Darüber fragt Node-RED Modell und Firmware ab, und die
   Oberfläche schaltet Relais.
8. Speichern – das Gerät startet neu.

Die Shelly-Cloud darf bei Gen2+ eingeschaltet bleiben.

### Gen1 (Shelly 1PM, 2.5, Plug S, EM, 3EM …)

In der Weboberfläche des Geräts unter **Internet & Security → Advanced –
Developer Settings**:

1. **Enable action execution via MQTT** anhaken
2. **Username / Password:** `shelly` / Kennwort
3. **Server:** `10.10.0.60:1883`
4. **Max. times reconnect / Clean session / Retain** auf Vorgabe lassen
5. Speichern – das Gerät startet neu.

**Achtung:** Bei Gen1 schaltet MQTT die Shelly-Cloud ab – beides zugleich
geht dort nicht.

Energie von Relais meldet Gen1 in *Wattminuten*; Node-RED rechnet in Wh
um. Die Summe über die Phasen eines EM/3EM bildet Node-RED selbst, sobald
von allen Phasen ein frischer Wert vorliegt.

### Eigener Zugang je Shelly

Der gemeinsame Zugang hat einen Haken: Wer sein Kennwort aus *einem* Gerät
ausliest, kann *jedes* Gerät daran schalten und mitlesen. Ein eigener
Zugang darf nur den Themenbaum seines Geräts (`<präfix>/#`, Gen1
`shellies/<id>/#`). Sobald bestätigt ist, dass das Gerät ihn nutzt, sperrt
der Broker dem gemeinsamen Zugang diesen Baum – erst damit ist das Gerät
geschützt. Geräte ohne eigenen Zugang arbeiten unverändert weiter; umgestellt
wird Gerät für Gerät, im laufenden Betrieb.

Das geht in der Oberfläche unter **Messtechnik → MQTT-Zugänge**; umstellen
darf, wer das Recht `mqtt.zugaenge` hat (nur zusammen mit „Alle Standorte,
Anlagen und Messdaten“). Die Liste zeigt jeden Shelly, den Node-RED
gemeldet hat, mit seinem Zugang. Benutzername am Broker ist das
MQTT-Präfix des Geräts (`shellypro4pm-a0b1c2d3e4f5` bzw.
`shelly/hauptverteilung`), bei Gen1 die Geräte-ID (`shelly1-B929CC`).
PowerManagement speichert das Kennwort nirgends im Klartext – nur den Hash
beim Broker. Melden sich unter demselben Gerätenamen mehrere Präfixe
(Doppelgänger, etwa `kuehlhaus` und `shelly/kuehlhaus`), lässt sich keines
umstellen, bis geklärt ist, welches das echte Gerät ist.

**Gen2+**

1. Beim Gerät **„Eigenen Zugang einrichten“**. Der Zugang wird am Broker
   angelegt und per `MQTT.SetConfig` ans Gerät übertragen – nur Benutzer und
   Kennwort; Server, Präfix und alle anderen Einstellungen bleiben. Oder
   **„von Hand“**: Benutzer und Kennwort erscheinen einmal und werden in der
   Weboberfläche des Geräts unter *Settings → MQTT* eingetragen – dann geht
   nichts davon über MQTT (siehe „Grenzen“).
2. Den Shelly **neu starten** (Weboberfläche des Geräts → *Reboot*, oder
   Strom aus und wieder ein). Erst dann meldet er sich mit dem neuen Zugang
   an. PowerManagement startet nie selbst ein Gerät neu; bis zum Neustart
   gelten beide Zugänge, die Messung läuft durch.
3. **„Umstellung prüfen“**. Der gemeinsame Zugang wird dafür bis zu 90 s
   auf dieses Gerät gesperrt. Antwortet das Gerät trotzdem, nutzt es den
   eigenen Zugang: Zustand „eigener Zugang“, die Sperre bleibt dauerhaft.
   Antwortet es nicht (noch nicht neu gestartet), fällt die Sperre sofort
   wieder weg und nichts ist verloren.

**Gen1**

1. **„Eigenen Zugang einrichten“** – Benutzer und Kennwort erscheinen
   **genau einmal**.
2. In der Weboberfläche des Geräts unter *Internet & Security → Advanced –
   Developer Settings* bei **Username / Password** eintragen, Server und
   alles andere lassen, speichern (das Gerät startet neu).
3. **„Umstellung prüfen“** (Sperre bis zu 120 s).

**Später**

* **Neues Kennwort:** erscheint einmal und wird am Gerät eingetragen (bei
  beiden Generationen; nie per MQTT – wer das bisherige Gerätekennwort
  kennt, dürfte dort mitlesen). Der Broker nimmt sofort nur noch das neue
  an, bis zum Eintragen ist das Gerät getrennt. Auch der Weg, wenn ein Gerät
  mit eigenem Zugang durch ein neues mit gleichem Präfix ersetzt wird.
* **Entfernen:** Konto und Sperre fallen weg. Nutzt das Gerät den eigenen
  Zugang schon, ist es sofort getrennt – am Gerät wieder `shelly` und das
  gemeinsame Kennwort eintragen. Bei Gen2+ vor der Bestätigung fragt die
  Oberfläche erst das Gerät: hat es den Zugang schon gespeichert, nutzte es
  ihn ab dem nächsten Neustart – dann nur mit **erzwingen**.

**Grenzen**

* Die Übertragung per `MQTT.SetConfig` läuft durch den Themenbaum des
  Geräts, den der gemeinsame Zugang zu diesem Zeitpunkt noch lesen darf.
  Wer das gemeinsame Kennwort kennt und genau dann mitliest, sieht das neue
  Gerätekennwort. Wer das ausschließen will, richtet „von Hand“ ein.
* Mosquitto bindet Client-IDs nicht an Benutzer: Wer das gemeinsame
  Kennwort kennt, kann ein umgestelltes Gerät vom Broker werfen, indem er
  sich mit dessen Client-ID anmeldet. Schalten oder mitlesen kann er es
  nicht.
* Den Gen1-Rundruf `shellies/command` darf der gemeinsame Zugang weiter
  schreiben (`announce`, `update`, `update_fw` – nur offizielle Firmware);
  Gen1-Geräte am gemeinsamen Zugang brauchen ihn.
* Gespeicherte (retained) Nachrichten, die der gemeinsame Zugang vor der
  Umstellung im Baum des Geräts abgelegt hat, liefert der Broker danach
  nicht mehr aus (`check_retain_source true` in
  `/etc/mosquitto/conf.d/powermanagement.conf`).
* Den Schutz vor Doppelgänger-Präfixen in den Messwerten leistet Node-RED
  (Liste unter `pm/status/mqtt-zugaenge`). Wer die Flows im Editor geändert
  hat und sie beim Update behält, hat ihn nicht – die Seite „MQTT-Zugänge“
  meldet dann, dass Node-RED die Liste nicht bestätigt.

Hinter den Kulissen schreibt die Oberfläche den Sollzustand nach
`storage/mqtt/soll/zugaenge.txt`; `powermanagement-mqtt-zugaenge.path`
startet daraufhin `deploy/mqtt-zugaenge.sh`. Der Helfer prüft das Soll
streng (bei einem Fehler ändert er nichts), schreibt den Block oben in
`/etc/mosquitto/powermanagement.passwd` und die wirksame ACL, lädt
Mosquitto neu (SIGHUP – bestehende Verbindungen bleiben) und legt das
Ergebnis nach `storage/mqtt/ist/ergebnis.txt` (als `www-data`). Der
Zeitgeber wiederholt das alle zwei Minuten und räumt dabei abgelaufene
Prüf-Sperren ab. Von Hand anstoßen: `systemctl start
powermanagement-mqtt-zugaenge.service`. Der Dienst läuft mit
`ProtectSystem=full` und darf nur `/etc/mosquitto` schreiben – kein Pfad,
den die Anwendung besitzt, damit ein Verweis dort ihm nichts freigibt.
Laufen Installation oder Update den Helfer ohne Neuladen (sie starten den
Broker selbst), legt er bei Änderungen die Marke
`/etc/mosquitto/powermanagement-mqtt-zugaenge.neuladen` an; bricht der
Aufrufer danach ab, holt der nächste Lauf das Neuladen nach.
Auf der Kommandozeile zeigt `bin/power mqtt:zugaenge` (als `www-data`),
welches Gerät welchen Zugang hat.

**Nicht umstellbar** sind Geräte, deren Präfix sich nicht als Benutzername
eignet: Gen2+ nur Kleinbuchstaben, Ziffern, `_ . -` (bis 64 Zeichen) oder
`shelly/<name>`; Gen1-IDs dürfen auch Großbuchstaben enthalten. Reserviert
sind `shelly`, `shellies`, `nodered`, `powermanagement`, `pm`, `command`,
`announce`, alles mit `pm-`, `pm_`, `pm.` am Anfang sowie Namen, die schon
von Hand in der Kennwortdatei stehen. Solche Geräte bleiben beim
gemeinsamen Zugang – oder bekommen im Gerät ein anderes Präfix.

**Fremder Broker (`--ohne-mosquitto`):** Dann richtet die Installation
weder den Helfer noch seine Einheiten ein, und die Oberfläche kann nichts
umstellen. Eigene Zugänge lassen sich dort von Hand nach demselben Schema
anlegen: je Gerät ein Benutzer = Präfix (Gen1: Geräte-ID) mit eigenem
Kennwort, in der ACL dieselben drei Zeilen wie am Ende von
`deploy/mosquitto/powermanagement.acl`

```
pattern readwrite %u/#
pattern readwrite shellies/%u/#
pattern read shellies/command
```

und für den gemeinsamen Zugang je umgestelltem Gerät eine Sperre:

```
user shelly
topic deny shellypro4pm-a0b1c2d3e4f5/#
topic deny shellies/shelly1-B929CC/#
```

Dazu in der Broker-Konfiguration `check_retain_source true`, damit
gespeicherte Nachrichten des gemeinsamen Zugangs im gesperrten Baum nicht
weiter ausgeliefert werden. Das Kennwort am Gerät von Hand eintragen (Gen2+:
*Settings → MQTT*), Gerät neu starten, dann sperren und Broker neu laden.

### Prüfen

```bash
KW="$(sed -n 's/^MQTT_POWERMANAGEMENT_KENNWORT=//p' /etc/powermanagement/zugaenge.txt)"

# Rohdaten der Shellys
mosquitto_sub -h 127.0.0.1 -u powermanagement -P "$KW" -t '+/status/#' -t 'shelly/#' -t 'shellies/#' -v

# Was Node-RED und der Sammler daraus machen
mosquitto_sub -h 127.0.0.1 -u powermanagement -P "$KW" -t 'pm/#' -v
```

Ein neues Gerät erscheint nach wenigen Sekunden in der Messung `geraet`
und damit in der Geräteliste der Oberfläche.

## 6 · Victron und ComAp

Beide fragt der **Sammler** ab (`powermanagement-sammler.service`); die
Datenquellen werden in der Oberfläche angelegt. Der Sammler veröffentlicht
die Werte auf `pm/messwert/<gerät>/<kanal>`, Node-RED schreibt sie in die
InfluxDB.

### Victron GX (Cerbo GX, Ekrano, Venus GX)

* **Modbus TCP einschalten:** am GX bzw. in der Remote Console unter
  **Einstellungen → Dienste → Modbus TCP → Aktivieren** (neuere
  Venus-OS-Fassungen: *Einstellungen → Integrationen → Modbus-TCP-Server*).
  Unter
  *Verfügbare Dienste* stehen die Unit-IDs der angeschlossenen Geräte
  (System meist `100`, VE.Bus z. B. `227`, Batteriemonitor je nach Anschluss).
* Port **502**. Der GX muss vom Container aus erreichbar sein – im LAN direkt,
  an entfernten Standorten über WireGuard (Abschnitt 7).
* Modbus TCP hat **keine Anmeldung**. Der Port gehört deshalb nie ins
  Internet; am Standort-Router nur aus dem Tunnel bzw. von der Adresse des
  Containers zulassen.
* Zusätzlich oder statt Modbus: **VRM-API**. Im VRM-Portal unter
  *Einstellungen → Integrationen → Zugriffstoken* ein Token anlegen; dazu die
  Installations-ID (`idSite`, steht in der Adresse der Installation im VRM).
  Werte über VRM sind grob (Minutenraster) und hängen an der
  Internetverbindung des Standorts – Modbus über WireGuard ist direkter.

### ComAp (InteliGen 200 u. a.)

* **Modbus TCP am Controller:** in InteliConfig bzw. am Display den
  Modbus-TCP-Server einschalten (Kommunikationseinstellungen, Port 502) und
  eine feste IP vergeben. Welche Register der Controller liefert, zeigt
  InteliConfig (*Modbus-Registerliste erzeugen*); PowerManagement bringt
  Registerprofile mit.
* **WebSupervisor-API** (Cloud), wenn die Anlage ohnehin in WebSupervisor
  hängt: Im Entwicklerportal **portal.websupervisor.net** anmelden, das API-
  Produkt abonnieren – daraus kommt der **ComAp-Key** (Subscription-Key) –
  und eine Anwendung anlegen, die **clientId** und **secret** liefert. Alle
  drei Werte gehören in die Datenquelle in der Oberfläche; sie werden
  verschlüsselt abgelegt.

## 7 · WireGuard zu entfernten Standorten

Victron- und ComAp-Anlagen an anderen Standorten erreicht der Sammler über
einen WireGuard-Tunnel. Das Installationsskript installiert nur die
Werkzeuge (`wireguard-tools`) und richtet **keinen** Tunnel ein – wie der
Tunnel aussieht, hängt am Netz.

### a) Empfohlen: Tunnel auf dem MikroTik

RouterOS 7 kann WireGuard. Der Tunnel endet auf dem Router in der Zentrale
und auf dem Router am Standort; der Container merkt davon nichts und braucht
höchstens eine Route.

```
 LXC 10.10.0.60 ── MikroTik Zentrale ══ WireGuard ══ MikroTik Standort ── GX 192.168.50.10
                    10.99.0.1                         10.99.0.2
```

**Zentrale** (feste IP oder DynDNS, UDP-Port offen):

```routeros
/interface wireguard add name=wg-standorte listen-port=13231
/ip address add address=10.99.0.1/24 interface=wg-standorte
/interface wireguard peers add interface=wg-standorte comment="Messe Nord" \
    public-key="<öffentlicher Schlüssel Standort>" \
    allowed-address=10.99.0.2/32,192.168.50.0/24
/ip route add dst-address=192.168.50.0/24 gateway=wg-standorte
/ip firewall filter add chain=input protocol=udp dst-port=13231 action=accept \
    comment="WireGuard Standorte" place-before=0
```

**Standort** (wählt sich ein – so geht es auch hinter NAT und mit
wechselnder IP):

```routeros
/interface wireguard add name=wg-zentrale listen-port=13231
/ip address add address=10.99.0.2/24 interface=wg-zentrale
/interface wireguard peers add interface=wg-zentrale \
    public-key="<öffentlicher Schlüssel Zentrale>" \
    endpoint-address=zentrale.example.de endpoint-port=13231 \
    allowed-address=10.99.0.0/24,10.10.0.0/24 persistent-keepalive=25s
/ip route add dst-address=10.10.0.0/24 gateway=wg-zentrale
# Modbus nur vom Container aus
/ip firewall filter add chain=forward src-address=10.10.0.60 dst-address=192.168.50.10 \
    protocol=tcp dst-port=502 action=accept place-before=0
```

Den öffentlichen Schlüssel jeder Seite zeigt
`/interface wireguard print` (Spalte *public-key*).

**Im Container:** Ist der MikroTik der Zentrale das Standard-Gateway des
Containers, ist nichts zu tun. Sonst eine Route über ihn eintragen (hier
liegt er auf `10.10.0.2`) – am einfachsten als kleiner Dienst, weil Proxmox
`/etc/network/interfaces` im Container verwaltet:

```bash
cat > /etc/systemd/system/route-standorte.service <<'EOF'
[Unit]
Description=Routen zu den Standorten über den MikroTik
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/ip route replace 192.168.50.0/24 via 10.10.0.2

[Install]
WantedBy=multi-user.target
EOF
systemctl enable --now route-standorte.service
```

Prüfen: `nc -vz 192.168.50.10 502` aus dem Container.

### b) Tunnel im Container selbst

Wenn es keinen passenden Router gibt, endet der Tunnel im LXC. WireGuard
ist Teil des Kernels – der Container teilt ihn mit dem Proxmox-Host und kann
das Modul nicht selbst laden.

**Auf dem Proxmox-Host** (im PVE-Kernel enthalten):

```bash
modprobe wireguard
echo wireguard > /etc/modules-load.d/wireguard.conf   # auch nach dem Neustart
```

**Im Container:**

```bash
umask 077
wg genkey | tee /etc/wireguard/privat.key | wg pubkey > /etc/wireguard/oeffentlich.key
cat /etc/wireguard/oeffentlich.key     # kommt auf die Gegenseite

cat > /etc/wireguard/wg0.conf <<'EOF'
[Interface]
Address = 10.99.0.10/32
PrivateKey = <Inhalt von /etc/wireguard/privat.key>
# Kein DNS= – dafür bräuchte wg-quick resolvconf, das im LXC fehlt.

[Peer]
# Standort Messe Nord (Router oder GX-Netz)
PublicKey = <öffentlicher Schlüssel der Gegenseite>
Endpoint = standort-nord.example.de:13231
AllowedIPs = 10.99.0.0/24, 192.168.50.0/24
PersistentKeepalive = 25
EOF
chmod 600 /etc/wireguard/wg0.conf

systemctl enable --now wg-quick@wg0
wg show
```

Hinweise für **unprivilegierte** Container:

* Das Interface anlegen geht: root im Container darf in seinem eigenen
  Netz-Namensraum Interfaces erzeugen – nur das Modul muss auf dem Host
  geladen sein. Fehlt es, meldet `wg-quick` *„RTNETLINK answers: Not
  supported“*.
* **Nur die Standort-Netze** in `AllowedIPs` eintragen, nicht `0.0.0.0/0`.
  Für einen vollen Tunnel will `wg-quick` Kernel-Einstellungen setzen
  (`src_valid_mark`), die ein unprivilegierter Container nicht setzen darf.
* Auf der Gegenseite muss die Adresse des Containers (`10.99.0.10`) den
  Weg zurück finden.

## 8 · Firewall

Welche Ports offen sein müssen – und für wen:

| Port | Dienst | erreichbar für |
|------|--------|----------------|
| 80 (bzw. `--port`), 443 bei TLS | Oberfläche, Node-RED-Editor | LAN, Proxy |
| 1883 | MQTT | nur Shellys: LAN und Tunnel-Netze |
| 8086 | InfluxDB | nur lokal – bzw. zusätzlich ein Grafana-Server |
| 1880 | Node-RED direkt | nur lokal (lauscht ohnehin nur auf 127.0.0.1) |
| 22 | SSH | Verwaltungsnetz |

Beispiel mit nftables im Container (Netze anpassen: `10.10.0.0/24` LAN,
`10.99.0.0/24` Tunnel):

```bash
apt install -y nftables
cat > /etc/nftables.conf <<'NFT'
#!/usr/sbin/nft -f
flush ruleset
table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;
        ct state established,related accept
        iif lo accept
        ip protocol icmp accept
        ip saddr 10.10.0.0/24 tcp dport { 22, 80, 443 } accept
        ip saddr { 10.10.0.0/24, 10.99.0.0/24 } tcp dport 1883 accept   # Shellys
        # ip saddr 10.10.0.20 tcp dport 8086 accept                     # Grafana
        udp dport 13231 accept                                           # nur bei WireGuard im Container
    }
    chain forward { type filter hook forward priority 0; policy drop; }
    chain output  { type filter hook output priority 0; policy accept; }
}
NFT
systemctl enable --now nftables
```

> **Achtung:** Die Regel für Port 80 muss bleiben – sonst ist die Oberfläche
> nach dem Neustart nicht mehr erreichbar. Wer lieber die Proxmox-Firewall
> nutzt, setzt dieselben Regeln dort für den Container.

## 9 · Von außen erreichbar machen (optional)

Wie bei MasterAdmin übernimmt das ein Tunnel oder Reverse-Proxy (Pangolin,
nginx auf einem anderen Host), der **getrennt** läuft und auf
`http://10.10.0.60` zeigt. Dabei:

1. **Echte Client-IP:** in `/etc/nginx/sites-available/powermanagement`
   die Zeilen `set_real_ip_from <Adresse des Proxys>;` und
   `real_ip_header X-Forwarded-For;` aktivieren, dieselbe Adresse in
   `config/config.php` unter `security.trusted_proxies` eintragen,
   `systemctl reload nginx`.
2. **TLS** endet am Proxy; er reicht `X-Forwarded-Proto: https` durch, und
   PowerManagement setzt das Sitzungscookie dann auf `secure`.
3. **Zusätzliche Anmeldung** am Proxy (SSO, PIN) ist empfehlenswert.
   `/node-red/` von außen am besten gar nicht freigeben – der Editor kann
   alles, was Node-RED kann.
4. **MQTT nicht über den Proxy ins Internet** stellen. Entfernte Shellys
   kommen über WireGuard (Abschnitt 7).

Die nginx-Site passt das Update-Skript nie ungefragt an; eigene Zeilen
bleiben also erhalten.

## 10 · Update

```bash
# Erst ansehen, was sich ändern würde – holt per git, ändert sonst nichts
/opt/powermanagement/deploy/update-debian.sh --pruefen

# Dann aktualisieren
/opt/powermanagement/deploy/update-debian.sh
```

So geht es als root auf der Konsole. Seit der Fassung mit
**Update-Funktion** geht es auch ohne root: über **System → Update** in der
Oberfläche, **automatisch** in einem Zeitfenster oder als Auftrag von der
Konsole (`bin/power update:starten`) – alle Wege führen zu diesem Skript
(*Über die Oberfläche*, *Automatische Updates* und *Einmalig über die
Konsole*, unten). Eine ältere Installation steigt dafür **einmal** auf der
Konsole um: *Erstes Update auf die Fassung mit Update-Funktion*.

### Was das Update-Skript tut

Es läuft in zwei Stufen: Die installierte Fassung prüft, holt, sichert und
checkt den neuen Stand aus; danach übernimmt die **neue** Fassung des
Skripts den Rest – so laufen immer die Schritte, die zum neuen Code gehören.
Die Schritte (die Oberfläche zählt sie mit):

1. **Stand prüfen:** Der ausgecheckte Zweig bleibt (`--zweig=<name>` wählt
   einen anderen). Lokale Änderungen am Code brechen ab (`--erzwingen`
   verwirft sie).
2. **Änderungen holen** (Token aus `/etc/powermanagement/git.conf`; ein
   hängender Abruf bricht nach zehn Minuten ab). Eingespielt wird nur ein
   Stand, der **vorwärts** vom installierten liegt – kein Rückschritt, kein
   umgeschriebener Verlauf (sonst nur mit `--erzwingen`).
3. **Sicherung:** Es prüft den freien Platz, sperrt die Oberfläche für
   Änderungen (*Schreibsperre*, unten), hält den Sammler an und sichert
   `config/config.php` und die Datenbank nach
   `storage/backups/vor-update-<zeit>.tar.gz` (die letzten fünf bleiben;
   die Datenbank als stimmige Kopie per `VACUUM INTO`, auch im laufenden
   Betrieb). Die eigene Fassung legt es nach
   `/var/lib/powermanagement/update/alt/` – damit rollt der Update-Dienst
   ein unterbrochenes Update zurück.
4. **Quellcode** auf den neuen Stand; ab hier übernimmt die neue Fassung
   (sie muss vorher `bash -n` bestehen).
5. **Rechte und Ordner** wie bei der Installation (Code root, `storage/`
   www-data).
6. **Migrationen** (`bin/power migrate`).
7. **Fehlende Pakete** nachziehen – apt wartet, bis eine laufende
   Paketinstallation (etwa unattended-upgrades) fertig ist, und behält von
   Hand geänderte Konfigurationsdateien.
8. **Abgleichen:** systemd-Einheiten, PHP-FPM-Pool, Logrotate, nginx-Site
   und die Mosquitto-Dateien: Grundlage der ACL, eigene Zugänge
   (`deploy/mqtt-zugaenge.sh` baut die wirksame ACL neu), Konfiguration –
   Mosquitto wird dafür nur neu geladen, die Shellys bleiben verbunden;
   neu gestartet wird er nur, wenn sich die Konfiguration ändert.
9. **Dienste starten:** PHP-FPM nach `php-fpm -t` nur neu laden (die
   Oberfläche ist kaum weg), nginx nach `nginx -t`, den Sammler neu starten,
   Node-RED nur, wenn sich Einheit, Einstellungen oder Flows ändern.
10. **Grundrisse kacheln.**
11. **Prüfen.** *Pflicht:* Die Anmeldeseite antwortet mit HTTP 200 (wenn
    sie es vorher tat), PHP-FPM, nginx und alle Dienste, die vorher liefen,
    laufen (der Sammler ohne Neustarts), und `bin/power pruefen` erreicht
    die Datenbank. *Wunsch:* `bin/power pruefen` ganz ohne Fehler (InfluxDB,
    MQTT, Node-RED). Meldet es nach anderthalb Minuten noch Fehler, die es
    vorher nicht gab, bleibt das Update eingespielt („eingespielt, mit
    Hinweisen“) – solche Störungen haben meist äußere Gründe, und ein
    Zurückrollen wäre nur ein weiterer Neustart.
12. **Fertig:** Schreibsperre aus, `Vorher:`/`Jetzt:`, die Sicherung und
    die Befehle, mit denen sich das Update von Hand zurücknehmen lässt.

**Geht dabei etwas schief, kommt der alte Stand vollständig zurück** –
Quellcode und Zweig, Datenbank (wenn schon migriert), Einheiten und
Vorlagen: Was die neue Fassung neu angelegt hat, wird entfernt, dann gleicht
die **alte** Fassung des Skripts alles wieder ab (`--nur-abgleichen`). Das
gilt auch bei einem Abbruch: Strg+C, ein abreißendes Terminal,
`systemctl stop` oder das Herunterfahren beenden den laufenden Schritt, dann
wird zurückgerollt. Stirbt das Update ganz (Strom weg, `kill -9`,
`pct stop`), bleibt die Marke `/var/lib/powermanagement/update/offen`
stehen, und der Update-Dienst rollt selbst zurück – nach einem Neustart
zwei Minuten danach, sonst spätestens nach 15 Minuten (*Zurückrollen von
Hand*, unten). Scheitert ein Stand selbst (nicht nur ein Abbruch oder
Absturz), wird er als gescheitert vermerkt: Die Automatik versucht ihn nie
wieder.

**Node-RED-Flows:** Hat niemand die Flows im Editor verändert, werden sie
durch die neue Fassung ersetzt. Wurden sie verändert, bleiben sie, wie sie
sind; die neue Fassung liegt dann unter
`/var/lib/powermanagement/node-red/flows.powermanagement-neu.json` und kann
im Editor über *Menü → Importieren* übernommen werden – oder mit
`--flows-ersetzen` (die alten werden als `flows.json.vor-<zeit>` gesichert).
Dasselbe gilt für `settings.js`, die nginx-Site und die Mosquitto-Dateien.

**Mosquitto-Rechte von Hand ändern** nur in der Grundlage
`/etc/mosquitto/powermanagement.acl` (erlaubt: Zeilen mit `user`, `topic`,
`pattern` und Kommentare), danach
`systemctl start powermanagement-mqtt-zugaenge.service`. Die wirksame
Datei `powermanagement-wirksam.acl` und den Block oben in der
Kennwortdatei schreibt der Helfer bei jedem Lauf neu – Änderungen dort sind
nach spätestens zwei Minuten weg. Ist die Grundlage verändert, bleibt sie
beim Update, wie sie ist; fehlen ihr die `pattern`-Zeilen für die eigenen
Zugänge, stehen sie in `powermanagement.acl.powermanagement-neu` daneben
und müssen von Hand übernommen werden (sonst dürfen eigene Zugänge nichts,
und die Oberfläche meldet es). Eine von Hand veränderte
`conf.d/powermanagement.conf` braucht für die Sperren die Zeile
`acl_file /etc/mosquitto/powermanagement-wirksam.acl`.

**Update von einer Fassung ohne eigene MQTT-Zugänge:** Das laufende
Update-Skript ist dabei noch das alte. Es holt den neuen Code und spielt
eine Übergangsfassung der Mosquitto-Konfiguration ein
(`deploy/mosquitto/powermanagement.conf` – Mosquitto liest damit weiter die
Grundlage `powermanagement.acl`); Broker und Shellys laufen weiter, die
eigenen Zugänge aber noch nicht – die Oberfläche meldet unter
*MQTT-Zugänge*, dass der Broker-Abgleich nicht eingerichtet ist. Dann
`update-debian.sh` **ein zweites Mal** aufrufen: Jetzt läuft das neue
Skript; es meldet „Eigene MQTT-Zugänge sind noch nicht eingerichtet – wird
nachgeholt“, richtet Helfer, Einheiten, `storage/mqtt/` und die wirksame ACL
ein und stellt die Konfiguration auf die gültige Vorlage
`deploy/mosquitto/broker.conf` um (ein Neustart des Brokers).

Das neue Update-Skript holt fehlende Teile (wirksame ACL, Einheiten – auch
die der Update-Funktion –, Ordner in `storage/`) auch sonst nach, wenn der
Code schon aktuell ist; neu gestartet wird dabei nur, was sich geändert hat.

| Option | Wofür |
|--------|-------|
| `--pruefen` | nur anzeigen, was sich ändern würde (holt per git, ändert sonst nichts) |
| `--flows-ersetzen` | Flows auch dann ersetzen, wenn sie verändert wurden |
| `--erzwingen` | lokale Änderungen am Quellcode verwerfen statt abzubrechen – auch auf einen Stand, der nicht vorwärts liegt |
| `--zweig=<name>` | von einem anderen Zweig aktualisieren |
| `--bis=<commit>` | genau diesen Stand einspielen (40 Zeichen; nur vorwärts auf der Erst-Eltern-Linie des Zweigs – so wählt auch die Oberfläche) |
| `--behalten=<n>` | wie viele Sicherungen liegen bleiben (Vorgabe 5) |
| `--git-zugang=<datei>` | Token-Datei an anderer Stelle |
| `--nur-abgleichen` | ohne git, Migration und Sicherung: Ordner, Rechte, Einheiten, Vorlagen und Konfiguration zum ausgecheckten Stand abgleichen; neu gestartet wird nur Geändertes |
| `--nur-abgleichen --ohne-dienste` | dasselbe, aber nichts starten (so rollt das Skript beim Herunterfahren zurück) |
| `--wiederaufnehmen` | ein unterbrochenes Update zurückrollen (Marke `/var/lib/powermanagement/update/offen`) – das macht der Update-Dienst nach dem Start selbst |

Nur für das Skript selbst und den Update-Dienst: `--stufe=2` (die alte
Fassung übergibt an die neue), `--ergebnis-datei=` und
`--fortschritt-datei=` (Ergebnis und Schritt maschinenlesbar). Von Hand
braucht man sie nicht.

| Exit | heißt |
|------|-------|
| 0 | eingespielt, schon aktuell oder (`--pruefen`) nachgesehen |
| 1 | nichts verändert – etwa lokale Änderungen, kein Netz, kein Zugang, nicht vorwärts, zu wenig Platz oder ein anderes Update läuft gerade |
| 2 | fehlgeschlagen oder abgebrochen und vollständig zurückgerollt – der alte Stand läuft |
| 3 | nicht vollständig zurückgerollt – Handarbeit (*Zurückrollen von Hand*, unten) |

Die letzte Zeile jedes Laufs lautet `==> Ergebnis: …` mit dem Ergebnis, dem
Grund in Klammern und `alt → neu`, etwa
`==> Ergebnis: eingespielt – 7586d1d → 3b1f0c9` oder
`==> Ergebnis: nicht_begonnen (lokal_geaendert) – Stand 7586d1d`.

**Node-RED** selbst (innerhalb der Hauptversion 4) und **InfluxDB**
aktualisieren sich nicht mit – Node-RED von Hand, InfluxDB über apt bzw.
unattended-upgrades:

```bash
npm install --prefix /opt/node-red --omit=dev node-red@4 && systemctl restart powermanagement-nodered
apt update && apt install --only-upgrade influxdb2 influxdb2-cli
```

### Über die Oberfläche

**System → Update** braucht das Recht *PowerManagement aktualisieren*
(`system.update`) – von sich aus hat es nur die Rolle Administration
([rechte.md](rechte.md#update)). Die Seite zeigt den installierten und den
verfügbaren Stand, was neu ist, Fortschritt, Verlauf und die Befehle für
die Konsole; ein Abzeichen am Menüeintrag (auf dem Handy in der Kopfleiste)
meldet neue Stände und Störungen. Bedienung: [bedienung.md](bedienung.md#update).

So läuft es ab:

1. *Jetzt suchen* bzw. *Jetzt aktualisieren* legt nur einen **Auftrag** nach
   `storage/update/soll/auftrag`: Kennung, Aktion, Auslöser, Ziel – keine
   Optionen, Zweige oder Pfade. Die Oberfläche startet selbst nichts, kein
   sudo, kein systemctl.
2. `powermanagement-update.path` sieht den Auftrag und startet
   `powermanagement-update.service` (root) mit `deploy/update-helfer.sh`.
   Der Helfer prüft zuerst, dass sein Code root gehört (`deploy/`,
   `bin/power`, `.git`, `.git/config`, `.git/hooks`), liest den Auftrag als
   `www-data` und prüft ihn streng: Form, Alter, Kennung, Abstände,
   `update.conf`.
3. Er ruft `update-debian.sh` mit selbst gebauten Optionen auf – zum Suchen
   `--pruefen`, zum Aktualisieren `--bis=<angezeigter Stand>` –, ohne die
   Umgebung des Aufrufers und in einer eigenen cgroup: Der Neustart von
   PHP-FPM trifft ihn nicht.
4. Lauf, Fortschritt, Protokoll (ohne Farben, Zugangsdaten geschwärzt), Stand
   und Verlauf schreibt er als `www-data` nach `storage/update/ist/`. Die
   Seite liest nur diese Dateien, alle zwei Sekunden, solange etwas läuft.
   Während PHP-FPM neu lädt, zeigt sie „Dienste starten neu …“ und macht
   danach weiter; Neuladen oder ein anderes Gerät stören das Update nicht.

Eingespielt wird so nur ein Stand **vorwärts** auf dem ausgecheckten Zweig,
und zwar genau der angezeigte. `--erzwingen`, `--flows-ersetzen`, `--zweig`
und `--behalten` gibt es nur auf der Konsole. Bei lokalen Änderungen am
Code, nicht linearem Verlauf oder ohne ausgecheckten Zweig bleibt *Jetzt
aktualisieren* gesperrt. Läuft ein Aufbau oder wurde in den letzten 30
Minuten geschaltet, fragt die Seite nach (*Trotzdem jetzt aktualisieren*).

Der Update-Dienst bremst nach: zwischen zwei Updates mindestens zwei
Minuten („Zu kurz nach dem letzten Update“), ein Auftrag von Hand höchstens
30 Minuten alt (automatisch zehn), jede Kennung nur einmal. Wer innerhalb
einer Minute erneut sucht, bekommt den Stand vom letzten Abruf. Abgelehnte
Aufträge stehen mit Grund im Fortschritt.

**Schreibsperre:** Von der Sicherung bis zur Prüfung nimmt die Oberfläche
keine Änderungen an – ein Zurückrollen legte die Datenbank von vorher
zurück, und was jemand eben gespeichert hat, wäre verloren. Formulare melden
„Gerade läuft ein Update – Änderungen sind in etwa einer Minute wieder
möglich“, Anfragen mit `Accept: application/json` bekommen 503 mit
`Retry-After: 30`. An- und Abmelden gehen weiter. Die Sperre endet mit dem
Update; bleibt sie nach einem Absturz stehen, gilt sie höchstens 60 Minuten
(länger nur, solange sich der Lauf meldet), und der Update-Dienst räumt
eine verwaiste Sperre bei seinem nächsten Lauf weg.

**Spur:** Maßgeblich ist, was root aufschreibt:
`journalctl -a -u powermanagement-update` (das ganze Protokoll),
`/var/log/powermanagement/update/` (eine Datei je Lauf) und
`/var/lib/powermanagement/update/verlauf`. Im
Audit-Log stehen Aufträge (`system.update.anfordern`, auch von der Konsole),
das Ergebnis jedes Laufs (`system.update.ergebnis`, Benutzer `system`),
Einstellungen (`system.update.automatik`) und was die Automatik angefordert
oder verschoben hat (`system.update.automatisch`, `system.update.verschoben`).

**Abschalten** – nur root, die Oberfläche kann es nicht ändern:

```bash
printf 'OBERFLAECHE=nein\n' > /etc/powermanagement/update.conf   # keine Aufträge mehr (Oberfläche, Automatik, bin/power update:*)
printf 'AUTOMATIK=nein\n'   > /etc/powermanagement/update.conf   # nur das automatische Einspielen aus
rm /etc/powermanagement/update.conf                              # wieder an
```

Der Update-Dienst liest die Datei bei jedem Lauf (nie per `source`); die
Seite zeigt den neuen Stand nach seinem nächsten Lauf, spätestens nach 15
Minuten. Die Datei muss root gehören; lässt sie sich nicht sicher lesen oder
steht dort etwas anderes als `ja` oder `nein`, gilt „nein“.
`update-debian.sh` von Hand geht immer.

| Datei | Inhalt |
|-------|--------|
| `/opt/powermanagement/storage/update/soll/auftrag` | der Auftrag der Oberfläche (www-data) |
| `/opt/powermanagement/storage/update/ist/` | `helfer` (Lebenszeichen), `stand`, `lauf`, `protokoll`, `verlauf`, `wartung` (Schreibsperre) – schreibt der Update-Dienst als www-data |
| `/opt/powermanagement/storage/update/zeitplan` | Lebenszeichen des Zeitplans (Änderungszeit) |
| `/opt/powermanagement/deploy/update-helfer.sh` | der Update-Dienst (root): prüft den Auftrag und ruft `update-debian.sh` |
| `/etc/systemd/system/powermanagement-update.{path,service,timer}` | Auftrag beobachten und ausführen; der Zeitgeber weckt den Dienst zwei Minuten nach dem Start und 15 Minuten nach jedem Lauf (Lebenszeichen, Stand, Wiederanlauf, liegengebliebene Aufträge) |
| `/etc/systemd/system/powermanagement-update-zeitplan.{service,timer}` | Automatik: alle fünf Minuten `bin/power update:zeitplan` als www-data |
| `/etc/powermanagement/update.conf` | Notschalter (optional, root) |
| `/etc/powermanagement/update.lock`, `update-helfer.lock` | Sperren: nie zwei Updates – oder Update und Installation – zugleich |
| `/var/lib/powermanagement/update/` | nur root: Marke `offen`, alte Fassung des Skripts, Verlauf, gescheiterte Stände, bekannte Kennungen |
| `/var/log/powermanagement/update/` | nur root: Protokoll je Lauf (die letzten 30) und Protokolle des Zurückrollens |

### Automatische Updates

Karte *Automatische Updates* auf **System → Update** – oder
`bin/power update:automatik` (unten):

| Modus | was passiert |
|-------|--------------|
| *Aus* | nichts automatisch; gesucht wird nur mit *Jetzt suchen* |
| *Nur suchen und anzeigen* (Vorgabe) | etwa alle sechs Stunden suchen; neue Stände zeigt das Abzeichen im Menü, eingespielt wird nur von Hand |
| *Automatisch einspielen* | dazu im Zeitfenster von selbst einspielen – mit Sicherung und Zurückrollen wie von Hand |

Entschieden wird alle fünf Minuten von `powermanagement-update-zeitplan.timer`
(`bin/power update:zeitplan` als www-data – nur die Anwendung sieht Aufbau
und Schaltvorgänge). Heraus kommt ein gewöhnlicher Auftrag, den der
Update-Dienst prüft wie jeden anderen; root liest nie die Datenbank.

- **Zeitfenster:** Wochentage, Beginn (Uhrzeit der Installation, Vorgabe
  04:00 – nicht 02–03 wegen der Zeitumstellung, nicht 06–07 wegen
  apt-daily-upgrade) und Dauer (1, 2 oder 4 Stunden). Begonnen wird bis 15
  Minuten vor dem Ende und je Installation um einen festen, zufälligen
  Versatz später – nicht alle Container holen zugleich. Ein Fenster über
  Mitternacht gehört zum Tag, an dem es beginnt.
- **Höchstens ein Update je Tag**, vorher wird frisch gesucht (der Stand
  darf höchstens 30 Minuten alt sein). Schlägt eine Suche fehl (kein Netz),
  sucht der Zeitplan frühestens nach 30 Minuten wieder; ein Abzeichen gibt
  es dafür nicht – Container auf Veranstaltungen sind oft ohne Netz.
- **Karenzzeit** (sofort, 6 Stunden, 24 Stunden – Vorgabe –, 3 Tage):
  eingespielt wird der neueste Stand, dessen Commit mindestens so alt ist.
  Ein fehlerhafter Stand kann so noch ersetzt werden.
- **Veranstaltungsschutz** über alle Standorte: Läuft ein Aufbau oder Betrieb
  (nicht *beendet*) oder wurde in den letzten 30 Minuten geschaltet, wird
  **verschoben** und im Fenster erneut geprüft. Ebenso bei *Pausieren bis*
  (einschließlich dieses Tages, höchstens 90 Tage voraus), lokalen
  Änderungen am Code, nicht linearem Verlauf, `AUTOMATIK=nein` und einem
  noch nicht zurückgerollten Update. Ins Audit-Log kommt das höchstens
  einmal je Tag und Grund; nach 14 Tagen Verschieben zeigen Abzeichen und
  Seite es an (oft ein vergessener, nie beendeter Aufbau).
- **Gescheiterte Stände** versucht die Automatik nie wieder – erst einen
  neueren Stand oder *Jetzt aktualisieren* von Hand. Der Update-Dienst
  prüft das nach und lässt automatisch höchstens alle 20 Stunden ein Update
  zu.
- Erweitert ein Update die Datenbank und ist sie nicht SQLite, spielt die
  Automatik es nicht ein – zurück ginge es nur mit einer eigenen Sicherung.

Auf der Konsole (als Anwendungsbenutzer; nicht genannte Werte bleiben):

```bash
cd /opt/powermanagement
runuser -u www-data -- php bin/power update:automatik              # anzeigen
runuser -u www-data -- php bin/power update:automatik einspielen --tage=1,2,3,4,5 --beginn=04:00 --dauer=120 --karenz=24
runuser -u www-data -- php bin/power update:automatik einspielen --pause-bis=JJJJ-MM-TT   # --pause-bis= hebt die Pause auf
runuser -u www-data -- php bin/power update:automatik suchen
```

`--tage` als Wochentage 1 (Montag) bis 7 (Sonntag), `--dauer` in Minuten
(60, 120, 240), `--karenz` in Stunden (0, 6, 24, 72). Läuft der Zeitplan
nicht (die Karte meldet es), hilft
`systemctl status powermanagement-update-zeitplan.timer powermanagement-update-zeitplan.service`
weiter; mit `226/NAMESPACE` fehlt dem Container „nesting“ (Abschnitt 12).

### Einmalig über die Konsole

Empfohlen ist derselbe Weg wie in der Oberfläche: ein Auftrag an den
Update-Dienst, der Fortschritt im Terminal. Strg+C oder ein
Verbindungsabbruch beenden nur die Anzeige – das Update läuft im Dienst
weiter.

```bash
cd /opt/powermanagement && runuser -u www-data -- php bin/power update:starten --warten
```

- Ist der bekannte Stand älter als eine Minute, sucht der Befehl zuerst und
  spielt dann genau den gefundenen Stand ein (`--ziel=<commit>`: einen
  bestimmten, 40 Zeichen, vorwärts auf dem Zweig).
- Laufen gerade ein Aufbau oder Schaltvorgänge, lehnt er ab und nennt sie.
  Bewusst trotzdem: `--trotz-betrieb`.
- Exit wie `update-debian.sh`: 0 = eingespielt bzw. schon aktuell, 1 =
  nichts verändert, 2 = zurückgerollt, 3 = Handarbeit. Bei einem Fehler
  folgen die letzten 40 Zeilen des Protokolls.

Nachsehen und mitlesen:

```bash
cd /opt/powermanagement && runuser -u www-data -- php bin/power update:stand          # Einrichtung, Stand, letzter Lauf, Automatik
cd /opt/powermanagement && runuser -u www-data -- php bin/power update:suchen --warten
/opt/powermanagement/deploy/update-debian.sh --pruefen      # als root, ohne den Update-Dienst
journalctl -a -f -u powermanagement-update                  # mitlesen
journalctl -a -u powermanagement-update -I                  # letzter Lauf (systemd 257, Debian 13)
```

`bin/power` nie als root aufrufen – es legte root-eigene Dateien in
`storage/` an; die Befehle `update:suchen`, `update:starten` und
`update:automatik` lehnen das ab. Immer `runuser -u www-data -- php bin/power …`.

**Direkt mit dem Skript** – für Optionen, die die Oberfläche nicht kennt
(`--erzwingen`, `--flows-ersetzen`, `--zweig=<name>`, `--bis=<commit>`).
Am besten als eigene Einheit, damit ein Verbindungsabbruch nicht stört:

```bash
systemd-run --unit=pm-update --collect -p KillMode=mixed -p TimeoutStopSec=20min \
  /opt/powermanagement/deploy/update-debian.sh --flows-ersetzen
journalctl -a -f -u pm-update
```

Im Terminal geht es auch: Strg+C oder ein abreißendes Terminal rollen sauber
zurück. Am Ende stößt das Skript den Update-Dienst an, damit Stand und
Verlauf in der Oberfläche stimmen. Läuft schon ein Update, bricht es mit
„Ein anderes Update läuft gerade.“ ab.

### Erstes Update auf die Fassung mit Update-Funktion

Eine Installation von vor der Update-Funktion hat noch das alte
Update-Skript. Es holt den neuen Stand zwar, arbeitet danach aber seine
alten Schritte ab und kennt die Update-Funktion noch nicht. Deshalb läuft
das Update **zweimal hintereinander**: Lauf 1 (altes Skript) holt und
spielt den neuen Stand ein, Lauf 2 (jetzt das neue Skript) richtet die
Update-Funktion ein. Beides als eigene systemd-Einheit – so stört ein
Abbruch der SSH-Verbindung oder ein versehentliches Strg+C nicht, und
`HOME` ist gesetzt (ohne bricht das alte Skript ab). Am besten außerhalb
einer Veranstaltung: Messung und Schalten sind dabei 1–3 Minuten
unterbrochen.

```bash
# 1 · Als root in den Container – vom Proxmox-Host aus:
pct enter <CTID>
#     (oder: ssh root@<container>)

# 2 · Optional: nachsehen, was kommt (holt nur per git, ändert sonst nichts)
HOME=/root /opt/powermanagement/deploy/update-debian.sh --pruefen

# 3 · Umstieg starten (beide Zeilen zusammen einfügen):
systemd-run --unit=pm-umstieg --collect --setenv=HOME=/root \
  /bin/bash -c '/opt/powermanagement/deploy/update-debian.sh && /opt/powermanagement/deploy/update-debian.sh'

# 4 · Zusehen (Strg+C beendet nur das Zusehen – das Update läuft weiter):
journalctl -a -f -u pm-umstieg
```

Was zu sehen sein sollte (meist 2–5 Minuten):

- **Lauf 1:** `==> Stand prüfen`, `Neu: <hash> <Betreff>` mit der Liste der
  Änderungen, `==> Sicherung anlegen` (Pfad
  `…/storage/backups/vor-update-<zeit>.tar.gz`),
  `==> Migrationen ausführen`,
  `==> Dienste und Konfiguration abgleichen`, `==> Dienste starten`,
  `==> Prüfen`, `==> Fertig` mit `Vorher:`/`Jetzt:`.
- **Lauf 2:** `Bereits auf dem neuesten Stand (…)`, dann
  `Einrichtung unvollständig (Update-Funktion, Einheiten oder storage/-Ordner) – wird nachgeholt.`,
  einige Zeilen `powermanagement-update… aktualisiert` und am Ende
  `==> Ergebnis: aktuell – Update-Funktion eingerichtet`. Lauf 2 startet
  nur die neuen Update-Einheiten, keinen der bestehenden Dienste neu.
- Zum Schluss meldet systemd
  `pm-umstieg.service: Deactivated successfully.` – dann Strg+C.

Prüfen:

```bash
systemctl is-active powermanagement-update.path powermanagement-update.timer powermanagement-update-zeitplan.timer
#   → dreimal: active
cd /opt/powermanagement && runuser -u www-data -- php bin/power update:stand
#   → „Update-Funktion: eingerichtet …“, „Installiert: <hash> vom …“
git -C /opt/powermanagement log -1 --format='%h %ci %s'
#   → der neue Stand
```

Danach in der Oberfläche **System → Update** öffnen (nur mit der Rolle
Administration): Dort stehen der installierte Stand, *Jetzt suchen*, *Jetzt
aktualisieren* und die Karte *Automatische Updates* (Vorgabe: *Nur suchen
und anzeigen*; *Automatisch einspielen* lässt sich dort einschalten – mit
Wochentagen, Uhrzeit und Karenzzeit, nie während eines Aufbaus).

Wenn etwas nicht klappt:

- *Verbindung abgerissen:* wieder anmelden. Läuft es noch?
  `systemctl is-active pm-umstieg` (`activating` = läuft). Protokoll:
  `journalctl -a -u pm-umstieg -n 200 --no-pager`. Die Einheit **nicht**
  stoppen – das alte Skript rollt bei einem Abbruch nicht zurück.
- *„In /opt/powermanagement gibt es lokale Änderungen“* (Lauf 1 bricht ab,
  Lauf 2 startet dann nicht): `git -C /opt/powermanagement status --short`
  ansehen. Sind sie entbehrlich, Schritt 3 mit `--erzwingen` beim **ersten**
  Aufruf wiederholen:
  `systemd-run --unit=pm-umstieg --collect --setenv=HOME=/root /bin/bash -c '/opt/powermanagement/deploy/update-debian.sh --erzwingen && /opt/powermanagement/deploy/update-debian.sh'`
- *„Die Änderungen ließen sich nicht holen“* (privates Repository, Token
  abgelaufen): Token eintragen
  `printf 'GITHUB_TOKEN=%s\n' '<token>' > /etc/powermanagement/git.conf; chmod 600 /etc/powermanagement/git.conf`,
  dann Schritt 3 wiederholen.
- *Lauf 1 „fehlgeschlagen – der alte Stand wird wiederhergestellt“:* Der
  alte Stand läuft weiter. Die Ursache steht im Protokoll; nach der
  Behebung Schritt 3 wiederholen.
- *Schon umgestellt?* Der Befehl schadet nicht – beide Läufe melden dann
  „aktuell“.
- *Anderer Ort als /opt/powermanagement:* steht in
  `/etc/powermanagement/installation.conf` (`ZIEL=`).
- *Ohne systemd-run* (nur bei stabiler Verbindung, etwa direkt an der
  Proxmox-Konsole, und ohne Strg+C):
  `HOME=/root /opt/powermanagement/deploy/update-debian.sh && HOME=/root /opt/powermanagement/deploy/update-debian.sh`

### Zurückrollen von Hand

**„Nicht vollständig zurückgerollt – Handarbeit nötig“** (Exit 3,
`unvollstaendig`) heißt: Beim Zurückrollen ließ sich ein Teil nicht
wiederherstellen (git, Datenbank, Abgleich oder die Anmeldeseite danach).
Die Marke `/var/lib/powermanagement/update/offen` bleibt, Updates aus der
Oberfläche sind gesperrt, und die Seite zeigt es rot. Ein unterbrochenes
Update versucht der Update-Dienst höchstens zweimal zurückzurollen, danach
gibt er auf („aufgegeben“) und meldet es einmal.

```bash
cat /var/lib/powermanagement/update/offen                   # alt, alt_zweig, neu, Sicherung, Schnappschuss, migriert, neu_angelegt, Phase
ls -t /var/log/powermanagement/update/ | head               # Protokolle, darunter …-zurueckrollen.log
journalctl -a -u powermanagement-update -n 200 --no-pager
# Ursache beheben (Platte voll, Paketquelle, Rechte …), dann erneut:
/opt/powermanagement/deploy/update-debian.sh --wiederaufnehmen   # Exit 2 = zurück, 3 = noch nicht
```

Geht es so nicht, die Schritte des Zurückrollens von Hand – mit den Werten
aus der Marke:

```bash
systemctl stop powermanagement-sammler php8.4-fpm
git -C /opt/powermanagement checkout -q -f -B <alt_zweig> <alt>     # alt_zweig „-“: checkout -q -f --detach <alt>
# nur bei „migriert ja“ – die Datenbank aus dem Schnappschuss (oder aus der Sicherung, wie unten):
install -o www-data -g www-data -m 640 <schnappschuss> /opt/powermanagement/storage/database.sqlite
rm -f /opt/powermanagement/storage/database.sqlite-wal /opt/powermanagement/storage/database.sqlite-shm
# je Zeile „neu_angelegt <datei>“: Einheiten erst ausschalten (systemctl disable --now <name>), dann rm <datei>
rm /var/lib/powermanagement/update/offen
/opt/powermanagement/deploy/update-debian.sh --nur-abgleichen
systemctl start php8.4-fpm powermanagement-sammler
runuser -u www-data -- rm -f /opt/powermanagement/storage/update/ist/wartung
systemctl start powermanagement-update.service                       # Stand und Verlauf für die Oberfläche
```

**Ein erfolgreiches Update zurücknehmen** geht nur auf der Konsole –
Migrationen laufen nur vorwärts, zurück kommt die Datenbank nur aus der
Sicherung (was seitdem gespeichert wurde, ist dann weg). Die Befehle mit den
echten Werten stehen am Ende jedes Laufs, etwa:

```bash
systemctl stop powermanagement-sammler php8.4-fpm
git -C /opt/powermanagement checkout -q -f -B <zweig> <vorher>
install -d -m 700 /root/pm-zurueck && tar xzf /opt/powermanagement/storage/backups/vor-update-<zeit>.tar.gz -C /root/pm-zurueck
install -o www-data -g www-data -m 640 /root/pm-zurueck/database.sqlite /opt/powermanagement/storage/database.sqlite
rm -f /opt/powermanagement/storage/database.sqlite-wal /opt/powermanagement/storage/database.sqlite-shm
/opt/powermanagement/deploy/update-debian.sh --nur-abgleichen
systemctl start php8.4-fpm powermanagement-sammler
rm -rf /root/pm-zurueck                           # darin steckt config.php mit app.key
systemctl start powermanagement-update.service    # Stand und Verlauf für die Oberfläche
```

Danach zeigt die Oberfläche den zurückgenommenen Stand wieder als
verfügbar. Steht die Automatik auf *Automatisch einspielen*, vorher auf
*Nur suchen und anzeigen* stellen – sonst spielt sie ihn im nächsten Fenster
wieder ein. Ein Stand von vor der Update-Funktion kennt `--nur-abgleichen`
noch nicht: Dort die Zeile weglassen und die Einheiten der Update-Funktion
ausschalten
(`systemctl disable --now powermanagement-update.path powermanagement-update.timer powermanagement-update-zeitplan.timer`).

## 11 · Sicherung

| Was | Wo | Warum |
|-----|----|-------|
| `config/config.php` | `/opt/powermanagement/config/` | enthält `app.key` – **ohne ihn sind alle hinterlegten Zugänge (Modbus, Cloud, InfluxDB-Token) verloren** |
| Datenbank | `/opt/powermanagement/storage/database.sqlite` | Anlagen, Standorte, Benutzer, Einstellungen |
| Grundrisse | `/opt/powermanagement/storage/plans/` | hochgeladene Pläne |
| Zugänge | `/etc/powermanagement/` | Kennwörter, Tokens, Node-RED-Umgebung |
| Node-RED | `/var/lib/powermanagement/node-red/` | Flows, `flows_cred.json`, eigene Paletten-Nodes |
| Messwerte | InfluxDB | per `influx backup` |

Ein nächtlicher Lauf, der alles in `/var/backups/powermanagement` legt:

```bash
install -d -m 700 /var/backups/powermanagement
cat > /usr/local/sbin/powermanagement-sicherung <<'EOF'
#!/bin/bash
set -euo pipefail
ZIEL=/var/backups/powermanagement/$(date +%F)
install -d -m 700 "$ZIEL"
# SQLite im laufenden Betrieb stimmig kopieren – in ein Verzeichnis, das nur
# www-data lesen kann (die Datenbank enthält verschlüsselte Zugänge)
KOPIE=/opt/powermanagement/storage/backups/.nacht.sqlite
rm -f "$KOPIE"
runuser -u www-data -- php -r '$p = new PDO("sqlite:/opt/powermanagement/storage/database.sqlite");
    $p->exec("VACUUM INTO " . $p->quote($argv[1]));' "$KOPIE"
mv "$KOPIE" "$ZIEL/database.sqlite"
tar czf "$ZIEL/dateien.tar.gz" /opt/powermanagement/config/config.php \
    /opt/powermanagement/storage/plans /etc/powermanagement /var/lib/powermanagement/node-red \
    --exclude=/var/lib/powermanagement/node-red/node_modules
# InfluxDB: Token über die Umgebung, nicht über die Kommandozeile
INFLUX_TOKEN="$(sed -n 's/^INFLUX_OPERATOR_TOKEN=//p' /etc/powermanagement/zugaenge.txt)" \
    influx backup --host http://127.0.0.1:8086 "$ZIEL/influx"
find /var/backups/powermanagement -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
EOF
chmod 700 /usr/local/sbin/powermanagement-sicherung

cat > /etc/systemd/system/powermanagement-sicherung.service <<'EOF'
[Unit]
Description=PowerManagement Sicherung
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/powermanagement-sicherung
EOF
cat > /etc/systemd/system/powermanagement-sicherung.timer <<'EOF'
[Unit]
Description=PowerManagement Sicherung täglich
[Timer]
OnCalendar=*-*-* 02:15:00
Persistent=true
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload && systemctl enable --now powermanagement-sicherung.timer
```

Die Sicherungen gehören zusätzlich auf einen anderen Rechner – eine Kopie auf
derselben Platte hilft beim Plattenausfall nicht. Vor jedem Update lohnt ein
Proxmox-Snapshot des Containers (`pct snapshot 150 vor-update`).

**Zurückspielen** der InfluxDB auf einer frisch eingerichteten Datenbank:

```bash
INFLUX_TOKEN="<Operator-Token>" influx restore --host http://127.0.0.1:8086 --full /var/backups/powermanagement/<datum>/influx
```

`--full` ersetzt auch Benutzer und Tokens durch die gesicherten – danach
gelten wieder die Tokens aus der alten `zugaenge.txt`.

## 12 · Fehlersuche

Der erste Blick:

```bash
cd /opt/powermanagement && runuser -u www-data -- php bin/power pruefen

systemctl status powermanagement-nodered powermanagement-sammler mosquitto influxdb nginx php8.4-fpm
journalctl -u powermanagement-nodered -f      # Node-RED: Verbindung, Warnungen der Flows
journalctl -u powermanagement-sammler -f      # Modbus, Cloud
journalctl -u powermanagement-mqtt-zugaenge   # eigene MQTT-Zugänge: eine Zeile je Lauf
journalctl -a -u powermanagement-update -I    # letztes Update aus Oberfläche/Automatik
tail -f /opt/powermanagement/storage/logs/app.log
tail -f /var/log/mosquitto/mosquitto.log

KW="$(sed -n 's/^MQTT_POWERMANAGEMENT_KENNWORT=//p' /etc/powermanagement/zugaenge.txt)"
mosquitto_sub -h 127.0.0.1 -u powermanagement -P "$KW" -t 'pm/#' -v
mosquitto_sub -h 127.0.0.1 -u powermanagement -P "$KW" -t 'pm/status/nodered' -C 1   # letzter Herzschlag
```

In **Node-RED** zeigt der Node *InfluxDB-Schreiber* den Zustand
(grün: geschrieben, rot: letzter Fehler). Der Debug-Node *Zeilen ansehen*
im Tab „Schreiben“ ist ausgeschaltet – einschalten (Knopf rechts am Node),
dann erscheint in der Debug-Seitenleiste jede erzeugte Zeile Line Protocol.

In die InfluxDB schauen:

```bash
T="$(sed -n 's/^INFLUX_TOKEN_OBERFLAECHE=//p' /etc/powermanagement/zugaenge.txt)"
printf 'Authorization: Token %s\n' "$T" | curl -sG -H @- http://127.0.0.1:8086/query \
  --data-urlencode db=strom --data-urlencode "q=SELECT * FROM geraet ORDER BY time DESC LIMIT 5"
```

| Symptom | Ursache / Abhilfe |
|---------|-------------------|
| Shelly verbindet sich nicht (`not authorised` im Mosquitto-Log) | Benutzer/Kennwort im Gerät falsch – `shelly` und `MQTT_SHELLY_KENNWORT` aus `zugaenge.txt`. Kennwort ohne Leerzeichen abtippen. |
| Shelly mit eigenem Zugang verbindet sich nicht mehr (`not authorised`) | Altes Kennwort im Gerät (nach „Neues Kennwort“ noch nicht neu gestartet bzw. bei Gen1 nicht eingetragen) oder der Zugang wurde entfernt. In der Oberfläche „Neues Kennwort“ – oder am Gerät wieder den gemeinsamen Zugang eintragen und den eigenen entfernen. Steht der Benutzer im Block? `grep '^# konto' /etc/mosquitto/powermanagement.passwd` (zeigt keine Hashes) und `journalctl -u powermanagement-mqtt-zugaenge`. |
| Eigener Zugang verbunden, aber keine Messwerte, Schalten ohne Antwort | Der Grundlage fehlen die `pattern`-Zeilen (von Hand verändert, Abschnitt 10): `grep ^pattern /etc/mosquitto/powermanagement-wirksam.acl` muss drei Zeilen zeigen. |
| Oberfläche: „Broker-Abgleich nicht eingerichtet“ oder Fehler | Gerade von einer Fassung ohne eigene Zugänge aktualisiert? Dann `update-debian.sh` noch einmal aufrufen (Abschnitt 10). Sonst: `systemctl status powermanagement-mqtt-zugaenge.path powermanagement-mqtt-zugaenge.timer`, `journalctl -u powermanagement-mqtt-zugaenge` (`uebernommen: …`, `abgelehnt: Zeile … (code)`, `fehler: …`), `cat /opt/powermanagement/storage/mqtt/ist/ergebnis.txt`. `marker`: Block in der Kennwortdatei beschädigt – die Zeilen von `# >>> powermanagement geraetezugaenge` bis `# <<< …` löschen, dann `cd /opt/powermanagement && runuser -u www-data -- php bin/power mqtt:zugaenge-schreiben` (schreibt das Soll neu). `basis`: in der Grundlage steht eine Zeile, die nicht mit `user`, `topic` oder `pattern` beginnt. `viele`: mehr als fünf Zugänge auf einmal entfernt – gewollt? Dann `mqtt:zugaenge-schreiben --viele-entfernen`. `neuladen`: Mosquitto ließ sich nicht neu laden (`systemctl status mosquitto`) – die Dateien bleiben auf dem alten Stand, der nächste Lauf versucht es erneut. |
| Mosquitto-Log: `ACL pattern 'shellies/command' does not contain '%c' or '%u'` | Gewollt – alle eigenen Zugänge dürfen den Gen1-Rundruf lesen. |
| Mosquitto startet nicht: `Unable to open acl_file … powermanagement-wirksam.acl` | Die wirksame ACL fehlt: `systemctl start powermanagement-mqtt-zugaenge.service` baut sie, danach `systemctl restart mosquitto` – oder zur Not die Grundlage kopieren: `install -o mosquitto -g mosquitto -m 600 /etc/mosquitto/powermanagement.acl /etc/mosquitto/powermanagement-wirksam.acl`. |
| Shelly Gen2 verbunden, aber keine Messwerte | „Generic status update over MQTT“ ist aus. Prüfen mit `mosquitto_sub … -t '+/status/#' -v`. |
| Gen2-Gerät fehlt in der Geräteliste mit Modell/Firmware | „RPC over MQTT“ bzw. „MQTT Control“ im Gerät ausgeschaltet – ohne das antwortet es nicht auf `Shelly.GetDeviceInfo`. Messwerte kommen trotzdem. |
| Gen1 meldet sich, EM/3EM ohne Summe | Die Summe entsteht erst, wenn alle Phasen innerhalb von 30 s geliefert haben – nach dem Start ab der zweiten Runde. Update-Periode im Gerät prüfen. |
| Node *InfluxDB-Schreiber* rot: `HTTP 401` | Token in `/etc/powermanagement/node-red.env` falsch oder gelöscht. Neues Token: `influx auth create --write-bucket <id>` (Token über `INFLUX_TOKEN=` in der Umgebung), eintragen, `systemctl restart powermanagement-nodered`. |
| … `HTTP 404` (database not found) | DBRP-Zuordnung fehlt: `influx v1 dbrp list`; anlegen mit `influx v1 dbrp create --db strom --rp autogen --bucket-id <id> --default`. |
| … `ECONNREFUSED` | InfluxDB läuft nicht: `systemctl status influxdb`. Node-RED puffert bis zu 5 Minuten und schreibt danach nach. |
| Warnung „field type conflict“ im Node-RED-Log | Ein Feld kam einmal als Zahl, einmal als Text. Die abgelehnte Zeile wird verworfen, alle anderen geschrieben. Die Quelle (meist ein Sammler-Kanal) korrigieren. |
| Herzschlag fehlt (`pruefen`: „kein Herzschlag“) | Node-RED läuft nicht oder ist nicht mit dem Broker verbunden: `journalctl -u powermanagement-nodered`; Zugang in `flows_cred.json` (Benutzer `nodered`). |
| Node-RED-Editor: „Verbindung verloren“ | WebSocket wird nicht durchgereicht – die nginx-Vorlage aus `deploy/` verwenden (`location ^~ /node-red/` mit `Upgrade`). Ein vorgeschalteter Proxy muss WebSockets ebenfalls durchlassen. |
| Node-RED-Editor lässt niemanden hinein | `admin-kennwort.bcrypt` fehlt oder ist kaputt – dann sperrt `settings.js` bewusst. Neues Kennwort setzen (Abschnitt 4). |
| Installation bricht ab: `chmod: changing permissions of '/opt/powermanagement/storage/logs': Operation not permitted` | Ältere Fassungen des Skripts setzten die Rechte an den mitgelieferten Ordnern in `storage/` als `www-data`, obwohl `git clone` sie root gegeben hatte. Neuen Stand holen (`git -C /opt/powermanagement pull`) und das Skript mit denselben Optionen noch einmal aufrufen – es macht dort weiter, Kennwörter und `app.key` bleiben. Ohne neuen Stand vorher `chown -R -P www-data:www-data /opt/powermanagement/storage`. |
| Dienst scheitert mit `status=226/NAMESPACE` | Nesting ist am Container aus – `pct set <id> --features nesting=1` (Abschnitt 1). |
| Sammler erreicht GX/ComAp nicht | Route bzw. Tunnel: `ip route get 192.168.50.10`, `wg show`, `nc -vz 192.168.50.10 502`. Modbus TCP am GX eingeschaltet? |
| Speichern auf einer Anlagen-Seite mit Livewerten hängt bis zum Timeout, im Audit-Log steht nichts | Ältere Fassungen: jede Live-Abfrage hielt die Sitzung, bis die InfluxDB antwortete – bei einer trägen InfluxDB stauten sie sich, und alles Weitere aus demselben Browser wartete. Neuen Stand einspielen (Abschnitt 10). Wie schnell die InfluxDB antwortet: `grep 'InfluxDB antwortet langsam' /opt/powermanagement/storage/logs/app.log`, `systemctl status influxdb`. |
| `502 Bad Gateway` | PHP-FPM läuft nicht: `systemctl status php8.4-fpm`, Socket `/run/php/powermanagement.sock`. |
| „Welcome to nginx“ statt Anmeldung | `rm -f /etc/nginx/sites-enabled/default && systemctl reload nginx` |
| Update bricht mit „could not read Username“ ab | Privates Repository ohne Token – `/etc/powermanagement/git.conf` anlegen (Abschnitt 3). |
| Update meldet „lokale Änderungen“ | Jemand hat Dateien in `/opt/powermanagement` geändert. Sichern, dann `--erzwingen`. |
| System → Update: „Die Update-Funktion ist … noch nicht eingerichtet“ (`update:stand`: „nicht eingerichtet“) | Noch das alte Update-Skript – einmal umsteigen (Abschnitt 10, *Erstes Update auf die Fassung mit Update-Funktion*). Sonst hat sich der Update-Dienst nie gemeldet: `systemctl status powermanagement-update.path powermanagement-update.timer`, `journalctl -a -u powermanagement-update -n 50`. Steht dort „Code nicht root-eigen …“, hat jemand in `/opt/powermanagement` Besitz oder Rechte geändert – `update-debian.sh --nur-abgleichen` setzt sie zurück. Fehlende Einheiten und Ordner holt `update-debian.sh` (ohne Option) nach. |
| „Der Auftrag wird nicht übernommen“ | Die Pfad-Einheit hat nach zu vielen Starts aufgegeben (`start-limit-hit`) oder läuft nicht. Der Zeitgeber heilt das nach spätestens 15 Minuten; sofort: `systemctl reset-failed powermanagement-update.service powermanagement-update.path && systemctl restart powermanagement-update.path`. Steht in `storage/update/ist/helfer` die Zeile `pfad soll_ungueltig`, ist `storage/update/soll` kein gewöhnlicher Ordner – als www-data entfernen, dann legt `update-debian.sh --nur-abgleichen` ihn neu an. |
| „Der Update-Dienst meldet sich seit … nicht“ | Der Zeitgeber weckt ihn sonst alle 15 Minuten: `systemctl status powermanagement-update.path powermanagement-update.timer`; ausgeschaltet? `systemctl enable --now powermanagement-update.path powermanagement-update.timer`. |
| „Der Zeitplan läuft nicht“, `powermanagement-update-zeitplan.service` scheitert mit `status=226/NAMESPACE` | Nesting ist am Container aus – `pct set <id> --features nesting=1`, Container neu starten. Ohne nesting gehen Updates aus der Oberfläche und von der Konsole trotzdem, nur die Automatik nicht. Gemeldet wird nach 15 Minuten ohne Lebenszeichen; lief der Zeitplan noch nie, zählt die Zeit ab der Einrichtung. |
| „Automatische Updates werden seit … verschoben“ | Der Grund steht auf der Seite. Meist ein Aufbau, der nie beendet wurde (Standort → Aufbau → beenden), sonst eine vergessene Pause, lokale Änderungen oder `AUTOMATIK=nein`. |
| „Zu kurz nach dem letzten Update“ | Zwischen zwei Updates liegen mindestens zwei Minuten, automatisch 20 Stunden – kurz warten. |
| „Suche fehlgeschlagen“ | `kein_zugang`: Token in `/etc/powermanagement/git.conf` abgelaufen oder ohne Leserecht (Abschnitt 3). `abruf`: GitHub nicht erreichbar – auf Veranstaltungen oft ohne Netz; es wird später wieder gesucht. Genaueres: `journalctl -a -u powermanagement-update -I`. |
| „Ein früheres Update wurde unterbrochen“ (`update-debian.sh` oder `install-debian.sh` brechen ab, die Seite sperrt Updates) | Die Marke `/var/lib/powermanagement/update/offen` steht – Strom weg oder `kill -9` mitten im Update. Der Update-Dienst rollt zwei Minuten nach dem Start selbst zurück; von Hand: `/opt/powermanagement/deploy/update-debian.sh --wiederaufnehmen`, dann den Aufruf wiederholen. |
| „Nicht vollständig zurückgerollt – Handarbeit nötig“ (Exit 3, `unvollstaendig`, „aufgegeben“) | Abschnitt 10, *Zurückrollen von Hand*. Bis dahin bleiben Updates aus der Oberfläche gesperrt. |
| „Gerade läuft ein Update – Änderungen sind … wieder möglich“, obwohl keins läuft | Die Schreibsperre `storage/update/ist/wartung` blieb stehen (ein Update starb vor der Marke). Ohne Marke und ohne laufendes Update räumt der Update-Dienst sie ab zehn Minuten Alter bei seinem nächsten Lauf weg – sofort: `systemctl start powermanagement-update.service`. Steht die Marke, erst zurückrollen (Zeile oben). |

## 13 · Grafana daneben

Ein vorhandenes Grafana kann weiterlaufen und dieselbe InfluxDB lesen.

1. Eigenes Token nur zum Lesen:
   ```bash
   export INFLUX_TOKEN="$(sed -n 's/^INFLUX_OPERATOR_TOKEN=//p' /etc/powermanagement/zugaenge.txt)"
   influx auth create --host http://127.0.0.1:8086 --org powermanagement \
       --read-bucket "$(sed -n 's/^INFLUX_BUCKET_ID=//p' /etc/powermanagement/zugaenge.txt)" \
       --description "Grafana (nur lesen)"
   unset INFLUX_TOKEN
   ```
2. In Grafana eine Datenquelle *InfluxDB* anlegen: URL
   `http://10.10.0.60:8086`, Abfragesprache **InfluxQL**, Datenbank
   `strom`, HTTP-Header `Authorization: Token <token>` – oder **Flux** mit
   Organisation `powermanagement` und dem Token.
3. In der Firewall Port 8086 für den Grafana-Server öffnen (Abschnitt 8).

Das Schema der Messwerte:

| Messung | Tags | Felder |
|---------|------|--------|
| `strom` | `geraet`, `kanal`, `phase` (L1, L2, L3, N, sum), `typ` | `u` V, `i` A, `p` W, `q` var, `s` VA, `pf`, `f` Hz, `e`/`e_rueck` Wh (Zählerstand) |
| `zustand` | `geraet`, `kanal`, `typ` | z. B. `soc` %, `schalter` 1/0, `online` 1/0, `temperatur` °C, `motorzustand` (Text) |
| `geraet` | `geraet`, `typ` | `modell`, `produkt`, `name`, `firmware`, `generation`, `mac`, `ip`, `praefix` |
| `pipeline` | `quelle` | `geschrieben`, `fehler`, `puffer`, `verworfen` (Herzschlag von Node-RED alle 30 s) |

Alle Zahlen sind Floats. Beispiel für ein Grafana-Panel (Leistung je Phase
eines Pro 3EM):

```sql
SELECT mean("p") FROM "strom"
WHERE "geraet" = 'shellypro3em-a0b1c2d3e4f5' AND "phase" =~ /^L[123]$/ AND $timeFilter
GROUP BY time($__interval), "phase" fill(null)
```
