<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Services\LeitungService;

/**
 * Grenzwerte und Bewertung der Messwerte eines Übergabeberichts.
 *
 * Bewertet wird nur hier, auf dem Server; der Browser zeigt an, was das
 * Autosave zurückgibt – es gibt keine zweite Logik in JavaScript. Die
 * Parameter werden beim Anlegen in `inhalt.grenzwerte` festgehalten und
 * nicht mehr geändert: ein Bericht rechnet mit den Werten, die galten, als
 * er angelegt wurde, auch wenn jemand später die Einstellungen ändert.
 *
 * Verglichen wird mit dem ungerundeten Wert (C16: 1,4375 Ω), angezeigt mit
 * zwei Nachkommastellen. Ein Messwert hat einen Operator: „>“ heißt über
 * dem Messbereich, „<“ darunter.
 *
 * Bewertung je Zeile: '' (offen, noch kein Wert), ok, hinweis, manuell
 * (kein Rechenwert – der Prüfer bewertet selbst), fehlt (Pflichtwert leer),
 * mangel; entf bei „nicht gemessen“ mit Grund. Es gilt die schlechteste
 * Stufe: mangel > fehlt > manuell > hinweis > ok.
 */
final class Grenzwerte
{
    /** Rang der Stufen; „info“ zählt nicht. */
    public const STUFEN = ['ok' => 1, 'hinweis' => 2, 'manuell' => 3, 'fehlt' => 4, 'mangel' => 5];

    public const QUELLE = 'DIN VDE 0100-600:2017-06 Tab. 6.1; DIN VDE 0100-410:2018-10; DIN VDE 0100-711:2020-06; DGUV I 203-032';

    /** Felder mit Messwerten je Kreis. */
    public const MESSWERTE = ['zs', 'ik', 'rpe', 'riso', 'idelta', 'ta', 'ub'];

    private const NAMEN = ['zs' => 'Zs', 'ik' => 'Ik', 'rpe' => 'R_PE', 'riso' => 'R_iso', 'idelta' => 'IΔ', 'ta' => 't_A', 'ub' => 'U_B'];

    /**
     * Die Parameter aus den Einstellungen – alles Strings, damit sie
     * unverändert in den gehashten Inhalt passen.
     *
     * Fassung 2: R_iso bei 250 V ≥ 1,0 MΩ. Die Zeile „250 V / 0,5 MΩ“ der
     * Tab. 6.1 (DIN VDE 0100-600) gilt nur für SELV/PELV; wird bei 230/400 V
     * mit 250 V gemessen (nicht abtrennbare Überspannungsschutzgeräte,
     * 6.4.3.3), bleibt es bei 1 MΩ. Ein SELV/PELV-Kreis wird von Hand
     * bewertet. Ältere Berichte rechnen weiter mit ihrer Fassung.
     */
    public static function parameter(): array
    {
        return [
            'version'        => '2',
            'u0'             => '230',
            'ul'             => Einstellungen::wert('berichte.ul'),
            'zs_faktor'      => Einstellungen::wert('berichte.zs_faktor'),
            'k'              => ['B' => '5', 'C' => '10', 'D' => '20', 'K' => '14', 'Z' => '3'],
            'riso'           => ['250' => '1.0', '500' => '1.0', '1000' => '1.0'],
            'rcd'            => ['i_min' => '0.5', 'i_max' => '1.0', 'ta_max' => '300', 'ta_s_min' => '130', 'ta_s_max' => '500'],
            'rpe_hinweis'    => Einstellungen::wert('berichte.rpe_hinweis'),
            'erde_generator' => Einstellungen::wert('berichte.erde_generator'),
            'u_toleranz'     => '0.1',
            'quelle'         => self::QUELLE,
        ];
    }

    /**
     * Eine Eingabe lesen: „0,42“ → ['op' => '', 'w' => '0.42'];
     * „> 550“ und „≥550“ → '>'; „<0,1“ und „≤0,1“ → '<'. Sonst null.
     *
     * @return array{op:string,w:string}|null
     */
    public static function messwert(string $eingabe): ?array
    {
        $s = (string) preg_replace('/[\s\x{00A0}\x{2009}\x{202F}]+/u', '', $eingabe);
        $op = '';
        if (preg_match('/^(<=|>=|≤|≥|<|>|=)/u', $s, $m) === 1) {
            $op = match ($m[1]) {
                '<', '<=', '≤' => '<',
                '>', '>=', '≥' => '>',
                default        => '',
            };
            $s = substr($s, strlen($m[1]));
        }
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,4}))?$/', $s, $m) === 1) {
            $ganz = ltrim($m[1], '0');
            $w = ($ganz === '' ? '0' : $ganz) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : '');
        } elseif (preg_match('/^[.,](\d{1,4})$/', $s, $m) === 1) {
            $w = '0.' . $m[1];
        } else {
            return null;
        }
        return ['op' => $op, 'w' => $w];
    }

    /** Dezimaltext → Zahl (nur zum Rechnen, nie zum Speichern). */
    public static function zahl(mixed $text): ?float
    {
        if (!is_string($text) || preg_match('/^\d{1,9}(\.\d{1,6})?$/', $text) !== 1) {
            return null;
        }
        return (float) $text;
    }

    /** Zs_max = f · U0 / (k · In), ungerundet – oder null ohne Rechenwert (NH/gG, fehlende Angaben). */
    public static function zsMax(string $char, string $in, array $p): ?float
    {
        $k = self::zahl($p['k'][self::charakteristik($char)] ?? null);
        $strom = self::zahl($in);
        $f = self::zahl($p['zs_faktor'] ?? '1.0') ?? 1.0;
        $u0 = self::zahl($p['u0'] ?? '230') ?? 230.0;
        if ($k === null || $k <= 0 || $strom === null || $strom <= 0) {
            return null;
        }
        return $f * $u0 / ($k * $strom);
    }

    /**
     * Ein Stromkreis.
     *
     * @param array<string,array<string,mixed>> $kreiseNachZ alle Kreise des Berichts nach Zeilenschlüssel (für rcd_von)
     * @param array<string,mixed> $messung Kopf der Messung (Messspannung R_iso)
     * @return array{auto:string, meldungen:list<array{code:string,stufe:string,text:string}>, grenzen:array<string,?string>, abschaltung:string}
     */
    public static function kreis(array $kreis, array $kreiseNachZ, array $netz, array $p, array $messung = []): array
    {
        $meldungen = [];
        $grenzen = [];
        $abschaltung = '';
        $melden = static function (string $code, string $stufe, string $text) use (&$meldungen): void {
            $meldungen[] = ['code' => $code, 'stufe' => $stufe, 'text' => $text];
        };
        $w = is_array($kreis['w'] ?? null) ? $kreis['w'] : [];
        $u0 = self::zahl($p['u0'] ?? '230') ?? 230.0;
        $ul = self::zahl($p['ul'] ?? '50') ?? 50.0;
        $form = (string) ($netz['form'] ?? '');
        $tn = $form === '' || str_starts_with($form, 'TN');
        // Im TN-C-System löst ein RCD nicht aus (der Fehlerstrom fließt über den
        // PEN durch den Summenstromwandler zurück): er zählt weder als
        // Abschaltung noch als Zusatzschutz (DIN VDE 0100-410 411.4.5).
        $tnc = $form === 'TN-C';
        $nichtGemessen = ($kreis['status'] ?? '') === 'nicht_gemessen';

        $ueb = is_array($kreis['ueb'] ?? null) ? $kreis['ueb'] : [];
        $rcd = is_array($kreis['rcd'] ?? null) ? $kreis['rcd'] : [];
        $in = self::zahl($ueb['in'] ?? '');
        $idn = self::zahl($rcd['idn'] ?? '');
        if ($idn !== null && $idn <= 0) {
            $idn = null;
        }
        $idnWirksam = $tnc ? null : $idn;

        $hatWerte = ((string) ($kreis['drehfeld'] ?? '')) !== '';
        foreach (self::MESSWERTE as $feld) {
            if ((string) ($w[$feld]['w'] ?? '') !== '') {
                $hatWerte = true;
            }
        }

        // ---------------------------------------------- aus der Planung
        if (($ueb['art'] ?? '') === '' && ($ueb['in'] ?? '') === '' && ($ueb['grenze_hand'] ?? '') === '') {
            $melden('ueb_fehlt', 'hinweis', 'Kein Überstromschutz eingetragen.');
        }
        if (($kreis['art'] ?? 'end') === 'end' && $in !== null && $in <= 32 && ($idnWirksam === null || $idnWirksam > 30)) {
            $melden('rcd_pflicht', 'mangel', 'Endstromkreis bis 32 A ohne RCD ≤ 30 mA (DIN VDE 0100-711).');
        }
        if ($tnc && $idn !== null) {
            $melden('rcd_tnc', 'mangel', 'RCD im TN-C-System unwirksam (DIN VDE 0100-410 411.4.5) – weder Abschaltung noch Zusatzschutz belegt.');
        }
        $q = self::zahl($kreis['leitung']['querschnitt'] ?? '');
        if ($q !== null && $in !== null) {
            $belastbar = LeitungService::belastbarkeit($q);
            if ($belastbar !== null && $belastbar < $in) {
                $melden('querschnitt', 'hinweis', sprintf('Querschnitt %s mm² trägt rund %d A, abgesichert mit %s A.', self::komma($q), $belastbar, self::komma($in)));
            }
        }
        if (($kreis['drehfeld'] ?? '') === 'links') {
            $melden('drehfeld', 'mangel', 'Linksdrehfeld.');
        }

        if ($nichtGemessen) {
            if (trim((string) ($kreis['grund'] ?? '')) === '') {
                $melden('grund_fehlt', 'fehlt', 'Bitte angeben, warum nicht gemessen wurde.');
            }
            return ['auto' => self::stufe($meldungen, 'entf'), 'meldungen' => $meldungen, 'grenzen' => $grenzen, 'abschaltung' => ''];
        }

        // ---------------------------------------------- Werte lesen
        $gelesen = [];
        foreach (self::MESSWERTE as $feld) {
            $gelesen[$feld] = self::lesen($w[$feld] ?? null);
            if ($gelesen[$feld] === false) {
                $melden('unlesbar_' . $feld, 'fehlt', sprintf('%s: „%s“ ist keine Zahl.', self::NAMEN[$feld], mb_substr((string) ($w[$feld]['w'] ?? ''), 0, 16)));
                $gelesen[$feld] = null;
            }
        }

        // ---------------------------------------------- Schleifenimpedanz
        // TN immer; TT ohne RCD ebenso (Zs · Ia ≤ U0, DIN VDE 0100-410
        // 411.5.4) – „Zs entfällt“ gilt nur, wenn ein RCD den Kreis schützt.
        $zsBewerten = $tn || ($form === 'TT' && $idn === null);
        if ($form === 'IT' && $idn === null && $hatWerte) {
            $melden('it_zweitfehler', 'manuell', 'IT-System ohne RCD: Abschaltung im Zweitfehlerfall (DIN VDE 0100-410 411.6.4) selbst bewerten.');
        }
        if ($zsBewerten) {
            $grenzeHand = self::zahl($ueb['grenze_hand'] ?? '');
            $zsMax = $grenzeHand;
            if ($zsMax === null && ($ueb['art'] ?? '') !== 'NH') {
                $zsMax = self::zsMax((string) ($ueb['char'] ?? ''), (string) ($ueb['in'] ?? ''), $p);
            }
            if ($zsMax !== null && $zsMax > 0) {
                $grenzen['zs_max'] = self::dez($zsMax);
                $grenzen['ik_min'] = self::dez($u0 / $zsMax, 1);
            }
            if ($idnWirksam !== null) {
                $grenzen['zs_rcd_max'] = self::dez($u0 / ($idnWirksam / 1000), 0);
            }
            $zs = $gelesen['zs'];
            if ($zs === null && $gelesen['ik'] !== null && $gelesen['ik']['w'] > 0) {
                // Ik ≥ k·In/f ist dasselbe wie U0/Ik ≤ Zs_max; der Operator dreht sich um.
                $ik = $gelesen['ik'];
                $zs = ['op' => $ik['op'] === '>' ? '<' : ($ik['op'] === '<' ? '>' : ''), 'w' => $u0 / $ik['w']];
                $grenzen['zs_aus_ik'] = self::dez($zs['w'], 3);
            }
            if ($zs === null) {
                if ($hatWerte) {
                    $melden('zs_fehlt', 'fehlt', 'Schleifenimpedanz Zs (oder Ik) fehlt.');
                }
            } else {
                $durchRcd = $idnWirksam !== null && self::vergleich($zs, $u0 / ($idnWirksam / 1000), true) === 'ok';
                $ergebnis = $zsMax !== null ? self::vergleich($zs, $zsMax, true) : 'ohne';
                if ($ergebnis === 'ok') {
                    $abschaltung = 'LS';
                } elseif ($durchRcd && $ergebnis !== 'unklar') {
                    $abschaltung = 'RCD';
                    $melden('zs_rcd', 'hinweis', $ergebnis === 'ohne'
                        ? 'Abschaltung durch RCD (Zs · IΔn ≤ U0); kein Rechenwert für den Überstromschutz.'
                        : sprintf('Zs über %s Ω – Abschaltung durch RCD (Zs · IΔn ≤ U0).', self::komma((float) $zsMax)));
                } elseif ($ergebnis === 'ohne') {
                    $melden('zs_manuell', 'manuell', 'Kein Rechenwert für dieses Schutzorgan (NH/gG oder Angaben fehlen) – Grenzwert von Hand eintragen oder selbst bewerten.');
                } elseif ($ergebnis === 'unklar') {
                    $melden('zs_unklar', 'manuell', 'Zs außerhalb des Messbereichs – bitte selbst bewerten.');
                } else {
                    $melden('zs_hoch', 'mangel', sprintf('Zs %s Ω über dem Grenzwert %s Ω.', self::komma($zs['w']), self::komma((float) $zsMax)));
                }
            }
        }

        // ---------------------------------------------- Isolationswiderstand
        $risoU = (string) ($w['riso_u'] ?? '');
        if ($risoU === '') {
            $risoU = (string) ($messung['riso_u'] ?? '');
        }
        if ($gelesen['riso'] !== null) {
            $min = self::zahl($p['riso'][$risoU] ?? null);
            if ($risoU === '' || $min === null) {
                $melden('riso_u_fehlt', 'fehlt', 'Messspannung für R_iso fehlt.');
            } else {
                $grenzen['riso_min'] = self::dez($min);
                if ($risoU === '250' && $min >= 1.0) {
                    $melden('riso_250', 'info', 'Reduzierte Messspannung 250 V (DIN VDE 0100-600 6.4.3.3): Grenzwert ' . self::komma($min) . ' MΩ; SELV/PELV (0,5 MΩ) selbst bewerten.');
                }
                $ergebnis = self::vergleich($gelesen['riso'], $min, false);
                if ($ergebnis === 'ueber') {
                    $melden('riso_klein', 'mangel', sprintf('R_iso %s MΩ unter %s MΩ (bei %s V).', self::komma($gelesen['riso']['w']), self::komma($min), $risoU));
                } elseif ($ergebnis === 'unklar') {
                    $melden('riso_unklar', 'manuell', 'R_iso außerhalb des Messbereichs – bitte selbst bewerten.');
                }
            }
        } elseif ($hatWerte) {
            $melden('riso_fehlt', 'fehlt', 'Isolationswiderstand R_iso fehlt.');
        }

        // ---------------------------------------------- Schutzleiter
        $rpeMax = self::zahl($p['rpe_hinweis'] ?? '');
        if ($rpeMax !== null) {
            $grenzen['rpe_max'] = self::dez($rpeMax);
            if ($gelesen['rpe'] !== null && self::vergleich($gelesen['rpe'], $rpeMax, true) === 'ueber') {
                $melden('rpe_hoch', 'hinweis', sprintf('R_PE %s Ω über %s Ω – Schutzleiter prüfen.', self::komma($gelesen['rpe']['w']), self::komma($rpeMax)));
            }
        }

        // ---------------------------------------------- RCD
        if ($idn !== null) {
            $quelle = $gelesen;
            $vonZ = (string) ($kreis['rcd_von'] ?? '');
            $bezug = $vonZ !== '' && $vonZ !== (string) ($kreis['z'] ?? '') ? ($kreiseNachZ[$vonZ] ?? null) : null;
            $anderer = false;
            if ($bezug !== null) {
                // Die Werte eines anderen Kreises gelten nur für denselben RCD
                // (DIN VDE 0100-600 6.4.3.7: jede RCD wird nachgewiesen).
                $rcdBezug = is_array($bezug['rcd'] ?? null) ? $bezug['rcd'] : [];
                $bezBezug = (string) ($rcdBezug['bez'] ?? '');
                $bezEigen = (string) ($rcd['bez'] ?? '');
                $idnBezug = self::zahl($rcdBezug['idn'] ?? '');
                $verschieden = static fn (string $a, string $b): bool => $a !== '' && $b !== '' && $a !== $b;
                $anderer = $idnBezug === null || abs($idnBezug - $idn) > 1e-9
                    || $verschieden($bezBezug, $bezEigen)
                    || $verschieden((string) ($bezug['objekt_id'] ?? ''), (string) ($kreis['objekt_id'] ?? ''))
                    || $verschieden((string) ($rcdBezug['in'] ?? ''), (string) ($rcd['in'] ?? ''))
                    || $verschieden((string) ($rcdBezug['typ'] ?? ''), (string) ($rcd['typ'] ?? ''))
                    || (string) ($rcdBezug['s'] ?? '0') !== (string) ($rcd['s'] ?? '0');
                if ($anderer) {
                    $melden('rcd_von_anders', 'fehlt', 'Der Bezugskreis (Kreis ' . ($bezug['nr'] ?? '?') . ') hängt an einem anderen RCD'
                        . ($bezBezug !== '' ? ' (' . $bezBezug . ')' : '') . ' – den RCD dieses Kreises messen.');
                } else {
                    $quelle = [];
                    foreach (['idelta', 'ta', 'ub'] as $feld) {
                        $wert = self::lesen($bezug['w'][$feld] ?? null);
                        $quelle[$feld] = $wert === false ? null : $wert;
                    }
                    $melden('rcd_von', 'info', 'RCD-Werte gemessen an Kreis ' . ($bezug['nr'] ?? '?') . '.');
                    if ($bezBezug === '' || $bezEigen === '') {
                        $melden('rcd_von_unbelegt', 'hinweis', 'Gleicher RCD nicht belegt – die Bezeichnung des RCD an beiden Kreisen eintragen.');
                    }
                }
            }
            $rp = is_array($p['rcd'] ?? null) ? $p['rcd'] : [];
            $iMin = (self::zahl($rp['i_min'] ?? '0.5') ?? 0.5) * $idn;
            $iMax = (self::zahl($rp['i_max'] ?? '1.0') ?? 1.0) * $idn;
            $typS = ($rcd['s'] ?? '0') === '1';
            $taMin = $typS ? (self::zahl($rp['ta_s_min'] ?? '130') ?? 130.0) : null;
            $taMax = $typS ? (self::zahl($rp['ta_s_max'] ?? '500') ?? 500.0) : (self::zahl($rp['ta_max'] ?? '300') ?? 300.0);
            $grenzen['idelta_min'] = self::dez($iMin);
            $grenzen['idelta_max'] = self::dez($iMax);
            $grenzen['ta_min'] = $taMin !== null ? self::dez($taMin) : null;
            $grenzen['ta_max'] = self::dez($taMax);
            $grenzen['ub_max'] = self::dez($ul);

            if ($quelle['idelta'] !== null) {
                $ergebnis = self::bereich($quelle['idelta'], $iMin, false, $iMax);
                if ($ergebnis === 'aussen') {
                    $melden('idelta', 'mangel', sprintf('Auslösestrom IΔ %s mA außerhalb %s–%s mA.', self::komma($quelle['idelta']['w']), self::komma($iMin), self::komma($iMax)));
                } elseif ($ergebnis === 'unklar') {
                    $melden('idelta_unklar', 'manuell', 'IΔ außerhalb des Messbereichs – bitte selbst bewerten.');
                }
            }
            if ($quelle['ta'] !== null) {
                $ergebnis = self::bereich($quelle['ta'], $taMin, true, $taMax);
                if ($ergebnis === 'aussen') {
                    $melden('ta', 'mangel', $typS
                        ? sprintf('Auslösezeit %s ms außerhalb %s–%s ms (selektiver RCD).', self::komma($quelle['ta']['w']), self::komma((float) $taMin), self::komma($taMax))
                        : sprintf('Auslösezeit %s ms über %s ms.', self::komma($quelle['ta']['w']), self::komma($taMax)));
                } elseif ($ergebnis === 'unklar') {
                    $melden('ta_unklar', 'manuell', 't_A außerhalb des Messbereichs – bitte selbst bewerten.');
                }
            }
            if ($quelle['ub'] !== null) {
                $ergebnis = self::vergleich($quelle['ub'], $ul, true);
                if ($ergebnis === 'ueber') {
                    $melden('ub', 'mangel', sprintf('Berührungsspannung %s V über %s V.', self::komma($quelle['ub']['w']), self::komma($ul)));
                } elseif ($ergebnis === 'unklar') {
                    $melden('ub_unklar', 'manuell', 'U_B außerhalb des Messbereichs – bitte selbst bewerten.');
                }
            }
            if ($quelle['idelta'] === null && $quelle['ta'] === null && $hatWerte && !$anderer) {
                $bezug !== null
                    ? $melden('rcd_von_leer', 'fehlt', 'Der Bezugskreis hat keine RCD-Werte.')
                    : $melden('rcd_fehlt', 'fehlt', 'RCD-Werte fehlen (IΔ, t_A) – oder „gemessen an Kreis …“ wählen.');
            }
        } elseif ($gelesen['idelta'] !== null || $gelesen['ta'] !== null) {
            $melden('rcd_ohne_idn', 'hinweis', 'RCD-Werte eingetragen, aber kein RCD (IΔn) am Kreis.');
        }

        return [
            'auto'        => $hatWerte ? self::stufe($meldungen, 'ok') : '',
            'meldungen'   => $meldungen,
            'grenzen'     => $grenzen,
            'abschaltung' => $abschaltung,
        ];
    }

    /**
     * Der Erdungswiderstand im Kopf, mit dem Bezug, nach dem er bewertet wird.
     *
     * @param list<array<string,mixed>> $kreise
     * @return array{auto:string, meldungen:list<array{code:string,stufe:string,text:string}>, grenze:?string, basis:string, bezug:string}
     */
    public static function erdung(array $messung, array $netz, array $kreise, array $p): array
    {
        $erdung = is_array($messung['erdung'] ?? null) ? $messung['erdung'] : [];
        $basis = (string) ($erdung['basis'] ?? '');
        $form = (string) ($netz['form'] ?? '');
        if ($basis === '') {
            // IT: R_A · Id ≤ U_L mit sehr kleinem Id (411.6.2) – kein Erder dieser
            // Größe nötig, die 50 Ω des Generators gelten fürs TN-System.
            $basis = match (true) {
                $form === 'TT'                                                       => 'tt',
                $form === 'IT'                                                       => 'keine',
                in_array($netz['einspeisung'] ?? '', ['generator', 'hybrid'], true) => 'generator',
                default                                                              => 'keine',
            };
        }
        $ul = self::zahl($p['ul'] ?? '50') ?? 50.0;
        $grenze = null;
        switch ($basis) {
            case 'generator':
                $grenze = self::zahl($p['erde_generator'] ?? '50');
                $bezug = 'Generator im TN-System (DGUV I 203-032)';
                break;
            case 'tt':
                $idnMax = null;
                foreach ($kreise as $k) {
                    $idn = self::zahl($k['rcd']['idn'] ?? '');
                    if ($idn !== null && $idn > 0) {
                        $idnMax = max($idnMax ?? 0.0, $idn);
                    }
                }
                $grenze = $idnMax !== null ? $ul / ($idnMax / 1000) : null;
                $bezug = 'TT-System: R_A · IΔn ≤ U_L' . ($idnMax !== null ? ' (IΔn ' . self::komma($idnMax) . ' mA, U_L ' . self::komma($ul) . ' V)' : '');
                break;
            case 'eigen':
                $grenze = self::zahl($erdung['grenze'] ?? '');
                $bezug = 'eigener Grenzwert';
                break;
            default:
                $basis = 'keine';
                $bezug = 'ohne Grenzwert';
        }
        $meldungen = [];
        $wert = self::lesen($erdung);
        if ($basis === 'generator' && $form !== '' && !str_starts_with($form, 'TN') && $wert !== null) {
            $meldungen[] = ['code' => 'erdung_bezug', 'stufe' => 'hinweis', 'text' => 'Der Bezug „Generator im TN-System“ gilt für TN-Systeme – die Netzform ist ' . $form . '.'];
        }
        if ($wert === null) {
            return ['auto' => '', 'meldungen' => [], 'grenze' => $grenze !== null ? self::dez($grenze) : null, 'basis' => $basis, 'bezug' => $bezug];
        }
        if ($wert === false) {
            $meldungen[] = ['code' => 'erdung_unlesbar', 'stufe' => 'fehlt', 'text' => 'Erdungswiderstand ist keine Zahl.'];
        } elseif ($basis === 'keine') {
            return ['auto' => 'entf', 'meldungen' => [], 'grenze' => null, 'basis' => $basis, 'bezug' => $bezug];
        } elseif ($grenze === null) {
            $meldungen[] = ['code' => 'erdung_manuell', 'stufe' => 'manuell', 'text' => $basis === 'tt'
                ? 'Kein RCD im Bericht – Grenzwert R_A · IΔn ≤ U_L nicht berechenbar.' : 'Kein Grenzwert eingetragen.'];
        } else {
            $ergebnis = self::vergleich($wert, $grenze, true);
            if ($ergebnis === 'ueber') {
                $meldungen[] = ['code' => 'erdung_hoch', 'stufe' => 'mangel',
                    'text' => sprintf('Erdungswiderstand %s Ω über dem Grenzwert %s Ω (%s).', self::komma($wert['w']), self::komma($grenze), $bezug)];
            } elseif ($ergebnis === 'unklar') {
                $meldungen[] = ['code' => 'erdung_unklar', 'stufe' => 'manuell', 'text' => 'Erdungswiderstand außerhalb des Messbereichs – bitte selbst bewerten.'];
            }
        }
        return ['auto' => self::stufe($meldungen, 'ok'), 'meldungen' => $meldungen, 'grenze' => $grenze !== null ? self::dez($grenze) : null,
            'basis' => $basis, 'bezug' => $bezug];
    }

    /**
     * Was am Netz selbst auffällt: Spannung, Drehfeld der Einspeisung, IT-System.
     *
     * @return array{auto:string, meldungen:list<array{code:string,stufe:string,text:string}>}
     */
    public static function netz(array $netz, array $punkte, array $p): array
    {
        $meldungen = [];
        $u0 = self::zahl($p['u0'] ?? '230') ?? 230.0;
        $toleranz = self::zahl($p['u_toleranz'] ?? '0.1') ?? 0.1;
        $u = self::zahl($netz['u_ln'] ?? '');
        if ($u !== null && abs($u - $u0) > $toleranz * $u0) {
            $meldungen[] = ['code' => 'u_ln', 'stufe' => 'hinweis',
                'text' => sprintf('Netzspannung %s V außerhalb %s V ± %s %%.', self::komma($u), self::komma($u0), self::komma($toleranz * 100))];
        }
        if (($netz['drehfeld'] ?? '') === 'links') {
            $meldungen[] = ['code' => 'drehfeld_einspeisung', 'stufe' => 'mangel', 'text' => 'Linksdrehfeld an der Einspeisung.'];
        }
        if (($netz['form'] ?? '') === 'IT' && (($punkte['erprobung']['imd'] ?? '') !== 'io')) {
            $meldungen[] = ['code' => 'it_imd', 'stufe' => 'hinweis', 'text' => 'IT-System: Isolationsüberwachung (IMD) erproben; Kreise ohne RCD für den Zweitfehler selbst bewerten.'];
        }
        if (($netz['form'] ?? '') === 'TN-C') {
            $meldungen[] = ['code' => 'netz_tnc', 'stufe' => 'hinweis',
                'text' => 'TN-C: hinter dem Übergabepunkt kein PEN in beweglichen Leitungen, RCDs wirken nur im TN-S-Teil – Netzform prüfen (meist TN-C-S).'];
        }
        $hat = $u !== null || ($netz['drehfeld'] ?? '') !== '';
        return ['auto' => $hat || $meldungen !== [] ? self::stufe($meldungen, 'ok') : '', 'meldungen' => $meldungen];
    }

    /**
     * Alles auf einmal – so antwortet das Autosave.
     *
     * @return array{kreise:array<string,array<string,mixed>>, erdung:array<string,mixed>, netz:array<string,mixed>}
     */
    public static function alle(array $inhalt): array
    {
        $p = is_array($inhalt['grenzwerte'] ?? null) && $inhalt['grenzwerte'] !== [] ? $inhalt['grenzwerte'] : self::parameter();
        $netz = is_array($inhalt['netz'] ?? null) ? $inhalt['netz'] : [];
        $messung = is_array($inhalt['messung'] ?? null) ? $inhalt['messung'] : [];
        $kreise = is_array($inhalt['kreise'] ?? null) ? $inhalt['kreise'] : [];
        $nachZ = [];
        foreach ($kreise as $k) {
            if (is_array($k) && (string) ($k['z'] ?? '') !== '') {
                $nachZ[(string) $k['z']] = $k;
            }
        }
        $out = [];
        foreach ($nachZ as $z => $k) {
            $out[$z] = self::kreis($k, $nachZ, $netz, $p, $messung);
        }
        return [
            'kreise' => $out,
            'erdung' => self::erdung($messung, $netz, array_values($nachZ), $p),
            'netz'   => self::netz($netz, is_array($inhalt['punkte'] ?? null) ? $inhalt['punkte'] : [], $p),
        ];
    }

    // ------------------------------------------------------------ Hilfen

    /** Lesbar mit Komma, höchstens zwei Nachkommastellen: 1,4375 → „1,44“, 50.0 → „50“. */
    public static function komma(float $wert, int $stellen = 2): string
    {
        $s = number_format($wert, $stellen, ',', '');
        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    /** Als Dezimaltext (für grenzen und den Bericht): 1.4375 → „1.4375“. */
    public static function dez(float $wert, int $stellen = 4): string
    {
        $s = rtrim(rtrim(number_format($wert, $stellen, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private static function charakteristik(string $char): string
    {
        $c = trim($char);
        return in_array(strtoupper($c), ['B', 'C', 'D', 'K', 'Z'], true) ? strtoupper($c) : $c;
    }

    /**
     * Einen Messwert aus dem Bericht lesen.
     *
     * @return array{op:string,w:float}|null|false  null = leer, false = keine Zahl
     */
    private static function lesen(mixed $feld): array|null|false
    {
        if (!is_array($feld)) {
            return null;
        }
        $roh = (string) ($feld['w'] ?? '');
        if (trim($roh) === '') {
            return null;
        }
        $m = self::messwert($roh);
        if ($m === null) {
            return false;
        }
        $op = (string) ($feld['op'] ?? '');
        if ($m['op'] === '' && in_array($op, ['<', '>'], true)) {
            $m['op'] = $op;
        }
        return ['op' => $m['op'], 'w' => (float) $m['w']];
    }

    /**
     * Ein Wert gegen eine Grenze.
     *
     * $hoechstens: Grenze ist ein Höchstwert (Zs ≤ Zs_max), sonst ein
     * Mindestwert (R_iso ≥ min). Rückgabe ok | ueber (verletzt) | unklar
     * (der Operator lässt es offen, etwa „> 0,3“ bei einem Mindestwert 1,0).
     */
    private static function vergleich(array $wert, float $grenze, bool $hoechstens): string
    {
        $w = $wert['w'];
        $op = $wert['op'];
        if ($hoechstens) {
            return match ($op) {
                '>'     => $w >= $grenze ? 'ueber' : 'unklar',
                '<'     => $w <= $grenze ? 'ok' : 'unklar',
                default => $w <= $grenze ? 'ok' : 'ueber',
            };
        }
        return match ($op) {
            '>'     => $w >= $grenze ? 'ok' : 'unklar',
            '<'     => $w <= $grenze ? 'ueber' : 'unklar',
            default => $w >= $grenze ? 'ok' : 'ueber',
        };
    }

    /**
     * Liegt der Wert im Bereich (min, max]? $minEinschliesslich für
     * [min, max]. min null = ohne Untergrenze. Rückgabe ok | aussen | unklar.
     */
    private static function bereich(array $wert, ?float $min, bool $minEinschliesslich, float $max): string
    {
        $w = $wert['w'];
        $ueberMin = static fn (float $x): bool => $min === null || ($minEinschliesslich ? $x >= $min : $x > $min);
        return match ($wert['op']) {
            '>'     => $w >= $max ? 'aussen' : 'unklar',
            '<'     => ($min !== null && $w <= $min) ? 'aussen' : 'unklar',
            default => ($ueberMin($w) && $w <= $max) ? 'ok' : 'aussen',
        };
    }

    /** Die schlechteste Stufe der Meldungen – oder $sonst, wenn keine schlechter als ok ist. */
    private static function stufe(array $meldungen, string $sonst): string
    {
        $rang = 0;
        $stufe = $sonst;
        foreach ($meldungen as $m) {
            $r = self::STUFEN[$m['stufe']] ?? 0;
            if ($r > 1 && $r > $rang) {
                $rang = $r;
                $stufe = $m['stufe'];
            }
        }
        return $stufe;
    }
}
