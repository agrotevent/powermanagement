<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Berichte\Kanon;
use App\Pdf\Schreiber;
use App\Pdf\Tabelle;

/**
 * Das PDF eines Prüfberichts der Geräteprüfung: „Prüfprotokoll
 * ortsveränderliche elektrische Betriebsmittel (DGUV Vorschrift 3)“ auf A4.
 *
 * Kopf auf jeder Seite (Titel, Firma, Nummer und Fassung), Blöcke
 * Auftragnehmer und Auftraggeber, Prüfling, Prüfung mit Prüfgerät und
 * Zubehör, Sichtprüfung, Messungen (Tabelle Schritt | Messwert | Grenzwert |
 * Bewertung, Kopf auf jeder Seite), Ergebnis groß mit Symbol und Wort,
 * nächste Prüfung, Unterschrift des Prüfers mit Name, Qualifikation, Ort und
 * Datum. Fuß auf jeder Seite: Firma, Nummer, Kurz-Prüfsumme, „Blatt x von y“.
 *
 * Gelesen wird nur der eingefrorene Inhalt und die Striche der
 * Unterschrift – keine Einstellungen, keine Uhr, kein Zufall, keine
 * Zeitzone (alle Daten stehen im Inhalt schon als Ortsdatum): zweimal
 * erzeugt, dieselben Bytes. CreationDate ist `eingefroren_am`, die
 * Dokument-ID kommt aus dem `inhalt_hash`. Gebaut mit App\Pdf\Schreiber und
 * Tabelle, nur mit deren vorhandenen Methoden (die beiden ändern hieße, die
 * Bytes alter Übergabeberichte zu ändern).
 */
final class PruefberichtPdf
{
    /**
     * Kennung dieses Erzeugers – bei JEDER Änderung, die die Bytes eines
     * PDF ändert (Layout, Texte, Schreiber, Schrift), hochzählen. Die
     * Integritätsprüfung (geraetepruefung:pruefen --pdf) wertet eine
     * abweichende Neuerzeugung bei gleichem Erzeuger als Befund, bei einem
     * älteren nur als Hinweis.
     */
    public const ERZEUGER = 'pm-gp-pdf-2';

    public const TITEL = 'Prüfprotokoll';
    public const UNTERTITEL = 'ortsveränderliche elektrische Betriebsmittel (DGUV Vorschrift 3)';

    private const LINKS = 15.0;
    private const RECHTS = 195.0;
    private const BREITE = 180.0;
    private const OBEN = 41.0;
    private const UNTEN = 268.0;
    private const FUSS = 272.0;
    private const LABEL = 45.0;
    private const POLSTER_X = 1.2;
    private const POLSTER_Y = 0.7;
    private const PT = 8.0;

    private Schreiber $pdf;
    /** @var array<string,mixed> eingefrorener Inhalt */
    private array $i;
    private float $y = 0.0;
    private string $abschnitt = '';

    /**
     * @param array<string,mixed> $bericht Zeile mit `inhalt` (Array), `inhalt_hash`, `eingefroren_am`, `nummer`, `fassung`
     * @param list<list<array{0:int,1:int}>> $striche Unterschrift des Prüfers
     */
    private function __construct(private array $bericht, private array $striche)
    {
        $this->i = is_array($bericht['inhalt'] ?? null) ? $bericht['inhalt'] : [];
    }

    /** Die Bytes des PDF. */
    public static function erzeugen(array $bericht, array $striche): string
    {
        return (new self($bericht, $striche))->bauen();
    }

    /** Dateiname ohne Kundendaten: „GP-2026-00042.pdf“ bzw. „GP-2026-00042-K1.pdf“. */
    public static function dateiname(array $bericht): string
    {
        $nummer = (string) ($bericht['nummer'] ?? '');
        return (string) preg_replace('/[^A-Za-z0-9-]/', '-', $nummer !== '' ? $nummer : 'Pruefbericht') . '.pdf';
    }

    private function bauen(): string
    {
        $this->pdf = new Schreiber([
            'Title'   => self::TITEL . ' ' . $this->nummer(),
            'Author'  => $this->text('auftragnehmer', 'name'),
            'Subject' => self::TITEL . ' ' . self::UNTERTITEL . ($this->fassung() > 1 ? ' – Korrektur (Fassung ' . $this->fassung() . ')' : ''),
        ]);
        $this->neueSeite();
        $this->korrektur();
        $this->kopfblock();
        $this->sicht();
        $this->messungen();
        $this->ergebnis();
        $this->unterschrift();
        $this->hinweise();

        $gesamt = $this->pdf->seitenzahl();
        for ($s = 0; $s < $gesamt; $s++) {
            $this->pdf->aufSeite($s);
            $this->fuss($s + 1, $gesamt);
        }
        return $this->pdf->ausgabe($this->erzeugtAm(), $this->dokumentId());
    }

    // ================================================================ Blöcke

    private function korrektur(): void
    {
        if ($this->fassung() <= 1) {
            return;
        }
        $v = is_array($this->i['vorgaenger'] ?? null) ? $this->i['vorgaenger'] : [];
        $text = 'Korrektur (Fassung ' . $this->fassung() . ') – ersetzt Fassung ' . self::s($v['fassung'] ?? '?') . ' (' . self::s($v['nummer'] ?? '–') . ')'
            . (self::s($v['inhalt_hash'] ?? '') !== '' ? ', Prüfsumme ' . self::kurz(self::s($v['inhalt_hash'])) : '');
        $grund = self::s($v['grund'] ?? '');
        $this->zeile([['b' => self::BREITE, 'grau' => 0.95, 'teile' => [['t' => $text, 'fett' => true], ['t' => 'Grund: ' . ($grund !== '' ? $grund : '–')]]]]);
        $this->y += 2;
    }

    private function kopfblock(): void
    {
        $f = is_array($this->i['auftragnehmer'] ?? null) ? $this->i['auftragnehmer'] : [];
        $a = is_array($this->i['auftraggeber'] ?? null) ? $this->i['auftraggeber'] : [];
        $l = is_array($this->i['pruefling'] ?? null) ? $this->i['pruefling'] : [];
        $p = is_array($this->i['pruefung'] ?? null) ? $this->i['pruefung'] : [];
        $g = is_array($this->i['pruefgeraet'] ?? null) ? $this->i['pruefgeraet'] : [];

        $this->abschnitt('Auftrag');
        $this->kopfZeile('Auftragnehmer', [['t' => self::s($f['name'] ?? '') !== '' ? self::s($f['name']) : '–', 'fett' => true],
            ['t' => self::einzeilig(self::ohneName(self::s($f['anschrift'] ?? ''), self::s($f['name'] ?? '')))]]);
        $this->kopfZeile('Auftraggeber', [['t' => self::s($a['name'] ?? ''), 'fett' => true], ['t' => self::einzeilig(self::s($a['anschrift'] ?? ''))],
            ['t' => self::s($a['kundennummer'] ?? '') !== '' ? 'Kundennummer ' . self::s($a['kundennummer']) : '']]);
        $this->abschnittEnde();

        $this->abschnitt('Prüfling');
        $this->kopfZeile('Prüflings-ID', [['t' => self::s($l['kennung'] ?? ''), 'fett' => true]], 'Bezeichnung', [['t' => self::s($l['bezeichnung'] ?? '')]]);
        $this->kopfZeile('Hersteller / Typ / Modell', [['t' => self::verbinden([self::s($l['hersteller'] ?? ''), self::s($l['typ'] ?? ''), self::s($l['modell'] ?? '')], ' / ')]],
            'Seriennummer', [['t' => self::s($l['seriennummer'] ?? '')]]);
        $sk = self::s($l['schutzklasse'] ?? '');
        $leitung = self::verbinden([
            self::s($l['leitungslaenge'] ?? '') !== '' ? Messarten::anzeige(self::s($l['leitungslaenge'])) . ' m' : '',
            self::s($l['querschnitt'] ?? '') !== '' ? Messarten::anzeige(self::s($l['querschnitt'])) . ' mm²' : '',
            self::s($l['leiter'] ?? '') !== '' ? self::s($l['leiter']) . ' Leiter' : '',
        ]);
        $this->kopfZeile('Schutzklasse', [['t' => match ($sk) { '1' => 'I', '2' => 'II', '3' => 'III', default => $sk }]],
            'Leitung', [['t' => $leitung]]);
        $standort = self::verbinden([self::s($l['abteilung'] ?? ''), self::s($l['gebaeude'] ?? '')]);
        $mehr = self::verbinden([
            self::s($l['nennleistung'] ?? '') !== '' ? 'Nennleistung ' . Messarten::anzeige(self::s($l['nennleistung'])) . ' W' : '',
            self::s($l['inventar'] ?? '') !== '' ? 'Inventar ' . self::s($l['inventar']) : '',
        ]);
        if ($standort !== '' || $mehr !== '') {
            $this->kopfZeile('Abteilung / Gebäude', [['t' => $standort]], 'Weitere Angaben', [['t' => $mehr]]);
        }
        $this->abschnittEnde();

        $this->abschnitt('Prüfung');
        $this->kopfZeile('Prüfdatum', [['t' => self::datum(self::s($p['datum'] ?? '')) . (self::s($p['uhrzeit'] ?? '') !== '' ? ', ' . self::s($p['uhrzeit']) . ' Uhr' : ''), 'fett' => true]],
            'Prüfart', [['t' => self::s($p['pruefart_text'] ?? '')]]);
        $this->kopfZeile('Prüfung nach', [['t' => self::s($p['norm'] ?? '')], ['t' => 'DGUV Vorschrift 3', 'pt' => 7.0, 'grau' => 0.35]],
            'Prüfablauf', [['t' => self::s($p['ablauf_name'] ?? '')]]);
        $auftrag = self::verbinden([self::s($p['auftrag'] ?? '') !== '' ? 'Auftrag ' . self::s($p['auftrag']) : '', self::s($p['ort'] ?? '')]);
        if ($auftrag !== '' || self::s($p['bemerkung'] ?? '') !== '') {
            $this->kopfZeile('Auftrag / Ort', [['t' => $auftrag]], 'Bemerkung', [['t' => self::einzeilig(self::s($p['bemerkung'] ?? ''))]]);
        }
        $this->kopfZeile('Prüfer laut Prüfgerät', [['t' => self::s($p['pruefer_geraet'] ?? '') !== '' ? self::s($p['pruefer_geraet']) : '–'],
            ['t' => self::s($p['prueffirma'] ?? ''), 'pt' => 7.0, 'grau' => 0.35]]);
        $geraet = self::verbinden([
            self::s($g['bezeichnung'] ?? '') !== '' ? self::s($g['bezeichnung']) : trim(self::s($g['fabrikat'] ?? '') . ' ' . self::s($g['typ'] ?? '')),
            self::s($g['seriennummer'] ?? '') !== '' ? 'Nr. ' . self::s($g['seriennummer']) : '',
            self::s($g['firmware'] ?? '') !== '' ? 'Firmware ' . self::s($g['firmware']) : '',
        ], ', ');
        $kal = self::s($g['kalibriert_bis'] ?? '');
        $quelle = self::s($g['kalibrierung_quelle'] ?? '');
        $this->kopfZeile('Prüfgerät', [['t' => $geraet !== '' ? $geraet : '–'],
            ['t' => ($kal !== '' ? 'kalibriert bis ' . self::datum($kal) : 'Kalibrierung nicht bekannt') . ($quelle !== '' ? ' (' . $quelle . ')' : ''),
                'pt' => 7.0, 'grau' => $kal !== '' ? 0.0 : 0.35],
            ['t' => self::s($g['norm'] ?? ''), 'pt' => 7.0, 'grau' => 0.35]]);
        $zubehoer = [];
        foreach (is_array($this->i['zubehoer'] ?? null) ? $this->i['zubehoer'] : [] as $z) {
            if (is_array($z)) {
                $zubehoer[] = ['t' => self::verbinden([trim(self::s($z['fabrikat'] ?? '') . ' ' . self::s($z['typ'] ?? '')),
                    self::s($z['seriennummer'] ?? '') !== '' ? 'Nr. ' . self::s($z['seriennummer']) : '',
                    self::s($z['kalibriert_bis'] ?? '') !== '' ? 'kalibriert bis ' . self::datum(self::s($z['kalibriert_bis'])) : ''], ', ')];
            }
        }
        if ($zubehoer !== []) {
            $zQuelle = self::s($this->i['zubehoer_quelle'] ?? '');
            if ($zQuelle !== '') {
                $zubehoer[] = ['t' => $zQuelle, 'pt' => 7.0, 'grau' => 0.35];
            }
            $this->kopfZeile('Zubehör', $zubehoer);
        }
        $this->abschnittEnde();
    }

    private function sicht(): void
    {
        $sicht = is_array($this->i['sicht'] ?? null) ? $this->i['sicht'] : [];
        $antworten = array_values(array_filter(is_array($sicht['antworten'] ?? null) ? $sicht['antworten'] : [], 'is_array'));
        $gesamt = match (self::s($sicht['ergebnis'] ?? '')) {
            'ok'      => 'in Ordnung',
            'maengel' => 'Mängel',
            default   => 'keine Angabe',
        };
        $this->abschnitt('Sichtprüfung', $gesamt);
        if ($antworten === []) {
            $this->zeile([['b' => self::BREITE, 'teile' => [['t' => 'Sichtprüfung: ' . $gesamt . ' (keine Einzelfragen im Prüfgerät erfasst).', 'grau' => 0.3]]]]);
            $this->abschnittEnde();
            return;
        }
        $tabelle = new Tabelle($this->pdf, self::LINKS, $this->y, [
            ['titel' => ['Prüfpunkt'], 'breite' => 142.0],
            ['titel' => [''], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Bewertung'], 'breite' => 30.0],
        ], [], function (): float {
            $this->neueSeite();
            return $this->y;
        }, self::UNTEN);
        $tabelle->kopf();
        foreach ($antworten as $a) {
            $ok = self::s($a['bewertung'] ?? '') === 'ok';
            $tabelle->zeile([self::s($a['text'] ?? '') !== '' ? self::s($a['text']) : 'Frage ' . self::s($a['frage'] ?? ''),
                ['symbol' => $ok ? 'haken' : 'kreuz', 'teile' => []], ['teile' => [['t' => $ok ? 'in Ordnung' : 'nicht i. O.', 'fett' => !$ok]]]]);
        }
        $this->y = $tabelle->y();
        $this->abschnittEnde();
    }

    private function messungen(): void
    {
        $schritte = array_values(array_filter(is_array($this->i['schritte'] ?? null) ? $this->i['schritte'] : [], 'is_array'));
        $this->abschnitt('Messungen', 'Messwerte und Bewertung wie vom Prüfgerät', 14.0);
        if ($schritte === []) {
            $this->zeile([['b' => self::BREITE, 'teile' => [['t' => 'Keine Messschritte (nur Sichtprüfung).', 'grau' => 0.3]]]]);
            $this->abschnittEnde();
            return;
        }
        $tabelle = new Tabelle($this->pdf, self::LINKS, $this->y, [
            ['titel' => ['Schritt'], 'breite' => 48.0],
            ['titel' => ['Messwert'], 'breite' => 60.0],
            ['titel' => ['Grenzwert'], 'breite' => 42.0],
            ['titel' => [''], 'breite' => 8.0, 'ausrichtung' => 'mitte'],
            ['titel' => ['Bewertung'], 'breite' => 22.0],
        ], [], function (): float {
            $this->neueSeite();
            $this->abschnittsKopf('Messungen (Fortsetzung)', '');
            return $this->y;
        }, self::UNTEN);
        $tabelle->kopf();
        foreach ($schritte as $s) {
            $titel = self::s($s['titel'] ?? '');
            $art = self::s($s['art'] ?? '');
            $schritt = [['t' => $titel !== '' ? $titel : $art, 'fett' => true]];
            if ($art !== '' && $art !== $titel) {
                $schritt[] = ['t' => $art, 'pt' => 6.5, 'grau' => 0.35];
            }
            $werte = [];
            foreach (is_array($s['werte'] ?? null) ? $s['werte'] : [] as $w) {
                if (is_array($w)) {
                    $werte[] = ['t' => self::verbinden([self::s($w['name'] ?? ''), Messarten::anzeige(self::s($w['wert'] ?? ''))], ': ')
                        . (self::s($w['bewertung'] ?? '') === 'ok' ? '' : ' (nicht i. O.)'), 'fett' => self::s($w['bewertung'] ?? '') !== 'ok'];
                }
            }
            $grenzen = [];
            foreach (is_array($s['grenzwerte'] ?? null) ? $s['grenzwerte'] : [] as $g) {
                if (is_array($g)) {
                    $grenzen[] = ['t' => self::verbinden([self::s($g['name'] ?? ''), Messarten::anzeige(self::s($g['wert'] ?? ''))], ' ')];
                }
            }
            $ok = self::s($s['bewertung'] ?? '') === 'ok';
            $tabelle->zeile([
                ['teile' => $schritt],
                ['teile' => $werte !== [] ? $werte : [['t' => '–', 'grau' => 0.4]]],
                ['teile' => $grenzen !== [] ? $grenzen : [['t' => '–', 'grau' => 0.4]]],
                ['symbol' => $ok ? 'haken' : 'kreuz', 'teile' => []],
                ['teile' => [['t' => $ok ? 'i. O.' : 'nicht i. O.', 'fett' => !$ok]]],
            ]);
        }
        $this->y = $tabelle->y();
        $this->abschnitt = '';
        $this->y += 2.2;
    }

    private function ergebnis(): void
    {
        $bestanden = self::s($this->i['ergebnis'] ?? '') === 'bestanden';
        $h = 18.0;
        $this->platz($h + 2.0);
        $y0 = $this->y;
        $this->pdf->rechteck(self::LINKS, $y0, self::BREITE, $h, $bestanden ? 0.94 : 0.88, true, 0.4);
        $this->pdf->kaestchen(self::LINKS + 4.0, $y0 + 4.0, $bestanden ? 'io' : 'nio', 10.0);
        $this->pdf->text(self::LINKS + 18.0, $y0 + 7.5, 'Ergebnis der Prüfung', 8.0, false, 0.3);
        $this->pdf->text(self::LINKS + 18.0, $y0 + 14.5, $bestanden ? 'BESTANDEN' : 'NICHT BESTANDEN', 16.0, true);
        $this->pdf->text(self::LINKS + 100.0, $y0 + 7.5, 'Nächste Prüfung', 8.0, false, 0.3);
        foreach (array_slice($this->pdf->umbruch(self::s($this->i['naechste_pruefung_text'] ?? ''), 76.0, 9.5, true), 0, 2) as $n => $z) {
            $this->pdf->text(self::LINKS + 100.0, $y0 + 12.5 + $n * 4.2, $z, 9.5, true);
        }
        $this->y = $y0 + $h + 3.0;
        if (!$bestanden) {
            $this->zeile([['b' => self::BREITE, 'teile' => [['t' => 'Das Gerät darf erst nach Instandsetzung und bestandener Prüfung wieder benutzt werden.', 'fett' => true]]]]);
            $this->y += 2.0;
        }
    }

    private function unterschrift(): void
    {
        $pr = is_array($this->i['pruefer'] ?? null) ? $this->i['pruefer'] : [];
        $u = is_array($this->i['unterschrift'] ?? null) ? $this->i['unterschrift'] : [];
        $p = is_array($this->i['pruefung'] ?? null) ? $this->i['pruefung'] : [];
        $uebernommen = self::s($pr['verantwortlich_uebernommen'] ?? '') === 'ja';
        $links = [
            ['t' => 'Prüfer', 'pt' => 9.0, 'fett' => true],
            ['t' => self::s($u['name'] ?? $pr['name'] ?? ''), 'pt' => 8.5, 'fett' => true],
            ['t' => self::s($pr['qualifikation'] ?? ''), 'pt' => 7.5],
            ['t' => 'Ort, Datum: ' . self::verbinden([self::s($u['ort'] ?? ''), self::datum(self::s($u['datum'] ?? ''))], ', '), 'pt' => 7.5],
            ['t' => 'Konto ' . (self::s($pr['konto'] ?? '') !== '' ? self::s($pr['konto']) : '–') . (self::s($pr['user_id'] ?? '') !== '' ? ' (#' . (int) self::s($pr['user_id']) . ')' : ''),
                'pt' => 6.8, 'grau' => 0.35],
        ];
        if ($uebernommen) {
            $links[] = ['t' => 'Prüfer laut Prüfgerät: ' . (self::s($p['pruefer_geraet'] ?? '') !== '' ? self::s($p['pruefer_geraet']) : '–')
                . ' – verantwortlich: ' . self::s($u['name'] ?? ''), 'pt' => 7.2, 'fett' => true];
        }
        $zeilen = $this->zeilen($links, 86.0);
        $h = max(30.0, $this->hoehe($zeilen) + 4.0);
        $this->platz($h + 2.0);
        $y0 = $this->y;
        $this->pdf->rechteck(self::LINKS, $y0, self::BREITE, $h);
        $this->schreiben($zeilen, self::LINKS + 2.0, $y0 + 2.0);
        $x = self::LINKS + 92.0;
        if ($this->striche !== []) {
            $this->pdf->unterschrift($this->striche, $x, $y0 + 2.0, 84.0, $h - 10.0);
        }
        $this->pdf->linie($x, $y0 + $h - 6.5, self::RECHTS - 2.0, $y0 + $h - 6.5, 0.2);
        $this->pdf->text($x, $y0 + $h - 3.0, 'Unterschrift des Prüfers', 6.5, false, 0.4);
        $this->y = $y0 + $h + 3.0;
    }

    private function hinweise(): void
    {
        $hinweise = array_values(array_filter(array_map(static fn (mixed $h): string => self::s($h), is_array($this->i['hinweise'] ?? null) ? $this->i['hinweise'] : []),
            static fn (string $h): bool => $h !== ''));
        $teile = [];
        foreach ($hinweise as $h) {
            $teile[] = ['t' => 'Hinweis: ' . $h, 'pt' => 7.0, 'grau' => 0.3];
        }
        $teile[] = ['t' => 'Messwerte, Grenzwerte und Bewertung stammen unverändert aus dem Prüfgerät.', 'pt' => 6.5, 'grau' => 0.35];
        $teile[] = ['t' => 'Prüfsumme des eingefrorenen Inhalts (SHA-256): ' . self::s($this->bericht['inhalt_hash'] ?? ''), 'pt' => 6.5, 'grau' => 0.35];
        $zeilen = $this->zeilen($teile, self::BREITE);
        $this->platz($this->hoehe($zeilen));
        $this->y = $this->schreiben($zeilen, self::LINKS, $this->y);
    }

    // ================================================================ Seiten

    private function neueSeite(): void
    {
        $this->pdf->seite();
        $this->pdf->text(self::LINKS, 22.0, self::TITEL, 18.0, true);
        $this->pdf->text(self::LINKS, 28.5, self::UNTERTITEL, 9.0);
        if ($this->fassung() > 1) {
            $this->pdf->text(self::LINKS, 34.0, 'Korrektur (Fassung ' . $this->fassung() . ')', 9.0, true);
        }
        $firma = $this->text('auftragnehmer', 'name');
        if ($firma !== '') {
            $pt = 12.0;
            while ($pt > 8.0 && $this->pdf->breite($firma, $pt, true) > 85.0) {
                $pt -= 0.5;
            }
            $this->pdf->textRechts(self::RECHTS, 20.5, $this->pdf->umbruch($firma, 85.0, $pt, true)[0], $pt, true);
        }
        $zusatz = $this->text('auftragnehmer', 'zusatz');
        if ($zusatz !== '') {
            $this->pdf->textRechts(self::RECHTS, 25.0, $this->pdf->umbruch(self::einzeilig($zusatz), 85.0, 7.5)[0], 7.5);
        }
        $this->pdf->textRechts(self::RECHTS, 34.0, 'Prüfprotokoll Nr. ' . $this->nummer() . ' · Fassung ' . $this->fassung(), 8.0, true);
        $this->pdf->linie(self::LINKS, 36.5, self::RECHTS, 36.5, 0.4);
        $this->y = self::OBEN;
    }

    private function fuss(int $blatt, int $gesamt): void
    {
        $this->pdf->linie(self::LINKS, self::FUSS, self::RECHTS, self::FUSS, 0.3);
        $f = is_array($this->i['auftragnehmer'] ?? null) ? $this->i['auftragnehmer'] : [];
        $spalten = [
            [['t' => self::s($f['name'] ?? ''), 'pt' => 6.5, 'fett' => true], ['t' => self::ohneName(self::s($f['anschrift'] ?? ''), self::s($f['name'] ?? '')), 'pt' => 6.5]],
            [['t' => self::s($f['kontakt'] ?? ''), 'pt' => 6.5]],
            [['t' => self::s($f['bank'] ?? ''), 'pt' => 6.5]],
            [['t' => self::s($f['register'] ?? ''), 'pt' => 6.5]],
        ];
        foreach ($spalten as $n => $teile) {
            $zeilen = array_slice($this->zeilen($teile, 43.0), 0, 5);
            $this->schreiben($zeilen, self::LINKS + 45.0 * $n, self::FUSS + 1.0);
        }
        $hash = self::s($this->bericht['inhalt_hash'] ?? '');
        $this->pdf->text(self::LINKS, 291.5, self::verbinden([$this->nummer(), 'Fassung ' . $this->fassung(), $hash !== '' ? 'Prüfsumme ' . self::kurz($hash) : '']),
            6.0, false, 0.25);
        $this->pdf->textRechts(self::RECHTS, 291.5, 'Blatt ' . $blatt . ' von ' . $gesamt, 6.0, false, 0.25);
    }

    // ================================================================ Bausteine (wie BerichtPdf)

    private function platz(float $mm): void
    {
        if ($this->y + $mm <= self::UNTEN) {
            return;
        }
        $this->neueSeite();
        if ($this->abschnitt !== '') {
            $this->abschnittsKopf($this->abschnitt . ' (Fortsetzung)', '');
        }
    }

    private function abschnitt(string $titel, string $zusatz = '', float $zusammen = 7.0): void
    {
        $this->abschnitt = '';
        $this->platz(5.0 + $zusammen);
        $this->abschnitt = $titel;
        $this->abschnittsKopf($titel, $zusatz);
    }

    private function abschnittsKopf(string $titel, string $zusatz): void
    {
        $this->pdf->rechteck(self::LINKS, $this->y, self::BREITE, 5.0, 0.86);
        $this->pdf->text(self::LINKS + self::POLSTER_X, $this->y + 3.6, $titel, 9.0, true);
        if ($zusatz !== '') {
            $this->pdf->text(self::LINKS + self::POLSTER_X + $this->pdf->breite($titel, 9.0, true) + 2.0, $this->y + 3.6, $zusatz, 6.8, false, 0.3);
        }
        $this->y += 5.0;
    }

    private function abschnittEnde(): void
    {
        $this->abschnitt = '';
        $this->y += 2.2;
    }

    /** @param list<array<string,mixed>> $zellen */
    private function zeile(array $zellen, float $min = 4.8): void
    {
        $inhalt = [];
        $h = $min;
        foreach ($zellen as $n => $z) {
            $inhalt[$n] = $this->zeilen((array) ($z['teile'] ?? []), (float) $z['b'] - 2 * self::POLSTER_X);
            $h = max($h, $this->hoehe($inhalt[$n]) + 2 * self::POLSTER_Y);
        }
        $this->platz($h);
        $x = self::LINKS;
        foreach ($zellen as $n => $z) {
            $b = (float) $z['b'];
            $this->pdf->rechteck($x, $this->y, $b, $h, isset($z['grau']) ? (float) $z['grau'] : null);
            $this->schreiben($inhalt[$n], $x + self::POLSTER_X, $this->y + self::POLSTER_Y);
            $x += $b;
        }
        $this->y += $h;
    }

    /** Beschriftung (grau) und Wert – oder zwei Paare nebeneinander. */
    private function kopfZeile(string $label, array $teile, ?string $label2 = null, array $teile2 = []): void
    {
        $filter = static fn (array $t): array => array_values(array_filter($t, static fn (array $x): bool => trim((string) $x['t']) !== ''));
        $teile = $filter($teile) ?: [['t' => '–', 'grau' => 0.4]];
        $l = ['b' => self::LABEL, 'grau' => 0.95, 'teile' => [['t' => $label, 'pt' => 7.2, 'fett' => true]]];
        if ($label2 === null) {
            $this->zeile([$l, ['b' => self::BREITE - self::LABEL, 'teile' => $teile]]);
            return;
        }
        $this->zeile([$l, ['b' => 52.0, 'teile' => $teile], ['b' => 28.0, 'grau' => 0.95, 'teile' => [['t' => $label2, 'pt' => 7.2, 'fett' => true]]],
            ['b' => self::BREITE - self::LABEL - 52.0 - 28.0, 'teile' => $filter($teile2) ?: [['t' => '–', 'grau' => 0.4]]]]);
    }

    /**
     * @param list<array{t:string,pt?:float,fett?:bool,grau?:float}> $teile
     * @return list<array{t:string,pt:float,fett:bool,grau:float}>
     */
    private function zeilen(array $teile, float $breite): array
    {
        $out = [];
        foreach ($teile as $t) {
            $text = (string) ($t['t'] ?? '');
            if (trim($text) === '') {
                continue;
            }
            $pt = (float) ($t['pt'] ?? self::PT);
            $fett = (bool) ($t['fett'] ?? false);
            foreach ($this->pdf->umbruch($text, max(5.0, $breite), $pt, $fett) as $z) {
                $out[] = ['t' => $z, 'pt' => $pt, 'fett' => $fett, 'grau' => (float) ($t['grau'] ?? 0.0)];
            }
        }
        return $out;
    }

    private function hoehe(array $zeilen): float
    {
        $h = 0.0;
        foreach ($zeilen as $z) {
            $h += Tabelle::zeilenhoehe($z['pt']);
        }
        return $h;
    }

    private function schreiben(array $zeilen, float $x, float $y): float
    {
        foreach ($zeilen as $z) {
            $h = Tabelle::zeilenhoehe($z['pt']);
            $this->pdf->text($x, $y + $h * 0.78, $z['t'], $z['pt'], $z['fett'], $z['grau']);
            $y += $h;
        }
        return $y;
    }

    // ================================================================ kleine Hilfen

    private function nummer(): string
    {
        $n = self::s($this->bericht['nummer'] ?? ($this->i['nummer'] ?? ''));
        return $n !== '' ? $n : '–';
    }

    private function fassung(): int
    {
        return max(1, (int) ($this->bericht['fassung'] ?? ($this->i['fassung'] ?? 1)));
    }

    private function text(string $block, string $feld): string
    {
        return is_array($this->i[$block] ?? null) ? trim(self::s($this->i[$block][$feld] ?? '')) : '';
    }

    private function erzeugtAm(): string
    {
        $am = self::s($this->bericht['eingefroren_am'] ?? '');
        return $am !== '' && strtotime($am) !== false ? $am : '2000-01-01T00:00:00+00:00';
    }

    private function dokumentId(): string
    {
        $hash = self::s($this->bericht['inhalt_hash'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            try {
                $hash = Kanon::hash($this->i);
            } catch (\Throwable) {
                $hash = hash('sha256', (string) json_encode($this->i));
            }
        }
        return substr($hash, 0, 32);
    }

    private static function s(mixed $w): string
    {
        return is_string($w) ? $w : (is_int($w) ? (string) $w : '');
    }

    private static function datum(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : ($d !== '' ? $d : '–');
    }

    /** Die ersten 16 Zeichen einer Prüfsumme in Vierergruppen. */
    private static function kurz(string $hash): string
    {
        return implode(' ', str_split(substr($hash, 0, 16), 4));
    }

    private static function verbinden(array $teile, string $mit = ' · '): string
    {
        return implode($mit, array_filter(array_map(static fn ($t): string => trim((string) $t), $teile), static fn (string $t): bool => $t !== ''));
    }

    private static function einzeilig(string $text): string
    {
        return self::verbinden(preg_split('/\R/u', $text) ?: [], ', ');
    }

    /**
     * Die Anschrift ohne erste Zeile, wenn die nur den Firmennamen wiederholt
     * (so steht sie oft in „firma.anschrift“) – der Name steht schon fett davor.
     */
    private static function ohneName(string $anschrift, string $name): string
    {
        $zeilen = preg_split('/\R/u', $anschrift) ?: [];
        $gleich = static fn (string $t): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $t)));
        if ($name !== '' && $zeilen !== [] && $gleich($zeilen[0]) === $gleich($name)) {
            array_shift($zeilen);
        }
        return implode("\n", $zeilen);
    }
}
