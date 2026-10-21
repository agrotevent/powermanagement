<?php
/**
 * Reiter Protokolle: die Übergabeberichte, die dem Kunden zugeordnet sind –
 * und ob er sie im Kundenbereich sieht (erst abgeschlossen, nur mit
 * gespeichertem PDF). Vorschläge kommen nur aus Eventworx (verknüpfter
 * Vorgang oder derselbe Eventworx-Kunde), nie über den Standort; zugeordnet
 * wird nie automatisch. Zuordnen und herausnehmen mit „kunden.manage“, und
 * nur Berichte, die du selbst siehst.
 *
 * @var array<string,mixed> $kunde
 * @var array{zugeordnet:list<array<string,mixed>>,vorschlaege:list<array<string,mixed>>} $protokolle
 * @var ?array{bericht:int,kunde:int,text:string,zurueck:?int} $rueckfrage
 * @var bool $pflegen
 */
use App\Berichte\BerichtService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
$nummer = static fn (array $b): string => $b['nummer'] !== null && (string) $b['nummer'] !== '' ? (string) $b['nummer'] : 'Entwurf #' . (int) $b['id'];
$rueckfrage = $rueckfrage ?? null;
?>
<?php if ($rueckfrage !== null): ?>
    <form method="post" action="/berichte/<?= (int) $rueckfrage['bericht'] ?>/kundenbereich" class="notice warn">
        <?= Csrf::field() ?>
        <input type="hidden" name="kunde_id" value="<?= (int) $rueckfrage['kunde'] ?>">
        <input type="hidden" name="bestaetigt" value="1">
        <input type="hidden" name="zurueck" value="<?= $id ?>">
        <p class="mb0"><?= View::e($rueckfrage['text']) ?></p>
        <button class="btn btn-sm mt" type="submit">Ja, derselbe Kunde – zuordnen</button>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Zugeordnete Protokolle</h2><span class="hint">Übergabeberichte – für alle Fassungen einer Nummer</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Bericht</th><th>Anlage / Veranstaltung</th><th>Prüfung</th><th>Im Kundenbereich</th><th>Zuletzt aufgerufen</th><th></th></tr></thead>
            <tbody>
            <?php if ($protokolle['zugeordnet'] === []): ?>
                <tr><td colspan="6" class="muted">Noch keinem Bericht zugeordnet. Zugeordnet wird am Bericht (Karte „Kundenbereich“) oder unten aus den Vorschlägen.</td></tr>
            <?php endif; ?>
            <?php foreach ($protokolle['zugeordnet'] as $b): ?>
                <tr>
                    <td data-label="Bericht"><?php if ($b['sichtbar']): ?><a href="/berichte/<?= (int) $b['id'] ?>"><strong><?= View::e($nummer($b)) ?></strong></a><?php else: ?><strong><?= View::e($nummer($b)) ?></strong><?php endif; ?>
                        <div class="small muted"><?= View::e(BerichtService::STATUS[(string) $b['status']] ?? (string) $b['status']) ?><?= (int) $b['fassung'] > 1 ? ' · Fassung ' . (int) $b['fassung'] : '' ?></div></td>
                    <td data-label="Anlage / Veranstaltung"><?= View::e(implode(' · ', array_filter([(string) $b['titel'], (string) $b['veranstaltung'], (string) $b['standort_name']])) ?: '–') ?>
                        <?php if ((string) $b['kunde_name'] !== ''): ?><div class="small muted">im Bericht: <?= View::e((string) $b['kunde_name']) ?></div><?php endif; ?></td>
                    <td data-label="Prüfung"><?= View::e($datum((string) $b['pruefdatum'])) ?></td>
                    <td data-label="Im Kundenbereich"><?php if ($b['beim_kunden']): ?><span class="badge ok">sichtbar</span>
                        <?php elseif (in_array($b['status'], ['entwurf', 'geprueft'], true)): ?><span class="badge">nach dem Abschluss</span>
                        <?php elseif ($b['pdf_sha256'] === null): ?><span class="badge warn">ohne PDF</span>
                        <?php else: ?><span class="badge">nicht sichtbar</span><?php endif; ?></td>
                    <td data-label="Zuletzt aufgerufen" class="small"><?= $b['abruf'] !== null ? View::e(Zeit::anzeige((string) $b['abruf']['am']) . (($b['abruf']['art'] ?? '') === 'ansicht' ? ' (angesehen)' : ' (PDF)')) : '<span class="muted">nie</span>' ?></td>
                    <td class="right">
                        <?php if ($pflegen && $b['sichtbar']): ?>
                            <form method="post" action="/berichte/<?= (int) $b['id'] ?>/kundenbereich" data-confirm="<?= View::e($nummer($b)) ?> aus dem Kundenbereich nehmen? Der Kunde sieht den Bericht sofort nicht mehr.">
                                <?= Csrf::field() ?><input type="hidden" name="kunde_id" value=""><input type="hidden" name="zurueck" value="<?= $id ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">Herausnehmen</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pflegen): ?>
    <div class="card">
        <div class="card-head"><h2>Vorschläge</h2><span class="hint">aus Eventworx – nie automatisch</span></div>
        <?php if ($protokolle['vorschlaege'] === []): ?>
            <div class="card-body"><p class="muted mb0">Kein Vorschlag. Vorschläge gibt es für Berichte, deren Eventworx-Vorgang mit diesem Kunden verknüpft
                ist oder bei denen Eventworx denselben Kunden nennt. Jeden anderen Bericht ordnest du an ihm selbst zu.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="tabelle-karten">
                    <thead><tr><th>Bericht</th><th>Anlage / Veranstaltung</th><th>Prüfung</th><th>Warum</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($protokolle['vorschlaege'] as $b): ?>
                        <tr>
                            <td data-label="Bericht"><a href="/berichte/<?= (int) $b['id'] ?>"><strong><?= View::e($nummer($b)) ?></strong></a>
                                <div class="small muted"><?= View::e(BerichtService::STATUS[(string) $b['status']] ?? (string) $b['status']) ?></div></td>
                            <td data-label="Anlage / Veranstaltung"><?= View::e(implode(' · ', array_filter([(string) $b['titel'], (string) $b['veranstaltung'], (string) $b['standort_name']])) ?: '–') ?>
                                <?php if ((string) $b['kunde_name'] !== ''): ?><div class="small muted">im Bericht: <?= View::e((string) $b['kunde_name']) ?></div><?php endif; ?></td>
                            <td data-label="Prüfung"><?= View::e($datum((string) $b['pruefdatum'])) ?></td>
                            <td data-label="Warum" class="small"><?= View::e((string) $b['grund']) ?></td>
                            <td class="right">
                                <form method="post" action="/berichte/<?= (int) $b['id'] ?>/kundenbereich">
                                    <?= Csrf::field() ?><input type="hidden" name="kunde_id" value="<?= $id ?>"><input type="hidden" name="zurueck" value="<?= $id ?>">
                                    <button class="btn btn-sm" type="submit">Zuordnen</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<p class="help">Der Kunde sieht ein Protokoll erst, wenn es abgeschlossen ist – mit dem dabei gespeicherten PDF, nie eine Vorschau. Zugeordnete
    Protokolle bleiben sichtbar, auch wenn seine Location-Freigabe abläuft; sie enden mit dem Kunden.</p>
