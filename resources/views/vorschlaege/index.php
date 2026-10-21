<?php
/**
 * Änderungsvorschläge anderer Instanzen – offene zuerst. Direkt
 * Geschriebenes („plaene.schreiben“) steht als angenommen dabei.
 *
 * Alles von der Gegenseite (Absender, Notiz, Name des Zugangs) geht durch View::e().
 *
 * @var list<array<string,mixed>> $vorschlaege VorschlagService::alle()
 */
use App\Services\VorschlagService;
use App\Support\View;
use App\Support\Zeit;

$title = 'Änderungsvorschläge';
$subtitle = 'Was andere Instanzen an Standorten von hier geändert zurückschicken';
?>
<div class="card">
    <div class="card-body">
        <p class="mb0">Eine andere Instanz, die hier einen Standort übernommen hat, schickt Änderungen an Grundrissen, Maßstab und Georeferenz – mit
            „Feststrom“ auch an Feststromanschlüssen und festen Kabeln – über die Schnittstelle zurück. Übernommen wird ein Vorschlag erst, wenn ihn
            hier jemand annimmt, und nur für die gewählten Etagen. Namen, Schaltsperren und Messpunkte hier bleiben, gelöscht wird nichts.
            Ein Zugang mit „schreiben“ übernimmt direkt, aber nur auf einem Stand, den er kennt – das steht hier als <em>angenommen</em> mit dem
            Zugang als Entscheider.</p>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Eingang</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Eingang</th><th>Location</th><th>Von</th><th>Notiz</th><th>Zustand</th></tr></thead>
            <tbody>
            <?php if ($vorschlaege === []): ?><tr><td colspan="5" class="muted">Noch keine Vorschläge eingegangen.</td></tr><?php endif; ?>
            <?php foreach ($vorschlaege as $v): ?>
                <?php
                $status = (string) $v['status'];
                [$zText, $zKlasse] = VorschlagService::ZUSTAENDE[$status] ?? [$status, ''];
                $direkt = ($v['entscheidung']['direkt'] ?? false) === true;
                $notiz = (string) ($v['notiz'] ?? '');
                ?>
                <tr<?= $status === VorschlagService::OFFEN ? '' : ' class="muted"' ?>>
                    <td data-label="Eingang"><a href="/vorschlaege/<?= (int) $v['id'] ?>">Nr. <?= (int) $v['id'] ?></a>
                        <div class="small"><?= View::e(Zeit::anzeige((string) $v['created_at'])) ?></div></td>
                    <td data-label="Location"><?= $v['site_name'] !== null ? '<strong>' . View::e((string) $v['site_name']) . '</strong>' : '<span class="muted">Standort gelöscht</span>' ?>
                        <div class="small muted mono"><?= View::e((string) $v['site_key']) ?></div></td>
                    <td data-label="Von"><?= View::e((string) $v['absender']) ?>
                        <?php if ((string) $v['token_name'] !== '' && $v['token_name'] !== $v['absender']): ?><div class="small muted">Zugang „<?= View::e((string) $v['token_name']) ?>“</div><?php endif; ?></td>
                    <td data-label="Notiz" class="small"><?= $notiz !== '' ? View::e(mb_strlen($notiz) > 120 ? mb_substr($notiz, 0, 119) . '…' : $notiz) : '<span class="muted">–</span>' ?></td>
                    <td data-label="Zustand"><span class="badge <?= View::e($zKlasse) ?>"><?= View::e($direkt ? 'direkt übernommen' : $zText) ?></span>
                        <?php if ($status !== VorschlagService::OFFEN && (string) $v['entschieden_at'] !== ''): ?>
                            <div class="small muted"><?= View::e(Zeit::anzeige((string) $v['entschieden_at'])) ?> · <?= View::e((string) $v['entschieden_von']) ?></div>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
