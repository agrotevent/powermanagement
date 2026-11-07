# PowerManagement – Hinweise für die Arbeit am Code

Sprache: **Deutsch** in Oberfläche, Code (Bezeichner, Kommentare), Commits
und Doku. Aufbau wie MasterAdmin: PHP ohne Framework, keine Composer-Pakete,
keine Build-Schritte. JS-Bibliotheken liegen fertig unter `public/assets/vendor`.

## Grundregeln

- **Messpunkte haben eine Zeit** (`gueltig_ab`/`gueltig_bis`, Inventar-Einsätze).
  Live, Schalten und Zuordnung nur mit jetzigen (`gueltig_bis IS NULL`),
  Verläufe über `MesspunktService::fenster()`. Ein Abgleich beendet direkte
  Messpunkte (`gueltig_bis`), statt sie zu löschen, und lässt die bleibenden
  mit ID und Beginn stehen (`LocationBundle::belegungErsetzen`). Der Shelly
  eines Inventarstücks misst nur über einen Einsatz – direkt zuordnen lehnt
  `AnlagenController::messpunktSpeichern` ab (`MesspunktService::inventarstueck()`),
  einsetzen nur, wenn jeder Shelly des Stücks `Zugriff::darfGeraetZuordnen()`
  erfüllt; ein neues Gerät aus `strom.json` nur unter denselben Bedingungen
  (nicht im Inventar, an keiner Anlage eines anderen Standorts, aus der
  Oberfläche mit `darfGeraetZuordnen()` – sonst übergangen und gemeldet).
  Ein Gerät an mehreren Anlagen schaltet über Anlagen-Freigaben nur, wenn sie
  jede davon decken, an der das Geschaltete hängt – beim Shelly je Kanal
  (Messpunkte dieses Kanals oder ohne Kanal), sonst alle
  (`Zugriff::darfSteuern`).
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
  Eine Rolle gibt ihre Freigaben mit: zuweisen (von Hand, Anmeldeanfrage,
  Gruppe), ändern und löschen nur, wer auch jede ihrer Freigaben vergeben
  dürfte (`rolleVergebenFehler()`; beim Speichern eines Kontos
  `vergabeFehler()` vor `speichern()` – geprüft wird nur Neues und
  Verlängertes, Bestehendes bleibt stehen). Ein Konto verwaltet nur, wer
  auch seine Freigaben (am Konto, an jeder Rolle, auch künftig) vergeben
  dürfte (`darfVerwalten()`). Wer befristet verwaltet, vergibt Enden
  höchstens bis zum eigenen (`eigenesEnde()`, Rollen aus Gruppen
  unbefristet) und verlängert sich nicht selbst – auch nicht über ein
  anderes Konto: einer Rolle, die jemand länger (oder ohne Ende) hat, gibt er
  keine neuen Rechte (`rollenRechteFehler()`), und ein Konto, das länger
  Benutzer verwaltet, verwaltet er nicht (`darfVerwalten()`). Ein Kennwort in offener
  Sitzung (Kennwort ändern, Token anzeigen) prüft `Auth` nur mit Bremse je
  Konto (`bestaetigungGebremst()`); Fehlversuche der Anmeldung kommen mit
  höchstens 64 Zeichen ins Audit, die Bremse je Adresse einmal je Fenster.
  Anschlüsse kopiert eine neue Anlage
  nur von einem Katalog-Typ (`ist_vorlage = 1`), nie von einer Anlage
  (`AnlagenService::speichern`). Eine Location aus dem Repository übernimmt
  die Oberfläche nur mit denselben Rechten wie von Hand und über eine andere
  Instanz (`LocationSync::uebernehmenVerboten()`, vor dem Holen und vor dem
  Eintragen; der Schlüssel in `location.json` passt zum Ordner).
- **Die Git-Arbeitskopie ist Eingabe von außen – ohne Verweise.** git checkt
  mit `core.symlinks=false` aus (`LocationSync::git()`), `holen()` lehnt
  einen Stand mit Modus 120000 unter `locations/`, `verteiler/` und den
  README-Dateien ab (`verweisImRepo()`), und jedes Lesen, Schreiben und
  Löschen dort geht über `LocationSync::pfadInKopie()` (kein Verweis in
  irgendeiner Pfadkomponente), `ausKopieLesen()` (höchstens
  `JSON_HOECHSTENS`) bzw. `inKopieSchreiben()` (Zwischendatei neben der
  Arbeitskopie, dann `rename`) – nie `file_put_contents`, `copy`, `unlink`
  oder `glob` + `file_get_contents` direkt.
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
  gehören nie in Logs, Audit oder Antworten. Ein Geheimnis gilt für sein
  Ziel: InfluxDB-Token/-Kennwort, MQTT-Kennwort und Repository-Token bindet
  `Settings::put()` beim Setzen an das wirksame Ziel (`Settings::BINDUNG`,
  `ZIEL`: Schema, Host, Port, Benutzer – wie `eventworx.token_host`,
  `sso.secret_fuer`); heraus gibt sie nur `Settings::geheimnis()`/`gebunden()`
  bei passendem Ziel, den Wert aus config.php (bzw. `POWERMANAGEMENT_REPO_TOKEN`)
  nur, solange die Einstellungen das Ziel nicht von dem aus config.php
  wegbewegen (`zielWieInDatei()`) – das Repository-Token aus der Umgebung
  zudem für den Server, an den `bindungenNachholen()` es einmal gebunden hat
  (`Settings::UMGEBUNG_REPO`, die Adresse beim Update; nie neu, nie aus der
  Oberfläche; gilt es nicht, sagen Einstellungen und `hindernis()` es). Das Formular entfernt ein Geheimnis, dessen
  Ziel ohne neues Geheimnis wechselt (mit Meldung); ältere bindet
  `bin/power migrate` (`bindungenNachholen()`). Ein neuer Zugang mit Ziel
  kommt in `BINDUNG`/`ZIEL`. Fehlermeldungen fremder Dienste nie mit rohem
  Rumpf (InfluxDB: nur Status und `error` aus JSON).
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
  `Pruefnachweise::eventworxUebernehmen()`, nach dem Holen und nach dem Push
  `LocationSync::updateBegonnen()` – auch in `VerteilerSync`); wer kürzer
  wartet, sagt im Kommentar, warum nicht (der Grundriss-Upload trägt alles
  vor dem Kacheln ein). Kacheln rechnet das Update nie selbst unter der
  Schreibsperre: nach dem Punkt ohne Rückweg `kacheln_anstossen` (eigene
  Einheit `powermanagement-kacheln` als App-Benutzer,
  `plan:kacheln --nachholen`: offene Aufträge, fehlende und abgebrochene –
  nie die Pläne einer älteren `FASSUNG`, die rechnet die Wartung), nach
  Abbruch und Zurückrollen ebenso (`kacheln_nach_abbruch`). Ein Bau
  im Hintergrund liest die Datenbank nur und rechnet seinen Plan zu Ende;
  einen weiteren beginnt kein Prozess, solange die Schreibsperre steht oder
  seit seinem Start ein Update lief (`PlanTileService::updateDazwischen()`:
  Lauf, Commit oder Reflog von HEAD geändert – auch von der Konsole;
  in jeder Schleife über Pläne und in `auftrag()` nach dem Warten) – danach
  macht `plan:kacheln` als derselbe Prozess mit dem neuen Code weiter
  (`pcntl_exec`). Die Automatik verschiebt, solange einer die Sperre von
  `nacheinander()` hält (`verschoben:kacheln`); die Wartung rechnet ältere
  Fassungen deshalb nur bis kurz vor ihrem Fenster
  (`UpdateAutomatik::kachelnBis()`). Was beim Lesen geschrieben wird (Abrufe des
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
  `cp` oder `tar` als root; ebenso Sicherung und Zurückspielen in
  `docs/installation-debian-lxc.md`, und Dateien im Ordner von `nodered` schreibt
  root nur über `runuser -u nodered`). Rekursives `chown` über `storage/` als
  root nur mit `fs.protected_hardlinks = 1` (`hardlinks_geschuetzt` in
  `update-debian.sh`: ohne den Schutz kein Abbruch, sondern Hinweis und
  Warnung `hardlinks`, die Ordner legt `ordner_anlegen` als App-Benutzer an –
  neue Ordner in `storage/` darum nur über `APP_ORDNER`, nie als `.gitkeep`
  aus dem Repository; das Installationsskript ohne den Schutz nur, solange
  alles in `storage/` root gehört, sonst Abbruch mit Hinweis). `bin/power` nie als root: Gehört `storage/` nicht
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
  per MQTT. Die App startet nie ein Gerät neu. Den Gen1-Rundruf
  `shellies/command` nimmt der Helfer dem gemeinsamen Zugang, sobald ein
  Gen1-Gerät gesperrt ist (`topic deny shellies/command`, sperrt auch das
  Lesen); nichts im Flow schickt an den Rundruf – `announce` fragt Node-RED je
  Gerät über `shellies/<id>/command` (Ausgang 2 von `shelly-gen1.js`).
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
- **Geräteprüfung (DGUV V3, BENNING ST 755+)** nur über
  `App\Geraetepruefung\*`. Eine fremde SQLite (Datenbank des Prüfgeräts)
  öffnet nur `BenningDatei`: höchstens `MAX_BYTES`, Kopf geprüft (kein WAL),
  eigene Kopie in `storage/cache` (im `finally` weg), nur lesend
  (`SQLITE_OPEN_READONLY`, `query_only`, `trusted_schema=OFF`, vor
  `quick_check` `BenningDatei::schemaSicher()`: keine berechnete oder
  versteckte Spalte in irgendeiner Tabelle, DEFAULT nur kurze Literale, kein
  CHECK, kein Ausdrucks- oder Teilindex – sonst rechnet SQLite an
  `memory_limit` vorbei; ebenso vor jedem Schreiben der Vorlage und des
  Exports), nur Tabellen/Indizes/Trigger, Pflichttabellen und
  -spalten, ausdrückliche Spaltenlisten, vor dem Lesen gezählt und
  gedeckelt (`GRENZEN`) – nie über `Database::pdo()`. Die Normalform ist
  nur Text, Wahrheitswerte, Listen und Ganzzahlen (Zahlen als Dezimaltext,
  `Messarten::zahl()`; Gerätezeit = Ortszeit → UTC). Vorlage und Export
  nur über `BenningVorlage`: Trigger nur nach dem Muster des Geräts
  (`TRIGGER`, sonst keine Vorlage), geleerte Tabellen ohne Rest im
  Byte-Strom; der Export schreibt nur Kunden, Hersteller, Geräte und ihre
  Fragen – Schema und `EINSTELLUNGEN` bleiben Byte für Byte wie in der
  Vorlage (ob das Gerät die Datei lädt, ist nicht am echten Gerät
  erprobt), höchstens `BenningDatei::MAX_BYTES`. Ein Prüfling kommt nur mit
  einem Prüfablauf, dessen Name und Maske zur Zeile `Pruefung` der Vorlage
  passen (`BenningVorlage::ablaufPasst()`, Name dann genau wie dort), und mit
  Leitungslänge, Querschnitt, Leiterzahl > 0 und Intervall ≥ 1
  (`angabenVollstaendig()`, im Formular Pflicht mit Prüfablauf) hinein –
  sonst übergangen, gezählt, gemeldet; nie 0, nie ein erfundener Wert.
  Prüfabläufe legt nur das Gerät an (hier nur Norm und Zubehör;
  fehlende werden ungültig, nie gelöscht); am Gerät angelegte (ID ≥
  1 000 000) vergibt jedes Gerät selbst – nie nur über die ID zuordnen:
  weichen Name oder Maske vom Bestand ab, bekommt ein Prüfling ihn nur mit
  „als Vorlage übernehmen“ (Hinweis in der Vorschau). Bewertet wird am Gerät, der
  Server leitet nur ab (`Messarten::ergebnisAbleiten()`). Prüfungen sind
  nach dem Import unveränderlich und werden nur über ihren Fingerabdruck
  `Pruefungen::schluessel()` wiedererkannt (Prüfgerät, Zeit, Ablauf,
  Schritte, Sicht – nie die Kennung, die sich am Gerät ändern lässt) – wer
  ihn ändert, legt jede Prüfung doppelt an. Import nur lesen → Vorschau → übernehmen
  (`GeraeteImport`, eine Transaktion, Sperre ohne Warten, Schreibsperre
  vor dem Schreiben noch einmal): Kunden im Gerät werden **nie still**
  zugeordnet (Vorschlag nur `PM-EIGEN`/`PM-K<id>`/gleiche Kundennummer/
  gemerkt, sonst „nicht“; die Wahl kommt aus dem Formular, passend zu
  `zuordnung_stand`), „hier anders“ nur mit Haken und gesehenem Stand,
  vorhandene Kunden nur leere Felder, neue nur mit `kunden.manage`.
  Prüfberichte nur über `PruefberichtService`: beim Unterschreiben
  eingefroren (Nummer `GP-…`, Inhalt per `Kanon` nur aus Strings,
  `inhalt_hash`, Kette, Siegel, PDF – je Prüfung eine Transaktion),
  Korrektur = neue Fassung (die Anforderung gesiegelt, `korrektur_siegel`;
  ein Neuversuch eines älteren Durchgangs deckt sie nie), Messdaten nie;
  Name = voller Name des Kontos (Pflicht wie `pruefer_qualifikation`),
  anderer Prüfer laut Gerät nur mit „Verantwortung übernehmen“;
  Kalibrierung und Zubehör „laut Verwaltung, Stand <Datum der
  Unterschrift>“, nach dem Prüftag geändert → Hinweis; Sammelunterschrift:
  eine Zeile `gp_unterschriften` je `anfrage` (Idempotenz, höchstens
  `JE_ANFRAGE` je Anfrage), Striche nie zweimal – in beide Richtungen mit
  `bericht_unterschriften` (`Unterschrift::stricheBekannt()`).
  Das PDF erzeugt nur `PruefberichtPdf` aus dem eingefrorenen Inhalt (ohne
  Uhr und Zufall, `App\Pdf` dafür nie ändern); wer seine Bytes ändert,
  zählt `PruefberichtPdf::ERZEUGER` hoch. Prüfnachweise daraus nur über
  `Pruefnachweise::ausGeraetepruefung()`: nur gültige, **bestandene**
  Berichte, immer als `entwurf` (Freigabe von Hand, beim Unterschreiben nur
  mit `kunden.nachweise`), Bytes nur aus `gp_bericht_pdf`,
  `EINGEFROREN_AUS_BERICHT` bleibt. Prüflinge sind kein
  Standort-Bestand (`geraetepruefung.view` sieht alle); ein Inventarstück
  nur im eigenen Bestand, nie ein Tanksensor; Anschrift und Kontakt am
  Kunden nie in Audit oder `KundenSicht`. **Echte Gerätedatenbanken**
  (Kundendaten) gehören nie ins Repository, in Prüfskripte, Logs oder
  Commits – Prüfskripte und Demo nutzen nur `BenningMuster`.
- **Eventworx nur lesend** über `App\Services\Eventworx` (GET, feste
  Pfadliste, Token verschlüsselt und an den Host gebunden). Kundendaten und
  Token nie in Log, Audit oder Antworten. `Http` folgt keiner Umleitung.
  Eine Dateiliste, in der auch nur eine fremde Datei steht, wird ganz
  verworfen (Eventworx übergeht Filter still). Die Lese-Probe
  (`EventworxProbe`) hat ihre eigene, enge Pfadliste und lädt nie eine Datei.
- **SSO (OIDC)** nur über `App\Auth\Sso\*` und `/login/sso…`: Code-Flow mit
  PKCE S256, `state`, `nonce`; ID-Token immer mit Signatur (RS256/ES256 über
  JWKS, `kty` passend zu `alg`) und `iss`/`aud`/`exp`/`iat`/`nonce`;
  Endpunkte nur beim Aussteller, keine Umleitungen. Konten nur über
  (`iss`, `sub`) verknüpfen (`schluessel`) – nie über Benutzername oder
  E-Mail; Unbekannte werden Anmeldeanfragen, freigeschaltet mit den Werten
  aus der Zeile, nie aus dem Browser. Rollen aus Gruppen nur als
  `user_roles.quelle = 'sso'` (befristet, eine Kennwort-Anmeldung entfernt
  sie); Freigaben nie aus Gruppen; Zuordnungen nur mit `darfVergeben`,
  verwaltende Rollen und Verwaltungskonten nur mit `sso.admins`. Die
  Rückruf-Adresse kommt nur aus `app.url`; was bestimmt, wohin das Secret
  geht (`sso.verbinden_ueber`, `sso.ca_datei`), steht nur in config.php.
  Client-Secret verschlüsselt und an Issuer+Client-ID gebunden; Code und
  Tokens nie in Log, Audit oder Antworten (`#[\SensitiveParameter]`), das
  ID-Token nur in der Sitzung für die Abmeldung. Das Netz (Discovery,
  Token, JWKS, Userinfo, „Prüfen“) wartet in `Session::ohneSperre()`; der
  `state` wird vorher verbraucht, geschrieben wird danach nur in dieselbe
  Sitzung – und erst, nachdem Einrichtung (`Einstellungen::unveraendert()`,
  frisch gelesen) und Sitzung noch einmal geprüft sind. HEAD verbraucht
  nirgends etwas (Router: HEAD = GET). Wer ein Kennwort bestätigen lässt
  (Kundenmodus, Token anzeigen), geht über `Auth::bestaetigen()` – bei „nur
  SSO“ nur über den Anmeldedienst mit derselben Identität und denselben
  Schranken wie beim Anmelden (auch MFA). Verbinden im Profil erst nach dem
  Kennwort. Eine neue Verbindung (Aussteller/Client-ID) bei bestehenden
  Zuordnungen nur, wer jede selbst anlegen dürfte. Kundenzugänge per SSO
  nur mit `sso.kunden`. Während der Schreibsperre eines Updates beginnt kein
  SSO-Vorgang (Anmelden, Verbinden, Bestätigen: Hinweis statt Anbieter), und
  der Rückruf schreibt nichts – kein Konto, keine Identität, Anfrage, Rolle,
  Bremse, kein Fehlversuch und kein Audit (Code `update`): nach dem
  Verbrauch des `state` angemeldet wie ein POST
  (`UpdateService::anfrageAnmelden()`), dann gefragt, nach dem Warten noch
  einmal (auch `fehlgeschlagen()`); `Einstellungen::einschalten()` fragt nach
  der Prüfung noch einmal. Die Kennwort-Anmeldung bleibt offen wie ohne SSO
  (`UpdateService::wartungsSperre()` lässt POST /login durch; nur
  Buchhaltung). Ein Rückruf mit gültigem `state` vermerkt in der Sitzung
  `Anmeldung::BEIM_ANBIETER` (auch gescheitert oder während des Updates),
  damit das Abmelden danach `pm_sso_neu` setzt. Prüfskripte zu SSO laufen
  in einer eigenen Instanz (`tests/hilfen/instanz.php`, mit eigenem
  `storage/update`) gegen die OIDC-Attrappe, nie gegen die
  `sso.*`-Einstellungen der Arbeitskopie.
- **Grundrisse bis 100 MB:** die Grenze steht nur in
  `SiteService::MAX_UPLOAD_BYTES` (Formular, Prüfung im Browser, Upload,
  Git- und Partner-Abgleich); die Vorlagen in `deploy/` passen dazu
  (`upload_max_filesize` 100M, `post_max_size` 105M, `client_max_body_size`
  105m nur für `/standorte/grundriss`, 50m für `/api/`, 64k für `/login`
  (`location ~ ^/login/*$` – die App kürzt Schrägstriche am Ende),
  sonst 16m – alle drei mit eigenem FastCGI-Block, nie über `try_files` zu
  `index.php`: bei Körpern in Stücken (chunked) zählt nur die location, die
  ihn liest). Der Grundriss nimmt je Adresse höchstens zwei Uploads zugleich
  an (`limit_conn pm_plan 2`, 429 mit eigener Seite – nginx puffert vor der
  App), POST `/login` ist gebremst (`pm_anmeldung`, nur POST zählt); beide
  Zonen stehen oben in der Vorlage (http-Kontext). `app.js` meldet 429 wie 413.
  Rasterbilder über `PlanTileService::MAX_PIXEL` nie mit gd ausgepackt
  (Speicher außerhalb von `memory_limit`), sondern über poppler (`BildPdf`).
  Wie groß gerechnet wird, sagt nur `PlanTileService::ziel()`: poppler rechnet
  ein Bild immer auf die ganze Ausgabe hoch (3 Byte je Pixel), ein Bild im
  PDF bleibt deshalb unter `MAX_BILDPIXEL` (`PdfRasterService::bildflaeche()`),
  ohne pdfimages die Seite unter `MAX_PIXEL`; pdftoppm läuft unter `prlimit`
  (`PdfRasterService::SPEICHER`), „Out of memory“ ist ein Fehler, auch mit
  Rückgabewert 0. Nie bauen, wenn weniger als `PLATTE_RESERVE` frei bliebe
  (`plattePruefen()` vor jedem Bild, nach jedem Streifen und jeder Spalte).
  Die Stufen unter der obersten eines PDF rechnet poppler selbst (große in
  Streifen), die großen zuerst, solange `kleineDirekt()` die Zeit gibt.
  Grenzen, die sich nur mit stundenlangen Bauten oder voller Platte zeigten,
  setzen Prüfskripte über `PlanTileService::$pruefung` herab. Was die Pixel der Kacheln
  ändert (Filter, Größen, Formate, Qualität), zählt `PlanTileService::FASSUNG`
  hoch – wie `BerichtPdf::ERZEUGER`; ältere Pyramiden bleiben nutzbar und
  gelten als veraltet. Beim Neurechnen bleiben Seitenverhältnis und Qualität
  der vorhandenen Pyramide (Anlagen stehen in Prozent von Breite und Höhe).
  Kacheln liefert `KarteController::planKachel` (ohne Sitzungssperre) mit
  dem Stand der Pyramide in der Adresse (`?v=`, `PlanTileService::stand()`)
  ein Jahr in den Browser, ohne `Pragma`/`Expires` aus `session_start()`;
  ein unbekannter Stand ist 404, eine abgelöste Fassung kommt noch
  `GNADE` Sekunden. **Nie in den Ordner rechnen, aus dem die Karte liest:**
  `bauen()` baut nach `v<stand>.bau/` und setzt erst fertig ein
  (`umschalten()`: `rename`, dann der Zeiger `kacheln.json` über
  Zwischendatei und `rename`); bei Fehler oder Abbruch bleibt die bisherige
  Fassung ganz. Neu rechnen aus der Oberfläche (`plans.manage` und
  *bearbeiten* an jedem Standort, dessen Etagen die Pyramide nutzen) stößt
  nur an (`neuRechnenAnfordern()`, ebenso Upload und Abgleich über
  `imHintergrund()`): ein Auftrag je Plan in `.auftrag.json`, und **ein**
  Prozess im Hintergrund (`arbeiterStarten()` → `plan:kacheln --auftraege`
  → `arbeiten()`, hält `.arbeiter`) rechnet alle nacheinander
  (`auftrag()`, `nacheinander()`) – nie ein Prozess je Klick, und keiner
  wartet `GNADE` ab. Wer selbst rechnet (Upload), rechnet nur bei freier
  Sperre (`nacheinander()` ohne Warten), sonst gibt er ab. Fristen (`$bis`)
  gelten auch beim Warten auf die Sperre und im Bau; den Zustand fragt die Karte
  über `/grundriss/{id}/kacheln/stand` (gibt zuerst die Sitzung frei; die
  nächste Abfrage erst nach der Antwort, `plan-neu.js`). Die Wartung räumt
  Abgelöstes weg, holt Abgebrochenes nach und rechnet veraltete Pyramiden
  mit Zeitbudget (`veralteteNachrechnen()`). Die Karte wählt die Stufe nach
  Gerätepixeln (`kachelStufe()` in `plan-karte.js`) und streckt Kacheln vom
  Server nie (darunter liegt Papierweiß gegen Fugen).
  Eine Datei von außen nie ganz in Listen zerlegen (Treffer, Chunks) –
  zählen oder deckeln. Kacheln rechnet in einer Web-Anfrage nur der Upload,
  `KACHELZEIT_IM_UPLOAD` lang und ohne Sitzungssperre
  (`Session::ohneSperre()`; das Formular trägt `data-laeuft`, die Größe
  prüft der Browser davor); den Rest und alles Übernommene (Git, andere
  Instanz, Vorschlag) `PlanTileService::imHintergrund()` – nacheinander
  (`nacheinander()`). Wer in einer Web-Anfrage mehrmals wartet (Git, andere
  Instanz), deckelt jede Frist mit `App\Support\Anfragezeit` (285 s für alle
  zusammen). Was der Upload in die Datenbank einträgt, steht vor dem
  Kacheln (ein Update wartet höchstens 150 s auf ihn, dann sichert es);
  steht die Schreibsperre inzwischen, kachelt er nicht mehr selbst.
  Ersetzte Pläne räumt `SiteService::planAufraeumen()` weg, was
  liegen bleibt die Wartung (`PlanTileService::wartung()`). Die Schnittstelle
  bleibt bei 30 MiB je Plan (Protokoll von MasterAdmin); ein ausgelassener
  Plan geht ohne Maßstab und Georeferenz und zählt nicht als abgeglichen.
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
| `tests/zeitfenster.php` | Freigaben, Gültigkeit, Datenfenster, Schalt-Freigaben, ganzer Standort sichtbar, Freigaben am Kunden (Zeitraum, Datenfenster, ohne Datenende leer), Rollen aus SSO-Gruppen enden mit `gueltig_bis` (auch ihre Rollen-Freigaben) |
| `tests/anschluesse.php` | Anschlüsse, Standard-Verteiler, Schutzorgane, Kabel, Spannungsfall, fest/mobil (Anlagen und Kabel), Messpunkte und Einsätze bleiben beim Ersetzen der Anschlüsse; Kraftstoffschlauch ohne Anschlüsse, nicht in Belegung, Speisung, Baum und Spannungsfall, Hinweise „nur Vorlauf“ und „Rücklauf muss in denselben Tank“; Vorlage nur ein Katalog-Typ (eine Anlage als `vorlage_id` kopiert weder Anschlüsse noch Notizen); Messpunkte aus einer Datei: bleibende mit ID und Beginn, fehlende beendet statt gelöscht, neue ab jetzt, Kennung mit MQTT-Platzhaltern übergangen, frühere und Einsatz-Messpunkte unverändert |
| `tests/inventar.php` | Inventar, Einsatz/Abbau, Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten nach Abbau; Stellen des Tanksensors, Stecker-Pflicht nur beim Zwischenstecker; Shelly eines Stücks nie direkt zuordnen (auch nicht als Administration, `AnlagenController` in-process), Stück mit einem Shelly an einer fremden Anlage nicht einsetzbar (`InventarController::einsetzen`, JSON), Gerät an eigener und fremder Anlage schaltet nicht über die Freigabe der eigenen, Mehrkanal-Shelly schaltet je Kanal (eigener Kanal ja; fremder, ohne Kanal, Kanal ohne Messpunkt, Messpunkt ohne Kanal an der fremden Anlage nein) |
| `tests/location-abgleich.php` | Git-Location-Abgleich mit MasterAdmin-Format; Update beginnt beim Holen (nichts übernommen/hochgeladen) oder beim Push (dort angekommen, Vermerk hier erst beim nächsten Hochladen, mit neuem Commit); `location:uebernehmen` mit PDF-Grundriss trägt Vermerk und Audit vor dem Kacheln ein (Sicherung mitten im Kacheln) und kachelt unter `nacheinander()`; nur Feststromanschlüsse und feste Kabel, mobile Planung bleibt; fehlende Dosen bleiben, Messpunkte nach erneutem Holen; Übertrag der Schnittstelle (`LocationUebertrag`), freie Schlüssel; Schläuche nicht über Git; über die Schnittstelle fester Tank hinaus und herüber (ohne Anschlüsse/Messpunkte), gelernter Inhalt je mm bleibt, Tank ↔ Verteilung und Schlauch ↔ Kabel übergangen (auch über Git); Plan über 100 MB vor dem Push abgelehnt (nichts hochgeladen), über 50 MB Hinweis, darunter keiner; Frist der ganzen Anfrage (`Anfragezeit`) für Holen und Hochladen, örtliche Befehle ausgenommen; Übernehmen aus der Oberfläche nur mit Rechten (ohne Freigabe abgelehnt, bevor das Repository gefragt wird; nur sehen genügt nicht; mit strom.json ohne „Anlagen ändern“ gar nichts; neuer Standort nur mit allen Standorten; Kommandozeile als Betreiber); Messpunkte beim Übernehmen: bleibende mit ID und Beginn, fehlende beendet; ein neues Gerät aus `strom.json`, das an einer Anlage eines anderen Standorts misst oder in einem Inventarstück steckt, übergangen und gemeldet (Oberfläche mit *bearbeiten* am Standort und Kommandozeile); Verweis (Symlink) im Repository auf einen Kanarienvogel in `storage/` (`file://`): Hochladen, Übernehmen und Abfragen abgelehnt mit Meldung, Kanarienvogel unverändert, nichts gepusht, kein Verweis ausgecheckt; Verweis in einer alten Arbeitskopie: weder gelesen noch beschrieben, kein „..“, keine Zwischendatei bleibt; nach dem Entfernen geht es weiter |
| `tests/grundrisse.php` | Grundrisse bis 100 MB: Grenze und Anzeige, wirksame Grenze aus `upload_max_filesize`/`post_max_size`, Vorlagen in `deploy/`, nginx mit der Vorlage (64k für `/login`, auch `/login/` und `/login//`, 16m/50m/105m je Adresse, auch in Stücken; Grundriss: zweiter Upload neben einem langsamen durch, der dritte 429 mit eigener Seite; `POST /login` nach 20 gebremst, `/login/` unter derselben Bremse, GET nie; ohne nginx übersprungen), Prüfnachweise, Katalog-Import und Datenbank des Prüfgeräts unter den 16m, Ablehnen ohne Ablage (zu groß, Bombe, progressiv, CMYK, Transparenz, Zeilensprung, kaputt, PNG aus zu vielen Chunks), Seiten eines PDF mit 100 MB aus Millionen Treffern unter 512M, Streifenhöhe, `BildPdf` (Kopf, Farben durch poppler), Bild mit 96 Megapixeln über poppler statt gd (Speicher des Prozesses gemessen), Zeitgrenze im Upload → Hintergrund (`plan:kacheln --auftraege`, `kacheln.log`; Audit vor dem Kacheln, die Etagen im Hintergrund in `app.log`), im Hintergrund nacheinander (Sperre), Abbruch über fertiger Pyramide ohne Reste und ohne `kacheln.json`, Etage während des Baus gelöscht, ersetzte Pläne samt Kacheln weg (je PDF-Seite), übernommene Pläne in einer Web-Anfrage gleich in den Hintergrund, Wartung (Zwischendateien, Pläne und Kacheln ohne Etage, nachrechnen), Tank und Kraftstoffschlauch bleiben bei Kacheln im Hintergrund und neuem Plan, wo sie sind; Schärfe: Zielgröße je Qualität (A0, Gelände 2 × 1 m, A3, Scan im PDF unter 80 Megapixeln, ohne pdfimages wie früher 50, Bilder bis 80 Megapixel in Originalgröße), Ausweichen bei Zeit/Platte (Hochrechnung mit mindestens 4 KB je Kachel über 256 MB Reserve), kleine Stufen direkt nur mit Zeit, Fassung (ohne Eintrag veraltet), Linien eine Stufe tiefer auf gerader und ungerader Spalte, Papier weiß und Rand durchsichtig auf allen Stufen, JPEG in Qualität 92, Scan als PNG → JPEG, mit Transparenz verlustfrei, Haarlinien im PDF drei Stufen tiefer zu sehen, Neurechnen behält Qualität und Seitenverhältnis, ersetzt `kacheln.json` atomar und lässt keine Zwischendatei und keinen Bauordner zurück, pdfimages misst die Bildfläche, Speicher von pdftoppm beim 96-Megapixel-Bild; Stand der Pyramide in der Kacheladresse (unbekannter Stand → keine Kachel, neu gerechnet → neuer Stand); Fassungsordner: eigener Ordner je Fassung, abgelöste unter ihrem Stand bis `GNADE`, danach weg; ältere Pyramide (Stufen im Planordner, ohne Fassung) lesbar, empfohlen, beim Neurechnen abgelöst in `v<stand>`, `veralteteNachrechnen(0)` rechnet nichts und meldet „Noch N“; Neu rechnen im Hintergrund (träges `pdftoppm` vorn im PATH): sofort zurück, ein Auftrag bei zwei Klicks, währenddessen Byte für Byte die alte Kachel und derselbe Stand, ein zweiter Prozess endet mit „läuft schon“, danach umgeschaltet; Auftrag ohne Prozess → abgebrochen, die Wartung holt ihn in der verlangten Qualität nach; `pdftoppm` scheitert am zweiten Streifen → Kacheln und `kacheln.json` unverändert, kein Bauordner, Zustand „fehler“ ohne Pfade; dieselbe Datei für zwei Etagen → einmal gerechnet (Upload und `--neu --etage=A,B`); Etage gelöscht während des Neubaus → nichts bleibt; Wartung im Kachelordner einer Etage (Abgelöstes nach `GNADE`, Bauordner nach einem Tag, alte Stufen, Zwischendateien, alte Aufträge; Aktives, Frisches, Fremdes und Gesperrtes bleiben); Abbruch über fertiger Pyramide → die bisherige bleibt; Anzeige ohne Browser (Node, echtes Leaflet: Kachelstufe nach Gerätepixeln bei devicePixelRatio 1/1,25/2/3 – nie vergrößert, solange es eine feinere gibt, nie unter die Hälfte –, Kacheln ungestreckt, Ecken auf dem Marker desselben Planpunkts, Papierweiß darunter, animiertes Zoomen über mehrere Stufen ohne Fehler, Umsteigen auf eine neu gerechnete Pyramide derselben Fläche ohne Neuladen, sonst false; nach „Neu laden“ derselbe Ausschnitt in derselben Größe, auch mit anderen Pixeln und anderer oberster Stufe); große Stufe unter der obersten direkt von poppler in Streifen, pixelgleich (Streifengrenze über `$pruefung` herabgesetzt); die Wächter im Bau: reicht die Zeit nicht für alle Stufen darunter, die großen direkt und die kleinen gemittelt, über dem Zeitbudget bzw. ohne Platz kleiner gerechnet (`grenze` zeit/platte), unter der Reserve bricht ein Bau von PDF wie Bild ab und der bisherige Plan bleibt; pdftoppm unter `prlimit` (1 GB), „Out of memory“ mit Rückgabewert 0 ist ein Fehler (kein weißer Plan); abgelöste Fassung nach `GNADE` keine Kachel mehr, auch wenn sie noch liegt; bei belegter Sperre: der Upload gibt gleich an den Hintergrund, drei Aufträge – ein Prozess, alle „wartet“, danach nacheinander gerechnet, und er endet gleich; `--veraltet --bis` hält die Frist auch beim Warten auf die Sperre (Plan und Zustand unverändert); Bild ohne Kacheln, das welche bekäme: aufbereiten möglich; während eines Updates (eigenes `storage/update`): bei Schreibsperre trägt „Plan neu rechnen“ ein, startet aber keinen Prozess (nichts in `kacheln.log`, „wartet, bis das Update eingespielt ist“), weder Prozess im Hintergrund noch Wartung (fehlende, ältere Fassung) beginnt einen Plan; beginnt das Update, während der Prozess wartet, beginnt er den Plan nicht und endet; `plan:kacheln --nachholen` rechnet danach den Auftrag und den fehlenden Plan (Rest eines getöteten Baus weg), nicht die ältere Fassung; lief das Update schon durch, macht der Prozess mit dem neuen Stand weiter (`pcntl_exec`); ein Update erkannt auch von der Konsole (Commit), nach einem Prüflauf und zurückgerollt auf denselben Commit (Reflog von HEAD), ein Prüflauf allein nicht; Update von der Konsole während des ersten Plans → der zweite bleibt angefordert; Budget der Wartung bis 2 min vor dem Fenster der Updates (ohne poppler-utils, gd oder Node Teile übersprungen) |
| `tests/katalog-import.php` | Katalog-Import: Format (kein JSON, falsche Art, Format 2, leer, doppelter Schlüssel, unbekannte Art, Stecker ohne Beschreibung, neuer Stecker ohne Werte, Auswahl, Spannung …), neu → übernommen → schon da, keine Messpunkte am Typ (auch im Verteiler-Abgleich), bearbeitbar, „hier anders“ nur mit Auswahl und nur im Stand der Vorschau überschrieben, Typwechsel mit Eigenschaften, Tank-Typ (nur Eigenschaften, feste elektrische Werte, Anschlüsse in der Datei abgelehnt, gelernter Inhalt je mm → „hier anders“), Rechte, alles oder nichts, Vorschau ablegen, Kommandozeile und jedes Paket unter `resources/kataloge` (je eigene leere Instanz) |
| `tests/verteiler-abgleich.php` | Verteiler-Abgleich: Standard-Verteiler und Inventar hochladen/übernehmen, hier/dort/beide geändert, Shelly-Tausch im Einsatz, Rechte, Entfernen; Update beginnt beim Holen oder beim Push (Vermerk beim nächsten „Repository abfragen“); Tanksensor mit Sensordaten und Tank-Typ ohne Anschlüsse, Fingerabdruck vorhandener Stücke unverändert; `verteiler/inventar` als Ordner-Verweis und eine Typ-Datei als Datei-Verweis auf Kanarienvögel in `storage/`: Hochladen, Übernehmen und Entfernen abgelehnt, nichts angefasst, nichts gepusht; Ordner-Verweis in der Arbeitskopie weder gelesen noch beschrieben, das nächste Holen setzt ihn zurück |
| `tests/aufbau.php` | Aufbau: Plan festhalten, bestätigen/geändert/entfallen/zusätzlich, fertig, beenden, Abgleich (Versatz, Längen, Material, Last); Schlauch als Posten, Last ohne Tanks |
| `tests/bereiche.php` | Bereiche: Summe ohne Doppelzählung, Live/Verlauf, Aufräumen; Materialliste und Kabelstückelung; Tank zählt nicht und ist nicht wählbar, Material mit Schläuchen und Kupplungen |
| `tests/georeferenz.php` | Plan ↔ Breite/Länge, Koordinaten lesen, Längen aus der Georeferenz, strom.json |
| `tests/modbus.php` | Modbus TCP, Victron-Profil, Line Protocol |
| `tests/mqtt.php` | MQTT-Client, Shelly schalten (Gen1/Gen2, Antwort im Baum bei eigenem Zugang, falsches dst), Sammler → MQTT (eigener Mosquitto) |
| `tests/mqtt-helfer.php` | Helfer `deploy/mqtt-zugaenge.sh`: Namensregeln, Soll-Datei prüfen und ablehnen, Block in der Kennwortdatei, wirksame ACL, Rechte am Broker mit der echten ACL; mit gesperrtem Gen1-Gerät `topic deny shellies/command` – der gemeinsame Zugang erreicht das Gerät nicht mehr über den Rundruf (weder schreiben noch lesen), Node-RED schon, `announce` je Gerät kommt an, Gen1 wieder frei → Rundruf wieder offen (eigener Mosquitto) |
| `tests/mqtt-zugaenge.php` | Eigene MQTT-Zugänge von App bis Broker: Gen2 einrichten/übertragen/prüfen/Neustart/neues Kennwort/entfernen, Gen1, Sperre des gemeinsamen Zugangs, Kontoprobe gegen falsche ACLs, keine Geheimnisse (eigener Mosquitto, Shelly-Attrappen) |
| `tests/influx.php` | InfluxQL: Livewerte, Verläufe, Datenfenster, Energie je Tag; Tank: letzter Rohwert ab Einbau mit exakter Zeit, Sensorfehler, Lebenszeichen, Median, Analogkanäle bis 7 Tage, Gefälle, Verbrauch je Tag bei seltenen Meldungen (eigene InfluxDB 1.x) |
| `tests/cloud.php` | ComAp WebSupervisor, Victron VRM (Http-Attrappe) |
| `tests/schnittstelle.php` | API Seite A: Token, 401/403/404/405/413 nur JSON, keine Sitzung, Zuschnitt (nur freigegebene Standorte, nur fest, keine Messpunkte/Dosen/Racks/APs), Plandatei-Whitelist, Stand, Zugänge anlegen/anzeigen/widerrufen, keine Bindung an Kundenzugänge (per SQL gebunden: endet mit dem Kunden), keine Geheimnisse; „Anzeigen“ für Konten „nur über SSO“ nur nach Bestätigung über den Anmeldedienst (eigener PHP-Server) |
| `tests/schnittstelle-vorschlaege.php` | Vorschläge: Prüfungen beim Eingang (auch Anlage ohne Anschlüsse/Schutzorgane/Namen, Leitung ohne Punkte), MasterAdmin-Form, Unterschiede, annehmen nur mit Rechten, Namen/Schaltsperre/Messpunkte bleiben, Georeferenz bleibt, wenn sie fehlt, ablehnen nur mit „bearbeiten“, sehen nur mit dem ganzen Standort; direkt schreiben mit `plaene.schreiben` (passender Stand, 409, `erzwingen`, 422 Steckertypen, Schreibsperre erst beim Schreiben bemerkt → `wartung`); unbenutzte Steckertypen nie im Katalog, mobil Gewordenes bleibt mobil, Kabelenden an Anlagen, die nicht mitkommen, bleiben; ersetzte/direkte Zeilen nur mit Eckdaten, 60 Sendungen je Stunde; gleichzeitig entscheiden und schreiben (zweiter Prozess); Schlüssel älterer Fassungen (`-` am Ende, `-2` hinter 64 Zeichen) (eigener PHP-Server) |
| `tests/schnittstelle-partner.php` | Andere Instanzen: eintragen, Zustände, übernehmen/zuordnen/verknüpfen/lösen nur am Standort (Zuordnen nur mit „bearbeiten“, mit Rückfrage), nichts wandert zwischen Standorten, zurückschicken und annehmen, hochladen (409, `erzwingen` → „dort geändert“), Befugnisse vor dem Senden frisch, eigene Einträge nie doppelt (Schlüssel aus der Antwort, `gesendet_als`, freie Schlüssel), dort Gelöschtes kommt nicht zurück, nur hier Vorhandenes hält „hier geändert“, mobil Gemachtes bleibt mobil, fester Tank (Inhalt je mm nur für einen neuen, ohne Herkunft, gelernt auf einer Seite kein Zustand und kein „geändert“ im Vorschlag, Annehmen lässt den von A), lange Schlüssel PM ↔ PM und von MasterAdmin, Verbindungsfehler, MasterAdmin-Format, Plan über 30 MiB beim Zurückschicken ausgelassen statt alles abgelehnt – ohne Maßstab und Georeferenz, danach nicht „aktuell“ (PM ↔ PM und MasterAdmin, direkt übernommen), Frist der ganzen Anfrage für Anfragen dorthin (zweite Instanz, Attrappe) |
| `tests/berichte.php` | Übergabeberichte: Vorbelegung aus Plan/Schutzorganen/Kabeln/Inventar (Messwerte nie), Grenzwerte (Zs/Ik, R_iso inkl. 250 V, RCD, „gemessen an“ nur am selben RCD, TT/IT ohne RCD, TN-C, Erdung inkl. IT), Speichern (Version/409, verlorene Antwort → 200, 422, IDs nur vom Server, kein Gleitkomma), Abschluss-Prüfung (Prüfdatum, Kurzliste, „ja“/„mängelfrei“ trotz Mangel, Erdung), Unterschrift (nur Striche aus ganzen Zahlen, Grenzen Punkte/Bytes, Name passt zum Konto), Einfrieren/Nummer/Siegel, Schreibsperre eines zweiten Prozesses, Zurückziehen, Kunde und ohne Kunde (gesehener Stand), Kundenmodus (nicht über die Sperre eines anderen Kontos am Gerät, über eine abgelaufene oder die eines gelöschten Kontos schon) und Kennwort, Integrität (Status per SQL, PDF-Siegel, Erzeuger), PDF beim Abschluss (gespeichert, Neuerzeugung byte-gleich, ein Byte geändert → Befund), Korrektur, Kunde aus früherem Bericht, Sichtbarkeit mit Datenfenster (auch nach Löschen des Standorts), Löschen/Verwerfen, Lösch-Abläufe, Aufbewahrung (ab Abschluss, ganze Nummern, Nummer nie zweimal), Kommandozeile (`berichte:pruefen`/`pdf`/`aufbewahrung`) und Wartungs-Hinweise, Datenschutz; Kundenbereich: nie über `sichtbar()` für Kundenzugänge, Zuordnen an Kunden (Recht, Rückfrage bei anderem Namen, alle Fassungen, nicht verworfen), Eventworx-Kunden-Kennung nur aus der Merkliste, Korrektur übernimmt den Kunden, Kunde gelöscht → Bericht bleibt, `namePasst`; Entsperren für Konten „nur über SSO“ nie mit Kennwort, nur über den Anmeldedienst (`Kundenmodus::erlaubt()` mit Bestätigen und Rückruf) |
| `tests/bericht-pdf.php` | PDF der Übergabeberichte: eigener Schreiber, Schrift als Teilmenge (Prüfsumme, Glyphen, kein .notdef), Layout mit poppler (A4, Schriften eingebettet, Texte, Tabellenkopf je Seite, keine Bilder, Fuß „Blatt x von y“), zweimal erzeugt gleiche Bytes, Vorschau, ohne Kunde, Korrektur, 300 Kreise (ohne poppler übersprungen) |
| `tests/eventworx.php` | Eventworx nur lesend: Adresse, Token an den Host gebunden, Pfadliste (auch `file/delete/…`, `file/download` nie hinaus), Anfragen, Aufbereitung ohne Beträge/Notizen, Kunden-Kennung nur als ObjectId, Fehlerantworten, Nachlesen bei `data:null`, Drossel, Dateien am Vorgang (Filter, nur Benutzer-PDFs, fremde Datei verwirft die Liste, Download nur aus der Liste desselben Laufs, 10 MB, HTML statt PDF), Vorgänge mit Kunden verknüpfen (Kennung, Rückfrage, je nur einmal, lösen), Lese-Probe (eigene Pfadliste, kein Download, Budget, Ausgabe ohne Inhalte), Demo, keine Geheimnisse in Log/Audit/Meldungen (Http-Attrappe) |
| `tests/kundenbereich.php` | Kundenbereich: Kundenzugänge ohne Rechte (auch mit Rolle per SQL), Tor über alle Routen aus `public/index.php` (GET/POST, auch JSON, danach nichts geändert; die Tank-Routen darunter, dazu mit echten IDs eines festen und eines mobilen Tanks, Karte, Schlauch und Tanksensor – keine Antwort nennt einen Tank, an den Tanks nichts geändert), keine Tanks (nicht unter den festen Anschlüssen, `Zugriff` deckt für Kundenzugänge keinen Tank – für die Rolle „kunde“ wie bisher –, nicht auf den Seiten, `tank:liste --als=<Kundenzugang>` abgelehnt), nur Freigaben am Kunden (nur Standort, mit Ende und Datenzeitraum, nie bearbeiten/schalten), Überschneidung, mehrere Veranstalter je Gelände, Locations/Etagen/Pläne (Kopfzeilen, Bytes, Vorschau nur aus Kachel oder kleinem Plan), Protokolle nur über `kunde_id` (Fassungen, PDF-Kopfzeilen und Prüfsumme, Abrufe in drei Spuren, auch die Leseansicht), gleiche 404 für Fremdes, Einrichtungslink (nur HMAC, Ablauf, einmal), vorläufiges Kennwort, Zugänge/Links/Kennwörter und Wieder-Öffnen nur mit „Alle Standorte …“ und den Protokollen des Kunden (Kommandozeile darf), Einmal-Anzeige mit `no-store`, Kundenmodus des Prüfers sperrt einen danach angemeldeten Kundenzugang nicht (Kundenzugänge nie im Kundenmodus, auch nicht mit einer Sperre auf das eigene Konto), Kontowechsel im selben Browser (Anmeldeseite ohne Meldung des abgelaufenen Kontos, Kundenzugang und anderes Konto ohne dessen Eingaben, dasselbe Konto behält sie), Kunde beendet (auch laufende Sitzungen), Löschen von Kunde und Standort, Benutzerverwaltung ohne Kundenzugänge, Kundenverwaltung (Rechte, CSRF, fremde IDs), Fehlerseiten im Kundenlayout, Datenschutz; Anmeldung über SSO: lokale Kundenzugänge nie, Zugänge aus SSO ohne Link und Kennwort, ihr Name dort gesperrt (eigener PHP-Server) |
| `tests/pruefnachweise.php` | Prüfnachweise (DGUV V3): Upload nur PDF (Größe, auch schon im Browser, Name, Dubletten), Angaben (Daten, Ergebnis, Location und Vorgang nur des Kunden, Inventar nur mit Recht, kein Tanksensor – weder zur Auswahl noch angenommen), Sichtbarkeit (Entwurf/zurückgezogen/freigegeben, unversehrte Datei), Gültigkeit in der Mietzeit (Zeitumstellung, Mitternacht), Aufbewahrung und Kommandozeile, Integrität (beschädigte Datei heilt durch dieselben Bytes; Nachweis/Vorgang ohne Kunde ist Befund), Inventar gelöscht, IDOR, Übernahme aus Eventworx-Vorgängen (Merkliste ∩ frische Liste, höchstens fünf, nie doppelt, Herkunft ohne Inhalt; Kunde gelöscht oder Vorgang gelöst während des Wartens → nichts geschrieben, auch beim Verknüpfen; Schreibsperre eines Updates während des Wartens → ab da nichts mehr, mit `wartung`; die Dateiliste wartet höchstens so lange, wie ihr Kommentar sagt, und kürzer als `ANFRAGEN_WARTEN`; Datenbankfehler ≠ „schon übernommen“; Http-Attrappe und Demo über HTTP) (eigener PHP-Server) |
| `tests/sso.php` | SSO (OIDC): ID-Token (RS256/ES256, `iss`/`aud`/`exp`/`iat`/`nonce`, `alg`-Verwechslung, Schlüsselwechsel, auch RSA → EC: Discovery gedrosselt neu), Discovery und Secret-Probe, Einstellungen (Secret an die Verbindung gebunden, Marke, neue Verbindung nur mit den Rechten aller Zuordnungen), Start und Rückruf (`state` einmalig ohne Audit, Bremse, SSO aus/neue Marke/„Verwaltung per SSO“ aus während des Wartens → nichts geschrieben), Verknüpfung nur über `iss`+`sub` (auch Groß/Klein), Anmeldeanfragen, automatische Konten, Prüfkette, Gruppen → Rollen/Kunden (dieselbe Gruppe in anderer Schreibweise), Sitzung, nur SSO/Notzugang (der letzte auch nicht auf Termin), Step-up (MFA-Pflicht, Vermerk fürs Abmelden auch nach einem gescheiterten), `system.update` zählt als Verwaltung, keine Geheimnisse, Kommandozeile (`sso:*`, `pruefen`, `user:password`; Hinweise darauf in Seite und Meldungen mit `runuser`), Wartung, `verbinden_ueber`/`ca_datei` mit echtem curl gegen `openssl s_server`; während eines Updates (Schreibsperre: Rückruf an der Tür und nach dem Warten – nichts geschrieben, Code nicht eingelöst, kein Konto, keine Anfrage, kein Fehlschlag, nichts verknüpft, Kundenmodus ohne Fehlversuch, Einschalten bleibt aus), Migration 017 nach der Sicherung auf einer Datenbank ohne 017 (018 angewandt), Zurückrollen mit `db_zuruecklegen`, noch einmal migrieren, das Secret übersteht Update und Zurückrollen (eigene Instanz, OIDC-Attrappe) |
| `tests/sso-web.php` | SSO im Browser-Ablauf: Anmeldeseite, Anmeldung, `state` einmalig, Login-CSRF, HEAD verbraucht nichts (Start, Rückruf, Verbinden, Bestätigen, `/abgemeldet`), neue Sitzung, Abmelden mit `end_session`, Verbinden (erst das Kennwort, einmal, Bremse; danach Abmelden wie SSO), Anfrage, Einstellungsseite (Rechte, CSRF, kein Secret, Auswahl ohne Vorwahl), nur SSO (Lösen und Kennwort im Profil abgelehnt), Step-up für Kundenmodus und Token, Kundenzugang über eine Kundengruppe, während eines Updates (Start, Verbinden und Bestätigen mit Hinweis, Rückruf an der Tür und während eines trägen Anbieters – angemeldet beim Update, nichts geschrieben, Vorgang verbraucht, Abmelden danach mit `prompt=login` –, Kennwort-Anmeldung, Abmelden und `/abgemeldet` gehen, `/system/sso` 503 bzw. Hinweis) (eigene Instanz, zwei PHP-Server) |
| `tests/web.php` | alle Seiten ohne PHP-Meldung (auch im Fehlerprotokoll des Servers; auch die der Geräteprüfung – Übersicht, Listen mit Filtern, Formulare, Import, Export, Prüfabläufe, Prüflinge und Prüfungen der Demo über Eigenschaften gewählt –, dazu das PDF eines Prüfberichts mit den gespeicherten Bytes und der Link der Übersicht auf alle offenen Prüfungen mit `status=offen`, der nur offene liefert), 403/404 als eigene Seite ohne Navigation, CSRF (mit `Accept: application/json` 419/401 als JSON), Schalt-Ablehnung, Weiterleitungen, Sitzung nach Kennwortwechsel, Benutzerverwaltung ohne Administration (auch keine Rolle mit Freigabe „alles + schalten“ für sich oder andere, kein Kennwort für ein Konto mit einer solchen Rolle; eine Rolle der Administration bleibt beim Speichern des eigenen Kontos), Ändern nur mit „bearbeiten“, Seiten der Schnittstelle (Zugänge, andere Instanzen, Vorschläge; Standortnamen in der Liste der Zugänge nur, wo man hinsieht; CSRF bei Anlegen und Löschen) und `/api` ohne Sitzung, Übergabeberichte (Seiten, PDF mit Kopfzeilen/Vorschau/gespeicherten Bytes, Autosave 200/409/422/423, Eventworx-Demo, Firma-Karte, Prüfer-Qualifikation, Rechte von Kunde und Planer, Unterschreiben, Kundenmodus-Sperre auch für unbekannte/verbotene Adressen, die Seiten der Schnittstelle, Tankseite, Tank-CSV, Verlauf/Tage/Live (423), Peilung und Betankung und Abmelden – `/api` bleibt davon unberührt –, Sperre am Gerät nach neuer Anmeldung, nicht für ein anderes Konto im selben Browser (nach Kennwort-Rücksetzung: kein Ausweg, kein Ereignis, Abmelden geht; auch mit „bearbeiten“ kein eigener Kundenmodus über die fremde Sperre, auch mit nur dem Gerätecookie – Sperre und Cookie bleiben), mit vorläufigem Kennwort kein Kreis über `/profil`, mit abgelaufener Rolle nur das Entsperren statt 403 und danach keine 403, Bremse statt Abmelden nach fünf Fehlversuchen, Entsperren, ohne Kunde, Korrektur, kein Tank zur Wahl und Anlegen mit Tank abgelehnt), Katalog-Import (Seite, Vorschau nach Upload mit vorausgewählten neuen Typen, Übernehmen mit CSRF, Stand der Vorschau, nichts übernommen → Warnung und Vorschau bleibt, ohne Stecker-Recht, Zahlen ungerundet im Formular, 403, kein JSON), Tanks (Formular ohne elektrische Felder, kein Artwechsel, kein Kabel und kein Anschluss am Tank, Schlauch auf der Karte, Tanksensor anlegen und einbauen, Tankseite mit Vor Ort, Verlauf, Tagen, Protokoll, Kennlinie und Versorgung, Verlauf/Tage/CSV mit 403/404, Peilung und Betankung nur mit CSRF und *bearbeiten*, nach dem Ende des Datenfensters 403, Kunde ohne Knöpfe auch im Kartenpanel, Messpunkt des Tanksensors nicht löschbar, Schlauch nur mit *bearbeiten* an beiden Enden, Leitungsenden nur auf der eigenen Etage, Tank-Grenzen in den Einstellungen), Kundenverwaltung (jeder Reiter, Karte „Kundenbereich“ am Bericht, Einstellungen, Plandatei des Personals mit Kopfzeilen), Kacheln (Stand in der Adresse der Karte, ein Jahr im Browser ohne `Pragma`/`Expires` und ohne neues Sitzungscookie, ohne Stand `no-cache`, unbekannter Stand 404 mit `no-store`, nach dem Neurechnen der alte Stand mit den alten Bytes bis `GNADE`, danach 404, Standort nicht sichtbar 403), Plan neu rechnen (Knopf nur mit *bearbeiten*, nicht bei SVG und nicht für eine geteilte Pyramide eines nur sichtbaren Standorts, Zeile auf der Standortseite; ohne Token 419, ohne „Grundrisse verwalten“ 403, nur sehen 403, fremd 404, geteilt 403, falsche Qualität und SVG 422, kein Auftrag; mit *bearbeiten* 200, ein Auftrag bei zwei Klicks, Audit ohne Pfade; Status JSON mit `no-store`, ohne Pfade, Plandatei und Namen, fremd 403, unbekannt 404, abgemeldet 401; danach nennen Karte und Livewerte den neuen Stand; Bild ohne Kacheln: „Plan aufbereiten“ in der Karte, „Aufbereiten“ auf der Standortseite, 200; Formular ohne Recht: Meldung und Rücksprung; im Kundenmodus Status und Knopf 423 bzw. zur Kundenseite, kein Auftrag), Grundriss-Upload bis 100 MB (Formular mit Grenze, Prüfung im Browser per Node, knapp 100 MiB angenommen, ein Byte mehr mit Grenze abgelehnt, über `post_max_size` die Größe statt „Token abgelaufen“), Anmeldung über SSO (aus: Start, Rückruf und HEAD führen zu `/login`; Verbinden und Bestätigen nur von hier und nur angemeldet; `/abgemeldet`; „Abgemeldet.“; `/system/sso` nur mit `sso.manage`) (eigener PHP-Server mit den Grenzen des Pools) |
| `tests/sitzung.php` | Sitzungssperre: Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche), die des Tanks (live, Verlauf, Tage, Peilung – Doppelklick auf „Peilung“ trägt einmal ein, Meldung des zweiten) und Eventworx-Suche/-Vorgang im Bericht geben die Sitzung frei – Speichern, Bericht-Autosave und Seiten kommen bei träger InfluxDB bzw. trägem Eventworx durch, der geladene Vorgang bleibt vorgemerkt (`nachtragen()`), nach dem Abmelden 401 ohne Kundendaten; Seite einer anderen Instanz und „Prüfen“ halten sie nicht, während sie auf dort warten (`ohneSperre()`), Meldungen danach und aus einem zweiten Reiter kommen an; Doppelklick auf „Übernehmen“ legt einen Standort an (Sperre ohne Warten); Kennwortwechsel oder Abmelden im zweiten Reiter, während „Prüfen“ wartet: angemeldet bzw. abgemeldet, keine neue leere Sitzung; kein zweites Sitzungscookie beim Wiederöffnen; ebenso halten sie nicht: Dashboard (InfluxDB beim Laden), Eventworx „Verbindung testen“, Einstellungen „Prüfen“, VRM „Jetzt abfragen“, ComAp „Einheiten abrufen“, Schalten und MQTT-Zugänge (Übertragen, Übersicht) bei stummem Broker, „Repository abfragen“ im Location-Abgleich – Meldungen danach kommen an; Doppelklick auf „Übernehmen“ im Location-Abgleich und „Hochladen“, während der Verteiler-Abgleich das Repository abfragt (und umgekehrt), hören sofort mit Meldung auf (Sperre der Arbeitskopie), danach ist nichts gesperrt; Formulare mit `data-laeuft` (abgeschickt wie von app.js, mit `X-Requested-With`) enden mit Meldung und Weiterleitung statt JSON (MQTT-Zugänge, Verteiler-Abgleich), „Neues Kennwort“ trägt `data-laeuft-seite`; Kundenmodus, gestartet während das Dashboard auf die InfluxDB wartet: das Dashboard führt danach zur Kundenseite, Live 423; ebenso die CSV-Downloads (Messwerte, Bereich, Tank, Tank je Tag) ohne Datei und Audit, keine Meldung von „Prüfen“ und keine von vor dem Start auf der Kundenseite; app.js und live.js ohne Browser (Node): fetch ohne `X-Requested-With`, `data-laeuft-seite` schickt der Browser ab, zweiter Klick auf einen Link mit `data-laeuft` abgefangen, Strg-/Umschalt-Klick auf ein Formular sperrt nichts, abgebrochenes Navigieren (`navigateerror`) gibt Link, Formular und Neuladen wieder frei, eine Datei über `data-max-bytes` hält das Formular an, bevor `data-laeuft` sperrt, live.js lädt bei 423 „kundenmodus“ neu; Kundenverwaltung: Eventworx-Suche, Verknüpfen und Dateiliste halten die Sitzung nicht, Treffer und Liste sind danach gemerkt, wer sich währenddessen abmeldet, bekommt nichts; beginnt währenddessen der Kundenmodus (Suche, Dateiliste, Übernehmen), kommen weder Treffer noch Dateiliste noch Meldungen an, nichts doppelt übernommen; ihre Formulare tragen `data-laeuft-seite`; Grundriss-Upload hält sie nicht, während er Kacheln rechnet (wie von app.js; ein zweiter Reiter legt dabei eine Etage an, beide Meldungen kommen an, kein zweites Sitzungscookie); Plan neu rechnen mit trägem `pdftoppm`: der Knopf ist sofort zurück, während gerechnet wird kommen Karte und Status gleich durch, danach „fertig“ mit neuem Stand, Status `no-store` ohne Sitzungscookie; der Status gibt die Sitzung frei, bevor er liest (hängt das Lesen des Auftrags – FIFO –, kommt eine Seite trotzdem); plan-neu.js ohne Browser (Node): nie zwei Abfragen zugleich, die nächste 2,5 s nach der Antwort, Schluss bei „fertig“/„fehler“/403, 423 → neu laden, 401 → Schluss, verborgener Reiter fragt nicht, Text aus Daten bleibt Text; Anmeldung über SSO: Start, Rückruf und Bestätigen, „Prüfen“ und „Einschalten“ halten die Sitzung nicht, während der Anmeldedienst trödelt; eine Kennwort-Anmeldung im zweiten Reiter währenddessen wird nicht überschrieben (eigene Instanz, Attrappen für InfluxDB, Eventworx über `eventworx.basis`, eine andere Instanz, VRM/ComAp über `auto_prepend_file`, ein stummer Broker, ein träges `git` und `pdftoppm` vorn im PATH gegen ein `file://`-Repository und eine träge OIDC-Attrappe) |
| `tests/sicherheit.php` | Rechte vergeben, Anmelde-Bremse ohne Kontoerkennung, Geräte zuordnen, Kanal-Freigaben beim Schalten, Schlüssel aus dem Repository, Line Protocol, CSV (auch Tankverlauf, Protokoll mit Lieferschein, Tage), Adressen, keine Umleitung mit Token (curl und Streams), app.key, PDF-Werkzeuge, Zugänge der Schnittstelle vergeben, anzeigen nur mit den Rechten dahinter, Bindung lösen nur mit Verwaltungsrecht, verfälschtes Token, Meldungen anderer Instanzen maskiert, Instanzseite ohne Etagen eines verborgenen Standorts, Ansichten ohne Inline-JS, Bericht-JS und „Plan neu rechnen“ (plan-neu.js, sein Abschnitt in karte.js) ohne HTML aus Daten, nur mit Zeitgrenze, Abmelden ohne Rückschreiben des Entwurfs, andere Tabs im Kundenmodus, Unterschrift ohne Zeit/Druck, Handballen und Bytegrenze der Fläche (Node), als SVG nur Zahlen; Tank: Fehler der InfluxDB nur für die Messtechnik, Kennlinie von Hand nur endliche Zahlen, `tank:liste` nur mit `--als`; Kundenbereich: Rechte `kunden.*` nur vergeben, wer sie hat, Freigaben am Kunden nur Standort/sehen, Kundenseiten ohne Inline-JS und ohne `Eventworx::`, `Content-Disposition` nur ASCII, Datenschutztext maskiert, Eventworx nur über einen Netzweg mit GET und nur ohne Sitzungssperre; SSO: `sso.manage` vergibt nur, wer es hat, `Settings::OVERRIDABLE` ohne `sso.*` und `app.url`, Anmelde- und SSO-Ansichten ohne Inline-JS, Texte des Anmeldedienstes maskiert; Geräteprüfung: Rechte `geraetepruefung.*` vergibt nur, wer sie hat, Ansichten ohne Inline-Skript, `on…=` und `javascript:`, `geraetepruefung.js` ohne HTML aus Daten, ohne Zeit und Druck, kein `fetch(` (nur `PMNetz` mit Zeitgrenze); Konten und Anmeldung (Prüfung 2026-11): eine Rolle mit Freigabe „alles + schalten“ vergibt der Verwalter nicht (sich, neuem oder anderem Konto, Anmeldeanfrage, Gruppe), eine bestehende am eigenen Konto bleibt (nicht verlängert), Konten mit solchen Freigaben (am Konto, über eine – auch künftige – Rolle) verwaltet er nicht; befristeter Verwalter: Enden höchstens bis zum eigenen (aus Konto oder Rolle, Rolle aus SSO-Gruppe unbefristet, neue Rolle endet mit dem Konto), das eigene Konto und eigene Rollen nicht verlängern, keine neuen Rechte an eine Rolle, die jemand länger hat (ohne Ende, über das Konto, aus einer SSO-Gruppe; ohne Zuweisung und bis vor seinem Ende schon), kein Konto verwalten, das länger Benutzer verwaltet (auch über eine erst später gültige Rolle), Administration ungedeckelt; überlanger Name und kein UTF-8 bei der Anmeldung: dieselbe Meldung, im Audit höchstens 64 Zeichen; gebremste Adresse einmal je Fenster vermerkt; Platzhalter-Hash mit den Kosten von `PASSWORD_DEFAULT`, gesperrtes Konto antwortet nicht schneller; Kennwort ändern und Token anzeigen nach fünf Fehlversuchen ohne Prüfung abgelehnt (je Zweck, Konto bleibt offen) |
| `tests/tanks.php` | Tanks und Tanksensoren: Art ohne Strom, Rechnung Rohwert → mm → l (4–20 mA an Bürde und Wandler, 0–10 V, Ultraschall, Zylinder), Fassungen (Einsatz, Shelly-Tausch, Sensor – nur Hinweis oder Meldeschritt behält den Anker –, Tank, Peilung, Betankung ohne „geprüft“, von Hand, rückwirkend, zurück, nie geändert), Live ab Einbau (auch nach 7 Tagen ohne Wert), Stufen, Peilung und Betankung (auch über 60 min), Versorgung und Restlaufzeit, kein Tank im Übergabebericht, Verbrauch je Tag (ohne Sprung bei neuer Fassung, Betankung nach langer Ruhe), Rechte (Eintragen nur im Datenfenster), Sperre je Tank, Kommandozeile (`tank:liste`, `tank:rechnen`) und Demo-Gelände (eigene Instanz) |
| `tests/geheimnisse.php` | Zugangsdaten gelten für ihr Ziel (`Settings::BINDUNG`): Wert aus config.php nur für das Ziel aus config.php (Groß/Klein, Port, Schrägstrich egal; anderes Schema, Host, Port, Benutzer nicht), eingetragene nur für das Ziel beim Setzen (Influx-Kennwort auch mit dem Benutzer), Bindung weg mit dem Geheimnis, ältere ohne Bindung bindet `bindungenNachholen()` einmal; Repository-Token eingetragen nur für seinen Server, aus `POWERMANAGEMENT_REPO_TOKEN` nur für den aus config.php und – Bestand mit der Adresse in der Oberfläche – den, an den `bindungenNachholen()` es einmal gebunden hat (nie neu, anderer Server: Anzeige `umgebung_fremd` und Hindernis); `Influx::adresseFehler()`; Fehlermeldung der InfluxDB ohne rohen Rumpf; HTTP mit nur „Systemeinstellungen ändern“ und Fängern statt Broker und InfluxDB: „Prüfen“ schickt die Geheimnisse ans echte Ziel, nach dem Umstellen auf einen Fänger trägt weder MQTT-CONNECT noch Influx-Anfrage eins (aus config.php; eingetragen – vom Formular mit Meldung entfernt, Audit nur Schlüssel –; an der Oberfläche vorbei umgestellt – Hinweis bei „Prüfen“), neu im selben Formular → gilt fürs neue Ziel, Adresse mit Zugangsdaten/„?“/anderem Schema speichert nichts (eigene Instanz, eigener PHP-Server) |
| `tests/geraetepruefung-fundament.php` | Geräteprüfung, Fundament: Migration 019 (Tabellen, Kundenfelder mit Vorgabe leer, eindeutige Indizes; MySQL-Form durch Lesen – `{{ENGINE}}`, indizierte Texte bis 190 Zeichen, keine Fremdschlüssel), Rechte (Gruppe „Geräteprüfung“, nicht „System“; Standardrollen und die INSERTs der Migration), `Messarten` (`zahl()` ohne float32-Reste, `dezimal()`, Anzeige mit Komma, `zeitLesen()` für alle Formate des Geräts in Europe/Berlin mit Sommer- und Winterzeit, `ortsdatum()`, `monatePlus()` am Monatsende, `norm()`, Grenzwerte, `ergebnisAbleiten()`, Texte), `BenningMuster` (sqlite_master Text für Text gleich `resources/benning/st755-schema.sql`, Seitengröße 1024, Journal delete, Kopf-Bytes 18/19 = 1, Werksdaten ohne Kundendaten, alle festen Fälle mit erwartetem Ergebnis, Zeiten in allen Formaten, gleicher Startwert = gleiche Bytes, `pruefungAnhaengen()`), `Pruefungen` (Schlüssel ohne Fragetext und ohne Kennung des Prüflings – Zeit, Prüfgerät, Ablauf, Werte und Antworten zählen –, `anlegen()` idempotent, Formfehler, Liste mit Filtern), `Prueflinge` (eindeutig je Besitzer ohne Groß/Klein, Kürzen, Inventar nur eigener Bestand, `faelligkeitNeu()`: bestanden → + Intervall, nicht bestanden → am Prüftag, ohne Prüfungen unverändert) (eigene Instanz) |
| `tests/geraetepruefung-import.php` | Geräteprüfung, Import/Vorlage/Export: Lesen (`BenningDatei`: Zählungen, Quelle mit sha256/DB-Version/Prüfgerät/Firmware, Zeiten in UTC – Winter, Sommer, alle Formate –, abgeleitetes Ergebnis je Fall, auch „Gerät sagt bestanden, ein Wert nicht“ mit Hinweis, Sichtprüfung, Grenzwerte ohne float32-Reste, Werte wie vom Gerät, Kennung getrimmt, leere Kennung übergangen, nie geprüft, außer Betrieb, nur Text/Wahrheitswerte/Listen/Ganzzahlen, Fingerabdruck stabil), Ablehnen (keine SQLite, leer, zu groß, WAL, beschädigt, Ansicht statt Tabelle, fehlende Tabelle und Spalte, zu viele Zeilen – echt und über `BenningDatei::$grenzen` –; berechnete Spalte auch in einer unbekannten Tabelle ohne Speicheranstieg, Funktions-DEFAULT, Ausdrucks- und Teilindex, CHECK (auch mit Kommentar vor der Klammer) – das Schema des Geräts bleibt lesbar; Texte gekürzt; fremder Trigger und unbekannte Tabelle → lesbar, aber keine Vorlage; keine Kopie bleibt in `storage/cache`), Vorlage (geleerte Tabellen ohne Zeilen und `sqlite_sequence`, Einstellungen per `quote()` und Schema Text für Text gleich, Seitengröße 1024, Journal delete, Kopf-Bytes 18/19 = 1, keine Namen, Kennungen, Anschriften im Byte-Strom, zweimal gebaut gleiche Bytes), Import (Vorschläge, Zuordnung, gleiche Kennung zweimal beim selben Kunden → ein Prüfling, neuer Kunde nur mit „Kunden pflegen“, Sperre gegen Doppelklick (Sperrdatei aus dem echten Pfad, alte fremde räumt die Wartung weg), Schreibsperre eines Updates, eine Transaktion, bei vorhandenen Kunden nur leere Felder, Beleg und Audit nur mit Zahlen, idempotent auch mit der Sicherung, am Gerät umbenannt → Prüfung „schon da“ beim bisherigen Prüfling mit Hinweis, „hier anders“ nur mit Haken und gesehenem Stand, Inventar-Vorschlag nie Tanksensor, dieselbe Vorlage nicht doppelt, Vorschau ablegen/verwerfen/aufräumen), Export (ohne Vorlage abgelehnt, Schema und Einstellungen gleich der Vorlage, Kunden mit `PM-EIGEN`/Kundennummer/`PM-K<id>`, ohne gültigen Prüfablauf, ohne Leitungsangaben bzw. mit Intervall 0 und mit einem Ablauf, der hier anders steht als in der Vorlage, übergangen, gezählt und gemeldet – kein Gerät mit 0 m/0 mm²/0 Leitern/Intervall 0 –, Name des Ablaufs genau wie `Pruefung_Name` (auch mit Leerzeichen am Ende), Hersteller angehängt, Fragen, Vorlage mit Funktions-DEFAULT → Meldung; Re-Import mit „am Gerät“ eingetragenen Prüfungen erkennt Kunden und Prüflinge, nur die neuen Prüfungen kommen; zweites Prüfgerät mit anderem Ablauf 1000001 → Hinweis, ohne Vorlage Prüfling ohne ihn; Kunde gelöscht → gemerkte Zuordnung weg), HTTP (Seiten ohne PHP-Meldung und Inline-JS, Dateifeld mit eigenem Rat statt dem für PDF – app.js unter Node –, Upload → Vorschau, ohne Token und abgeschnitten nichts, geänderte Zuordnung ohne Blick auf die Prüflinge nichts, Download mit Kopfzeilen, Prüfabläufe mit Norm und Zubehör, nur sehen → 403, Schreibsperre) (eigene Instanz, eigener PHP-Server, nur die erfundene Datenbank aus `BenningMuster`) |
| `tests/geraetepruefung-prueflinge.php` | Geräteprüfung, Prüflinge: `Prueflinge::speichern()` (Kennung Pflicht, höchstens 50 Zeichen ohne stilles Kürzen, je Besitzer eindeutig ohne Groß/Klein; Kunde muss es geben; Inventarstück nur beim eigenen Bestand, nie Tanksensor, nie zweimal, nur mit „Datenquellen ansehen“; nur ein gültiger Prüfablauf; Zahlen mit Komma, Intervall 0–600, mit Prüfablauf Länge/Querschnitt/Leiter > 0 und Intervall ≥ 1 Pflicht; Daten von Hand nur ohne Prüfungen; Besitzer wechseln nur ohne Prüfbericht; Audit nur IDs), Liste/Anzahl/Zahlen (Filter Besitzer, fällig, Betrieb, Suche mit „%“/„_“ wörtlich; nicht bestanden = überfällig; Fälligkeit mit Symbol und Wort), Löschen nur ohne Prüfungen, Inventar (Löschen lässt den Prüfling, Auswahl ohne Tanksensor), Kunden (Anschrift und Kontakt gesäubert, nie im Audit und in `KundenSicht`, Löschen mit Prüflingen abgelehnt), HTTP (Übersicht mit Importen nur als Zahlen über 0 mit deutschen Beschriftungen, Liste, Formular mit Vorbelegung aus Kunde und Inventar, Detail – letzte Prüfung von Hand ohne Ergebnis „Ergebnis nicht bekannt“ –, unverändert speichern ändert nichts, CSRF, Eingaben bleiben nach Fehler, fremde IDs 404, nur ansehen → kein Formular, Kundenzugang nie, Karte „DGUV V3“ am Inventar, nie am Tanksensor), Ansichten ohne Inline-JS, jede Zelle mit `data-label` (eigene Instanz, eigener PHP-Server) |
| `tests/geraetepruefung-berichte.php` | Geräteprüfung, Prüfberichte: Unterschreiben nur mit Recht, Qualifikation und vollem Namen im Konto, höchstens 25, Kennung, Name = Konto, Ort, Prüfart, Striche (422, nichts geschrieben), anderer Prüfer laut Gerät nur mit „Verantwortung übernehmen“; Nummer `GP-<Jahr>-<5 Stellen>` (Jahr aus dem Ortsdatum, Silvesternacht), Inhalt nur Text, `inhalt_hash`, Siegel über die Kette, PDF gespeichert und neu erzeugt byte-gleich, Kalibrierung und Zubehör aus „Prüfgeräte“ mit „laut Verwaltung, Stand …“, nach dem Prüftag geändert → Hinweis, nächste Prüfung; Idempotenz (dieselbe Kennung → Erfolg ohne neue Zeile, andere Striche oder anderes Konto → 409, dieselben Striche mit neuer Kennung → 422, auch aus einem Übergabebericht – und umgekehrt nimmt `BerichtService::strichePruefen` die Striche eines Prüfberichts nicht an), Sammelunterschrift über 30 Prüfungen in zwei Stücken (eine Unterschrift, Nummern ohne Lücke), gelöschte Nummer nie wieder; Korrektur (Grund, nur von „gültig“, Fassung 2 `-K1` mit dem jetzigen Stand, Vorgänger ersetzt; Neuversuch eines alten Durchgangs nach der Korrektur → keine Fassung mit der alten Unterschrift; per SQL gesetzte Korrektur oder geänderter Grund → Befund, nie eingefroren; unter einem anderen Schlüssel angefordert → nicht unterschreibbar, Befund „nicht prüfbar“, neu anforderbar); Prüfnachweis (bestandenes Kundengerät → Entwurf mit den Bytes des PDF – ohne `kunden.nachweise` keiner, Hinweis, nachholbar –, im Kundenbereich erst nach Freigabe, nicht bestanden nie, eigener Bestand nur von Hand, nie zweimal, nie an einen anderen Kunden, eingefrorene Felder, Korrektur zieht den Entwurf zurück, ein freigegebener bleibt mit Hinweis); Integrität (Inhalt, Name, Striche, Schlüssel, PDF, Erzeuger, Status, Kunde verändert → Befund), Kommandozeile (`geraetepruefung:pruefen` Exit 0/1 ohne Kundendaten, `--pdf`, `geraetepruefung:pdf` 0640, nie überschreiben, `--fassung`); HTTP (Liste und Detail ohne PHP-Meldung und Inline-JS, 404, Unterschreiben als JSON: ohne Token 419, ohne Recht 403, Schreibsperre 503 mit `wartung` und `Retry-After`, PDF mit Kopfzeilen und gespeicherten Bytes, Kundenzugang abgewiesen); `geraetepruefung.js` unter Node (Stücke, 503-Wiederholung, eine Kennung) (eigene Instanz, eigener PHP-Server) |
| `tests/geraetepruefung-pdf.php` | PDF der Prüfberichte (`PruefberichtPdf`): zweimal erzeugt dieselben Bytes (anderes `eingefroren_am` → andere), `/ID` aus dem `inhalt_hash`, CreationDate, Dateiname ohne Kundendaten; mit poppler A4 auf jeder Seite, Titel ohne Kundendaten, zwei eingebettete Teilschriften, keine Bilder, Texte (Kopf, Firma – der Name nicht doppelt, wenn die Anschrift mit ihm beginnt –, Auftraggeber, Prüfling, Norm, Messwerte mit Komma, Grenzwerte, Ergebnis, nächste Prüfung, Unterschrift, Kalibrierung und Zubehör „laut Verwaltung, Stand …“), Fuß „Blatt x von y“ mit Kurz-Prüfsumme und Tabellenkopf der Messungen auf jeder Seite (80 Schritte), kein .notdef; nicht bestanden, Korrektur, Verantwortung übernommen, ohne Messschritte (eigene Instanz, ohne poppler-utils übersprungen) |
| `tests/nodered-funktionen.mjs` | Node-RED-Funktionen (auch Analogeingänge: Voltmeter, Analog-Input, Uni-ADC mit `sensorfehler`; Gen1 fragt `announce` je Gerät über `shellies/<id>/command` beim ersten Lebenszeichen und nach sechs Stunden, nichts im Flow schickt an `shellies/command`), `flows.json` passt zu den Quellen |
| `tests/installation-debian.sh` | `deploy/install-debian.sh` auf frischem Debian 13 mit systemd (Docker): Erstinstallation, zweiter Lauf (Kennwörter, app.key bleiben), Update mit neuem Ordner in `storage/`; Rechte (Code root, `storage/` www-data), Dienste, Anmeldung, Broker, `bin/power pruefen`; Grenzen für Grundrisse, wie PHP-FPM (`-tt`) und nginx (413 je Adresse, auch in Stücken; 70 KiB an `/login` und `/login/` → 413) sie anwenden, das Update hebt alte Grenzen (64M) an, danach knapp 100 MiB über beide hochgeladen und ein Byte mehr abgelehnt; nach dem Update rechnet `powermanagement-kacheln` fehlende Kacheln als www-data (Rest eines getöteten Baus weg); eine von Hand veränderte Site ohne den Block für Grundrisse bleibt, mit Hinweis |
| `tests/installation-debian.sh` (Update) | Phasen 3b–8 (3c Flows): Update ohne HOME und Terminal (systemd-run), über die Oberfläche mit Selbst-Update, kaputte Migration und Prüfung nach dem Neustart (vollständig zurück, auch Einheiten und Vorlagen), Abbruch und SIGKILL mit Wiederanlauf, Automatik mit Veranstaltungsschutz, Aufträge als App-Benutzer und untergeschobene Aufträge, `bin/power` als root abgelehnt; Node-RED-Flows (unverändert ersetzt, im Editor verändert behalten mit Hinweis und Zusammenfassung, `--flows-ersetzen`, beim Zurückrollen mit zurück); `--umstieg=<commit>`: einmaliger Umstieg von einer Fassung ohne Update-Funktion genau wie in der Anleitung (neue Flows und alle Migrationen, auch 016 und 018), danach das erste Update über die Oberfläche (eigener CI-Job „Umstieg“) |
| `tests/update-helfer.php` | Update-Helfer ohne root: Auftrag übernehmen und prüfen (Verweis, FIFO, Ordner, Größe, Zeichen, Kennung, Alter, Abstände, gescheiterte Stände, `update.conf` – unbekannte Zeilen und über 4 KiB schalten ab, „nein“ bleibt), argv und Umgebung, Sperre, Signale als TERM, Wiederanlauf (höchstens zweimal, dann aufgegeben), Nachlauf, `ist/wartung` mit Zeitlimit gelesen, Sammler nach einem vor der Marke abgebrochenen Update wieder gestartet, Pfad-Einheit nur geheilt, wenn `soll/` in Ordnung ist (nie im Nachlauf), Protokoll bereinigt und ohne Token, Stand aus git (Kandidaten auch von einem Seitenzweig aus, linear, lokal), Abgleich mit `UpdateService::auftragText()`; als root zusätzlich Code-Prüfung, setpriv und `soll/` ohne Schreibrecht des App-Benutzers (Attrappen `tests/hilfen/update-attrappe.sh`, `systemctl`, Temp-Repository) |
| `tests/update.php` | Update in der App: Parser der Helfer-Dateien, Zustände über die Zeit (auch die Lücke zwischen Übernahme des Auftrags und seinem Lauf, ein langes Update mit Puls ist kein stiller Dienst), installierter Stand ohne git-Prozess, Auftrag (Format, Sperrgründe, Hindernisse), Abzeichen, Audit idempotent, Automatik (Einstellungen, Fenster mit Zeitumstellung und über Mitternacht, Versatz, Karenz, Hindernisse, einmal je Tag, Zeitplan mit Dateien und Audit, nach einer langen Schreibsperre nicht „still“, eine Pause ist kein „verschoben“), Kommandozeile `update:*` (als root abgelehnt, auch wenn `storage/update` ein Verweis auf einen Ordner von root ist), HTTP (Seite, Rechte, CSRF, Schreibsperre 503/Meldung, Kundenmodus geht der Schreibsperre vor, Schnittstelle 503 mit Retry-After und Lesen geht, ein Körper über `post_max_size` meldet die Größe statt 503, Grundriss-Upload abgewiesen, Status-JSON ohne Sitzungssperre), laufende Änderungen beim Setzen der Schreibsperre (Anmeldung über `storage/update/.anfragen`, `wartung_an` aus `update-debian.sh` wartet auf eine Übernahme von einer trägen anderen Instanz und höchstens `ANFRAGEN_WARTEN`, die Übernahme fragt vor dem Schreiben noch einmal: kein Standort, kein Grundriss, „nichts übernommen“), `db_zuruecklegen` aus `update-debian.sh` (harter Verweis ohne alte WAL; ein zweites Mal nach Absturz rührt nichts an), `rechte_setzen` aus `update-debian.sh` (chown -R über `storage/` nur mit `fs.protected_hardlinks`, sonst Hinweis, Warnung `hardlinks` einmal und weiter mit den Ordnern; Stufe 1 beginnt auch ohne; `storage/` im Repository nur mit den bekannten `.gitkeep`-Ordnern aus `APP_ORDNER`; das Installationsskript übergibt ohne den Schutz nur, solange alles in `storage/` root gehört – auch eine fremde Datei unter einem `storage/` von root zählt), `vorlage_uebernehmen` aus beiden Skripten (von Hand verändert bleibt, die ganz übernommene `-neu`-Fassung gilt beim nächsten Update als ausgeliefert, ein Verweis als `-neu` nicht), Grundrisse während des Updates (`kacheln_anstossen` aus `update-debian.sh` erst nach der Schreibsperre, als App-Benutzer mit `plan:kacheln --nachholen` – das dieser Stand kennt –, nie zweimal, nie als root; nach jedem `wartung_aus` in `abbrechen`/`zurueckrollen` ebenso, nur wenn der Lauf die Sperre aufhob und nicht beim Herunterfahren; Wartung und php-fpm ebenso als App-Benutzer, `util-linux` (prlimit) in der Paketliste; „Plan neu rechnen“ während der Schreibsperre 503 bzw. Meldung ohne Auftrag, ein Auftrag von vorher „wartet“ ohne Prozess (auch keiner, der gleich wieder endet: nichts in `kacheln.log`); `kachelnBis()` vor, in und nach dem Fenster, Demo, `AUTOMATIK=nein`; eine Sicherung mitten im Kacheln des Uploads enthält Etage und Audit; `plan:kacheln` räumt den Rest eines getöteten Baus weg und schreibt nichts in die Datenbank; ein Upload bei schon stehender Sperre kachelt nicht selbst, sein Auftrag wartet, `--nachholen` rechnet ihn danach, ohne in die Datenbank zu schreiben; Automatik `verschoben:kacheln`, von Hand ohne Rückfrage), als root der Wächter in `bin/power` (lehnt ab, bevor etwas in `storage/` entsteht – auch `sso:*` samt Notausgang, `plan:kacheln --auftraege`/`--nachholen`/`--veraltet` und `wartung` –, `hilfe` ohne Datenbank), `update.js` ohne HTML aus Daten und 423 lädt neu, Berichte nehmen 503 als „gleich noch einmal“; Tank: Peilung, Betankung, Auswerten, Kennlinie, Steigung, Löschen, Einsetzen/Ausbauen und Kraftstoffschlauch während der Schreibsperre 503 bzw. Meldung, nichts eingetragen, lesen geht; eine Peilung, die auf eine träge InfluxDB wartet, ist angemeldet – `wartung_an` wartet, bis sie eingetragen hat; `tank.js` lädt nach 502–504 noch einmal (Node); Kundenbereich: Kundenzugänge lesen und laden während der Schreibsperre weiter (Abrufe zusätzlich in `app.log`), Kennwort und Einrichtungslink abgewiesen mit der Meldung des Kundenbereichs in dessen Layout (ohne Referer zurück zum Konto bzw. zum selben, weiter gültigen Link; JSON 503), das Tor geht der Sperre vor (Adressen des Personals: zum Kundenbereich bzw. 403, kein Auftrag), nie Abzeichen oder Seiten des Updates; Kundenverwaltung zurück auf die nächste Seite, die es als GET gibt; Verknüpfen und „Prüfnachweise aus Eventworx übernehmen“ mit trägem Eventworx und dem echten `wartung_an`: Verknüpfen schreibt vor der Sicherung, Übernehmen fragt noch einmal – nichts übernommen, Liste mit Auswahl und Angaben wieder da (eigene Instanz, Attrappen der InfluxDB und von Eventworx) |
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
kommen aus der Demo, übernommen wird nichts. Die Geräteprüfung nimmt es als
Administrator (Übersicht, Prüflinge, Import-Vorschau einer erfundenen
Datenbank aus `DemoSeed::geraeteDatenbank()` – gelesen, dann verworfen) und
als `technik` auf (Prüfungen, eine Prüfung); Blatt 1 eines Prüfberichts
über `bin/power geraetepruefung:pdf` und `pdftoppm`. Nur einzelne Teile:
`PM_TEILE=geraetepruefung` (Namen bei `teil()` im Skript). Chromium von
Playwright braucht mehr Adressraum, als `ulimit -v` für PHP lässt.

Für die Anmeldung über SSO (Anmeldeseite, *System → Anmeldung (SSO)*)
schaltet es die Demo-Verbindung zu Authentik vorübergehend ein –
Platzhalter-Secret, Discovery- und Schlüssel-Zwischenspeicher, eine
gelungene Prüfung, kein Netz, kein Klick – und startet dafür einen eigenen
PHP-Server mit `app.url` auf derselben Datenbank (`POWERMANAGEMENT_CONFIG`);
danach stehen alle `sso.*`-Einstellungen wieder wie vorher.

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
