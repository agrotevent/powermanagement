# Kundenbereich: Locations, Protokolle und Prüfnachweise für Kunden

Kunden – Veranstalter, Mieter eines Geländes, Auftraggeber – bekommen einen
eigenen Zugang und sehen darin nur, was ihnen gehört:

- ihre **Locations** im Zeitraum ihrer Veranstaltung: Anschrift, Pläne der
  Etagen und die festen Stromanschlüsse,
- ihre **Protokolle**: abgeschlossene Übergabeberichte mit Messprotokoll
  ([berichte.md](berichte.md)), als PDF so, wie sie bei der Übergabe
  gespeichert wurden,
- ihre **Prüfnachweise** (DGUV Vorschrift 3) für gemietete Verteiler, Kabel
  und Verlängerungen – von Hand hinterlegt oder aus einem
  Eventworx-Auftrag übernommen.

Der Kundenbereich ist **nur zum Lesen**. Kunden planen nichts, schalten
nichts und sehen keine Karte, keine Statistik und keine anderen Kunden.

<img src="bilder/kundenbereich-uebersicht-handy.png" alt="Kundenbereich am Telefon: Locations, Protokolle, Prüfnachweise" width="260"> <img src="bilder/kundenbereich-protokolle.png" alt="Ein Protokoll im Kundenbereich: PDF ansehen oder herunterladen, Prüfsumme, Kopf des Berichts" width="260">

Drei Dinge heißen ähnlich und sind verschieden:

| | was | wer, wo |
|---|---|---|
| **Kundenbereich** | eigener Zugang des Kunden, nur `/kundenbereich` | der Kunde, an seinem eigenen Gerät |
| **Kundenmodus** | das Gerät des Prüfers ist gesperrt, bis der Kunde den Übergabebericht unterschrieben hat ([berichte.md](berichte.md#kundenbestätigung-und-kundenmodus)) | der Kunde, am Tablet des Prüfers |
| Rolle **„Kunde / Veranstalter“** | ein gewöhnliches Konto mit Freigaben – der ältere Weg, siehe [Übergang](#übergang-konten-mit-der-rolle-kunde--veranstalter) | wie das Personal, mit eingeschränkten Rechten |

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Kunde** | Firma oder Verein, für den gearbeitet wird: Name, Kundennummer, aktiv, „Zugänge enden am“, Notizen (nur für das Personal). Keine Anschrift, kein Telefon – das steht im Bericht oder in Eventworx. |
| **Kundenzugang** | ein Konto mit Benutzername und Kennwort, das zu genau einem Kunden gehört. Ein Kunde kann mehrere haben (Produktionsleitung, Technik). Kundenzugänge haben **keine Rolle und keine Rechte** und stehen nicht in der Benutzerverwaltung. |
| **Freigabe am Kunden** | „dieser Kunde sieht diesen Standort von … bis …, mit Messdaten von … bis …“. Gilt für alle Zugänge des Kunden; ein Widerruf genügt. Nur Standorte, nie „bearbeiten“ oder „schalten“. |
| **Protokoll** | ein Übergabebericht, den das Personal dem Kunden ausdrücklich **zugeordnet** hat. |
| **Prüfnachweis** | das Prüfprotokoll eines Geräts nach DGUV V3 / DIN EN 50699, als PDF mit Angaben (geprüft am, nächste Prüfung, Ergebnis). Ein Übergabebericht prüft die **Anlage vor Ort**, ein Prüfnachweis das **einzelne Gerät** – das eine ersetzt das andere nicht. |

## Was Kunden sehen – und was nie

Für einen angemeldeten Kundenzugang eines Kunden K:

| Was | sichtbar genau dann, wenn | unabhängig von |
|---|---|---|
| der Zugang selbst | Zugang aktiv und in seinem Zeitraum, K aktiv und nicht über „Zugänge enden am“ | – |
| eine **Location** | K hat eine Freigabe für den Standort, und *jetzt* liegt in ihrem Gültigkeitszeitraum | Freigaben am Konto oder an Rollen, Freigaben für einzelne Anlagen |
| eine kommende Location | wie oben, aber die Freigabe beginnt erst – dann nur Name und „ab …“ | – |
| Etage und Plan | Location sichtbar, die Etage gehört zum Standort und hat einen Plan | – |
| ein fester Stromanschluss | Location sichtbar, elektrische Anlage dort mit Merkmal „Feststromanschluss“ – nie ein Tank, auch kein fester | mobile Anlagen |
| ein **Protokoll** | der Bericht ist K zugeordnet und abgeschlossen (oder durch eine Korrektur ersetzt) | Standort, Freigaben, Prüfdatum |
| das Protokoll-PDF | Protokoll sichtbar und sein PDF beim Abschluss gespeichert – nie eine Vorschau | – |
| ein **Prüfnachweis** | er gehört K, ist freigegeben, und seine Datei ist unversehrt | Freigaben |
| **nie** | Notizen an Standort, Etage, Anlage und Kunde, Fremdbestand, Netzbetreiber, mobile Anlagen, Kabel, Messgeräte, Katalog, Aufbauten, Einsätze, **Tanks** (siehe [unten](#tanks)), Audit-Log, andere Zugänge und andere Kunden | – |

Was ein Kunde nicht sieht, gibt es für ihn nicht: Ein fremdes, nicht
freigegebenes oder nicht vorhandenes Protokoll, eine fremde Location oder ein
fremder Nachweis geben **dieselbe Fehlerseite 404**. Andere Seiten der App
erreicht ein Kundenzugang gar nicht – jede Adresse außerhalb von
`/kundenbereich` führt zurück zur Übersicht.

Protokolle und Prüfnachweise **überdauern die Location-Freigabe**: Ist die
Veranstaltung vorbei und die Location verschwunden, bleiben die Dokumente
im Kundenbereich – bis der Kunde beendet wird oder ihre Aufbewahrung endet.

### Tanks

**Entscheidung: Kunden sehen nichts von Tanks.** Kein Tank – auch kein
fester Bodentank – steht unter den festen Stromanschlüssen einer Location,
und es gibt keine Füllstände, Verläufe, Verbrauch je Tag, Restlaufzeiten,
Kennlinien, Peilungen, Betankungen (Lieferscheine), Kraftstoffschläuche,
Tanksensoren und keine Tank-Marker. Pläne und ihre Vorschau sind nur die
hochgeladene Plandatei bzw. deren Kachel – eingezeichnet ist darin nichts;
eine Karte hat der Kundenbereich nicht.

Warum:

- Füllstand, Verbrauch und Betankungen sind **Betriebsdaten des
  Dienstleisters** (Laufzeit und Last der Aggregate, Liefermengen,
  Lieferscheine, wer gepeilt hat) – nichts, was ein Veranstalter für die
  Übergabe braucht. Nachbestellen ist Sache des Personals.
- Ein fester Tank versorgt oft **mehrere Veranstalter** auf demselben
  Gelände; sein Pegel verriete, was die anderen verbraucht haben.
- Der Kundenbereich zeigt **keine Messwerte** (siehe [Grenzen](#grenzen)) –
  Tankwerte wären die ersten. Auch die Übergabeberichte, also die
  Protokolle, enthalten keinen Tank (`BerichtService::objektePruefen`).
- Ein Tank ist **kein Stromanschluss**; unter „Feste Stromanschlüsse“ wäre
  er falsch.

So ist es durchgesetzt – jede Tür für sich:

1. **Tor im Router**: Jede Tank-Route (`/anlagen/{id}`, `…/live`,
   `…/tank/verlauf`, `…/tank/tage`, `…/tank/csv`, Peilung, Betankung,
   Kennlinie, Einbau des Sensors), die Karte mit Tank-Panel und Schlauch,
   Statistik und Inventar liegen außerhalb von `/kundenbereich` – ein
   Kundenzugang landet wieder in der Übersicht, JSON bekommt 403. Der
   Durchlauf über alle Routen aus `public/index.php` in
   `tests/kundenbereich.php` schließt die Tank-Routen ein und ruft sie
   zusätzlich mit den echten IDs eines festen und eines mobilen Tanks auf
   der sichtbaren Location auf: keine Antwort nennt einen Tank, an den
   Tanks ändert sich nichts.
2. **Keine Rechte**: Verlauf, Tage und CSV brauchen „Statistik ansehen“ bzw.
   „exportieren“, die Tankseite „Anlagen ansehen“, Eintragen „Anlagen
   ändern“ – Kundenzugänge haben keines davon. `bin/power tank:liste
   --als=<Kundenzugang>` bricht deshalb ab.
3. **Zugriff**: Für einen Kundenzugang deckt keine Freigabe einen Tank –
   `Zugriff::siehtObjekt()` ist falsch, das Datenfenster leer, auch auf der
   sichtbaren Location. Ein künftiger Weg über `Zugriff` oder `Messdaten`
   brächte also auch nichts.
4. **KundenSicht** nimmt unter die festen Anschlüsse nur elektrische
   Anlagen (`AnlagenService::elektrisch()`).
5. **Prüfnachweise**: Ein Tanksensor steht nicht zur Auswahl als
   Inventarstück und wird abgelehnt.

Auch über eine Schnittstelle erreicht kein Tankwert einen Kunden: Tokens
der Plan-API lassen sich nicht an Kundenzugänge binden; was ein fester Tank
dort mitbringt (Eigenschaften wie Bauform, Inhalt und Inhalt je mm – nie
Messwerte, Kennlinien, Peilungen oder Betankungen), geht an andere Instanzen
des Personals, nicht an Kunden.

Konten mit der älteren Rolle *Kunde / Veranstalter* (siehe
[Übergang](#übergang-konten-mit-der-rolle-kunde--veranstalter)) sehen Tanks
dagegen wie jede Anlage ihres freigegebenen Standorts (im Datenfenster
ihrer Freigabe, ohne Knöpfe) – ein Grund mehr, auf Kundenzugänge
umzustellen.

Sollen Kunden später doch etwas sehen (etwa „versorgt aus Tank“ ohne
Werte), gehört das als ausdrücklich genanntes Feld in `KundenSicht` – mit
eigener Prüfung, nie über Freigaben oder die Karte.

## Einrichten

1. **`app.url`** in `config/config.php` auf die Adresse setzen, unter der
   Kunden die Oberfläche erreichen (etwa `https://strom.example.de`). Nur
   daraus entstehen Einrichtungslinks – nie aus der Anfrage. Ohne `app.url`
   gibt es nur vorläufige Kennwörter. Die Karte *System → Einstellungen →
   Kundenbereich* zeigt, ob die Adresse fehlt, ungültig oder
   unverschlüsselt (`http://` außerhalb von `localhost`) ist. Für einen
   Zugang von außen siehe [installation-debian-lxc.md](installation-debian-lxc.md#9--von-außen-erreichbar-machen-optional).
2. **Einstellungen** (*System → Einstellungen → Kundenbereich*, Recht
   „Einstellungen ändern“):

   | Feld | Vorgabe | Wozu |
   |---|---|---|
   | Datenschutzhinweis | Mustertext nach Art. 13 DSGVO mit `{firma.name}` und `{firma.anschrift}` | steht auf der Einrichtungsseite, im Konto und im Fuß jeder Seite. Der Mustertext ist **keine Rechtsberatung** – vor dem Einsatz prüfen. |
   | Ansprechpartner | leer – dann die Bereitschaft aus den Übergabeberichten | Telefon oder Name für Kunden; eine Telefonnummer wird zum Anruf-Knopf |
   | Einrichtungslinks gültig | 72 Stunden (1–168) | wie lange ein Link eingelöst werden kann |
   | Aufbewahrung der Prüfnachweise | 3 Jahre (1–30) | bis 31.12. des Prüfjahres plus so viele Jahre |

3. **Rechte** an die Personen verteilen, die Kunden pflegen
   ([rechte.md](rechte.md#kunden--kundenbereich)): Stromplanung hat ab Werk
   alles, Elektrotechnik darf Kunden ansehen und Prüfnachweise hinterlegen.

Es gibt **keinen Mailversand**. Einrichtungslinks und vorläufige Kennwörter
gibt das Personal selbst weiter – am Telefon, per eigener Mail, ausgedruckt.

## Einen Kunden einrichten

*Kunden → Kunden & Kundenbereich → Neuer Kunde* (Recht „Kunden pflegen“):

1. **Stammdaten**: Name, Kundennummer (nicht doppelt), aktiv, optional
   „Zugänge enden am“. Die Notizen sieht der Kunde nie.
2. **Zugang** anlegen (Reiter *Zugänge*): Benutzername (2–64 Zeichen,
   Groß- und Kleinschreibung zählt nicht: „Muster“ gibt es nicht neben
   „muster“), Name, E-Mail-Adresse. Dann einer von zwei Wegen:
   - **Einrichtungslink** (Vorgabe): ein Link, mit dem der Kunde sein
     Kennwort selbst festlegt – einmal, höchstens 72 Stunden. Danach meldet
     er sich ganz normal unter `/login` an.
   - **Vorläufiges Kennwort**: 16 Zeichen; bei der ersten Anmeldung muss der
     Kunde ein eigenes festlegen und kommt vorher nirgendwohin.

   Link bzw. Kennwort stehen **genau einmal** auf der Seite nach dem
   Anlegen – sie werden nirgends gespeichert (vom Link nur ein HMAC) und
   stehen in keinem Log und keinem Audit-Eintrag. Wer sie verliert, erzeugt
   einen neuen Link bzw. ein neues Kennwort; der alte Link gilt dann nicht
   mehr. Jeder Zugang zeigt seinen Zustand: *aktiv*, *Erstanmeldung*,
   *Einrichtungslink offen*, *Einrichtungslink abgelaufen*, *gesperrt*
   (nach fünf falschen Kennwörtern, *Entsperren*), *abgelaufen*,
   *deaktiviert*.

   Zugänge, Einrichtungslinks und vorläufige Kennwörter gibt nur aus, wer
   **selbst alles sieht, was der Kunde sieht**: „Kunden pflegen“ reicht
   dafür nicht, dazu gehören „Alle Standorte, Anlagen und Messdaten“,
   „Kunden ansehen“ und die Protokolle des Kunden (sonst legte sich jemand
   ein Kundenkonto an und läse dort Pläne und Protokolle, die er nicht
   bekommt). Dasselbe gilt, wenn ein beendeter Kunde oder Zugang wieder
   **aktiv** wird oder sein Ende **später** rückt. Stammdaten ändern und
   früher beenden darf jeder mit „Kunden pflegen“.
3. **Location freigeben** (Reiter *Freigaben*): Standort, *gültig ab/bis*,
   *Daten ab/bis* (oder „wie die Gültigkeit“) und die Veranstaltung als
   Notiz. Ende und Datenzeitraum sind **Pflicht** – ein Gelände hat viele
   Veranstalter. Freigeben darf nur, wer selbst alle Standorte sieht.
4. **Protokolle zuordnen** und **Prüfnachweise hinterlegen** – siehe unten.
5. Unter *Übersicht* prüfen, was der Kunde jetzt sieht: **„Der Kunde sieht
   derzeit …“** rechnet mit denselben Regeln wie der Kundenbereich selbst.

![Ein Kunde in der Kundenverwaltung: „Der Kunde sieht derzeit …“ mit Location, Protokoll und Prüfnachweisen](bilder/kunden-kunde.png)

Ohne Oberfläche geht ein Zugang auch auf der Kommandozeile:
`bin/power kunden:zugang <kunde-id> <benutzer> [--kennwort] [--name=…] [--email=…]`
gibt Link bzw. Kennwort einmal aus.

Der Kunde ändert sein Kennwort selbst unter *Konto*. Ein vergessenes
Kennwort setzt das Personal zurück (neuer Link oder neues vorläufiges
Kennwort); eine Selbstbedienung ohne Mail gibt es nicht.

## Mehrere Veranstalter auf einem Gelände

Auf einer Festwiese folgt ein Veranstalter dem nächsten. Deshalb:

- Eine Freigabe am Kunden hat immer ein **Ende** und einen
  **Datenzeitraum**. Fehlt ein Datenende (etwa per SQL gesetzt), ist der
  Datenzeitraum leer – nie „ohne Grenze“.
- Schneidet sich der Datenzeitraum mit dem eines **anderen Kunden** am
  selben Standort, speichert die Kundenverwaltung erst nach einem bewussten
  Häkchen; das Audit hält das fest. Abgelaufene Freigaben zählen mit (ihre
  Messdaten gehören weiter dem anderen Kunden), bloßes Berühren nicht
  (endet die eine um 12:00 und beginnt die andere um 12:00, sieht keiner
  Daten des anderen).
- **Protokolle** kommen nie über den Standort in den Kundenbereich, nur
  über die ausdrückliche Zuordnung – sonst sähe der nächste Veranstalter den
  Bericht des vorigen, sobald dessen Prüfdatum in seinen Zeitraum fällt.
  Prüfnachweise gehören ebenso einem Kunden, nie einem Standort.
- Kunden sehen **feste** Stromanschlüsse, keine mobile Planung: Der jetzige
  Planungsstand kann schon der des nächsten Veranstalters sein.

## Protokolle zuordnen

Am Übergabebericht (*Übergabeberichte → Bericht*) steht für Personen mit
„Kunden ansehen“ die Karte **Kundenbereich**: wem er zugeordnet ist, ob der
Kunde ihn sieht und wann er ihn zuletzt geladen hat. Mit „Kunden pflegen“:

- **Zuordnen** an einen Kunden aus der Liste. Steht im Bericht ein anderer
  Kundenname, fragt die Seite einmal nach („Im Bericht steht …, zugeordnet
  werden soll … – derselbe Kunde?“). Rechtsformen, „e. V.“ und Satzzeichen
  zählen dabei nicht.
- **Vorschläge** kommen nur aus Eventworx: ist der Eventworx-Vorgang des
  Berichts mit dem Kunden verknüpft oder nennt Eventworx denselben Kunden,
  schlägt die Karte (und der Reiter *Protokolle* am Kunden) ihn vor.
  Zugeordnet wird **nie automatisch**.
- Die Zuordnung gilt für **alle Fassungen** einer Nummer; eine Korrektur
  übernimmt sie. Sie liegt außerhalb des eingefrorenen Inhalts – Inhalt,
  Siegel und PDF ändern sich nicht.
- Der Kunde sieht den Bericht erst, wenn er **abgeschlossen** ist. Wird ein
  zugeordneter Bericht im Kundenmodus unterschrieben, steht danach auf dem
  Tablet: „Der Bericht steht ab sofort in Ihrem Kundenbereich bereit.“
- **Herausnehmen** wirkt sofort.

Im Kundenbereich gibt es die Leseansicht des Berichts (ohne Verlauf, ohne
interne Links) und das gespeicherte PDF mit seiner Prüfsumme (SHA-256) –
so kann der Kunde prüfen, dass sein Exemplar das bei der Übergabe
gespeicherte ist. Eine PDF-Vorschau eines unfertigen Berichts bekommt er
nie.

## Prüfnachweise (DGUV V3)

Prüfnachweise hinterlegt, wer „Prüfnachweise hochladen …“ hat, im Reiter
*Prüfnachweise* des Kunden. Der Kunde sieht einen Nachweis erst, wenn eine
Person ihn **freigibt** – und nur mit unversehrter Datei.

![Prüfnachweise im Kundenbereich: Gültigkeit mit Symbol und Wort, Mietzeit, Quelle, PDF als Datei](bilder/kundenbereich-pruefnachweise.png)

### Von Hand

- **Nur PDF** (beginnt mit `%PDF-`), höchstens **10 MB**. Gleiche Bytes
  liegen nur einmal in der Datenbank. Eine größere Datei hält schon der
  Browser beim Auswählen an (Meldung unter dem Feld). Ohne JavaScript lehnt
  die App sie mit der Größe ab – bis 16 MB: Mehr lässt nginx außerhalb von
  `/standorte/grundriss` und `/api/` nicht durch und antwortet dann selbst
  mit 413. Ohne nginx davor gilt `post_max_size` (im Container 105M);
  darüber verwirft PHP den Körper ganz, und die App meldet die Größe statt
  „Sicherheitstoken ungültig“.
- **Angaben aus dem Protokoll** trägt eine Person ein – das PDF wird nicht
  ausgewertet: Gerät/Bezeichnung (Pflicht), Inventar- und Seriennummer,
  **geprüft am** (Pflicht, nicht in der Zukunft), **nächste Prüfung**,
  Norm, **Ergebnis** (nur, wenn es im Protokoll steht), Location (nur eine
  aus den Freigaben dieses Kunden), Eventworx-Vorgang des Kunden,
  Mietzeit (ohne eigene die des Vorgangs), Inventarstück (füllt leere
  Nummern; nie ein Tanksensor, siehe [Tanks](#tanks)) und ein Hinweis für
  den Kunden.
- Eine **Sammel-PDF** für mehrere Geräte nur, wenn sie **ausschließlich
  Geräte dieses Kunden** enthält – sonst liest ein Kunde die Geräte eines
  anderen mit.
- Status: *Entwurf* → *freigegeben* ↔ *zurückgezogen*. Löschen nimmt die
  Abrufe mit und die Datei, wenn sie sonst niemand nutzt.

Der Kunde bekommt die Datei als Download unter neutralem Namen
(`Pruefnachweis-<Nr>-<Datum>.pdf`), nie unter dem hochgeladenen.

### Gültigkeit

| Stufe | wann |
|---|---|
| ✓ gültig | nächste Prüfung in mehr als 30 Tagen |
| ! läuft bald ab | nächste Prüfung heute oder in höchstens 30 Tagen |
| ✗ abgelaufen | nächste Prüfung vor heute |
| ? unbekannt | keine nächste Prüfung eingetragen |

Gerechnet wird mit dem Tag in der Zeitzone der Installation. Dazu
Hinweise zur Mietzeit: „Die Prüffrist endet in der Mietzeit“, „… war schon
vor Beginn der Mietzeit abgelaufen“, „Nach der Übergabe geprüft“. Wer
Geräte an wechselnden Einsatzorten nutzt, muss dort einen Nachweis der
letzten Prüfung vorhalten (BetrSichV § 14 Abs. 7) – der Kundenbereich
sagt das, ist aber **keine Rechtsberatung**.

### Aus Eventworx

Liegen die Prüfprotokolle in Eventworx **am Auftrag oder Lieferschein**,
lassen sie sich übernehmen statt erneut hochzuladen
([eventworx.md](eventworx.md#prüfnachweise-aus-dateien-am-vorgang)):

1. Reiter *Vorgänge*: einen Auftrag oder Lieferschein suchen und mit dem
   Kunden **verknüpfen** (Recht „Kunden pflegen“ und „Kundendaten aus
   Eventworx …“). Beim ersten Vorgang nennt die Seite den Kunden, den
   Eventworx dazu kennt, und fragt einmal nach; danach gehören alle weiteren
   Vorgänge dieses Eventworx-Kunden hierher – und keine anderen. Ein
   Vorgang und ein Eventworx-Kunde gehören höchstens einem Kunden.
2. *PDF-Dateien ansehen*: die PDFs, die Benutzer in Eventworx an diesem
   Vorgang hochgeladen haben. Erzeugte Belege (Auftrag, Rechnung,
   Lieferschein) erscheinen nicht.
3. Dateien wählen, die **Angaben aus dem Protokoll** eintragen,
   *Gewählte übernehmen* – höchstens fünf je Durchgang. Eine Datei aus
   Eventworx gibt es hier nur einmal, auch nie für zwei Kunden.

Solange Eventworx antwortet, zeigt die Seite, dass gewartet wird; ein
zweiter Klick zählt nicht. Andere Reiter desselben Browsers lassen sich
währenddessen bedienen – die Anfrage hält die Sitzung nicht. Beginnt in
einem davon der Kundenmodus, kommt die Antwort nicht mehr an: Der Reiter
führt zur Kundenseite.

![Dateien am Eventworx-Auftrag AU-1042 der Demo: eine schon übernommen, eine zur Auswahl, darunter die Angaben aus dem Prüfprotokoll](bilder/kunden-nachweise-eventworx.png)

Prüfberichte aus *Werkstatt → Prüfungen* (am einzelnen Gerät) sind über
die Schnittstelle von Eventworx noch nicht nachgewiesen – sie bitte von Hand
hinterlegen. Ob es dafür einen Weg gibt, zeigt die Lese-Probe
`bin/power eventworx:sonde` ([eventworx.md](eventworx.md#lese-probe)).

**Der Kundenbereich selbst fragt Eventworx nie.** Was der Kunde lädt, liegt
schon in der Datenbank; ein später in Eventworx gelöschtes Dokument bleibt
hier mit seiner Herkunft, bis das Personal es löscht.

### Aufbewahrung

Ein Nachweis bleibt bis zum 31.12. des Prüfjahres plus
`kundenbereich.nachweise_jahre` (Vorgabe 3). Gelöscht wird **nie
automatisch**: Die tägliche Wartung meldet nur, wie viele über der Frist
sind.

```bash
sudo -u www-data php /opt/powermanagement/bin/power kunden:aufbewahrung              # zeigen
sudo -u www-data php /opt/powermanagement/bin/power kunden:aufbewahrung --ausfuehren # löschen
sudo -u www-data php /opt/powermanagement/bin/power kunden:nachweise-pruefen         # Prüfsummen nachrechnen (Exit 1 bei Befund)
```

## Der Kundenbereich aus Sicht des Kunden

Am Telefon liegt die Navigation als Leiste unten, am Rechner als Reiter oben:

| Seite | Inhalt |
|---|---|
| **Übersicht** | Locations, die letzten Protokolle, Prüfnachweise (was bald abläuft), „Demnächst“, Ansprechpartner mit Anruf-Knopf, Hilfe |
| **Locations** | je Location die Veranstaltung mit Zeitraum, die Pläne der Etagen (Bild im Browser, PDF als Datei; als Vorschau nur ein kleines Bild – die Kachel der Übersicht oder ein Plan bis 2 MB, nie der große Plan), die festen Stromanschlüsse mit Anschlüssen und Vorsicherung, die eigenen Protokolle und Nachweise dort |
| **Protokolle** | gültige Fassung je Nummer, frühere darunter; Leseansicht und PDF |
| **Prüfnachweise** | mit Gültigkeit, Mietzeit und Quelle, Filter je Location; PDF als Datei |
| **Konto** | Benutzername, Name, Kennwort ändern, Datenschutzhinweis |
| **Hilfe** | Unterschied Übergabebericht und Prüfnachweis, was die Angaben bedeuten, was vor Ort zu tun ist (RCD-Prüftaste arbeitstäglich, Nachweis am Einsatzort) |

Alle Seiten werden nicht zwischengespeichert (`Cache-Control: private,
no-store`), senden keinen Referer und kommen ohne Skript im Seitentext aus.

## Abrufe

Jeder **Download** eines Dokuments im Kundenbereich wird festgehalten –
und die **Leseansicht eines Protokolls** genauso, denn sie zeigt den ganzen
Bericht (Ansprechpartner, Anschrift, Unterschriften). So lässt sich nach
einer Fehlzuordnung sagen, ob der falsche Kunde das Dokument wirklich hatte:

| Spur | was | wie lange |
|---|---|---|
| `kunden_abrufe` (Reiter *Abrufe* am Kunden) | Kunde, Zugang, Dokument (Protokoll als PDF oder angesehen, Prüfnachweis), Prüfsumme des PDF, Zeit – ohne IP | mit dem Dokument bzw. dem Kunden |
| Audit-Log `kundenbereich.abruf` | Art und Kennung des Dokuments, mit IP | 400 Tage |
| Verlauf des Berichts `pdf_kunde` bzw. `ansicht_kunde` | Zeit, ohne Namen | so lange wie der Bericht |

Eine Ansicht desselben Protokolls durch denselben Zugang zählt höchstens
einmal in der Stunde (Neuladen ist kein neuer Abruf). Andere Seitenaufrufe
(Übersicht, Listen, Locations) werden nicht festgehalten. Am Bericht steht
„zuletzt angesehen“ bzw. „zuletzt als PDF geladen“, am Nachweis „zuletzt
geladen“; hat ein **anderer** Kunde den Bericht aufgerufen, warnt die Karte.

## Kunde beenden und löschen

- **Beenden**: *aktiv* ausschalten (oder „Zugänge enden am“). Alle Zugänge
  sind sofort zu, auch laufende Sitzungen enden bei der nächsten Anfrage;
  der Kunde sieht nichts mehr, seine Daten bleiben.
- **Löschen** geht nur bei einem beendeten Kunden und nur mit seinem Namen
  als Bestätigung. Weg sind dann seine Zugänge, Freigaben, Vorgänge,
  Prüfnachweise (mit den Dateien, die sonst niemand nutzt) und Abrufe.
  **Übergabeberichte bleiben** (Aufbewahrung), nur ohne Zuordnung.
- Wird ein **Standort** gelöscht, gehen die Freigaben der Kunden darauf
  mit; Vorgänge und Nachweise bleiben, nur ohne Standort.

## Datenschutz

Der Betreiber ist Verantwortlicher; Eventworx und das Hosting sind
Auftragsverarbeiter (der AV-Vertrag mit Eventworx muss den Abruf über die
Schnittstelle abdecken). Was folgt, ist eine Arbeitshilfe, **keine
Rechtsberatung**.

| Thema | Umsetzung |
|---|---|
| Rechtsgrundlage | Vertrag und Nachweispflichten (Art. 6 Abs. 1 lit. b und c DSGVO; BetrSichV, DGUV V3). Prüfernamen in den PDFs sind Pflichtangaben. |
| Datenschutz durch Technik (Art. 25) | Standard „nichts sichtbar“; Locations nur ausdrücklich, mit Ende und Datenzeitraum; Dokumente nur nach Zuordnung bzw. Freigabe durch eine Person; Kunden geben nie fremde Kennungen ein; Eventworx nie vom Kunden ausgelöst. |
| Datenminimierung (Art. 5 c) | Kunden ohne Anschrift und Telefon; aus Eventworx nur Kennungen, Nummer, Titel, Mietzeit, Kunden-Kennung und -Name zur Kontrolle und die gewählten Dateien – keine Preise, Positionen, Kontakte. |
| Sicherheit (Art. 32) | Tor im Router, gleiche 404 für Fremdes, `no-store`, Anmelde-Bremse, Einrichtungslink nur als HMAC, Geheimnisse nie in Log, Audit oder Antwort, Prüfskripte gegen das Durchprobieren von Kennungen. |
| Transparenz (Art. 13) | Datenschutzhinweis auf Einrichtungsseite, Konto und im Fuß. |
| Cookies (§ 25 TDDDG) | nur Sitzung und CSRF-Schutz. |

**Speicherbegrenzung (Art. 5 e):**

| Daten | bleiben bis |
|---|---|
| Kunde, Zugänge | Löschung durch das Personal; ein beendeter Kunde hat keinen Zugang |
| Freigaben am Kunden | mit Kunde oder Standort; abgelaufene bleiben als Verlauf, wirken aber nicht |
| Übergabeberichte | ihre Aufbewahrung ([berichte.md](berichte.md#aufbewahrung)); beim Löschen des Kunden nur ohne Zuordnung |
| Prüfnachweise und Dateien | `aufbewahren_bis` oder Löschen des Kunden; eine Datei, sobald sie niemand mehr nutzt |
| Abrufe | mit dem Dokument bzw. dem Kunden |
| Audit-Log | 400 Tage (`wartung`) |
| Einrichtungslinks | Einlösen; abgelaufene macht `wartung` unbrauchbar |

**Fehlzuordnung (Art. 33/34).** Hat ein Kunde ein fremdes Protokoll oder
einen fremden Nachweis bekommen:

1. Sofort **herausnehmen** (Karte am Bericht) bzw. **zurückziehen**
   (Nachweis) – wirkt bei der nächsten Anfrage.
2. Unter *Abrufe* am Kunden bzw. „zuletzt angesehen/geladen“ nachsehen, ob
   er das Dokument **angesehen** (Leseansicht des Protokolls – zeigt den
   ganzen Inhalt) oder **heruntergeladen** hat. Steht dort nichts, hat
   er es im Kundenbereich nicht geöffnet – in der Liste standen nur Nummer,
   Titel, Location und Prüfdatum.
3. Bewerten und, wenn nötig, binnen **72 Stunden** der Aufsichtsbehörde
   melden; den betroffenen Kunden informieren.

**Vorlage für das Verzeichnis der Verarbeitungstätigkeiten (Art. 30):**

| Feld | Eintrag |
|---|---|
| Bezeichnung | Kundenbereich (Bereitstellung von Prüfprotokollen und Prüfnachweisen) |
| Zweck | Kunden ihre Übergabeberichte, Prüfnachweise und Locations bereitstellen |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b und c DSGVO |
| Betroffene | Ansprechpartner der Kunden (Zugänge), Prüfer (in den Dokumenten) |
| Daten | Benutzername, Name, E-Mail, letzte Anmeldung, Abrufe mit Zeit; Inhalte der Dokumente |
| Empfänger | der jeweilige Kunde; Auftragsverarbeiter Hosting und Eventworx |
| Löschfristen | siehe Tabelle oben |
| Technische Maßnahmen | siehe „Sicherheit“ oben und [rechte.md](rechte.md) |

## Grenzen

- Keine Zwei-Faktor-Anmeldung, kein Mailversand, keine Selbstbedienung
  beim Kennwort.
- Keine Karte, keine Statistik, keine Messwerte, keine Tanks, keine mobile
  Planung, kein Aufbau – auch wenn ein Datenzeitraum freigegeben ist.
- Kundenzugänge melden sich nie über SSO an.
- Keine ZIP aller Nachweise und keine Übernahme von Prüfdaten aus einer
  Prüfsoftware oder aus *Werkstatt → Prüfungen* in Eventworx.
- Das Personal sieht den Kundenbereich nicht „als Kunde“; „Der Kunde sieht
  derzeit …“ rechnet aber mit denselben Regeln.

## Übergang: Konten mit der Rolle „Kunde / Veranstalter“

Konten mit der Rolle *Kunde / Veranstalter* und Freigaben am Konto laufen
**unverändert weiter**. Sie sehen Übersicht, Anlagen, Karte und Statistik
des freigegebenen Standorts – also auch den **jetzigen Planungsstand** mit
mobilen Anlagen, der schon der des nächsten Veranstalters sein kann, und
mit einer Freigabe ohne Datenzeitraum die ganze Messgeschichte. Freigaben
an der Rolle oder am Konto gelten für Kundenzugänge nie.

Umstellen je Kunde:

1. Kunde anlegen, Zugang einrichten.
2. Für jeden Standort des alten Kontos eine Freigabe am Kunden mit
   denselben Zeiträumen anlegen (Ende und Datenzeitraum sind jetzt Pflicht).
3. Seine Übergabeberichte zuordnen.
4. Das alte Konto deaktivieren und später löschen.

## Kommandozeile

```text
bin/power kunden:liste                       Kunden: ID, Name, aktiv, Zugänge, aktive Locations, Nachweise
bin/power kunden:zugang <kunde-id> <benutzer> [--kennwort] [--name=…] [--email=…]
bin/power kunden:aufbewahrung [--ausfuehren] Prüfnachweise über der Aufbewahrungsfrist
bin/power kunden:nachweise-pruefen           Dateien der Prüfnachweise nachrechnen; Nachweise und Vorgänge ohne Kunden melden
bin/power eventworx:sonde [--job=OID] …      Lese-Probe (eventworx.md)
bin/power wartung                            macht abgelaufene Einrichtungslinks unbrauchbar, meldet fällige Nachweise
```

## Demo

`php bin/power demo:seed` legt auf dem Demo-Gelände zwei Veranstalter an
(alle Namen und Werte erfunden):

| Zugang | Kennwort | zeigt |
|---|---|---|
| `muster-events` | `Kundenbereich2026!` | **Muster Events GmbH & Co. KG**: das Festivalgelände während des Sommerfestivals (Messdaten nur der Festivalwoche), das abgeschlossene Protokoll UB-…-0001, drei freigegebene Prüfnachweise (einer läuft in 20 Tagen ab, einer stammt aus dem Eventworx-Auftrag AU-1042). Ebenfalls beim Kunden, aber für ihn noch unsichtbar: UB-…-0002 (wartet auf die Unterschrift des Kunden) und ein Nachweis im Entwurf. |
| `stadtmarketing` | `Stadtmarketing2026!` | **Stadtmarketing Musterstadt e. V.**: dasselbe Gelände erst für den Herbstmarkt in einem Monat – bis dahin nur unter „Demnächst“, und nichts von Muster Events. |

Am Auftrag AU-1042 liegt in der Demo ein zweites Prüfprotokoll, das noch
nicht übernommen ist – zum Ausprobieren. Eventworx wird im Demo-Modus nie
gefragt.

## Prüfen

| Skript | prüft |
|---|---|
| `tests/kundenbereich.php` | Kundenzugänge ohne Rechte, Tor über alle Routen (auch die Tank-Routen, dazu mit echten Tank-IDs), keine Tanks (nicht unter den festen Anschlüssen, nicht über `Zugriff`, nicht auf den Seiten, `tank:liste` abgelehnt), Freigaben nur am Kunden, mehrere Veranstalter je Gelände, Locations und Pläne, Protokolle nur über die Zuordnung, gleiche 404 für Fremdes, PDF-Kopfzeilen und Abrufe (auch der Leseansicht), Einrichtungslink, Zugänge nur von dem, der alles sieht, Kundenmodus neben dem Kundenbereich (Kundenzugänge nie im Kundenmodus), Kontowechsel im selben Browser, Kunde beendet, Benutzer- und Kundenverwaltung |
| `tests/pruefnachweise.php` | Upload nur PDF, Dubletten, Angaben (kein Tanksensor), Sichtbarkeit, Gültigkeit in der Mietzeit, Aufbewahrung, Integrität (auch Heilen und Datensätze ohne Kunden), Übernahme aus Eventworx (auch mit gleichzeitigem Löschen) |
| `tests/eventworx.php` | Dateien am Vorgang, Verknüpfen, Lese-Probe |
| `tests/sitzung.php` | Suche, Verknüpfen und Dateiliste halten die Sitzung nicht; Kundenmodus während des Wartens; Formulare mit `data-laeuft-seite` |
| `tests/web.php`, `tests/sicherheit.php`, `tests/berichte.php`, `tests/zeitfenster.php`, `tests/schnittstelle.php` | Seiten der Kundenverwaltung, Rechte, Zuordnung am Bericht, Freigaben am Kunden, keine Tokens für Kundenzugänge |
