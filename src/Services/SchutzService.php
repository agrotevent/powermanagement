<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Database;

/**
 * Schutzorgane in Verteilungen und an Generatoren.
 *
 * Hauptschalter, RCD (FI), RCBO (FI/LS), Leitungsschutzschalter und
 * NH-Sicherungen bilden in jeder Verteilung einen Baum: die LS hängen hinter
 * einem RCD, die RCDs hinter dem Hauptschalter. Jeder Abgang hängt an genau
 * einem Schutzorgan – daraus lässt sich ablesen, welcher RCD eine Schuko-
 * Dose schützt, ohne die Tür der Verteilung zu öffnen.
 */
final class SchutzService
{
    public const ARTEN = [
        'hauptschalter' => 'Hauptschalter',
        'rcd'           => 'RCD (FI)',
        'rcbo'          => 'RCBO (FI/LS)',
        'ls'            => 'Leitungsschutzschalter',
        'nh'            => 'NH-Sicherung',
        'sonstiges'     => 'Sonstiges',
    ];

    public const KUERZEL = [
        'hauptschalter' => 'Q',
        'rcd'           => 'F',
        'rcbo'          => 'F',
        'ls'            => 'Q',
        'nh'            => 'F',
        'sonstiges'     => 'X',
    ];

    public const CHARAKTERISTIK = ['', 'B', 'C', 'D', 'K', 'Z', 'gG', 'gL', 'aM'];
    public const RCD_TYPEN = ['', 'A', 'F', 'B', 'B+', 'AC'];

    /** @return list<array<string,mixed>> in Baumreihenfolge, mit 'tiefe' */
    public static function liste(int $objektId): array
    {
        $alle = Database::select('SELECT * FROM schutzorgane WHERE objekt_id = ? ORDER BY reihenfolge, id', [$objektId]);
        $kinder = [];
        $ids = [];
        foreach ($alle as $s) {
            $ids[(int) $s['id']] = true;
        }
        foreach ($alle as $s) {
            $elter = $s['vorgeschaltet_id'] !== null && isset($ids[(int) $s['vorgeschaltet_id']]) ? (int) $s['vorgeschaltet_id'] : 0;
            $kinder[$elter][] = $s;
        }
        $out = [];
        $gehen = static function (int $elter, int $tiefe, array $pfad) use (&$gehen, &$out, $kinder): void {
            foreach ($kinder[$elter] ?? [] as $s) {
                $id = (int) $s['id'];
                if (isset($pfad[$id])) {
                    continue;
                }
                $s['tiefe'] = $tiefe;
                $s['text'] = self::text($s);
                $out[] = $s;
                $gehen($id, $tiefe + 1, $pfad + [$id => true]);
            }
        };
        $gehen(0, 0, []);
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function nachId(int $objektId): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM schutzorgane WHERE objekt_id = ?', [$objektId]) as $s) {
            $out[(int) $s['id']] = $s;
        }
        return $out;
    }

    /** „Q3 · LS C16 3-pol.“, „F1 · RCD 40 A 30 mA Typ A 4-pol.“ */
    public static function text(array $s): string
    {
        $teile = [];
        $art = (string) $s['art'];
        $teile[] = match ($art) {
            'hauptschalter' => 'Hauptschalter',
            'rcd'           => 'RCD',
            'rcbo'          => 'RCBO',
            'ls'            => 'LS',
            'nh'            => 'NH',
            default         => 'Schutz',
        };
        $strom = $s['nennstrom_a'] !== null ? rtrim(rtrim(number_format((float) $s['nennstrom_a'], 1, ',', ''), '0'), ',') : '';
        if (in_array($art, ['ls', 'rcbo'], true) && $strom !== '') {
            $teile[] = trim((string) $s['charakteristik']) . $strom;
        } elseif ($strom !== '') {
            $teile[] = trim(((string) $s['charakteristik'] !== '' ? $s['charakteristik'] . ' ' : '') . $strom . ' A');
        }
        if (in_array($art, ['rcd', 'rcbo'], true)) {
            if ($s['fehlerstrom_ma'] !== null) {
                $teile[] = (int) $s['fehlerstrom_ma'] . ' mA';
            }
            if ((string) $s['rcd_typ'] !== '') {
                $teile[] = 'Typ ' . $s['rcd_typ'];
            }
        }
        $teile[] = (int) $s['pole'] . '-pol.';
        $bezeichnung = trim((string) $s['bezeichnung']);
        return ($bezeichnung !== '' ? $bezeichnung . ' · ' : '') . implode(' ', $teile);
    }

    /**
     * Die Kette vom Schutzorgan bis nach oben, lesbar: „Q3 · LS C16 ← F1 · RCD 30 mA Typ A“.
     *
     * @param array<int,array<string,mixed>> $alle
     */
    public static function kette(int $id, array $alle): string
    {
        $teile = [];
        $gesehen = [];
        while (isset($alle[$id]) && !isset($gesehen[$id]) && count($teile) < 6) {
            $gesehen[$id] = true;
            $teile[] = self::text($alle[$id]);
            $id = (int) ($alle[$id]['vorgeschaltet_id'] ?? 0);
        }
        return implode(' ← ', $teile);
    }

    /** Der RCD, der dieses Schutzorgan (oder es selbst) schützt – oder null. */
    public static function rcd(int $id, array $alle): ?array
    {
        $gesehen = [];
        while (isset($alle[$id]) && !isset($gesehen[$id])) {
            $gesehen[$id] = true;
            if (in_array($alle[$id]['art'], ['rcd', 'rcbo'], true)) {
                return $alle[$id];
            }
            $id = (int) ($alle[$id]['vorgeschaltet_id'] ?? 0);
        }
        return null;
    }

    /**
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(int $objektId, ?int $id, array $daten): array
    {
        if (!self::mitStrom($objektId)) {
            return ['ok' => false, 'message' => 'Ein Tank hat keine Sicherungen.'];
        }
        $art = (string) ($daten['art'] ?? 'ls');
        if (!isset(self::ARTEN[$art])) {
            return ['ok' => false, 'message' => 'Unbekannte Art des Schutzorgans.'];
        }
        $vor = (int) ($daten['vorgeschaltet_id'] ?? 0);
        if ($vor > 0) {
            if (Database::first('SELECT id FROM schutzorgane WHERE id = ? AND objekt_id = ?', [$vor, $objektId]) === null) {
                $vor = 0;
            }
            // Kein Kreis: ein Schutzorgan hinter sich selbst oder hinter
            // einem, das selbst dahinter hängt.
            if ($id !== null && $vor > 0 && self::liegtDahinter($vor, $id, self::nachId($objektId))) {
                return ['ok' => false, 'message' => 'So entstünde ein Kreis: das vorgeschaltete Schutzorgan hängt selbst hinter diesem.'];
            }
        }
        $strom = trim(str_replace(',', '.', (string) ($daten['nennstrom_a'] ?? '')));
        $ma = trim((string) ($daten['fehlerstrom_ma'] ?? ''));
        $char = (string) ($daten['charakteristik'] ?? '');
        $typ = (string) ($daten['rcd_typ'] ?? '');
        $felder = [
            'art'              => $art,
            'bezeichnung'      => mb_substr(trim((string) ($daten['bezeichnung'] ?? '')), 0, 64),
            'nennstrom_a'      => $strom === '' ? null : (float) $strom,
            'charakteristik'   => in_array($char, self::CHARAKTERISTIK, true) ? $char : '',
            'fehlerstrom_ma'   => $ma === '' || !in_array($art, ['rcd', 'rcbo'], true) ? null : (int) $ma,
            'rcd_typ'          => in_array($art, ['rcd', 'rcbo'], true) && in_array($typ, self::RCD_TYPEN, true) ? $typ : '',
            'pole'             => in_array((int) ($daten['pole'] ?? 3), [1, 2, 3, 4], true) ? (int) $daten['pole'] : 3,
            'vorgeschaltet_id' => $vor > 0 ? $vor : null,
            'notizen'          => (string) ($daten['notizen'] ?? ''),
        ];
        if ($id !== null) {
            if (Database::first('SELECT id FROM schutzorgane WHERE id = ? AND objekt_id = ?', [$id, $objektId]) === null) {
                return ['ok' => false, 'message' => 'Dieses Schutzorgan gehört nicht zu der Anlage.'];
            }
            Database::update('schutzorgane', $id, $felder);
            Audit::log('schutz.update', 'objekt', (string) $objektId, ['schutzorgan' => $id]);
            return ['ok' => true, 'message' => 'Schutzorgan gespeichert.', 'id' => $id];
        }
        if ($felder['bezeichnung'] === '') {
            $felder['bezeichnung'] = self::naechsteBezeichnung($objektId, $art);
        }
        $felder['objekt_id'] = $objektId;
        $felder['reihenfolge'] = 1 + (int) Database::scalar('SELECT COALESCE(MAX(reihenfolge), 0) FROM schutzorgane WHERE objekt_id = ?', [$objektId]);
        $felder['created_at'] = gmdate('c');
        $id = Database::insert('schutzorgane', $felder);
        Audit::log('schutz.create', 'objekt', (string) $objektId, ['schutzorgan' => $id]);
        return ['ok' => true, 'message' => 'Schutzorgan angelegt.', 'id' => $id];
    }

    private static function liegtDahinter(int $kandidat, int $id, array $alle): bool
    {
        $gesehen = [];
        while (isset($alle[$kandidat]) && !isset($gesehen[$kandidat])) {
            if ($kandidat === $id) {
                return true;
            }
            $gesehen[$kandidat] = true;
            $kandidat = (int) ($alle[$kandidat]['vorgeschaltet_id'] ?? 0);
        }
        return false;
    }

    /**
     * Löschen: was dahinter hing, rückt an die Stelle davor; Abgänge
     * ebenso. Ein gelöschter RCD lässt die LS nicht ohne Platz im Baum.
     */
    public static function loeschen(int $objektId, int $id): array
    {
        $s = Database::first('SELECT * FROM schutzorgane WHERE id = ? AND objekt_id = ?', [$id, $objektId]);
        if ($s === null) {
            return ['ok' => false, 'message' => 'Dieses Schutzorgan gibt es nicht.'];
        }
        Database::execute('UPDATE schutzorgane SET vorgeschaltet_id = ? WHERE vorgeschaltet_id = ?', [$s['vorgeschaltet_id'], $id]);
        Database::execute('UPDATE anschluesse SET schutzorgan_id = ? WHERE schutzorgan_id = ?', [$s['vorgeschaltet_id'], $id]);
        Database::execute('UPDATE messpunkte SET schutzorgan_id = NULL WHERE schutzorgan_id = ?', [$id]);
        Database::delete('schutzorgane', $id);
        Audit::log('schutz.delete', 'objekt', (string) $objektId, ['schutzorgan' => $id]);
        return ['ok' => true, 'message' => 'Schutzorgan entfernt.'];
    }

    private static function naechsteBezeichnung(int $objektId, string $art): string
    {
        $kuerzel = self::KUERZEL[$art] ?? 'X';
        $max = 0;
        foreach (Database::select('SELECT bezeichnung FROM schutzorgane WHERE objekt_id = ?', [$objektId]) as $row) {
            if (preg_match('/^' . $kuerzel . '(\d+)$/', (string) $row['bezeichnung'], $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $kuerzel . ($max + 1);
    }

    /**
     * Schutzorgane von einer Anlage auf eine andere kopieren.
     *
     * @return array<int,int> alte ID => neue ID
     */
    public static function kopieren(int $vonId, int $nachId): array
    {
        $zuordnung = [];
        $alle = Database::select('SELECT * FROM schutzorgane WHERE objekt_id = ? ORDER BY id', [$vonId]);
        foreach ($alle as $s) {
            $zuordnung[(int) $s['id']] = Database::insert('schutzorgane', [
                'objekt_id'      => $nachId,
                'art'            => $s['art'],
                'bezeichnung'    => $s['bezeichnung'],
                'nennstrom_a'    => $s['nennstrom_a'],
                'charakteristik' => $s['charakteristik'],
                'fehlerstrom_ma' => $s['fehlerstrom_ma'],
                'rcd_typ'        => $s['rcd_typ'],
                'pole'           => (int) $s['pole'],
                'vorgeschaltet_id' => null,
                'notizen'        => $s['notizen'],
                'reihenfolge'    => (int) $s['reihenfolge'],
                'created_at'     => gmdate('c'),
            ]);
        }
        foreach ($alle as $s) {
            if ($s['vorgeschaltet_id'] !== null && isset($zuordnung[(int) $s['vorgeschaltet_id']])) {
                Database::update('schutzorgane', $zuordnung[(int) $s['id']], ['vorgeschaltet_id' => $zuordnung[(int) $s['vorgeschaltet_id']]]);
            }
        }
        return $zuordnung;
    }

    /**
     * Eine übliche Bestückung vorschlagen und anlegen.
     *
     * Für jeden Abgang ohne Schutzorgan: ein LS in Nennstromstärke des
     * Steckers (C-Charakteristik), bis 32 A zu je höchstens vier hinter
     * einem RCD 30 mA Typ A; ab 63 A ein eigener RCD 300 mA. Davor der
     * Hauptschalter in Stärke des Eingangs. Das ist ein Vorschlag, kein
     * Bemessungsnachweis – die Seite sagt das auch so.
     */
    public static function vorschlagen(int $objektId): array
    {
        if (!self::mitStrom($objektId)) {
            return ['ok' => false, 'message' => 'Ein Tank hat keine Sicherungen.'];
        }
        $anschluesse = Database::select(
            'SELECT a.*, t.strom_a, t.phasen FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id
             WHERE a.objekt_id = ? ORDER BY a.richtung, t.strom_a DESC, a.nr',
            [$objektId]
        );
        $eingang = 0.0;
        foreach ($anschluesse as $a) {
            if ($a['richtung'] === 'eingang') {
                $eingang = max($eingang, (float) $a['strom_a']);
            }
        }
        $offen = array_values(array_filter($anschluesse, static fn ($a) => $a['richtung'] === 'abgang' && $a['schutzorgan_id'] === null));
        if ($offen === []) {
            return ['ok' => false, 'message' => 'Alle Abgänge haben schon ein Schutzorgan.'];
        }

        $haupt = Database::first("SELECT id FROM schutzorgane WHERE objekt_id = ? AND art = 'hauptschalter' ORDER BY id LIMIT 1", [$objektId]);
        $hauptId = $haupt !== null ? (int) $haupt['id'] : (int) self::speichern($objektId, null, [
            'art' => 'hauptschalter', 'nennstrom_a' => $eingang > 0 ? $eingang : '', 'pole' => 4,
        ])['id'];

        $angelegt = 0;
        $rcd = null;
        $imRcd = 0;
        foreach ($offen as $a) {
            $strom = (float) $a['strom_a'];
            $pole = (int) $a['phasen'] === 1 ? 1 : 3;
            if ($strom >= 63) {
                $eigener = self::speichern($objektId, null, [
                    'art' => 'rcd', 'nennstrom_a' => $strom, 'fehlerstrom_ma' => 300, 'rcd_typ' => 'A', 'pole' => 4,
                    'vorgeschaltet_id' => $hauptId,
                ]);
                $ls = self::speichern($objektId, null, [
                    'art' => 'ls', 'nennstrom_a' => $strom, 'charakteristik' => 'C', 'pole' => 3,
                    'vorgeschaltet_id' => (int) $eigener['id'],
                ]);
                $angelegt += 2;
            } else {
                if ($rcd === null || $imRcd >= 4) {
                    $rcd = (int) self::speichern($objektId, null, [
                        'art' => 'rcd', 'nennstrom_a' => 40, 'fehlerstrom_ma' => 30, 'rcd_typ' => 'A', 'pole' => 4,
                        'vorgeschaltet_id' => $hauptId,
                    ])['id'];
                    $imRcd = 0;
                    $angelegt++;
                }
                $ls = self::speichern($objektId, null, [
                    'art' => 'ls', 'nennstrom_a' => $strom, 'charakteristik' => 'C', 'pole' => $pole,
                    'vorgeschaltet_id' => $rcd,
                ]);
                $imRcd++;
                $angelegt++;
            }
            Database::update('anschluesse', (int) $a['id'], ['schutzorgan_id' => (int) $ls['id']]);
        }
        Audit::log('schutz.vorschlag', 'objekt', (string) $objektId, ['angelegt' => $angelegt]);
        return ['ok' => true, 'message' => $angelegt . ' Schutzorgane vorgeschlagen und den Abgängen zugeordnet. Bitte mit der tatsächlichen Bestückung abgleichen.'];
    }

    /** Führt die Anlage Strom? (Ein Tank nicht – er hat keine Schutzorgane.) */
    private static function mitStrom(int $objektId): bool
    {
        return AnlagenService::elektrisch((string) Database::scalar('SELECT art FROM objekte WHERE id = ?', [$objektId]));
    }
}
