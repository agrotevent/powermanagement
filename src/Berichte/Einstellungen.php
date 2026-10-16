<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Support\Audit;
use App\Support\Settings;

/**
 * Einstellungen der Übergabeberichte und die Firmendaten für Kopf und Fuß.
 *
 * Alles über Settings::get/put (nicht in OVERRIDABLE – das ist keine
 * Laufzeitkonfiguration, sondern Inhalt der Berichte). Gelesen wird immer
 * geprüft: ein von Hand in die Datenbank geschriebener Unsinn ergibt die
 * Vorgabe, nie einen falschen Grenzwert.
 *
 * Die Firmendaten haben bewusst keine Vorgabe – Bank und Register gehören
 * nicht ins Repository. Vor der ersten Prüfer-Unterschrift muss
 * `firma.name` gesetzt sein.
 */
final class Einstellungen
{
    public const VORGABEN = [
        'firma.name'                  => '',
        'firma.zusatz'                => '',
        'firma.anschrift'             => '',
        'firma.kontakt'               => '',
        'firma.bank'                  => '',
        'firma.register'              => '',
        'berichte.praefix'            => 'UB',
        'berichte.ort'                => '',
        'berichte.normen'             => '0100-600,0100-711,dguv-v3',
        'berichte.riso_u'             => '500',
        'berichte.zs_faktor'          => '1.0',
        'berichte.ul'                 => '50',
        'berichte.erde_generator'     => '50',
        'berichte.rpe_hinweis'        => '1.0',
        'berichte.bereitschaft'       => '',
        'berichte.text_kunde'         => 'Abnahme-/Dokumentbestätigung – es wird nicht der prüftechnische Inhalt bestätigt. '
            . 'Mit der Unterschrift bestätigt der Kunde, den Bericht erhalten und die Anlage übernommen zu haben.',
        'berichte.text_einweisung'    => 'Einweisung erhalten: Hauptschalter/Not-Aus, RCD-Prüftaste arbeitstäglich betätigen, '
            . 'Verhalten bei Auslösung (nicht wiederholt einschalten, Elektrofachkraft rufen), keine Eingriffe durch Laien, Bereitschaft {telefon}.',
        'berichte.text_errichter'     => 'Der Errichter bestätigt, dass die oben bezeichnete Anlage den Bestimmungen der DGUV Vorschrift 3 '
            . 'entsprechend beschaffen ist (§ 5 Abs. 4).',
        'berichte.datenschutz'        => 'Name, Funktion, Ort und Unterschrift werden zur Dokumentation der Übergabe der elektrischen Anlage '
            . 'verarbeitet (Art. 6 Abs. 1 lit. b und c DSGVO) und mit dem Bericht aufbewahrt. Gespeichert wird die Unterschrift nur als '
            . 'Linienzug, ohne Zeit- und Druckwerte. Verantwortlich ist der oben genannte Auftragnehmer.',
        'berichte.aufbewahrung_jahre' => '10',
    ];

    /** Längen der Felder (Textareas: mehrzeilig). */
    private const LAENGE = [
        'firma.name' => 120, 'firma.zusatz' => 190, 'firma.anschrift' => 500, 'firma.kontakt' => 500, 'firma.bank' => 500,
        'firma.register' => 500, 'berichte.ort' => 120, 'berichte.bereitschaft' => 120, 'berichte.text_kunde' => 2000,
        'berichte.text_einweisung' => 2000, 'berichte.text_errichter' => 2000, 'berichte.datenschutz' => 3000,
    ];

    private const MEHRZEILIG = ['firma.anschrift', 'firma.kontakt', 'firma.bank', 'firma.register', 'berichte.text_kunde',
        'berichte.text_einweisung', 'berichte.text_errichter', 'berichte.datenschutz'];

    public const ZS_FAKTOREN = ['1.0', '0.8', '0.67'];
    public const UL = ['50', '25'];
    public const RISO_U = ['250', '500', '1000'];

    /** Der gültige Wert – gespeichert und geprüft, sonst die Vorgabe. */
    public static function wert(string $schluessel): string
    {
        $vorgabe = self::VORGABEN[$schluessel] ?? '';
        $wert = (string) Settings::get($schluessel, $vorgabe);
        return self::pruefen($schluessel, $wert) ?? $vorgabe;
    }

    /** @return array<string,string> */
    public static function alle(): array
    {
        $out = [];
        foreach (array_keys(self::VORGABEN) as $s) {
            $out[$s] = self::wert($s);
        }
        return $out;
    }

    /** @return list<string> Normen als Schlüssel aus dem Katalog */
    public static function normen(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', self::wert('berichte.normen'))),
            static fn (string $n): bool => isset(Katalog::normen()[$n])
        ));
    }

    /**
     * Mehrere Einstellungen auf einmal (Karte „Firma & Übergabeberichte“).
     * Unbekannte Schlüssel werden übergangen; ein ungültiger Wert verwirft
     * die ganze Eingabe, damit nicht die Hälfte gespeichert ist.
     *
     * @param array<string,mixed> $daten
     * @return array{ok:bool,message:string}
     */
    public static function speichern(array $daten): array
    {
        $neu = [];
        foreach (self::VORGABEN as $schluessel => $_vorgabe) {
            if (!array_key_exists($schluessel, $daten)) {
                continue;
            }
            $roh = $daten[$schluessel];
            if (is_array($roh)) {
                $roh = implode(',', array_map('strval', array_filter($roh, 'is_scalar')));
            }
            $wert = self::saeubern($schluessel, (string) $roh);
            $geprueft = self::pruefen($schluessel, $wert);
            if ($geprueft === null) {
                return ['ok' => false, 'message' => self::fehlerText($schluessel)];
            }
            $neu[$schluessel] = $geprueft;
        }
        $geaendert = [];
        foreach ($neu as $schluessel => $wert) {
            if ((string) Settings::get($schluessel, self::VORGABEN[$schluessel]) !== $wert) {
                Settings::put($schluessel, $wert);
                $geaendert[] = $schluessel;
            }
        }
        if ($geaendert !== []) {
            // Nur welche Felder, nicht die Werte: Bank und Register gehören nicht ins Audit.
            Audit::log('einstellungen.berichte', 'settings', 'berichte', ['felder' => $geaendert]);
        }
        return ['ok' => true, 'message' => $geaendert === [] ? 'Nichts geändert.' : 'Einstellungen der Übergabeberichte gespeichert.'];
    }

    private static function saeubern(string $schluessel, string $wert): string
    {
        $wert = str_replace(["\r\n", "\r"], "\n", $wert);
        $wert = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $wert);
        if (in_array($schluessel, self::MEHRZEILIG, true)) {
            $wert = (string) preg_replace('/[^\P{Cc}\n]/u', '', $wert);
            $wert = (string) preg_replace("/\n{3,}/", "\n\n", $wert);
        } else {
            $wert = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', $wert));
        }
        return trim($wert);
    }

    /** Der Wert in seiner gespeicherten Form – oder null, wenn er nicht taugt. */
    private static function pruefen(string $schluessel, string $wert): ?string
    {
        $wert = trim($wert);
        switch ($schluessel) {
            case 'berichte.praefix':
                $wert = strtoupper($wert);
                return preg_match('/^[A-Z]{1,6}$/', $wert) === 1 ? $wert : null;
            case 'berichte.zs_faktor':
                $wert = str_replace(',', '.', $wert);
                return in_array($wert, self::ZS_FAKTOREN, true) ? $wert : ($wert === '1' ? '1.0' : null);
            case 'berichte.ul':
                return in_array($wert, self::UL, true) ? $wert : null;
            case 'berichte.riso_u':
                return in_array($wert, self::RISO_U, true) ? $wert : null;
            case 'berichte.erde_generator':
            case 'berichte.rpe_hinweis':
                $wert = str_replace(',', '.', $wert);
                return preg_match('/^\d{1,5}(\.\d{1,3})?$/', $wert) === 1 && (float) $wert > 0 ? $wert : null;
            case 'berichte.aufbewahrung_jahre':
                return preg_match('/^\d{1,2}$/', $wert) === 1 && (int) $wert >= 1 && (int) $wert <= 30 ? (string) (int) $wert : null;
            case 'berichte.normen':
                $normen = [];
                foreach (array_map('trim', explode(',', $wert)) as $n) {
                    if (isset(Katalog::normen()[$n]) && !in_array($n, $normen, true)) {
                        $normen[] = $n;
                    }
                }
                return implode(',', $normen);
        }
        if (!array_key_exists($schluessel, self::VORGABEN)) {
            return null;
        }
        return mb_strlen($wert) <= (self::LAENGE[$schluessel] ?? 255) ? $wert : null;
    }

    private static function fehlerText(string $schluessel): string
    {
        return match ($schluessel) {
            'berichte.praefix'            => 'Das Kürzel der Berichtsnummer besteht aus 1 bis 6 Großbuchstaben (z. B. UB).',
            'berichte.zs_faktor'          => 'Faktor für Zs: 1,0, 0,8 oder 0,67.',
            'berichte.ul'                 => 'Berührungsspannung U_L: 50 V oder 25 V.',
            'berichte.riso_u'             => 'Messspannung für R_iso: 250, 500 oder 1000 V.',
            'berichte.erde_generator', 'berichte.rpe_hinweis' => 'Bitte einen Widerstand in Ω angeben (z. B. 50 oder 1,0).',
            'berichte.aufbewahrung_jahre' => 'Aufbewahrung: 1 bis 30 Jahre.',
            default                       => 'Ein Text ist zu lang (höchstens ' . (self::LAENGE[$schluessel] ?? 255) . ' Zeichen).',
        };
    }
}
