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
- **Updates nur als Auftrag.** Die App startet nie selbst ein Update (kein
  exec, sudo, systemctl): `UpdateService::anfordern()` legt
  `storage/update/soll/auftrag` an (Kennung, Aktion, Auslöser, Ziel – keine
  Optionen, Zweige, Pfade); `deploy/update-helfer.sh` (root, gestartet von
  `powermanagement-update.path`) liest ihn als App-Benutzer, prüft ihn und
  ruft `update-debian.sh` mit selbst gebautem argv. root liest nie die
  Datenbank und arbeitet in `storage/` nur als App-Benutzer; Lauf, Stand,
  Protokoll und Verlauf schreibt er nach `storage/update/ist`. Formate mit
  Kopfzeile, nur additiv – neuer Code liest die Dateien des alten und
  umgekehrt; Codes fest, Sätze nur in PHP (`UpdateService::grundText()`).
  `update-debian.sh` hat eine stabile Schnittstelle
  (`# update-debian-schnittstelle: 1`: `--stufe=2` mit Marke,
  `--nur-abgleichen [--ohne-dienste]`, `--wiederaufnehmen`); jede Datei, die
  es außerhalb von git schreibt, gehört in `abgleichen` (`datei_abgleichen`:
  Temp-Datei und Umbenennen, ein Schreibfehler bricht über den ERR-Trap ab –
  nie als `if`-Bedingung) – eine neue trägt Stufe 2 als `neu_angelegt` in die
  Marke ein, eine beiseitegelegte als `verschoben`, eine neue Einheit kommt in
  `einheiten()` und in `DIENSTE` von `install-debian.sh`. Es stoppt
  `powermanagement-update.service` nie und startet ihn nie neu. Die
  Schreibsperre hebt es erst nach dem Punkt ohne Rückweg auf
  (`eingespielt_festhalten`). Während der Schreibsperre
  (`storage/update/ist/wartung`) nimmt die App keine POSTs an
  (`UpdateService::wartungsSperre()` vor `dispatch()`); wer an Router und
  Sitzung vorbei schreibt, fragt `UpdateService::wartungAktiv()`. Dateien in
  `storage/` fasst root nur als App-Benutzer an – auch in Anleitungen zum
  Zurückrollen von Hand (`runuser -u www-data -- sh -c …`, nie `install`,
  `cp` oder `tar` als root). `bin/power update:*` nie als root. Hinweise für Betreiber stehen als
  Commit-Trailer `Update-Hinweis: …` – System → Update zeigt sie.
- **Broker-Zugänge der Shellys** nur über `MqttZugaenge` (Soll-Datei in
  `storage/mqtt/soll` → root-Helfer `deploy/mqtt-zugaenge.sh`); `/etc/mosquitto`
  schreibt außer dem Installationsskript nur dieser Helfer. Shelly-RPC nur
  über `ShellyRpc` (feste Methodenliste), Schalten weiter nur über
  `Steuerung::ausfuehren()`. Kennwörter eigener Zugänge werden nie
  gespeichert (nur der Mosquitto-Hash): Gen2 per `MQTT.SetConfig` ans Gerät
  oder von Hand; Gen1 und jedes neue Kennwort genau einmal angezeigt, nie
  per MQTT. Die App startet nie ein Gerät neu.
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
bash -n deploy/*.sh tests/*.sh tests/hilfen/*.sh && shellcheck -S warning -e SC2016,SC1111 deploy/*.sh tests/*.sh tests/hilfen/*.sh
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
| `tests/web.php` | alle Seiten ohne PHP-Meldung, 403/404, CSRF, Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“ (eigener PHP-Server) |
| `tests/sitzung.php` | Sitzungssperre: Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche) geben die Sitzung frei – Speichern und Seiten kommen bei träger InfluxDB durch (eigene Instanz, InfluxDB-Attrappe) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV, Adressen, app.key, PDF-Werkzeuge |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen, `flows.json` passt zu den Quellen |
| `tests/installation-debian.sh` | `deploy/install-debian.sh` auf frischem Debian 13 mit systemd (Docker): Erstinstallation, zweiter Lauf (Kennwörter, app.key bleiben), Update mit neuem Ordner in `storage/`; Rechte (Code root, `storage/` www-data), Dienste, Anmeldung, Broker, `bin/power pruefen` |
| `tests/installation-debian.sh` (Update) | Phasen 3b–8: Update ohne HOME und Terminal (systemd-run), über die Oberfläche mit Selbst-Update, kaputte Migration und Prüfung nach dem Neustart (vollständig zurück, auch Einheiten und Vorlagen), Abbruch und SIGKILL mit Wiederanlauf, Automatik mit Veranstaltungsschutz, Aufträge als App-Benutzer und untergeschobene Aufträge; `--umstieg=<commit>`: einmaliger Umstieg von einer Fassung ohne Update-Funktion genau wie in der Anleitung, danach das erste Update über die Oberfläche (eigener CI-Job „Umstieg“) |
| `tests/update-helfer.php` | Update-Helfer ohne root: Auftrag übernehmen und prüfen (Verweis, FIFO, Ordner, Größe, Zeichen, Kennung, Alter, Abstände, gescheiterte Stände, `update.conf` – unbekannte Zeilen und über 4 KiB schalten ab, „nein“ bleibt), argv und Umgebung, Sperre, Signale als TERM, Wiederanlauf (höchstens zweimal, dann aufgegeben), Nachlauf, `ist/wartung` mit Zeitlimit gelesen, Sammler nach einem vor der Marke abgebrochenen Update wieder gestartet, Pfad-Einheit nur geheilt, wenn `soll/` in Ordnung ist (nie im Nachlauf), Protokoll bereinigt und ohne Token, Stand aus git (Kandidaten auch von einem Seitenzweig aus, linear, lokal), Abgleich mit `UpdateService::auftragText()`; als root zusätzlich Code-Prüfung, setpriv und `soll/` ohne Schreibrecht des App-Benutzers (Attrappen `tests/hilfen/update-attrappe.sh`, `systemctl`, Temp-Repository) |
| `tests/update.php` | Update in der App: Parser der Helfer-Dateien, Zustände über die Zeit (auch die Lücke zwischen Übernahme des Auftrags und seinem Lauf, ein langes Update mit Puls ist kein stiller Dienst), installierter Stand ohne git-Prozess, Auftrag (Format, Sperrgründe, Hindernisse), Abzeichen, Audit idempotent, Automatik (Einstellungen, Fenster mit Zeitumstellung und über Mitternacht, Versatz, Karenz, Hindernisse, einmal je Tag, Zeitplan mit Dateien und Audit, nach einer langen Schreibsperre nicht „still“, eine Pause ist kein „verschoben“), Kommandozeile `update:*` (als root abgelehnt, auch wenn `storage/update` ein Verweis auf einen Ordner von root ist), HTTP (Seite, Rechte, CSRF, Schreibsperre 503/Meldung, Status-JSON ohne Sitzungssperre), `update.js` ohne HTML aus Daten (eigene Instanz) |
| `tests/sitzung-gc.php` | Sitzungsdateien aufräumen: GC nach `Session::start()` (Debians php.ini, Lebensdauer aus `idle_timeout`/`max_session`, ohne Grenze aus, Takt einer eingeschalteten GC bleibt, gemeinsames oder unlesbares Verzeichnis unberührt); eingebauter Server mit echtem Dateien-Handler: Veraltetes weg, Jüngeres und die angemeldete Sitzung bleiben, keine PHP-Meldung (eigene Instanz) |

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
