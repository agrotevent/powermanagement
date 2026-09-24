<?php
use App\Support\View;
use App\Support\Zeit;

$title = 'Schaltprotokoll';
$subtitle = 'Jeder Schaltbefehl – ausgeführt, gescheitert oder abgelehnt';
?>
<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Zeit</th><th>Benutzer</th><th>Gerät</th><th>Befehl</th><th>Anlage</th><th>Ergebnis</th></tr></thead>
            <tbody>
            <?php if ($eintraege === []): ?><tr><td colspan="6" class="muted">Noch nichts geschaltet.</td></tr><?php endif; ?>
            <?php foreach ($eintraege as $e): ?>
                <tr>
                    <td data-label="Zeit" class="nowrap small"><?= View::e(Zeit::anzeige($e['created_at'], 'd.m.Y H:i:s')) ?></td>
                    <td data-label="Benutzer"><?= View::e($e['username']) ?></td>
                    <td data-label="Gerät"><?= View::e((string) ($e['quelle_name'] ?? $e['geraet'])) ?><?= $e['kanal'] !== '' ? ' <span class="small muted mono">' . View::e($e['kanal']) . '</span>' : '' ?></td>
                    <td data-label="Befehl" class="mono"><?= View::e($e['befehl']) ?></td>
                    <td data-label="Anlage"><?= $e['objekt_id'] ? '<a href="/anlagen/' . (int) $e['objekt_id'] . '">' . View::e((string) $e['objekt_name']) . '</a>' : '–' ?></td>
                    <td data-label="Ergebnis"><span class="badge <?= $e['ergebnis'] === 'ok' ? 'ok' : 'bad' ?>"><?= $e['ergebnis'] === 'ok' ? 'ok' : 'Fehler' ?></span>
                        <div class="small muted"><?= View::e((string) $e['meldung']) ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
