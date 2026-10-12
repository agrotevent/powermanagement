<?php
declare(strict_types=1);

/**
 * Helfer deploy/mqtt-zugaenge.sh: eigene MQTT-Zugänge je Shelly in Mosquitto.
 *
 * Eigener Mosquitto mit der echten ACL aus deploy/mosquitto (wirksame Datei
 * vom Helfer gebaut). Geprüft: Namensregeln (--nur-namen), Block oben in der
 * Kennwortdatei (übrige Zeilen Byte für Byte), zweiter Lauf ohne Änderung
 * und ohne Neuladen, Ablehnungen (Exit 2, nichts geändert), fehlendes Soll,
 * abgelaufene Prüf-Sperren, Grundlage ohne „user shelly“ oder kaputt,
 * Abgleich des Installationsskripts, keine Hashes in Ausgaben – und die
 * Rechte am Broker: gemeinsamer Zugang, Gerätezugänge Gen2/Gen1, Sperre
 * (auch für ein Abo von vor dem Neuladen), Neuladen trennt niemanden.
 */

require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/mqtt-broker.php';

if (mqttMosquittoPfad() === null) {
    uebersprungen('mqtt-helfer', 'mosquitto ist nicht installiert');
}
if (!is_file('/bin/bash') || trim((string) shell_exec('command -v flock')) === '') {
    uebersprungen('mqtt-helfer', 'bash oder flock fehlt');
}

const KOPF = "# powermanagement mqtt-zugaenge 1\n";
const MARKE_ANFANG = '# >>> powermanagement geraetezugaenge - verwaltet von mqtt-zugaenge.sh, nicht von Hand aendern';
const MARKE_ENDE = '# <<< powermanagement geraetezugaenge';

$kennung = bin2hex(random_bytes(3));
$kw = ['shelly' => 'gemeinsam-' . bin2hex(random_bytes(6)), 'nodered' => 'nr-' . bin2hex(random_bytes(6)),
    'powermanagement' => 'pm-' . bin2hex(random_bytes(6))];
$broker = mqttTestbroker($kw);

/** Alle Ausgaben der Helfer – darin darf nie ein Hash stehen. */
$ausgaben = '';
$lauf = 0;
$helfer = static function (array $zusatz = [], ?string $stdin = null) use ($broker, &$ausgaben): array {
    $r = mqttHelfer($broker, $zusatz, $stdin);
    $ausgaben .= $r['ausgabe'] . $r['fehler'] . (string) @file_get_contents($broker['ist']);
    $zeilen = explode("\n", trim($r['ausgabe']));
    $r['letzte'] = (string) end($zeilen);
    return $r;
};
$soll = static function (array $konten, string $vorspann = '') use ($broker, &$lauf): int {
    $lauf++;
    $text = KOPF . "lauf {$lauf}\n" . $vorspann;
    foreach ($konten as [$benutzer, $generation, $hash, $sperre]) {
        $text .= "konto {$benutzer} {$generation} {$hash} {$sperre}\n";
    }
    file_put_contents($broker['soll'] . '.neu', $text);
    rename($broker['soll'] . '.neu', $broker['soll']);
    return $lauf;
};
$sollRoh = static function (string $text) use ($broker): void {
    file_put_contents($broker['soll'] . '.neu', $text);
    rename($broker['soll'] . '.neu', $broker['soll']);
};
/** Ergebnis-Datei lesen: einfache Werte und Listen (konto, gesperrt, fehler). */
$ergebnis = static function () use ($broker): array {
    $e = ['konto' => [], 'gesperrt' => [], 'fehler' => []];
    foreach (explode("\n", (string) @file_get_contents($broker['ist'])) as $zeile) {
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        [$k, $v] = array_pad(explode(' ', $zeile, 2), 2, '');
        if (in_array($k, ['konto', 'gesperrt', 'fehler'], true)) {
            $e[$k][] = $v;
        } else {
            $e[$k] = $v;
        }
    }
    return $e;
};
$datei = static function (string $pfad): array {
    clearstatcache(true, $pfad);
    $s = @stat($pfad);
    return ['inhalt' => (string) @file_get_contents($pfad), 'ino' => $s['ino'] ?? 0, 'mtime' => $s['mtime'] ?? 0];
};

// Geräte dieser Prüfung
$g2 = 'shellyplus1pm-' . $kennung . 'a0b1c2';
$g2Name = 'shelly/halle-' . $kennung;
$g1 = 'shelly1-' . strtoupper($kennung);
$frei = 'shellypro3em-' . $kennung . 'ffffff'; // ohne eigenen Zugang
$kwG2 = 'g2-' . bin2hex(random_bytes(8));
$kwG2Name = 'g2n-' . bin2hex(random_bytes(8));
$kwG1 = 'g1-' . bin2hex(random_bytes(8));
$hG2 = mqttHash($kwG2);
$hG2Name = mqttHash($kwG2Name);
$hG1 = mqttHash($kwG1);
$grund = [[$g2, 2, $hG2, '-'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']];

$clients = [];
$verbinden = static function (string $benutzer, string $kennwort, string $name) use ($broker, $kennung, &$clients): \App\Messung\Mqtt {
    $c = mqttVerbinden($broker, $benutzer, $kennwort, 'pm-pruef-' . $kennung . '-' . $name);
    $clients[] = $c;
    return $c;
};

try {
    // --------------------------------------------- Namensregeln
    $faelle = [
        [2, 'shellypro4pm-a0b1c2d3e4f5', 'ok'], [2, 'shelly/halle-3', 'ok'], [1, 'shelly1-B929CC', 'ok'],
        [2, str_repeat('a', 64), 'ok'], [2, 'shelly/command', 'ok'], [1, 'shelly1-b929cc', 'ok'],
        [2, 'shelly', 'reserviert'], [2, 'shellies', 'reserviert'], [2, 'nodered', 'reserviert'],
        [2, 'powermanagement', 'reserviert'], [2, 'pm', 'reserviert'], [2, 'pm-x', 'reserviert'],
        [1, 'PM_x', 'reserviert'], [1, 'pm.x', 'reserviert'], [1, 'command', 'reserviert'], [1, 'announce', 'reserviert'],
        [1, 'Shellies', 'reserviert'], [1, 'NodeRed', 'reserviert'],
        [2, 'PM_x', 'zeichen'], [2, 'a/b', 'zeichen'], [2, 'shelly/a/b', 'zeichen'], [2, 'Shelly/x', 'zeichen'],
        [1, 'shellies/x', 'zeichen'], [1, 'shelly/x', 'zeichen'], [2, 'ABC', 'zeichen'], [2, 'x y', 'zeichen'],
        [2, 'x+', 'zeichen'], [2, 'x#', 'zeichen'], [2, 'x:y', 'zeichen'], [2, '$SYS', 'zeichen'], [2, '%u', 'zeichen'],
        [2, '', 'zeichen'], [2, str_repeat('a', 65), 'zeichen'], [2, '-x', 'zeichen'], [2, 'shelly/', 'zeichen'],
        [2, "ab\0c", 'zeichen'], [2, "abc\r", 'zeichen'],
        [3, 'abc', 'generation'], [0, 'abc', 'generation'], ['x', 'abc', 'generation'],
    ];
    $eingabe = '';
    foreach ($faelle as [$generation, $name]) {
        $eingabe .= $generation . ' ' . $name . "\n";
    }
    $r = $helfer(['--nur-namen'], $eingabe);
    gleich($r['code'], 0, '--nur-namen endet mit 0');
    $antworten = explode("\n", rtrim($r['ausgabe'], "\n"));
    gleich(count($antworten), count($faelle), '--nur-namen: eine Antwort je Zeile');
    foreach ($faelle as $i => [$generation, $name, $code]) {
        gleich($antworten[$i] ?? '', $code === 'ok' ? 'ok' : 'fehler ' . $code,
            'Namensregel Gen' . $generation . ' „' . addcslashes($name, "\0\r") . '“');
    }

    // --------------------------------------------- Aufruf
    $r = $helfer(['--unbekannt=1']);
    gleich($r['code'], 1, 'unbekannte Option → Exit 1');
    $r = $helfer(['--neu-laden=sofort']);
    gleich($r['code'], 1, '--neu-laden mit unbekanntem Wert → Exit 1');
    $r = $helfer(['--ziel=relativ']);
    gleich($r['code'], 1, '--ziel muss absolut sein');

    // --------------------------------------------- Block oben, Rest Byte für Byte
    // Von Hand angelegte Zeilen außerhalb des Blocks, die letzte ohne Zeilenende.
    // Mosquitto liest „ leer-… :“ als „leer-…“ und „::doppel-…:“ als „doppel-…“.
    $leer = 'leer-' . $kennung;
    $doppel = 'doppel-' . $kennung;
    $aussen = (string) file_get_contents($broker['passwd'])
        . "# von Hand angelegt\nhandbenutzer:" . mqttHash('hand') . "\n\n {$leer}\t:" . mqttHash('leer')
        . "\n::{$doppel}:" . mqttHash('doppel') . "\nzweiter-hand:" . mqttHash('zwei');
    file_put_contents($broker['passwd'], $aussen);
    $r = $helfer();
    gleich($r['code'], 0, 'ohne Soll: Exit 0');
    gleich($r['letzte'], 'unveraendert', 'ohne Soll und ohne Block: nichts geändert');
    gleich((string) file_get_contents($broker['passwd']), $aussen, 'ohne Soll und Block bleibt die Kennwortdatei, wie sie ist');
    gleich($ergebnis()['lauf'] ?? null, '-', 'Ergebnis ohne Soll: lauf -');

    $l = $soll($grund);
    $r = $helfer();
    gleich($r['code'], 0, 'Soll mit drei Konten: Exit 0');
    gleich($r['letzte'], 'geaendert', 'Soll mit drei Konten: geändert');
    $e = $ergebnis();
    gleich($e['status'] ?? null, 'ok', 'Ergebnis: status ok');
    gleich($e['lauf'] ?? null, (string) $l, 'Ergebnis nennt den verarbeiteten Lauf');
    gleich($e['acl_wirksam'] ?? null, 'ja', 'Ergebnis: acl_wirksam ja (Konfiguration nennt die wirksame Datei)');
    gleich($e['basis_muster'] ?? null, 'ja', 'Ergebnis: basis_muster ja (echte Grundlage)');
    gleich($e['sperre_moeglich'] ?? null, 'ja', 'Ergebnis: sperre_moeglich ja');
    $erwartet = [$g2, $g2Name, $g1];
    sort($erwartet, SORT_STRING);
    gleich($e['konto'], $erwartet, 'Ergebnis: je Konto eine Zeile');
    gleich($e['gesperrt'], [], 'Ergebnis: noch keine Sperre');
    $p = (string) file_get_contents($broker['passwd']);
    pruefe(str_starts_with($p, MARKE_ANFANG . "\n"), 'Block steht oben in der Kennwortdatei');
    $ende = strpos($p, MARKE_ENDE . "\n");
    pruefe($ende !== false && substr($p, $ende + strlen(MARKE_ENDE) + 1) === $aussen,
        'Zeilen außerhalb des Blocks Byte für Byte und in alter Reihenfolge (auch ohne letztes Zeilenende)');
    pruefe(str_contains($p, "\n# konto {$g1} 1 -\n") && str_contains($p, "\n{$g1}:{$hG1}\n"), 'Block: Kommentar und Hash je Konto');
    gleich((string) file_get_contents($broker['wirksam']), (string) file_get_contents($broker['basis']), 'ohne Sperre: wirksame ACL = Grundlage');

    $vorP = $datei($broker['passwd']);
    $vorW = $datei($broker['wirksam']);
    $neuGeladen = mqttNeuGeladen($broker);
    sleep(1); // eine Sekunde Abstand, damit eine neue mtime auffiele
    $r = $helfer();
    gleich($r['letzte'], 'unveraendert', 'zweiter Lauf: unveraendert');
    gleich($ergebnis()['status'] ?? null, 'unveraendert', 'zweiter Lauf: status unveraendert');
    $nachP = $datei($broker['passwd']);
    $nachW = $datei($broker['wirksam']);
    pruefe($nachP == $vorP && $nachW == $vorW, 'zweiter Lauf: Kennwortdatei und wirksame ACL unberührt (Inhalt, Inode, mtime)');
    gleich(mqttNeuGeladen($broker), $neuGeladen, 'zweiter Lauf: Broker nicht neu geladen');

    // --------------------------------------------- Rechte am Broker
    $nr = $verbinden('nodered', $kw['nodered'], 'nr');
    // So liest Mosquitto die eingerückten Namen – darum zählen sie als reserviert.
    try {
        $verbinden($leer, 'leer', 'leer')->trennen();
        $verbinden($doppel, 'doppel', 'doppel')->trennen();
        pruefe(true, 'Mosquitto kennt „ name :“ und „::name:“ als Benutzer name');
    } catch (\RuntimeException $e) {
        pruefe(false, 'Mosquitto kennt „ name :“ und „::name:“ als Benutzer name: ' . $e->getMessage());
    }
    $sh = $verbinden('shelly', $kw['shelly'], 'shelly');
    pruefe(mqttKommtAn($sh, $nr, $frei . '/status/switch:0'), 'gemeinsamer Zugang schreibt in den Baum eines Geräts ohne eigenen Zugang');
    pruefe(mqttKommtAn($nr, $sh, $frei . '/rpc'), 'gemeinsamer Zugang liest den Baum eines Geräts ohne eigenen Zugang');
    pruefe(mqttKommtAn($nr, $sh, 'shellies/command'), 'gemeinsamer Zugang liest shellies/command');
    pruefe(!mqttKommtAn($sh, $nr, 'pm/messwert/' . $frei . '/x'), 'gemeinsamer Zugang schreibt nicht unter pm/');

    $d2 = $verbinden($g2, $kwG2, 'g2');
    pruefe(mqttKommtAn($d2, $nr, $g2 . '/status/switch:0'), 'Gen2-Zugang schreibt in den eigenen Baum');
    pruefe(mqttKommtAn($nr, $d2, $g2 . '/rpc'), 'Gen2-Zugang liest RPC-Anfragen im eigenen Baum');
    pruefe(mqttKommtAn($d2, $nr, $g2 . '/pm-steuerung-0a1b2c3d/rpc'), 'Gen2-Zugang antwortet an <präfix>/pm-steuerung-…/rpc');
    pruefe(!mqttKommtAn($d2, $nr, $frei . '/rpc'), 'Gen2-Zugang schreibt nicht in einen fremden Baum (<anderes>/rpc)');
    pruefe(!mqttKommtAn($d2, $nr, 'pm/messwert/' . $g2 . '/x'), 'Gen2-Zugang schreibt nicht unter pm/messwert/');
    pruefe(!mqttKommtAn($d2, $nr, 'pm-nodered/rpc'), 'Gen2-Zugang schreibt nicht an pm-nodered/rpc');
    pruefe(!mqttKommtAn($d2, $nr, 'shellies/announce'), 'Gen2-Zugang schreibt nicht in shellies/announce');
    pruefe(!mqttKommtAn($nr, $d2, $frei . '/status/switch:0'), 'Gen2-Zugang liest keinen fremden Baum');
    $d2n = $verbinden($g2Name, $kwG2Name, 'g2n');
    pruefe(mqttKommtAn($d2n, $nr, $g2Name . '/status/switch:0'), 'Gen2-Zugang mit Präfix shelly/<name> schreibt in den eigenen Baum');
    pruefe(!mqttKommtAn($d2n, $nr, 'shelly/anderes-' . $kennung . '/rpc'), 'Gen2-Zugang shelly/<name> schreibt nicht in shelly/<anderes>/');
    $d1 = $verbinden($g1, $kwG1, 'g1');
    pruefe(mqttKommtAn($nr, $d1, 'shellies/command'), 'Gen1-Zugang liest shellies/command');
    pruefe(mqttKommtAn($nr, $d1, 'shellies/' . $g1 . '/relay/0/command'), 'Gen1-Zugang liest shellies/<id>/relay/0/command');
    pruefe(mqttKommtAn($d1, $nr, 'shellies/' . $g1 . '/relay/0'), 'Gen1-Zugang schreibt shellies/<id>/relay/0');
    pruefe(!mqttKommtAn($d1, $nr, 'shellies/announce'), 'Gen1-Zugang schreibt nicht in shellies/announce');
    pruefe(!mqttKommtAn($d1, $nr, 'shellies/shelly1-' . $kennung . 'X/relay/0'), 'Gen1-Zugang schreibt nicht in einen fremden Gen1-Baum');

    // Abo und Sitzung des gemeinsamen Zugangs von vor dem Neuladen
    $shAlt = $verbinden('shelly', $kw['shelly'], 'shelly-alt');
    $shAlt->abonnieren([$g2 . '/#']);
    $angekommen = false;
    $nr->veroeffentlichen($g2 . '/status/kontrolle', 'x', 1);
    $shAlt->lauschen(2, static function (string $t) use ($g2, &$angekommen): bool {
        return $angekommen = $angekommen || $t === $g2 . '/status/kontrolle';
    });
    pruefe($angekommen, 'vor der Sperre: Abo des gemeinsamen Zugangs auf den Gerätebaum bekommt Nachrichten');
    $shUnveraendert = $verbinden('shelly', $kw['shelly'], 'shelly-unveraendert');

    // --------------------------------------------- Sperre „dauerhaft“
    $l = $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    gleich($r['letzte'], 'geaendert', 'Sperre: geändert');
    $e = $ergebnis();
    gleich($e['gesperrt'], [$g2], 'Ergebnis: gesperrt <präfix>');
    $w = (string) file_get_contents($broker['wirksam']);
    $teil = "\n# --- erzeugt von mqtt-zugaenge.sh - nicht von Hand aendern ---\n"
        . "# Gemeinsamer Zugang: Themen der Shellys mit eigenem Zugang weder lesen noch schreiben.\nuser shelly\ntopic deny {$g2}/#\n";
    gleich($w, (string) file_get_contents($broker['basis']) . $teil, 'wirksame ACL = Grundlage + erzeugter Teil');
    pruefe(str_contains((string) file_get_contents($broker['passwd']), "# konto {$g2} 2 dauerhaft\n"), 'Block hält die Sperre fest');

    $sh2 = $verbinden('shelly', $kw['shelly'], 'shelly-neu');
    pruefe(!mqttKommtAn($sh2, $nr, $g2 . '/rpc'), 'gesperrt: gemeinsamer Zugang schreibt nicht mehr in den Baum');
    pruefe(!mqttKommtAn($nr, $sh2, $g2 . '/status/switch:0'), 'gesperrt: gemeinsamer Zugang liest den Baum nicht mehr');
    $angekommen = false;
    $thema = $g2 . '/status/vorher';
    $nr->veroeffentlichen($thema, 'x', 1);
    $shAlt->lauschen(0.6, static function (string $t) use ($thema, &$angekommen): bool {
        return $angekommen = $angekommen || $t === $thema;
    });
    pruefe(!$angekommen, 'gesperrt: auch ein Abo von vor dem Neuladen bekommt nichts mehr');
    pruefe(mqttKommtAn($sh2, $nr, $frei . '/rpc'), 'gesperrt: gemeinsamer Zugang erreicht andere Bäume weiter');
    pruefe(mqttKommtAn($sh2, $nr, $g2Name . '/rpc'), 'gesperrt: nicht gesperrtes Gerät mit eigenem Zugang bleibt erreichbar');
    pruefe(mqttKommtAn($shUnveraendert, $nr, $frei . '/status/switch:1'), 'Neuladen (SIGHUP) lässt die Sitzung des gemeinsamen Zugangs bestehen');
    pruefe(mqttKommtAn($d2, $nr, $g2 . '/status/switch:1'), 'Neuladen lässt die Sitzung des Gerätezugangs bestehen');

    // --------------------------------------------- Prüf-Sperre bis:
    $l = $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, 'bis:' . (time() + 3)], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    $erwartet = [$g2, $g2Name];
    sort($erwartet, SORT_STRING);
    gleich($ergebnis()['gesperrt'], $erwartet, 'bis: Sperre gilt bis zum Ablauf');
    pruefe(!mqttKommtAn($sh2, $nr, $g2Name . '/rpc'), 'bis: gemeinsamer Zugang gesperrt');
    sleep(4);
    $r = $helfer(); // wie der Zeitgeber: gleiches Soll, Sperre abgelaufen
    gleich($r['letzte'], 'geaendert', 'abgelaufene Sperre: wirksame ACL geändert');
    gleich($ergebnis()['gesperrt'], [$g2], 'abgelaufene Sperre fällt weg');
    pruefe(str_contains((string) file_get_contents($broker['passwd']), "# konto {$g2Name} 2 -\n"), 'abgelaufene Sperre im Block als „-“');
    pruefe(mqttKommtAn($sh2, $nr, $g2Name . '/rpc'), 'abgelaufene Sperre: gemeinsamer Zugang erreicht das Gerät wieder');
    $l = $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, 'bis:' . (time() - 5)], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    gleich($ergebnis()['gesperrt'], [$g2], 'bereits abgelaufenes bis: gilt als „-“');
    gleich($r['code'], 0, 'bereits abgelaufenes bis: kein Fehler');

    // --------------------------------------------- Sperre eines Gen1-Geräts
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, 'dauerhaft']]);
    $r = $helfer();
    $erwartet = [$g2, 'shellies/' . $g1];
    sort($erwartet, SORT_STRING);
    gleich($ergebnis()['gesperrt'], $erwartet, 'Gen1 gesperrt: Präfix shellies/<id>');
    pruefe(!mqttKommtAn($sh2, $d1, 'shellies/' . $g1 . '/relay/0/command'), 'Gen1 gesperrt: gemeinsamer Zugang schaltet das Gerät nicht mehr');
    pruefe(!mqttKommtAn($d1, $sh2, 'shellies/' . $g1 . '/relay/0'), 'Gen1 gesperrt: gemeinsamer Zugang liest den Baum nicht mehr');
    pruefe(mqttKommtAn($nr, $d1, 'shellies/' . $g1 . '/relay/0/command'), 'Gen1 gesperrt: Node-RED erreicht das Gerät weiter');
    pruefe(mqttKommtAn($sh2, $nr, 'shellies/shelly1-' . $kennung . 'X/relay/0'), 'Gen1 gesperrt: anderer Gen1-Baum bleibt für den gemeinsamen Zugang');
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    gleich($ergebnis()['gesperrt'], [$g2], 'Gen1 wieder frei');

    // --------------------------------------------- Ablehnungen
    $ablehnen = static function (string $text, string $fehler, string $was) use ($broker, $helfer, $ergebnis, $sollRoh, $datei): void {
        $vorP = $datei($broker['passwd']);
        $vorW = $datei($broker['wirksam']);
        $sollRoh($text);
        $r = $helfer();
        gleich($r['code'], 2, $was . ': Exit 2');
        gleich($r['letzte'], 'abgelehnt', $was . ': letzte Zeile abgelehnt');
        $e = $ergebnis();
        gleich($e['status'] ?? null, 'abgelehnt', $was . ': status abgelehnt');
        gleich($e['fehler'], [$fehler], $was . ': fehler ' . $fehler);
        pruefe($datei($broker['passwd']) == $vorP, $was . ': Kennwortdatei unverändert');
        pruefe($datei($broker['wirksam']) == $vorW, $was . ': wirksame ACL unverändert');
    };
    $kopf = static function () use (&$lauf): string {
        $lauf++;
        return KOPF . "lauf {$lauf}\n";
    };
    $gut = "konto {$g2} 2 {$hG2} dauerhaft\nkonto {$g2Name} 2 {$hG2Name} -\nkonto {$g1} 1 {$hG1} -\n";
    $hx = mqttHash('x');
    $ablehnen($kopf() . "konto shelly 2 {$hx} -\n", '3 reserviert', 'reservierter Name shelly');
    $ablehnen($kopf() . $gut . "konto handbenutzer 2 {$hx} -\n", '6 reserviert', 'Name außerhalb des Blocks');
    $ablehnen($kopf() . $gut . "konto {$leer} 2 {$hx} -\n", '6 reserviert', 'Name außerhalb des Blocks mit Leerraum drumherum');
    $ablehnen($kopf() . $gut . "konto {$doppel} 2 {$hx} -\n", '6 reserviert', 'Name außerhalb des Blocks hinter „::“');
    $ablehnen($kopf() . $gut . "konto neu-{$kennung} 2 \$7\$101\$kurz -\n", '6 hash', 'kaputter Hash');
    $ablehnen($kopf() . "konto neu-{$kennung} 2 {$hx} -\r\n", '3 format', 'CR in der Datei');
    $ablehnen($kopf() . "konto neu-{$kennung} 2 {$hx} - extra\n", '3 format', 'überzähliges Feld');
    $ablehnen($kopf() . "konto  neu-{$kennung} 2 {$hx} -\n", '3 format', 'doppeltes Leerzeichen');
    $ablehnen($kopf() . "# Kommentar\n\nkonto neu-{$kennung} 2 {$hx} bis:" . (time() + 900) . "\n", '5 sperre', 'bis: mehr als 600 s voraus');
    $ablehnen($kopf() . "konto neu-{$kennung} 2 {$hx} bald\n", '3 sperre', 'unbekannte Sperre');
    $ablehnen($kopf() . "konto neu-{$kennung} 2 {$hx} -\nkonto neu-{$kennung} 2 {$hx} -\n", '4 doppelt', 'doppelter Benutzer');
    $ablehnen($kopf() . "konto Dup{$kennung} 1 {$hx} -\nkonto dup{$kennung} 2 {$hx} -\n", '4 geraet', 'doppeltes Gerät (shellies/X und x)');
    $ablehnen($kopf() . "konto neu-{$kennung} 3 {$hx} -\n", '3 generation', 'Generation 3');
    $ablehnen($kopf() . str_repeat('#' . str_repeat('x', 99) . "\n", 2700), '0 groesse', 'mehr als 262144 Byte');
    $ablehnen("# powermanagement mqtt-zugaenge 2\nlauf 1\n", '1 kopf', 'falscher Kopf');
    $ablehnen(KOPF . "lauf x\n", '2 lauf', 'falsche Lauf-Zeile');
    $ablehnen($kopf() . "konto\tneu 2 {$hx} -\n", '3 format', 'Tabulator');
    $ablehnen($kopf() . "entfernen-erlaubt vielleicht\n", '3 format', 'entfernen-erlaubt mit falschem Wert');
    $ablehnen($kopf() . "\nentfernen-erlaubt ja\n", '4 format', 'entfernen-erlaubt nicht direkt nach lauf');

    // Massen-Entfernen
    $viele = $grund;
    $viele[0][3] = 'dauerhaft';
    for ($i = 1; $i <= 5; $i++) {
        $viele[] = ['zusatz' . $i . '-' . $kennung, 2, $hx, '-'];
    }
    $soll($viele);
    $r = $helfer();
    gleich(count($ergebnis()['konto']), 8, 'acht Konten übernommen');
    $soll(array_slice($viele, 0, 3));
    $r = $helfer();
    gleich([$r['code'], $ergebnis()['status'] ?? null], [0, 'ok'], 'genau fünf Konten entfernen geht ohne Freigabe');
    $soll($viele);
    $helfer();
    $ablehnen($kopf() . "konto {$g2} 2 {$hG2} dauerhaft\nkonto {$g1} 1 {$hG1} -\n", '0 viele', 'sechs Konten entfernen ohne Freigabe');
    $l = $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g1, 1, $hG1, '-']], "entfernen-erlaubt ja\n");
    $r = $helfer();
    gleich([$r['code'], $ergebnis()['status'] ?? null, count($ergebnis()['konto'])], [0, 'ok', 2], 'mit entfernen-erlaubt ja geht es');
    $soll($viele);
    $helfer();
    $soll([]);
    $r = $helfer(['--viele-entfernen']);
    gleich([$r['code'], $ergebnis()['konto']], [0, []], 'leeres Soll mit --viele-entfernen leert den Block');
    pruefe(!str_contains((string) file_get_contents($broker['passwd']), MARKE_ANFANG), 'ohne Konten kein Block');
    gleich((string) file_get_contents($broker['passwd']), $aussen, 'ohne Konten: Kennwortdatei wieder wie vorher');

    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']]);
    $helfer();

    // --------------------------------------------- Neuladen scheitert
    // Dann kommt der alte Stand zurück, und der nächste Lauf versucht es erneut.
    $vorP = $datei($broker['passwd']);
    $vorW = $datei($broker['wirksam']);
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, 'dauerhaft'], [$g1, 1, $hG1, '-']]);
    $r = $helfer(['--neu-laden=pid:999999999']);
    $e = $ergebnis();
    gleich([$r['code'], $r['letzte']], [1, 'unveraendert'], 'Neuladen scheitert: Exit 1, nichts geändert');
    gleich([$e['status'] ?? null, $e['fehler']], ['fehler', ['0 neuladen']], 'Neuladen scheitert: fehler 0 neuladen');
    pruefe($datei($broker['passwd'])['inhalt'] === $vorP['inhalt'] && $datei($broker['wirksam'])['inhalt'] === $vorW['inhalt'],
        'Neuladen scheitert: Kennwortdatei und wirksame ACL wieder wie vorher');
    gleich($e['gesperrt'], [$g2], 'Neuladen scheitert: Ergebnis nennt, was gilt');
    $r = $helfer();
    $erwartet = [$g2, $g2Name];
    sort($erwartet, SORT_STRING);
    gleich([$r['code'], $r['letzte'], $ergebnis()['gesperrt']], [0, 'geaendert', $erwartet], 'nächster Lauf übernimmt es');
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']]);
    $helfer();

    // --------------------------------------------- fehlendes Soll
    unlink($broker['soll']);
    $vorP = $datei($broker['passwd']);
    $vorW = $datei($broker['wirksam']);
    $r = $helfer();
    gleich([$r['code'], $r['letzte']], [0, 'unveraendert'], 'fehlendes Soll: Exit 0, unveraendert');
    pruefe($datei($broker['passwd']) == $vorP && $datei($broker['wirksam']) == $vorW, 'fehlendes Soll ändert nichts');
    gleich($ergebnis()['gesperrt'], [$g2], 'fehlendes Soll: Sperren aus dem Block bleiben');

    // --------------------------------------------- Grundlage
    $grundlage = (string) file_get_contents($broker['basis']);
    file_put_contents($broker['basis'], str_replace("\nuser shelly\n", "\nuser shellyx\n", $grundlage));
    $r = $helfer();
    $e = $ergebnis();
    gleich($e['sperre_moeglich'] ?? null, 'nein', 'Grundlage ohne „user shelly“: sperre_moeglich nein');
    gleich($e['gesperrt'], [], 'Grundlage ohne „user shelly“: keine Sperre');
    gleich((string) file_get_contents($broker['wirksam']), (string) file_get_contents($broker['basis']),
        'Grundlage ohne „user shelly“: kein erzeugter Teil');
    file_put_contents($broker['basis'], $grundlage);
    $helfer();
    gleich($ergebnis()['gesperrt'], [$g2], 'Grundlage wieder in Ordnung: Sperre wieder da');

    file_put_contents($broker['basis'], $grundlage . "\nallow_anonymous true\n");
    $vorP = $datei($broker['passwd']);
    $vorW = $datei($broker['wirksam']);
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    $e = $ergebnis();
    gleich($r['code'], 1, 'kaputte Grundlage: Exit 1');
    gleich([$e['status'] ?? null, $e['fehler']], ['fehler', ['0 basis']], 'kaputte Grundlage: fehler 0 basis');
    pruefe($datei($broker['wirksam']) == $vorW, 'kaputte Grundlage: wirksame ACL unverändert');
    pruefe($datei($broker['passwd']) == $vorP, 'kaputte Grundlage: Kennwortdatei unverändert');
    gleich($e['gesperrt'], [$g2], 'kaputte Grundlage: Ergebnis nennt die Sperren der wirksamen Datei');
    file_put_contents($broker['basis'], $grundlage);
    $soll([[$g2, 2, $hG2, 'dauerhaft'], [$g2Name, 2, $hG2Name, '-'], [$g1, 1, $hG1, '-']]);
    $r = $helfer();
    gleich($r['code'], 0, 'Grundlage repariert: wieder Exit 0');

    // acl_wirksam: zwei acl_file-Zeilen
    $zweite = $broker['dir'] . '/zweite.conf';
    file_put_contents($zweite, "acl_file {$broker['basis']}\n");
    $helfer(['--conf=' . $broker['conf'], '--conf=' . $zweite]);
    gleich($ergebnis()['acl_wirksam'] ?? null, 'nein', 'zwei acl_file-Zeilen: acl_wirksam nein');
    $helfer();

    // --------------------------------------------- Markierungen
    $vorher = (string) file_get_contents($broker['passwd']);
    file_put_contents($broker['passwd'], MARKE_ANFANG . "\n" . $vorher);
    $r = $helfer();
    gleich([$r['code'], $ergebnis()['fehler']], [1, ['0 marker']], 'doppelte Anfangsmarkierung: fehler 0 marker, Exit 1');
    gleich((string) file_get_contents($broker['passwd']), MARKE_ANFANG . "\n" . $vorher, 'doppelte Anfangsmarkierung: nichts geändert');
    file_put_contents($broker['passwd'], str_replace("\n{$g1}:{$hG1}\n", "\n", $vorher));
    $r = $helfer();
    gleich([$r['code'], $ergebnis()['fehler']], [1, ['0 marker']], 'Block ohne Hash eines Kontos: fehler 0 marker');
    file_put_contents($broker['passwd'], $vorher);

    // --------------------------------------------- Abgleich des Installationsskripts
    // Wie deploy/install-debian.sh: die drei eigenen Benutzer neu, alles
    // andere (auch der Block) bleibt; danach ändert der Helfer nichts.
    $neu = tempnam(sys_get_temp_dir(), 'pm-pw-');
    $zusammen = tempnam(sys_get_temp_dir(), 'pm-pw-');
    $mosquittoPasswd = trim((string) shell_exec('command -v mosquitto_passwd'));
    if ($mosquittoPasswd !== '') {
        file_put_contents($neu, "shelly:{$kw['shelly']}\nnodered:{$kw['nodered']}\npowermanagement:{$kw['powermanagement']}\n");
        exec(escapeshellarg($mosquittoPasswd) . ' -U ' . escapeshellarg($neu) . ' 2>&1', $o, $rc);
        gleich($rc, 0, 'mosquitto_passwd -U wie im Installationsskript');
    } else {
        file_put_contents($neu, 'shelly:' . mqttHash($kw['shelly']) . "\nnodered:" . mqttHash($kw['nodered'])
            . "\npowermanagement:" . mqttHash($kw['powermanagement']) . "\n");
    }
    exec('awk -F: -v a=shelly -v b=nodered -v c=powermanagement \'$1 != a && $1 != b && $1 != c\' '
        . escapeshellarg($broker['passwd']) . ' > ' . escapeshellarg($zusammen) . ' && cat ' . escapeshellarg($neu)
        . ' >> ' . escapeshellarg($zusammen), $o, $rc);
    gleich($rc, 0, 'awk-Abgleich wie im Installationsskript');
    $gemischt = (string) file_get_contents($zusammen);
    pruefe(str_starts_with($gemischt, MARKE_ANFANG . "\n") && str_contains($gemischt, "\n{$g1}:{$hG1}\n"),
        'awk-Abgleich lässt den Block oben stehen');
    file_put_contents($broker['passwd'], $gemischt);
    @unlink($neu);
    @unlink($zusammen);
    $r = $helfer();
    gleich([$r['code'], $r['letzte']], [0, 'unveraendert'], 'nach dem Abgleich des Installationsskripts: Helfer ändert nichts');
    gleich((string) file_get_contents($broker['passwd']), $gemischt, 'nach dem Abgleich: Kennwortdatei Byte für Byte gleich');

    // --------------------------------------------- Sperrdatei
    file_put_contents($broker['lock'], "bleibt\n");
    $r = $helfer();
    gleich([$r['code'], (string) file_get_contents($broker['lock'])], [0, "bleibt\n"], 'Sperrdatei wird geöffnet, nie gekürzt');

    // --------------------------------------------- keine Hashes nach außen
    pruefe(!str_contains($ausgaben, '$7$'), 'kein Hash in Ausgabe, Fehlerausgabe oder Ergebnis-Datei');
    pruefe(!str_contains((string) @file_get_contents($broker['ist']), '$7$'), 'kein Hash in der Ergebnis-Datei');
} finally {
    foreach ($clients as $c) {
        try {
            $c->trennen();
        } catch (\Throwable) {
        }
    }
}

ende('mqtt-helfer');
