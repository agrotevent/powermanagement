<?php
/**
 * Kunden & Kundenbereich – Liste der Kunden mit Zahlen, Suche und
 * Formular „Neuer Kunde“ (nur mit „kunden.manage“).
 *
 * @var list<array<string,mixed>> $kunden KundenService::alle()
 * @var array{suche:string,aktiv:string} $filter
 * @var array<string,mixed> $eingabe Formular „Neuer Kunde“ (nach einem Fehler die Eingaben)
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Kunden & Kundenbereich';
$subtitle = 'Kunden mit eigenem Zugang: ihre Locations, Protokolle und Prüfnachweise';
$pflegen = View::can('kunden.manage');
$neuDatum = (string) ($eingabe['gueltig_bis'] ?? '');
?>
<form method="get" action="/kunden" class="card">
    <div class="card-body field-row">
        <div class="field"><label for="k-suche">Suche</label>
            <input id="k-suche" name="suche" type="search" value="<?= View::e($filter['suche']) ?>" placeholder="Name oder Kundennummer"></div>
        <div class="field"><label for="k-aktiv">Zustand</label>
            <select id="k-aktiv" name="aktiv" data-absenden>
                <?php foreach (['' => 'alle', '1' => 'aktiv', '0' => 'beendet'] as $w => $t): ?>
                    <option value="<?= View::e((string) $w) ?>"<?= $filter['aktiv'] === (string) $w ? ' selected' : '' ?>><?= View::e($t) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label class="sr-only" for="k-los">Suchen</label><button id="k-los" class="btn" type="submit">Suchen</button></div>
    </div>
</form>

<div class="card mt">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Kunde</th><th>Kundennr.</th><th>Zugänge</th><th>Locations jetzt</th><th>Protokolle</th><th>Prüfnachweise</th><th>Zustand</th></tr></thead>
            <tbody>
            <?php if ($kunden === []): ?>
                <tr><td colspan="7" class="muted"><?= $filter['suche'] !== '' || $filter['aktiv'] !== '' ? 'Kein Kunde passt zur Suche.' : 'Noch keine Kunden.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($kunden as $k): ?>
                <tr>
                    <td data-label="Kunde"><a href="/kunden/<?= (int) $k['id'] ?>"><strong><?= View::e((string) $k['name']) ?></strong></a></td>
                    <td data-label="Kundennr." class="mono"><?= View::e((string) $k['kundennummer'] !== '' ? (string) $k['kundennummer'] : '–') ?></td>
                    <td data-label="Zugänge"><?= (int) $k['zugaenge'] ?></td>
                    <td data-label="Locations jetzt"><?= (int) $k['locations_aktiv'] ?><?= (int) $k['kommend'] > 0 ? ' <span class="small muted">(+' . (int) $k['kommend'] . ' kommend)</span>' : '' ?></td>
                    <td data-label="Protokolle"><?= (int) $k['berichte'] ?></td>
                    <td data-label="Prüfnachweise"><?= (int) $k['nachweise'] ?></td>
                    <td data-label="Zustand">
                        <?php if ($k['sperre'] === null): ?>
                            <span class="badge ok">aktiv</span><?= !empty($k['gueltig_bis']) ? ' <span class="small muted">bis ' . View::e(Zeit::anzeige((string) $k['gueltig_bis'], 'd.m.Y')) . '</span>' : '' ?>
                        <?php else: ?>
                            <span class="badge"><?= (int) $k['aktiv'] === 1 ? 'abgelaufen' : 'beendet' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pflegen): ?>
<form method="post" action="/kunden/speichern" class="card mt kunden-formular" id="neuer-kunde">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Neuer Kunde</h2><span class="hint">danach Zugänge und Freigaben</span></div>
    <div class="card-body">
        <div class="field-row">
            <div class="field"><label for="n-name">Name</label>
                <input id="n-name" name="name" required minlength="2" maxlength="255" value="<?= View::e((string) $eingabe['name']) ?>" placeholder="Firma oder Veranstalter"></div>
            <div class="field"><label for="n-nummer">Kundennummer</label>
                <input id="n-nummer" name="kundennummer" maxlength="64" value="<?= View::e((string) $eingabe['kundennummer']) ?>" placeholder="optional"></div>
        </div>
        <div class="field-row">
            <div class="field"><label for="n-bis">Zugang endet am</label>
                <input id="n-bis" name="gueltig_bis" type="date" value="<?= View::e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $neuDatum) === 1 ? $neuDatum : '') ?>">
                <div class="help">Danach sind alle Zugänge des Kunden zu. Leer: bis er beendet wird.</div></div>
            <div class="field"><label class="check"><input type="checkbox" name="aktiv" value="1"<?= !empty($eingabe['aktiv']) ? ' checked' : '' ?>> aktiv</label></div>
        </div>
        <div class="field"><label for="n-notizen">Notizen (nur für uns, nie im Kundenbereich)</label>
            <textarea id="n-notizen" name="notizen" rows="2" maxlength="5000"><?= View::e((string) $eingabe['notizen']) ?></textarea></div>
        <p class="help">Ein Kunde sieht zunächst nichts. Sichtbar wird, was ihm ausdrücklich gegeben wird: Standorte über Freigaben
            (mit Ende und Datenzeitraum), später Protokolle und Prüfnachweise.</p>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Kunde anlegen</button></div>
</form>
<?php endif; ?>
