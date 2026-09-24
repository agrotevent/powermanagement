<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Audit;
use App\Support\Database;

/**
 * Registerprofile für Modbus TCP.
 *
 * Ein Profil sagt, welche Register ein Gerät hat und wohin der Wert
 * gehört. Je Eintrag:
 *
 *   name      Beschriftung
 *   kanal     Kanal in der InfluxDB (z. B. „netz“, „batterie“, „generator“)
 *   phase     L1, L2, L3, N oder sum → Messung „strom“; leer → „zustand“
 *   feld      u, i, p, q, s, pf, f, e (strom) bzw. frei (zustand, z. B. soc)
 *   adresse   Registeradresse (0-basiert, wie im Registerplan); null = noch
 *             nicht eingetragen, wird übersprungen
 *   typ       u16 | s16 | u32 | s32 | float32
 *   faktor    Rohwert × faktor (Victron „scale factor 10“ → 0.1)
 *   funktion  3 (Holding) oder 4 (Input)
 *   unit      feste Unit-ID oder null → die der Datenquelle
 *   werte     optional: Zahl → Text (Zustände), wird als <feld>_text geschrieben
 *
 * Befehle je Eintrag: schluessel, name, kanal, adresse, wert (oder werte
 * für FC 16), funktion (6|16), unit, aktiv. Befehle mit aktiv = false stehen
 * als Vorlage im Profil und werden erst nach Prüfung freigeschaltet.
 */
final class Profile
{
    /**
     * Die mitgelieferten Profile.
     *
     * Victron: nach der Registerliste „CCGX-Modbus-TCP-register-list“ von
     * Victron Energy (com.victronenergy.system auf Unit-ID 100,
     * com.victronenergy.vebus auf der Unit-ID des Multi/Quattro – am GX
     * unter Einstellungen → Dienste → Modbus TCP → Verfügbare Dienste
     * nachzulesen, häufig 227 oder 228). Die Liste ändert sich mit neuen
     * Venus-OS-Fassungen selten, aber sie ändert sich: im Zweifel mit der
     * aktuellen Fassung abgleichen.
     *
     * ComAp: Die Modbus-Belegung einer InteliGen 200 hängt an ihrer
     * Konfiguration und wird in InteliConfig erzeugt (Controller →
     * Modbus-Registerliste exportieren). Das Profil bringt deshalb die
     * Werte mit, die gebraucht werden, aber **ohne Adressen** – die kommen
     * aus dem Export der jeweiligen Steuerung.
     *
     * @return array<string,array{name:string,hersteller:string,beschreibung:string,register:list<array<string,mixed>>,befehle:list<array<string,mixed>>}>
     */
    public static function mitgeliefert(): array
    {
        $sys = 100;
        $r = static function (string $name, string $kanal, string $phase, string $feld, ?int $adresse, string $typ, float $faktor = 1, ?int $unit = null, array $werte = []): array {
            $eintrag = [
                'name' => $name, 'kanal' => $kanal, 'phase' => $phase, 'feld' => $feld, 'adresse' => $adresse,
                'typ' => $typ, 'faktor' => $faktor, 'funktion' => 3, 'unit' => $unit,
            ];
            if ($werte !== []) {
                $eintrag['werte'] = $werte;
            }
            return $eintrag;
        };

        $eingaenge = ['0' => 'unbekannt', '1' => 'Netz', '2' => 'Generator', '3' => 'Landstrom', '240' => 'nicht verbunden'];
        $vebusZustand = [
            '0' => 'Aus', '1' => 'Energiesparen', '2' => 'Fehler', '3' => 'Bulk', '4' => 'Absorption', '5' => 'Erhaltung',
            '6' => 'Lagerung', '7' => 'Ausgleich', '8' => 'Durchleitung', '9' => 'Wechselrichten', '10' => 'Netzunterstützung',
            '11' => 'Netzversorgung', '252' => 'Externe Steuerung',
        ];
        $schalter = ['1' => 'nur Laden', '2' => 'nur Wechselrichter', '3' => 'Ein', '4' => 'Aus'];

        return [
            'victron_gx' => [
                'name'         => 'Victron GX (System + VE.Bus)',
                'hersteller'   => 'Victron Energy',
                'beschreibung' => 'Cerbo GX, Venus GX, Ekrano: Systemwerte auf Unit-ID 100, Multi/Quattro auf der Unit-ID der Datenquelle (VE.Bus, häufig 227).',
                'register'     => [
                    $r('Verbrauch L1', 'verbrauch', 'L1', 'p', 817, 'u16', 1, $sys),
                    $r('Verbrauch L2', 'verbrauch', 'L2', 'p', 818, 'u16', 1, $sys),
                    $r('Verbrauch L3', 'verbrauch', 'L3', 'p', 819, 'u16', 1, $sys),
                    $r('Netz L1', 'netz', 'L1', 'p', 820, 's16', 1, $sys),
                    $r('Netz L2', 'netz', 'L2', 'p', 821, 's16', 1, $sys),
                    $r('Netz L3', 'netz', 'L3', 'p', 822, 's16', 1, $sys),
                    $r('Generator L1', 'generator', 'L1', 'p', 823, 's16', 1, $sys),
                    $r('Generator L2', 'generator', 'L2', 'p', 824, 's16', 1, $sys),
                    $r('Generator L3', 'generator', 'L3', 'p', 825, 's16', 1, $sys),
                    $r('PV am Ausgang L1', 'pv_ac', 'L1', 'p', 808, 'u16', 1, $sys),
                    $r('PV am Ausgang L2', 'pv_ac', 'L2', 'p', 809, 'u16', 1, $sys),
                    $r('PV am Ausgang L3', 'pv_ac', 'L3', 'p', 810, 'u16', 1, $sys),
                    $r('Aktive Eingangsquelle', 'system', '', 'eingangsquelle', 826, 'u16', 1, $sys, $eingaenge),
                    $r('Batteriespannung', 'batterie', '', 'u', 840, 'u16', 0.1, $sys),
                    $r('Batteriestrom', 'batterie', '', 'i', 841, 's16', 0.1, $sys),
                    $r('Batterieleistung', 'batterie', '', 'p', 842, 's16', 1, $sys),
                    $r('Ladezustand (SOC)', 'batterie', '', 'soc', 843, 'u16', 1, $sys),
                    $r('Batteriezustand', 'batterie', '', 'ladezustand', 844, 'u16', 1, $sys, ['0' => 'Ruhe', '1' => 'Laden', '2' => 'Entladen']),
                    $r('Restlaufzeit', 'batterie', '', 'restlaufzeit_s', 846, 'u16', 100, $sys),
                    $r('PV DC-gekoppelt', 'pv_dc', 'sum', 'p', 850, 'u16', 1, $sys),
                    $r('DC-System', 'dc_system', 'sum', 'p', 860, 's16', 1, $sys),
                    $r('Eingang Spannung L1', 'ac_ein', 'L1', 'u', 3, 'u16', 0.1),
                    $r('Eingang Spannung L2', 'ac_ein', 'L2', 'u', 4, 'u16', 0.1),
                    $r('Eingang Spannung L3', 'ac_ein', 'L3', 'u', 5, 'u16', 0.1),
                    $r('Eingang Strom L1', 'ac_ein', 'L1', 'i', 6, 's16', 0.1),
                    $r('Eingang Strom L2', 'ac_ein', 'L2', 'i', 7, 's16', 0.1),
                    $r('Eingang Strom L3', 'ac_ein', 'L3', 'i', 8, 's16', 0.1),
                    $r('Eingang Frequenz', 'ac_ein', 'sum', 'f', 9, 's16', 0.01),
                    $r('Eingang Leistung L1', 'ac_ein', 'L1', 'p', 12, 's16', 10),
                    $r('Eingang Leistung L2', 'ac_ein', 'L2', 'p', 13, 's16', 10),
                    $r('Eingang Leistung L3', 'ac_ein', 'L3', 'p', 14, 's16', 10),
                    $r('Ausgang Spannung L1', 'ac_aus', 'L1', 'u', 15, 'u16', 0.1),
                    $r('Ausgang Spannung L2', 'ac_aus', 'L2', 'u', 16, 'u16', 0.1),
                    $r('Ausgang Spannung L3', 'ac_aus', 'L3', 'u', 17, 'u16', 0.1),
                    $r('Ausgang Strom L1', 'ac_aus', 'L1', 'i', 18, 's16', 0.1),
                    $r('Ausgang Strom L2', 'ac_aus', 'L2', 'i', 19, 's16', 0.1),
                    $r('Ausgang Strom L3', 'ac_aus', 'L3', 'i', 20, 's16', 0.1),
                    $r('Ausgang Frequenz', 'ac_aus', 'sum', 'f', 21, 's16', 0.01),
                    $r('Eingangsstrombegrenzung', 'vebus', '', 'eingangsstrombegrenzung', 22, 's16', 0.1),
                    $r('Ausgang Leistung L1', 'ac_aus', 'L1', 'p', 23, 's16', 10),
                    $r('Ausgang Leistung L2', 'ac_aus', 'L2', 'p', 24, 's16', 10),
                    $r('Ausgang Leistung L3', 'ac_aus', 'L3', 'p', 25, 's16', 10),
                    $r('Aktiver AC-Eingang', 'vebus', '', 'aktiver_eingang', 29, 'u16', 1, null, ['0' => 'AC-Eingang 1', '1' => 'AC-Eingang 2', '240' => 'getrennt']),
                    $r('VE.Bus-Zustand', 'vebus', '', 'zustand', 31, 'u16', 1, null, $vebusZustand),
                    $r('VE.Bus-Fehler', 'vebus', '', 'fehler', 32, 'u16'),
                    $r('Schalterstellung', 'vebus', '', 'schalterstellung', 33, 'u16', 1, null, $schalter),
                ],
                'befehle' => [
                    ['schluessel' => 'vebus_ein', 'name' => 'Wechselrichter/Lader: Ein', 'kanal' => 'vebus', 'adresse' => 33, 'wert' => 3, 'funktion' => 6, 'unit' => null, 'aktiv' => true, 'art' => 'ein'],
                    ['schluessel' => 'vebus_aus', 'name' => 'Wechselrichter/Lader: Aus', 'kanal' => 'vebus', 'adresse' => 33, 'wert' => 4, 'funktion' => 6, 'unit' => null, 'aktiv' => true, 'art' => 'aus'],
                    ['schluessel' => 'vebus_laden', 'name' => 'Nur Laden', 'kanal' => 'vebus', 'adresse' => 33, 'wert' => 1, 'funktion' => 6, 'unit' => null, 'aktiv' => true, 'art' => 'modus'],
                    ['schluessel' => 'vebus_wr', 'name' => 'Nur Wechselrichter', 'kanal' => 'vebus', 'adresse' => 33, 'wert' => 2, 'funktion' => 6, 'unit' => null, 'aktiv' => true, 'art' => 'modus'],
                    ['schluessel' => 'relais1_ein', 'name' => 'GX-Relais 1: Ein', 'kanal' => 'relais1', 'adresse' => 806, 'wert' => 1, 'funktion' => 6, 'unit' => 100, 'aktiv' => true, 'art' => 'ein'],
                    ['schluessel' => 'relais1_aus', 'name' => 'GX-Relais 1: Aus', 'kanal' => 'relais1', 'adresse' => 806, 'wert' => 0, 'funktion' => 6, 'unit' => 100, 'aktiv' => true, 'art' => 'aus'],
                ],
            ],
            'comap_ig200' => [
                'name'         => 'ComAp InteliGen 200 (Vorlage)',
                'hersteller'   => 'ComAp',
                'beschreibung' => 'Werte eines Generatorsatzes. Die Adressen stehen in der Modbus-Registerliste, die InteliConfig für genau diese Steuerung erzeugt – bitte dort ablesen und hier eintragen. Einträge ohne Adresse werden übersprungen.',
                'register'     => [
                    $r('Generatorspannung L1-N', 'generator', 'L1', 'u', null, 'u16'),
                    $r('Generatorspannung L2-N', 'generator', 'L2', 'u', null, 'u16'),
                    $r('Generatorspannung L3-N', 'generator', 'L3', 'u', null, 'u16'),
                    $r('Generatorstrom L1', 'generator', 'L1', 'i', null, 'u16'),
                    $r('Generatorstrom L2', 'generator', 'L2', 'i', null, 'u16'),
                    $r('Generatorstrom L3', 'generator', 'L3', 'i', null, 'u16'),
                    $r('Wirkleistung L1', 'generator', 'L1', 'p', null, 's16', 1000),
                    $r('Wirkleistung L2', 'generator', 'L2', 'p', null, 's16', 1000),
                    $r('Wirkleistung L3', 'generator', 'L3', 'p', null, 's16', 1000),
                    $r('Wirkleistung gesamt', 'generator', 'sum', 'p', null, 's16', 1000),
                    $r('Blindleistung gesamt', 'generator', 'sum', 'q', null, 's16', 1000),
                    $r('Leistungsfaktor', 'generator', 'sum', 'pf', null, 's16', 0.01),
                    $r('Generatorfrequenz', 'generator', 'sum', 'f', null, 'u16', 0.1),
                    $r('Wirkarbeit gesamt', 'generator', 'sum', 'e', null, 'u32', 1000),
                    $r('Netzspannung L1-N', 'netz', 'L1', 'u', null, 'u16'),
                    $r('Netzspannung L2-N', 'netz', 'L2', 'u', null, 'u16'),
                    $r('Netzspannung L3-N', 'netz', 'L3', 'u', null, 'u16'),
                    $r('Netzfrequenz', 'netz', 'sum', 'f', null, 'u16', 0.1),
                    $r('Drehzahl', 'motor', '', 'drehzahl', null, 'u16'),
                    $r('Öldruck', 'motor', '', 'oeldruck_bar', null, 'u16', 0.1),
                    $r('Kühlmitteltemperatur', 'motor', '', 'kuehlmittel_c', null, 's16'),
                    $r('Tankfüllstand', 'motor', '', 'tank_prozent', null, 'u16'),
                    $r('Batteriespannung', 'motor', '', 'u_batterie', null, 'u16', 0.1),
                    $r('Betriebsstunden', 'motor', '', 'betriebsstunden', null, 'u32', 0.1),
                    $r('Anzahl Starts', 'motor', '', 'starts', null, 'u16'),
                    $r('Motorzustand', 'motor', '', 'motorzustand', null, 'u16'),
                    $r('Generatorschalter', 'motor', '', 'gcb', null, 'u16', 1, null, ['0' => 'offen', '1' => 'geschlossen']),
                ],
                'befehle' => [
                    // Nur als Vorlage – Adresse und Befehlscode stehen in der
                    // Liste „Modbus-Befehle“ der Steuerung. Erst nach Prüfung
                    // auf „aktiv“ setzen.
                    ['schluessel' => 'start', 'name' => 'Motor starten', 'kanal' => 'motor', 'adresse' => null, 'werte' => [], 'funktion' => 16, 'unit' => null, 'aktiv' => false, 'art' => 'ein'],
                    ['schluessel' => 'stopp', 'name' => 'Motor stoppen', 'kanal' => 'motor', 'adresse' => null, 'werte' => [], 'funktion' => 16, 'unit' => null, 'aktiv' => false, 'art' => 'aus'],
                    ['schluessel' => 'quittieren', 'name' => 'Fehler quittieren', 'kanal' => 'motor', 'adresse' => null, 'werte' => [], 'funktion' => 16, 'unit' => null, 'aktiv' => false, 'art' => 'quittieren'],
                ],
            ],
        ];
    }

    /** Mitgelieferte Profile anlegen bzw. auf den neuen Stand bringen (eigene bleiben unberührt). */
    public static function grundbestand(): int
    {
        $neu = 0;
        foreach (self::mitgeliefert() as $schluessel => $profil) {
            $vorhanden = Database::first('SELECT id FROM registerprofile WHERE schluessel = ?', [$schluessel]);
            $felder = [
                'name'             => $profil['name'],
                'hersteller'       => $profil['hersteller'],
                'beschreibung'     => $profil['beschreibung'],
                'register_json'    => json_encode($profil['register'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'befehle_json'     => json_encode($profil['befehle'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'ist_mitgeliefert' => 1,
                'updated_at'       => gmdate('c'),
            ];
            if ($vorhanden === null) {
                $felder['schluessel'] = $schluessel;
                $felder['created_at'] = gmdate('c');
                Database::insert('registerprofile', $felder);
                $neu++;
            }
        }
        return $neu;
    }

    /** @return list<array<string,mixed>> */
    public static function alle(): array
    {
        return array_map([self::class, 'aufbereiten'], Database::select('SELECT * FROM registerprofile ORDER BY hersteller, name'));
    }

    public static function find(string $schluessel): ?array
    {
        $p = Database::first('SELECT * FROM registerprofile WHERE schluessel = ?', [$schluessel]);
        return $p === null ? null : self::aufbereiten($p);
    }

    public static function findId(int $id): ?array
    {
        $p = Database::first('SELECT * FROM registerprofile WHERE id = ?', [$id]);
        return $p === null ? null : self::aufbereiten($p);
    }

    private static function aufbereiten(array $p): array
    {
        $p['register'] = json_decode((string) $p['register_json'], true) ?: [];
        $p['befehle'] = json_decode((string) ($p['befehle_json'] ?? ''), true) ?: [];
        $p['ohne_adresse'] = count(array_filter($p['register'], static fn ($r) => ($r['adresse'] ?? null) === null));
        return $p;
    }

    /**
     * Profil speichern (Register und Befehle als JSON aus dem Editor).
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Das Profil braucht einen Namen.'];
        }
        $register = json_decode((string) ($daten['register_json'] ?? '[]'), true);
        $befehle = json_decode((string) ($daten['befehle_json'] ?? '[]'), true);
        if (!is_array($register) || !is_array($befehle)) {
            return ['ok' => false, 'message' => 'Register oder Befehle sind kein gültiges JSON: ' . json_last_error_msg()];
        }
        $fehler = self::pruefen($register, $befehle);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $felder = [
            'name'          => mb_substr($name, 0, 190),
            'hersteller'    => mb_substr(trim((string) ($daten['hersteller'] ?? '')), 0, 64),
            'beschreibung'  => (string) ($daten['beschreibung'] ?? ''),
            'register_json' => json_encode(array_values($register), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'befehle_json'  => json_encode(array_values($befehle), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            // Wer ein mitgeliefertes Profil anpasst (etwa die ComAp-Adressen
            // einträgt), macht es zu seinem – ein Update überschreibt es nicht.
            'ist_mitgeliefert' => 0,
            'updated_at'    => gmdate('c'),
        ];
        if ($id) {
            Database::update('registerprofile', $id, $felder);
            Audit::log('profil.update', 'registerprofil', (string) $id, ['name' => $name]);
            return ['ok' => true, 'message' => 'Profil gespeichert.', 'id' => $id];
        }
        $schluessel = \App\Services\LocationBundle::schluessel($name, 'profil');
        while (Database::first('SELECT id FROM registerprofile WHERE schluessel = ?', [$schluessel]) !== null) {
            $schluessel .= '-2';
        }
        $felder['schluessel'] = $schluessel;
        $felder['created_at'] = gmdate('c');
        $id = Database::insert('registerprofile', $felder);
        Audit::log('profil.create', 'registerprofil', (string) $id, ['name' => $name]);
        return ['ok' => true, 'message' => 'Profil angelegt.', 'id' => $id];
    }

    /** Offensichtliche Fehler finden, bevor der Sammler daran scheitert. */
    public static function pruefen(array $register, array $befehle): ?string
    {
        $typen = ['u16', 's16', 'u32', 's32', 'float32'];
        foreach ($register as $i => $r) {
            $nr = $i + 1;
            if (!is_array($r) || trim((string) ($r['feld'] ?? '')) === '' || trim((string) ($r['kanal'] ?? '')) === '') {
                return "Register {$nr}: „kanal“ und „feld“ sind Pflicht.";
            }
            if (!in_array((string) ($r['typ'] ?? 'u16'), $typen, true)) {
                return "Register {$nr}: unbekannter Typ „" . ($r['typ'] ?? '') . '“ (erlaubt: ' . implode(', ', $typen) . ').';
            }
            if (($r['adresse'] ?? null) !== null && (!is_numeric($r['adresse']) || (int) $r['adresse'] < 0 || (int) $r['adresse'] > 65535)) {
                return "Register {$nr}: Adresse muss zwischen 0 und 65535 liegen.";
            }
            if (!in_array((int) ($r['funktion'] ?? 3), [3, 4], true)) {
                return "Register {$nr}: Funktion muss 3 oder 4 sein.";
            }
            $phase = (string) ($r['phase'] ?? '');
            if ($phase !== '' && !in_array($phase, ['L1', 'L2', 'L3', 'N', 'sum'], true)) {
                return "Register {$nr}: Phase muss L1, L2, L3, N, sum oder leer sein.";
            }
        }
        foreach ($befehle as $i => $b) {
            $nr = $i + 1;
            if (!is_array($b) || trim((string) ($b['schluessel'] ?? '')) === '' || trim((string) ($b['name'] ?? '')) === '') {
                return "Befehl {$nr}: „schluessel“ und „name“ sind Pflicht.";
            }
            if (!empty($b['aktiv']) && ($b['adresse'] ?? null) === null) {
                return "Befehl {$nr}: ein aktiver Befehl braucht eine Adresse.";
            }
            if (!in_array((int) ($b['funktion'] ?? 6), [6, 16], true)) {
                return "Befehl {$nr}: Funktion muss 6 oder 16 sein.";
            }
        }
        return null;
    }

    public static function loeschen(int $id): array
    {
        $p = self::findId($id);
        if ($p === null) {
            return ['ok' => false, 'message' => 'Dieses Profil gibt es nicht.'];
        }
        $benutzt = (int) Database::scalar('SELECT COUNT(*) FROM datenquellen WHERE profil = ?', [$p['schluessel']]);
        if ($benutzt > 0) {
            return ['ok' => false, 'message' => "Das Profil wird von {$benutzt} Datenquelle(n) benutzt."];
        }
        Database::delete('registerprofile', $id);
        Audit::log('profil.delete', 'registerprofil', (string) $id);
        return ['ok' => true, 'message' => 'Profil gelöscht.'];
    }
}
