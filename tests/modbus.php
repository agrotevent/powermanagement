<?php
declare(strict_types=1);

/**
 * Modbus TCP: Victron-GX-Profil gegen eine Attrappe lesen, Werte skalieren,
 * Zustände übersetzen, schreiben (Schaltbefehl) – und der Sammler gibt die
 * Werte im vereinbarten Format weiter.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Abfrage;
use App\Messung\Influx;
use App\Messung\ModbusTcp;
use App\Messung\Profile;

Profile::grundbestand();

// Dekodieren ohne Netz
gleich(ModbusTcp::dekodieren([0xFFD4], 's16'), -44.0, 's16 negativ');
gleich(ModbusTcp::dekodieren([0x0001, 0x86A0], 'u32'), 100000.0, 'u32');
gleich(ModbusTcp::dekodieren([0x86A0, 0x0001], 'u32', true), 100000.0, 'u32 mit Wortvertauschung');
nahe((float) ModbusTcp::dekodieren([0x4148, 0x0000], 'float32'), 12.5, 0.0001, 'float32');
gleich(ModbusTcp::dekodieren([0xFFFF], 'u16'), null, 'u16 0xFFFF = kein Wert (Victron)');

$port = freierPort();
$tabelle = [
    '100' => [817 => 1200, 818 => 1100, 819 => 900, 820 => -300, 821 => 10, 822 => 20, 826 => 1,
              840 => 524, 841 => -125, 842 => -655, 843 => 87, 844 => 2, 846 => 36, 850 => 2500, 860 => 150, 806 => 0],
    '227' => [3 => 2301, 4 => 2305, 5 => 2299, 6 => 52, 7 => 48, 8 => 50, 9 => 5001, 15 => 2300, 16 => 2301, 17 => 2302,
              18 => 40, 19 => 41, 20 => 42, 21 => 4999, 23 => 92, 24 => 94, 25 => 96, 29 => 0, 31 => 9, 32 => 0, 33 => 3],
];
hintergrund(['php', __DIR__ . '/hilfen/modbus-attrappe.php', (string) $port, json_encode($tabelle)]);
for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
    usleep(100_000);
}

$quelle = ['id' => 0, 'art' => 'modbus', 'name' => 'GX Prüfung', 'geraet' => 'gx-pruefung', 'host' => '127.0.0.1', 'port' => $port,
    'unit_id' => 227, 'profil' => 'victron_gx', 'intervall_s' => 1, 'aktiv' => 1, 'extern_id' => ''];
$erg = Abfrage::ausfuehren($quelle);
pruefe($erg['ok'], 'GX gelesen: ' . (string) $erg['fehler']);
$k = $erg['kanaele'];
gleich($k['verbrauch']['phasen']['L1']['p'] ?? null, 1200.0, 'Verbrauch L1');
gleich($k['verbrauch']['phasen']['sum']['p'] ?? null, 3200.0, 'Summe ergänzt');
gleich($k['netz']['phasen']['L1']['p'] ?? null, -300.0, 'Netz L1 negativ (Einspeisung)');
nahe($k['batterie']['zustand']['u'] ?? null, 52.4, 0.001, 'Batteriespannung skaliert');
nahe($k['batterie']['zustand']['i'] ?? null, -12.5, 0.001, 'Batteriestrom skaliert');
gleich($k['batterie']['zustand']['soc'] ?? null, 87.0, 'SOC');
gleich($k['batterie']['zustand']['ladezustand_text'] ?? null, 'Entladen', 'Zustand übersetzt');
gleich($k['batterie']['zustand']['restlaufzeit_s'] ?? null, 3600.0, 'Restlaufzeit ×100');
gleich($k['system']['zustand']['eingangsquelle_text'] ?? null, 'Netz', 'aktive Eingangsquelle');
nahe($k['ac_ein']['phasen']['L1']['u'] ?? null, 230.1, 0.001, 'VE.Bus Eingang U L1 (Unit der Quelle)');
nahe($k['ac_ein']['phasen']['sum']['f'] ?? null, 50.01, 0.001, 'Frequenz ×0,01');
gleich($k['ac_aus']['phasen']['L1']['p'] ?? null, 920.0, 'Ausgangsleistung ×10');
gleich($k['vebus']['zustand']['zustand_text'] ?? null, 'Wechselrichten', 'VE.Bus-Zustand übersetzt');
gleich($k['vebus']['zustand']['schalterstellung_text'] ?? null, 'Ein', 'Schalterstellung übersetzt');

// Fehlender Dienst reißt die anderen nicht mit
$ohneVebus = $quelle;
$ohneVebus['unit_id'] = 99;
$erg = Abfrage::ausfuehren($ohneVebus);
pruefe($erg['ok'] && isset($erg['kanaele']['verbrauch']) && !isset($erg['kanaele']['ac_ein']), 'ohne VE.Bus: Systemwerte trotzdem da');

// Schreiben: VE.Bus aus (Register 33 = 4)
$mb = new ModbusTcp('127.0.0.1', $port);
$mb->schreibenEinzeln(227, 33, 4);
gleich($mb->lesen(227, 33, 1), [4], 'FC 6 geschrieben und zurückgelesen');
$mb->schreibenMehrere(100, 806, [1]);
gleich($mb->lesen(100, 806, 1), [1], 'FC 16 geschrieben');
try {
    $mb->lesen(100, 5000, 2);
    pruefe(false, 'unbekanntes Register meldet eine Ausnahme');
} catch (RuntimeException $e) {
    pruefe(str_contains($e->getMessage(), 'Ausnahme 2'), 'unbekanntes Register → Modbus-Ausnahme 2');
}
$mb->trennen();

// Nicht erreichbar → verständlicher Fehler
$tot = $quelle;
$tot['port'] = freierPort();
$erg = Abfrage::ausfuehren($tot);
pruefe(!$erg['ok'] && str_contains((string) $erg['fehler'], 'nicht erreichbar'), 'totes Ziel: Meldung „nicht erreichbar“');

// Profil ohne Adressen (ComAp-Vorlage) sagt, was fehlt
$comap = $quelle;
$comap['profil'] = 'comap_ig200';
$erg = Abfrage::ausfuehren($comap);
pruefe(!$erg['ok'] && str_contains((string) $erg['fehler'], 'keine Adresse'), 'ComAp-Vorlage ohne Adressen: klare Meldung');

// Profilprüfung
pruefe(Profile::pruefen([['kanal' => 'x', 'feld' => 'p', 'typ' => 'u64']], []) !== null, 'unbekannter Typ wird abgelehnt');
pruefe(Profile::pruefen([], [['schluessel' => 'a', 'name' => 'A', 'aktiv' => true, 'adresse' => null]]) !== null, 'aktiver Befehl ohne Adresse wird abgelehnt');

// Line Protocol aus einer normalisierten Nachricht
$zeilen = Influx::zeilen('gx pruef,1', 'batterie', ['ts' => 1700000000000, 'typ' => 'victron',
    'phasen' => ['L1' => ['p' => 1200, 'u' => 230.1]], 'zustand' => ['soc' => 87, 'an' => true, 'text' => 'Läuft "gut"']]);
gleich($zeilen[0] ?? '', 'strom,geraet=gx\ pruef\,1,kanal=batterie,phase=L1,typ=victron p=1200.0,u=230.1 1700000000000', 'Zeile strom (Floats, Escaping)');
gleich($zeilen[1] ?? '', 'zustand,geraet=gx\ pruef\,1,kanal=batterie,typ=victron soc=87.0,an=1.0,text="Läuft \"gut\"" 1700000000000', 'Zeile zustand (Text, Bool)');

ende('modbus');
