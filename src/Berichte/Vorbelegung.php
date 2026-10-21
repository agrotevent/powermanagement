<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Auth\Auth;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\SchutzService;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Was die App über die Anlage schon weiß, steht beim Anlegen im Bericht:
 * Veranstaltung und Zeitraum aus dem Aufbau, Anlage und Aufstellort, je
 * Abgang ein Stromkreis mit Steckverbinder, Überstromschutz und RCD aus
 * den Schutzorganen, das gesteckte Kabel aus dem Plan.
 *
 * **Messwerte nie.** Sie kommen vom Prüfgerät, sonst wäre der Bericht
 * kein Prüfprotokoll. Ebenso bleibt die Netzform leer – vorgeschlagen wird
 * nur die des letzten Berichts dieser Anlagen.
 *
 * Was die App nur erschlossen hat (Leiterzahl aus dem Steckverbinder),
 * steht in `abgeleitet` und ist im Formular als „aus Plan“ markiert, bis
 * der Prüfer das Feld anfasst.
 */
final class Vorbelegung
{
    private const QUELLEN = ['generator' => 'generator', 'anschluss' => 'fest', 'wechselrichter' => 'speicher'];

    /**
     * Kopf, Netz, Prüfgrundlage und Messung für einen neuen Bericht.
     *
     * @param list<array<string,mixed>> $objekte ausgewählte Anlagen (AnlagenService::find), Einspeisung zuerst
     * @return array{kopf:array,netz:array,grundlage:array,messung:array}
     */
    public static function kopf(?array $site, ?array $aufbau, array $objekte): array
    {
        $leer = BerichtSchema::leer();
        $kopf = $leer['kopf'];
        $netz = $leer['netz'];
        $user = Auth::user() ?? [];

        $kopf['veranstaltung'] = mb_substr((string) ($aufbau['name'] ?? ''), 0, 255);
        $kopf['zeitraum'] = $aufbau !== null ? self::zeitraum($aufbau) : '';
        $kopf['ort'] = self::ort($site);
        $kopf['qualifikation'] = mb_substr(trim((string) ($user['pruefer_qualifikation'] ?? '')), 0, 120);
        $kopf['projektleiter_montage'] = mb_substr(trim((string) (($user['full_name'] ?? '') !== '' ? $user['full_name'] : ($user['username'] ?? ''))), 0, 190);
        $kopf['bereitschaft'] = Einstellungen::wert('berichte.bereitschaft');
        if ($site !== null) {
            $kopf['anlage']['aufstellort'] = mb_substr(trim($site['name'] . "\n" . trim((string) ($site['address'] ?? ''))), 0, 1000);
        }

        $einspeisung = self::einspeisung($objekte);
        if ($einspeisung !== null) {
            $netz['einspeisung'] = self::QUELLEN[$einspeisung['art']] ?? '';
            $netz['spannung'] = (string) ((int) ($einspeisung['spannung_v'] ?? 0) ?: 400);
        }
        [$bezeichnung, $details] = self::anlage($einspeisung, $objekte);
        $kopf['anlage']['bezeichnung'] = mb_substr($bezeichnung, 0, 255);
        $kopf['anlage']['details'] = mb_substr($details, 0, 2000);

        $messung = $leer['messung'];
        $messung['riso_u'] = Einstellungen::wert('berichte.riso_u');
        $messung['geraete'] = self::letzteGeraete((string) $kopf['pruefdatum']);

        return [
            'kopf'      => $kopf,
            'netz'      => $netz,
            'grundlage' => ['normen' => Einstellungen::normen(), 'sonstige' => '', 'grund' => $aufbau !== null ? 'aufbau' : ''],
            'messung'   => $messung,
        ];
    }

    /**
     * Neue Zeilen für die Abgänge einer Anlage. Abgänge, die schon eine
     * Zeile haben (gleiche anschluss_id), werden übersprungen – „Zeilen
     * ergänzen“ überschreibt so nie eine Eingabe.
     *
     * @param list<array<string,mixed>> $vorhandene Kreise des Berichts
     * @return list<array<string,mixed>>
     */
    public static function kreise(int $objektId, array $vorhandene, int $startNr): array
    {
        $objekt = AnlagenService::find($objektId);
        if ($objekt === null) {
            return [];
        }
        $schon = [];
        foreach ($vorhandene as $k) {
            if ((string) ($k['anschluss_id'] ?? '') !== '') {
                $schon[(string) $k['anschluss_id']] = true;
            }
        }
        $alle = SchutzService::nachId($objektId);
        $einsatz = self::einsatz($objektId);
        $inventarId = $einsatz !== null ? (int) $einsatz['inventar_id'] : null;
        $nr = $startNr;
        $out = [];
        foreach (AnlagenService::anschluesse($objektId) as $a) {
            if ($a['richtung'] !== 'abgang' || isset($schon[(string) $a['id']])) {
                continue;
            }
            $schutzId = $a['schutzorgan_id'] !== null ? (int) $a['schutzorgan_id'] : 0;
            $ueb = $schutzId > 0 ? SchutzService::ueberstrom($schutzId, $alle) : null;
            $rcd = $schutzId > 0 ? SchutzService::rcd($schutzId, $alle) : null;
            $kabel = $a['leitung'];
            $gegenstelle = $kabel !== null && $kabel['gegenstelle_id'] !== null ? AnlagenService::find((int) $kabel['gegenstelle_id']) : null;
            $bezeichnung = trim((string) $a['bezeichnung']);

            $k = BerichtSchema::kreisLeer();
            $k['nr'] = (string) $nr++;
            $k['art'] = $gegenstelle !== null && $gegenstelle['art'] === 'verteilung' ? 'verteiler' : 'end';
            $k['objekt_id'] = (string) $objektId;
            $k['anschluss_id'] = (string) $a['id'];
            $k['inventar_id'] = $inventarId !== null ? (string) $inventarId : '';
            $k['abgang'] = mb_substr($bezeichnung, 0, 64);
            $k['ort'] = self::zeile(trim($objekt['name'] . ' ' . $bezeichnung) . ' · ' . $a['typ_name']
                . ((string) $a['phase'] !== '' ? ' ' . $a['phase'] : '')
                . ($rcd !== null && trim((string) $rcd['bezeichnung']) !== '' ? ' · ' . trim((string) $rcd['bezeichnung']) : '')
                . (trim((string) ($a['notizen'] ?? '')) !== '' ? ' · ' . trim((string) $a['notizen']) : ''), 255);
            $k['kabel'] = $kabel !== null ? self::zeile(self::kabelText($kabel), 255) : '';
            $k['leitung']['art'] = mb_substr((string) $a['typ_name'], 0, 64);

            $zuletzt = self::zuletzt($inventarId, (int) $a['id'], $bezeichnung);
            if (($zuletzt['leiter'] ?? '') !== '') {
                $k['leitung']['leiter'] = $zuletzt['leiter'];
            } elseif ($kabel !== null && (int) ($kabel['adern'] ?? 0) > 0) {
                $k['leitung']['leiter'] = (string) (int) $kabel['adern'];
            } else {
                $k['leitung']['leiter'] = (int) $a['typ_phasen'] === 1 ? '3' : '5';
                $k['abgeleitet'][] = 'leitung.leiter';
            }
            $k['leitung']['querschnitt'] = (string) ($zuletzt['querschnitt'] ?? '');

            if ($ueb !== null) {
                $k['ueb'] = [
                    'art'         => strtoupper((string) $ueb['art']),
                    'char'        => in_array((string) $ueb['charakteristik'], SchutzService::CHARAKTERISTIK, true) ? (string) $ueb['charakteristik'] : '',
                    'in'          => $ueb['nennstrom_a'] !== null ? Grenzwerte::dez((float) $ueb['nennstrom_a'], 1) : '',
                    'grenze_hand' => '',
                ];
            }
            if ($rcd !== null) {
                $k['rcd'] = [
                    'bez' => mb_substr(trim((string) $rcd['bezeichnung']), 0, 32),
                    'in'  => $rcd['nennstrom_a'] !== null ? Grenzwerte::dez((float) $rcd['nennstrom_a'], 1) : '',
                    'typ' => in_array((string) $rcd['rcd_typ'], SchutzService::RCD_TYPEN, true) ? (string) $rcd['rcd_typ'] : '',
                    'idn' => $rcd['fehlerstrom_ma'] !== null ? (string) (int) $rcd['fehlerstrom_ma'] : '',
                    's'   => '0',
                ];
            }
            $out[] = $k;
        }
        return $out;
    }

    /**
     * Leiter und Querschnitt aus dem letzten abgeschlossenen Bericht: zuerst
     * dasselbe Inventarstück am gleich benannten Abgang (der Verteiler ist
     * innen gleich verdrahtet, wo er auch steht), sonst derselbe Anschluss.
     *
     * @return array{leiter:string,querschnitt:string}|null
     */
    public static function zuletzt(?int $inventarId, int $anschlussId, string $abgang): ?array
    {
        $treffer = static function (array $kreis): ?array {
            $leiter = (string) ($kreis['leitung']['leiter'] ?? '');
            $querschnitt = (string) ($kreis['leitung']['querschnitt'] ?? '');
            return $leiter === '' && $querschnitt === '' ? null : ['leiter' => $leiter, 'querschnitt' => $querschnitt];
        };
        if ($inventarId !== null && $inventarId > 0 && $abgang !== '') {
            foreach (self::abgeschlossene('bo.inventar_id = ?', [$inventarId]) as $inhalt) {
                foreach ($inhalt['kreise'] ?? [] as $kreis) {
                    if ((string) ($kreis['inventar_id'] ?? '') === (string) $inventarId && (string) ($kreis['abgang'] ?? '') === $abgang
                        && ($t = $treffer($kreis)) !== null) {
                        return $t;
                    }
                }
            }
        }
        $objektId = (int) Database::scalar('SELECT objekt_id FROM anschluesse WHERE id = ?', [$anschlussId]);
        if ($objektId > 0) {
            foreach (self::abgeschlossene('bo.objekt_id = ?', [$objektId]) as $inhalt) {
                foreach ($inhalt['kreise'] ?? [] as $kreis) {
                    if ((string) ($kreis['anschluss_id'] ?? '') === (string) $anschlussId && ($t = $treffer($kreis)) !== null) {
                        return $t;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Hinweise an den Prüfpunkten – nur zum Anzeigen. Gesetzt wird damit nie
     * etwas; ob ein Punkt in Ordnung ist, entscheidet der Prüfer.
     *
     * @return array<string,list<string>> Prüfpunkt → Hinweise
     */
    public static function hinweise(array $inhalt, ?int $siteId, ?int $aufbauId): array
    {
        $out = [];
        $kreise = is_array($inhalt['kreise'] ?? null) ? $inhalt['kreise'] : [];

        foreach ($kreise as $k) {
            $in = Grenzwerte::zahl($k['ueb']['in'] ?? '');
            $idn = Grenzwerte::zahl($k['rcd']['idn'] ?? '');
            if (($k['art'] ?? '') === 'end' && $in !== null && $in <= 32 && ($idn === null || $idn > 30)) {
                $out['rcd30'][] = 'Kreis ' . ($k['nr'] ?? '?') . ' (' . ($k['ort'] ?? '') . '): kein RCD ≤ 30 mA.';
            }
        }

        $objektIds = [];
        $inventarIds = [];
        foreach ($kreise as $k) {
            if ((string) ($k['objekt_id'] ?? '') !== '') {
                $objektIds[(int) $k['objekt_id']] = true;
            }
            if ((string) ($k['inventar_id'] ?? '') !== '') {
                $inventarIds[(int) $k['inventar_id']] = true;
            }
        }
        $heute = (string) ($inhalt['kopf']['pruefdatum'] ?? (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d'));
        foreach (array_keys($objektIds) as $id) {
            $o = AnlagenService::find($id);
            if ($o === null) {
                continue;
            }
            foreach (LeitungService::anObjekt($id) as $l) {
                foreach ($l['hinweise'] as $h) {
                    $out['auswahl'][] = AufbauService::leitungName($l) . ': ' . $h;
                }
            }
            $bis = self::pruefungBis((string) ($o['eigenschaften']['pruefung_bis'] ?? ''));
            if ($bis !== null && $bis < $heute) {
                $out['geraetepruefung'][] = $o['name'] . ': Prüffrist abgelaufen (' . $o['eigenschaften']['pruefung_bis'] . ').';
            }
            if (($o['status'] ?? '') === 'defekt') {
                $out['maengelfrei'][] = $o['name'] . ' ist als defekt markiert.';
            }
            $schutzart = (string) ($o['eigenschaften']['schutzart'] ?? '');
            if (preg_match('/IP\s*(\d)(\d)/i', $schutzart, $m) === 1 && ((int) $m[1] < 4 || (int) $m[2] < 4)) {
                $out['schutzart'][] = $o['name'] . ': Schutzart ' . strtoupper(trim($schutzart)) . ' – im Freien mindestens IP44.';
            }
        }
        foreach (array_keys($inventarIds) as $id) {
            $i = InventarService::find($id);
            if ($i !== null && (int) $i['defekt'] === 1) {
                $out['maengelfrei'][] = 'Inventar ' . $i['kennung'] . ' ist als defekt markiert.';
            }
        }
        if ($aufbauId !== null) {
            $aufbau = AufbauService::find($aufbauId);
            if ($aufbau !== null && ($siteId === null || (int) $aufbau['site_id'] === $siteId)) {
                $z = AufbauService::uebersicht($aufbau)['zaehler'];
                if (($z['offen'] ?? 0) > 0 || ($z['geaendert'] ?? 0) > 0) {
                    $out['dokumentation'][] = 'Aufbau „' . $aufbau['name'] . '“: ' . (int) ($z['offen'] ?? 0) . ' Positionen offen, '
                        . (int) ($z['geaendert'] ?? 0) . ' geändert.';
                }
            }
        }
        return array_map(static fn (array $l): array => array_values(array_unique($l)), $out);
    }

    /**
     * Netzform und Spannung des letzten abgeschlossenen Berichts dieser
     * Anlagen – nur als Vorschlag, gesetzt wird die Netzform nie.
     *
     * @param list<int> $objektIds
     * @return array{form:string,spannung:string,nummer:string,pruefdatum:string}|null
     */
    public static function netzVorschlag(array $objektIds): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $objektIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return null;
        }
        $row = Database::first(
            "SELECT b.nummer, b.pruefdatum, b.inhalt FROM berichte b
             WHERE b.status = 'abgeschlossen' AND b.id IN (SELECT bo.bericht_id FROM bericht_objekte bo WHERE bo.objekt_id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . '))
             ORDER BY b.abgeschlossen_am DESC, b.id DESC LIMIT 1',
            $ids
        );
        if ($row === null) {
            return null;
        }
        $netz = (json_decode((string) $row['inhalt'], true) ?: [])['netz'] ?? [];
        $form = (string) ($netz['form'] ?? '');
        if ($form === '') {
            return null;
        }
        return ['form' => $form, 'spannung' => (string) ($netz['spannung'] ?? ''), 'nummer' => (string) $row['nummer'], 'pruefdatum' => (string) $row['pruefdatum']];
    }

    /**
     * Das Inventarstück, das jetzt als diese Anlage steht: ein Verteiler aus
     * dem Inventar, sonst ein Zwischenstecker an der Einspeisung – nicht einer
     * an einem Abgang (der misst nur diesen Abgang).
     */
    public static function einsatz(int $objektId): ?array
    {
        $einsaetze = InventarService::einsaetzeAnObjekt($objektId);
        foreach ($einsaetze as $e) {
            if ($e['inventar_art'] === 'verteilung') {
                return $e;
            }
        }
        foreach ($einsaetze as $e) {
            if ($e['anschluss_id'] === null) {
                return $e;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Die Einspeisung: die erste ausgewählte Quelle, sonst das oberste Glied
     * der Speisung der ersten Anlage (fehlen Kabel im Plan: keine).
     */
    private static function einspeisung(array $objekte): ?array
    {
        foreach ($objekte as $o) {
            if (isset(self::QUELLEN[$o['art']])) {
                return $o;
            }
        }
        if ($objekte === []) {
            return null;
        }
        $kette = AnlagenService::speisung((int) $objekte[0]['id']);
        if ($kette === []) {
            return null;
        }
        $oben = AnlagenService::find((int) end($kette)['von_objekt_id']);
        return $oben !== null && isset(self::QUELLEN[$oben['art']]) ? $oben : null;
    }

    /** @return array{0:string,1:string} Bezeichnung und Details der Anlage */
    private static function anlage(?array $einspeisung, array $objekte): array
    {
        $details = [];
        if ($einspeisung !== null) {
            $modell = trim($einspeisung['hersteller'] . ' ' . $einspeisung['modell']);
            $leistung = $einspeisung['nennleistung_kva'] !== null ? Grenzwerte::komma((float) $einspeisung['nennleistung_kva'], 1) . ' kVA' : '';
            $bezeichnung = $einspeisung['name'] . ($modell !== '' ? ' – ' . $modell : '') . ($leistung !== '' ? ($modell !== '' ? ', ' : ' – ') . $leistung : '');
            $merkmale = [];
            if (trim((string) $einspeisung['kennung']) !== '') {
                $merkmale[] = trim((string) $einspeisung['kennung']);
            }
            if (trim((string) $einspeisung['seriennummer']) !== '') {
                $merkmale[] = 'SN ' . trim((string) $einspeisung['seriennummer']);
            }
            if ($merkmale !== []) {
                $details[] = $einspeisung['name'] . ': ' . implode(' · ', $merkmale);
            }
        } else {
            $bezeichnung = implode(', ', array_map(static fn (array $o): string => (string) $o['name'], $objekte));
        }
        $verteiler = [];
        foreach ($objekte as $o) {
            if ($o['art'] !== 'verteilung') {
                continue;
            }
            $merkmale = [];
            $einsatz = self::einsatz((int) $o['id']);
            if ($einsatz !== null) {
                $merkmale[] = (string) $einsatz['kennung'];
                $stueck = InventarService::find((int) $einsatz['inventar_id']);
                if ($stueck !== null && trim((string) $stueck['seriennummer']) !== '') {
                    $merkmale[] = 'SN ' . trim((string) $stueck['seriennummer']);
                }
            } elseif (trim((string) $o['kennung']) !== '') {
                $merkmale[] = trim((string) $o['kennung']);
            }
            $verteiler[] = $o['name'] . ($merkmale !== [] ? ' (' . implode(', ', $merkmale) . ')' : '');
        }
        if ($verteiler !== []) {
            $details[] = 'Verteiler: ' . implode(', ', $verteiler);
        }
        return [self::zeile($bezeichnung, 255), implode("\n", $details)];
    }

    /** „Aufbau ab 12.10.2026 08:00, Betrieb ab 13.10.2026 18:00, bis 16.10.2026 23:00“ */
    private static function zeitraum(array $aufbau): string
    {
        $teile = [];
        if (!empty($aufbau['gestartet_am'])) {
            $teile[] = 'Aufbau ab ' . Zeit::anzeige((string) $aufbau['gestartet_am']);
        }
        if (!empty($aufbau['betrieb_ab'])) {
            $teile[] = 'Betrieb ab ' . Zeit::anzeige((string) $aufbau['betrieb_ab']);
        }
        if (!empty($aufbau['beendet_am'])) {
            $teile[] = 'bis ' . Zeit::anzeige((string) $aufbau['beendet_am']);
        }
        return implode(', ', $teile);
    }

    /** Ort aus der Adresse des Standorts (Text nach der Postleitzahl), sonst aus den Einstellungen. */
    private static function ort(?array $site): string
    {
        $adresse = (string) ($site['address'] ?? '');
        if (preg_match('/\b\d{5}\s+([^,\n]+)/u', $adresse, $m) === 1) {
            return mb_substr(trim($m[1]), 0, 120);
        }
        return Einstellungen::wert('berichte.ort');
    }

    /** Die Prüfgeräte des letzten eigenen Berichts – nur aktive mit gültiger Kalibrierung. */
    private static function letzteGeraete(string $datum): array
    {
        $id = Auth::id();
        if ($id === null) {
            return [];
        }
        $inhalt = Database::scalar('SELECT inhalt FROM berichte WHERE erstellt_von = ? ORDER BY id DESC LIMIT 1', [$id]);
        $ids = is_string($inhalt) ? ((json_decode($inhalt, true) ?: [])['messung']['geraete'] ?? []) : [];
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $gid) {
            $g = Pruefgeraete::find((int) $gid);
            if ($g !== null && (int) $g['aktiv'] === 1 && !Pruefgeraete::abgelaufen($g, $datum)) {
                $out[] = (string) (int) $g['id'];
            }
        }
        return $out;
    }

    /** „H07RN-F 3G2,5, 25 m → Catering“ */
    private static function kabelText(array $kabel): string
    {
        $art = trim((string) $kabel['kabeltyp']);
        if ((int) ($kabel['adern'] ?? 0) > 0 || $kabel['querschnitt_mm2'] !== null) {
            $art .= ' ' . ((int) ($kabel['adern'] ?? 0) > 0 ? (int) $kabel['adern'] : '') . 'G'
                . ($kabel['querschnitt_mm2'] !== null ? Grenzwerte::komma((float) $kabel['querschnitt_mm2'], 1) : '');
        }
        $laenge = $kabel['laenge_m'] !== null ? (float) $kabel['laenge_m'] : (LeitungService::find((int) $kabel['id'])['laenge'] ?? null);
        $teile = array_filter([trim($art), $laenge !== null ? Grenzwerte::komma((float) $laenge, 1) . ' m' : '']);
        $text = implode(', ', $teile);
        $gegenstelle = trim((string) ($kabel['gegenstelle'] ?? ''));
        return trim($text . ($gegenstelle !== '' ? ' → ' . $gegenstelle : ''));
    }

    /** „03/2026“, „2026-03“ oder ein Datum → letzter gültiger Tag (JJJJ-MM-TT), sonst null. */
    private static function pruefungBis(string $text): ?string
    {
        $t = trim($text);
        if (preg_match('/^(\d{1,2})\s*\/\s*(\d{4})$/', $t, $m) === 1 || preg_match('/^(\d{4})-(\d{1,2})$/', $t, $n) === 1) {
            [$monat, $jahr] = isset($m[1]) ? [(int) $m[1], (int) $m[2]] : [(int) $n[2], (int) $n[1]];
            if ($monat < 1 || $monat > 12) {
                return null;
            }
            return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $jahr, $monat)))->modify('last day of this month')->format('Y-m-d');
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $t, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $t;
        }
        return null;
    }

    /** @return list<array> Inhalte der letzten abgeschlossenen Berichte zu einer Bedingung auf bericht_objekte */
    private static function abgeschlossene(string $bedingung, array $werte): array
    {
        $out = [];
        foreach (Database::select(
            "SELECT b.id, b.abgeschlossen_am, b.inhalt FROM berichte b
             WHERE b.status = 'abgeschlossen' AND b.id IN (SELECT bo.bericht_id FROM bericht_objekte bo WHERE {$bedingung})
             ORDER BY b.abgeschlossen_am DESC, b.id DESC LIMIT 10",
            $werte
        ) as $row) {
            $inhalt = json_decode((string) $row['inhalt'], true);
            if (is_array($inhalt)) {
                $out[] = $inhalt;
            }
        }
        return $out;
    }

    private static function zeile(string $text, int $hoechstens): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $hoechstens);
    }
}
