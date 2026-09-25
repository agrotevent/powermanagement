<?php
use App\Services\InventarService;
use App\Support\Csrf;
use App\Support\View;

$neu = (int) $item['id'] === 0;
$zs = $item['art'] === 'zwischenstecker';
$title = $neu ? ($zs ? 'Neuer Zwischenstecker' : 'Neuer Verteiler') : $item['kennung'] . ' bearbeiten';
$subtitle = $zs ? 'Messadapter bis 125 A – misst, wo er gesteckt wird' : 'Verteiler aus dem Lager mit seinen Shellys';
$stellen = $zs ? ['durchgang' => 'Durchgang'] : ($stellenJeTyp[(string) ($item['vorlage_id'] ?? '')] ?? $stellenJeTyp['']);
// Die eingetragenen Shellys und Platz für weitere (mindestens drei Zeilen, immer eine leere).
$zeilen = $messgeraete;
$bis = max(3, count($messgeraete) + 1);
while (count($zeilen) < $bis) {
    $zeilen[] = ['geraet' => '', 'kanal' => '', 'stelle' => $zs ? 'durchgang' : 'einspeisung', 'bezeichnung' => ''];
}
$kanaele = array_values(array_unique(array_map(static fn ($g) => (string) $g['kanal'], $geraete)));
?>
<form method="post" action="/inventar/speichern" class="card" id="inventar-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <input type="hidden" name="art" value="<?= View::e($item['art']) ?>">
    <div class="card-head"><h2><?= View::e($title) ?></h2></div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <div class="field"><label for="kennung">Inventarnummer (Aufkleber)</label>
                    <input id="kennung" name="kennung" required value="<?= View::e($item['kennung']) ?>" placeholder="<?= $zs ? 'z. B. ZS-63-02' : 'z. B. PD63-07' ?>"></div>
                <div class="field"><label for="name">Name</label>
                    <input id="name" name="name" value="<?= View::e($item['name']) ?>" placeholder="optional, z. B. „roter PD 63 mit 3EM“"></div>
                <?php if ($zs): ?>
                    <div class="field"><label for="steckertyp_id">Steckverbinder</label>
                        <select id="steckertyp_id" name="steckertyp_id" required>
                            <option value="">– wählen –</option>
                            <?php foreach ($steckertypen as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === (int) $item['steckertyp_id'] ? ' selected' : '' ?>><?= View::e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                <?php else: ?>
                    <div class="field"><label for="vorlage_id">Typ (Standard-Verteiler)</label>
                        <select id="vorlage_id" name="vorlage_id" data-stellen-je-typ="<?= View::e((string) json_encode($stellenJeTyp, JSON_UNESCAPED_UNICODE)) ?>">
                            <option value="">– ohne Typ –</option>
                            <?php foreach ($vorlagen as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"<?= (int) $v['id'] === (int) $item['vorlage_id'] ? ' selected' : '' ?>><?= View::e($v['name'] . ($v['modell'] !== '' ? ' (' . $v['modell'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Der Typ bringt Anschlüsse und Sicherungen mit. Wird das Stück in eine Anlage anderen Typs eingesetzt, lässt sich die Anlage umstellen.</div></div>
                <?php endif; ?>
                <div class="field"><label for="seriennummer">Seriennummer</label><input id="seriennummer" name="seriennummer" value="<?= View::e($item['seriennummer']) ?>"></div>
                <label class="check"><input type="checkbox" name="defekt" value="1"<?= (int) $item['defekt'] === 1 ? ' checked' : '' ?>> <span>defekt – nicht einsetzen</span></label>
                <div class="field"><label for="notizen">Notizen</label><textarea id="notizen" name="notizen" rows="3"><?= View::e((string) $item['notizen']) ?></textarea></div>
            </div>
            <div>
                <h3>Shellys</h3>
                <p class="help"><?= $zs
                    ? 'Der Shelly im Zwischenstecker misst den Durchgang – am Abgang oder an der Einspeisung, wo der Stecker beim Aufbau steckt.'
                    : 'Ein Shelly an der Einspeisung misst den ganzen Verteiler; Shellys an einzelnen Abgängen messen, was dort hängt. Beides geht auch zusammen.' ?></p>
                <div class="inventar-zeilen" data-inventar-zeilen>
                    <?php foreach ($zeilen as $i => $g): ?>
                        <fieldset class="inventar-zeile">
                            <div class="field-row">
                                <div class="field"><label>Gerät</label><input name="geraet[]" list="geraete-liste" value="<?= View::e($g['geraet']) ?>" placeholder="shellypro3em-…" autocomplete="off"></div>
                                <div class="field"><label>Kanal</label><input name="kanal[]" list="kanal-liste" value="<?= View::e($g['kanal']) ?>" placeholder="em:0" autocomplete="off"></div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label>Sitzt an</label>
                                    <select name="stelle[]" data-stelle="<?= View::e($g['stelle']) ?>">
                                        <?php foreach ($stellen as $k => $n): ?><option value="<?= View::e($k) ?>"<?= $k === $g['stelle'] ? ' selected' : '' ?>><?= View::e($n) ?></option><?php endforeach; ?>
                                    </select></div>
                                <div class="field"><label>Bezeichnung</label><input name="bezeichnung[]" value="<?= View::e($g['bezeichnung']) ?>" placeholder="optional"></div>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm" data-zeile-dazu>+ weiterer Shelly</button>
                <datalist id="geraete-liste">
                    <?php foreach (array_unique(array_map(static fn ($g) => (string) $g['geraet'], $geraete)) as $g): ?><option value="<?= View::e($g) ?>"></option><?php endforeach; ?>
                </datalist>
                <datalist id="kanal-liste">
                    <?php foreach ($kanaele as $k): ?><option value="<?= View::e($k) ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="<?= $neu ? '/inventar' : '/inventar/' . (int) $item['id'] ?>">Abbrechen</a>
    </div>
</form>
