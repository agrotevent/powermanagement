<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Berichte\Einstellungen;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Settings;

/**
 * Einstellungen des Kundenbereichs (Karte „Kundenbereich“ unter System →
 * Einstellungen).
 *
 * Wie die der Übergabeberichte über Settings::get/put, nicht in
 * OVERRIDABLE, und immer geprüft gelesen: ein von Hand in die Datenbank
 * geschriebener Unsinn ergibt die Vorgabe.
 *
 * Die Adresse der Oberfläche (app.url) steht nur in config/config.php – aus
 * ihr, nie aus der Anfrage (Host-Kopf), entstehen die Einrichtungslinks.
 */
final class KundenEinstellungen
{
    /** Mustertext nach Art. 13 DSGVO – keine Rechtsberatung, vor dem Einsatz prüfen. */
    public const DATENSCHUTZ = 'Im Kundenbereich verarbeiten wir die Angaben zu Ihrem Zugang (Benutzername, Name, '
        . 'E-Mail-Adresse, letzte Anmeldung) sowie Prüfprotokolle und Prüfnachweise zu unseren Leistungen für Ihr '
        . 'Unternehmen, um sie Ihnen nach Vertrag und den Nachweispflichten (BetrSichV, DGUV Vorschrift 3) '
        . 'bereitzustellen (Art. 6 Abs. 1 lit. b und c DSGVO). Abrufe von Dokumenten speichern wir mit Zeitpunkt. '
        . 'Prüfnachweise können aus unserer Auftragsverwaltung (Eventworx) stammen. Verantwortlich: {firma.name}, '
        . '{firma.anschrift}. Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung, Widerspruch '
        . 'und Beschwerde bei einer Aufsichtsbehörde.';

    public const VORGABEN = [
        'kundenbereich.datenschutz'          => self::DATENSCHUTZ,
        'kundenbereich.kontakt'              => '',
        'kundenbereich.einrichtung_stunden'  => '72',
        'kundenbereich.nachweise_jahre'      => '3',
    ];

    private const LAENGE = ['kundenbereich.datenschutz' => 3000, 'kundenbereich.kontakt' => 300];

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

    /** Wie lange ein Einrichtungslink gilt (Stunden, 1–168). */
    public static function einrichtungStunden(): int
    {
        return (int) self::wert('kundenbereich.einrichtung_stunden');
    }

    /**
     * Der Datenschutzhinweis mit Firmenname und Anschrift (aus den
     * Firmendaten der Übergabeberichte). Als Text – escaped wird beim
     * Ausgeben.
     */
    public static function datenschutz(): string
    {
        $name = Einstellungen::wert('firma.name');
        $zeilen = array_values(array_filter(array_map('trim', explode("\n", Einstellungen::wert('firma.anschrift'))), 'strlen'));
        if ($zeilen !== [] && mb_strtolower($zeilen[0]) === mb_strtolower($name)) {
            array_shift($zeilen);   // die Anschrift beginnt oft mit dem Namen – nicht zweimal
        }
        $anschrift = implode(', ', $zeilen);
        return strtr(self::wert('kundenbereich.datenschutz'), [
            '{firma.name}'      => $name !== '' ? $name : (string) Config::get('app.name', 'PowerManagement'),
            '{firma.anschrift}' => $anschrift !== '' ? $anschrift : '(Anschrift siehe Impressum)',
        ]);
    }

    /**
     * Die Adresse der Oberfläche aus app.url – nur http(s)://host[:port][/pfad],
     * ohne Zugangsdaten, Abfrage und Anker, ohne „/“ am Ende. null, wenn sie
     * fehlt oder nicht taugt: dann gibt es keine Einrichtungslinks.
     */
    public static function adresse(): ?string
    {
        $url = trim((string) Config::get('app.url', ''));
        if ($url === '' || preg_match('#^https?://[^\s/?\#@]+(/[^\s?\#]*)?$#i', $url) !== 1) {
            return null;
        }
        $teile = parse_url($url);
        if (!is_array($teile) || !isset($teile['scheme'], $teile['host']) || isset($teile['user']) || isset($teile['pass'])
            || preg_match('/^[A-Za-z0-9.-]+$|^\[[0-9A-Fa-f:.]+\]$/', (string) $teile['host']) !== 1) {
            return null;
        }
        return rtrim($url, '/');
    }

    /** Ist die Adresse unverschlüsselt (http://) und nicht nur auf diesem Rechner? */
    public static function unverschluesselt(?string $adresse = null): bool
    {
        $adresse ??= self::adresse();
        if ($adresse === null || !str_starts_with(strtolower($adresse), 'http://')) {
            return false;
        }
        $host = strtolower((string) parse_url($adresse, PHP_URL_HOST));
        return !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Mehrere Einstellungen auf einmal (Karte „Kundenbereich“). Unbekannte
     * Schlüssel werden übergangen; ein ungültiger Wert verwirft die ganze
     * Eingabe. Im Audit nur, welche Schlüssel – nie die Texte.
     *
     * @param array<string,mixed> $daten
     * @return array{ok:bool,message:string}
     */
    public static function speichern(array $daten): array
    {
        $neu = [];
        foreach (self::VORGABEN as $schluessel => $_vorgabe) {
            if (!array_key_exists($schluessel, $daten) || !is_scalar($daten[$schluessel])) {
                continue;
            }
            $wert = self::saeubern($schluessel, (string) $daten[$schluessel]);
            if ($schluessel === 'kundenbereich.datenschutz' && $wert === '') {
                $wert = self::DATENSCHUTZ; // leer: wieder der Mustertext
            }
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
            Audit::log('einstellungen.kundenbereich', 'settings', 'kundenbereich', ['schluessel' => $geaendert]);
        }
        return ['ok' => true, 'message' => $geaendert === [] ? 'Nichts geändert.' : 'Einstellungen des Kundenbereichs gespeichert.'];
    }

    private static function saeubern(string $schluessel, string $wert): string
    {
        $wert = str_replace(["\r\n", "\r"], "\n", $wert);
        $wert = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $wert);
        if ($schluessel === 'kundenbereich.datenschutz') {
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
        return match ($schluessel) {
            'kundenbereich.einrichtung_stunden' => preg_match('/^\d{1,3}$/', $wert) === 1 && (int) $wert >= 1 && (int) $wert <= 168 ? (string) (int) $wert : null,
            'kundenbereich.nachweise_jahre'     => preg_match('/^\d{1,2}$/', $wert) === 1 && (int) $wert >= 1 && (int) $wert <= 30 ? (string) (int) $wert : null,
            'kundenbereich.datenschutz'         => $wert !== '' && mb_strlen($wert) <= self::LAENGE[$schluessel] ? $wert : null,
            'kundenbereich.kontakt'             => mb_strlen($wert) <= self::LAENGE[$schluessel] ? $wert : null,
            default                             => null,
        };
    }

    private static function fehlerText(string $schluessel): string
    {
        return match ($schluessel) {
            'kundenbereich.einrichtung_stunden' => 'Ein Einrichtungslink gilt 1 bis 168 Stunden.',
            'kundenbereich.nachweise_jahre'     => 'Prüfnachweise bleiben 1 bis 30 Jahre im Kundenbereich.',
            default                             => 'Ein Text ist zu lang (höchstens ' . (self::LAENGE[$schluessel] ?? 255) . ' Zeichen).',
        };
    }
}
