<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Services\SchutzService;
use App\Support\Zeit;

/**
 * Aufbau und Bereinigung des Inhalts eines Übergabeberichts.
 *
 * Der Browser schickt den ganzen Inhalt als ein JSON-Feld (max_input_vars
 * schnitte sonst bei 80 Kreisen still ab). Durch kommt nur, was hier
 * beschrieben ist: unbekannte Schlüssel fallen weg, Texte werden gekürzt,
 * Zahlen werden Dezimaltext. Alle Werte sind Strings – Wahrheitswerte
 * "0"/"1" –, außer Listen und Objekten; so hängt der Hash des
 * eingefrorenen Inhalts nie an einer Zahlendarstellung.
 *
 * Was nur der Server setzt, nimmt bereinigen() aus dem gespeicherten Stand:
 * Schema, Katalog, Grenzwerte, die Qualifikation im Kopf und je Kreis die
 * IDs (objekt_id, anschluss_id, inventar_id) und den Abgang. Ein Kreis
 * findet seinen gespeicherten Stand über den Zeilenschlüssel „z“; im
 * Browser angelegte Zeilen haben keine IDs. Eine fremde ID aus der Anfrage
 * kommt so nie in den Bericht.
 */
final class BerichtSchema
{
    public const MAX_BYTES = 524288;
    public const MAX_KREISE = 300;
    public const MAX_MAENGEL = 100;
    public const MAX_GERAETE = 10;

    /** Je Kreis: nur vom Server (Vorbelegung), nie aus dem Browser. */
    public const SERVER_FELDER = ['objekt_id', 'anschluss_id', 'inventar_id', 'abgang'];

    /**
     * Vorgabe „Nächster Prüftermin“: nichtstationäre Anlagen – Event- und
     * Filmstrom – prüft man bei jedem Aufbau und nach Änderungen; läuft eine
     * Anlage länger, verlangt die DGUV Vorschrift 3 (Durchführungsanweisung,
     * Tab. 1A) die Wirksamkeit der RCD monatlich.
     */
    public const NAECHSTE = 'bei jedem Neuaufbau, nach Änderung oder Instandsetzung; bei längerem Betrieb spätestens nach 1 Monat (Wirksamkeit der RCD, DGUV V3 DA Tab. 1A)';

    /** Zeilenschlüssel: ein Buchstabe vorweg, damit er nie als Zahl gelesen wird. */
    public const ZEILE = '/^[a-z][a-z0-9]{5,15}$/';

    private const BIDI = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u';

    private static ?array $muster = null;

    public static function leer(): array
    {
        return [
            'schema'  => '1',
            'katalog' => (string) Katalog::VERSION,
            'kopf'    => [
                'veranstaltung' => '', 'auftrag_nr' => '', 'projekt_nr' => '',
                'pruefdatum' => self::heute(), 'zeitraum' => '', 'ort' => '',
                'qualifikation' => '',
                'projektleiter_kunde' => '', 'projektleiter_montage' => '',
                'anlagenverantwortlicher' => '', 'bereitschaft' => '',
                'kunde'  => ['firma' => '', 'anschrift' => '', 'kundennummer' => '', 'ansprechpartner' => '', 'telefon' => '', 'email' => '', 'quelle' => 'freitext'],
                'anlage' => ['bezeichnung' => '', 'details' => '', 'aufstellort' => ''],
            ],
            'netz'      => ['einspeisung' => '', 'spannung' => '400', 'frequenz' => '50', 'form' => '', 'erder' => '', 'u_ln' => '', 'drehfeld' => ''],
            'grundlage' => ['normen' => [], 'sonstige' => '', 'grund' => ''],
            'punkte'    => ['besichtigung' => [], 'erprobung' => []],
            'punkt_bemerkung' => [],
            'sicherheitskategorie' => '',
            'messung'   => [
                'erdung'  => ['op' => '', 'w' => '', 'methode' => '', 'basis' => '', 'grenze' => ''],
                'iso_bus' => ['op' => '', 'w' => ''],
                'riso_u'  => '500',
                'geraete' => [],
            ],
            'kreise'      => [],
            'maengel'     => [],
            'bemerkungen' => '',
            'ergebnis'    => ['entspricht' => '', 'maengelfrei' => '', 'instand_bis' => '', 'plakette' => '', 'plakette_nr' => '',
                'naechste' => self::NAECHSTE, 'errichter' => '0'],
            'anhang'      => ['thermographie' => '', 'weitere' => ''],
            'abschluss'   => ['warnungen_bestaetigt' => []],
            'grenzwerte'  => [],
        ];
    }

    /** Ein leerer Stromkreis mit neuem Zeilenschlüssel. */
    public static function kreisLeer(): array
    {
        $leer = ['op' => '', 'w' => ''];
        return [
            'z' => self::neueZeile(), 'nr' => '', 'art' => 'end',
            'objekt_id' => '', 'anschluss_id' => '', 'inventar_id' => '', 'abgang' => '',
            'ort' => '', 'kabel' => '',
            'leitung' => ['art' => '', 'leiter' => '', 'querschnitt' => ''],
            'ueb'     => ['art' => '', 'char' => '', 'in' => '', 'grenze_hand' => ''],
            'rcd'     => ['bez' => '', 'in' => '', 'typ' => '', 'idn' => '', 's' => '0'],
            'w'       => ['zs' => $leer, 'ik' => $leer, 'rpe' => $leer, 'riso' => $leer, 'riso_u' => '', 'idelta' => $leer, 'ta' => $leer, 'ub' => $leer],
            'drehfeld' => '',
            'rcd_von'  => '',
            'status'   => 'offen', 'grund' => '',
            'pruefer'  => 'auto', 'begruendung' => '', 'bem' => '',
            'abgeleitet' => [],
        ];
    }

    public static function neueZeile(): string
    {
        return 'k' . bin2hex(random_bytes(4));
    }

    /**
     * Der gespeicherte Inhalt mit allen Schlüsseln des Schemas – für die
     * Anzeige und zum Einfrieren. Listen bleiben, wie sie sind; was nur ein
     * eingefrorener Bericht hat (labels, texte, bewertung …), bleibt stehen.
     */
    public static function vollstaendig(array $inhalt): array
    {
        $voll = array_replace_recursive(self::leer(), $inhalt);
        foreach (['kreise', 'maengel'] as $liste) {
            $voll[$liste] = is_array($inhalt[$liste] ?? null) ? array_values($inhalt[$liste]) : [];
        }
        $voll['grundlage']['normen'] = is_array($inhalt['grundlage']['normen'] ?? null) ? array_values($inhalt['grundlage']['normen']) : [];
        $voll['messung']['geraete'] = is_array($inhalt['messung']['geraete'] ?? null) ? array_values(array_map('strval', $inhalt['messung']['geraete'])) : [];
        $voll['punkte'] = ['besichtigung' => (array) ($inhalt['punkte']['besichtigung'] ?? []), 'erprobung' => (array) ($inhalt['punkte']['erprobung'] ?? [])];
        $voll['abschluss']['warnungen_bestaetigt'] = is_array($inhalt['abschluss']['warnungen_bestaetigt'] ?? null)
            ? array_values($inhalt['abschluss']['warnungen_bestaetigt']) : [];
        foreach ($voll['kreise'] as $i => $k) {
            $voll['kreise'][$i] = array_replace_recursive(self::kreisLeer(), is_array($k) ? $k : []);
            $voll['kreise'][$i]['abgeleitet'] = is_array($k['abgeleitet'] ?? null) ? array_values($k['abgeleitet']) : [];
        }
        return $voll;
    }

    /**
     * Rohen Inhalt aus dem Browser auf das Schema bringen.
     *
     * @param array $roh  wie aus json_decode(…, true)
     * @param array $alt  der gespeicherte Stand (Quelle aller Server-Felder)
     * @return array{inhalt:array, hinweise:list<string>}
     */
    public static function bereinigen(array $roh, array $alt): array
    {
        $hinweise = [];
        $m = self::muster();
        $leer = self::leer();
        $alt = array_replace_recursive($leer, $alt);

        $inhalt = [
            'schema'  => (string) $alt['schema'],
            'katalog' => (string) $alt['katalog'],
            'kopf'    => self::felder($m['kopf'], $roh['kopf'] ?? null),
            'netz'    => self::felder($m['netz'], $roh['netz'] ?? null),
            'grundlage' => [
                'normen'   => self::schluesselListe($roh['grundlage']['normen'] ?? null, array_keys(Katalog::normen()), 20),
                'sonstige' => self::zeile($roh['grundlage']['sonstige'] ?? '', 255),
                'grund'    => self::auswahl($roh['grundlage']['grund'] ?? '', array_merge([''], array_keys(Katalog::gruende()))),
            ],
            'punkte' => [
                'besichtigung' => self::punkte($roh['punkte']['besichtigung'] ?? null, Katalog::besichtigung()),
                'erprobung'    => self::punkte($roh['punkte']['erprobung'] ?? null, Katalog::erprobung()),
            ],
            'punkt_bemerkung' => [],
            'sicherheitskategorie' => self::zeile($roh['sicherheitskategorie'] ?? '', 32),
            'messung' => self::felder($m['messung'], $roh['messung'] ?? null),
            'kreise'  => [],
            'maengel' => [],
            'bemerkungen' => self::text($roh['bemerkungen'] ?? '', 4000),
            'ergebnis'    => self::felder($m['ergebnis'], $roh['ergebnis'] ?? null),
            'anhang'      => self::felder($m['anhang'], $roh['anhang'] ?? null),
            'abschluss'   => ['warnungen_bestaetigt' => self::codes($roh['abschluss']['warnungen_bestaetigt'] ?? null)],
            'grenzwerte'  => is_array($alt['grenzwerte']) ? $alt['grenzwerte'] : [],
        ];

        // Kopf: Qualifikation kommt aus dem Konto, das Prüfdatum ist Pflicht.
        $inhalt['kopf']['qualifikation'] = (string) $alt['kopf']['qualifikation'];
        if ($inhalt['kopf']['pruefdatum'] === '') {
            $inhalt['kopf']['pruefdatum'] = (string) $alt['kopf']['pruefdatum'] !== '' ? (string) $alt['kopf']['pruefdatum'] : self::heute();
        }
        // Erdung: Messwert {op, w} und dazu Methode, Bezug, eigener Grenzwert.
        $inhalt['messung']['erdung'] = self::messwert($roh['messung']['erdung'] ?? null) + $inhalt['messung']['erdung'];
        $inhalt['messung']['geraete'] = self::idListe($roh['messung']['geraete'] ?? null, self::MAX_GERAETE);

        $punktSchluessel = array_merge(array_keys(Katalog::besichtigung()), array_keys(Katalog::erprobung()));
        if (is_array($roh['punkt_bemerkung'] ?? null)) {
            foreach ($roh['punkt_bemerkung'] as $schluessel => $text) {
                $schluessel = (string) $schluessel;
                $text = self::text($text, 500);
                if ($text !== '' && in_array($schluessel, $punktSchluessel, true)) {
                    $inhalt['punkt_bemerkung'][$schluessel] = $text;
                }
            }
        }

        // ---------------------------------------------- Stromkreise
        $altNachZ = [];
        foreach ($alt['kreise'] as $k) {
            if (is_array($k) && isset($k['z'])) {
                $altNachZ[(string) $k['z']] = $k;
            }
        }
        $rohKreise = is_array($roh['kreise'] ?? null) ? array_values($roh['kreise']) : [];
        if (count($rohKreise) > self::MAX_KREISE) {
            $hinweise[] = 'Höchstens ' . self::MAX_KREISE . ' Stromkreise – die übrigen ' . (count($rohKreise) - self::MAX_KREISE) . ' wurden nicht gespeichert.';
            $rohKreise = array_slice($rohKreise, 0, self::MAX_KREISE);
        }
        $gesehen = [];
        foreach ($rohKreise as $i => $rk) {
            if (!is_array($rk)) {
                continue;
            }
            $kreis = self::kreis($rk, $m['kreis']);
            $z = is_string($rk['z'] ?? null) ? $rk['z'] : '';
            if (preg_match(self::ZEILE, $z) !== 1 || isset($gesehen[$z])) {
                $z = self::neueZeile();
                $hinweise[] = 'Zeile ' . ($i + 1) . ' hat einen neuen Zeilenschlüssel bekommen.';
            }
            $gesehen[$z] = true;
            $kreis = ['z' => $z] + $kreis;
            $vorher = $altNachZ[$z] ?? null;
            foreach (self::SERVER_FELDER as $feld) {
                $kreis[$feld] = $vorher !== null ? (string) ($vorher[$feld] ?? '') : '';
            }
            $kreis['abgeleitet'] = self::abgeleitet($rk, $kreis, $vorher);
            $inhalt['kreise'][] = self::ordnen($kreis);
        }
        // Verweise zwischen Zeilen erst, wenn alle Zeilen da sind.
        foreach ($inhalt['kreise'] as &$kreis) {
            if ($kreis['rcd_von'] === $kreis['z'] || !isset($gesehen[$kreis['rcd_von']])) {
                $kreis['rcd_von'] = '';
            }
        }
        unset($kreis);

        // ---------------------------------------------- Mängel
        $rohMaengel = is_array($roh['maengel'] ?? null) ? array_values($roh['maengel']) : [];
        foreach (array_slice($rohMaengel, 0, self::MAX_MAENGEL) as $rm) {
            if (!is_array($rm)) {
                continue;
            }
            $mangel = self::felder($m['mangel'], $rm);
            if (!isset($gesehen[$mangel['kreis']])) {
                $mangel['kreis'] = '';
            }
            if ($mangel['text'] !== '' || $mangel['frist'] !== '' || $mangel['behoben'] !== '') {
                $inhalt['maengel'][] = $mangel;
            }
        }

        return ['inhalt' => $inhalt, 'hinweise' => $hinweise];
    }

    /**
     * Die Spalten der Liste aus dem Inhalt.
     *
     * @return array{titel:string,veranstaltung:string,kunde_name:string,pruefdatum:string,standort_name:string}
     */
    public static function listenfelder(array $inhalt): array
    {
        $kopf = is_array($inhalt['kopf'] ?? null) ? $inhalt['kopf'] : [];
        $ersteZeile = static fn (mixed $t, int $n): string => mb_substr(trim(explode("\n", (string) $t)[0]), 0, $n);
        $datum = (string) ($kopf['pruefdatum'] ?? '');
        return [
            'titel'         => $ersteZeile($kopf['anlage']['bezeichnung'] ?? '', 255),
            'veranstaltung' => $ersteZeile($kopf['veranstaltung'] ?? '', 255),
            'kunde_name'    => $ersteZeile($kopf['kunde']['firma'] ?? '', 255),
            'pruefdatum'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) === 1 ? $datum : self::heute(),
            'standort_name' => $ersteZeile($kopf['anlage']['aufstellort'] ?? '', 190),
        ];
    }

    /** Wert an einem Pfad wie „leitung.leiter“. */
    public static function anPfad(array $daten, string $pfad): mixed
    {
        foreach (explode('.', $pfad) as $teil) {
            if (!is_array($daten) || !array_key_exists($teil, $daten)) {
                return null;
            }
            $daten = $daten[$teil];
        }
        return $daten;
    }

    // ------------------------------------------------------------ Muster

    /**
     * Typen: z:N einzeilig, t:N mehrzeilig, zahl Dezimaltext, ganz:N
     * Ganzzahl mit höchstens N Ziffern, datum JJJJ-MM-TT, b "0"/"1",
     * e:a|b Auswahl (erster Eintrag = Vorgabe), w Messwert {op, w}.
     */
    private static function muster(): array
    {
        if (self::$muster !== null) {
            return self::$muster;
        }
        $ja = 'e:|ja|nein';
        return self::$muster = [
            'kopf' => [
                'veranstaltung' => 'z:255', 'auftrag_nr' => 'z:64', 'projekt_nr' => 'z:64', 'pruefdatum' => 'datum', 'zeitraum' => 'z:255',
                'ort' => 'z:120', 'qualifikation' => 'z:120', 'projektleiter_kunde' => 'z:190', 'projektleiter_montage' => 'z:190',
                'anlagenverantwortlicher' => 'z:190', 'bereitschaft' => 'z:120',
                'kunde'  => ['firma' => 'z:255', 'anschrift' => 't:1000', 'kundennummer' => 'z:64', 'ansprechpartner' => 'z:190',
                    'telefon' => 'z:64', 'email' => 'z:190', 'quelle' => 'e:freitext|eventworx|bericht'],
                'anlage' => ['bezeichnung' => 'z:255', 'details' => 't:2000', 'aufstellort' => 't:1000'],
            ],
            'netz' => [
                'einspeisung' => 'e:|' . implode('|', array_keys(Katalog::einspeisungen())), 'spannung' => 'zahl', 'frequenz' => 'zahl',
                'form' => 'e:|' . implode('|', array_keys(Katalog::netzformen())), 'erder' => 'z:120', 'u_ln' => 'zahl', 'drehfeld' => 'e:|rechts|links',
            ],
            'messung' => [
                'erdung'  => ['methode' => 'z:120', 'basis' => 'e:|generator|tt|eigen|keine', 'grenze' => 'zahl'],
                'iso_bus' => 'w',
                'riso_u'  => 'e:|250|500|1000',
            ],
            'ergebnis' => [
                'entspricht' => $ja, 'maengelfrei' => $ja, 'instand_bis' => 'datum', 'plakette' => $ja, 'plakette_nr' => 'z:64',
                'naechste' => 'z:255', 'errichter' => 'b',
            ],
            'anhang' => ['thermographie' => $ja, 'weitere' => 'z:255'],
            'mangel' => ['text' => 't:1000', 'kreis' => 'z:16', 'frist' => 'datum', 'behoben' => 'datum'],
            'kreis'  => [
                'nr' => 'z:8', 'art' => 'e:end|verteiler', 'ort' => 'z:255', 'kabel' => 'z:255',
                'leitung' => ['art' => 'z:64', 'leiter' => 'ganz:2', 'querschnitt' => 'zahl'],
                'ueb'     => ['art' => 'e:|LS|RCBO|NH', 'char' => 'e:' . implode('|', SchutzService::CHARAKTERISTIK), 'in' => 'zahl', 'grenze_hand' => 'zahl'],
                'rcd'     => ['bez' => 'z:32', 'in' => 'zahl', 'typ' => 'e:' . implode('|', SchutzService::RCD_TYPEN), 'idn' => 'zahl', 's' => 'b'],
                'w'       => ['zs' => 'w', 'ik' => 'w', 'rpe' => 'w', 'riso' => 'w', 'riso_u' => 'e:|250|500|1000', 'idelta' => 'w', 'ta' => 'w', 'ub' => 'w'],
                'drehfeld' => 'e:|rechts|links',
                'rcd_von'  => 'z:16',
                'status'   => 'e:offen|gemessen|nicht_gemessen', 'grund' => 'z:255',
                'pruefer'  => 'e:auto|io|nio', 'begruendung' => 'z:500', 'bem' => 'z:500',
            ],
        ];
    }

    private static function felder(array $muster, mixed $roh): array
    {
        $roh = is_array($roh) ? $roh : [];
        $out = [];
        foreach ($muster as $schluessel => $typ) {
            $wert = $roh[$schluessel] ?? null;
            $out[$schluessel] = is_array($typ) ? self::felder($typ, $wert) : self::wert($typ, $wert);
        }
        return $out;
    }

    private static function wert(string $typ, mixed $roh): string|array
    {
        [$art, $arg] = explode(':', $typ, 2) + [1 => ''];
        return match ($art) {
            'z'     => self::zeile($roh, (int) $arg),
            't'     => self::text($roh, (int) $arg),
            'zahl'  => self::zahl($roh),
            'ganz'  => self::ganz($roh, (int) $arg),
            'datum' => self::datum($roh),
            'b'     => self::wahr($roh) ? '1' : '0',
            'e'     => self::auswahl($roh, explode('|', $arg)),
            'w'     => self::messwert($roh),
            default => '',
        };
    }

    private static function kreis(array $rk, array $muster): array
    {
        $kreis = self::felder($muster, $rk);
        // „gemessen“ ergibt sich aus den Werten; nur „nicht gemessen“ setzt der Prüfer.
        if ($kreis['status'] !== 'nicht_gemessen') {
            $hat = $kreis['drehfeld'] !== '';
            foreach (Grenzwerte::MESSWERTE as $feld) {
                if ($kreis['w'][$feld]['w'] !== '') {
                    $hat = true;
                }
            }
            $kreis['status'] = $hat ? 'gemessen' : 'offen';
        }
        return $kreis;
    }

    /** Reihenfolge der Schlüssel wie in kreisLeer() – nur der Lesbarkeit halber. */
    private static function ordnen(array $kreis): array
    {
        $out = [];
        foreach (array_keys(self::kreisLeer()) as $schluessel) {
            $out[$schluessel] = $kreis[$schluessel] ?? '';
        }
        return $out;
    }

    /**
     * „aus Plan“ bleibt nur stehen, solange der Prüfer das Feld nicht
     * angefasst hat: der Pfad stand schon im gespeicherten Stand, der Browser
     * hat ihn nicht entfernt, und der Wert ist derselbe. Neu hinzufügen kann
     * ihn der Browser nicht.
     */
    private static function abgeleitet(array $rk, array $kreis, ?array $vorher): array
    {
        if ($vorher === null || !is_array($vorher['abgeleitet'] ?? null)) {
            return [];
        }
        $vomBrowser = is_array($rk['abgeleitet'] ?? null) ? array_map('strval', array_filter($rk['abgeleitet'], 'is_scalar')) : null;
        $out = [];
        foreach ($vorher['abgeleitet'] as $pfad) {
            $pfad = (string) $pfad;
            if ($vomBrowser !== null && !in_array($pfad, $vomBrowser, true)) {
                continue;
            }
            if (self::anPfad($kreis, $pfad) === self::anPfad($vorher, $pfad) && self::anPfad($kreis, $pfad) !== null) {
                $out[] = $pfad;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string,string> Prüfpunkt → io|nio|entf; nicht geprüfte fehlen */
    private static function punkte(mixed $roh, array $katalog): array
    {
        $out = [];
        if (!is_array($roh)) {
            return $out;
        }
        foreach ($katalog as $schluessel => $_punkt) {
            $wert = $roh[$schluessel] ?? '';
            if (is_string($wert) && in_array($wert, ['io', 'nio', 'entf'], true)) {
                $out[$schluessel] = $wert;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function schluesselListe(mixed $roh, array $erlaubt, int $hoechstens): array
    {
        $out = [];
        foreach (is_array($roh) ? $roh : [] as $w) {
            if (is_string($w) && in_array($w, $erlaubt, true) && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return array_slice($out, 0, $hoechstens);
    }

    /** @return list<string> IDs als Text (existieren und aktiv: prüft BerichtService) */
    private static function idListe(mixed $roh, int $hoechstens): array
    {
        $out = [];
        foreach (is_array($roh) ? $roh : [] as $w) {
            if (is_int($w) || (is_string($w) && preg_match('/^[1-9]\d{0,9}$/', $w) === 1)) {
                $w = (string) $w;
                if ($w !== '0' && !in_array($w, $out, true)) {
                    $out[] = $w;
                }
            }
        }
        return array_slice($out, 0, $hoechstens);
    }

    /** @return list<string> bestätigte Warnungen (Codes): Kleinbuchstaben, Ziffern und _:.-, höchstens 100 */
    public static function codes(mixed $roh): array
    {
        $out = [];
        foreach (is_array($roh) ? $roh : [] as $w) {
            if (is_string($w) && preg_match('/^[a-z0-9_:.-]{1,64}$/', $w) === 1 && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return array_slice($out, 0, 100);
    }

    // ------------------------------------------------------------ Werte

    private static function skalarText(mixed $roh): string
    {
        return match (true) {
            is_string($roh) => $roh,
            is_int($roh)    => (string) $roh,
            is_float($roh)  => self::zahlText($roh),
            default         => '',
        };
    }

    /** Gleitkomma aus dem Browser als Dezimaltext – nie als float in den Bericht. */
    private static function zahlText(float $f): string
    {
        if (!is_finite($f)) {
            return '';
        }
        $s = rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private static function zeile(mixed $roh, int $hoechstens): string
    {
        $s = self::skalarText($roh);
        $s = (string) preg_replace(self::BIDI, '', $s);
        $s = (string) preg_replace('/\p{Cc}/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, $hoechstens);
    }

    private static function text(mixed $roh, int $hoechstens): string
    {
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], self::skalarText($roh));
        $s = (string) preg_replace(self::BIDI, '', $s);
        $s = (string) preg_replace('/[^\P{Cc}\n]/u', '', $s);
        $s = (string) preg_replace('/[ ]+\n/', "\n", $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
        return mb_substr(trim($s), 0, $hoechstens);
    }

    /** Dezimaltext („2.5“) oder ''. Einheiten dahinter („16 A“) fallen weg. */
    private static function zahl(mixed $roh): string
    {
        $s = trim(self::skalarText($roh));
        if ($s === '') {
            return '';
        }
        $s = (string) preg_replace('/\s*[A-Za-zΩµ%²]+$/u', '', $s);
        $m = Grenzwerte::messwert($s);
        return $m !== null && $m['op'] === '' ? $m['w'] : '';
    }

    private static function ganz(mixed $roh, int $ziffern): string
    {
        $s = trim(self::skalarText($roh));
        if (preg_match('/^\d{1,' . max(1, $ziffern) . '}$/', $s) !== 1) {
            return '';
        }
        return (string) (int) $s;
    }

    private static function datum(mixed $roh): string
    {
        $s = trim(self::skalarText($roh));
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m) === 1) {
            $s = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        return $s;
    }

    private static function wahr(mixed $roh): bool
    {
        return $roh === true || $roh === 1 || (is_string($roh) && in_array(strtolower(trim($roh)), ['1', 'true', 'on', 'ja'], true));
    }

    /** Ein Wert aus der Liste (auch in anderer Groß-/Kleinschreibung), sonst der erste. */
    private static function auswahl(mixed $roh, array $moeglich): string
    {
        $s = trim(self::skalarText($roh));
        if (in_array($s, $moeglich, true)) {
            return $s;
        }
        foreach ($moeglich as $m) {
            if (strcasecmp($m, $s) === 0) {
                return $m;
            }
        }
        return (string) $moeglich[0];
    }

    /**
     * Ein Messwert {op, w}. Lesbare Zahlen werden Dezimaltext mit Punkt;
     * was keine Zahl ist, bleibt als Text stehen (gekürzt) – die Bewertung
     * meldet es, statt die Eingabe still zu verwerfen.
     *
     * @return array{op:string,w:string}
     */
    private static function messwert(mixed $roh): array
    {
        if (!is_array($roh)) {
            $roh = ['op' => '', 'w' => $roh];
        }
        $op = self::zeile($roh['op'] ?? '', 2);
        $op = match ($op) {
            '<', '≤', '<=' => '<',
            '>', '≥', '>=' => '>',
            default        => '',
        };
        $w = self::zeile($roh['w'] ?? '', 24);
        if ($w === '') {
            return ['op' => '', 'w' => ''];
        }
        $gelesen = Grenzwerte::messwert($w);
        if ($gelesen === null) {
            return ['op' => $op, 'w' => mb_substr($w, 0, 16)];
        }
        if ($gelesen['op'] === '') {
            $gelesen['op'] = $op;
        }
        return $gelesen;
    }

    private static function heute(): string
    {
        return (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d');
    }
}
