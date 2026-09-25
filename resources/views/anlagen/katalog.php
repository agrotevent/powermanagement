<?php
use App\Services\AnlagenService;
use App\Support\View;

$title = 'Standard-Verteiler';
$subtitle = 'Katalog: Verteiler, Aggregate und Feststromanschlüsse mit fester Bestückung, die auf der Karte platziert werden';
$darf = View::can('stecker.manage') || View::can('anlagen.scope.all');
?>
<div class="toolbar-zeile">
    <p class="muted small mb0">Ein Standard-Verteiler bringt Anschlüsse, Sicherungen und RCDs mit. Auf der Karte unter
        „+ Anlage“ gewählt, entsteht daraus eine eigene Anlage mit genau dieser Bestückung – die sich danach einzeln anpassen lässt.
        Typen mit „fest“ (etwa die Energiesäulen eines Geländes) ergeben Feststromanschlüsse.</p>
    <div class="spacer"></div>
    <?php if (View::can('inventory.pull')): ?><a class="btn btn-sm btn-ghost" href="/verteiler-abgleich" title="Standard-Verteiler mit anderen Installationen abgleichen">Abgleich</a><?php endif; ?>
    <?php if ($darf): ?>
        <a class="btn btn-sm" href="/anlagen/neu?art=anschluss&amp;vorlage=1&amp;fest=1">+ Feststromanschluss-Typ</a>
        <a class="btn btn-primary btn-sm" href="/anlagen/neu?art=verteilung&amp;vorlage=1">+ Neuer Standard-Verteiler</a>
    <?php endif; ?>
</div>
<div class="katalog-raster">
    <?php if ($vorlagen === []): ?>
        <div class="card"><div class="card-body muted">Der Katalog ist leer.</div></div>
    <?php endif; ?>
    <?php foreach ($vorlagen as $v): ?>
        <?php $art = AnlagenService::ARTEN[$v['art']]; ?>
        <a class="card katalog-karte" href="/anlagen/<?= (int) $v['id'] ?>">
            <div class="card-body">
                <div class="katalog-kopf">
                    <span class="art-zeichen" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
                    <strong><?= View::e($v['name']) ?></strong>
                    <?php if ((int) $v['fest'] === 1): ?><span class="badge info" title="Anlagen daraus sind fest installiert">fest</span><?php endif; ?>
                </div>
                <div class="small muted"><?= View::e(trim($v['hersteller'] . ' ' . $v['modell'])) ?></div>
                <p class="small mt"><?= View::e($v['kurzform']) ?></p>
                <p class="small muted mb0"><?= (int) $v['schutz'] ?> Schutzorgane<?= $v['nennstrom_a'] !== null ? ' · ' . (int) $v['nennstrom_a'] . ' A' : '' ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>
