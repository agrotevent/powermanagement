<?php
/**
 * Reiter Abrufe (nur mit „kunden.manage“): welche Dokumente die Zugänge des
 * Kunden im Kundenbereich geladen haben – neueste zuerst. Ohne IP (die steht
 * 400 Tage im Audit-Log); die Abrufe gehen mit dem Dokument bzw. dem Kunden.
 * Nach einer Fehlzuordnung zeigt diese Liste, ob der Kunde das Dokument
 * wirklich hatte.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $abrufe Abrufe::fuerKunde()
 */
use App\Kunden\Abrufe;
use App\Support\View;
use App\Support\Zeit;

?>
<div class="card">
    <div class="card-head"><h2>Abrufe</h2><span class="hint">Downloads im Kundenbereich – Seitenaufrufe werden nicht festgehalten</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Zeit</th><th>Zugang</th><th>Dokument</th><th>Prüfsumme</th></tr></thead>
            <tbody>
            <?php if ($abrufe === []): ?>
                <tr><td colspan="4" class="muted">Noch nichts geladen.</td></tr>
            <?php endif; ?>
            <?php foreach ($abrufe as $a): ?>
                <?php
                $dokument = $a['art'] === 'bericht'
                    ? ($a['bericht_nummer'] !== null ? (string) $a['bericht_nummer'] . ((int) $a['bericht_fassung'] > 1 ? ' (Fassung ' . (int) $a['bericht_fassung'] . ')' : '') : 'Bericht #' . (int) $a['ziel_id'] . ' (gelöscht)')
                    : ($a['nachweis_bezeichnung'] !== null ? (string) $a['nachweis_bezeichnung'] : 'Nachweis #' . (int) $a['ziel_id'] . ' (gelöscht)');
                ?>
                <tr>
                    <td data-label="Zeit"><?= View::e(Zeit::anzeige((string) $a['am'])) ?></td>
                    <td data-label="Zugang"><?= View::e((string) $a['username'] !== '' ? (string) $a['username'] : '–') ?></td>
                    <td data-label="Dokument"><span class="small muted"><?= View::e(Abrufe::ARTEN[(string) $a['art']] ?? (string) $a['art']) ?></span>
                        <?php if ($a['art'] === 'bericht' && $a['bericht_nummer'] !== null): ?><a href="/berichte/<?= (int) $a['ziel_id'] ?>"><?= View::e($dokument) ?></a><?php else: ?><?= View::e($dokument) ?><?php endif; ?></td>
                    <td data-label="Prüfsumme" class="mono small"><?= View::e($a['sha256'] !== null ? substr((string) $a['sha256'], 0, 16) . ' …' : '–') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
