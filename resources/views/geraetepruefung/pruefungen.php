<?php
/**
 * Prüfungen der Geräteprüfung: Filter (offen/unterschrieben, Ergebnis,
 * Prüfer laut Gerät, Zeitraum, Besitzer, Suche), Liste mit Auswahl und
 * „Auswahl unterschreiben“ (geraetepruefung.js).
 *
 * @var list<array<string,mixed>> $liste PruefberichtService::liste()
 * @var array<string,string> $filter
 * @var list<string> $pruefer
 * @var list<array{id:int,name:string}> $kunden
 * @var array{darf:bool,recht:bool,name:string,qualifikation:string,ort:string} $unterschrift
 */
use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\PruefberichtService;
use App\Geraetepruefung\Pruefungen;
use App\Support\View;

$title = 'Prüfungen';
$subtitle = 'Geräteprüfung (DGUV V3) – Prüfungen aus dem Prüfgerät, Prüfberichte unterschreiben';
$kundenName = [];
foreach ($kunden as $k) {
    $kundenName[$k['id']] = $k['name'];
}
$darf = $unterschrift['darf'];
$offene = 0;
foreach ($liste as $p) {
    $offene += ($p['bericht'] === null || $p['bericht']['status'] === 'korrektur') ? 1 : 0;
}
?>
<div class="toolbar-zeile">
    <?php if (View::can('geraetepruefung.view')): ?><a class="btn btn-ghost" href="/geraetepruefung">← Geräteprüfung</a><?php endif; ?>
    <div class="spacer"></div>
    <form method="get" action="/geraetepruefung/pruefungen" class="inline-form bericht-filter" role="search">
        <select name="status" aria-label="Stand" data-absenden>
            <option value="">alle</option>
            <option value="offen"<?= $filter['status'] === 'offen' ? ' selected' : '' ?>>offen (ohne gültigen Bericht)</option>
            <option value="unterschrieben"<?= $filter['status'] === 'unterschrieben' ? ' selected' : '' ?>>unterschrieben</option>
        </select>
        <select name="ergebnis" aria-label="Ergebnis" data-absenden>
            <option value="">jedes Ergebnis</option>
            <?php foreach (Pruefungen::ERGEBNISSE as $k => $t): ?>
                <option value="<?= View::e($k) ?>"<?= $filter['ergebnis'] === $k ? ' selected' : '' ?>><?= View::e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($pruefer !== []): ?>
            <select name="pruefer" aria-label="Prüfer laut Gerät" data-absenden>
                <option value="">jeder Prüfer</option>
                <?php foreach ($pruefer as $n): ?>
                    <option value="<?= View::e($n) ?>"<?= $filter['pruefer'] === $n ? ' selected' : '' ?>><?= View::e($n) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="besitzer" aria-label="Besitzer" data-absenden>
            <option value="">alle Besitzer</option>
            <option value="eigen"<?= $filter['besitzer'] === 'eigen' ? ' selected' : '' ?>>eigener Bestand</option>
            <?php foreach ($kunden as $k): ?>
                <option value="<?= (int) $k['id'] ?>"<?= $filter['besitzer'] === (string) $k['id'] ? ' selected' : '' ?>><?= View::e($k['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="von" value="<?= View::e($filter['von']) ?>" aria-label="geprüft ab">
        <input type="date" name="bis" value="<?= View::e($filter['bis']) ?>" aria-label="geprüft bis">
        <input type="search" name="suche" value="<?= View::e($filter['suche']) ?>" placeholder="Kennung, Bezeichnung, Seriennummer" aria-label="Suche">
        <button class="btn" type="submit">Suchen</button>
    </form>
</div>

<?php if ($darf && $offene > 0): ?>
    <div class="card">
        <div class="card-head"><h2>Unterschreiben</h2><span class="small muted" data-auswahl-zahl="input[data-gp-auswahl]"></span></div>
        <div class="card-body">
            <div class="btn-row">
                <button class="btn btn-sm" type="button" data-auswahl-an="input[data-gp-auswahl]">alle offenen auswählen</button>
                <button class="btn btn-sm btn-ghost" type="button" data-auswahl-aus="input[data-gp-auswahl]">keine</button>
            </div>
            <?= View::partial('geraetepruefung/_bericht-unterschreiben', ['u' => $unterschrift, 'ids' => '', 'knopf' => 'Auswahl unterschreiben']) ?>
        </div>
    </div>
<?php elseif (!$darf && $unterschrift['recht']): ?>
    <p class="small text-warn">Unterschreiben geht erst mit einer hinterlegten Prüfer-Qualifikation (Benutzerverwaltung).</p>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr>
                <?php if ($darf): ?><th><span class="sr-only">Auswahl</span></th><?php endif; ?>
                <th>Prüfling</th><th>Besitzer</th><th>Geprüft</th><th>Prüfer laut Gerät</th><th>Ergebnis</th><th>Prüfbericht</th>
            </tr></thead>
            <tbody>
            <?php if ($liste === []): ?>
                <tr><td colspan="<?= $darf ? 7 : 6 ?>" class="muted"><?= array_filter($filter) !== [] ? 'Nichts gefunden.' : 'Noch keine Prüfung – Prüfungen kommen mit dem Import der Datenbank des Prüfgeräts.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($liste as $p): ?>
                <?php
                $b = $p['bericht'];
                $offen = $b === null || $b['status'] === 'korrektur';
                $bestanden = $p['ergebnis'] === 'bestanden';
                $besitzer = $p['kunde_id'] === null ? 'eigener Bestand' : ($kundenName[(int) $p['kunde_id']] ?? 'Kunde #' . (int) $p['kunde_id']);
                ?>
                <tr>
                    <?php if ($darf): ?>
                        <td data-label="Auswahl"><?php if ($offen): ?><label class="check"><input type="checkbox" data-gp-auswahl value="<?= (int) $p['id'] ?>"
                            aria-label="Prüfung <?= View::e((string) $p['kennung']) ?> auswählen"></label><?php endif; ?></td>
                    <?php endif; ?>
                    <td data-label="Prüfling"><a href="/geraetepruefung/pruefungen/<?= (int) $p['id'] ?>"><strong><?= View::e((string) ($p['kennung'] ?? '–')) ?></strong></a>
                        <?php if ((string) ($p['bezeichnung'] ?? '') !== ''): ?><div class="small muted"><?= View::e((string) $p['bezeichnung']) ?></div><?php endif; ?></td>
                    <td data-label="Besitzer"><?= View::e($besitzer) ?></td>
                    <td data-label="Geprüft" class="nowrap"><?= View::e((string) Messarten::ortszeit((string) $p['geprueft_am'], 'd.m.Y H:i')) ?>
                        <div class="small muted"><?= View::e((string) $p['ablauf_name']) ?></div></td>
                    <td data-label="Prüfer laut Gerät"><?= View::e((string) $p['pruefer'] !== '' ? (string) $p['pruefer'] : '–') ?></td>
                    <td data-label="Ergebnis"><span class="badge <?= $bestanden ? 'ok' : 'bad' ?>"><?= $bestanden ? '✓' : '✗' ?> <?= View::e(Pruefungen::ERGEBNISSE[$p['ergebnis']] ?? $p['ergebnis']) ?></span></td>
                    <td data-label="Prüfbericht">
                        <?php if ($b === null): ?>
                            <span class="badge warn">offen</span>
                        <?php else: ?>
                            <a href="/geraetepruefung/berichte/<?= (int) $b['id'] ?>/pdf"><?= View::e($b['nummer']) ?></a>
                            <?php if ($b['status'] !== 'gueltig'): ?><div><span class="badge warn"><?= View::e(PruefberichtService::STATUS[$b['status']] ?? $b['status']) ?></span></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($liste) >= 500): ?><div class="card-footer small muted">Die ersten 500 – bitte mit den Filtern eingrenzen.</div><?php endif; ?>
</div>
