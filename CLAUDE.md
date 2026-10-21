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
  `karte.js`), oder brechen sie ab (Eventworx in `bericht.js`). Ohne
  Sitzungssperre läuft dieselbe Aktion auch zweimal nebeneinander
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
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben, ganzer Standort sichtbar |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel), Messpunkte und Einsätze bleiben beim Ersetzen der Anschlüsse |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt; fehlende Dosen bleiben, Messpunkte nach erneutem Holen; Übertrag der Schnittstelle (`LocationUebertrag`), freie Schlüssel |
| `tests/katalog-import.php` | Katalog-Import: Format (kein JSON, falsche Art, Format 2, leer, doppelter Schlüssel, unbekannte Art, Stecker ohne Beschreibung, neuer Stecker ohne Werte, Auswahl, Spannung …), neu → übernommen → schon da, keine Messpunkte am Typ (auch im Verteiler-Abgleich), bearbeitbar, „hier anders“ nur mit Auswahl und nur im Stand der Vorschau überschrieben, Typwechsel mit Eigenschaften, Rechte, alles oder nichts, Vorschau ablegen, Kommandozeile und jedes Paket unter `resources/kataloge` (je eigene leere Instanz) |
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
| `tests/berichte.php` | Übergabeberichte: Vorbelegung aus Plan/Schutzorganen/Kabeln/Inventar (Messwerte nie), Grenzwerte (Zs/Ik, R_iso inkl. 250 V, RCD, „gemessen an“ nur am selben RCD, TT/IT ohne RCD, TN-C, Erdung inkl. IT), Speichern (Version/409, verlorene Antwort → 200, 422, IDs nur vom Server, kein Gleitkomma), Abschluss-Prüfung (Prüfdatum, Kurzliste, „ja“/„mängelfrei“ trotz Mangel, Erdung), Unterschrift (nur Striche aus ganzen Zahlen, Grenzen Punkte/Bytes, Name passt zum Konto), Einfrieren/Nummer/Siegel, Schreibsperre eines zweiten Prozesses, Zurückziehen, Kunde und ohne Kunde (gesehener Stand), Kundenmodus (nicht über die Sperre eines anderen Kontos am Gerät, über eine abgelaufene oder die eines gelöschten Kontos schon) und Kennwort, Integrität (Status per SQL, PDF-Siegel, Erzeuger), PDF beim Abschluss (gespeichert, Neuerzeugung byte-gleich, ein Byte geändert → Befund), Korrektur, Kunde aus früherem Bericht, Sichtbarkeit mit Datenfenster (auch nach Löschen des Standorts), Löschen/Verwerfen, Lösch-Abläufe, Aufbewahrung (ab Abschluss, ganze Nummern, Nummer nie zweimal), Kommandozeile (`berichte:pruefen`/`pdf`/`aufbewahrung`) und Wartungs-Hinweise, Datenschutz |
| `tests/bericht-pdf.php` | PDF der Übergabeberichte: eigener Schreiber, Schrift als Teilmenge (Prüfsumme, Glyphen, kein .notdef), Layout mit poppler (A4, Schriften eingebettet, Texte, Tabellenkopf je Seite, keine Bilder, Fuß „Blatt x von y“), zweimal erzeugt gleiche Bytes, Vorschau, ohne Kunde, Korrektur, 300 Kreise (ohne poppler übersprungen) |
| `tests/eventworx.php` | Eventworx nur lesend: Adresse, Token an den Host gebunden, Pfadliste, Anfragen, Aufbereitung ohne Beträge/Notizen, Fehlerantworten, Nachlesen bei `data:null`, Drossel, Demo, keine Geheimnisse in Log/Audit/Meldungen (Http-Attrappe) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung (auch im Fehlerprotokoll des Servers), 403/404 als eigene Seite ohne Navigation, CSRF (mit `Accept: application/json` 419/401 als JSON), Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration, Ändern nur mit „bearbeiten“, Seiten der Schnittstelle (Zugänge, andere Instanzen, Vorschläge; Standortnamen in der Liste der Zugänge nur, wo man hinsieht; CSRF bei Anlegen und Löschen) und `/api` ohne Sitzung, Übergabeberichte (Seiten, PDF mit Kopfzeilen/Vorschau/gespeicherten Bytes, Autosave 200/409/422/423, Eventworx-Demo, Firma-Karte, Prüfer-Qualifikation, Rechte von Kunde und Planer, Unterschreiben, Kundenmodus-Sperre auch für unbekannte/verbotene Adressen, die Seiten der Schnittstelle und Abmelden – `/api` bleibt davon unberührt –, Sperre am Gerät nach neuer Anmeldung, nicht für ein anderes Konto im selben Browser (nach Kennwort-Rücksetzung: kein Ausweg, kein Ereignis, Abmelden geht; auch mit „bearbeiten“ kein eigener Kundenmodus über die fremde Sperre, auch mit nur dem Gerätecookie – Sperre und Cookie bleiben), mit vorläufigem Kennwort kein Kreis über `/profil`, mit abgelaufener Rolle nur das Entsperren statt 403 und danach keine 403, Bremse statt Abmelden nach fünf Fehlversuchen, Entsperren, ohne Kunde, Korrektur), Katalog-Import (Seite, Vorschau nach Upload mit vorausgewählten neuen Typen, Übernehmen mit CSRF, Stand der Vorschau, nichts übernommen → Warnung und Vorschau bleibt, ohne Stecker-Recht, Zahlen ungerundet im Formular, 403, kein JSON) (eigener PHP-Server) |
| `tests/sitzung.php` | Sitzungssperre: Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche) und Eventworx-Suche/-Vorgang im Bericht geben die Sitzung frei – Speichern, Bericht-Autosave und Seiten kommen bei träger InfluxDB bzw. trägem Eventworx durch, der geladene Vorgang bleibt vorgemerkt (`nachtragen()`), nach dem Abmelden 401 ohne Kundendaten; Seite einer anderen Instanz und „Prüfen“ halten sie nicht, während sie auf dort warten (`ohneSperre()`), Meldungen danach und aus einem zweiten Reiter kommen an; Doppelklick auf „Übernehmen“ legt einen Standort an (Sperre ohne Warten); Kennwortwechsel oder Abmelden im zweiten Reiter, während „Prüfen“ wartet: angemeldet bzw. abgemeldet, keine neue leere Sitzung; kein zweites Sitzungscookie beim Wiederöffnen; ebenso halten sie nicht: Dashboard (InfluxDB beim Laden), Eventworx „Verbindung testen“, Einstellungen „Prüfen“, VRM „Jetzt abfragen“, ComAp „Einheiten abrufen“, Schalten und MQTT-Zugänge (Übertragen, Übersicht) bei stummem Broker, „Repository abfragen“ im Location-Abgleich – Meldungen danach kommen an; Doppelklick auf „Übernehmen“ im Location-Abgleich und „Hochladen“, während der Verteiler-Abgleich das Repository abfragt (und umgekehrt), hören sofort mit Meldung auf (Sperre der Arbeitskopie), danach ist nichts gesperrt; Formulare mit `data-laeuft` (abgeschickt wie von app.js, mit `X-Requested-With`) enden mit Meldung und Weiterleitung statt JSON (MQTT-Zugänge, Verteiler-Abgleich), „Neues Kennwort“ trägt `data-laeuft-seite`; Kundenmodus, gestartet während das Dashboard auf die InfluxDB wartet: das Dashboard führt danach zur Kundenseite, Live 423; ebenso die CSV-Downloads (Messwerte, Bereich) ohne Datei und Audit, keine Meldung von „Prüfen“ und keine von vor dem Start auf der Kundenseite; app.js und live.js ohne Browser (Node): fetch ohne `X-Requested-With`, `data-laeuft-seite` schickt der Browser ab, zweiter Klick auf einen Link mit `data-laeuft` abgefangen, Strg-/Umschalt-Klick auf ein Formular sperrt nichts, abgebrochenes Navigieren (`navigateerror`) gibt Link, Formular und Neuladen wieder frei, live.js lädt bei 423 „kundenmodus“ neu (eigene Instanz, Attrappen für InfluxDB, Eventworx über `eventworx.basis`, eine andere Instanz, VRM/ComAp über `auto_prepend_file`, ein stummer Broker und ein träges `git` vorn im PATH gegen ein `file://`-Repository) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV, Adressen, keine Umleitung mit Token (curl und Streams), app.key, PDF-Werkzeuge, Zugänge der Schnittstelle vergeben, anzeigen nur mit den Rechten dahinter, Bindung lösen nur mit Verwaltungsrecht, verfälschtes Token, Meldungen anderer Instanzen maskiert, Instanzseite ohne Etagen eines verborgenen Standorts, Ansichten ohne Inline-JS, Bericht-JS ohne HTML aus Daten, nur mit Zeitgrenze, Abmelden ohne Rückschreiben des Entwurfs, andere Tabs im Kundenmodus, Unterschrift ohne Zeit/Druck, Handballen und Bytegrenze der Fläche (Node), als SVG nur Zahlen |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen, `flows.json` passt zu den Quellen |
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

Die Übergabeberichte nimmt das Skript als Demo-Prüfer `technik` auf; Blatt 1
des PDF holt es über `bin/power berichte:pdf` und `pdftoppm`. Den vom Prüfer
unterschriebenen Demo-Bericht übergibt es dabei an den Kunden und entsperrt
wieder – er lässt sich danach nicht mehr zurückziehen.

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
