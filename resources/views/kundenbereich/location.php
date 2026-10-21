<?php
/**
 * Eine Location, wie der Kunde sie sieht: Anschrift, Freigabe(n) mit
 * Veranstaltung, „sichtbar bis“ und Messzeitraum, Pläne der Etagen (Bild im
 * Browser, PDF als Datei), die festen Stromanschlüsse und Ihre Protokolle
 * an dieser Location. Nie Notizen, mobile Anlagen, Kabel oder Messgeräte
 * (KundenSicht::location()).
 *
 * @var array<string,mixed> $kunde
 * @var array{site:array{id:int,name:string,address:string},freigaben:list<array<string,mixed>>,etagen:list<array{id:int,name:string,plan:string,seite:int}>,feststrom:list<array<string,string>>} $location
 */
use App\Kunden\Kundenbereich;
use App\Support\View;
use App\Support\Zeit;

$site = $location['site'];
$title = $site['name'];
$basis = Kundenbereich::PFAD . '/locations/' . (int) $site['id'];
?>
<a class="kb-zurueck" href="<?= View::e(Kundenbereich::PFAD . '/locations') ?>">← Alle Locations</a>
<h1 class="kb-titel"><?= View::e($site['name']) ?></h1>
<?php if ($site['address'] !== ''): ?><p class="kb-einleitung"><?= View::e($site['address']) ?></p><?php endif; ?>

<div class="kb-karten">
    <section class="card" aria-labelledby="kb-freigabe-titel">
        <div class="card-head"><h2 id="kb-freigabe-titel">Ihre Veranstaltung</h2></div>
        <div class="card-body">
            <ul class="kb-freigaben">
                <?php foreach ($location['freigaben'] as $f): ?>
                    <li>
                        <strong class="kb-veranstaltung"><?= View::e($f['veranstaltung'] !== '' ? (string) $f['veranstaltung'] : 'Freigabe für Sie') ?></strong>
                        <span class="kb-meta">sichtbar bis <?= View::e(Zeit::anzeige($f['bis'])) ?></span>
                        <span class="kb-meta"><?= View::e((string) $f['daten']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="help">Nach diesem Zeitpunkt sehen Sie die Location hier nicht mehr.</p>
        </div>
    </section>

    <section class="card" aria-labelledby="kb-plaene-titel">
        <div class="card-head"><h2 id="kb-plaene-titel">Pläne</h2></div>
        <div class="card-body">
            <?php if ($location['etagen'] === []): ?>
                <p class="kb-leer">Für diese Location liegt kein Plan vor.</p>
            <?php else: ?>
                <ul class="kb-plaene">
                    <?php foreach ($location['etagen'] as $e): ?>
                        <li>
                            <span class="kb-plan-name"><strong><?= View::e($e['name']) ?></strong>
                                <span class="kb-meta"><?= $e['plan'] === 'pdf' ? 'PDF' . ($e['seite'] > 1 ? ', Seite ' . (int) $e['seite'] : '') : 'Bild' ?></span></span>
                            <?php if ($e['plan'] === 'pdf'): ?>
                                <a class="btn" href="<?= View::e($basis . '/plan/' . (int) $e['id']) ?>" download>Plan herunterladen</a>
                            <?php else: ?>
                                <a class="btn" href="<?= View::e($basis . '/plan/' . (int) $e['id']) ?>">Plan ansehen</a>
                                <?php /* Vorschau als Bild: in <img> läuft auch in einem SVG nichts; antippen öffnet den Plan groß. */ ?>
                                <a class="kb-plan-vorschau" href="<?= View::e($basis . '/plan/' . (int) $e['id']) ?>" tabindex="-1" aria-hidden="true">
                                    <img src="<?= View::e($basis . '/plan/' . (int) $e['id']) ?>" alt="" loading="lazy"></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="card kb-breit" aria-labelledby="kb-fest-titel">
        <div class="card-head"><h2 id="kb-fest-titel">Feste Stromanschlüsse</h2></div>
        <?php if ($location['feststrom'] === []): ?>
            <div class="card-body"><p class="kb-leer">Für diese Location sind keine festen Stromanschlüsse erfasst.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="tabelle-karten">
                    <thead><tr><th>Name</th><th>Art</th><th>Etage</th><th>Anschlüsse</th><th>Vorsicherung</th></tr></thead>
                    <tbody>
                        <?php foreach ($location['feststrom'] as $o): ?>
                            <tr>
                                <td data-label="Name"><strong><?= View::e($o['name']) ?></strong></td>
                                <td data-label="Art"><?= View::e($o['art']) ?></td>
                                <td data-label="Etage"><?= View::e($o['etage'] !== '' ? $o['etage'] : '–') ?></td>
                                <td data-label="Anschlüsse"><?= View::e($o['anschluss']) ?></td>
                                <td data-label="Vorsicherung"><?= View::e($o['vorsicherung'] !== '' ? $o['vorsicherung'] : '–') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="card-footer small muted">Nur was fest zur Location gehört. Mobile Verteiler und Kabel gehören zur Planung Ihrer
            Veranstaltung und stehen hier nicht.</div>
    </section>

    <section class="card kb-breit" aria-labelledby="kb-ort-protokolle-titel">
        <div class="card-head"><h2 id="kb-ort-protokolle-titel">Ihre Protokolle an dieser Location</h2></div>
        <div class="card-body">
            <?php if (($location['berichte'] ?? []) === []): ?>
                <p class="kb-leer">Für diese Location liegt noch kein abgeschlossenes Protokoll für Sie vor.</p>
            <?php else: ?>
                <?= View::partial('kundenbereich/_protokolle', ['berichte' => $location['berichte'], 'mitLocation' => false]) ?>
            <?php endif; ?>
        </div>
    </section>
</div>
