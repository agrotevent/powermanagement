<?php
use App\Support\View;
use App\Support\Zeit;

$title = 'Audit-Log';
$subtitle = 'Wer hat wann was geändert';
?>
<form method="get" action="/audit" class="toolbar-zeile inline-form">
    <input name="benutzer" value="<?= View::e($filter['benutzer']) ?>" placeholder="Benutzer">
    <input name="aktion" value="<?= View::e($filter['aktion']) ?>" placeholder="Aktion (z. B. steuerung)">
    <button class="btn btn-sm" type="submit">Filtern</button>
</form>
<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Ziel</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($eintraege as $e): ?>
                <tr>
                    <td data-label="Zeit" class="nowrap small"><?= View::e(Zeit::anzeige($e['created_at'], 'd.m.Y H:i:s')) ?></td>
                    <td data-label="Benutzer"><?= View::e($e['username']) ?></td>
                    <td data-label="Aktion" class="mono small"><?= View::e($e['action']) ?></td>
                    <td data-label="Ziel" class="small"><?= View::e(trim($e['target_type'] . ' ' . $e['target_id'])) ?></td>
                    <td data-label="Details" class="small mono"><?= View::e(mb_substr((string) $e['details'], 0, 200)) ?></td>
                    <td data-label="IP" class="small"><?= View::e($e['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
