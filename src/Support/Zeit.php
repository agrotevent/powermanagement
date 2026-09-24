<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Zeitpunkte zwischen Formular, Datenbank und InfluxDB.
 *
 * In der Datenbank steht UTC im ISO-Format (gmdate('c')), im Formular die
 * Ortszeit (datetime-local, ohne Zone). Dazwischen wird hier umgerechnet –
 * an genau einer Stelle, damit eine Freigabe „bis 24.09. 23:59“ nicht
 * wegen einer vergessenen Zeitzone schon um 21:59 endet.
 */
final class Zeit
{
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone((string) Config::get('app.timezone', 'Europe/Berlin'));
    }

    /** Eingabe aus dem Formular (Ortszeit) → ISO in UTC, oder null. */
    public static function ausEingabe(?string $wert, bool $tagesende = false): ?string
    {
        $wert = trim((string) $wert);
        if ($wert === '') {
            return null;
        }
        // Nur ein Datum: Beginn bzw. Ende des Tages.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert) === 1) {
            $wert .= $tagesende ? ' 23:59:59' : ' 00:00:00';
        }
        try {
            $dt = new \DateTimeImmutable(str_replace('T', ' ', $wert), self::zone());
        } catch (\Throwable) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    /** ISO (UTC) → Wert für ein datetime-local-Feld in Ortszeit. */
    public static function fuerEingabe(?string $iso): string
    {
        $ts = self::ts($iso);
        if ($ts === null) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone())->format('Y-m-d\TH:i');
    }

    /** ISO → Unix-Zeit, oder null bei leer/ungültig. */
    public static function ts(?string $iso): ?int
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Unix-Zeit → ISO in UTC. */
    public static function iso(int $ts): string
    {
        return gmdate('c', $ts);
    }

    /** Lesbar in Ortszeit, z. B. „24.09.2026 18:30“. */
    public static function anzeige(?string $iso, string $format = 'd.m.Y H:i'): string
    {
        $ts = self::ts($iso);
        if ($ts === null) {
            return '–';
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone())->format($format);
    }

    /**
     * Liegt ein Zeitpunkt im Fenster [ab, bis]? Leere Grenzen sind offen.
     */
    public static function imFenster(int $jetzt, ?string $ab, ?string $bis): bool
    {
        $a = self::ts($ab);
        $b = self::ts($bis);
        return ($a === null || $jetzt >= $a) && ($b === null || $jetzt <= $b);
    }

    /** „seit 3 Min.“, „in 2 Tagen“ – für Hinweise an Freigaben. */
    public static function abstand(?string $iso): string
    {
        $ts = self::ts($iso);
        if ($ts === null) {
            return '';
        }
        $d = $ts - time();
        $betrag = abs($d);
        $text = match (true) {
            $betrag < 90      => 'einem Moment',
            $betrag < 5400    => (int) round($betrag / 60) . ' Min.',
            $betrag < 129600  => (int) round($betrag / 3600) . ' Std.',
            default           => (int) round($betrag / 86400) . ' Tagen',
        };
        return $d >= 0 ? 'in ' . $text : 'vor ' . $text;
    }
}
