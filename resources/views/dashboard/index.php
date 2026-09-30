<?php
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Support\Csrf;
use App\Support\View;

$title = 'Übersicht';
$subtitle = 'Livewerte aller freigegebenen Anlagen';

$stufen = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'stumm' => 0];
foreach ($objekte as $o) {
    $l = $live[(int) $o['id']] ?? [];
    if (isset($stufen[$l['stufe'] ?? ''])) {
        $stufen[$l['stufe']]++;
    }
}
?>
<div class="toolbar-zeile">
    <form method="get" action="/dashboard" class="inline-form">
        <label class="sr-only" for="standort">Standort</label>
        <select id="standort" name="standort" data-absenden>
            <option value="0">Alle Standorte</option>
            <?php foreach ($standorte as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= $siteId === (int) $s['id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <span class="small muted" id="live-stand" data-live-url="/dashboard/live<?= $siteId > 0 ? '?standort=' . $siteId : '' ?>">Livewerte werden alle 5 Sekunden aktualisiert.</span>
</div>

<div class="grid grid-4 kennzahlen">
    <div class="card stat-card"><div class="stat"><span class="label">Einspeisung gesamt</span><span class="value"><?= View::e(Messdaten::format($einspeisung, 'W')) ?></span><span class="trend">je Zweig die oberste gemessene Anlage</span></div></div>
    <div class="card stat-card ok"><div class="stat"><span class="label">Im grünen Bereich</span><span class="value"><?= $stufen['ok'] ?></span><span class="trend">Anlagen mit Messwerten</span></div></div>
    <div class="card stat-card <?= $stufen['warn'] + $stufen['bad'] > 0 ? 'bad' : 'ok' ?>"><div class="stat"><span class="label">Hoch ausgelastet</span><span class="value"><?= $stufen['warn'] + $stufen['bad'] ?></span><span class="trend">ab <?= (int) \App\Services\LiveService::grenzeWarnung() ?> % des Nennstroms</span></div></div>
    <div class="card stat-card <?= $stufen['stumm'] > 0 ? 'warn' : 'ok' ?>"><div class="stat"><span class="label">Ohne aktuelle Werte</span><span class="value"><?= $stufen['stumm'] ?></span><span class="trend">Messgerät seit über 2 min stumm</span></div></div>
</div>

<?php if ($warnungen !== []): ?>
    <div class="card">
        <div class="card-head"><h2>Datenquellen mit Fehler</h2></div>
        <div class="card-body tight">
            <?php foreach ($warnungen as $w): ?>
                <div class="notice danger"><a href="<?= View::e($w['link']) ?>"><?= View::e($w['text']) ?></a></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($objekte === []): ?>
    <div class="card"><div class="card-body">
        <p>Hier sind noch keine Anlagen zu sehen.</p>
        <p class="muted small">Entweder gibt es noch keine – dann unter <a href="/anlagen">Anlagen</a> oder auf der <a href="/karte">Karte</a> anlegen –,
            oder für dieses Konto ist gerade keine Freigabe wirksam.</p>
    </div></div>
<?php else: ?>
    <?php foreach (AnlagenService::ARTEN as $art => $info): ?>
        <?php $dieser = array_values(array_filter($objekte, static fn ($o) => $o['art'] === $art)); ?>
        <?php if ($dieser === []) { continue; } ?>
        <h2 class="abschnitt"><?= View::e($info['mehrzahl']) ?></h2>
        <div class="live-raster">
            <?php foreach ($dieser as $o): ?>
                <?= View::partial('partials/live-kachel', ['objekt' => $o, 'l' => $live[(int) $o['id']] ?? []]) ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($schaltbar !== []): ?>
    <div class="card mt">
        <div class="card-head"><h2>Schalten</h2><span class="hint">nur Geräte mit Schalt-Freigabe</span></div>
        <div class="card-body tight">
            <?= View::partial('partials/schalter', ['schaltbar' => $schaltbar]) ?>
        </div>
    </div>
<?php endif; ?>
