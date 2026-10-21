# PowerManagement – Hinweise für die Arbeit am Code

Sprache: **Deutsch** in Oberfläche, Code (Bezeichner, Kommentare), Commits
und Doku. Aufbau wie MasterAdmin: PHP ohne Framework, keine Composer-Pakete,
keine Build-Schritte. JS-Bibliotheken liegen fertig unter `public/assets/vendor`.

## Grundregeln

- **Messpunkte haben eine Zeit** (`gueltig_ab`/`gueltig_bis`, Inventar-Einsätze).
  Live, Schalten und Zuordnung nur mit jetzigen (`gueltig_bis IS NULL`),
  Verläufe über `MesspunktService::fenster()`.
- **Rechte immer serverseitig.** Die Route prüft das Recht
  (`public/index.php`, drittes Argument). *Worauf* jemand zugreift, prüft
  `App\Auth\Zugriff` (Freigaben, Zeitfenster). Wer Messdaten liest, geht
  über `Messdaten` (schneidet mit `Zugriff::begrenzen()` zu). Wer schaltet,
  geht über `Steuerung::ausfuehren()`. Eine zweite Tür daran vorbei gibt es
  nicht.
- **POST nur mit CSRF-Token** (`Csrf::field()`). Der Router prüft es – nur
  aus dem Formular (`$_POST`), nie aus der Adresse.
- **Sehen ist nicht ändern.** Wer etwas an einem Standort ändert (Anlagen,
  Kabel, Etagen, Plan, Maßstab), braucht eine Freigabe mit *bearbeiten*:
  `Zugriff::darfAufStandortAnlegen()`, `darfStandortAendern()`,
  `darfBearbeiten()`. `siehtStandort()` genügt nur zum Lesen. IDs aus der
  Anfrage (auch indirekte: Etage → Standort, Anschluss → Anlage) einzeln
  prüfen. Rechte und Freigaben vergibt nur, wer sie selbst hat
  (`BenutzerService::darfVergeben()`).
- **Kein Inline-JavaScript.** Die Content-Security-Policy
  (`Response::sicherheitsRichtlinie()`) lässt nur Skripte aus Dateien zu –
  kein `onclick="…"`, kein `javascript:`. Verhalten über `data-`-Attribute
  in `app.js`; ein unvermeidbares `<script>` bekommt `nonce="<?= View::e(View::nonce()) ?>"`.
  Texte aus Antworten per `textContent`, nie per `innerHTML`.
- **Nichts hält die Sitzung, während es wartet.** PHP sperrt die
  Sitzungsdatei für die ganze Anfrage. Endpunkte, die regelmäßig abgefragt
  werden oder auf InfluxDB, Geräte oder Clouds warten, rufen vorher
  `Session::freigeben()` (danach nur noch lesen). Abfragen im Browser
  starten erst, wenn die vorige fertig ist (`live.js`, `karte.js`).
- **Weiterleitungen** nur auf eigene Pfade: `Response::sichererPfad()`,
  `Response::zurueckZiel()` statt `$_SERVER['HTTP_REFERER']` oder `next`.
- **Zeiten** werden in UTC gespeichert (ISO 8601) und in der Zeitzone der
  Installation angezeigt (`App\Support\Zeit`).
- **Messwerte** kommen im normalisierten Format (`docs/datenquellen.md`).
  Zahlen gehen immer als Float nach InfluxDB.
- **Geheimnisse** (Tokens, Kennwörter, Cloud-Zugänge) verschlüsselt ablegen
  (`Crypto`, `Settings::geheimnisSetzen`, `Datenquellen::zugang`). Sie
  gehören nie in Logs, Audit oder Antworten.
- **Broker-Zugänge der Shellys** nur über `MqttZugaenge` (Soll-Datei in
  `storage/mqtt/soll` → root-Helfer `deploy/mqtt-zugaenge.sh`); `/etc/mosquitto`
  schreibt außer dem Installationsskript nur dieser Helfer. Shelly-RPC nur
  über `ShellyRpc` (feste Methodenliste), Schalten weiter nur über
  `Steuerung::ausfuehren()`. Kennwörter eigener Zugänge werden nie
  gespeichert (nur der Mosquitto-Hash): Gen2 per `MQTT.SetConfig` ans Gerät
  oder von Hand; Gen1 und jedes neue Kennwort genau einmal angezeigt, nie
  per MQTT. Die App startet nie ein Gerät neu.
- **Ein Import überschreibt nie still eine Korrektur.** Katalog-Pakete gehen
  nur über `App\Services\KatalogImport` (lesen → Vorschau → uebernehmen,
  je Typ `VerteilerBundle::typAnwenden`, alles in einer Transaktion). Was hier
  anders steht als in der Datei, wird nur ersetzt, wenn es ausdrücklich zum
  Überschreiben gewählt ist – und nur der Stand, den die Vorschau gezeigt hat
  (`$gesehen`). Eigenschaften, die ein Import mitbringt, stehen
  in `AnlagenService::FELDER` – sonst gingen sie beim ersten Bearbeiten verloren;
  was still anders ankäme (Auswahl-Werte, leere Spannung, neue Stecker ohne
  Werte), ist ein Fehler. Ein Katalog-Typ hat nie Messpunkte. Formulare geben
  Zahlen ungerundet aus (`View::zahlFeld`): unverändert speichern ändert nichts.
- **Tanks:** In die InfluxDB kommen nur Rohwerte (`u`, `eingang`, `x`,
  `i_ma`, `abstand_mm`, `sensorfehler` …). Liter und Prozent rechnet nur
  `App\Tanks\TankRechnung` beim Lesen, mit der Fassung aus `tank_kennlinien`,
  die zur Zeit des Werts galt (größte id mit `ab ≤ t`). Fassungen nie ändern –
  eine Korrektur ist eine neue (nur `TankService::fassungAnlegen`), rückwirkend
  nur mit unbegrenztem Datenfenster. Live liest frühestens ab Einbau
  (`Messdaten::tankLetzte`); ein Sensorfehler ist nie „leer“. Den Rohwert für
  Peilung und Betankung holt der Server, nie der Browser. Eintragen am Tank
  (Peilung, Betankung, Kennlinie, Einbau) nur mit *bearbeiten* und solange
  das Datenfenster jetzt gilt (`TankService::darfEintragen`); den Messpunkt
  eines Tanksensors löscht niemand, er endet mit dem Abbau. Ein
  Kraftstoffschlauch braucht *bearbeiten* an beiden Enden. Ein Tank ist nicht
  elektrisch (`AnlagenService::elektrisch()`): keine Anschlüsse, Schutzorgane,
  Kabel und kein Wechsel der Art. Aus Tankwerten wird nie geschaltet, ein
  Gerät am Tank schaltet niemand. Die Restlaufzeit verrät von Generatoren
  ohne Freigabe weder Namen noch Leistung; Tankwerte auf der Kommandozeile
  nur mit `--als=<benutzer>`.
- Neue Tabellen oder Spalten kommen als **neue Migration** dazu
  (`database/migrations/00N_*.sql`). Vorhandene Migrationen nie ändern.
- Oberfläche **mobil zuerst**: Tabellen mit `tabelle-karten` und
  `data-label`, keine Hover-only-Bedienung.

## Prüfen

```bash
# einmalig (Demo-Modus, ohne InfluxDB/MQTT)
php bin/power install admin 'PowerAdmin2026!' --ohne-wechsel --demo
php bin/power demo:seed

# alle Prüfskripte – vor jedem Commit
for t in tests/*.php; do php "$t" || break; done
node tests/nodered-funktionen.mjs
bash tests/installation-debian.sh   # nur mit Docker – sonst übernimmt das die CI

# Syntax
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /dev/null
bash -n deploy/*.sh tests/*.sh && shellcheck -S warning -e SC2016,SC1111 deploy/*.sh tests/*.sh
```

| Prüfskript | prüft |
|------------|-------|
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel); Kraftstoffschlauch ohne Anschlüsse, nicht in Belegung, Speisung, Baum und Spannungsfall, Hinweise „nur Vorlauf“ und „Rücklauf muss in denselben Tank“ |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau; Stellen des Tanksensors, Stecker-Pflicht nur beim Zwischenstecker |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt; fester Tank mit Eigenschaften hin und zurück, Schläuche nicht |
| `tests/katalog-import.php` | Katalog-Import: Format (kein JSON, falsche Art, Format 2, leer, doppelter Schlüssel, unbekannte Art, Stecker ohne Beschreibung, neuer Stecker ohne Werte, Auswahl, Spannung …), neu → übernommen → schon da, keine Messpunkte am Typ (auch im Verteiler-Abgleich), bearbeitbar, „hier anders“ nur mit Auswahl und nur im Stand der Vorschau überschrieben, Typwechsel mit Eigenschaften, Tank-Typ (nur Eigenschaften, feste elektrische Werte, Anschlüsse in der Datei abgelehnt, gelernter Inhalt je mm → „hier anders“), Rechte, alles oder nichts, Vorschau ablegen, Kommandozeile und jedes Paket unter `resources/kataloge` (je eigene leere Instanz) |
| `tests/verteiler-abgleich.php` | Verteiler-Abgleich: Standard-Verteiler und Inventar hochladen/übernehmen, hier/dort/beide geändert, Shelly-Tausch im Einsatz, Rechte, Entfernen; Tanksensor mit Sensordaten und Tank-Typ ohne Anschlüsse, Fingerabdruck vorhandener Stücke unverändert |
| `tests/aufbau.php` | Aufbau: Plan festhalten, bestätigen/geändert/entfallen/zusätzlich, fertig, beenden, Abgleich (Versatz, Längen, Material, Last); Schlauch als Posten, Last ohne Tanks |
| `tests/bereiche.php` | Bereiche: Summe ohne Doppelzählung, Live/Verlauf, Aufräumen; Materialliste und Kabelstückelung; Tank zählt nicht und ist nicht wählbar, Material mit Schläuchen und Kupplungen |
| `tests/georeferenz.php` | Plan ↔ Breite/Länge, Koordinaten lesen, Längen aus der Georeferenz, strom.json |
| `tests/modbus.php` | Modbus TCP, Victron-Profil, Line Protocol |
| `tests/mqtt.php` | MQTT-Client, Shelly schalten (Gen1/Gen2, Antwort im Baum bei eigenem Zugang, falsches dst), Sammler → MQTT (eigener Mosquitto) |
| `tests/mqtt-helfer.php` | Helfer `deploy/mqtt-zugaenge.sh`: Namensregeln, Soll-Datei prüfen und ablehnen, Block in der Kennwortdatei, wirksame ACL, Rechte am Broker mit der echten ACL (eigener Mosquitto) |
| `tests/mqtt-zugaenge.php` | Eigene MQTT-Zugänge von App bis Broker: Gen2 einrichten/übertragen/prüfen/Neustart/neues Kennwort/entfernen, Gen1, Sperre des gemeinsamen Zugangs, Kontoprobe gegen falsche ACLs, keine Geheimnisse (eigener Mosquitto, Shelly-Attrappen) |
| `tests/influx.php` | InfluxQL: Livewerte, Verläufe, Datenfenster, Energie je Tag; Tank: letzter Rohwert ab Einbau mit exakter Zeit, Sensorfehler, Lebenszeichen, Median, Analogkanäle bis 7 Tage, Gefälle, Verbrauch je Tag bei seltenen Meldungen (eigene InfluxDB 1.x) |
| `tests/cloud.php` | ComAp WebSupervisor, Victron VRM (Http-Attrappe) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung, 403/404, CSRF, Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“, Katalog-Import (Seite, Vorschau nach Upload mit vorausgewählten neuen Typen, Übernehmen mit CSRF, Stand der Vorschau, nichts übernommen → Warnung und Vorschau bleibt, ohne Stecker-Recht, Zahlen ungerundet im Formular, 403, kein JSON), Tanks (Formular ohne elektrische Felder, kein Artwechsel, kein Kabel und kein Anschluss am Tank, Schlauch auf der Karte, Tanksensor anlegen und einbauen, Tankseite mit Vor Ort, Verlauf, Tagen, Protokoll, Kennlinie und Versorgung, Verlauf/Tage/CSV mit 403/404, Peilung und Betankung nur mit CSRF und *bearbeiten*, nach dem Ende des Datenfensters 403, Kunde ohne Knöpfe auch im Kartenpanel, Messpunkt des Tanksensors nicht löschbar, Schlauch nur mit *bearbeiten* an beiden Enden, Leitungsenden nur auf der eigenen Etage, Tank-Grenzen in den Einstellungen) (eigener PHP-Server) |
| `tests/sitzung.php` | Sitzungssperre: Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche) und die des Tanks (live, Verlauf, Tage, Peilung) geben die Sitzung frei – Speichern und Seiten kommen bei träger InfluxDB durch (eigene Instanz, InfluxDB-Attrappe) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV (auch Tankverlauf, Protokoll mit Lieferschein, Tage), Adressen, app.key, PDF-Werkzeuge; Tank: Fehler der InfluxDB nur für die Messtechnik, Kennlinie von Hand nur endliche Zahlen, `tank:liste` nur mit `--als` |
| `tests/tanks.php` | Tanks und Tanksensoren: Art ohne Strom, Rechnung Rohwert → mm → l (4–20 mA an Bürde und Wandler, 0–10 V, Ultraschall, Zylinder), Fassungen (Einsatz, Shelly-Tausch, Sensor – nur Hinweis oder Meldeschritt behält den Anker –, Tank, Peilung, Betankung ohne „geprüft“, von Hand, rückwirkend, zurück, nie geändert), Live ab Einbau (auch nach 7 Tagen ohne Wert), Stufen, Peilung und Betankung (auch über 60 min), Versorgung und Restlaufzeit, Verbrauch je Tag (ohne Sprung bei neuer Fassung, Betankung nach langer Ruhe), Rechte (Eintragen nur im Datenfenster), Kommandozeile (`tank:liste`, `tank:rechnen`) und Demo-Gelände (eigene Instanz) |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen (auch Analogeingänge: Voltmeter, Analog-Input, Uni-ADC mit `sensorfehler`), `flows.json` passt zu den Quellen |
| `tests/installation-debian.sh` | `deploy/install-debian.sh` auf frischem Debian 13 mit systemd (Docker): Erstinstallation, zweiter Lauf (Kennwörter, app.key bleiben), Update mit neuem Ordner in `storage/`; Rechte (Code root, `storage/` www-data), Dienste, Anmeldung, Broker, `bin/power pruefen` |

Prüfskripte legen eigene Daten mit eindeutiger Kennung an und löschen sie am
Ende. Sie wählen Datensätze über Eigenschaften, nie über die kleinste ID.

## Node-RED

Funktionscode in `deploy/node-red/funktionen/*.js` bearbeiten, dann
`node deploy/node-red/flows-bauen.mjs`. `flows.json` nie von Hand ändern.

## Entwicklungsserver

```bash
php -S 127.0.0.1:8080 -t public
```

## Bildschirmfotos der Doku

Die Bilder in `docs/bilder` stammen aus dem Demo-Gelände und werden mit
einem Skript aufgenommen – nach Änderungen an der Oberfläche neu aufnehmen:

```bash
php bin/power demo:seed --reset
php -S 127.0.0.1:8080 -t public &
NPM_ROOT=$(npm root -g) node docs/bilder/aufnehmen.mjs http://127.0.0.1:8080
```

Für den Verteiler-Abgleich legt das Skript sich ein eigenes Repository im
Temp-Ordner an (kein Token, nichts auf GitHub) und stellt Einstellungen und
Abgleich-Vermerke danach wieder her – der Server muss dafür diese
Arbeitskopie ausliefern (`php` und `git` im PATH). Nur Bilder mit geänderter
Oberfläche einchecken; die übrigen unterscheiden sich nur in Zeiten und
Messwerten.

Andere Bildschirmfotos (Prüfen, Fehlersuche) und Wegwerfdateien gehören
nicht ins Repository.
