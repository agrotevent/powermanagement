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
- **Kundenzugänge** (`users.kunde_id`) haben keine Rechte und erreichen nur
  `/kundenbereich` (Tor im Router vor der Routensuche, nach der
  Kundenmodus-Sperre; `App\Kunden\Kundenbereich`) – eine neue Seite für
  Kunden gehört unter `/kundenbereich`, jede andere Route ist für sie von
  selbst zu. Was sie sehen, kommt nur aus Freigaben am Kunden
  (`subject_type = 'kunde'`, nur Standorte, mit Ende und Datenzeitraum, nie
  bearbeiten/schalten) und aus ausdrücklicher Zuordnung (`berichte.kunde_id`,
  freigegebene `pruefnachweise`) – nie aus dem Standort allein, nie aus
  Freitext, nie aus Freigaben am Konto oder an Rollen. Jede ID im
  Kundenbereich über `KundenSicht` (mit ausdrücklicher Kunden-ID) gegen den
  Kunden prüfen; fremd = nicht vorhanden = dieselbe 404. Tanks sehen Kunden
  nie: kein Tank unter den festen Anschlüssen (`KundenSicht` nur
  elektrische), für Kundenzugänge deckt `Zugriff` keinen Tank, kein
  Tanksensor im Prüfnachweis. Kundenseiten mit `private, no-store`,
  Downloads über `Abrufe`. Der Kundenbereich fragt
  Eventworx nie; Dateien aus Eventworx übernimmt eine Person (`file/list` +
  `file/<ObjectId>` nur aus einer Liste desselben Laufs). Einrichtungslinks
  nur aus `app.url` und nur als HMAC gespeichert; Link und vorläufiges
  Kennwort genau einmal in der Antwort.
- **Kein Inline-JavaScript.** Die Content-Security-Policy
  (`Response::sicherheitsRichtlinie()`) lässt nur Skripte aus Dateien zu –
  kein `onclick="…"`, kein `javascript:`. Verhalten über `data-`-Attribute
  in `app.js`; ein unvermeidbares `<script>` bekommt `nonce="<?= View::e(View::nonce()) ?>"`.
  Texte aus Antworten per `textContent`, nie per `innerHTML`.
- **Nichts hält die Sitzung, während es wartet.** PHP sperrt die
  Sitzungsdatei für die ganze Anfrage. Endpunkte, die regelmäßig abgefragt
  werden oder auf InfluxDB, Geräte, Clouds oder andere Instanzen warten,
  rufen vorher `Session::freigeben()` (danach nur noch lesen); was nach dem
  Warten noch kurz in die Sitzung muss, trägt `Session::nachtragen()` ein,
  und wer danach weiter in die Sitzung schreibt und weiterleitet (Geräte,
  Clouds, Eventworx „Verbindung testen“), wartet in `Session::ohneSperre()` –
  beide öffnen nur dieselbe Sitzung desselben Benutzers wieder, ohne zweites
  Cookie. InfluxDB-Abfragen, git zum Repository und Anfragen an andere
  Instanzen warten schon selbst so (`Influx::abfragenAlle()`,
  `LocationSync::git()`, `PartnerService::anfrage()`).
  Abfragen im Browser starten erst, wenn die vorige fertig ist (`live.js`,
  `karte.js`, `update.js`), oder brechen sie ab (Eventworx in `bericht.js`).
  Ohne Sitzungssperre läuft dieselbe Aktion auch zweimal nebeneinander
  (Doppelklick, zweiter Reiter): Wer danach anlegt oder verknüpft, braucht
  eine eigene Sperre, und zwar **nicht blockierend**
  (`flock(LOCK_EX | LOCK_NB)`, sonst Meldung – `PartnerAbgleich::gesperrt()`,
  `LocationSync::gesperrt()` für die Arbeitskopie des Repositories);
  blockierend hinge die zweite Anfrage mit der Sitzung fest, und die erste
  käme nie zurück. Formulare davor tragen `data-laeuft` (per fetch, danach
  neu laden – der Server antwortet mit Meldung und Weiterleitung; JSON nur
  auf `Accept: application/json`, nie auf `X-Requested-With`). Antwortet er
  mit der Seite selbst (Kennwort genau einmal) oder leitet er woandershin
  weiter als auf die Seite des Formulars (fetch sieht das Ziel nicht), dazu
  `data-laeuft-seite`; Links davor (`Repository abfragen`) tragen
  `data-laeuft` gegen den zweiten Klick. Beginnt der Kundenmodus, während
  eine Anfrage wartet, leitet `Controller::kundenmodusSperrt()` vor Seite
  oder Download zur Kundenseite, und `Flash::add()` schreibt nichts mehr.
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
  (`UpdateService::wartungsSperre()` vor `dispatch()`, nach allen Routen;
  was der Kundenmodus sperrt und für Kundenzugänge das Tor, beantwortet
  weiter der Router; zurück geht es auf die nächste Seite, die es als GET
  gibt – `Router::hatSeite()` –, im Kundenbereich mit `GESPERRT_KUNDE` in
  dessen Layout, nie mit Seiten oder Abzeichen des Updates); wer an
  Router und Sitzung vorbei schreibt, fragt `UpdateService::wartungAktiv()`
  (die Schnittstelle `/api/v1`: schreibende Methoden 503 mit `Retry-After: 30`).
  Wer schreiben kann, meldet sich vorher an (`UpdateService::anfrageAnmelden()`,
  geteilte Sperre auf `storage/update/.anfragen`): Nach dem Setzen der
  Schreibsperre wartet das Update, bis diese Anfragen fertig sind (höchstens
  150 s, `wartung_an`), erst dann sichert es. Wer länger auf andere Instanzen,
  git, Geräte oder Eventworx wartet und danach schreibt, fragt die Sperre
  unmittelbar vor dem Schreiben noch einmal (`LocationUebertrag::anwenden()`,
  `Pruefnachweise::eventworxUebernehmen()`); wer kürzer wartet, sagt im
  Kommentar, warum nicht. Was beim Lesen geschrieben wird (Abrufe des
  Kundenbereichs), steht während der Sperre zusätzlich in `app.log` – ein
  Zurückrollen nähme es sonst mit.
  Wer per JSON schreibt, nimmt 503 mit `wartung` als „gleich noch einmal“ –
  Eingaben bleiben stehen (`bericht.js`, `bericht-kunde.js`, Kartenpanel des
  Tanks); wer regelmäßig liest, nimmt 502–504 als Neustart der Dienste
  (`update.js`, `tank.js`). Neue Funktionen der Node-RED-Flows kommen nur an,
  wenn niemand die Flows im Editor geändert hat – sonst `flows_behalten`, am
  Ende des Laufs noch einmal genannt (`flows_behalten_melden`). Dateien in
  `storage/` fasst root nur als App-Benutzer an – auch in Anleitungen zum
  Zurückrollen von Hand (`runuser -u www-data -- sh -c …`, nie `install`,
  `cp` oder `tar` als root). `bin/power` nie als root: Gehört `storage/` nicht
  root, bricht es vor dem `require` ab (nur `hilfe` geht). Hinweise für
  Betreiber stehen als Commit-Trailer `Update-Hinweis: …` – System → Update
  zeigt sie.
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
  `App\Tanks\TankRechnung` beim Lesen, mit der Fassung aus
  `tank_kennlinien`, die zur Zeit des Werts galt (größte id mit `ab ≤ t`).
  Fassungen nie ändern – eine Korrektur ist eine neue (nur
  `TankService::fassungAnlegen`), rückwirkend nur mit unbegrenztem
  Datenfenster. Live liest frühestens ab Einbau (`Messdaten::tankLetzte`);
  ein Sensorfehler ist nie „leer“. Den Rohwert für Peilung und Betankung
  holt der Server, nie der Browser. Eintragen am Tank (Peilung, Betankung,
  Kennlinie, Einbau) nur mit *bearbeiten* und solange das Datenfenster jetzt
  gilt (`TankService::darfEintragen`), und je Tank nur einmal zur Zeit
  (`TankService::gesperrt()` – sie warten ohne Sitzungssperre auf die
  InfluxDB); den Messpunkt eines Tanksensors löscht niemand, er endet mit
  dem Abbau. Ein Kraftstoffschlauch braucht *bearbeiten* an beiden Enden.
  Ein Tank ist nicht elektrisch (`AnlagenService::elektrisch()`): keine
  Anschlüsse, Schutzorgane, Kabel und kein Wechsel der Art. Aus Tankwerten
  wird nie geschaltet, ein Gerät am Tank schaltet niemand; ein Tank gehört
  in keinen Übergabebericht (`BerichtService::objektePruefen`). Ein fester
  Tank geht über Git und die Schnittstelle mit (ohne Anschlüsse und
  Messpunkte), ein Kraftstoffschlauch nie; ein Schlüssel wechselt dabei nie
  zwischen Tank und elektrischer Anlage oder Schlauch und Kabel, und über
  die Schnittstelle bleibt, was die Kennlinie hier gelernt hat
  (`TankService::KENNLINIE_HIER`) – es zählt daher auch in keinem Stand,
  Zustand oder Vorschlags-Vergleich (`TankService::ohneKennlinieHier()`),
  und seine Herkunft (Peilung, Betankung) geht nie hinaus. Die Restlaufzeit
  verrät von Generatoren ohne Freigabe weder Namen noch Leistung; Tankwerte
  auf der Kommandozeile nur mit `--als=<benutzer>`.
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
  Wer am PDF etwas ändert, das die Bytes ändert, zählt `BerichtPdf::ERZEUGER`
  hoch (sonst meldet `berichte:pruefen --pdf` jeden alten Bericht als
  Befund). Eingefroren ist ein Bericht nach den Daten (Unterschrift,
  `inhalt_hash`, PDF), nicht nach `status`. Der Kundenmodus sperrt Sitzung
  und Gerät; offen ist nur `Kundenmodus::erlaubt()` – auch kein Abmelden.
  Kundenseite und Entsperren bleiben auch ohne das Recht der Route
  (`Kundenmodus::ausweg()`) und mit vorläufigem Kennwort erreichbar.
- **Eventworx nur lesend** über `App\Services\Eventworx` (GET, feste
  Pfadliste, Token verschlüsselt und an den Host gebunden). Kundendaten und
  Token nie in Log, Audit oder Antworten. `Http` folgt keiner Umleitung.
  Eine Dateiliste, in der auch nur eine fremde Datei steht, wird ganz
  verworfen (Eventworx übergeht Filter still). Die Lese-Probe
  (`EventworxProbe`) hat ihre eigene, enge Pfadliste und lädt nie eine Datei.
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
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben, ganzer Standort sichtbar, Freigaben am Kunden (Zeitraum, Datenfenster, ohne Datenende leer) |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel), Messpunkte und Einsätze bleiben beim Ersetzen der Anschlüsse; Kraftstoffschlauch ohne Anschlüsse, nicht in Belegung, Speisung, Baum und Spannungsfall, Hinweise „nur Vorlauf“ und „Rücklauf muss in denselben Tank“ |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau; Stellen des Tanksensors, Stecker-Pflicht nur beim Zwischenstecker |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt; fehlende Dosen bleiben, Messpunkte nach erneutem Holen; Übertrag der Schnittstelle (`LocationUebertrag`), freie Schlüssel; Schläuche nicht über Git; über die Schnittstelle fester Tank hinaus und herüber (ohne Anschlüsse/Messpunkte), gelernter Inhalt je mm bleibt, Tank ↔ Verteilung und Schlauch ↔ Kabel übergangen (auch über Git) |
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
| `tests/schnittstelle.php` | API Seite A: Token, 401/403/404/405/413 nur JSON, keine Sitzung, Zuschnitt (nur freigegebene Standorte, nur fest, keine Messpunkte/Dosen/Racks/APs), Plandatei-Whitelist, Stand, Zugänge anlegen/anzeigen/widerrufen, keine Bindung an Kundenzugänge (per SQL gebunden: endet mit dem Kunden), keine Geheimnisse (eigener PHP-Server) |
| `tests/schnittstelle-vorschlaege.php` | Vorschläge: Prüfungen beim Eingang (auch Anlage ohne Anschlüsse/Schutzorgane/Namen, Leitung ohne Punkte), MasterAdmin-Form, Unterschiede, annehmen nur mit Rechten, Namen/Schaltsperre/Messpunkte bleiben, Georeferenz bleibt, wenn sie fehlt, ablehnen nur mit „bearbeiten“, sehen nur mit dem ganzen Standort; direkt schreiben mit `plaene.schreiben` (passender Stand, 409, `erzwingen`, 422 Steckertypen, Schreibsperre erst beim Schreiben bemerkt → `wartung`); unbenutzte Steckertypen nie im Katalog, mobil Gewordenes bleibt mobil, Kabelenden an Anlagen, die nicht mitkommen, bleiben; ersetzte/direkte Zeilen nur mit Eckdaten, 60 Sendungen je Stunde; gleichzeitig entscheiden und schreiben (zweiter Prozess); Schlüssel älterer Fassungen (`-` am Ende, `-2` hinter 64 Zeichen) (eigener PHP-Server) |
| `tests/schnittstelle-partner.php` | Andere Instanzen: eintragen, Zustände, übernehmen/zuordnen/verknüpfen/lösen nur am Standort (Zuordnen nur mit „bearbeiten“, mit Rückfrage), nichts wandert zwischen Standorten, zurückschicken und annehmen, hochladen (409, `erzwingen` → „dort geändert“), Befugnisse vor dem Senden frisch, eigene Einträge nie doppelt (Schlüssel aus der Antwort, `gesendet_als`, freie Schlüssel), dort Gelöschtes kommt nicht zurück, nur hier Vorhandenes hält „hier geändert“, mobil Gemachtes bleibt mobil, fester Tank (Inhalt je mm nur für einen neuen, ohne Herkunft, gelernt auf einer Seite kein Zustand und kein „geändert“ im Vorschlag, Annehmen lässt den von A), lange Schlüssel PM ↔ PM und von MasterAdmin, Verbindungsfehler, MasterAdmin-Format (zweite Instanz, Attrappe) |
| `tests/berichte.php` | Übergabeberichte: Vorbelegung aus Plan/Schutzorganen/Kabeln/Inventar (Messwerte nie), Grenzwerte (Zs/Ik, R_iso inkl. 250 V, RCD, „gemessen an“ nur am selben RCD, TT/IT ohne RCD, TN-C, Erdung inkl. IT), Speichern (Version/409, verlorene Antwort → 200, 422, IDs nur vom Server, kein Gleitkomma), Abschluss-Prüfung (Prüfdatum, Kurzliste, „ja“/„mängelfrei“ trotz Mangel, Erdung), Unterschrift (nur Striche aus ganzen Zahlen, Grenzen Punkte/Bytes, Name passt zum Konto), Einfrieren/Nummer/Siegel, Schreibsperre eines zweiten Prozesses, Zurückziehen, Kunde und ohne Kunde (gesehener Stand), Kundenmodus (nicht über die Sperre eines anderen Kontos am Gerät, über eine abgelaufene oder die eines gelöschten Kontos schon) und Kennwort, Integrität (Status per SQL, PDF-Siegel, Erzeuger), PDF beim Abschluss (gespeichert, Neuerzeugung byte-gleich, ein Byte geändert → Befund), Korrektur, Kunde aus früherem Bericht, Sichtbarkeit mit Datenfenster (auch nach Löschen des Standorts), Löschen/Verwerfen, Lösch-Abläufe, Aufbewahrung (ab Abschluss, ganze Nummern, Nummer nie zweimal), Kommandozeile (`berichte:pruefen`/`pdf`/`aufbewahrung`) und Wartungs-Hinweise, Datenschutz; Kundenbereich: nie über `sichtbar()` für Kundenzugänge, Zuordnen an Kunden (Recht, Rückfrage bei anderem Namen, alle Fassungen, nicht verworfen), Eventworx-Kunden-Kennung nur aus der Merkliste, Korrektur übernimmt den Kunden, Kunde gelöscht → Bericht bleibt, `namePasst` |
| `tests/bericht-pdf.php` | PDF der Übergabeberichte: eigener Schreiber, Schrift als Teilmenge (Prüfsumme, Glyphen, kein .notdef), Layout mit poppler (A4, Schriften eingebettet, Texte, Tabellenkopf je Seite, keine Bilder, Fuß „Blatt x von y“), zweimal erzeugt gleiche Bytes, Vorschau, ohne Kunde, Korrektur, 300 Kreise (ohne poppler übersprungen) |
| `tests/eventworx.php` | Eventworx nur lesend: Adresse, Token an den Host gebunden, Pfadliste (auch `file/delete/…`, `file/download` nie hinaus), Anfragen, Aufbereitung ohne Beträge/Notizen, Kunden-Kennung nur als ObjectId, Fehlerantworten, Nachlesen bei `data:null`, Drossel, Dateien am Vorgang (Filter, nur Benutzer-PDFs, fremde Datei verwirft die Liste, Download nur aus der Liste desselben Laufs, 10 MB, HTML statt PDF), Vorgänge mit Kunden verknüpfen (Kennung, Rückfrage, je nur einmal, lösen), Lese-Probe (eigene Pfadliste, kein Download, Budget, Ausgabe ohne Inhalte), Demo, keine Geheimnisse in Log/Audit/Meldungen (Http-Attrappe) |
| `tests/kundenbereich.php` | Kundenbereich: Kundenzugänge ohne Rechte (auch mit Rolle per SQL), Tor über alle Routen aus `public/index.php` (GET/POST, auch JSON, danach nichts geändert; die Tank-Routen darunter, dazu mit echten IDs eines festen und eines mobilen Tanks, Karte, Schlauch und Tanksensor – keine Antwort nennt einen Tank, an den Tanks nichts geändert), keine Tanks (nicht unter den festen Anschlüssen, `Zugriff` deckt für Kundenzugänge keinen Tank – für die Rolle „kunde“ wie bisher –, nicht auf den Seiten, `tank:liste --als=<Kundenzugang>` abgelehnt), nur Freigaben am Kunden (nur Standort, mit Ende und Datenzeitraum, nie bearbeiten/schalten), Überschneidung, mehrere Veranstalter je Gelände, Locations/Etagen/Pläne (Kopfzeilen, Bytes, Vorschau nur aus Kachel oder kleinem Plan), Protokolle nur über `kunde_id` (Fassungen, PDF-Kopfzeilen und Prüfsumme, Abrufe in drei Spuren, auch die Leseansicht), gleiche 404 für Fremdes, Einrichtungslink (nur HMAC, Ablauf, einmal), vorläufiges Kennwort, Zugänge/Links/Kennwörter und Wieder-Öffnen nur mit „Alle Standorte …“ und den Protokollen des Kunden (Kommandozeile darf), Einmal-Anzeige mit `no-store`, Kundenmodus des Prüfers sperrt einen danach angemeldeten Kundenzugang nicht (Kundenzugänge nie im Kundenmodus, auch nicht mit einer Sperre auf das eigene Konto), Kontowechsel im selben Browser (Anmeldeseite ohne Meldung des abgelaufenen Kontos, Kundenzugang und anderes Konto ohne dessen Eingaben, dasselbe Konto behält sie), Kunde beendet (auch laufende Sitzungen), Löschen von Kunde und Standort, Benutzerverwaltung ohne Kundenzugänge, Kundenverwaltung (Rechte, CSRF, fremde IDs), Fehlerseiten im Kundenlayout, Datenschutz (eigener PHP-Server) |
| `tests/pruefnachweise.php` | Prüfnachweise (DGUV V3): Upload nur PDF (Größe, Name, Dubletten), Angaben (Daten, Ergebnis, Location und Vorgang nur des Kunden, Inventar nur mit Recht, kein Tanksensor – weder zur Auswahl noch angenommen), Sichtbarkeit (Entwurf/zurückgezogen/freigegeben, unversehrte Datei), Gültigkeit in der Mietzeit (Zeitumstellung, Mitternacht), Aufbewahrung und Kommandozeile, Integrität (beschädigte Datei heilt durch dieselben Bytes; Nachweis/Vorgang ohne Kunde ist Befund), Inventar gelöscht, IDOR, Übernahme aus Eventworx-Vorgängen (Merkliste ∩ frische Liste, höchstens fünf, nie doppelt, Herkunft ohne Inhalt; Kunde gelöscht oder Vorgang gelöst während des Wartens → nichts geschrieben, auch beim Verknüpfen; Schreibsperre eines Updates während des Wartens → ab da nichts mehr, mit `wartung`; Datenbankfehler ≠ „schon übernommen“; Http-Attrappe und Demo über HTTP) (eigener PHP-Server) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung (auch im Fehlerprotokoll des Servers), 403/404 als eigene Seite ohne Navigation, CSRF (mit `Accept: application/json` 419/401 als JSON), Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“, Seiten der Schnittstelle (Zugänge, andere Instanzen, Vorschläge; Standortnamen in der Liste der Zugänge nur, wo man hinsieht; CSRF bei Anlegen und Löschen) und `/api` ohne Sitzung, Übergabeberichte (Seiten, PDF mit Kopfzeilen/Vorschau/gespeicherten Bytes, Autosave 200/409/422/423, Eventworx-Demo, Firma-Karte, Prüfer-Qualifikation, Rechte von Kunde und Planer, Unterschreiben, Kundenmodus-Sperre auch für unbekannte/verbotene Adressen, die Seiten der Schnittstelle, Tankseite, Tank-CSV, Verlauf/Tage/Live (423), Peilung und Betankung und Abmelden – `/api` bleibt davon unberührt –, Sperre am Gerät nach neuer Anmeldung, nicht für ein anderes Konto im selben Browser (nach Kennwort-Rücksetzung: kein Ausweg, kein Ereignis, Abmelden geht; auch mit „bearbeiten“ kein eigener Kundenmodus über die fremde Sperre, auch mit nur dem Gerätecookie – Sperre und Cookie bleiben), mit vorläufigem Kennwort kein Kreis über `/profil`, mit abgelaufener Rolle nur das Entsperren statt 403 und danach keine 403, Bremse statt Abmelden nach fünf Fehlversuchen, Entsperren, ohne Kunde, Korrektur, kein Tank zur Wahl und Anlegen mit Tank abgelehnt), Katalog-Import (Seite, Vorschau nach Upload mit vorausgewählten neuen Typen, Übernehmen mit CSRF, Stand der Vorschau, nichts übernommen → Warnung und Vorschau bleibt, ohne Stecker-Recht, Zahlen ungerundet im Formular, 403, kein JSON), Tanks (Formular ohne elektrische Felder, kein Artwechsel, kein Kabel und kein Anschluss am Tank, Schlauch auf der Karte, Tanksensor anlegen und einbauen, Tankseite mit Vor Ort, Verlauf, Tagen, Protokoll, Kennlinie und Versorgung, Verlauf/Tage/CSV mit 403/404, Peilung und Betankung nur mit CSRF und *bearbeiten*, nach dem Ende des Datenfensters 403, Kunde ohne Knöpfe auch im Kartenpanel, Messpunkt des Tanksensors nicht löschbar, Schlauch nur mit *bearbeiten* an beiden Enden, Leitungsenden nur auf der eigenen Etage, Tank-Grenzen in den Einstellungen), Kundenverwaltung (jeder Reiter, Karte „Kundenbereich“ am Bericht, Einstellungen, Plandatei des Personals mit Kopfzeilen) (eigener PHP-Server) |
| `tests/sitzung.php` | Sitzungssperre: Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche), die des Tanks (live, Verlauf, Tage, Peilung – Doppelklick auf „Peilung“ trägt einmal ein, Meldung des zweiten) und Eventworx-Suche/-Vorgang im Bericht geben die Sitzung frei – Speichern, Bericht-Autosave und Seiten kommen bei träger InfluxDB bzw. trägem Eventworx durch, der geladene Vorgang bleibt vorgemerkt (`nachtragen()`), nach dem Abmelden 401 ohne Kundendaten; Seite einer anderen Instanz und „Prüfen“ halten sie nicht, während sie auf dort warten (`ohneSperre()`), Meldungen danach und aus einem zweiten Reiter kommen an; Doppelklick auf „Übernehmen“ legt einen Standort an (Sperre ohne Warten); Kennwortwechsel oder Abmelden im zweiten Reiter, während „Prüfen“ wartet: angemeldet bzw. abgemeldet, keine neue leere Sitzung; kein zweites Sitzungscookie beim Wiederöffnen; ebenso halten sie nicht: Dashboard (InfluxDB beim Laden), Eventworx „Verbindung testen“, Einstellungen „Prüfen“, VRM „Jetzt abfragen“, ComAp „Einheiten abrufen“, Schalten und MQTT-Zugänge (Übertragen, Übersicht) bei stummem Broker, „Repository abfragen“ im Location-Abgleich – Meldungen danach kommen an; Doppelklick auf „Übernehmen“ im Location-Abgleich und „Hochladen“, während der Verteiler-Abgleich das Repository abfragt (und umgekehrt), hören sofort mit Meldung auf (Sperre der Arbeitskopie), danach ist nichts gesperrt; Formulare mit `data-laeuft` (abgeschickt wie von app.js, mit `X-Requested-With`) enden mit Meldung und Weiterleitung statt JSON (MQTT-Zugänge, Verteiler-Abgleich), „Neues Kennwort“ trägt `data-laeuft-seite`; Kundenmodus, gestartet während das Dashboard auf die InfluxDB wartet: das Dashboard führt danach zur Kundenseite, Live 423; ebenso die CSV-Downloads (Messwerte, Bereich, Tank, Tank je Tag) ohne Datei und Audit, keine Meldung von „Prüfen“ und keine von vor dem Start auf der Kundenseite; app.js und live.js ohne Browser (Node): fetch ohne `X-Requested-With`, `data-laeuft-seite` schickt der Browser ab, zweiter Klick auf einen Link mit `data-laeuft` abgefangen, Strg-/Umschalt-Klick auf ein Formular sperrt nichts, abgebrochenes Navigieren (`navigateerror`) gibt Link, Formular und Neuladen wieder frei, live.js lädt bei 423 „kundenmodus“ neu; Kundenverwaltung: Eventworx-Suche, Verknüpfen und Dateiliste halten die Sitzung nicht, Treffer und Liste sind danach gemerkt, wer sich währenddessen abmeldet, bekommt nichts; beginnt währenddessen der Kundenmodus (Suche, Dateiliste, Übernehmen), kommen weder Treffer noch Dateiliste noch Meldungen an, nichts doppelt übernommen; ihre Formulare tragen `data-laeuft-seite` (eigene Instanz, Attrappen für InfluxDB, Eventworx über `eventworx.basis`, eine andere Instanz, VRM/ComAp über `auto_prepend_file`, ein stummer Broker und ein träges `git` vorn im PATH gegen ein `file://`-Repository) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV (auch Tankverlauf, Protokoll mit Lieferschein, Tage), Adressen, keine Umleitung mit Token (curl und Streams), app.key, PDF-Werkzeuge, Zugänge der Schnittstelle vergeben, anzeigen nur mit den Rechten dahinter, Bindung lösen nur mit Verwaltungsrecht, verfälschtes Token, Meldungen anderer Instanzen maskiert, Instanzseite ohne Etagen eines verborgenen Standorts, Ansichten ohne Inline-JS, Bericht-JS ohne HTML aus Daten, nur mit Zeitgrenze, Abmelden ohne Rückschreiben des Entwurfs, andere Tabs im Kundenmodus, Unterschrift ohne Zeit/Druck, Handballen und Bytegrenze der Fläche (Node), als SVG nur Zahlen; Tank: Fehler der InfluxDB nur für die Messtechnik, Kennlinie von Hand nur endliche Zahlen, `tank:liste` nur mit `--als`; Kundenbereich: Rechte `kunden.*` nur vergeben, wer sie hat, Freigaben am Kunden nur Standort/sehen, Kundenseiten ohne Inline-JS und ohne `Eventworx::`, `Content-Disposition` nur ASCII, Datenschutztext maskiert, Eventworx nur über einen Netzweg mit GET und nur ohne Sitzungssperre |
| `tests/tanks.php` | Tanks und Tanksensoren: Art ohne Strom, Rechnung Rohwert → mm → l (4–20 mA an Bürde und Wandler, 0–10 V, Ultraschall, Zylinder), Fassungen (Einsatz, Shelly-Tausch, Sensor – nur Hinweis oder Meldeschritt behält den Anker –, Tank, Peilung, Betankung ohne „geprüft“, von Hand, rückwirkend, zurück, nie geändert), Live ab Einbau (auch nach 7 Tagen ohne Wert), Stufen, Peilung und Betankung (auch über 60 min), Versorgung und Restlaufzeit, kein Tank im Übergabebericht, Verbrauch je Tag (ohne Sprung bei neuer Fassung, Betankung nach langer Ruhe), Rechte (Eintragen nur im Datenfenster), Sperre je Tank, Kommandozeile (`tank:liste`, `tank:rechnen`) und Demo-Gelände (eigene Instanz) |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen (auch Analogeingänge: Voltmeter, Analog-Input, Uni-ADC mit `sensorfehler`), `flows.json` passt zu den Quellen |
| `tests/installation-debian.sh` | `deploy/install-debian.sh` auf frischem Debian 13 mit systemd (Docker): Erstinstallation, zweiter Lauf (Kennwörter, app.key bleiben), Update mit neuem Ordner in `storage/`; Rechte (Code root, `storage/` www-data), Dienste, Anmeldung, Broker, `bin/power pruefen` |
| `tests/installation-debian.sh` (Update) | Phasen 3b–8 (3c Flows): Update ohne HOME und Terminal (systemd-run), über die Oberfläche mit Selbst-Update, kaputte Migration und Prüfung nach dem Neustart (vollständig zurück, auch Einheiten und Vorlagen), Abbruch und SIGKILL mit Wiederanlauf, Automatik mit Veranstaltungsschutz, Aufträge als App-Benutzer und untergeschobene Aufträge, `bin/power` als root abgelehnt; Node-RED-Flows (unverändert ersetzt, im Editor verändert behalten mit Hinweis und Zusammenfassung, `--flows-ersetzen`, beim Zurückrollen mit zurück); `--umstieg=<commit>`: einmaliger Umstieg von einer Fassung ohne Update-Funktion genau wie in der Anleitung (neue Flows und alle Migrationen, auch 016 und 018), danach das erste Update über die Oberfläche (eigener CI-Job „Umstieg“) |
| `tests/update-helfer.php` | Update-Helfer ohne root: Auftrag übernehmen und prüfen (Verweis, FIFO, Ordner, Größe, Zeichen, Kennung, Alter, Abstände, gescheiterte Stände, `update.conf` – unbekannte Zeilen und über 4 KiB schalten ab, „nein“ bleibt), argv und Umgebung, Sperre, Signale als TERM, Wiederanlauf (höchstens zweimal, dann aufgegeben), Nachlauf, `ist/wartung` mit Zeitlimit gelesen, Sammler nach einem vor der Marke abgebrochenen Update wieder gestartet, Pfad-Einheit nur geheilt, wenn `soll/` in Ordnung ist (nie im Nachlauf), Protokoll bereinigt und ohne Token, Stand aus git (Kandidaten auch von einem Seitenzweig aus, linear, lokal), Abgleich mit `UpdateService::auftragText()`; als root zusätzlich Code-Prüfung, setpriv und `soll/` ohne Schreibrecht des App-Benutzers (Attrappen `tests/hilfen/update-attrappe.sh`, `systemctl`, Temp-Repository) |
| `tests/update.php` | Update in der App: Parser der Helfer-Dateien, Zustände über die Zeit (auch die Lücke zwischen Übernahme des Auftrags und seinem Lauf, ein langes Update mit Puls ist kein stiller Dienst), installierter Stand ohne git-Prozess, Auftrag (Format, Sperrgründe, Hindernisse), Abzeichen, Audit idempotent, Automatik (Einstellungen, Fenster mit Zeitumstellung und über Mitternacht, Versatz, Karenz, Hindernisse, einmal je Tag, Zeitplan mit Dateien und Audit, nach einer langen Schreibsperre nicht „still“, eine Pause ist kein „verschoben“), Kommandozeile `update:*` (als root abgelehnt, auch wenn `storage/update` ein Verweis auf einen Ordner von root ist), HTTP (Seite, Rechte, CSRF, Schreibsperre 503/Meldung, Kundenmodus geht der Schreibsperre vor, Schnittstelle 503 mit Retry-After und Lesen geht, Status-JSON ohne Sitzungssperre), laufende Änderungen beim Setzen der Schreibsperre (Anmeldung über `storage/update/.anfragen`, `wartung_an` aus `update-debian.sh` wartet auf eine Übernahme von einer trägen anderen Instanz und höchstens `ANFRAGEN_WARTEN`, die Übernahme fragt vor dem Schreiben noch einmal: kein Standort, kein Grundriss, „nichts übernommen“), `db_zuruecklegen` aus `update-debian.sh` (harter Verweis ohne alte WAL; ein zweites Mal nach Absturz rührt nichts an), als root der Wächter in `bin/power` (lehnt ab, bevor etwas in `storage/` entsteht, auch `hilfe` ohne Datenbank), `update.js` ohne HTML aus Daten und 423 lädt neu, Berichte nehmen 503 als „gleich noch einmal“; Tank: Peilung, Betankung, Auswerten, Kennlinie, Steigung, Löschen, Einsetzen/Ausbauen und Kraftstoffschlauch während der Schreibsperre 503 bzw. Meldung, nichts eingetragen, lesen geht; eine Peilung, die auf eine träge InfluxDB wartet, ist angemeldet – `wartung_an` wartet, bis sie eingetragen hat; `tank.js` lädt nach 502–504 noch einmal (Node); Kundenbereich: Kundenzugänge lesen und laden während der Schreibsperre weiter (Abrufe zusätzlich in `app.log`), Kennwort und Einrichtungslink abgewiesen mit der Meldung des Kundenbereichs in dessen Layout (ohne Referer zurück zum Konto bzw. zum selben, weiter gültigen Link; JSON 503), das Tor geht der Sperre vor (Adressen des Personals: zum Kundenbereich bzw. 403, kein Auftrag), nie Abzeichen oder Seiten des Updates; Kundenverwaltung zurück auf die nächste Seite, die es als GET gibt; Verknüpfen und „Prüfnachweise aus Eventworx übernehmen“ mit trägem Eventworx und dem echten `wartung_an`: Verknüpfen schreibt vor der Sicherung, Übernehmen fragt noch einmal – nichts übernommen, Liste mit Auswahl und Angaben wieder da (eigene Instanz, Attrappen der InfluxDB und von Eventworx) |
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

Die Übergabeberichte nimmt das Skript als Demo-Prüfer `technik` auf; Blatt 1
des PDF holt es über `bin/power berichte:pdf` und `pdftoppm`. Den vom Prüfer
unterschriebenen Demo-Bericht übergibt es dabei an den Kunden und entsperrt
wieder – er lässt sich danach nicht mehr zurückziehen. Den Kundenbereich
nimmt es als Demo-Kunde `muster-events` auf (nur ansehen, kein Download),
die Kundenverwaltung als Administrator; die Dateien am Eventworx-Auftrag
kommen aus der Demo, übernommen wird nichts.

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
