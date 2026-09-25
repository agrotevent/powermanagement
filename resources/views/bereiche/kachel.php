<?php
/**
 * Kachel eines Bereichs – live.js aktualisiert sie wie eine Anlagenkachel.
 *
 * @var array<string,mixed> $bereich
 * @var array<string,mixed> $l Livewerte aus BereichService::live()
 */
use App\Messung\Messdaten;
use App\Support\View;

$stufe = (string) ($l['stufe'] ?? 'keine');
$farbe = (string) ($bereich['farbe'] ?: '#0ea5e9');
$grenze = array_filter([
    $bereich['grenze_kw'] !== null ? number_format((float) $bereich['grenze_kw'], 0, ',', '.') . ' kW' : null,
    $bereich['grenze_a'] !== null ? number_format((float) $bereich['grenze_a'], 0, ',', '.') . ' A je Phase' : null,
]);
?>
<div class="live-kachel bereich-kachel stufe-<?= View::e($stufe) ?>" data-bereich-id="<?= (int) $bereich['id'] ?>" style="--bereich-farbe: <?= View::e($farbe) ?>">
    <div class="live-kopf">
        <span class="bereich-zeichen" aria-hidden="true"></span>
        <div class="live-titel">
            <a class="live-name" href="/bereiche/<?= (int) $bereich['id'] ?>"><?= View::e($bereich['name']) ?></a>
            <span class="live-ort small muted"><?= View::e(trim(($bereich['site_name'] ?? '') . ' · ' . (int) ($bereich['mitglieder_zahl'] ?? 0) . ' Mitglieder', ' ·')) ?></span>
        </div>
    </div>
    <div class="live-leistung">
        <span data-feld="p"><?= View::e(Messdaten::format($l['p'] ?? null, 'W')) ?></span>
        <span class="live-auslastung" data-feld="auslastung"><?= ($l['auslastung'] ?? null) !== null ? View::e(number_format((float) $l['auslastung'], 0, ',', '.')) . ' %' : '' ?></span>
    </div>
    <div class="live-phasen">
        <?php foreach (['L1', 'L2', 'L3'] as $phase): ?>
            <?php $i = $l['i'][$phase] ?? null; ?>
            <div class="phase-zeile" data-phase="<?= $phase ?>"<?= $i === null ? ' hidden' : '' ?>>
                <span class="phase-name"><?= $phase ?></span>
                <span class="phase-balken"><span data-balken style="width: 0%"></span></span>
                <span class="phase-wert" data-strom><?= $i !== null ? View::e(number_format((float) $i, 1, ',', '.')) . ' A' : '' ?></span>
                <span class="phase-wert muted" data-spannung></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($grenze !== []): ?><div class="small muted">Grenze <?= View::e(implode(' · ', $grenze)) ?></div><?php endif; ?>
    <div class="live-hinweis small muted" data-feld="hinweis"><?= View::e((string) ($l['hinweis'] ?? '')) ?></div>
</div>
