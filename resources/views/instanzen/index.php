<?php
/**
 * Andere Instanzen: eingetragene Verbindungen und „Instanz eintragen“.
 *
 * @var list<array<string,mixed>> $partner PartnerService::alle()
 * @var array{name:string,adresse:string} $eingabe nach gescheitertem Eintragen (nie das Token)
 * @var string|null $hindernis app.key fehlt
 */
use App\Services\PartnerService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Andere Instanzen';
$subtitle = 'Standorte mit Grundrissen, Maßstab, Georeferenz und Feststrom von anderen Instanzen übernehmen – statt über ein gemeinsames Git';
?>
<?php if ($hindernis !== null): ?>
    <div class="notice warn"><?= View::e($hindernis) ?></div>
<?php endif; ?>
<div class="grid grid-sidebar">
    <div class="card">
        <div class="card-head"><h2>Verbindungen</h2></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Instanz</th><th>Adresse</th><th>Gegenseite</th><th>Befugnisse dort</th><th class="num">Standorte hier</th><th>Geprüft</th></tr></thead>
                <tbody>
                <?php if ($partner === []): ?><tr><td colspan="6" class="muted">Noch keine andere Instanz eingetragen.</td></tr><?php endif; ?>
                <?php foreach ($partner as $p): ?>
                    <tr>
                        <td data-label="Instanz"><a href="/instanzen/<?= (int) $p['id'] ?>"><strong><?= View::e((string) $p['name']) ?></strong></a>
                            <?php if ((string) $p['remote_name'] !== '' && $p['remote_name'] !== $p['name']): ?>
                                <div class="small muted">nennt sich „<?= View::e((string) $p['remote_name']) ?>“</div>
                            <?php endif; ?></td>
                        <td data-label="Adresse"><span class="mono small api-adresse"><?= View::e((string) $p['base_url']) ?></span>
                            <?php if ($p['unverschluesselt']): ?><span class="badge warn" title="http über ein Netz – das Token geht unverschlüsselt hinüber">unverschlüsselt</span><?php endif; ?></td>
                        <td data-label="Gegenseite"><?= View::e(PartnerService::produktName((string) $p['remote_produkt'])) ?></td>
                        <td data-label="Befugnisse dort" class="small"><?= $p['befugnisse'] === [] ? '<span class="muted">–</span>'
                            : implode(', ', array_map(static fn (string $b): string => '<code>' . View::e($b) . '</code>', $p['befugnisse'])) ?></td>
                        <td data-label="Standorte hier" class="num"><?= (int) $p['locations'] ?></td>
                        <td data-label="Geprüft" class="small">
                            <?= $p['verified_at'] !== null && (string) $p['verified_at'] !== '' ? View::e(Zeit::anzeige((string) $p['verified_at'])) : '<span class="muted">nie</span>' ?>
                            <?php if ((string) $p['letzter_fehler'] !== ''): ?><div><span class="badge bad">zuletzt fehlgeschlagen</span></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <form method="post" action="/instanzen/anlegen" class="card" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="card-head"><h3>Instanz eintragen</h3></div>
        <div class="card-body">
            <div class="field">
                <label for="partner-name">Name (optional)</label>
                <input type="text" id="partner-name" name="name" maxlength="190" placeholder="Messe Nord" value="<?= View::e($eingabe['name']) ?>">
                <span class="help">Leer: so, wie sich die Instanz selbst nennt.</span>
            </div>
            <div class="field">
                <label for="partner-adresse">Adresse</label>
                <input type="url" id="partner-adresse" name="adresse" required maxlength="255" placeholder="https://strom.messe-nord.de"
                       value="<?= View::e($eingabe['adresse']) ?>">
                <span class="help">Wie die andere Instanz sie unter <em>System → Schnittstelle (API)</em> nennt (ein <code>/api/v1</code>
                    am Ende schadet nicht). Verbunden wird vom Server dieser Instanz aus, nicht vom Browser – im Zweifel die IP eintragen,
                    etwa <code>http://10.0.0.20</code>. Die Adresse lässt sich danach nicht mehr ändern.</span>
            </div>
            <div class="field">
                <label for="partner-token">Token</label>
                <input type="password" id="partner-token" name="token" required autocomplete="off" spellcheck="false" placeholder="mtma_…">
                <span class="help">Gibt die andere Instanz unter <em>System → Schnittstelle (API)</em> aus. Liegt hier verschlüsselt und geht
                    nur an diese Adresse.</span>
            </div>
            <button class="btn btn-primary btn-block" type="submit"<?= $hindernis !== null ? ' disabled' : '' ?>>Verbinden</button>
        </div>
    </form>
</div>
