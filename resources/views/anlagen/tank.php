<?php
/**
 * Die Seite eines Tanks (oder Tank-Typs im Katalog).
 *
 * Ein Tank hat keine Anschlüsse, Sicherungen und Kabel – statt der Reiter
 * der elektrischen Anlagen stehen hier seine Daten in den Gruppen des
 * Formulars (AnlagenService::FELDGRUPPEN).
 *
 * @var array<string,mixed> $objekt
 * @var bool $vorlage
 * @var string $kurzform
 * @var array<string,list<array{beschriftung:string,wert:string,einheit:string}>> $gruppen
 * @var ?array{lat:float,lon:float} $lage
 * @var bool $darfAendern
 */
use App\Services\AnlagenService;
use App\Services\PlanGeoService;
use App\Support\Csrf;
use App\Support\View;

$id = (int) $objekt['id'];
$art = AnlagenService::ARTEN[$objekt['art']];
$title = $objekt['name'];
$subtitle = ($vorlage ? $art['name'] . '-Typ im Katalog' . ((int) $objekt['fest'] === 1 ? ' (fest)' : '') : $objekt['art_anzeige'])
    . ($objekt['site_name'] ? ' · ' . $objekt['site_name'] : '') . ($objekt['floor_name'] ? ' / ' . $objekt['floor_name'] : '');
?>
<div class="toolbar-zeile">
    <span class="art-zeichen gross" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
    <?php if (!$vorlage): ?><span class="badge status-<?= View::e($objekt['status']) ?>"><?= View::e(AnlagenService::STATUS[$objekt['status']] ?? '') ?></span><?php endif; ?>
    <span class="small muted"><?= View::e($kurzform) ?></span>
    <div class="spacer"></div>
    <div class="btn-row">
        <?php if (!$vorlage && $objekt['floor_id'] !== null): ?><a class="btn btn-sm" href="/karte?etage=<?= (int) $objekt['floor_id'] ?>&amp;anlage=<?= $id ?>">Auf der Karte</a><?php endif; ?>
        <?php if ($lage !== null): ?><a class="btn btn-sm" href="<?= View::e(PlanGeoService::kartenLink($lage['lat'], $lage['lon'])) ?>" target="_blank" rel="noopener"
            title="<?= View::e(number_format($lage['lat'], 6, '.', '') . ', ' . number_format($lage['lon'], 6, '.', '')) ?>">Lage (OSM)</a><?php endif; ?>
        <a class="btn btn-sm" href="/anlagen/<?= $id ?>/druck" target="_blank" rel="noopener">Datenblatt drucken</a>
        <?php if ($darfAendern): ?>
            <a class="btn btn-sm btn-primary" href="/anlagen/<?= $id ?>/bearbeiten">Bearbeiten</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-sidebar">
    <div>
        <div class="card">
            <div class="card-head"><h2>Daten</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Kennung</dt><dd><?= View::e($objekt['kennung'] ?: '–') ?></dd>
                    <dt>Hersteller / Modell</dt><dd><?= View::e(trim($objekt['hersteller'] . ' ' . $objekt['modell']) ?: '–') ?></dd>
                    <dt>Seriennummer</dt><dd><?= View::e($objekt['seriennummer'] ?: '–') ?></dd>
                </dl>
                <?php foreach ($gruppen as $gruppe => $zeilen): ?>
                    <?php if ($gruppe !== ''): ?><h3 class="abschnitt"><?= View::e($gruppe) ?></h3><?php endif; ?>
                    <dl class="kv">
                        <?php foreach ($zeilen as $z): ?>
                            <dt><?= View::e($z['beschriftung']) ?></dt><dd><?= View::e($z['wert']) ?><?= $z['einheit'] !== '' ? ' ' . View::e($z['einheit']) : '' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endforeach; ?>
                <?php if ($gruppen === []): ?><p class="small muted mt">Noch keine Angaben zu Inhalt, Maßen und Zulassung.</p><?php endif; ?>
                <?php if ((string) $objekt['notizen'] !== ''): ?><p class="mt small"><?= nl2br(View::e((string) $objekt['notizen'])) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <div>
        <?php if ($darfAendern): ?>
            <div class="card">
                <div class="card-head"><h2>Aktionen</h2></div>
                <div class="card-body btn-row">
                    <form method="post" action="/anlagen/<?= $id ?>/kopieren">
                        <?= Csrf::field() ?>
                        <button class="btn btn-sm" type="submit">Kopie anlegen</button>
                    </form>
                    <form method="post" action="/anlagen/<?= $id ?>/loeschen" data-confirm="<?= View::e($objekt['name']) ?> wirklich löschen?">
                        <?= Csrf::field() ?>
                        <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
