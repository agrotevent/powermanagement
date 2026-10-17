<?php
/**
 * Karte „Übergabeberichte“ im Aufbau und im Abgleich.
 *
 * @var array $aufbau
 * @var list<array> $berichte sichtbare Berichte dieses Aufbaus
 * @var bool $berichtNeu darf hier einen Bericht anlegen
 * @var ?string $aufbauGeaendert letzte Änderung einer Aufbau-Position (UTC)
 */
use App\Berichte\BerichtService;
use App\Support\View;
use App\Support\Zeit;

$klasse = ['entwurf' => 'warn', 'geprueft' => 'info', 'abgeschlossen' => 'ok', 'ersetzt' => '', 'verworfen' => ''];
$geaendert = Zeit::ts($aufbauGeaendert ?? null);
?>
<div class="card">
    <div class="card-head"><h2>Übergabeberichte (<?= count($berichte) ?>)</h2><div class="spacer"></div>
        <?php if ($berichtNeu): ?><a class="btn btn-sm btn-primary" href="/berichte/neu?aufbau=<?= (int) $aufbau['id'] ?>">Übergabebericht</a><?php endif; ?></div>
    <div class="card-body tight">
        <?php if ($berichte === []): ?>
            <p class="small muted mb0">Noch kein Bericht zu diesem Aufbau. Ist alles aufgebaut, prüft die Elektrofachkraft – der Bericht ist dann schon mit
                Anlagen, Abgängen, Schutzorganen und Kabeln vorbelegt.</p>
        <?php endif; ?>
        <ul class="liste-schlicht">
            <?php foreach ($berichte as $b): ?>
                <?php $unterschrieben = Zeit::ts($b['eingefroren_am'] ?? null); ?>
                <li><a href="/berichte/<?= (int) $b['id'] ?>"><strong><?= View::e($b['nummer'] ?? ('Entwurf #' . $b['id'])) ?></strong></a>
                    <span class="badge <?= $klasse[$b['status']] ?? '' ?>"><?= View::e(BerichtService::STATUS[$b['status']] ?? $b['status']) ?></span>
                    <span class="small muted"><?= View::e(trim($b['titel'] . ($b['kunde_name'] !== '' ? ' · ' . $b['kunde_name'] : ''))) ?></span>
                    <?php if ($unterschrieben !== null && $geaendert !== null && $geaendert > $unterschrieben && in_array($b['status'], ['geprueft', 'abgeschlossen'], true)): ?>
                        <div class="small text-warn">Aufbau seit der Prüfer-Unterschrift geändert – Bericht prüfen, gegebenenfalls Korrektur.</div>
                    <?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
