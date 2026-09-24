<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * Modbus TCP – lesen und schreiben, ohne Bibliothek.
 *
 * Gebraucht für den Victron GX (Modbus TCP-Server auf Port 502, Unit-ID je
 * Dienst) und ComAp-Steuerungen (InteliGen 200 mit Ethernet bzw. über
 * AirGate/WireGuard erreichbar). Beide Gegenstellen sprechen den
 * Standard; die Registerbelegung steht im Registerprofil, nicht hier.
 *
 *   FC 3  Holding Register lesen     FC 6   ein Register schreiben
 *   FC 4  Input Register lesen       FC 16  mehrere Register schreiben
 */
final class ModbusTcp
{
    /** @var resource|null */
    private $sock = null;
    private int $transaktion = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 502,
        private readonly float $zeit = 3.0,
    ) {
    }

    public function verbinden(): void
    {
        if ($this->sock !== null) {
            return;
        }
        $sock = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $nr, $text, $this->zeit);
        if ($sock === false) {
            throw new \RuntimeException('Modbus-Gerät ' . $this->host . ':' . $this->port . ' nicht erreichbar: ' . $text);
        }
        stream_set_timeout($sock, (int) ceil($this->zeit));
        $this->sock = $sock;
    }

    public function trennen(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->trennen();
    }

    /** @return list<int> Registerwerte 0…65535 */
    public function lesen(int $unit, int $adresse, int $anzahl, int $funktion = 3): array
    {
        if ($anzahl < 1 || $anzahl > 125) {
            throw new \InvalidArgumentException('Es lassen sich 1 bis 125 Register auf einmal lesen.');
        }
        if (!in_array($funktion, [3, 4], true)) {
            throw new \InvalidArgumentException('Lesen geht mit Funktion 3 oder 4.');
        }
        $antwort = $this->anfrage($unit, $funktion, pack('nn', $adresse, $anzahl));
        $bytes = ord($antwort[0] ?? "\0");
        if ($bytes !== $anzahl * 2 || strlen($antwort) < 1 + $bytes) {
            throw new \RuntimeException('Modbus-Antwort hat die falsche Länge.');
        }
        return array_values(unpack('n*', substr($antwort, 1, $bytes)));
    }

    public function schreibenEinzeln(int $unit, int $adresse, int $wert): void
    {
        $this->anfrage($unit, 6, pack('nn', $adresse, $wert & 0xFFFF));
    }

    /** @param list<int> $werte */
    public function schreibenMehrere(int $unit, int $adresse, array $werte): void
    {
        $daten = pack('nnC', $adresse, count($werte), count($werte) * 2);
        foreach ($werte as $wert) {
            $daten .= pack('n', $wert & 0xFFFF);
        }
        $this->anfrage($unit, 16, $daten);
    }

    private function anfrage(int $unit, int $funktion, string $daten): string
    {
        $this->verbinden();
        $this->transaktion = ($this->transaktion + 1) % 65536;
        $pdu = chr($funktion) . $daten;
        $paket = pack('nnnC', $this->transaktion, 0, strlen($pdu) + 1, $unit) . $pdu;
        if (@fwrite($this->sock, $paket) !== strlen($paket)) {
            $this->trennen();
            throw new \RuntimeException('Modbus: Senden fehlgeschlagen.');
        }
        $kopf = $this->genau(7);
        $t = unpack('ntrans/nproto/nlaenge/Cunit', $kopf);
        $rest = $this->genau($t['laenge'] - 1);
        if ($t['trans'] !== $this->transaktion) {
            // Eine verspätete Antwort auf eine frühere Anfrage: Verbindung
            // neu aufbauen, statt Werte dem falschen Register zuzuordnen.
            $this->trennen();
            throw new \RuntimeException('Modbus: Antwort passt nicht zur Anfrage.');
        }
        $fc = ord($rest[0]);
        if ($fc === ($funktion | 0x80)) {
            $code = ord($rest[1] ?? "\0");
            throw new \RuntimeException('Modbus-Ausnahme ' . $code . ': ' . match ($code) {
                1 => 'Funktion nicht unterstützt',
                2 => 'Registeradresse ungültig (Unit-ID oder Adresse prüfen)',
                3 => 'Wert ungültig',
                4 => 'Gerätefehler',
                10, 11 => 'Gateway: Ziel nicht erreichbar (Unit-ID prüfen)',
                default => 'unbekannt',
            });
        }
        return substr($rest, 1);
    }

    private function genau(int $n): string
    {
        $daten = '';
        $ende = microtime(true) + $this->zeit;
        while (strlen($daten) < $n) {
            if (microtime(true) > $ende) {
                $this->trennen();
                throw new \RuntimeException('Modbus: Zeitüberschreitung beim Lesen der Antwort.');
            }
            $stueck = @fread($this->sock, $n - strlen($daten));
            if ($stueck === false || ($stueck === '' && feof($this->sock))) {
                $this->trennen();
                throw new \RuntimeException('Modbus: Verbindung geschlossen.');
            }
            $daten .= $stueck;
        }
        return $daten;
    }

    // ------------------------------------------------------------ Umrechnen

    /**
     * Rohregister in einen Wert übersetzen.
     *
     * @param list<int> $register ab der Adresse des Werts
     */
    public static function dekodieren(array $register, string $typ, bool $wortTausch = false): float|string|null
    {
        $w = static fn (int $i): int => $register[$i] ?? 0;
        $zwei = static function () use ($w, $wortTausch): int {
            return $wortTausch ? (($w(1) << 16) | $w(0)) : (($w(0) << 16) | $w(1));
        };
        return match ($typ) {
            'u16'     => $w(0) === 0xFFFF ? null : (float) $w(0),
            's16'     => (float) ($w(0) >= 0x8000 ? $w(0) - 0x10000 : $w(0)),
            'u32'     => (float) $zwei(),
            's32'     => (float) (($v = $zwei()) >= 0x80000000 ? $v - 0x100000000 : $v),
            'float32' => (float) unpack('G', pack('N', $zwei()))[1],
            'string'  => rtrim(implode('', array_map(static fn ($r) => pack('n', $r), $register)), "\0 "),
            default   => null,
        };
    }

    /** Wie viele Register ein Typ belegt. */
    public static function breite(string $typ, int $laenge = 1): int
    {
        return match ($typ) {
            'u32', 's32', 'float32' => 2,
            'string' => max(1, $laenge),
            default => 1,
        };
    }
}
