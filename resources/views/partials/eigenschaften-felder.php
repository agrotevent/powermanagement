<?php
/**
 * Felder für Eigenschaften einer Anlage (AnlagenService::FELDER) – eine
 * Gruppe, mit Titel als <fieldset>.
 *
 * @var string $art
 * @var string $gruppe Titel, '' = ohne Rahmen
 * @var list<string> $schluesselListe
 * @var array<string,mixed> $eigenschaften
 */
use App\Services\AnlagenService;
use App\Support\View;

$zahl = [View::class, 'zahlFeld'];
?>
<?php if ($gruppe !== ''): ?><fieldset><legend><?= View::e($gruppe) ?></legend><?php endif; ?>
<?php foreach ($schluesselListe as $schluessel): ?>
    <?php [$beschriftung, $typ, $zusatz] = AnlagenService::FELDER[$art][$schluessel]; ?>
    <div class="field">
        <label for="e_<?= $schluessel ?>"><?= View::e($beschriftung) ?><?= $typ !== 'auswahl' && $zusatz !== '' ? ' (' . View::e($zusatz) . ')' : '' ?></label>
        <?php $wert = $eigenschaften[$schluessel] ?? ''; ?>
        <?php if ($typ === 'auswahl'): ?>
            <select id="e_<?= $schluessel ?>" name="eigenschaften[<?= $schluessel ?>]">
                <option value="">–</option>
                <?php foreach ($zusatz as $option): ?>
                    <option<?= (string) $wert === $option ? ' selected' : '' ?>><?= View::e($option) ?></option>
                <?php endforeach; ?>
                <?php if ((string) $wert !== '' && !in_array((string) $wert, $zusatz, true)): ?>
                    <?php /* Ein Wert, den die Auswahl nicht kennt (etwa aus einem Abgleich), bleibt beim Speichern erhalten. */ ?>
                    <option value="<?= View::e((string) $wert) ?>" selected><?= View::e((string) $wert) ?></option>
                <?php endif; ?>
            </select>
        <?php else: ?>
            <input id="e_<?= $schluessel ?>" name="eigenschaften[<?= $schluessel ?>]" value="<?= View::e(is_float($wert) ? $zahl($wert) : (string) $wert) ?>"<?= $typ === 'zahl' ? ' inputmode="decimal"' : '' ?>>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($gruppe !== ''): ?></fieldset><?php endif; ?>
