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
- **Übergabeberichte ändern nur über `App\Berichte\BerichtService`.** Nach
  der Prüfer-Unterschrift ist der Inhalt eingefroren, eine Korrektur ist eine
  neue Fassung. Der Inhalt ist ein JSON-Dokument nur aus Strings
  (`BerichtSchema`, `Kanon` – nie Gleitkomma); IDs der Stromkreise setzt nur
  der Server. Messwerte nie vorbelegen; bewertet wird nur auf dem Server
  (`Grenzwerte`). Unterschriften nur als Striche, nie Zeit oder Druck.
  Formulare mit vielen Feldern (Bericht) senden ihren Zustand als **ein
  JSON-Feld** – `max_input_vars` schnitte sonst still ab; der Zustand steht
  in den Feldern (`data-feld`), nicht als JSON in der Seite. Das PDF
  erzeugt nur `BerichtPdf` (Schreiber `App\Pdf`, Schriften in
  `resources/fonts` wegen `open_basedir`) aus dem eingefrorenen Inhalt – nie
  aus Einstellungen, ohne Uhr und Zufall: zweimal erzeugt, dieselben Bytes.
- **Eventworx nur lesend** über `App\Services\Eventworx` (GET, feste
  Pfadliste, Token verschlüsselt und an den Host gebunden). Kundendaten und
  Token nie in Log, Audit oder Antworten. `Http` folgt keiner Umleitung.
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

# Syntax
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /dev/null
bash -n deploy/*.sh && shellcheck -S warning -e SC2016,SC1111 deploy/*.sh
```

| Prüfskript | prüft |
|------------|-------|
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel) |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt |
| `tests/verteiler-abgleich.php` | Verteiler-Abgleich: Standard-Verteiler und Inventar hochladen/übernehmen, hier/dort/beide geändert, Shelly-Tausch im Einsatz, Rechte, Entfernen |
| `tests/aufbau.php` | Aufbau: Plan festhalten, bestätigen/geändert/entfallen/zusätzlich, fertig, beenden, Abgleich (Versatz, Längen, Material, Last) |
| `tests/bereiche.php` | Bereiche: Summe ohne Doppelzählung, Live/Verlauf, Aufräumen; Materialliste und Kabelstückelung |
| `tests/georeferenz.php` | Plan ↔ Breite/Länge, Koordinaten lesen, Längen aus der Georeferenz, strom.json |
| `tests/modbus.php` | Modbus TCP, Victron-Profil, Line Protocol |
| `tests/mqtt.php` | MQTT-Client, Shelly schalten (Gen1/Gen2, Antwort im Baum bei eigenem Zugang, falsches dst), Sammler → MQTT (eigener Mosquitto) |
| `tests/mqtt-helfer.php` | Helfer `deploy/mqtt-zugaenge.sh`: Namensregeln, Soll-Datei prüfen und ablehnen, Block in der Kennwortdatei, wirksame ACL, Rechte am Broker mit der echten ACL (eigener Mosquitto) |
| `tests/mqtt-zugaenge.php` | Eigene MQTT-Zugänge von App bis Broker: Gen2 einrichten/übertragen/prüfen/Neustart/neues Kennwort/entfernen, Gen1, Sperre des gemeinsamen Zugangs, Kontoprobe gegen falsche ACLs, keine Geheimnisse (eigener Mosquitto, Shelly-Attrappen) |
| `tests/influx.php` | InfluxQL: Livewerte, Verläufe, Datenfenster, Energie je Tag (eigene InfluxDB 1.x) |
| `tests/cloud.php` | ComAp WebSupervisor, Victron VRM (Http-Attrappe) |
| `tests/berichte.php` | Übergabeberichte: Vorbelegung aus Plan/Schutzorganen/Kabeln/Inventar (Messwerte nie), Grenzwerte (Zs/Ik, R_iso, RCD, TT/IT, Erdung), Speichern (Version/409, 422, IDs nur vom Server, kein Gleitkomma), Abschluss-Prüfung, Unterschrift (nur Striche aus ganzen Zahlen), Einfrieren/Nummer/Siegel, Zurückziehen, Kunde und ohne Kunde, Kundenmodus und Kennwort, Integrität, PDF beim Abschluss (gespeichert, Neuerzeugung byte-gleich, ein Byte geändert → Befund), Korrektur, Kunde aus früherem Bericht, Sichtbarkeit mit Datenfenster, Löschen/Verwerfen, Lösch-Abläufe, Aufbewahrung, Kommandozeile (`berichte:pruefen`/`pdf`/`aufbewahrung`) und Wartungs-Hinweise, Datenschutz |
| `tests/bericht-pdf.php` | PDF der Übergabeberichte: eigener Schreiber, Schrift als Teilmenge (Prüfsumme, Glyphen, kein .notdef), Layout mit poppler (A4, Schriften eingebettet, Texte, Tabellenkopf je Seite, keine Bilder, Fuß „Blatt x von y“), zweimal erzeugt gleiche Bytes, Vorschau, ohne Kunde, Korrektur, 300 Kreise (ohne poppler übersprungen) |
| `tests/eventworx.php` | Eventworx nur lesend: Adresse, Token an den Host gebunden, Pfadliste, Anfragen, Aufbereitung ohne Beträge/Notizen, Fehlerantworten, Nachlesen bei `data:null`, Drossel, Demo, keine Geheimnisse in Log/Audit/Meldungen (Http-Attrappe) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung, 403/404, CSRF (mit `Accept: application/json` 419/401 als JSON), Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“, Übergabeberichte (Seiten, PDF mit Kopfzeilen/Vorschau/gespeicherten Bytes, Autosave 200/409/422/423, Eventworx-Demo, Firma-Karte, Prüfer-Qualifikation, Rechte von Kunde und Planer, Unterschreiben, Kundenmodus-Sperre und Entsperren, ohne Kunde, Korrektur) (eigener PHP-Server) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV, Adressen, keine Umleitung mit Token (curl und Streams), app.key, PDF-Werkzeuge, Bericht-JS ohne HTML aus Daten, Unterschrift ohne Zeit/Druck und als SVG nur Zahlen |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen, `flows.json` passt zu den Quellen |

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

Die Übergabeberichte nimmt das Skript als Demo-Prüfer `technik` auf; Blatt 1
des PDF holt es über `bin/power berichte:pdf` und `pdftoppm`. Den vom Prüfer
unterschriebenen Demo-Bericht übergibt es dabei an den Kunden und entsperrt
wieder – er lässt sich danach nicht mehr zurückziehen.

Für den Verteiler-Abgleich legt das Skript sich ein eigenes Repository im
Temp-Ordner an (kein Token, nichts auf GitHub) und stellt Einstellungen und
Abgleich-Vermerke danach wieder her – der Server muss dafür diese
Arbeitskopie ausliefern (`php` und `git` im PATH). Nur Bilder mit geänderter
Oberfläche einchecken; die übrigen unterscheiden sich nur in Zeiten und
Messwerten.

Andere Bildschirmfotos (Prüfen, Fehlersuche) und Wegwerfdateien gehören
nicht ins Repository.
