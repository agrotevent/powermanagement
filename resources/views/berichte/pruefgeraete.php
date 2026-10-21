<?php
use App\Berichte\Pruefgeraete;
use App\Support\Csrf;
use App\Support\View;

$title = 'Prüfgeräte';
$subtitle = 'Installationstester und Erdungsmesser für die Übergabeberichte';
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/berichte">← Übergabeberichte</a>
    <?php if ($darf): ?><a class="btn btn-sm btn-primary" href="/berichte/pruefgeraete?neu=1">+ Prüfgerät</a><?php endif; ?>
    <span class="small muted">Nicht die Messgeräte im Inventar (Shellys), sondern die, mit denen geprüft wird. Gelöscht wird keins – nur stillgelegt.</span>
</div>

<?php if ($geraet !== null): ?>
    <form method="post" action="/berichte/pruefgeraete/speichern" class="card">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $geraet['id'] ?>">
        <div class="card-head"><h2><?= (int) $geraet['id'] > 0 ? View::e(trim($geraet['fabrikat'] . ' ' . $geraet['typ'])) . ' bearbeiten' : 'Neues Prüfgerät' ?></h2></div>
        <div class="card-body">
            <div class="field-row">
                <div class="field"><label for="pg-fabrikat">Fabrikat</label><input id="pg-fabrikat" name="fabrikat" required maxlength="80" value="<?= View::e($geraet['fabrikat']) ?>" placeholder="z. B. Fluke"></div>
                <div class="field"><label for="pg-typ">Typ</label><input id="pg-typ" name="typ" required maxlength="80" value="<?= View::e($geraet['typ']) ?>" placeholder="z. B. 1664 FC"></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="pg-sn">Seriennummer</label><input id="pg-sn" name="seriennummer" maxlength="80" value="<?= View::e($geraet['seriennummer']) ?>"></div>
                <div class="field"><label for="pg-kal">Kalibriert bis</label><input id="pg-kal" name="kalibriert_bis" type="date" value="<?= View::e($geraet['kalibriert_bis']) ?>"></div>
            </div>
            <div class="field"><label for="pg-norm">Norm</label><input id="pg-norm" name="norm" maxlength="160" value="<?= View::e($geraet['norm']) ?>" placeholder="<?= View::e(Pruefgeraete::NORM) ?>"></div>
            <div class="field"><label for="pg-notiz">Notizen</label><input id="pg-notiz" name="notizen" maxlength="2000" value="<?= View::e((string) $geraet['notizen']) ?>"></div>
            <label class="check"><input type="checkbox" name="aktiv" value="1"<?= (int) $geraet['aktiv'] === 1 ? ' checked' : '' ?>> <span>aktiv – im Bericht wählbar</span></label>
        </div>
        <div class="card-footer btn-row">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn btn-ghost" href="/berichte/pruefgeraete">Abbrechen</a>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Gerät</th><th>Seriennummer</th><th>Norm</th><th>Kalibriert bis</th><th></th></tr></thead>
            <tbody>
            <?php if ($liste === []): ?><tr><td colspan="5" class="muted">Noch kein Prüfgerät.</td></tr><?php endif; ?>
            <?php foreach ($liste as $g): ?>
                <?php $ab = Pruefgeraete::abgelaufen($g, $heute); ?>
                <tr class="<?= (int) $g['aktiv'] === 1 ? '' : 'stillgelegt' ?>">
                    <td data-label="Gerät"><strong><?= View::e(trim($g['fabrikat'] . ' ' . $g['typ'])) ?></strong><?= (int) $g['aktiv'] === 1 ? '' : ' <span class="badge">stillgelegt</span>' ?></td>
                    <td data-label="Seriennummer" class="mono small"><?= View::e($g['seriennummer'] ?: '–') ?></td>
                    <td data-label="Norm" class="small"><?= View::e($g['norm']) ?></td>
                    <td data-label="Kalibriert bis"><?= $g['kalibriert_bis'] !== '' ? View::e($datum((string) $g['kalibriert_bis'])) : '–' ?>
                        <?= $ab ? ' <span class="badge bad">abgelaufen</span>' : '' ?></td>
                    <td class="right"><?php if ($darf): ?><a class="btn btn-sm" href="/berichte/pruefgeraete?id=<?= (int) $g['id'] ?>">Bearbeiten</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
