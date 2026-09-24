<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Config;
use App\Support\Settings;

/**
 * Ein schlanker MQTT-3.1.1-Client.
 *
 * Gebraucht wird wenig: veröffentlichen (Sammler, Schaltbefehle an Shelly)
 * und kurz mitlesen (Antwort eines Shelly auf einen Befehl, Herzschlag von
 * Node-RED). Dafür eine Bibliothek samt Composer einzuführen, wäre mehr
 * Last als Nutzen – das Protokoll ist für diesen Umfang übersichtlich.
 *
 * QoS 0 und 1 beim Senden, QoS 0 beim Empfangen. Kein TLS: der Broker
 * läuft auf derselben Maschine bzw. im selben Netz.
 */
final class Mqtt
{
    /** @var resource|null */
    private $sock = null;
    private int $paketId = 1;
    private float $letzteAktivitaet = 0.0;
    private int $keepAlive = 30;

    /** @var list<array{thema:string,nachricht:string,retain:bool}> */
    private array $eingang = [];

    public static function ausKonfiguration(string $clientId = ''): self
    {
        $client = new self();
        $client->verbinden(
            (string) Config::get('mqtt.host', '127.0.0.1'),
            (int) Config::get('mqtt.port', 1883),
            (string) Config::get('mqtt.username', ''),
            Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'),
            $clientId !== '' ? $clientId : 'pm-' . bin2hex(random_bytes(4))
        );
        return $client;
    }

    public function verbinden(string $host, int $port, string $benutzer, string $kennwort, string $clientId, int $zeit = 5): void
    {
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, $zeit);
        if ($sock === false) {
            throw new \RuntimeException('MQTT-Broker ' . $host . ':' . $port . ' nicht erreichbar: ' . $text);
        }
        stream_set_timeout($sock, $zeit);
        $this->sock = $sock;

        $flags = 0x02; // clean session
        $nutzlast = self::zeichen($clientId);
        if ($benutzer !== '') {
            $flags |= 0x80;
            $nutzlast .= self::zeichen($benutzer);
            if ($kennwort !== '') {
                $flags |= 0x40;
            }
        }
        if ($benutzer !== '' && $kennwort !== '') {
            $nutzlast .= self::zeichen($kennwort);
        }
        $variabel = self::zeichen('MQTT') . chr(4) . chr($flags) . pack('n', $this->keepAlive);
        $this->senden(0x10, $variabel . $nutzlast);

        $antwort = $this->paketLesen($zeit);
        if ($antwort === null || $antwort['typ'] !== 2) {
            $this->schliessen();
            throw new \RuntimeException('MQTT-Broker antwortet nicht auf die Anmeldung.');
        }
        $code = ord($antwort['daten'][1] ?? "\x05");
        if ($code !== 0) {
            $this->schliessen();
            throw new \RuntimeException('MQTT-Anmeldung abgelehnt: ' . match ($code) {
                1 => 'Protokollversion',
                2 => 'Client-ID abgelehnt',
                3 => 'Broker nicht verfügbar',
                4 => 'Benutzer oder Kennwort falsch',
                5 => 'nicht berechtigt',
                default => 'Code ' . $code,
            });
        }
    }

    public function veroeffentlichen(string $thema, string $nachricht, int $qos = 0, bool $retain = false): void
    {
        $kopf = 0x30 | ($qos > 0 ? 0x02 : 0) | ($retain ? 0x01 : 0);
        $variabel = self::zeichen($thema);
        $id = 0;
        if ($qos > 0) {
            $id = $this->naechsteId();
            $variabel .= pack('n', $id);
        }
        $this->senden($kopf, $variabel . $nachricht);
        if ($qos > 0) {
            // Auf PUBACK warten – eingehende Nachrichten dabei aufheben.
            $ende = microtime(true) + 5;
            while (microtime(true) < $ende) {
                $paket = $this->paketLesen(1);
                if ($paket === null) {
                    continue;
                }
                if ($paket['typ'] === 4 && unpack('n', substr($paket['daten'], 0, 2))[1] === $id) {
                    return;
                }
                $this->verarbeiten($paket);
            }
            throw new \RuntimeException('Der MQTT-Broker hat die Nachricht nicht bestätigt.');
        }
    }

    /** @param list<string> $themen */
    public function abonnieren(array $themen): void
    {
        $id = $this->naechsteId();
        $nutzlast = '';
        foreach ($themen as $thema) {
            $nutzlast .= self::zeichen($thema) . chr(0);
        }
        $this->senden(0x82, pack('n', $id) . $nutzlast);
        $ende = microtime(true) + 5;
        while (microtime(true) < $ende) {
            $paket = $this->paketLesen(1);
            if ($paket === null) {
                continue;
            }
            if ($paket['typ'] === 9) {
                return;
            }
            $this->verarbeiten($paket);
        }
        throw new \RuntimeException('Der MQTT-Broker hat das Abonnement nicht bestätigt.');
    }

    /**
     * Nachrichten lesen, bis die Zeit um ist oder der Prüfer „genug“ sagt.
     *
     * @param callable(string,string,bool):bool|null $genug
     * @return list<array{thema:string,nachricht:string,retain:bool}>
     */
    public function lauschen(float $sekunden, ?callable $genug = null): array
    {
        $ende = microtime(true) + $sekunden;
        $gesammelt = [];
        while (true) {
            while ($this->eingang !== []) {
                $n = array_shift($this->eingang);
                $gesammelt[] = $n;
                if ($genug !== null && $genug($n['thema'], $n['nachricht'], $n['retain'])) {
                    return $gesammelt;
                }
            }
            $rest = $ende - microtime(true);
            if ($rest <= 0) {
                break;
            }
            $paket = $this->paketLesen(min(1.0, $rest));
            if ($paket !== null) {
                $this->verarbeiten($paket);
            }
            $this->ping();
        }
        return $gesammelt;
    }

    /** Hält die Verbindung am Leben – für den Sammler zwischen zwei Runden. */
    public function ping(): void
    {
        if ($this->sock !== null && microtime(true) - $this->letzteAktivitaet > $this->keepAlive * 0.6) {
            $this->senden(0xC0, '');
        }
    }

    public function verbunden(): bool
    {
        return $this->sock !== null && !feof($this->sock);
    }

    public function trennen(): void
    {
        if ($this->sock !== null) {
            @fwrite($this->sock, chr(0xE0) . chr(0));
        }
        $this->schliessen();
    }

    public function __destruct()
    {
        $this->schliessen();
    }

    // ------------------------------------------------------------ innen

    private function verarbeiten(array $paket): void
    {
        if ($paket['typ'] !== 3) {
            return;
        }
        $daten = $paket['daten'];
        $laenge = unpack('n', substr($daten, 0, 2))[1];
        $thema = substr($daten, 2, $laenge);
        $rest = substr($daten, 2 + $laenge);
        $qos = ($paket['flags'] >> 1) & 0x03;
        if ($qos > 0) {
            $id = unpack('n', substr($rest, 0, 2))[1];
            $rest = substr($rest, 2);
            $this->senden(0x40, pack('n', $id)); // PUBACK
        }
        $this->eingang[] = ['thema' => $thema, 'nachricht' => $rest, 'retain' => ($paket['flags'] & 0x01) === 1];
    }

    private function senden(int $kopf, string $rumpf): void
    {
        if ($this->sock === null) {
            throw new \RuntimeException('Keine Verbindung zum MQTT-Broker.');
        }
        $paket = chr($kopf) . self::laenge(strlen($rumpf)) . $rumpf;
        $geschrieben = 0;
        while ($geschrieben < strlen($paket)) {
            $n = @fwrite($this->sock, substr($paket, $geschrieben));
            if ($n === false || $n === 0) {
                $this->schliessen();
                throw new \RuntimeException('Verbindung zum MQTT-Broker abgebrochen.');
            }
            $geschrieben += $n;
        }
        $this->letzteAktivitaet = microtime(true);
    }

    /** @return array{typ:int,flags:int,daten:string}|null */
    private function paketLesen(float $zeit): ?array
    {
        if ($this->sock === null) {
            return null;
        }
        $lesen = [$this->sock];
        $leer = null;
        $sek = (int) floor($zeit);
        $usek = (int) (($zeit - $sek) * 1_000_000);
        if (@stream_select($lesen, $leer, $leer, $sek, $usek) < 1) {
            return null;
        }
        $erstes = fread($this->sock, 1);
        if ($erstes === false || $erstes === '') {
            $this->schliessen();
            return null;
        }
        $byte = ord($erstes);
        $laenge = 0;
        $faktor = 1;
        do {
            $b = fread($this->sock, 1);
            if ($b === false || $b === '') {
                $this->schliessen();
                return null;
            }
            $b = ord($b);
            $laenge += ($b & 0x7F) * $faktor;
            $faktor *= 128;
        } while (($b & 0x80) !== 0 && $faktor <= 128 * 128 * 128);
        $daten = '';
        while (strlen($daten) < $laenge) {
            $stueck = fread($this->sock, $laenge - strlen($daten));
            if ($stueck === false || $stueck === '') {
                $this->schliessen();
                return null;
            }
            $daten .= $stueck;
        }
        return ['typ' => $byte >> 4, 'flags' => $byte & 0x0F, 'daten' => $daten];
    }

    private function naechsteId(): int
    {
        $this->paketId = $this->paketId % 65535 + 1;
        return $this->paketId;
    }

    private function schliessen(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    private static function zeichen(string $text): string
    {
        return pack('n', strlen($text)) . $text;
    }

    private static function laenge(int $n): string
    {
        $out = '';
        do {
            $byte = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($n > 0);
        return $out;
    }
}
