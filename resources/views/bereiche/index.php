<?php
use App\Support\View;

$title = 'Bereiche';
$subtitle = 'Last mehrerer Anlagen und Abgänge zusammen – ohne Doppelzählung, mit Materialliste';
?>
<div class="toolbar-zeile">
    <p class="small muted mb0">Ein Bereich rechnet zusammen, was dort tatsächlich verbraucht wird – etwa eine Foodtruck-Meile an mehreren
        Aggregaten, die auch anderes versorgen. Was hinter einem anderen Mitglied hängt, zählt nicht doppelt.</p>
    <div class="spacer"></div>
    <?php if ($darf): ?><a class="btn btn-primary btn-sm" href="/bereiche/neu">+ Neuer Bereich</a><?php endif; ?>
    <span class="small muted" data-live-url="/bereiche/live"></span>
</div>
<?php if ($bereiche === []): ?>
    <div class="card"><div class="card-body">
        <p>Noch kein Bereich angelegt.</p>
        <p class="small muted mb0">Ein Bereich besteht aus ganzen Anlagen (etwa den Verteilern der Foodtruck-Meile) und einzelnen gemessenen
            Abgängen (etwa dem Shelly am Aggregat-Abgang, an dem ein Truck hängt).</p>
    </div></div>
<?php else: ?>
    <div class="live-raster">
        <?php foreach ($bereiche as $b): ?>
            <?= View::partial('bereiche/kachel', ['bereich' => $b, 'l' => $live[(int) $b['id']] ?? []]) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
