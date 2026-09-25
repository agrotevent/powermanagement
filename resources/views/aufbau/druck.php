<?php
use App\Services\AufbauService;
use App\Support\View;
use App\Support\Zeit;

$title = 'Abgleich · ' . $aufbau['name'];
?>
<div class="druck-blatt">
    <header class="druck-kopf">
        <div>
            <h1>Abgleich Planung ↔ Umsetzung</h1>
            <div><?= View::e($aufbau['name'] . ' · ' . $aufbau['site_name'] . ' · ' . AufbauService::STATUS[$aufbau['status']]) ?></div>
        </div>
        <div class="right small">Stand <?= View::e(Zeit::anzeige(Zeit::iso(time()))) ?></div>
    </header>
    <?= View::partial('aufbau/abgleich-inhalt', ['aufbau' => $aufbau, 'abgleich' => $abgleich, 'betrieb' => $betrieb]) ?>
</div>
