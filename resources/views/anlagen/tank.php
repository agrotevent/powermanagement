<?php
/**
 * Die Seite eines Tanks (oder Tank-Typs im Katalog).
 *
 * Ein Tank hat keine Anschlüsse, Sicherungen und Kabel – statt der Reiter
 * der elektrischen Anlagen stehen hier untereinander (auf breiten
 * Bildschirmen in zwei Spalten):
 *   Jetzt              Füllstand live (live.js über /anlagen/{id}/live)
 *   Verlauf            Liter, %, mm oder Rohwert (statistik.js über
 *                      /anlagen/{id}/tank/verlauf)
 *   Sensor & Kennlinie der eingebaute Tanksensor, die jetzige Fassung, die
 *                      Fassungen im Datenfenster, frühere Sensoren
 *   Daten              die Eigenschaften in den Gruppen des Formulars
 *
 * @var array<string,mixed> $objekt
 * @var bool $vorlage
 * @var string $kurzform
 * @var array<string,list<array{beschriftung:string,wert:string,einheit:string}>> $gruppen
 * @var ?array{lat:float,lon:float} $lage
 * @var bool $darfAendern
 * @var ?array<string,mixed> $sensor laufender Tanksensor
 * @var ?array<string,mixed> $fassung die jetzt geltende Kennlinie (im Datenfenster)
 * @var string $kennlinie die jetzige Kennlinie im Klartext
 * @var list<string> $hinweise
 * @var list<array<string,mixed>> $sensorFrei Tanksensoren, die sich hier einbauen ließen
 * @var array<string,mixed> $live Zusammenfassung aus LiveService
 * @var ?array $fenster Datenfenster
 * @var list<array<string,mixed>> $fassungen Fassungen im Datenfenster
 * @var list<array<string,mixed>> $frueher frühere Tanksensoren
 * @var int $von
 * @var int $bis
 * @var string $vorgabe Zeitraum (1h, 6h, heute, 24h, 7d, 30d, frei)
 * @var string $groesse liter | prozent | hoehe_mm | roh
 */
use App\Auth\Zugriff;
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
$einbau = (array) ($fassung['rechnung']['herkunft']['einbau'] ?? []);
$verlauf = !$vorlage && View::can('statistik.view') && ($sensor !== null || $frueher !== []);
$query = static function (array $zusatz) use ($vorgabe, $von, $bis, $groesse): string {
    $basis = ['zeitraum' => $vorgabe, 'groesse' => $groesse];
    if ($vorgabe === 'frei') {
        $basis['von'] = Zeit::fuerEingabe(Zeit::iso($von));
        $basis['bis'] = Zeit::fuerEingabe(Zeit::iso($bis));
    }
    return '?' . http_build_query(array_merge($basis, $zusatz));
};
$kennung = static fn (array $m): string => (string) ($m['kennung'] ?? '') !== '' ? (string) $m['kennung'] : (string) $m['bezeichnung'];
ob_start();
?>
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
<?php
$datenKarte = (string) ob_get_clean();
?>
<div class="toolbar-zeile"<?= !$vorlage ? ' data-live-url="/anlagen/' . $id . '/live"' : '' ?>>
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
        <?php if (!$vorlage): ?>
            <div class="live-raster einzeln">
                <?= View::partial('partials/tank-kachel', ['objekt' => $objekt, 'l' => $live, 'details' => $sensor !== null]) ?>
            </div>
            <?php if ($fenster !== null): ?>
                <p class="small muted">Sichtbarer Datenzeitraum: <?= View::e(Zugriff::beschreiben($fenster)) ?>.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($verlauf): ?>
            <div class="card">
                <div class="card-head"><h2>Verlauf</h2><span class="hint">je Zeitpunkt mit der Kennlinie, die damals galt</span></div>
                <div class="card-body">
                    <div class="zeitraum-knoepfe" role="group" aria-label="Zeitraum">
                        <?php foreach (['6h' => '6 Std.', 'heute' => 'Heute', '24h' => '24 Std.', '7d' => '7 Tage', '30d' => '30 Tage'] as $k => $n): ?>
                            <a class="btn btn-sm<?= $vorgabe === $k ? ' aktiv' : '' ?>" href="<?= View::e($query(['zeitraum' => $k, 'von' => null, 'bis' => null])) ?>"><?= $n ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="zeitraum-knoepfe mt" role="group" aria-label="Größe">
                        <?php foreach (['liter' => 'Liter', 'prozent' => '%', 'hoehe_mm' => 'Füllhöhe', 'roh' => 'Rohwert'] as $k => $n): ?>
                            <a class="btn btn-sm<?= $groesse === $k ? ' aktiv' : '' ?>" href="<?= View::e($query(['groesse' => $k])) ?>"><?= $n ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="diagramm mt" data-mp="" data-quelle="/anlagen/<?= $id ?>/tank/verlauf" data-feld="<?= View::e($groesse) ?>" data-messung="zustand"></div>
                    <details class="mt">
                        <summary class="small">Zeitraum frei wählen</summary>
                        <form method="get" action="/anlagen/<?= $id ?>" class="field-row mt">
                            <input type="hidden" name="zeitraum" value="frei">
                            <input type="hidden" name="groesse" value="<?= View::e($groesse) ?>">
                            <div class="field"><label for="tank_von">Von</label><input id="tank_von" type="datetime-local" name="von" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($von))) ?>"></div>
                            <div class="field"><label for="tank_bis">Bis</label><input id="tank_bis" type="datetime-local" name="bis" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($bis))) ?>"></div>
                            <div class="field"><button class="btn btn-sm" type="submit">Anzeigen</button></div>
                        </form>
                    </details>
                    <p class="help">Liter gibt es erst mit bekanntem Inhalt je mm (Peilung oder Betankung) – davor zeigen „Füllhöhe“ und „Rohwert“ den Verlauf.
                        Lücken sind Zeiten ohne Freigabe oder ohne Meldung.<?php if (View::can('statistik.export')): ?>
                        <a href="/anlagen/<?= $id ?>/tank/csv<?= View::e($query([])) ?>">CSV herunterladen</a><?php endif; ?></p>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($vorlage): ?><?= $datenKarte ?><?php endif; ?>
    </div>
    <div>
        <?php if (!$vorlage): ?>
            <div class="card">
                <div class="card-head"><h2>Sensor &amp; Kennlinie</h2><span class="hint">misst den Füllstand</span></div>
                <div class="card-body">
                    <?php if ($sensor === null): ?>
                        <p class="small muted">Kein Tanksensor eingebaut.</p>
                    <?php else: ?>
                        <p><strong><?= View::can('datenquellen.view') && $sensor['inventar_id'] !== null
                            ? '<a href="/inventar/' . (int) $sensor['inventar_id'] . '">' . View::e($kennung($sensor)) . '</a>'
                            : View::e($kennung($sensor)) ?></strong>
                            <span class="small"><?= View::e($sensor['sensor_kurz']) ?></span></p>
                        <dl class="kv small">
                            <dt>Messkanal</dt><dd class="mono"><?= View::e($sensor['geraet'] . ' · ' . $sensor['kanal']) ?></dd>
                            <dt>Eingebaut</dt><dd><?= View::e(Zeit::anzeige((string) $sensor['gueltig_ab'], 'd.m.Y H:i')) ?></dd>
                            <?php if (isset($einbau['offset_mm'])): ?><dt>Über dem Boden</dt><dd><?= View::e(TankService::zahl((float) $einbau['offset_mm'])) ?> mm</dd><?php endif; ?>
                            <?php if (isset($einbau['d_leer_mm'])): ?><dt>Sensorfläche</dt><dd><?= View::e(TankService::zahl((float) $einbau['d_leer_mm'])) ?> mm über dem Boden</dd><?php endif; ?>
                            <dt>Kennlinie</dt><dd><?= View::e($kennlinie) ?></dd>
                        </dl>
                        <?php foreach ($hinweise as $h): ?><div class="notice warn mt small"><?= View::e($h) ?></div><?php endforeach; ?>
                        <?php if ($darfAendern && $sensor['einsatz_id'] !== null): ?>
                            <form method="post" action="/einsatz/<?= (int) $sensor['einsatz_id'] ?>/beenden" class="mt" data-confirm="<?= View::e($kennung($sensor)) ?> ausbauen? Der Sensor misst dann nicht mehr hier.">
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
                    <?php elseif ($sensor === null && $darfAendern): ?>
                        <p class="small muted">Kein freier Tanksensor im Inventar<?= View::can('inventar.manage') ? ' – <a href="/inventar/neu?art=tanksensor">Tanksensor anlegen</a>' : '' ?>.</p>
                    <?php endif; ?>

                    <?php if ($fassungen !== []): ?>
                        <h3 class="abschnitt">Fassungen der Kennlinie</h3>
                        <ul class="liste-schlicht small fassungen">
                            <?php foreach ($fassungen as $f): ?>
                                <?php $ljm = $f['rechnung']['tank']['liter_je_mm'] ?? null; $jetzt = $fassung !== null && (int) $fassung['id'] === (int) $f['id']; ?>
                                <li><strong><?= View::e(Zeit::anzeige($f['ab'], 'd.m.Y H:i')) ?></strong> · <?= View::e(TankService::ANLAESSE[$f['anlass']] ?? $f['anlass']) ?>
                                    <?= $jetzt ? '<span class="badge primary">jetzt</span>' : '' ?>
                                    <?= $f['geprueft'] ? '<span class="badge ok">geprüft</span>' : '<span class="badge">ungeprüft</span>' ?>
                                    <div class="muted"><?= View::e(implode(' · ', array_filter([(string) $f['kennung'], $ljm !== null ? TankService::zahl((float) $ljm) . ' l/mm' : 'Inhalt je mm unbekannt',
                                        $f['von'] !== '' ? 'von ' . $f['von'] : '']))) ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="help">Eine Fassung wird nie geändert: Was sich am Sensor, am Shelly oder am Tank ändert, ergibt eine neue ab dann.</p>
                    <?php endif; ?>

                    <?php if ($frueher !== []): ?>
                        <h3 class="abschnitt">Frühere Sensoren</h3>
                        <ul class="liste-schlicht small">
                            <?php foreach ($frueher as $m): ?>
                                <li><strong><?= View::e($kennung($m)) ?></strong> <span class="mono muted"><?= View::e($m['geraet'] . ' · ' . $m['kanal']) ?></span>
                                    <div class="muted"><?= View::e(Zeit::anzeige((string) $m['gueltig_ab'], 'd.m.Y H:i') . ' – ' . Zeit::anzeige((string) $m['gueltig_bis'], 'd.m.Y H:i')) ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!$vorlage): ?><?= $datenKarte ?><?php endif; ?>
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
<?php if ($verlauf): ?>
<script type="application/json" id="statistik-daten"><?= json_encode(['zeitraum' => $vorgabe, 'von' => Zeit::fuerEingabe(Zeit::iso($von)), 'bis' => Zeit::fuerEingabe(Zeit::iso($bis))], JSON_HEX_TAG) ?></script>
<?php endif; ?>
