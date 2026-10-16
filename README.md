# PowerManagement

Webanwendung für die Stromversorgung von Veranstaltungsgeländen, Messen und
Baustellen: **Stromanschlüsse, Generatoren, Wechselrichter/Speicher und
Verteilungen** dokumentieren, auf dem Grundriss einzeichnen, verbinden,
messen, auswerten und, wo erlaubt, schalten.

Gebaut wie [MasterAdmin](https://github.com/agrotevent/mikrotik-masteradmin):
PHP ohne Framework, SQLite, Leaflet-Grundrisse und derselbe
Location-Abgleich über Git. Für Smartphone und Tablet ausgelegt.

![Karte des Demo-Geländes mit Feststromanschlüssen, mobilen Verteilern, Kabeln und Livewerten](docs/bilder/karte.png)

<img src="docs/bilder/aufbau-handy.png" alt="Aufbau-Modus auf dem Handy" width="220"> <img src="docs/bilder/gps-handy.png" alt="Eigene GPS-Position im Plan" width="220"> <img src="docs/bilder/bereiche-handy.png" alt="Bereiche auf dem Handy" width="220">

Mehr Bilder in der [Bedienungsanleitung](docs/bedienung.md).

## Was es kann

- **Anlagen**: Netzanschlüsse, Aggregate, Speicher/Wechselrichter,
  Verteilungen, Unterverteilungen, Verbraucher, jeweils mit Nennleistung,
  Nennstrom, Seriennummer usw. Jede Anlage ist entweder
  **Feststromanschluss** (gehört zur Location: Hausanschluss, Energiesäule,
  Wandverteiler …) oder **mobil** (gehört zur Veranstaltung).
- **Anschlüsse** je Anlage: Powerlock, CEE 125/63/32/16 A und Schuko ab
  Werk, eigene Steckertypen dazu. Anzahl und Kombination je Verteilung frei.
- **Sicherungen und RCDs** (HS, RCD, RCBO, LS, NH) als Baum, jedem Abgang
  zugeordnet, mit Vorschlagsfunktion. Dazu ein **Verteilerplan** als
  Schaltbild.
- **Standard-Verteiler**: Katalog fertig bestückter Verteiler, die sich auf
  der Karte platzieren lassen.
- **Karte**: Grundrisse (PDF/Bild/SVG) mit Feststromanschlüssen, mobilen
  Verteilern, **Kabeln, Kabelwegen und Kabelbrücken**. Anlagen per Kabel verbinden, mit Prüfung von
  Belastbarkeit, Steckern und **Spannungsfall**, dazu Maßstab und Livewerte.
  **Georeferenz** über zwei Passpunkte: Koordinaten jeder Anlage, eigene
  GPS-Position im Plan beim Aufbau, GeoJSON-Export.
- **Messung**: Shelly (Gen1/Gen2+) über MQTT → Node-RED → InfluxDB.
  **Victron** (GX über Modbus TCP oder VRM-Portal) und **ComAp IG200**
  (Modbus TCP über WireGuard oder WebSupervisor-Cloud) über den Sammler.
- **Auswertung** statt Grafana: Livewerte, Verläufe je Phase, Energie je
  Tag, Kennzahlen, Vergleich, CSV. Messgeräte lassen sich im Verteilerplan
  anklicken und in die Auswertung übernehmen.
- **Bereiche**: Last mehrerer Anlagen und einzelner Abgänge zusammen (etwa
  eine Foodtruck-Meile an mehreren Aggregaten), zeitgleich summiert und
  **ohne Doppelzählung** – was hinter einem anderen Mitglied hängt, zählt
  nicht noch einmal. Mit Grenze, Verlauf, Energie und CSV.
- **Inventar**: Verteiler und Zwischenstecker (bis 125 A) mit ihren
  Shellys – an der Einspeisung, je Abgang oder im Durchgang. Beim Aufbau
  wird ausgewählt, welches Stück wo steht; die Werte gehören dann zur
  Anlage, genau für die Dauer des Einsatzes.
- **Aufbau-Modus**: Plan festhalten, vor Ort jede Anlage und jedes Kabel per
  Klick bestätigen, als geändert (mit echter Länge) oder entfallen markieren.
  Der **Abgleich** zeigt danach Versatz, Kabellängen, Material und die
  gemessene Last gegen den Nennstrom – mit Hinweisen für die nächste Planung.
- **Materialliste** je Bereich oder Etage: Verteiler nach Typ, Kabel in
  üblichen Stücklängen (5–50 m, 75/100 m nur bei 63 A/125 A/Powerlock),
  Kupplungen, Kabelbrücken – zum Drucken für den Aufbau.
- **Schalten**: Shelly ein/aus, Victron Modus/Relais, ComAp Start/Stopp/
  Betriebsart. Das Schaltrecht lässt sich **je Gerät und Kanal** vergeben,
  jeder Versuch wird protokolliert, und an der Anlage gibt es eine Sperre.
- **Rechte mit Zeitfenstern**: Rollen, Freigaben für Standort, Anlage oder
  Gerät mit *gültig ab/bis* und eigenem *Datenzeitraum*. Beispiel: „Kunde A
  sieht die Statistik vom 1. bis 7. Juli, danach nichts mehr.“
- **Location-Abgleich** mit MasterAdmin über ein gemeinsames Git-Repository.
  Die **Feststromanschlüsse** der Location und die **fest verlegten Kabel**
  dazwischen liegen als `strom.json` neben der `location.json`. Mobile
  Anlagen und Kabel bleiben in der Installation.
- **Verteiler-Abgleich** über dasselbe Repository: Standard-Verteiler und
  Inventar (Verteiler und Zwischenstecker mit ihren Shellys), eine Datei je
  Verteiler. Je Verteiler steht, ob er hier, dort oder auf beiden Seiten
  geändert wurde; „Alle übernehmen/hochladen“ überschreibt nie eine
  Änderung der anderen Seite.

## Schnell ausprobieren (Demo-Modus)

Der Demo-Modus braucht weder InfluxDB noch MQTT. Messwerte werden
synthetisch erzeugt.

```bash
php bin/power install admin 'PowerAdmin2026!' --ohne-wechsel --demo
php bin/power demo:seed
php -S 127.0.0.1:8080 -t public
```

→ <http://127.0.0.1:8080>, Anmeldung `admin` / `PowerAdmin2026!`. Weitere
Demo-Konten: `veranstalter` / `Veranstalter2026!` (sieht nur die
Festivalwoche) und `technik` / `Technik2026!!` (darf nur das Licht schalten).

Voraussetzungen: PHP ≥ 8.2 mit `pdo_sqlite`, `mbstring`, `sodium`,
`sockets`, `curl` und `gd`. Für PDF-Grundrisse zusätzlich `poppler-utils`.

## Produktivbetrieb

Ein Skript richtet alles in einem **Debian-13-LXC** ein: nginx, PHP-FPM,
Mosquitto, Node-RED, InfluxDB, Sammler und Wartung.

```bash
git clone https://github.com/agrotevent/powermanagement.git /opt/powermanagement
/opt/powermanagement/deploy/install-debian.sh --name="Stromversorgung Messe Nord"
```

Bei einem privaten Repository braucht der Container ein Zugangstoken. Wie
das geht, steht in der Anleitung.

Details, Optionen, Shelly-Einrichtung, WireGuard, Firewall, Sicherung und
Update: **[docs/installation-debian-lxc.md](docs/installation-debian-lxc.md)**.

## Dokumentation

| Datei | Inhalt |
|-------|--------|
| [docs/installation-debian-lxc.md](docs/installation-debian-lxc.md) | Installation, Update, Sicherung, Fehlersuche |
| [docs/bedienung.md](docs/bedienung.md) | Übersicht, Karte, Anlagen, Verteilerplan, Aufbau und Abgleich, Inventar, Auswertung, Bereiche, Materialliste, Schalten |
| [docs/rechte.md](docs/rechte.md) | Rollen, Freigaben, Zeitfenster, Schaltrechte je Gerät |
| [docs/datenquellen.md](docs/datenquellen.md) | MQTT-Format, InfluxDB-Schema, Shelly, Victron, ComAp, WireGuard, Sammler, Node-RED |
| [docs/eventworx.md](docs/eventworx.md) | Kundendaten aus Eventworx (nur lesend): Token, was gelesen wird, Fehlermeldungen, Datenschutz |

## Kommandozeile

```text
bin/power install [benutzer] [passwort] [--stdin] [--ohne-wechsel] [--demo] [--name=…]
bin/power migrate
bin/power einstellung <schlüssel> <wert> | --stdin
bin/power pruefen
bin/power sammler [--einmal] [--gespraechig]
bin/power user:add <name> <rolle> [passwort]   ·   bin/power user:password <name> <passwort>
bin/power titel "Titel" [untertitel] [kürzel]
bin/power plan:kacheln [--neu]
bin/power location:liste   ·   bin/power location:uebernehmen <schlüssel>
bin/power verteiler:liste   ·   bin/power verteiler:uebernehmen|verteiler:hochladen --alle | <typ|inventar> <schlüssel>
bin/power demo:seed [--reset]
bin/power wartung
```

## Prüfungen

```bash
for t in tests/*.php; do php "$t"; done
node tests/nodered-funktionen.mjs
```

Jedes Skript ist eigenständig (Exit 0 = grün) und räumt auf, was es anlegt.
MQTT- und InfluxDB-Prüfungen starten ihren eigenen Mosquitto bzw. ihre
eigene InfluxDB auf freien Ports. Die Web-Prüfung startet einen eigenen
PHP-Server. Die GitHub-Action `Prüfen` führt alles bei jedem Pull-Request
aus.

## Aufbau

```text
bin/power                 Kommandozeile
config/                   config.example.php (config.php je Installation, nicht im Repository)
database/migrations/      SQL-Migrationen (SQLite; MySQL/MariaDB vorbereitet)
deploy/                   Installation/Update Debian, nginx, PHP-FPM, systemd, Mosquitto, Node-RED-Flows
docs/                     Dokumentation
public/                   Einstieg index.php, CSS, JS (Leaflet, uPlot, pdf.js mitgeliefert)
resources/views/          Vorlagen (PHP)
src/Auth/                 Anmeldung, Rechte, Freigaben/Zeitfenster (Zugriff)
src/Controllers/          Seiten
src/Messung/              InfluxDB, MQTT, Modbus TCP, ComAp, VRM, Sammler, Steuerung, Demo
src/Services/             Anlagen, Schutzorgane, Leitungen, Messpunkte, Inventar, Aufbau, Live, Bereiche, Material, Standorte, Location- und Verteiler-Abgleich
src/Support/              Router, Datenbank, Sitzung, CSRF, Verschlüsselung …
storage/                  Datenbank, Grundrisse, Kacheln, Zwischenspeicher, Protokolle
tests/                    Prüfskripte
```
