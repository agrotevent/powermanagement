<?php
use App\Auth\Permissions;
use App\Support\Csrf;
use App\Support\View;

$title = 'Rollen & Rechte';
$subtitle = 'Was eine Rolle darf – woran und wann, regeln die Freigaben';
$formular = static function (?array $r) {
    $rechte = $r['permissions'] ?? [];
    $alles = in_array('*', $rechte, true);
    ob_start(); ?>
    <form method="post" action="/system/rollen/speichern">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
        <div class="field-row">
            <div class="field"><label>Name</label><input name="name" required value="<?= View::e((string) ($r['name'] ?? '')) ?>"></div>
            <div class="field"><label>Beschreibung</label><input name="description" value="<?= View::e((string) ($r['description'] ?? '')) ?>"></div>
        </div>
        <?php if ($alles): ?>
            <p class="notice">Diese Rolle darf alles.</p>
        <?php else: ?>
            <div class="grid grid-2">
                <?php foreach (Permissions::ALL as $gruppe => $liste): ?>
                    <div class="rechte-gruppe">
                        <h3><?= View::e($gruppe) ?></h3>
                        <?php foreach ($liste as $k => $text): ?>
                            <label class="check"><input type="checkbox" name="rechte[]" value="<?= View::e($k) ?>"<?= in_array($k, $rechte, true) ? ' checked' : '' ?>> <span><?= View::e($text) ?> <span class="small muted mono"><?= View::e($k) ?></span></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<?php foreach ($rollen as $r): ?>
    <div class="card">
        <div class="card-head">
            <h2><?= View::e($r['name']) ?></h2>
            <span class="hint"><?= (int) $r['benutzer'] ?> Benutzer</span>
            <span class="spacer"></span>
            <a class="btn btn-sm" href="/system/freigaben?art=role&amp;id=<?= (int) $r['id'] ?>">Freigaben</a>
            <?php if ((int) $r['is_system'] !== 1): ?>
                <form method="post" action="/system/rollen/loeschen" data-confirm="Rolle löschen?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="small muted"><?= View::e((string) $r['description']) ?></p>
            <details<?= (int) $bearbeiten === (int) $r['id'] ? ' open' : '' ?>>
                <summary class="btn btn-sm">Rechte: <?= in_array('*', $r['permissions'], true) ? 'alle' : count($r['permissions']) ?></summary>
                <div class="mt"><?= $formular($r) ?></div>
            </details>
        </div>
    </div>
<?php endforeach; ?>
<div class="card">
    <div class="card-head"><h2>Neue Rolle</h2></div>
    <div class="card-body"><?= $formular(null) ?></div>
</div>
