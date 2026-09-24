<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Config;
use App\Support\Logger;

/**
 * Der Sammler: fragt Modbus-Geräte und Clouds ab und gibt die Werte weiter.
 *
 * Läuft als Dienst (powermanagement-sammler.service, `bin/power sammler`).
 * Jede Datenquelle hat ihr eigenes Intervall; eine Runde fragt nur ab, was
 * fällig ist. Eine langsame Cloud hält so die Sekundenwerte des GX nicht
 * auf – sie ist einfach seltener dran.
 *
 * Ziel (sammler.ziel):
 *   mqtt    → pm/messwert/<geraet>/<kanal>; Node-RED schreibt in die InfluxDB.
 *             Das ist die Vorgabe: ein Weg in die Datenbank für alle Quellen,
 *             und wer mitlesen will (Grafana, Home Assistant), abonniert.
 *   influx  → direkt in die InfluxDB, wenn kein Node-RED läuft.
 */
final class Sammler
{
    private ?Mqtt $mqtt = null;
    /** @var array<int,float> Datenquelle → nächste Fälligkeit */
    private array $faellig = [];
    /** @var array<int,int> aufeinanderfolgende Fehler je Quelle */
    private array $fehlerFolge = [];
    private bool $laufen = true;

    public function __construct(private readonly bool $gespraechig = false)
    {
    }

    public function stoppen(): void
    {
        $this->laufen = false;
    }

    /** Dauerbetrieb. */
    public function schleife(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stoppen());
            pcntl_signal(SIGINT, fn () => $this->stoppen());
        }
        $this->melden('Sammler gestartet, Ziel: ' . self::ziel());
        $quellenStand = 0.0;
        $quellen = [];
        while ($this->laufen) {
            // Die Liste der Quellen alle 30 s neu lesen – Änderungen in der
            // Oberfläche greifen so ohne Neustart des Dienstes.
            if (microtime(true) - $quellenStand > 30) {
                $quellen = Datenquellen::alle();
                $quellenStand = microtime(true);
            }
            $this->runde($quellen);
            $this->mqtt?->ping();
            usleep(250_000);
        }
        $this->mqtt?->trennen();
        $this->melden('Sammler beendet.');
    }

    /**
     * Eine Runde: alle fälligen Quellen abfragen.
     *
     * @param list<array<string,mixed>> $quellen
     * @return int Anzahl abgefragter Quellen
     */
    public function runde(array $quellen, bool $alle = false): int
    {
        $jetzt = microtime(true);
        $anzahl = 0;
        foreach ($quellen as $quelle) {
            $id = (int) $quelle['id'];
            if ((int) $quelle['aktiv'] !== 1 || $quelle['art'] === 'shelly') {
                continue;
            }
            if (!$alle && ($this->faellig[$id] ?? 0) > $jetzt) {
                continue;
            }
            // Nach Fehlern seltener fragen (höchstens alle 5 Minuten), damit
            // ein toter Tunnel nicht jede Sekunde drei Sekunden Wartezeit kostet.
            $folge = $this->fehlerFolge[$id] ?? 0;
            $pause = max((int) $quelle['intervall_s'], min(300, (int) $quelle['intervall_s'] * (2 ** min($folge, 6))));
            $this->faellig[$id] = $jetzt + ($folge > 0 ? $pause : (int) $quelle['intervall_s']);

            $ergebnis = Abfrage::ausfuehren($quelle);
            $anzahl++;
            if (!$ergebnis['ok']) {
                $this->fehlerFolge[$id] = $folge + 1;
                Datenquellen::kontakt($id, (string) $ergebnis['fehler']);
                if ($folge === 0) {
                    Logger::info('Sammler: ' . $quelle['name'] . ': ' . $ergebnis['fehler']);
                }
                $this->melden($quelle['name'] . ': FEHLER ' . $ergebnis['fehler']);
                continue;
            }
            $this->fehlerFolge[$id] = 0;
            $fehler = $this->weitergeben($quelle, $ergebnis['kanaele']);
            Datenquellen::kontakt($id, $fehler);
            $this->melden($quelle['name'] . ': ' . count($ergebnis['kanaele']) . ' Kanäle' . ($fehler !== null ? ' – ' . $fehler : ''));
        }
        return $anzahl;
    }

    public static function ziel(): string
    {
        return Config::get('sammler.ziel', 'mqtt') === 'influx' ? 'influx' : 'mqtt';
    }

    /** @return string|null Fehlermeldung */
    private function weitergeben(array $quelle, array $kanaele): ?string
    {
        $ts = (int) round(microtime(true) * 1000);
        $typ = match ((string) $quelle['art']) {
            'comap_cloud' => 'comap',
            'victron_vrm' => 'victron',
            default       => self::typAusProfil((string) $quelle['profil']),
        };
        if (self::ziel() === 'influx') {
            $zeilen = [];
            foreach ($kanaele as $kanal => $werte) {
                array_push($zeilen, ...Influx::zeilen((string) $quelle['geraet'], (string) $kanal, $werte + ['ts' => $ts, 'typ' => $typ]));
            }
            $fehler = null;
            return Influx::schreiben($zeilen, $fehler) ? null : $fehler;
        }

        $praefix = trim((string) Config::get('mqtt.prefix', 'pm'), '/');
        try {
            if ($this->mqtt === null || !$this->mqtt->verbunden()) {
                $this->mqtt = Mqtt::ausKonfiguration('pm-sammler-' . getmypid());
            }
            foreach ($kanaele as $kanal => $werte) {
                $this->mqtt->veroeffentlichen(
                    $praefix . '/messwert/' . $quelle['geraet'] . '/' . $kanal,
                    (string) json_encode(['ts' => $ts, 'typ' => $typ] + $werte, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
                );
            }
        } catch (\RuntimeException $e) {
            $this->mqtt = null;
            return 'MQTT: ' . $e->getMessage();
        }
        return null;
    }

    private static function typAusProfil(string $profil): string
    {
        return match (true) {
            str_starts_with($profil, 'victron') => 'victron',
            str_starts_with($profil, 'comap')   => 'comap',
            default                             => 'modbus',
        };
    }

    private function melden(string $text): void
    {
        if ($this->gespraechig) {
            fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $text . PHP_EOL);
        }
    }
}
