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
- **Schnittstelle `/api/v1`** läuft vor Sitzung und Router (`ApiController`),
  antwortet nur JSON und nimmt das Token nur aus
  `Authorization: Bearer mtma_…`. Was ein Token sieht, bestimmen seine
  Befugnisse und `api_token_standorte` (`App\Auth\ApiZugang`) – nie
  `Zugriff`. Hinaus geht nur, was auch über Git geht (Standorte, Etagen,
  Pläne, Maßstab, Georeferenz, Feststrom) – nie Messpunkte, mobile Planung
  oder Dosen/Racks/APs, auch nicht der Fremdbestand; `location.format`
  bleibt 1, fehlender Schlüssel heißt „unbekannt“, leere Liste „keine“. Von
  außen ändert ein Vorschlag, den hier jemand annimmt – oder mit
  `plaene.schreiben` direkt, aber nur mit passendem Stand (`basis`) oder
  ausdrücklichem `erzwingen`; dieselben Schutzregeln: dabei und beim
  Übernehmen bleiben Namen, Schaltsperre und Messpunkte hier, ein Maßstab
  oder eine Georeferenz hier wird nie gelöscht, gelöscht wird nichts, ein
  Token legt keine Steckertypen an (und niemand einen, den kein Anschluss
  der Sendung nutzt). Zugeordnet werden nur
  feste Anlagen und Kabel, innerhalb des Standorts – über
  `partner_schluessel` (B) bzw. `sync_key` und `api_zuordnungen` je Token
  (A), nie über den `sync_key` anderer Standorte; mobil Gewordenes bleibt
  mobil. Schlüssel an der Schnittstelle prüft
  `LocationBundle::schluesselFormat()` (wie die Adresse), nicht
  `schluesselGueltig()`. Annehmen, Ablehnen und direktes Schreiben holen
  zuerst die Schreibsperre (Zustand bzw. Standort), dann wird geprüft.
  Alles läuft über `LocationUebertrag`; Anfragen an andere Instanzen nur
  über `PartnerService::anfrage()` (folgt keiner Umleitung).
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
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben, ganzer Standort sichtbar |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel), Messpunkte und Einsätze bleiben beim Ersetzen der Anschlüsse |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt; fehlende Dosen bleiben, Messpunkte nach erneutem Holen; Übertrag der Schnittstelle (`LocationUebertrag`), freie Schlüssel |
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
| `tests/schnittstelle.php` | API Seite A: Token, 401/403/404/405/413 nur JSON, keine Sitzung, Zuschnitt (nur freigegebene Standorte, nur fest, keine Messpunkte/Dosen/Racks/APs), Plandatei-Whitelist, Stand, Zugänge anlegen/anzeigen/widerrufen, keine Geheimnisse (eigener PHP-Server) |
| `tests/schnittstelle-vorschlaege.php` | Vorschläge: Prüfungen beim Eingang (auch Anlage ohne Anschlüsse/Schutzorgane/Namen, Leitung ohne Punkte), MasterAdmin-Form, Unterschiede, annehmen nur mit Rechten, Namen/Schaltsperre/Messpunkte bleiben, Georeferenz bleibt, wenn sie fehlt, ablehnen nur mit „bearbeiten“, sehen nur mit dem ganzen Standort; direkt schreiben mit `plaene.schreiben` (passender Stand, 409, `erzwingen`, 422 Steckertypen); unbenutzte Steckertypen nie im Katalog, mobil Gewordenes bleibt mobil, Kabelenden an Anlagen, die nicht mitkommen, bleiben; ersetzte/direkte Zeilen nur mit Eckdaten, 60 Sendungen je Stunde; gleichzeitig entscheiden und schreiben (zweiter Prozess); Schlüssel älterer Fassungen (`-` am Ende, `-2` hinter 64 Zeichen) (eigener PHP-Server) |
| `tests/schnittstelle-partner.php` | Andere Instanzen: eintragen, Zustände, übernehmen/zuordnen/verknüpfen/lösen nur am Standort (Zuordnen nur mit „bearbeiten“, mit Rückfrage), nichts wandert zwischen Standorten, zurückschicken und annehmen, hochladen (409, `erzwingen` → „dort geändert“), Befugnisse vor dem Senden frisch, eigene Einträge nie doppelt (Schlüssel aus der Antwort, `gesendet_als`, freie Schlüssel), dort Gelöschtes kommt nicht zurück, nur hier Vorhandenes hält „hier geändert“, mobil Gemachtes bleibt mobil, lange Schlüssel PM ↔ PM und von MasterAdmin, Verbindungsfehler, MasterAdmin-Format (zweite Instanz, Attrappe) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung, 403/404, CSRF, Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“, Seiten der Schnittstelle (Zugänge, andere Instanzen, Vorschläge; Standortnamen in der Liste der Zugänge nur, wo man hinsieht; CSRF bei Anlegen und Löschen) und `/api` ohne Sitzung (eigener PHP-Server) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV, Adressen, app.key, PDF-Werkzeuge, Zugänge der Schnittstelle vergeben, anzeigen nur mit den Rechten dahinter, Bindung lösen nur mit Verwaltungsrecht, verfälschtes Token, Meldungen anderer Instanzen maskiert, Instanzseite ohne Etagen eines verborgenen Standorts, Ansichten ohne Inline-JS |
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

Für den Verteiler-Abgleich legt das Skript sich ein eigenes Repository im
Temp-Ordner an (kein Token, nichts auf GitHub) und stellt Einstellungen und
Abgleich-Vermerke danach wieder her – der Server muss dafür diese
Arbeitskopie ausliefern (`php` und `git` im PATH). Für die Schnittstelle
startet es eine zweite Instanz (eigene Datenbank im Temp-Ordner, eigener
PHP-Server auf einem freien Port) und räumt danach Zugänge, Vorschlag, die
eigens angelegten Standorte und die Plandateien der zweiten Instanz wieder
weg. Nur Bilder mit geänderter
Oberfläche einchecken; die übrigen unterscheiden sich nur in Zeiten und
Messwerten.

Andere Bildschirmfotos (Prüfen, Fehlersuche) und Wegwerfdateien gehören
nicht ins Repository.
