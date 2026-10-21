<?php
use App\Services\AnlagenService;
use App\Support\View;

$title = 'Standard-Verteiler';
$subtitle = 'Katalog: Verteiler, Aggregate und Feststromanschlüsse mit fester Bestückung und Tanks, die auf der Karte platziert werden';
$darf = View::can('stecker.manage') || View::can('anlagen.scope.all');
?>
<div class="toolbar-zeile">
    <p class="muted small mb0">Ein Standard-Verteiler bringt Anschlüsse, Sicherungen und RCDs mit. Auf der Karte unter
        „+ Anlage“ gewählt, entsteht daraus eine eigene Anlage mit genau dieser Bestückung – die sich danach einzeln anpassen lässt.
        Typen mit „fest“ (etwa die Energiesäulen eines Geländes) ergeben Feststromanschlüsse. Ein Tank-Typ bringt Inhalt, Maße und Zulassung mit.</p>
    <div class="spacer"></div>
    <?php if (View::can('inventory.pull')): ?><a class="btn btn-sm btn-ghost" href="/verteiler-abgleich" title="Standard-Verteiler mit anderen Installationen abgleichen">Abgleich</a><?php endif; ?>
    <?php if (!empty($darfImport)): ?><a class="btn btn-sm" href="/anlagen/katalog/import" title="Standard-Verteiler und Aggregate aus einer Datei übernehmen">Importieren …</a><?php endif; ?>
    <?php if ($darf): ?>
        <a class="btn btn-sm" href="/anlagen/neu?art=anschluss&amp;vorlage=1&amp;fest=1">+ Feststromanschluss-Typ</a>
        <a class="btn btn-sm" href="/anlagen/neu?art=tank&amp;vorlage=1">+ Tank-Typ</a>
        <a class="btn btn-primary btn-sm" href="/anlagen/neu?art=verteilung&amp;vorlage=1">+ Neuer Standard-Verteiler</a>
    <?php endif; ?>
</div>
<?php if ($vorlagen === []): ?>
    <div class="card"><div class="card-body muted">Der Katalog ist leer.</div></div>
<?php endif; ?>
<?php /* Ein Abschnitt je Art, in der Reihenfolge von AnlagenService::ARTEN. */ ?>
<?php foreach (AnlagenService::ARTEN as $artSchluessel => $artInfo): ?>
<?php $dieser = array_values(array_filter($vorlagen, static fn (array $v): bool => $v['art'] === $artSchluessel)); ?>
<?php if ($dieser === []) { continue; } ?>
<h2 class="abschnitt"><?= View::e($artInfo['mehrzahl']) ?></h2>
<div class="katalog-raster">
    <?php foreach ($dieser as $v): ?>
        <?php $art = AnlagenService::ARTEN[$v['art']]; ?>
        <?php $import = $importiert[(int) $v['id']] ?? null; ?>
        <a class="card katalog-karte<?= $import !== null ? ' importiert' : '' ?>" href="/anlagen/<?= (int) $v['id'] ?>">
            <div class="card-body">
                <div class="katalog-kopf">
                    <span class="art-zeichen" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
                    <strong><?= View::e($v['name']) ?></strong>
                    <?php if ((int) $v['fest'] === 1): ?><span class="badge info" title="Anlagen daraus sind fest installiert">fest</span><?php endif; ?>
                    <?php if ($import !== null): ?><span class="badge primary"><?= $import === 'neu' ? 'importiert' : 'überschrieben' ?></span><?php endif; ?>
                </div>
                <div class="small muted"><?= View::e(trim($v['hersteller'] . ' ' . $v['modell'])) ?></div>
                <p class="small mt"><?= View::e($v['kurzform']) ?></p>
                <?php if (AnlagenService::elektrisch((string) $v['art'])): ?>
                    <p class="small muted mb0"><?= (int) $v['schutz'] ?> Schutzorgane<?= $v['nennstrom_a'] !== null ? ' · ' . (int) $v['nennstrom_a'] . ' A' : '' ?></p>
                <?php elseif (trim((string) ($v['eigenschaften']['zulassung'] ?? '')) !== ''): ?>
                    <p class="small muted mb0">Zulassung <?= View::e((string) $v['eigenschaften']['zulassung']) ?></p>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
