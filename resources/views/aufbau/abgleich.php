<?php
use App\Services\AufbauService;
use App\Support\View;

$title = 'Abgleich · ' . $aufbau['name'];
$subtitle = $aufbau['site_name'] . ' · ' . AufbauService::STATUS[$aufbau['status']];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm" href="/aufbau/<?= (int) $aufbau['id'] ?>">← Aufbau</a>
    <?php if ($aufbau['status'] === 'aufbau'): ?><span class="small muted">Der Aufbau läuft noch – der Abgleich zeigt den jetzigen Stand.</span><?php endif; ?>
    <div class="spacer"></div>
    <a class="btn btn-sm" href="/aufbau/<?= (int) $aufbau['id'] ?>/druck" target="_blank" rel="noopener">Drucken</a>
</div>
<?php if ($berichte !== null): ?>
    <?= View::partial('berichte/_aufbau-karte', ['aufbau' => $aufbau, 'berichte' => $berichte, 'berichtNeu' => $berichtNeu, 'aufbauGeaendert' => $aufbauGeaendert]) ?>
<?php endif; ?>
<div class="card"><div class="card-body">
    <?= View::partial('aufbau/abgleich-inhalt', ['aufbau' => $aufbau, 'abgleich' => $abgleich, 'betrieb' => $betrieb]) ?>
</div></div>
