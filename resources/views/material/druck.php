<?php
use App\Support\View;
use App\Support\Zeit;

$title = $titel;
?>
<div class="druck-blatt">
    <header class="druck-kopf">
        <div>
            <h1><?= View::e($titel) ?></h1>
            <div><?= View::e($untertitel) ?></div>
        </div>
        <div class="right small">Stand <?= View::e(Zeit::anzeige(Zeit::iso(time()))) ?></div>
    </header>
    <?= View::partial('material/liste', ['material' => $material, 'druck' => true]) ?>
</div>
