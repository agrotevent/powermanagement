<?php
declare(strict_types=1);

namespace App\Tanks;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\PlanGeoService;
use App\Support\View;

/**
 * Tanks: Anlagen der Art „tank“.
 *
 * Ein Tank steht im Plan wie jede Anlage – mit Ort, Freigaben, Aufbau und
 * Abgleichen –, hat aber keine Anschlüsse, keinen Nennstrom und keine
 * Leistung (AnlagenService::elektrisch()). Was ihn ausmacht – Nennvolumen,
 * Kraftstoff, Bauform, Maße, Zulassung –, steht in seinen Eigenschaften
 * (AnlagenService::FELDER['tank']).
 *
 * Hier liegt, was nur Tanks brauchen; die Anlagenseite eines Tanks
 * (anlagen/tank.php) bekommt ihre Daten aus seitenDaten().
 */
final class TankService
{
    /**
     * Kurzform für Listen, Karte und Katalog: „3.000 l · Diesel · Quader“ –
     * an der Stelle, an der bei einer elektrischen Anlage die Anschlüsse
     * stehen.
     */
    public static function kurzform(array $objekt): string
    {
        $eigenschaften = $objekt['eigenschaften'] ?? [];
        if (!is_array($eigenschaften)) {
            $eigenschaften = json_decode((string) $eigenschaften, true) ?: [];
        }
        $teile = [];
        $inhalt = $eigenschaften['inhalt_l'] ?? null;
        if (is_numeric($inhalt) && (float) $inhalt > 0) {
            $teile[] = self::liter((float) $inhalt);
        }
        foreach (['kraftstoff', 'bauform'] as $schluessel) {
            $wert = trim((string) ($eigenschaften[$schluessel] ?? ''));
            if ($wert !== '') {
                $teile[] = $wert;
            }
        }
        return $teile === [] ? 'Nennvolumen nicht eingetragen' : implode(' · ', $teile);
    }

    /** „3.000 l“, „1.250,5 l“ – Liter mit Tausenderpunkt. */
    public static function liter(float $liter): string
    {
        return self::zahl(round($liter, 1)) . ' l';
    }

    /** Eine Zahl mit Tausenderpunkt und nur so vielen Stellen, wie sie hat: „3.000“, „2,78“. */
    public static function zahl(float $wert): string
    {
        $text = View::zahlFeld($wert);
        $komma = strpos($text, ',');
        return number_format($wert, $komma === false ? 0 : strlen($text) - $komma - 1, ',', '.');
    }

    /** Die Beschriftung eines Felds ohne den Hinweis fürs Formular: „Füllgrenze (leer = 95 %)“ → „Füllgrenze“. */
    public static function beschriftung(string $beschriftung): string
    {
        return (string) preg_replace('/\s*\(leer = [^)]*\)$/u', '', $beschriftung);
    }

    /**
     * Was die Seite eines Tanks zeigt: seine Daten, gruppiert wie im
     * Formular, und was der Betrachter damit tun darf.
     *
     * @return array<string,mixed>
     */
    public static function seitenDaten(array $tank): array
    {
        $vorlage = (int) $tank['ist_vorlage'] === 1;
        $eigenschaften = (array) $tank['eigenschaften'];
        $gruppen = [];
        foreach (AnlagenService::feldGruppen((string) $tank['art']) as $titel => $schluessel) {
            $zeilen = [];
            foreach ($schluessel as $k) {
                if (!array_key_exists($k, $eigenschaften)) {
                    continue;
                }
                [$beschriftung, $typ, $zusatz] = AnlagenService::FELDER[(string) $tank['art']][$k];
                $wert = $eigenschaften[$k];
                $zeilen[] = [
                    'beschriftung' => self::beschriftung($beschriftung),
                    'wert'         => is_float($wert) || is_int($wert) ? self::zahl((float) $wert) : (string) $wert,
                    'einheit'      => $typ !== 'auswahl' ? (string) $zusatz : '',
                ];
            }
            if ($zeilen !== []) {
                $gruppen[$titel] = $zeilen;
            }
        }
        return [
            'objekt'      => $tank,
            'vorlage'     => $vorlage,
            'kurzform'    => self::kurzform($tank),
            'gruppen'     => $gruppen,
            'lage'        => $vorlage ? null : PlanGeoService::fuerObjekt($tank),
            'darfAendern' => $vorlage ? (Auth::can('stecker.manage') || Zugriff::alles()) : Zugriff::darfBearbeiten($tank),
        ];
    }
}
