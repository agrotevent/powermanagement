# Rechte, Freigaben und Zeitfenster

PowerManagement trennt zwei Fragen:

1. **Was darf jemand tun?** – über **Rollen** mit **Rechten** (Anlagen
   ansehen, Karte bearbeiten, Statistik exportieren, schalten …).
2. **Worauf und wann?** – über **Freigaben**: für alles, einen Standort,
   eine Anlage oder ein einzelnes Gerät, jeweils mit Gültigkeitszeitraum und
   eigenem Datenzeitraum.

Ein Recht allein zeigt noch nichts an. Wer „Statistik ansehen“ darf, aber
keine Freigabe hat, sieht eine leere Auswertung. Das Recht
`anlagen.scope.all` („alle Standorte“) ersetzt die Freigaben. Administration
und Stromplanung haben es.

## Rollen

| Rolle | gedacht für | Rechte (Auszug) |
|-------|-------------|-----------------|
| **Administration** | Betreiber | alles (`*`) |
| **Stromplanung** | Planer im Büro | Anlagen, Stecker, Karte, Grundrisse, Kabel, Statistik und Export, Datenquellen ansehen, Inventar pflegen, Locations holen. Sieht alle Standorte, **schaltet nicht**. |
| **Elektrotechnik vor Ort** | Elektriker auf dem Gelände | Anlagen und Kabel dokumentieren, Statistik, Datenquellen ansehen, **schalten** und Schaltprotokoll – nur auf freigegebenen Standorten |
| **Kunde / Veranstalter** | Auftraggeber | Übersicht, Anlagen, Karte, Statistik und CSV-Export – nur Freigegebenes im freigegebenen Zeitraum |

Rollen und ihre Rechte lassen sich unter **System → Rollen & Rechte** anpassen oder
neu anlegen. Die Rolle *Administration* behält immer alle Rechte.

Das Rechte-Verzeichnis steht in `src/Auth/Permissions.php`.

## Zeit an drei Stellen

| Wo | Wirkung |
|----|---------|
| **Benutzerkonto** *gültig ab/bis* | Außerhalb des Zeitraums ist keine Anmeldung möglich. Eine laufende Sitzung endet mit der nächsten Anfrage. |
| **Rollenzuweisung** *ab/bis* | Die Rolle – und damit ihre Rechte und die Freigaben der Rolle – gilt nur in diesem Zeitraum. |
| **Freigabe** *gültig ab/bis* | Die Freigabe wirkt nur in diesem Zeitraum. |
| **Freigabe** *Daten ab/bis* | Welche **Messdaten** sichtbar sind. Unabhängig davon, wann man hinschaut. |

Die Zeitpunkte gelten in der Zeitzone der Installation (Vorgabe
Europe/Berlin). Gespeichert wird UTC.

### Beispiel: „User A sieht die Statistik vom 1. bis 7. Juli, danach nicht mehr“

1. Benutzer anlegen, Rolle **Kunde / Veranstalter**.
2. **Freigaben** → *Gilt für: Standort* „Festivalgelände“,
   *gültig* 01.07. 00:00 – 07.07. 23:59, Haken **„Daten: genau der
   Gültigkeitszeitraum“**.

Ergebnis: Vom 1. bis 7. Juli sieht A den Standort und die Messwerte dieser
Tage. Ab dem 8. Juli ist die Freigabe abgelaufen, und A sieht nichts mehr,
auch nicht die Juli-Daten.

Soll A die Festivalwoche **auch danach** noch einsehen dürfen (etwa für die
Abrechnung), dann *gültig* bis Ende August und *Daten* 01.07. – 07.07.
setzen. A sieht dann bis Ende August genau diese Woche und keine Messwerte
davor oder danach.

Sicherheitshalber lässt sich zusätzlich das **Konto** befristen
(*gültig bis*). Dann ist nach Ablauf auch keine Anmeldung mehr möglich.

### Wie das Datenfenster durchgesetzt wird

Die Einschränkung geschieht **vor** der Abfrage. `Zugriff::begrenzen()`
schneidet den gewünschten Zeitraum auf die freigegebenen Intervalle zu. An
die InfluxDB gehen nur Abfragen innerhalb dieser Intervalle. Mehrere
Freigaben werden vereinigt, Lücken dazwischen bleiben leer. Das gilt für
Diagramme, Kennzahlen, Energie je Tag, CSV-Export und Livewerte. Livewerte
gibt es nur, wenn *jetzt* im Datenfenster liegt.

## Freigaben

**System → Benutzer & Freigaben** (je Benutzer) oder **System → Rollen &
Rechte → Freigaben** (gilt für alle mit dieser Rolle, solange die Zuweisung gilt).

| Feld | Bedeutung |
|------|-----------|
| *Gilt für* | **alles**, ein **Standort** (mit allen Anlagen darauf), eine **Anlage** oder ein **Gerät** (Datenquelle) |
| *nur Kanal* | nur bei Geräten: z. B. `switch:2`. Leer bedeutet alle Kanäle. |
| *darf Anlagen bearbeiten* | wirkt nur zusammen mit dem Recht „Anlagen ändern“. Beschränkt das Bearbeiten auf den freigegebenen Bereich. |
| *darf schalten* | wirkt nur zusammen mit dem Recht „Geräte schalten“ (`steuern`) |
| *gültig ab/bis* | wann die Freigabe wirkt |
| *Daten ab/bis* | welche Messdaten sichtbar sind (siehe oben) |

## Schalten – je Gerät einzeln

Ein Schaltbefehl (Shelly ein/aus, Victron Modus, ComAp Start/Stopp …) wird
nur ausgeführt, wenn **alle drei** Bedingungen erfüllt sind:

1. Der Benutzer hat das Recht **`steuern`** („Geräte schalten“).
2. An der Anlage ist die Steuerung **nicht gesperrt**. Die Sperre setzt man
   an der Anlage selbst, z. B. für das Aggregat, das nie aus der Ferne
   gestoppt werden darf. Sie gilt auch für Administratoren.
3. Es gibt eine **wirksame Freigabe mit „darf schalten“**, die das Gerät
   abdeckt. Das kann eine Freigabe für alles, für den Standort, für die
   Anlage oder **genau für dieses Gerät** sein, optional nur für einen
   **Kanal**. Ausnahme: `anlagen.scope.all` ersetzt diese Bedingung.

So bekommt die Elektrikerin auf dem Gelände eine Schalt-Freigabe nur für den
Shelly der Geländebeleuchtung (oder nur dessen `switch:0`). Alles andere
sieht sie, kann es aber nicht schalten.

Jeder Versuch landet im **Schaltprotokoll** (*Auswertung → Schaltprotokoll*):
Benutzer, Gerät, Kanal, Befehl, Ergebnis. Abgelehnte Versuche werden
ebenfalls protokolliert. Schaltknöpfe erscheinen in der Oberfläche nur, wo
alle Bedingungen erfüllt sind. Die Prüfung läuft trotzdem serverseitig bei
jedem Befehl (`Steuerung::ausfuehren()` → `Zugriff::darfSteuern()`).

## Aufbau

Einen Aufbau und seinen Abgleich sieht, wer den Standort sieht; die Last
im Abgleich ist auf die Freigaben beschnitten wie überall. Starten, fertig
melden und beenden darf, wer auf dem Standort Anlagen anlegen darf. Eine
Anlage bestätigen darf, wer sie bearbeiten darf; ein Kabel, wer Kabel
zeichnen darf.

## Inventar

Das Inventar (Verteiler und Zwischenstecker mit Shellys) gehört keinem
Standort. Ansehen darf es, wer „Datenquellen ansehen“ hat (Planung,
Technik), pflegen, wer „Inventar pflegen“ hat. Ein Stück **einsetzen** oder
**abbauen** ist eine Änderung an der Anlage: dafür braucht es „Anlagen
ändern“ und eine Freigabe mit „bearbeiten“ für diese Anlage. Messgeräte aus
einem Einsatz zählen für Datenfenster und Schalt-Freigaben nur, solange sie
dort stecken.

## Bereiche

Einen Bereich sieht, wer seinen Standort sieht. In die Summe kommen aber nur
Mitglieder, deren Anlage er sehen darf, und nur Messwerte aus dem
Datenfenster seiner Freigabe; fehlt etwas, steht das als Hinweis an der
Kachel („… nicht freigegeben – die Summe ist unvollständig“). Anlegen,
ändern und löschen darf, wer das Recht „Anlagen ändern“ hat und auf dem
Standort Anlagen anlegen darf. Verlauf und CSV brauchen wie sonst
„Statistik“ bzw. „Export“.

## Demo-Zugänge

Im Demo-Modus legt `bin/power demo:seed` zwei Beispielkonten an:

| Benutzer | Kennwort | zeigt |
|----------|----------|-------|
| `veranstalter` | `Veranstalter2026!` | Rolle Kunde. Freigabe nur für das Festivalgelände und nur für die Festivalwoche. Konto läuft nach 10 Tagen ab. |
| `technik` | `Technik2026!!` | Rolle Elektrotechnik. Bearbeiten auf dem Festivalgelände, schalten nur beim Shelly der Geländebeleuchtung. |
