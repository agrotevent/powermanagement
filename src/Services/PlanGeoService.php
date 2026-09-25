<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Database;

/**
 * Die Georeferenz eines Grundrisses.
 *
 * Zwei Passpunkte verbinden den Plan mit der Welt: je ein Punkt auf dem Plan
 * (Prozent) und seine Lage in Breite/Länge (WGS84). Daraus folgen Maßstab,
 * Drehung und Verschiebung des Plans (eine Ähnlichkeitsabbildung) – genug
 * für jeden maßstäblichen Plan. Damit bekommt jede Anlage Koordinaten, zu
 * denen man beim Aufbau navigieren kann, das Handy zeigt seine GPS-Position
 * im Plan, und ohne eigenen Maßstab stimmen trotzdem die Kabellängen.
 *
 * Gerechnet wird in einer Ebene um Passpunkt A (Meter nach Osten und
 * Norden). Für ein Veranstaltungsgelände von einigen Kilometern ist der
 * Fehler davon weit unter einem Meter.
 *
 * Plankoordinaten wie beim Maßstab in Breiten-Einheiten: x bleibt, y wird
 * mit dem Seitenverhältnis (Höhe durch Breite) gestreckt.
 */
final class PlanGeoService
{
    /** Mittlerer Erdradius in Metern. */
    private const ERDRADIUS = 6371008.8;

    /** Kürzer (in Metern) ist kein brauchbarer Abstand zweier Passpunkte. */
    public const MINDESTABSTAND_M = 5.0;

    /** Weiter auseinander (in Metern) liegt kein Gelände. */
    public const HOECHSTABSTAND_M = 50000.0;

    /** @var array<int,?array<string,mixed>> Georeferenz je Etage (für Listen) */
    private static array $jeEtage = [];

    /**
     * Die Georeferenz einer Etage – oder null.
     *
     * @param array<string,mixed> $floor Zeile aus floors
     * @return array{a:array{x:float,y:float,lat:float,lon:float},b:array{x:float,y:float,lat:float,lon:float},verhaeltnis:float,meter:float,von:string,am:string}|null
     */
    public static function lesen(array $floor): ?array
    {
        foreach (['geo_ax', 'geo_ay', 'geo_a_lat', 'geo_a_lon', 'geo_bx', 'geo_by', 'geo_b_lat', 'geo_b_lon', 'geo_verhaeltnis'] as $feld) {
            if (!isset($floor[$feld]) || $floor[$feld] === '') {
                return null;
            }
        }
        if ((float) $floor['geo_verhaeltnis'] <= 0) {
            return null;
        }
        $a = ['x' => (float) $floor['geo_ax'], 'y' => (float) $floor['geo_ay'], 'lat' => (float) $floor['geo_a_lat'], 'lon' => (float) $floor['geo_a_lon']];
        $b = ['x' => (float) $floor['geo_bx'], 'y' => (float) $floor['geo_by'], 'lat' => (float) $floor['geo_b_lat'], 'lon' => (float) $floor['geo_b_lon']];
        return [
            'a'           => $a,
            'b'           => $b,
            'verhaeltnis' => (float) $floor['geo_verhaeltnis'],
            'meter'       => round(self::entfernung($a['lat'], $a['lon'], $b['lat'], $b['lon']), 2),
            'von'         => (string) ($floor['geo_von'] ?? ''),
            'am'          => (string) ($floor['geo_am'] ?? ''),
        ];
    }

    public static function fuerEtage(int $floorId): ?array
    {
        if (!array_key_exists($floorId, self::$jeEtage)) {
            $floor = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
            self::$jeEtage[$floorId] = $floor === null ? null : self::lesen($floor);
        }
        return self::$jeEtage[$floorId];
    }

    /**
     * Ein Maßstab aus der Georeferenz – im Format von PlanMassstabService::lesen().
     *
     * @return array<string,mixed>|null
     */
    public static function massstab(?array $geo): ?array
    {
        if ($geo === null || $geo['meter'] <= 0) {
            return null;
        }
        return [
            'a'           => ['x' => $geo['a']['x'], 'y' => $geo['a']['y']],
            'b'           => ['x' => $geo['b']['x'], 'y' => $geo['b']['y']],
            'meter'       => $geo['meter'],
            'verhaeltnis' => $geo['verhaeltnis'],
            'von'         => $geo['von'],
            'am'          => $geo['am'],
            'quelle'      => 'georeferenz',
        ];
    }

    // ------------------------------------------------------------ Schreiben

    /**
     * Die Georeferenz setzen oder ersetzen.
     *
     * @param array{x:float,y:float,lat:float,lon:float} $a
     * @param array{x:float,y:float,lat:float,lon:float} $b
     * @return array{ok:bool,message:string,geo:?array}
     */
    public static function speichern(int $floorId, array $a, array $b, float $verhaeltnis): array
    {
        $floor = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
        if ($floor === null) {
            return ['ok' => false, 'message' => 'Etage nicht gefunden.', 'geo' => null];
        }
        foreach ([$a, $b] as $p) {
            if (!isset($p['x'], $p['y']) || $p['x'] < 0 || $p['x'] > 100 || $p['y'] < 0 || $p['y'] > 100) {
                return ['ok' => false, 'message' => 'Die Passpunkte müssen auf dem Plan liegen.', 'geo' => null];
            }
            if (!isset($p['lat'], $p['lon']) || !is_finite($p['lat']) || !is_finite($p['lon']) || abs($p['lat']) > 85 || abs($p['lon']) > 180) {
                return ['ok' => false, 'message' => 'Bitte zu beiden Punkten Breite und Länge angeben (etwa „51.2345, 7.1234“).', 'geo' => null];
            }
        }
        if (!($verhaeltnis > 0.01 && $verhaeltnis < 100)) {
            return ['ok' => false, 'message' => 'Das Seitenverhältnis des Plans ist unbekannt – bitte die Seite neu laden.', 'geo' => null];
        }
        if (PlanMassstabService::abstand($a, $b, $verhaeltnis) < PlanMassstabService::MINDESTABSTAND) {
            return ['ok' => false, 'message' => 'Die beiden Punkte liegen auf dem Plan zu nah beieinander. Je weiter auseinander, desto genauer.', 'geo' => null];
        }
        $meter = self::entfernung($a['lat'], $a['lon'], $b['lat'], $b['lon']);
        if ($meter < self::MINDESTABSTAND_M) {
            return ['ok' => false, 'message' => 'Die Koordinaten der beiden Punkte liegen keine 5 m auseinander – bitte weiter entfernte Punkte wählen.', 'geo' => null];
        }
        if ($meter > self::HOECHSTABSTAND_M) {
            return ['ok' => false, 'message' => 'Die Koordinaten liegen über 50 km auseinander – ist eine vertauscht?', 'geo' => null];
        }

        Database::update('floors', $floorId, [
            'geo_ax' => round($a['x'], 4), 'geo_ay' => round($a['y'], 4),
            'geo_a_lat' => round($a['lat'], 7), 'geo_a_lon' => round($a['lon'], 7),
            'geo_bx' => round($b['x'], 4), 'geo_by' => round($b['y'], 4),
            'geo_b_lat' => round($b['lat'], 7), 'geo_b_lon' => round($b['lon'], 7),
            'geo_verhaeltnis' => round($verhaeltnis, 6),
            'geo_von' => (string) (Auth::user()['username'] ?? 'system'),
            'geo_am' => gmdate('c'),
        ]);
        unset(self::$jeEtage[$floorId]);
        Audit::log('plan.georeferenz', 'floor', (string) $floorId, ['meter' => round($meter, 1)]);

        $geo = self::fuerEtage($floorId);
        $text = 'Georeferenz gesetzt: ' . number_format($meter, 1, ',', '.') . ' m zwischen den Passpunkten.';
        $massstab = PlanMassstabService::lesen($floor);
        if ($massstab !== null) {
            $abweichung = self::abweichung($geo, $massstab);
            if ($abweichung !== null && $abweichung > 3) {
                $text .= ' Achtung: Der Maßstab des Plans weicht um ' . number_format($abweichung, 0, ',', '.')
                    . ' % davon ab – ein Passpunkt oder der Maßstab stimmt nicht.';
            }
        } else {
            $text .= ' Kabellängen kommen jetzt aus der Georeferenz.';
        }
        return ['ok' => true, 'message' => $text, 'geo' => $geo];
    }

    public static function entfernen(int $floorId): void
    {
        Database::update('floors', $floorId, [
            'geo_ax' => null, 'geo_ay' => null, 'geo_a_lat' => null, 'geo_a_lon' => null,
            'geo_bx' => null, 'geo_by' => null, 'geo_b_lat' => null, 'geo_b_lon' => null,
            'geo_verhaeltnis' => null, 'geo_von' => '', 'geo_am' => null,
        ]);
        unset(self::$jeEtage[$floorId]);
        Audit::log('plan.georeferenz_entfernt', 'floor', (string) $floorId);
    }

    /** Aus dem Location-Abgleich übernehmen – oder entfernen, wenn es dort keine gibt. */
    public static function uebernehmen(int $floorId, mixed $geo): void
    {
        if (!is_array($geo)) {
            $floor = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
            if ($floor !== null && self::lesen($floor) !== null) {
                self::entfernen($floorId);
            }
            return;
        }
        $punkt = static function (mixed $p): ?array {
            if (!is_array($p) || !isset($p['x'], $p['y'], $p['lat'], $p['lon'])) {
                return null;
            }
            return ['x' => (float) $p['x'], 'y' => (float) $p['y'], 'lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
        };
        $a = $punkt($geo['a'] ?? null);
        $b = $punkt($geo['b'] ?? null);
        $v = (float) ($geo['verhaeltnis'] ?? 0);
        if ($a === null || $b === null || $v <= 0) {
            return;
        }
        Database::update('floors', $floorId, [
            'geo_ax' => $a['x'], 'geo_ay' => $a['y'], 'geo_a_lat' => $a['lat'], 'geo_a_lon' => $a['lon'],
            'geo_bx' => $b['x'], 'geo_by' => $b['y'], 'geo_b_lat' => $b['lat'], 'geo_b_lon' => $b['lon'],
            'geo_verhaeltnis' => $v,
            'geo_von' => mb_substr((string) ($geo['von'] ?? ''), 0, 190),
            'geo_am' => (string) ($geo['am'] ?? '') !== '' ? (string) $geo['am'] : null,
        ]);
        unset(self::$jeEtage[$floorId]);
    }

    /** Für den Location-Abgleich: nur die Passpunkte, ohne abgeleitete Werte. */
    public static function alsDaten(?array $geo): ?array
    {
        return $geo === null ? null : [
            'a' => $geo['a'], 'b' => $geo['b'], 'verhaeltnis' => $geo['verhaeltnis'], 'von' => $geo['von'], 'am' => $geo['am'],
        ];
    }

    // ------------------------------------------------------------ Rechnen

    /**
     * Plan → Welt.
     *
     * @return array{lat:float,lon:float}
     */
    public static function zuGeo(array $geo, float $x, float $y): array
    {
        [$sr, $si, $k, $kx] = self::abbildung($geo);
        $v = $geo['verhaeltnis'];
        $qx = $x - $geo['a']['x'];
        $qy = -($y - $geo['a']['y']) * $v;
        $ost = $sr * $qx - $si * $qy;
        $nord = $sr * $qy + $si * $qx;
        return ['lat' => round($geo['a']['lat'] + $nord / $k, 7), 'lon' => round($geo['a']['lon'] + $ost / $kx, 7)];
    }

    /**
     * Welt → Plan (Prozent; kann außerhalb 0–100 liegen).
     *
     * @return array{x:float,y:float}
     */
    public static function zuPlan(array $geo, float $lat, float $lon): array
    {
        [$sr, $si, $k, $kx] = self::abbildung($geo);
        $ost = ($lon - $geo['a']['lon']) * $kx;
        $nord = ($lat - $geo['a']['lat']) * $k;
        $betrag = $sr * $sr + $si * $si;
        $qx = ($ost * $sr + $nord * $si) / $betrag;
        $qy = ($nord * $sr - $ost * $si) / $betrag;
        return ['x' => round($geo['a']['x'] + $qx, 4), 'y' => round($geo['a']['y'] - $qy / $geo['verhaeltnis'], 4)];
    }

    /** Drehung des Plans: um wie viel Grad Plan-oben von Norden abweicht (im Uhrzeigersinn). */
    public static function drehung(array $geo): float
    {
        [$sr, $si] = self::abbildung($geo);
        // Plan-oben ist (0, 1) in Plan-Einheiten → (−si, sr) in Ost/Nord.
        $grad = rad2deg(atan2(-$si, $sr));
        return round(fmod($grad + 360.0, 360.0), 1);
    }

    /** Große-Kreis-Entfernung in Metern. */
    public static function entfernung(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lon2 - $lon1);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::ERDRADIUS * asin(min(1.0, sqrt($h)));
    }

    /** Abweichung zwischen Maßstab und Georeferenz in Prozent. */
    public static function abweichung(?array $geo, ?array $massstab): ?float
    {
        if ($geo === null || $massstab === null) {
            return null;
        }
        $geoJe = $geo['meter'] / PlanMassstabService::abstand($geo['a'], $geo['b'], $geo['verhaeltnis']);
        $mJe = $massstab['meter'] / PlanMassstabService::abstand($massstab['a'], $massstab['b'], $massstab['verhaeltnis']);
        return $mJe > 0 ? abs($geoJe / $mJe - 1) * 100 : null;
    }

    /**
     * Koordinaten aus einem Text lesen: „51.2345, 7.1234“, „51,2345; 7,1234“,
     * „51°14'04.2"N 7°07'24.2"E“, geo:-Adresse oder ein Link aus
     * OpenStreetMap bzw. Google Maps.
     *
     * @return array{lat:float,lon:float}|null
     */
    public static function koordinatenAusText(string $text): ?array
    {
        $text = trim(str_replace(['′', '’', '″', '“', '”'], ["'", "'", '"', '"', '"'], $text));
        if ($text === '') {
            return null;
        }
        $ok = static fn (float $lat, float $lon): ?array => abs($lat) <= 90 && abs($lon) <= 180 ? ['lat' => $lat, 'lon' => $lon] : null;

        // Grad, Minuten, Sekunden mit Himmelsrichtung
        $dms = '(\d{1,3})\s*°\s*(?:(\d{1,2}(?:[.,]\d+)?)\s*\'\s*)?(?:(\d{1,2}(?:[.,]\d+)?)\s*"\s*)?([NSEWOnsewo])';
        if (preg_match('/' . $dms . '[\s,;]*' . $dms . '/u', $text, $m) === 1) {
            $wert = static function (string $g, string $min, string $sek, string $r): array {
                $w = (float) $g + (float) str_replace(',', '.', $min ?: '0') / 60 + (float) str_replace(',', '.', $sek ?: '0') / 3600;
                $r = strtoupper($r);
                return [in_array($r, ['S', 'W'], true) ? -$w : $w, in_array($r, ['N', 'S'], true) ? 'lat' : 'lon'];
            };
            [$w1, $a1] = $wert($m[1], $m[2], $m[3], $m[4]);
            [$w2, $a2] = $wert($m[5], $m[6], $m[7], $m[8]);
            if ($a1 !== $a2) {
                return $a1 === 'lat' ? $ok($w1, $w2) : $ok($w2, $w1);
            }
            return null;
        }
        // Links: …?mlat=51.2&mlon=7.1, …#map=18/51.2/7.1, …/@51.2,7.1,17z, geo:51.2,7.1
        if (preg_match('/mlat=(-?\d+(?:\.\d+)?).*?mlon=(-?\d+(?:\.\d+)?)/', $text, $m) === 1
            || preg_match('/map=\d+(?:\.\d+)?\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/', $text, $m) === 1
            || preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $text, $m) === 1
            || preg_match('/^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i', $text, $m) === 1) {
            return $ok((float) $m[1], (float) $m[2]);
        }
        // Zwei Dezimalzahlen – mit Punkt, oder mit Komma und dann durch ; oder Leerzeichen getrennt.
        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)$/', $text, $m) === 1) {
            return $ok((float) $m[1], (float) $m[2]);
        }
        if (preg_match('/^(-?\d+(?:,\d+)?)\s*(?:;|\s)\s*(-?\d+(?:,\d+)?)$/', $text, $m) === 1) {
            return $ok((float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2]));
        }
        return null;
    }

    /** Link zur Lage in OpenStreetMap. */
    public static function kartenLink(float $lat, float $lon): string
    {
        return sprintf('https://www.openstreetmap.org/?mlat=%1$.6F&mlon=%2$.6F#map=19/%1$.6F/%2$.6F', $lat, $lon);
    }

    /**
     * Koordinaten einer Anlage (auf einer georeferenzierten Etage).
     *
     * @return array{lat:float,lon:float}|null
     */
    public static function fuerObjekt(array $objekt): ?array
    {
        if (empty($objekt['floor_id']) || $objekt['plan_x'] === null || $objekt['plan_y'] === null) {
            return null;
        }
        $geo = self::fuerEtage((int) $objekt['floor_id']);
        return $geo === null ? null : self::zuGeo($geo, (float) $objekt['plan_x'], (float) $objekt['plan_y']);
    }

    /** @return array{0:float,1:float,2:float,3:float} Drehstreckung (sr, si) und Meter je Grad Breite bzw. Länge */
    private static function abbildung(array $geo): array
    {
        $k = self::ERDRADIUS * M_PI / 180;
        $kx = $k * cos(deg2rad($geo['a']['lat']));
        $v = $geo['verhaeltnis'];
        $px = $geo['b']['x'] - $geo['a']['x'];
        $py = -($geo['b']['y'] - $geo['a']['y']) * $v;
        $ost = ($geo['b']['lon'] - $geo['a']['lon']) * $kx;
        $nord = ($geo['b']['lat'] - $geo['a']['lat']) * $k;
        $betrag = $px * $px + $py * $py;
        return [($ost * $px + $nord * $py) / $betrag, ($nord * $px - $ost * $py) / $betrag, $k, $kx];
    }
}
