<?php
/*
 * Prüfabläufe des Prüfgeräts (aus der Vorlage): ansehen mit
 * „geraetepruefung.view“, Norm und Zubehör pflegen mit
 * „geraetepruefung.manage“. Ablauf, Name und Grenzwerte bleiben Sache des
 * Geräts. Erwartet $ablaeufe (BenningVorlage::pruefablaeufe) und
 * $pruefgeraete (Pruefgeraete::liste).
 */
use App\Support\Csrf;
use App\Support\View;

$title = 'Geräteprüfung: Prüfabläufe';
$subtitle = 'aus den Einstellungen des Prüfgeräts – Norm und Zubehör hier';
$darf = View::can('geraetepruefung.manage');
$geraetText = static fn (array $g): string => trim($g['fabrikat'] . ' ' . $g['typ']) . ($g['seriennummer'] !== '' ? ', Nr. ' . $g['seriennummer'] : '')
    . ((int) $g['aktiv'] === 1 ? '' : ' (stillgelegt)');
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a>
    <?php if ($darf): ?><a class="btn btn-sm btn-ghost" href="/geraetepruefung/import">Import</a><?php endif; ?>
    <span class="small muted">Angelegt und geändert werden Prüfabläufe nur am Gerät; ein neuer Import mit „als Vorlage übernehmen“ gleicht sie ab.</span>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Prüfablauf</th><th>Schutzklasse</th><th>Norm</th><th>Zubehör</th><th class="num">Prüflinge</th></tr></thead>
            <tbody>
            <?php if ($ablaeufe === []): ?>
                <tr><td colspan="5" class="muted">Noch keine Prüfabläufe – sie kommen mit dem ersten Import einer Datenbank des Prüfgeräts.</td></tr>
            <?php endif; ?>
            <?php foreach ($ablaeufe as $a): ?>
                <tr id="ablauf-<?= (int) $a['id'] ?>" class="<?= $a['ungueltig'] ? 'stillgelegt' : '' ?>">
                    <td data-label="Prüfablauf"><strong><?= View::e((string) $a['name']) ?></strong>
                        <div class="small muted">ID <?= View::e((string) $a['geraet_id']) ?><?= $a['nr'] !== '' ? ' · Nr. ' . View::e((string) $a['nr']) : '' ?></div>
                        <div><?= $a['fest'] ? '<span class="badge">ab Werk</span> ' : '' ?><?= $a['individuell'] ? '<span class="badge info">individuell</span> ' : '' ?>
                            <?= $a['ungueltig'] ? '<span class="badge warn">ungültig</span>' : '' ?></div></td>
                    <td data-label="Schutzklasse"><?= View::e($a['schutzklasse'] !== '' ? 'SK ' . str_repeat('I', max(1, min(3, (int) $a['schutzklasse']))) : '–') ?></td>
                    <td data-label="Norm" class="small">
                        <?= $a['norm'] !== '' ? View::e((string) $a['norm']) : '<span class="muted">' . View::e((string) $a['norm_abgeleitet']) . ' (abgeleitet)</span>' ?>
                    </td>
                    <td data-label="Zubehör" class="small">
                        <?= $a['zubehoer_geraete'] === [] ? '–' : View::e(implode('; ', array_map($geraetText, $a['zubehoer_geraete']))) ?>
                        <?php if ($darf): ?>
                            <details class="mt">
                                <summary>bearbeiten</summary>
                                <form method="post" action="/geraetepruefung/pruefablaeufe/<?= (int) $a['id'] ?>/speichern">
                                    <?= Csrf::field() ?>
                                    <div class="field">
                                        <label for="pa-norm-<?= (int) $a['id'] ?>">Norm (leer = abgeleitet)</label>
                                        <input id="pa-norm-<?= (int) $a['id'] ?>" name="norm" maxlength="160" value="<?= View::e((string) $a['norm']) ?>"
                                               placeholder="<?= View::e((string) $a['norm_abgeleitet']) ?>">
                                    </div>
                                    <fieldset class="field">
                                        <legend>Zubehör (Prüfgeräte, etwa Messadapter)</legend>
                                        <?php if ($pruefgeraete === []): ?>
                                            <div class="help">Noch keine Prüfgeräte – sie stehen unter <a href="/berichte/pruefgeraete">Prüfgeräte</a>.</div>
                                        <?php endif; ?>
                                        <?php foreach ($pruefgeraete as $g): ?>
                                            <?php $an = in_array((int) $g['id'], $a['zubehoer'], true); ?>
                                            <?php if ((int) $g['aktiv'] !== 1 && !$an) { continue; } ?>
                                            <label class="check"><input type="checkbox" name="zubehoer[]" value="<?= (int) $g['id'] ?>"<?= $an ? ' checked' : '' ?>> <span><?= View::e($geraetText($g)) ?></span></label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td data-label="Prüflinge" class="num"><?= (int) $a['prueflinge'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
