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
 * @var ?array<string,mixed> $sensor laufender Tanksensor (nur für wer bearbeiten darf)
 * @var ?array<string,mixed> $fassung die jetzt geltende Kennlinie
 * @var list<string> $hinweise
 * @var list<array<string,mixed>> $sensorFrei Tanksensoren, die sich hier einbauen ließen
 */
use App\Services\AnlagenService;
use App\Services\PlanGeoService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;
use App\Tanks\TankService;

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
        <?php if (!$vorlage && $darfAendern): ?>
            <?php $einbau = (array) ($fassung['rechnung']['herkunft']['einbau'] ?? []); ?>
            <div class="card">
                <div class="card-head"><h2>Tanksensor</h2><span class="hint">misst den Füllstand</span></div>
                <div class="card-body">
                    <?php if ($sensor === null): ?>
                        <p class="small muted">Kein Tanksensor eingebaut.</p>
                    <?php else: ?>
                        <p><strong><?= View::can('datenquellen.view') && $sensor['inventar_id'] !== null
                            ? '<a href="/inventar/' . (int) $sensor['inventar_id'] . '">' . View::e((string) $sensor['kennung']) . '</a>'
                            : View::e((string) ($sensor['kennung'] ?? $sensor['bezeichnung'])) ?></strong>
                            <span class="small"><?= View::e($sensor['sensor_kurz']) ?></span></p>
                        <dl class="kv small">
                            <dt>Messkanal</dt><dd class="mono"><?= View::e($sensor['geraet'] . ' · ' . $sensor['kanal']) ?></dd>
                            <dt>Eingebaut</dt><dd><?= View::e(Zeit::anzeige((string) $sensor['gueltig_ab'], 'd.m.Y H:i')) ?></dd>
                            <?php if (isset($einbau['offset_mm'])): ?><dt>Über dem Boden</dt><dd><?= View::e(TankService::zahl((float) $einbau['offset_mm'])) ?> mm</dd><?php endif; ?>
                            <?php if (isset($einbau['d_leer_mm'])): ?><dt>Sensorfläche</dt><dd><?= View::e(TankService::zahl((float) $einbau['d_leer_mm'])) ?> mm über dem Boden</dd><?php endif; ?>
                            <dt>Kennlinie</dt><dd><?= View::e($fassung === null ? 'keine – nur Rohwert'
                                : (TankService::ANLAESSE[$fassung['anlass']] ?? $fassung['anlass']) . ' ' . Zeit::anzeige($fassung['ab'], 'd.m.Y H:i')
                                . ($fassung['geprueft'] ? ' · geprüft' : ($fassung['anlass'] === 'einsatz' ? ' · seit Einbau nicht gepeilt' : ' · ungeprüft – bitte peilen'))) ?></dd>
                        </dl>
                        <?php foreach ($hinweise as $h): ?><div class="notice warn mt small"><?= View::e($h) ?></div><?php endforeach; ?>
                        <?php if ($sensor['einsatz_id'] !== null): ?>
                            <form method="post" action="/einsatz/<?= (int) $sensor['einsatz_id'] ?>/beenden" class="mt" data-confirm="<?= View::e((string) $sensor['kennung']) ?> ausbauen? Der Sensor misst dann nicht mehr hier.">
                                <?= Csrf::field() ?><button class="btn btn-sm" type="submit">Ausbauen</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($sensorFrei !== []): ?>
                        <form method="post" action="/anlagen/<?= $id ?>/einsetzen" class="mt">
                            <?= Csrf::field() ?>
                            <div class="field"><label for="ts_inventar"><?= $sensor === null ? 'Tanksensor einbauen' : 'Anderen Tanksensor einbauen' ?></label>
                                <select id="ts_inventar" name="inventar_id" required>
                                    <option value="">– Sensor wählen –</option>
                                    <?php foreach ($sensorFrei as $i): ?>
                                        <option value="<?= (int) $i['id'] ?>"><?= View::e($i['kennung'] . ' – ' . $i['typ']
                                            . ($i['einsatz'] !== null ? ' (jetzt: ' . ($i['einsatz']['anlage_name'] ?? $i['einsatz']['objekt_name']) . ')' : '')) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="field-row">
                                <div class="field"><label for="ts_offset">Sondenspitze über dem Boden (mm)</label>
                                    <input id="ts_offset" name="einbau[offset_mm]" inputmode="decimal" placeholder="20"></div>
                                <div class="field"><label for="ts_dleer">Ultraschall: Sensorfläche über dem Boden (mm)</label>
                                    <input id="ts_dleer" name="einbau[d_leer_mm]" inputmode="decimal" placeholder="nur Ultraschall"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" type="submit">Einbauen</button>
                            <p class="help">Ab jetzt misst der Sensor hier; ein Sensor, der schon im Tank steckt, wird ersetzt. Danach peilen – die Einbautiefe ändert sich mit jedem Einbau.</p>
                        </form>
                    <?php elseif ($sensor === null): ?>
                        <p class="small muted">Kein freier Tanksensor im Inventar<?= View::can('inventar.manage') ? ' – <a href="/inventar/neu?art=tanksensor">Tanksensor anlegen</a>' : '' ?>.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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
