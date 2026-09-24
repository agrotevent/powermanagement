<?php
use App\Support\Csrf;
use App\Support\View;

$t ??= ['id' => 0, 'name' => '', 'strom_a' => 16, 'phasen' => 1, 'spannung_v' => 230, 'farbe' => '#2563eb', 'reihenfolge' => 100, 'notizen' => ''];
$p = 'st' . (int) $t['id'] . '_';
?>
<form method="post" action="/stecker/speichern">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
    <div class="field"><label for="<?= $p ?>name">Name</label><input id="<?= $p ?>name" name="name" required value="<?= View::e($t['name']) ?>"></div>
    <div class="field-row">
        <div class="field"><label for="<?= $p ?>strom">Nennstrom (A)</label><input id="<?= $p ?>strom" name="strom_a" inputmode="decimal" value="<?= View::e((string) (float) $t['strom_a']) ?>"></div>
        <div class="field"><label for="<?= $p ?>phasen">Phasen</label>
            <select id="<?= $p ?>phasen" name="phasen"><option value="3"<?= (int) $t['phasen'] === 3 ? ' selected' : '' ?>>3</option><option value="1"<?= (int) $t['phasen'] === 1 ? ' selected' : '' ?>>1</option></select></div>
        <div class="field"><label for="<?= $p ?>u">Spannung (V)</label><input id="<?= $p ?>u" name="spannung_v" inputmode="numeric" value="<?= (int) $t['spannung_v'] ?>"></div>
    </div>
    <div class="field-row">
        <div class="field"><label for="<?= $p ?>farbe">Farbe</label><input id="<?= $p ?>farbe" type="color" name="farbe" value="<?= View::e($t['farbe']) ?>"></div>
        <div class="field"><label for="<?= $p ?>rf">Reihenfolge</label><input id="<?= $p ?>rf" name="reihenfolge" inputmode="numeric" value="<?= (int) $t['reihenfolge'] ?>"></div>
    </div>
    <div class="field"><label for="<?= $p ?>n">Notiz</label><input id="<?= $p ?>n" name="notizen" value="<?= View::e((string) $t['notizen']) ?>"></div>
    <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
</form>
