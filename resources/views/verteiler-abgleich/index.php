<?php
use App\Services\VerteilerBundle;
use App\Services\VerteilerSync;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Verteiler-Abgleich';
$subtitle = 'Standard-Verteiler und Inventar über das gemeinsame Repository – wie die Locations';
$dortDa = ['aktuell', 'neu', 'dort_neuer', 'hier_neuer', 'beide', 'fehler'];
$bereiche = [
    'typ'      => ['Standard-Verteiler', 'Katalog: Anschlüsse, Sicherungen, Nennwerte', '/anlagen/'],
    'inventar' => ['Inventar', 'Verteiler und Zwischenstecker mit ihren Shellys', '/inventar/'],
];
?>
<?php if ($hindernis !== null): ?>
    <div class="notice warn"><?= View::e($hindernis) ?></div>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <p>Die <strong>Standard-Verteiler</strong> aus dem Katalog und die Verteiler und Zwischenstecker aus dem <strong>Inventar</strong>
            (mit ihren Shellys) gehen über dasselbe Repository wie die Locations – eine Datei je Verteiler unter <code>verteiler/</code>.
            So kennen alle Installationen dieselben Typen und dasselbe Lager. Wo ein Stück gerade eingesetzt ist, bleibt in der Installation,
            die den Aufbau führt.</p>
        <p>Verglichen wird je Verteiler mit dem Stand beim letzten Abgleich. <strong>Alle übernehmen</strong> und <strong>Alle hochladen</strong>
            nehmen nur die eindeutigen Fälle; wo hier und dort geändert wurde, entscheidest du je Verteiler, welcher Stand gilt.
            Beim Übernehmen wird hier nichts gelöscht.</p>
        <p class="small muted mb0">Repository: <span class="mono"><?= View::e($repo !== '' ? $repo : 'nicht eingetragen') ?></span> · Zweig <span class="mono"><?= View::e($zweig) ?></span>
            <?= $geholt ? ' · Stand <span class="mono">' . View::e($commit) . '</span>' : '' ?></p>
    </div>
    <div class="card-footer btn-row">
        <a class="btn btn-sm<?= $geholt ? '' : ' btn-primary' ?><?= $hindernis !== null ? ' disabled' : '' ?>" href="/verteiler-abgleich?holen=1" data-laeuft="fragt ab …" data-laeuft-hinweis="Fragt das Repository ab. Über eine langsame Leitung kann das dauern. Bitte nicht noch einmal klicken.">Repository abfragen</a>
        <?php if ($geholt && $liste['uebernehmen'] > 0 && ($erlaubt['typ'] || $erlaubt['inventar'])): ?>
            <form method="post" action="/verteiler-abgleich/uebernehmen" data-laeuft="übernimmt …" data-confirm="<?= (int) $liste['uebernehmen'] ?> Verteiler aus dem Repository übernehmen (neu oder dort neuer)?">
                <?= Csrf::field() ?><input type="hidden" name="alle" value="1">
                <button class="btn btn-primary btn-sm" type="submit">Alle übernehmen (<?= (int) $liste['uebernehmen'] ?>)</button>
            </form>
        <?php endif; ?>
        <?php if ($geholt && $darfHoch && $liste['hochladen'] > 0): ?>
            <form method="post" action="/verteiler-abgleich/hochladen" data-laeuft="lädt hoch …" data-confirm="<?= (int) $liste['hochladen'] ?> Verteiler ins Repository hochladen (nur hier oder hier geändert)?">
                <?= Csrf::field() ?><input type="hidden" name="alle" value="1">
                <button class="btn btn-primary btn-sm" type="submit">Alle hochladen (<?= (int) $liste['hochladen'] ?>)</button>
            </form>
        <?php endif; ?>
        <a class="btn btn-sm btn-ghost" href="/location">Location-Abgleich</a>
    </div>
</div>
<?php if ($hinweis !== ''): ?><div class="notice warn"><?= View::e($hinweis) ?></div><?php endif; ?>
<?php if (!$geholt): ?>
    <div class="notice">Ohne Abfrage zeigt die Liste nur, was sich hier seit dem letzten Abgleich geändert hat. Übernehmen und Hochladen gibt es nach „Repository abfragen“.</div>
<?php endif; ?>

<?php foreach ($bereiche as $bereich => [$ueberschrift, $hint, $link]): ?>
<div class="card">
    <div class="card-head"><h2><?= View::e($ueberschrift) ?></h2><span class="hint"><?= View::e($hint) ?> · <span class="mono"><?= View::e(VerteilerBundle::BEREICHE[$bereich]['ordner']) ?>/</span></span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Verteiler</th><th>Inhalt</th><th>Stand</th><th></th></tr></thead>
            <tbody>
            <?php if ($liste[$bereich] === []): ?>
                <tr><td colspan="4" class="muted"><?= $bereich === 'typ' ? 'Weder hier noch im Repository ein Standard-Verteiler.' : 'Weder hier noch im Repository ein Inventarstück.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($liste[$bereich] as $e): ?>
                <?php [$statusText, $statusKlasse] = VerteilerSync::STATUS[$e['status']] ?? [$e['status'], '']; ?>
                <tr>
                    <td data-label="Verteiler">
                        <strong><?= $e['id'] > 0 ? '<a href="' . View::e($link . (int) $e['id']) . '">' . View::e($e['titel']) . '</a>' : View::e($e['titel']) ?></strong>
                        <div class="small muted mono"><?= View::e($e['schluessel']) ?></div>
                    </td>
                    <td data-label="Inhalt" class="small"><?= View::e($e['beschreibung']) ?></td>
                    <td data-label="Stand">
                        <span class="badge <?= View::e($statusKlasse) ?>"><?= View::e($statusText) ?></span>
                        <?php if ($e['unterschiede'] !== []): ?><div class="small">anders: <?= View::e(implode(', ', $e['unterschiede'])) ?></div><?php endif; ?>
                        <?php if ($e['fehler'] !== null): ?><div class="small muted"><?= View::e($e['fehler']) ?></div><?php endif; ?>
                        <?php if ($e['dort_stand'] !== '' && $e['status'] !== 'aktuell'): ?>
                            <div class="small muted">dort: <?= View::e(Zeit::anzeige($e['dort_stand'])) ?><?= $e['dort_von'] !== '' ? ' · ' . View::e($e['dort_von']) : '' ?></div>
                        <?php endif; ?>
                        <?php if ($e['synced_at'] !== ''): ?><div class="small muted">abgeglichen <?= View::e(Zeit::anzeige($e['synced_at'])) ?></div><?php endif; ?>
                    </td>
                    <td class="right">
                        <?php if ($geholt): ?>
                        <div class="btn-row">
                            <?php if (in_array($e['status'], ['neu', 'dort_neuer', 'beide'], true) && $erlaubt[$bereich]): ?>
                                <form method="post" action="/verteiler-abgleich/uebernehmen" data-laeuft="übernimmt …"<?= $e['status'] === 'beide' ? ' data-confirm="„' . View::e($e['titel']) . '“ aus dem Repository übernehmen? Was hier geändert wurde, geht verloren."' : '' ?>>
                                    <?= Csrf::field() ?><input type="hidden" name="bereich" value="<?= View::e($bereich) ?>"><input type="hidden" name="schluessel" value="<?= View::e($e['schluessel']) ?>">
                                    <button class="btn btn-sm<?= $e['status'] !== 'beide' ? ' btn-primary' : '' ?>" type="submit">Übernehmen</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($e['status'], ['nur_hier', 'hier_neuer', 'beide', 'dort_entfernt'], true) && $darfHoch): ?>
                                <?php $frage = match ($e['status']) {
                                    'beide'         => '„' . $e['titel'] . '“ hochladen? Was dort geändert wurde, wird überschrieben.',
                                    'dort_entfernt' => '„' . $e['titel'] . '“ wurde aus dem Repository entfernt. Wieder hochladen?',
                                    default         => '',
                                }; ?>
                                <form method="post" action="/verteiler-abgleich/hochladen" data-laeuft="lädt hoch …"<?= $frage !== '' ? ' data-confirm="' . View::e($frage) . '"' : '' ?>>
                                    <?= Csrf::field() ?><input type="hidden" name="bereich" value="<?= View::e($bereich) ?>"><input type="hidden" name="schluessel" value="<?= View::e($e['schluessel']) ?>">
                                    <button class="btn btn-sm<?= in_array($e['status'], ['nur_hier', 'hier_neuer'], true) ? ' btn-primary' : '' ?>" type="submit">Hochladen</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($e['status'], $dortDa, true) && $darfHoch): ?>
                                <form method="post" action="/verteiler-abgleich/entfernen" data-laeuft="entfernt …" data-confirm="„<?= View::e($e['titel']) ?>“ aus dem Repository entfernen? Hier bleibt es stehen; die anderen Installationen behalten ihre Kopie.">
                                    <?= Csrf::field() ?><input type="hidden" name="bereich" value="<?= View::e($bereich) ?>"><input type="hidden" name="schluessel" value="<?= View::e($e['schluessel']) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit" title="Aus dem Repository entfernen">Entfernen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
