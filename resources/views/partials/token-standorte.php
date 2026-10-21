<?php
/**
 * Welche Standorte ein Zugang (Token) sieht und wo er bearbeiten darf.
 *
 * Ein Standort umfasst alle seine Etagen, auch künftige. „Bearbeiten“
 * schließt „Sehen“ ein und wirkt nur mit der Befugnis „vorschlagen“ bzw.
 * „schreiben“.
 *
 * @var list<array<string,mixed>> $standorte SiteService::all()
 * @var list<int|string> $sehen
 * @var list<int|string> $bearbeiten
 */
use App\Support\View;

$sehen = array_map('intval', $sehen ?? []);
$bearbeiten = array_map('intval', $bearbeiten ?? []);
?>
<?php if ($standorte === []): ?>
    <p class="small muted">Noch kein Standort angelegt.</p>
<?php else: ?>
    <div class="btn-row token-standorte-alle">
        <label class="check"><input type="checkbox" data-toggle-all="#token-standorte" data-toggle-name="sehen[]"> alle sehen</label>
        <label class="check"><input type="checkbox" data-toggle-all="#token-standorte" data-toggle-name="bearbeiten[]"> alle bearbeiten</label>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten token-standorte">
            <thead><tr><th>Standort</th><th>Sehen</th><th>Bearbeiten</th></tr></thead>
            <tbody id="token-standorte">
            <?php foreach ($standorte as $s): ?>
                <?php $id = (int) $s['id']; $etagen = count((array) ($s['floors'] ?? [])); ?>
                <tr>
                    <td data-label="Standort"><strong><?= View::e((string) $s['name']) ?></strong>
                        <span class="small muted"><?= $etagen === 1 ? '1 Etage' : $etagen . ' Etagen' ?></span></td>
                    <td data-label="Sehen"><label class="check"><input type="checkbox" name="sehen[]" value="<?= $id ?>"
                        aria-label="<?= View::e((string) $s['name']) ?> sehen"<?= in_array($id, $sehen, true) || in_array($id, $bearbeiten, true) ? ' checked' : '' ?>> sehen</label></td>
                    <td data-label="Bearbeiten"><label class="check"><input type="checkbox" name="bearbeiten[]" value="<?= $id ?>"
                        aria-label="<?= View::e((string) $s['name']) ?> bearbeiten"<?= in_array($id, $bearbeiten, true) ? ' checked' : '' ?>> bearbeiten</label></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="help">„Bearbeiten“ schließt „Sehen“ ein und wirkt nur mit „vorschlagen“ oder „schreiben“: dann meldet die Schnittstelle
        die Etagen als bearbeitbar, und die andere Instanz darf Änderungen schicken.</p>
<?php endif; ?>
