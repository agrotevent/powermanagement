<?php
/**
 * Eine Kachel mit den Livewerten einer Anlage.
 *
 * live.js aktualisiert sie über die data-Attribute – die Kachel wird dazu
 * nicht neu gezeichnet, nur ihre Zahlen. So springt auf dem Tablet nichts,
 * während man daneben tippt.
 *
 * @var array<string,mixed> $objekt
 * @var array<string,mixed> $l Zusammenfassung aus LiveService
 */
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Support\View;

$art = AnlagenService::ARTEN[$objekt['art']] ?? ['kuerzel' => '?', 'farbe' => '#64748b', 'name' => $objekt['art']];
$stufe = (string) ($l['stufe'] ?? 'keine');
?>
<div class="live-kachel stufe-<?= View::e($stufe) ?>" data-objekt-id="<?= (int) $objekt['id'] ?>">
    <div class="live-kopf">
        <span class="art-zeichen" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
        <div class="live-titel">
            <a class="live-name" href="/anlagen/<?= (int) $objekt['id'] ?>"><?= View::e($objekt['name']) ?></a>
            <span class="live-ort small muted"><?= View::e(trim(($objekt['kennung'] ?? '') . ' · ' . ($objekt['site_name'] ?? ''), ' ·')) ?></span>
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
                <span class="phase-balken"><span data-balken style="width: <?= $i !== null && ($l['auslastung'] ?? null) !== null && ($l['i'] ?? []) !== [] ? min(100, max(2, (float) $i / max(0.01, max($l['i'])) * (float) $l['auslastung'])) : 0 ?>%"></span></span>
                <span class="phase-wert" data-strom><?= $i !== null ? View::e(number_format((float) $i, 1, ',', '.')) . ' A' : '' ?></span>
                <span class="phase-wert muted" data-spannung><?= isset($l['u'][$phase]) ? View::e(number_format((float) $l['u'][$phase], 0, ',', '.')) . ' V' : '' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="live-zustand" data-feld="zustand">
        <?php foreach (['soc' => 'SOC', 'tank_prozent' => 'Tank', 'drehzahl' => 'U/min', 'motorzustand' => '', 'zustand_text' => ''] as $feld => $etikett): ?>
            <?php if (isset($l['zustand'][$feld])): ?>
                <span class="chip"><?= $etikett !== '' ? View::e($etikett) . ' ' : '' ?><?= View::e(is_numeric($l['zustand'][$feld]) ? number_format((float) $l['zustand'][$feld], 0, ',', '.') . ($feld === 'soc' || $feld === 'tank_prozent' ? ' %' : '') : (string) $l['zustand'][$feld]) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="live-hinweis small muted" data-feld="hinweis"><?= View::e((string) ($l['hinweis'] ?? '')) ?></div>
</div>
