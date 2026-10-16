<?php
/**
 * Ein Stromkreis im Formular – auch die Vorlage für neue Zeilen
 * ($vorlage = true, Zeilenschlüssel „__Z__“).
 *
 * @var array $k Kreis (vollständig, wie BerichtSchema::kreisLeer())
 * @var ?array $bw Bewertung des Servers für diese Zeile
 * @var list<array{z:string,nr:string,ort:string}> $zeilen alle Zeilen (für „RCD-Werte gemessen an“)
 */
use App\Berichte\Felder;
use App\Services\SchutzService;
use App\Support\View;

$vorlage = $vorlage ?? false;
$z = $vorlage ? Felder::VORLAGE : (string) $k['z'];
$o = ['z' => $z];
$abg = array_values(array_filter(is_array($k['abgeleitet'] ?? null) ? $k['abgeleitet'] : [], 'is_string'));
$bw = $bw ?? null;
$grenzen = is_array($bw['grenzen'] ?? null) ? $bw['grenzen'] : [];
[$klasse, $text] = Felder::bewertung((string) ($bw['auto'] ?? ''), (string) $k['pruefer']);
$mw = static fn (string $feld, string $label, string $einheit, string $extra = '') => Felder::messwert('w.' . $feld, (array) $k['w'][$feld], $label, $einheit,
    $o + ['grenze' => $feld, 'grenzeText' => Felder::grenzeText($feld, $grenzen), 'klasse' => $extra]);
$wahl = static fn (array $liste): array => array_combine($liste, array_map(static fn ($v) => $v === '' ? '–' : $v, $liste));
$a = static fn (string $pfad): bool => in_array($pfad, $abg, true);
?>
<article class="kreis stufe-<?= View::e($klasse) ?>" data-zeile="<?= View::e($z) ?>" data-abgeleitet="<?= View::e(implode(' ', $abg)) ?>"
    data-stufe="<?= View::e((string) ($bw['auto'] ?? '')) ?>" data-feld-ziel="kreise.<?= View::e($z) ?>" tabindex="-1">
    <div class="kreis-kopf">
        <?= Felder::text('nr', (string) $k['nr'], 'Nr.', $o + ['max' => 8, 'klasse' => 'kreis-nr']) ?>
        <div class="kreis-ort">
            <?= Felder::text('ort', (string) $k['ort'], 'Ort / Anlagenteil', $o + ['max' => 255]) ?>
            <div class="kreis-info"><span data-schutz-text><?= View::e(Felder::schutzText($k)) ?></span><?php if ((string) $k['kabel'] !== ''): ?>
                <span class="kreis-kabel" data-kabel-text><?= View::e((string) $k['kabel']) ?></span><?php endif; ?></div>
        </div>
        <span class="bewertung <?= View::e($klasse) ?>" data-bewertung><?= View::e($text) ?></span>
    </div>
    <div class="kreis-werte">
        <?= $mw('zs', 'Zs', 'Ω') ?>
        <?= $mw('riso', 'R_iso', 'MΩ') ?>
        <?= $mw('rpe', 'R_PE', 'Ω') ?>
        <?= $mw('idelta', 'IΔ', 'mA') ?>
        <?= $mw('ta', 't_A', 'ms') ?>
        <?= $mw('ub', 'U_B', 'V') ?>
    </div>
    <ul class="meldungen kreis-meldungen" data-meldungen>
        <?php foreach (($bw['meldungen'] ?? []) as $m): ?><li class="<?= View::e($m['stufe']) ?>"><?= View::e($m['text']) ?></li><?php endforeach; ?>
    </ul>
    <details class="kreis-details"<?= $vorlage ? ' open' : '' ?>>
        <summary>Leitung, Schutz, weitere Werte, Bewertung</summary>
        <div class="feld-raster">
            <?= Felder::auswahl('art', (string) $k['art'], 'Art', ['end' => 'Endstromkreis', 'verteiler' => 'zu einem Verteiler'], $o) ?>
            <?= Felder::text('kabel', (string) $k['kabel'], 'Gestecktes Kabel', $o + ['max' => 255]) ?>
            <?= Felder::text('leitung.art', (string) $k['leitung']['art'], 'Steckverbinder / Leitung', $o + ['max' => 64, 'abgeleitet' => $a('leitung.art')]) ?>
            <?= Felder::text('leitung.leiter', (string) $k['leitung']['leiter'], 'Leiter', $o + ['zahl' => true, 'max' => 2, 'abgeleitet' => $a('leitung.leiter')]) ?>
            <?= Felder::text('leitung.querschnitt', (string) $k['leitung']['querschnitt'], 'Querschnitt', $o + ['zahl' => true, 'einheit' => 'mm²', 'abgeleitet' => $a('leitung.querschnitt')]) ?>
            <?= Felder::auswahl('ueb.art', (string) $k['ueb']['art'], 'Überstromschutz', ['' => '–', 'LS' => 'LS', 'RCBO' => 'RCBO (FI/LS)', 'NH' => 'NH/Schmelzsicherung'], $o) ?>
            <?= Felder::auswahl('ueb.char', (string) $k['ueb']['char'], 'Charakteristik', $wahl(SchutzService::CHARAKTERISTIK), $o) ?>
            <?= Felder::text('ueb.in', (string) $k['ueb']['in'], 'Nennstrom In', $o + ['zahl' => true, 'einheit' => 'A']) ?>
            <?= Felder::text('ueb.grenze_hand', (string) $k['ueb']['grenze_hand'], 'Zs-Grenzwert von Hand', $o + ['zahl' => true, 'einheit' => 'Ω']) ?>
            <?= Felder::text('rcd.bez', (string) $k['rcd']['bez'], 'RCD', $o + ['max' => 32, 'platzhalter' => 'z. B. F2']) ?>
            <?= Felder::text('rcd.in', (string) $k['rcd']['in'], 'RCD Nennstrom', $o + ['zahl' => true, 'einheit' => 'A']) ?>
            <?= Felder::text('rcd.idn', (string) $k['rcd']['idn'], 'IΔn', $o + ['zahl' => true, 'einheit' => 'mA']) ?>
            <?= Felder::auswahl('rcd.typ', (string) $k['rcd']['typ'], 'RCD-Typ', $wahl(SchutzService::RCD_TYPEN), $o) ?>
            <div class="field"><span class="form-label">Selektiv</span><?= Felder::haken('rcd.s', (string) $k['rcd']['s'] === '1', 'Typ S', $o) ?></div>
            <?= Felder::messwert('w.ik', (array) $k['w']['ik'], 'Ik (statt Zs)', 'A', $o + ['grenze' => 'ik', 'grenzeText' => Felder::grenzeText('ik', $grenzen)]) ?>
            <?= Felder::auswahl('w.riso_u', (string) $k['w']['riso_u'], 'Messspannung R_iso', ['' => 'wie oben', '250' => '250 V', '500' => '500 V', '1000' => '1000 V'], $o) ?>
            <?= Felder::auswahl('drehfeld', (string) $k['drehfeld'], 'Drehfeld', ['' => '–', 'rechts' => 'rechts', 'links' => 'links'], $o) ?>
            <div class="field">
                <label for="<?= View::e(Felder::id('rcd_von', $z)) ?>">RCD-Werte gemessen an</label>
                <select id="<?= View::e(Felder::id('rcd_von', $z)) ?>" data-feld="rcd_von" data-rcd-von>
                    <option value="">an diesem Kreis</option>
                    <?php foreach ($zeilen ?? [] as $andere): ?>
                        <?php if ($andere['z'] !== $z && (string) $k['rcd_von'] === $andere['z']): ?>
                            <option value="<?= View::e($andere['z']) ?>" selected><?= View::e('Kreis ' . $andere['nr'] . ($andere['ort'] !== '' ? ' · ' . mb_strimwidth($andere['ort'], 0, 40, '…') : '')) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php /* Die übrigen Zeilen setzt bericht.js beim Öffnen ein – 80 Zeilen × 80 Optionen wären sonst ein halbes Megabyte. */ ?>
            </div>
        </div>
        <div class="feld-raster">
            <div class="field"><span class="form-label">Messung</span>
                <?= Felder::haken('status', (string) $k['status'] === 'nicht_gemessen', 'nicht gemessen', $o + ['an' => 'nicht_gemessen', 'aus' => 'offen']) ?></div>
            <?= Felder::text('grund', (string) $k['grund'], 'Grund (wenn nicht gemessen)', $o + ['max' => 255, 'platzhalter' => 'z. B. Verbraucher fest angeschlossen']) ?>
        </div>
        <div class="field">
            <span class="form-label">Bewertung durch den Prüfer</span>
            <?= Felder::dreiwahl('pruefer', (string) $k['pruefer'], ['auto' => 'automatisch', 'io' => 'i.O.', 'nio' => 'n.i.O.'], $o + ['fest' => true, 'label' => 'Bewertung durch den Prüfer']) ?>
        </div>
        <div class="feld-raster">
            <?= Felder::text('begruendung', (string) $k['begruendung'], 'Begründung (bei Abweichung von der Automatik)', $o + ['max' => 500]) ?>
            <?= Felder::text('bem', (string) $k['bem'], 'Bemerkung', $o + ['max' => 500]) ?>
        </div>
        <div class="btn-row kreis-aktionen">
            <button class="btn btn-sm" type="button" data-kreis-kopieren>Zeile kopieren (ohne Messwerte)</button>
            <button class="btn btn-sm btn-ghost" type="button" data-kreis-entfernen>Zeile entfernen</button>
        </div>
    </details>
</article>
