<?php
/**
 * Reiter Stammdaten: Name, Kundennummer, aktiv, Ende, Notizen – ändern und
 * löschen nur mit „kunden.manage“. Gelöscht wird nur ein beendeter Kunde,
 * mit seinem Namen als Bestätigung.
 *
 * @var array<string,mixed> $kunde
 * @var array<string,int> $zahlen
 * @var bool $pflegen
 * @var array<string,mixed> $eingabe
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$e = ($eingabe['form'] ?? '') === 'stammdaten' ? $eingabe : [];
$wert = static fn (string $feld): string => (string) ($e[$feld] ?? $kunde[$feld] ?? '');
$bisDatum = array_key_exists('gueltig_bis', $e) ? (string) $e['gueltig_bis'] : Zeit::anzeige($kunde['gueltig_bis'] ?? null, 'Y-m-d');
$bisDatum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bisDatum) === 1 ? $bisDatum : '';
$aktiv = array_key_exists('aktiv', $e) ? !empty($e['aktiv']) : (int) $kunde['aktiv'] === 1;
?>
<div class="grid grid-2">
<?php if ($pflegen): ?>
    <form method="post" action="/kunden/speichern" class="card">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card-head"><h2>Stammdaten</h2></div>
        <div class="card-body">
            <div class="field"><label for="s-name">Name</label>
                <input id="s-name" name="name" required minlength="2" maxlength="255" value="<?= View::e($wert('name')) ?>"></div>
            <div class="field-row">
                <div class="field"><label for="s-nummer">Kundennummer</label>
                    <input id="s-nummer" name="kundennummer" maxlength="64" value="<?= View::e($wert('kundennummer')) ?>"></div>
                <div class="field"><label for="s-bis">Zugang endet am</label>
                    <input id="s-bis" name="gueltig_bis" type="date" value="<?= View::e($bisDatum) ?>"></div>
            </div>
            <label class="check"><input type="checkbox" name="aktiv" value="1"<?= $aktiv ? ' checked' : '' ?>> aktiv – ausgeschaltet sind alle Zugänge sofort zu (Daten bleiben)</label>
            <div class="field mt"><label for="s-notizen">Notizen (nur für uns, nie im Kundenbereich)</label>
                <textarea id="s-notizen" name="notizen" rows="4" maxlength="5000"><?= View::e($wert('notizen')) ?></textarea></div>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
<?php else: ?>
    <section class="card">
        <div class="card-head"><h2>Stammdaten</h2></div>
        <div class="card-body">
            <dl class="zugangsdaten">
                <dt>Name</dt><dd><?= View::e((string) $kunde['name']) ?></dd>
                <dt>Kundennummer</dt><dd><?= View::e((string) $kunde['kundennummer'] !== '' ? (string) $kunde['kundennummer'] : '–') ?></dd>
                <dt>Zugang endet</dt><dd><?= View::e(!empty($kunde['gueltig_bis']) ? Zeit::anzeige((string) $kunde['gueltig_bis']) : '–') ?></dd>
                <dt>Aktiv</dt><dd><?= (int) $kunde['aktiv'] === 1 ? 'ja' : 'nein' ?></dd>
            </dl>
            <?php if ((string) ($kunde['notizen'] ?? '') !== ''): ?><p class="small"><?= nl2br(View::e((string) $kunde['notizen'])) ?></p><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

    <section class="card">
        <div class="card-head"><h2>Herkunft</h2></div>
        <div class="card-body">
            <dl class="zugangsdaten">
                <dt>Angelegt</dt><dd><?= View::e(Zeit::anzeige($kunde['created_at'] ?? null)) ?></dd>
                <dt>Geändert</dt><dd><?= View::e(Zeit::anzeige($kunde['updated_at'] ?? null)) ?></dd>
                <dt>Eventworx</dt><dd><?= (string) ($kunde['ewx_kunde_ref'] ?? '') !== ''
                    ? View::e((string) $kunde['ewx_kunde_name']) . ' <span class="small muted mono">' . View::e((string) $kunde['ewx_host']) . '</span>'
                    : '<span class="muted">nicht verknüpft</span>' ?></dd>
            </dl>
        </div>
    </section>

<?php if ($pflegen): ?>
    <form method="post" action="/kunden/<?= $id ?>/loeschen" class="card" id="loeschen"
          data-confirm="Kunde mit allen Zugängen, Freigaben und Prüfnachweisen endgültig löschen?">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Kunde löschen</h2></div>
        <div class="card-body">
            <?php if ((int) $kunde['aktiv'] === 1): ?>
                <p class="muted">Löschen lässt sich nur ein beendeter Kunde: erst „aktiv“ ausschalten und speichern.
                    Beenden schließt alle Zugänge sofort, die Daten bleiben.</p>
            <?php else: ?>
                <p>Weg sind danach: <strong><?= (int) $zahlen['zugaenge'] ?></strong> Zugänge, <strong><?= (int) $zahlen['freigaben'] ?></strong> Freigaben,
                    <strong><?= (int) $zahlen['vorgaenge'] ?></strong> Eventworx-Vorgänge, die Prüfnachweise des Kunden und
                    <strong><?= (int) $zahlen['abrufe'] ?></strong> Abrufe. <strong><?= (int) $zahlen['berichte'] ?></strong> Übergabeberichte bleiben
                    (Aufbewahrung), nur ohne Zuordnung zum Kunden.</p>
                <div class="field"><label for="l-name">Zur Bestätigung den Namen des Kunden eintragen</label>
                    <input id="l-name" name="bestaetigung" required autocomplete="off" placeholder="<?= View::e((string) $kunde['name']) ?>"></div>
                <button class="btn btn-danger" type="submit">Endgültig löschen</button>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>
</div>
