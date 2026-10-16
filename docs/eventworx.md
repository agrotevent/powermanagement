# Eventworx: Kundendaten für Übergabeberichte

Ein Übergabebericht braucht Kunde, Anschrift, Ansprechpartner, Auftrags- und
Projektnummer, Veranstaltung und Ort. Das steht meist schon in **Eventworx**.
PowerManagement kann es von dort holen, statt es abzutippen. Eventworx ist
dafür **nicht nötig**: Ohne Eventworx werden die Kundendaten als Freitext
eingetragen, und das ist der Normalfall.

```
 Übergabebericht ── Suche (Nummer/Text) ──► PowerManagement ── GET /backend/job ──────► Eventworx
                 ◄── Treffer ─────────────                  ◄── Aufträge, Angebote ──
                 ── Treffer antippen ─────►                 ── GET /backend/job/<id> ─►
                 ◄── Kunde, Ort, Zeitraum ─                 ◄── ein Vorgang ──────────
```

PowerManagement **liest nur**. Es schreibt nichts nach Eventworx und lädt
auch kein PDF hoch. Die übernommenen Werte werden in den Bericht
**kopiert** und bleiben dort änderbar.

## Voraussetzungen

- **REST-Modul in Eventworx.** Die REST-Schnittstelle ist ein kostenpflichtiges
  Zusatzmodul, das Eventworx freischaltet.
- **API-Token.** Angelegt wird es in Eventworx unter *Setup →
  Programmeinstellungen → API*. Ein Token **„REST API Lesen“** genügt.
  Ein Token „Schreiben/Lesen“ geht auch, ist aber unnötig: Ein Eventworx-Token
  hat weitreichende Rechte, und PowerManagement braucht davon nur das Lesen
  von Aufträgen und Angeboten.
- **Netz.** Der Server von PowerManagement muss `https://<mandant>.eventworx.biz`
  erreichen (ausgehend HTTPS, Port 443).

## Einrichten

*System → Einstellungen → Eventworx* (Recht „Einstellungen ändern“):

1. **Mandant** eintragen: `firma`, `firma.eventworx.biz` oder ein aus dem
   Browser kopierter Link aus Eventworx (`https://firma.eventworx.biz/eventworx/#job/…`).
   Übrig bleibt immer nur `firma.eventworx.biz`.
2. **API-Token** einfügen und **Speichern**.
3. **Verbindung prüfen.** Erst fragt PowerManagement `check/ping`, dann
   zählt es die lesbaren Aufträge und Angebote. Erfolg sieht so aus:
   „Verbunden mit firma.eventworx.biz – 1.234 Aufträge/Angebote lesbar.“

Für das Token gilt:

- Es wird **verschlüsselt** abgelegt (mit `app.key`) und nie wieder angezeigt.
  Das Feld zeigt nur „hinterlegt für firma.eventworx.biz – leer lassen zum
  Behalten“.
- Es gilt **nur für die Adresse, für die es eingetragen wurde.** Wer die
  Adresse ändert, ohne ein neues Token einzugeben, verliert das alte:
  „Für eine andere Eventworx-Adresse bitte das API-Token neu eingeben.“
  So kann niemand das Token durch Ändern der Adresse an einen fremden Server
  schicken.
- `-` im Tokenfeld entfernt das Token. Ein leerer Mandant entfernt die ganze
  Anbindung.
- Im Audit-Log steht nur der Host und ob das Token gesetzt, entfernt oder
  unverändert ist, nie das Token selbst.

Erlaubt ist nur `https://<mandant>.eventworx.biz` auf Port 443, ohne
Benutzerangaben in der Adresse. Ein Testserver oder eine eigene
Eventworx-Installation geht nur über `config/config.php`:

```php
'eventworx' => [
    'basis' => 'https://eventworx.intern.example',
],
```

Diese Basis gilt nur, wenn im Feld **Mandant** genau diese Adresse steht.
Das Token kommt auch dann aus der Oberfläche, nie aus der Datei.

## Im Übergabebericht

Im Bericht hat der Abschnitt Kunde drei Reiter: **Freitext | Eventworx | Aus
früherem Bericht**. Die Oberfläche sagt dazu, dass die Kundendaten aus dem
Eventworx-Vorgang kommen.

1. **Suchen:** Auftrags- oder Angebotsnummer eintippen, dann Knopf oder
   Enter. Es gibt keine Live-Suche beim Tippen. Gesucht wird unter Aufträgen
   und Angeboten, die nicht gelöscht oder archiviert sind. Ein Treffer mit
   genau der eingegebenen Nummer steht oben.
2. **Treffer antippen:** PowerManagement liest den Vorgang und zeigt, was er
   eintragen würde, je Gruppe mit Häkchen: Veranstaltung, Kunde,
   Ansprechpartner, Ort und Zeitraum. Weicht ein Feld vom bisherigen Eintrag
   ab, stehen beide Werte nebeneinander.
3. **Übernehmen:** Die Felder werden gefüllt und bleiben änderbar. Der
   Bericht merkt sich nur den Verweis (Host, Kennung, Art, Nummer, Zeit).
   Daraus wird der Link „In Eventworx öffnen“, über den man das fertige PDF
   dort von Hand anhängen kann.

Die Nummer ist der sichere Weg. Ob Eventworx mit dem Suchtext auch in Titel
und Kundennamen sucht, ist am echten Mandanten noch nicht geprüft.

Eventworx gibt es nur in einem Bericht, den man ändern darf (Recht
„Kundendaten aus Eventworx suchen und übernehmen“). Eine freie Suche durch
Eventworx gibt es nicht. Das Recht zeigt alle Vorgänge des Mandanten und
gehört deshalb nicht zu Kunden-Rollen.

## Was gelesen wird – und was nicht

| aus dem Vorgang | im Bericht |
|---|---|
| `title`, `jobNumber` (bei Angeboten `variantNumber`), `projectNumber` | Veranstaltung, Auftrags-Nr., Projekt-Nr. |
| `customer`: Firma bzw. Vor- und Nachname bzw. Ortsname, Kundennummer | Kunde |
| `postalAddress`: Straße, Zusatz, PLZ Ort, Land (nur außerhalb Deutschlands) | Anschrift |
| `contact`: Anrede, Titel, Name, Abteilung; erste gültige Telefonnummer (`tel_business`, sonst `mobile`) und E-Mail (`email_business`) | Ansprechpartner, Vorschlag Projektleiter Kunde |
| `locationContact` und `locationAddress`, oder der Kunde selbst (`customerIsLocation`); `locationContactPerson` | Vorschlag Aufstellort und Anlagenverantwortlicher |
| `setupDate`, `startDate`/`endDate` (sonst Mietzeit), `tearDownDate` | Zeitraum in Ortszeit |

**Nie übernommen** werden Positionen, Preise und Beträge, Notizen
(`notes`, `internalNote`), die Rechnungsseite (`invoiceContact`,
`invoiceAddress`) und Bankdaten. Sie verlassen den Server nicht und kommen
auch nicht in den Browser.

Namen speichert Eventworx so, wie die Web-App sie maskiert
(`Muster &amp; Söhne`). PowerManagement dekodiert nur vollständige
Entitäten. Steuer- und Richtungszeichen werden entfernt.

Zeiten liefert Eventworx als Millisekunden oder ISO-Text. Sie werden in UTC
gespeichert und in der Zeitzone der Installation angezeigt. Ein Tag „ab
11.07.“ ist in Berlin 22:00 UTC am Vortag; angezeigt wird der 11.07.

## Was PowerManagement an Eventworx schickt

- Nur **GET**, und nur an drei Pfade unter `/backend/`: `check/ping`, `job`
  und `job/<id>` mit `<id>` aus 8–40 Buchstaben und Ziffern. Andere Pfade,
  etwa `file/delete/…` (Eventworx löscht Dateien per GET!), lehnt das
  Programm ab, bevor etwas ins Netz geht.
- Kopfzeilen `S-API-TOKEN`, `Accept: application/json`, `User-Agent: PowerManagement`.
- **Keine Umleitungen.** Eine 3xx-Antwort wird nicht verfolgt, damit das
  Token nicht an ein anderes Ziel geht. Das gilt für alle HTTP-Abfragen von
  PowerManagement.
- Höchstens **zwei Anfragen je Sekunde**, über alle PHP-Prozesse zusammen.
  Wer länger als zwei Sekunden warten müsste, bekommt „gerade viele
  Anfragen – gleich noch einmal“. Abgelehnte Anfragen werden nicht wiederholt,
  auch nicht nach „zu viele Anfragen“.
- Ins Log (`storage/logs/app.log`) kommen je Anfrage nur Pfadmuster (`job`,
  `job/<id>`, `check/ping`), HTTP-Status, Fehlercode und Dauer. Suchtext,
  Vorgangskennung, Antwort und Token kommen nicht ins Log.

## Fehlermeldungen

| Meldung | Ursache und Abhilfe |
|---|---|
| Eventworx ist nicht eingerichtet (Systemeinstellungen). | Mandant oder Token fehlt, oder das Token gehört zu einer anderen Adresse: Token neu eintragen. |
| Eventworx nicht erreichbar – Kundendaten bitte als Freitext eintragen. | Kein Netz, DNS, Zeitüberschreitung (12 s). |
| Eventworx leitet um – stimmt die Adresse? | Antwort 3xx: falscher Mandantenname oder Adresse. |
| Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen. | 401/403 oder `ACCESS_DENIED` (Eventworx meldet das auch mit HTTP 200): Token falsch oder widerrufen, REST-Modul nicht freigeschaltet. |
| Vorgang in Eventworx nicht (mehr) vorhanden. | 404, oder zweimal hintereinander „Erfolg ohne Daten“. Eventworx meldet fehlende Vorgänge so, deshalb wird nach einer halben Sekunde einmal nachgelesen. |
| Eventworx: zu viele Anfragen – bitte kurz warten. | 429 von Eventworx. |
| Eventworx: gerade viele Anfragen – bitte gleich noch einmal. | Die eigene Drossel (siehe oben). |
| Eventworx meldet einen Serverfehler (HTTP 5xx). | Störung bei Eventworx. |
| Eventworx antwortet nicht mit JSON – stimmt die Adresse? | Statt der Schnittstelle kam eine Webseite, meist die Anmeldeseite. |
| Eventworx: VALIDATION (o. ä.) | Eventworx meldet einen eigenen Fehlercode. Angezeigt wird er nur, wenn er wie ein Code aussieht (Großbuchstaben, Ziffern, `_`), sonst „Eventworx: Fehler“. |
| Eventworx: unerwartete Antwort. | Die Antwort hat nicht die erwartete Form, etwa eine fremde Vorgangskennung oder eine Rechnung statt Auftrag oder Angebot. |

In keiner Meldung stehen Antwortinhalt, Umleitungsziel oder Token. Bei jeder
Meldung im Bericht bleiben die Freitextfelder nutzbar.

## Datenschutz

- Eventworx verarbeitet die Kundendaten im Auftrag des eigenen Unternehmens.
  Der **Vertrag zur Auftragsverarbeitung (AV-Vertrag)** mit dem
  Eventworx-Anbieter muss bestehen; in der Regel gibt es ihn schon. Dass
  PowerManagement auf dem eigenen Server liest, ändert daran nichts.
- Übernommen wird nur, was der Bericht braucht (Art. 5 Abs. 1 lit. c DSGVO):
  Firma, Anschrift, Ansprechpartner mit Telefon und E-Mail, Ort, Zeitraum und
  Nummern. Keine Finanzdaten.
- Die Übernahme gehört in das Verzeichnis der Verarbeitungstätigkeiten, unter
  derselben Tätigkeit wie die Übergabeberichte.
- Suchergebnisse werden nicht zwischengespeichert. Was übernommen ist, steht
  im Bericht und nirgends sonst.

## Demo-Modus

Im Demo-Modus (`app.demo`) fragt PowerManagement Eventworx nie. Die Suche
liefert zwei erfundene Vorgänge: Auftrag `AU-1042` „Sommerfestival 2026“
(Kunde „Muster Events GmbH & Co. KG“, Ansprechpartnerin Erika Musterfrau,
Festivalgelände Nordwiese) und Angebot `AN-1050-01`.

## Prüfen

`php tests/eventworx.php` prüft die Anbindung ohne Netz, mit einer
nachgestellten Gegenstelle: Adressen, Token-Bindung, Pfadliste, Form der
Anfragen, Aufbereitung, jede Fehlerantwort, Drossel, Demo und dass Token und
Suchtext nirgends auftauchen.

Diese Punkte sind gegen einen echten Mandanten noch nicht geprüft:

- ob `query` nur Nummern oder auch Titel und Kunden durchsucht;
- welche Felder die verkürzte Trefferliste trägt (Zeitraum, Projekt);
- ob es `setupDate` und `tearDownDate` in der Schnittstelle gibt;
- welche `typeName`-Werte die Kommunikationsdaten haben;
- ob `check/ping` ein Token braucht;
- ob Eventworx unbekannte Filter still übergeht.
