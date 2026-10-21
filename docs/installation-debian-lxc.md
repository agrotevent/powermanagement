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
7. **Oberfläche:** PHP-FPM-Pool, nginx-Site (mit Bremse für die
   Schnittstelle `/api/`, Abschnitt 9), Datenbank, Administrator,
   InfluxDB- und MQTT-Zugang (verschlüsselt in der Datenbank).
8. **Dienste:** `powermanagement-nodered`, `powermanagement-sammler`,
   `powermanagement-wartung.timer` (täglich; räumt Protokolle auf und meldet
   Übergabeberichte über der Aufbewahrungsfrist – gelöscht wird nur mit
   `bin/power berichte:aufbewahrung --ausfuehren`),
   `powermanagement-mqtt-zugaenge.path` und `.timer` (eigene MQTT-Zugänge,
   nur mit Mosquitto im Container), Logrotate.
9. **Prüfung** mit `bin/power pruefen` und eine Zusammenfassung mit allen
   Zugängen.

Alle erzeugten Kennwörter und Tokens stehen zusätzlich in
**`/etc/powermanagement/zugaenge.txt`** (nur root). Das Skript ist
wiederholbar: Ein zweiter Lauf verwendet die Kennwörter aus dieser Datei
weiter, ändert nichts an Datenbank und `config.php` und überschreibt keine
eigenen Änderungen an Flows, Node-RED-Einstellungen, nginx-Site oder
Mosquitto-ACL – neue Fassungen solcher Dateien legt es mit
`powermanagement-neu` im Namen daneben.

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

**Für Übergabeberichte** ([berichte.md](berichte.md)):

1. *System → Einstellungen → Firma & Übergabeberichte*: Firmenname (Pflicht
   vor der ersten Prüfer-Unterschrift), Anschrift, Kontakt, Bank, Register –
   sie stehen im Fuß jeder PDF-Seite. Das Repository bringt keine
   Firmendaten mit; dazu Kürzel der Nummer, Texte und Aufbewahrung.
2. *System → Benutzer & Freigaben*: bei den Prüfern die
   **Prüfer-Qualifikation** eintragen.
3. *Übergabeberichte → Prüfgeräte*: Installationstester mit Seriennummer
   und Kalibrierdatum.
4. Optional **Eventworx** (*System → Einstellungen → Eventworx*): Mandant
   und ein Token „REST API Lesen“, dann *Verbindung prüfen* – oder auf der
   Kommandozeile `sudo -u www-data php /opt/powermanagement/bin/power
   eventworx:pruefen`. Der Container braucht dafür ausgehend HTTPS zu
   `<mandant>.eventworx.biz`. Details: [eventworx.md](eventworx.md).

**Für den Kundenbereich** ([kundenbereich.md](kundenbereich.md)):

1. **`app.url`** in `config/config.php` auf die Adresse setzen, unter der
   Kunden die Oberfläche erreichen (etwa `'url' => 'https://strom.example.de'`).
   Nur daraus entstehen Einrichtungslinks; ohne sie gibt es nur vorläufige
   Kennwörter. Die Karte *System → Einstellungen → Kundenbereich* zeigt,
   ob die Adresse fehlt oder unverschlüsselt ist.
2. In derselben Karte Datenschutzhinweis (Mustertext prüfen) und
   Ansprechpartner eintragen.
3. Wer Kunden von außen hereinlässt: Abschnitt 9.

**Für die Anmeldung über SSO** (optional, etwa mit Authentik –
[anmeldung-sso.md](anmeldung-sso.md)):

1. **`app.url`** in `config/config.php` mit **https** und ohne Pfad (etwa
   `'url' => 'https://strom.example.de'`) – Pflicht für SSO; daraus entsteht
   die Rückruf-Adresse `…/login/sso/rueckruf`, nie aus der Anfrage.
2. Ausgehend **HTTPS zum Authentik-Host** über seine öffentliche Adresse
   (Hairpin-NAT oder interner DNS-Eintrag auf denselben Proxy). Geht das
   nicht, in `config/config.php` unter `sso` → `verbinden_ueber` die interne
   Adresse eintragen (Name und Zertifikatsprüfung bleiben), für eine
   interne CA `ca_datei` (im Programmordner, etwa unter `config/` –
   PHP-FPM darf wegen `open_basedir` nicht nach `/etc/ssl`).
3. **Uhrzeit**: Der Container nutzt die Uhr des Proxmox-Hosts – dort chrony
   prüfen. Mehr als 60 Sekunden Abweichung lassen jede Anmeldung scheitern.
4. Einrichten unter *System → Anmeldung (SSO)* oder auf der Kommandozeile
   (das Secret nur über die Standardeingabe):

   ```bash
   cd /opt/powermanagement
   printf '%s' "$SECRET" | runuser -u www-data -- php bin/power sso:einrichten https://auth.example.com/application/o/powermanagement/ <client-id>
   runuser -u www-data -- php bin/power sso:an
   ```

   Danach meldet `bin/power pruefen` eine Zeile `SSO: OK – …`.
5. Vorher einen **Notzugang** anlegen (`bin/power user:add notfall admin`,
   nie verknüpfen, Kennwort offline verwahren), wenn „nur SSO“ kommen soll.

Prüfnachweise liegen als PDF (bis 10 MB) in der Datenbank. SQLite braucht
dafür nichts; bei MySQL/MariaDB muss `max_allowed_packet` mindestens
16 MiB sein (die Vorgabe von MariaDB) – eine 10-MB-Datei wird base64 rund
13,4 MB groß.

Die Schrift für die PDF (Liberation Sans, SIL Open Font License) liegt im
Repository unter `resources/fonts` – PHP-FPM darf wegen `open_basedir`
nicht nach `/usr/share/fonts`. Weitere Pakete braucht die PDF nicht.

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

### Tanksensor (Plus Uni mit 4–20-mA-Sonde)

Ein Plus Uni (oder ein Plus/Gen3 mit Add-on, ein Gen1 Uni) wird wie oben
angebunden. Am Gerät zusätzlich, von Hand – die App stellt dort nichts ein:

1. **Peripherie:** den Analogeingang als *Voltmeter* einrichten
   (`voltmeter:100`), Messbereich 0 (0–15 V) und nie umschalten.
2. **`report_thr`** so klein wie möglich (0,02–0,05 V), **ECO-Modus aus**,
   *Generic status update over MQTT* an.
3. **Versorgung:** ein eigenes 24-V-SELV-Netzteil (der Plus Uni verträgt
   9–28 V DC) – nie Starterbatterie oder Bordnetz des Generators. Minus an
   **GND**, dort auch die Bürde (499 Ω) bzw. die Masse des Wandlers
   („0–20 mA → 0–10 V“).

Verdrahtung, Abgleich des Wandlers und Wahl der Sonde stehen in
[datenquellen.md](datenquellen.md#420-ma-sonde-am-plus-uni), Annahme und
Einbau am Tank in [bedienung.md](bedienung.md#sonde-annehmen-und-einbauen).
In PowerManagement wird die Sonde unter *Inventar → + Tanksensor* angelegt
und auf der Tankseite eingebaut. Ein eigener Zugang (unten) geht genauso.

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

Ausgehend braucht der Container nur das Übliche (DNS, Updates, Git) und –
wenn Eventworx angebunden ist – HTTPS (443) zu `<mandant>.eventworx.biz`,
mit Anmeldung über SSO HTTPS zum Host des Anmeldedienstes (etwa
`auth.example.com`).

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
   alles, was Node-RED kann. `/api/` davon ausnehmen, wenn andere Instanzen
   über den Proxy kommen (siehe unten): sie melden sich nur mit ihrem Token.
4. **MQTT nicht über den Proxy ins Internet** stellen. Entfernte Shellys
   kommen über WireGuard (Abschnitt 7).
5. **Kundenbereich:** Kunden kommen an einer zusätzlichen Anmeldung des
   Proxys nicht vorbei. Sollen sie von außen herein, braucht es für sie
   einen Weg ohne diese Anmeldung – etwa eine eigene Adresse, die nur
   `/login`, `/logout`, `/kundenbereich`, `/manifest` und
   `/assets/` durchlässt. Die App bremst Fehlversuche selbst, und ein
   Kundenzugang erreicht ohnehin nur `/kundenbereich`. `app.url` (siehe
   unten) ist dann diese Adresse, und sie sollte **https** sein. Melden sich
   Kunden über SSO an ([anmeldung-sso.md](anmeldung-sso.md#kunden-über-authentik)),
   gehören `/login/sso`, `/login/sso/rueckruf` und `/abgemeldet` dazu.
6. **Anmeldung über SSO** statt einer zusätzlichen Anmeldung am Proxy:
   dann nimmt PowerManagement die Anmeldung selbst von Authentik entgegen
   ([anmeldung-sso.md](anmeldung-sso.md)); `app.url` muss die äußere
   https-Adresse sein, unter der auch die Rückruf-Adresse erreichbar ist.

Die nginx-Site passt das Update-Skript nie ungefragt an; eigene Zeilen
bleiben also erhalten.

### Schnittstelle für andere Instanzen

Andere PowerManagement- oder MasterAdmin-Instanzen holen Standorte über
`/api/v1` und schicken Änderungen zurück
([schnittstelle.md](schnittstelle.md)). Dafür:

1. **`app.url`** in `config/config.php` auf die Adresse setzen, unter der
   die **anderen Instanzen** diese erreichen – nicht unbedingt die, die der
   eigene Browser benutzt:

   ```php
   'app' => [
       // …
       'url' => 'https://strom.messe-nord.de',   // oder http://10.10.0.60
   ],
   ```

   *System → Schnittstelle (API)* nennt daraus die Adresse für die
   Gegenseite (`…/api/v1`). Ohne den Eintrag rät die Seite aus der
   Anfrage – hinter einem Proxy stimmt das selten.
2. **nginx:** Die Vorlage `deploy/nginx-powermanagement.conf` bringt den
   Block für `/api/` mit – je Adresse zehn Anfragen in der Sekunde (mit
   Puffer für eine Liste samt Plandateien), Körper bis 50 MB (die App nimmt
   höchstens 48 MiB an), Antworten von nginx selbst (429, 413) als JSON:

   ```nginx
   limit_req_zone $binary_remote_addr zone=pm_api:1m rate=10r/s;   # oben in der Datei

   location ^~ /api/ {                                              # im server-Block
       limit_req zone=pm_api burst=40 nodelay;
       limit_req_status 429;
       client_max_body_size 50m;
       error_page 429 = @pm_api_429;
       error_page 413 = @pm_api_413;
       try_files $uri /index.php$is_args$args;
   }
   ```

   Ist die Site von Hand verändert, ersetzt das Update sie nicht und legt
   die neue Fassung daneben (`/etc/nginx/powermanagement.powermanagement-neu`).
   Dann die Zeilen für `pm_api` und die drei `location`-Blöcke (`/api/`,
   `@pm_api_429`, `@pm_api_413`) von dort übernehmen,
   `nginx -t && systemctl reload nginx`. Ohne den Block geht die
   Schnittstelle auch, aber ohne Bremse und mit der Größengrenze der Site.
3. **Hinter einem Proxy** muss er den Kopf **`Authorization`**
   unverändert durchreichen (nginx tut das von sich aus; eine Anmeldung
   des Proxys davor – SSO, PIN – darf `/api/` nicht abfangen) und Körper
   bis 50 MB annehmen. Mit `set_real_ip_from` und `security.trusted_proxies`
   (Punkt 1 oben) gilt die Bremse je anfragender Instanz, und unter
   *Zuletzt* steht deren Adresse statt der des Proxys. Wer PHP statt hinter
   nginx unter Apache betreibt, braucht `CGIPassAuth On` – sonst kommt das
   Token bei PHP nicht an, und jede Anfrage ist 401.
4. **Firewall:** Die Verbindung baut der **Server** der anderen Instanz auf,
   nicht ihr Browser – Port 80 bzw. 443 muss von dort erreichbar sein.
   Holt diese Instanz selbst von anderen, braucht sie den Weg nach draußen.
5. **https** zwischen den Instanzen ist dringend empfohlen. http geht, das
   Token und die Pläne gehen dann aber lesbar über das Netz – die
   Oberfläche markiert solche Verbindungen als *unverschlüsselt*.

Prüfen von der anderen Instanz aus (ohne Token):

```bash
curl -i http://10.10.0.60/api/v1/info
# HTTP/1.1 401 … WWW-Authenticate: Bearer … {"ok":false,"message":"Kein gültiges Token."}
```

Eine 401 als JSON heißt: erreichbar, nginx und PHP antworten. Mit dem
Token (`-H 'Authorization: Bearer mtma_…'`) kommt 200 mit den Befugnissen.
Kommt mit Token weiter 401, reicht etwas dazwischen den Kopf nicht durch.

## 10 · Update

```bash
# Erst ansehen, was sich ändern würde – dabei wird nichts angefasst
/opt/powermanagement/deploy/update-debian.sh --pruefen

# Dann aktualisieren
/opt/powermanagement/deploy/update-debian.sh
```

Das Skript

1. sichert `config/config.php` und die Datenbank nach
   `storage/backups/vor-update-<zeit>.tar.gz` (die letzten fünf bleiben;
   die Datenbank als stimmige Kopie per `VACUUM INTO`, auch im laufenden
   Betrieb),
2. holt die Änderungen (Token aus `/etc/powermanagement/git.conf`),
3. führt die Migrationen aus (`bin/power migrate`),
4. zieht fehlende Pakete nach,
5. gleicht systemd-Einheiten, PHP-FPM-Pool und Logrotate ab,
6. gleicht die Mosquitto-Dateien ab: Grundlage der ACL, eigene Zugänge
   (`deploy/mqtt-zugaenge.sh` baut die wirksame ACL neu), Konfiguration –
   Mosquitto wird dafür nur neu geladen, die Shellys bleiben verbunden;
   neu gestartet wird er nur, wenn sich die Konfiguration ändert,
7. startet die Dienste neu, kachelt die Grundrisse und prüft mit
   `bin/power pruefen`.

**Geht dabei etwas schief, kommt der alte Stand zurück** – Quellcode und
Datenbank. Die Befehle zum Zurückrollen von Hand stehen am Ende jedes Laufs.

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

Das neue Update-Skript holt fehlende Teile (wirksame ACL, Einheiten,
`storage/mqtt/`) auch sonst nach, wenn der Code schon aktuell ist.

| Option | Wofür |
|--------|-------|
| `--pruefen` | nur anzeigen, was sich ändern würde |
| `--flows-ersetzen` | Flows auch dann ersetzen, wenn sie verändert wurden |
| `--erzwingen` | lokale Änderungen am Quellcode verwerfen statt abzubrechen |
| `--zweig=<name>` | von einem anderen Zweig aktualisieren |
| `--behalten=<n>` | wie viele Sicherungen liegen bleiben (Vorgabe 5) |

**Node-RED** selbst (innerhalb der Hauptversion 4) und **InfluxDB**
aktualisieren sich nicht mit – Node-RED von Hand, InfluxDB über apt bzw.
unattended-upgrades:

```bash
npm install --prefix /opt/node-red --omit=dev node-red@4 && systemctl restart powermanagement-nodered
apt update && apt install --only-upgrade influxdb2 influxdb2-cli
```

## 11 · Sicherung

| Was | Wo | Warum |
|-----|----|-------|
| `config/config.php` | `/opt/powermanagement/config/` | enthält `app.key` – **ohne ihn sind alle hinterlegten Zugänge (Modbus, Cloud, InfluxDB-Token, Eventworx) verloren**, und die Siegel der Übergabeberichte lassen sich nicht mehr prüfen |
| Datenbank | `/opt/powermanagement/storage/database.sqlite` | Anlagen, Standorte, Benutzer, Einstellungen, Übergabeberichte samt Unterschriften und PDF |
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
| Andere Instanz: „Das Token wird dort nicht (mehr) angenommen“, obwohl es stimmt | Ein Proxy (oder Apache ohne `CGIPassAuth On`) verschluckt den Kopf `Authorization` – Abschnitt 9, *Schnittstelle für andere Instanzen*. Sonst ist es widerrufen oder abgelaufen: bei der Gegenseite unter *System → Schnittstelle (API)* nachsehen. |
| Andere Instanz: „Zu viele Anfragen“ | Die Bremse in nginx (zehn je Sekunde und Adresse). Stehen alle Instanzen hinter demselben Proxy ohne `set_real_ip_from`, teilen sie sich die Bremse. |
| Update bricht mit „could not read Username“ ab | Privates Repository ohne Token – `/etc/powermanagement/git.conf` anlegen (Abschnitt 3). |
| Update meldet „lokale Änderungen“ | Jemand hat Dateien in `/opt/powermanagement` geändert. Sichern, dann `--erzwingen`. |
| Anmeldung über SSO scheitert | `runuser -u www-data -- php bin/power sso:pruefen` zeigt jeden Schritt; die Meldungen und ihre Abhilfe stehen in [anmeldung-sso.md](anmeldung-sso.md#fehlersuche). Niemand kommt mehr hinein („nur SSO“, Authentik weg): `bin/power sso:aus --nur-sso`. |

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
