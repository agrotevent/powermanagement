<?php
declare(strict_types=1);

/**
 * Eine Modbus-TCP-Attrappe für die Prüfskripte.
 *
 *   php tests/hilfen/modbus-attrappe.php <port> '<json: {"unit": {"adresse": wert, …}, …}>'
 *
 * Beantwortet FC 3/4 (lesen) und FC 6/16 (schreiben) aus einer
 * Registertabelle je Unit-ID; unbekannte Register liefern die Modbus-
 * Ausnahme 2, eine unbekannte Unit die Ausnahme 11 – wie ein GX, an dem
 * ein Dienst fehlt. Endet nach 60 Sekunden von selbst.
 */

$port = (int) ($argv[1] ?? 1502);
$tabelle = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
$register = [];
foreach ($tabelle as $unit => $werte) {
    foreach ($werte as $adresse => $wert) {
        $register[(int) $unit][(int) $adresse] = ((int) $wert) & 0xFFFF;
    }
}

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $nr, $text);
if ($server === false) {
    fwrite(STDERR, $text . "\n");
    exit(1);
}
$ende = time() + 60;
$klienten = [];

while (time() < $ende) {
    $lesen = array_merge([$server], $klienten);
    $leer = null;
    if (@stream_select($lesen, $leer, $leer, 1) < 1) {
        continue;
    }
    foreach ($lesen as $s) {
        if ($s === $server) {
            $k = @stream_socket_accept($server, 1);
            if ($k !== false) {
                $klienten[] = $k;
            }
            continue;
        }
        $kopf = fread($s, 7);
        if ($kopf === false || strlen($kopf) < 7) {
            fclose($s);
            $klienten = array_values(array_filter($klienten, static fn ($x) => $x !== $s));
            continue;
        }
        $t = unpack('ntrans/nproto/nlaenge/Cunit', $kopf);
        $pdu = '';
        while (strlen($pdu) < $t['laenge'] - 1) {
            $pdu .= fread($s, $t['laenge'] - 1 - strlen($pdu));
        }
        $fc = ord($pdu[0]);
        $unit = $t['unit'];
        $fehler = static fn (int $code): string => chr($fc | 0x80) . chr($code);
        if (!isset($register[$unit])) {
            $antwort = $fehler(11);
        } elseif ($fc === 3 || $fc === 4) {
            ['a' => $adr, 'n' => $n] = unpack('na/nn', substr($pdu, 1, 4));
            $daten = '';
            $ok = true;
            for ($i = 0; $i < $n; $i++) {
                if (!array_key_exists($adr + $i, $register[$unit])) {
                    // Lücken in einem Block gibt es beim GX auch – dort
                    // kommt 0 zurück, solange der Block nicht ganz leer ist.
                    $daten .= pack('n', 0);
                    continue;
                }
                $daten .= pack('n', $register[$unit][$adr + $i]);
            }
            $bekannt = array_filter(array_keys($register[$unit]), static fn ($a) => $a >= $adr && $a < $adr + $n);
            $antwort = $bekannt === [] ? $fehler(2) : chr($fc) . chr($n * 2) . $daten;
            unset($ok);
        } elseif ($fc === 6) {
            ['a' => $adr, 'w' => $w] = unpack('na/nw', substr($pdu, 1, 4));
            $register[$unit][$adr] = $w;
            $antwort = $pdu;
        } elseif ($fc === 16) {
            ['a' => $adr, 'n' => $n] = unpack('na/nn', substr($pdu, 1, 4));
            $werte = array_values(unpack('n*', substr($pdu, 6, $n * 2)));
            foreach ($werte as $i => $w) {
                $register[$unit][$adr + $i] = $w;
            }
            $antwort = chr(16) . pack('nn', $adr, $n);
        } else {
            $antwort = $fehler(1);
        }
        fwrite($s, pack('nnnC', $t['trans'], 0, strlen($antwort) + 1, $unit) . $antwort);
    }
}
