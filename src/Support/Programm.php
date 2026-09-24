<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Ein Programm auf dem Server finden – auch unter open_basedir.
 *
 * Der PHP-FPM-Pool der Oberfläche grenzt das Dateisystem ein
 * (`open_basedir = /opt/powermanagement:/tmp:/usr/share/php`). Dort ist schon
 * das Nachsehen verboten: `is_file('/usr/bin/git')` liefert false, obwohl
 * git daliegt. Die Oberfläche meldete deshalb „auf dem Server fehlt das
 * Paket git“, während derselbe Abgleich aus den systemd-Diensten heraus
 * lief – die laufen über die Kommandozeile, ohne diese Grenze.
 *
 * Ein Programm **starten** darf PHP trotzdem. Unter open_basedir wird
 * deshalb gesucht, indem aufgerufen wird: ein Kandidat gilt als gefunden,
 * wenn er mit der Probe (etwa `--version`) sauber endet. Ohne Grenze
 * bleibt es beim schnellen Nachsehen.
 *
 * Gesucht wird im PATH **und** an den üblichen Orten – php-fpm räumt die
 * Umgebung in der Vorgabe leer (`clear_env = yes`), der PATH allein sagt
 * dort nichts.
 */
final class Programm
{
    /** Die üblichen Orte, unabhängig davon, was im PATH steht. */
    public const ORTE = ['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin'];

    /** @var array<string,?string> */
    private static array $gefunden = [];

    /**
     * Den Pfad eines Programms – oder null, wenn es nirgends läuft.
     *
     * @param list<string> $probe Argumente, mit denen das Programm sofort und
     *                            ohne Netz endet (Rückgabewert 0)
     */
    public static function finden(string $name, array $probe = ['--version']): ?string
    {
        $schluessel = $name . "\0" . implode("\0", $probe);
        if (array_key_exists($schluessel, self::$gefunden)) {
            return self::$gefunden[$schluessel];
        }

        $orte = array_merge(explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '')), self::ORTE);
        $eingegrenzt = self::eingegrenzt();

        foreach (array_unique(array_filter($orte)) as $ort) {
            $pfad = rtrim($ort, '/') . '/' . $name;
            if (!$eingegrenzt) {
                if (is_file($pfad) && is_executable($pfad)) {
                    return self::$gefunden[$schluessel] = $pfad;
                }
                continue;
            }
            if (self::laeuft(array_merge([$pfad], $probe))) {
                return self::$gefunden[$schluessel] = $pfad;
            }
        }

        return self::$gefunden[$schluessel] = null;
    }

    /** Ob PHP hier eingegrenzt ist – dann hilft nur Aufrufen. */
    public static function eingegrenzt(): bool
    {
        return trim((string) ini_get('open_basedir')) !== '';
    }

    /** Für Prüfskripte: das Gefundene vergessen. */
    public static function vergessen(): void
    {
        self::$gefunden = [];
    }

    /**
     * Startet der Befehl und endet er mit 0?
     *
     * Die Eingabe als Rohr, das gleich wieder zugeht – nicht als
     * /dev/null: diese Datei öffnete PHP selbst, und genau das verbietet
     * open_basedir.
     *
     * @param list<string> $befehl
     */
    private static function laeuft(array $befehl, float $sekunden = 5.0): bool
    {
        $prozess = @proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        if (!is_resource($prozess)) {
            return false;
        }
        fclose($rohre[0]);
        stream_set_blocking($rohre[1], false);
        stream_set_blocking($rohre[2], false);

        $rc = -1;
        $bis = microtime(true) + $sekunden;
        while (true) {
            // Lesen, damit ein gesprächiges Programm nicht am vollen Rohr hängt.
            stream_get_contents($rohre[1]);
            stream_get_contents($rohre[2]);
            $stand = proc_get_status($prozess);
            if (!$stand['running']) {
                // Nur hier steht der Rückgabewert verlässlich – ein
                // proc_close() danach liefert bis PHP 8.2 nur noch -1.
                $rc = (int) $stand['exitcode'];
                break;
            }
            if (microtime(true) > $bis) {
                proc_terminate($prozess, 9);
                break;
            }
            usleep(20_000);
        }
        fclose($rohre[1]);
        fclose($rohre[2]);
        proc_close($prozess);

        return $rc === 0;
    }
}
