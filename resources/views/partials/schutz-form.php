<?php
/**
 * Formular für ein Schutzorgan – neu oder zum Ändern.
 *
 * @var int $objektId
 * @var array<string,mixed>|null $s
 * @var list<array<string,mixed>> $schutz
 */
use App\Services\SchutzService;
use App\Support\Csrf;
use App\Support\View;

$s ??= ['id' => 0, 'art' => 'ls', 'bezeichnung' => '', 'nennstrom_a' => null, 'charakteristik' => 'C', 'fehlerstrom_ma' => null, 'rcd_typ' => 'A', 'pole' => 3, 'vorgeschaltet_id' => null, 'notizen' => ''];
$p = 'sf' . (int) $s['id'] . '_';
?>
<form method="post" action="/anlagen/<?= (int) $objektId ?>/schutz" class="schutz-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="schutz_id" value="<?= (int) $s['id'] ?>">
    <div class="field-row">
        <div class="field">
            <label for="<?= $p ?>art">Art</label>
            <select id="<?= $p ?>art" name="art">
                <?php foreach (SchutzService::ARTEN as $k => $name): ?><option value="<?= $k ?>"<?= $s['art'] === $k ? ' selected' : '' ?>><?= View::e($name) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="<?= $p ?>bez">Bezeichnung</label>
            <input id="<?= $p ?>bez" name="bezeichnung" value="<?= View::e((string) $s['bezeichnung']) ?>" placeholder="F1, Q3 … (leer: automatisch)">
        </div>
    </div>
    <div class="field-row">
        <div class="field">
            <label for="<?= $p ?>strom">Nennstrom (A)</label>
            <input id="<?= $p ?>strom" name="nennstrom_a" inputmode="decimal" value="<?= $s['nennstrom_a'] !== null ? View::e(rtrim(rtrim(number_format((float) $s['nennstrom_a'], 1, ',', ''), '0'), ',')) : '' ?>">
        </div>
        <div class="field">
            <label for="<?= $p ?>char">Charakteristik</label>
            <select id="<?= $p ?>char" name="charakteristik">
                <?php foreach (SchutzService::CHARAKTERISTIK as $c): ?><option value="<?= $c ?>"<?= (string) $s['charakteristik'] === $c ? ' selected' : '' ?>><?= $c === '' ? '–' : $c ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="<?= $p ?>pole">Pole</label>
            <select id="<?= $p ?>pole" name="pole">
                <?php foreach ([1, 2, 3, 4] as $pol): ?><option<?= (int) $s['pole'] === $pol ? ' selected' : '' ?>><?= $pol ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field-row">
        <div class="field">
            <label for="<?= $p ?>ma">Fehlerstrom (mA) – nur RCD/RCBO</label>
            <select id="<?= $p ?>ma" name="fehlerstrom_ma">
                <option value="">–</option>
                <?php foreach ([10, 30, 100, 300, 500] as $ma): ?><option<?= (int) $s['fehlerstrom_ma'] === $ma ? ' selected' : '' ?>><?= $ma ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="<?= $p ?>typ">RCD-Typ</label>
            <select id="<?= $p ?>typ" name="rcd_typ">
                <?php foreach (SchutzService::RCD_TYPEN as $t): ?><option value="<?= $t ?>"<?= (string) $s['rcd_typ'] === $t ? ' selected' : '' ?>><?= $t === '' ? '–' : $t ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field">
        <label for="<?= $p ?>vor">Hängt hinter</label>
        <select id="<?= $p ?>vor" name="vorgeschaltet_id">
            <option value="0">– direkt an der Einspeisung –</option>
            <?php foreach ($schutz as $v): ?>
                <?php if ((int) $v['id'] !== (int) $s['id']): ?>
                    <option value="<?= (int) $v['id'] ?>"<?= (int) $s['vorgeschaltet_id'] === (int) $v['id'] ? ' selected' : '' ?>><?= str_repeat('· ', (int) $v['tiefe']) . View::e($v['text']) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= $p ?>notiz">Notiz</label>
        <input id="<?= $p ?>notiz" name="notizen" value="<?= View::e((string) $s['notizen']) ?>" placeholder="z. B. Prüfdatum, Beschriftung in der Verteilung">
    </div>
    <button class="btn btn-primary btn-sm" type="submit"><?= (int) $s['id'] > 0 ? 'Speichern' : 'Eintragen' ?></button>
</form>
