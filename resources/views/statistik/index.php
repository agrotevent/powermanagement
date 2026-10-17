<?php
use App\Messung\Messdaten;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Statistik';
$subtitle = Zeit::anzeige(Zeit::iso($von)) . ' – ' . Zeit::anzeige(Zeit::iso($bis));
$query = static function (array $zusatz) use ($gewaehlt, $vorgabe, $von, $bis): string {
    $basis = ['mp' => $gewaehlt, 'zeitraum' => $vorgabe];
    if ($vorgabe === 'frei') {
        $basis['von'] = Zeit::fuerEingabe(Zeit::iso($von));
        $basis['bis'] = Zeit::fuerEingabe(Zeit::iso($bis));
    }
    return '?' . http_build_query(array_merge($basis, $zusatz));
};
$jeObjekt = [];
foreach ($alle as $m) {
    $jeObjekt[(string) $m['objekt_name']][] = $m;
}
ksort($jeObjekt);
?>
<div class="grid grid-list statistik-raster">
    <aside>
        <div class="card">
            <div class="card-head"><h2>Zeitraum</h2></div>
            <div class="card-body">
                <div class="zeitraum-knoepfe">
                    <?php foreach (['1h' => '1 Std.', '6h' => '6 Std.', 'heute' => 'Heute', '24h' => '24 Std.', '7d' => '7 Tage', '30d' => '30 Tage'] as $k => $n): ?>
                        <a class="btn btn-sm<?= $vorgabe === $k ? ' aktiv' : '' ?>" href="<?= View::e($query(['zeitraum' => $k, 'von' => null, 'bis' => null])) ?>"><?= $n ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" action="/statistik" class="mt">
                    <?php foreach ($gewaehlt as $id): ?><input type="hidden" name="mp[]" value="<?= (int) $id ?>"><?php endforeach; ?>
                    <input type="hidden" name="zeitraum" value="frei">
                    <div class="field"><label for="von">Von</label><input id="von" type="datetime-local" name="von" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($von))) ?>"></div>
                    <div class="field"><label for="bis">Bis</label><input id="bis" type="datetime-local" name="bis" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($bis))) ?>"></div>
                    <button class="btn btn-sm" type="submit">Anzeigen</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Messgeräte</h2></div>
            <form method="get" action="/statistik" class="card-body">
                <input type="hidden" name="zeitraum" value="<?= View::e($vorgabe) ?>">
                <?php if ($vorgabe === 'frei'): ?>
                    <input type="hidden" name="von" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($von))) ?>">
                    <input type="hidden" name="bis" value="<?= View::e(Zeit::fuerEingabe(Zeit::iso($bis))) ?>">
                <?php endif; ?>
                <div class="messpunkt-wahl">
                    <?php if ($alle === []): ?><p class="muted small">Keine Messgeräte an freigegebenen Anlagen.</p><?php endif; ?>
                    <?php foreach ($jeObjekt as $objekt => $mps): ?>
                        <div class="small muted mt"><?= View::e($objekt) ?></div>
                        <?php foreach ($mps as $m): ?>
                            <label><input type="checkbox" name="mp[]" value="<?= (int) $m['id'] ?>"<?= in_array((int) $m['id'], $gewaehlt, true) ? ' checked' : '' ?>>
                                <span><?= View::e($m['bezeichnung'] !== '' ? $m['bezeichnung'] : $m['geraet']) ?><?= in_array((int) $m['id'], $auswertung, true) ? ' <span class="badge primary">Auswertung</span>' : '' ?></span></label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <div class="btn-row mt">
                    <button class="btn btn-primary btn-sm" type="submit">Anzeigen</button>
                </div>
            </form>
            <?php if ($auswertung !== []): ?>
                <form method="post" action="/auswertung/leeren" class="card-footer">
                    <?= Csrf::field() ?>
                    <span class="small muted"><?= count($auswertung) ?> Messgerät(e) aus Karte/Verteilerplan übernommen.</span>
                    <button class="btn btn-sm btn-ghost" type="submit">Auswertung leeren</button>
                </form>
            <?php endif; ?>
        </div>
    </aside>

    <div>
        <?php if ($auswahl === []): ?>
            <div class="card"><div class="card-body">
                <p>Links Messgeräte ankreuzen – oder auf der <a href="/karte">Karte</a> bzw. im Verteilerplan einer Anlage ein Messgerät
                    antippen und „zur Auswertung“ wählen. Es erscheint dann hier.</p>
            </div></div>
        <?php else: ?>
            <div class="toolbar-zeile">
                <?php if (View::can('statistik.export')): ?>
                    <a class="btn btn-sm" href="/statistik/csv<?= View::e($query([])) ?>">CSV herunterladen</a>
                <?php endif; ?>
                <span class="small muted">Alle Werte nur im freigegebenen Datenzeitraum; Lücken in den Kurven sind Zeiten ohne Freigabe oder ohne Messwerte.</span>
            </div>
            <?php $strom = array_values(array_filter($auswahl, static fn ($a) => $a['rolle'] !== 'fuellstand')); ?>
            <?php if (count($strom) > 1): ?>
                <div class="card diagramm-karte">
                    <div class="card-head"><h2>Vergleich: Wirkleistung</h2></div>
                    <div class="card-body"><div class="diagramm" data-vergleich="<?= View::e(implode(',', array_column($strom, 'id'))) ?>"
                         data-titel="<?= View::e(json_encode(array_column($strom, 'titel', 'id'), JSON_UNESCAPED_UNICODE)) ?>"></div></div>
                </div>
            <?php endif; ?>
            <?php foreach ($auswahl as $a): ?>
                <?php if ($a['rolle'] === 'fuellstand'): ?>
                    <div class="card">
                        <div class="card-head">
                            <h2><?= View::e($a['titel']) ?></h2>
                            <span class="spacer"></span>
                            <span class="hint mono"><?= View::e($a['geraet'] . ' · ' . $a['kanal']) ?></span>
                            <?php if (View::can('statistik.export')): ?>
                                <a class="btn btn-sm" href="/anlagen/<?= (int) $a['objekt']['id'] ?>/tank/csv?<?= View::e(http_build_query(['mp' => (int) $a['id'], 'zeitraum' => $vorgabe]
                                    + ($vorgabe === 'frei' ? ['von' => Zeit::fuerEingabe(Zeit::iso($von)), 'bis' => Zeit::fuerEingabe(Zeit::iso($bis))] : []))) ?>">CSV</a>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="/anlagen/<?= (int) $a['objekt']['id'] ?>">Tankseite</a>
                        </div>
                        <div class="card-body">
                            <p class="fenster-hinweis muted">Datenzeitraum: <?= View::e($a['fenster']) ?><?= $a['teile'] === [] ? ' – im gewählten Zeitraum ist nichts freigegeben.' : '' ?></p>
                            <?php if ($a['teile'] !== []): ?>
                                <h3 class="mt">Inhalt</h3>
                                <div class="diagramm" data-mp="" data-quelle="/anlagen/<?= (int) $a['objekt']['id'] ?>/tank/verlauf?mp=<?= (int) $a['id'] ?>" data-feld="liter" data-messung="zustand"></div>
                                <p class="help">Liter aus dem Rohwert des Sensors, je Zeitpunkt mit der Kennlinie, die damals galt. Ohne Kennlinie: auf der Tankseite den Rohwert ansehen.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php continue; ?>
                <?php endif; ?>
                <?php $k = $a['kennzahlen']; $maxE = max([1.0, ...array_values($a['energie'])]); ?>
                <div class="card">
                    <div class="card-head">
                        <h2><?= View::e($a['titel']) ?></h2>
                        <span class="spacer"></span>
                        <span class="hint mono"><?= View::e($a['geraet'] . ' · ' . $a['kanal']) ?></span>
                        <button type="button" class="btn btn-sm auswahl-knopf<?= in_array($a['id'], $auswertung, true) ? ' aktiv' : '' ?>" data-auswertung="<?= (int) $a['id'] ?>">
                            <span data-auswertung-text><?= in_array($a['id'], $auswertung, true) ? 'in der Auswertung' : 'zur Auswertung' ?></span></button>
                    </div>
                    <div class="card-body">
                        <p class="fenster-hinweis muted">Datenzeitraum: <?= View::e($a['fenster']) ?><?= $a['teile'] === [] ? ' – im gewählten Zeitraum ist nichts freigegeben.' : '' ?></p>
                        <div class="metric-grid">
                            <div class="metric"><span class="metric-label">Spitzenleistung</span><span class="metric-value"><?= View::e(Messdaten::format($k['p_max'], 'W')) ?></span></div>
                            <div class="metric"><span class="metric-label">Mittlere Leistung</span><span class="metric-value"><?= View::e(Messdaten::format($k['p_mittel'], 'W')) ?></span></div>
                            <div class="metric"><span class="metric-label">Energie</span><span class="metric-value"><?= View::e(Messdaten::format($k['energie_wh'], 'Wh')) ?></span></div>
                            <div class="metric"><span class="metric-label">Höchster Phasenstrom</span><span class="metric-value"><?= View::e(Messdaten::format($k['i_max'], 'A')) ?></span></div>
                            <div class="metric"><span class="metric-label">Spannung min / max</span><span class="metric-value"><?= View::e(Messdaten::format($k['u_min'], 'V', 0)) ?> / <?= View::e(Messdaten::format($k['u_max'], 'V', 0)) ?></span></div>
                        </div>
                        <?php if ($a['teile'] !== []): ?>
                            <h3 class="mt">Wirkleistung</h3>
                            <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="p" data-phasen="L1,L2,L3,sum"></div>
                            <h3 class="mt">Strom je Phase</h3>
                            <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="i" data-phasen="L1,L2,L3,sum"></div>
                            <h3 class="mt">Spannung</h3>
                            <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="u" data-phasen="L1,L2,L3,sum"></div>
                            <?php if ($a['rolle'] === 'batterie'): ?>
                                <h3 class="mt">Ladezustand</h3>
                                <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="soc" data-messung="zustand"></div>
                            <?php endif; ?>
                            <?php if ($a['objekt']['art'] === 'generator' && $a['kanal'] === 'motor'): ?>
                                <h3 class="mt">Tank und Kühlmittel</h3>
                                <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="tank_prozent" data-messung="zustand"></div>
                                <div class="diagramm" data-mp="<?= (int) $a['id'] ?>" data-feld="kuehlmittel_c" data-messung="zustand"></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($a['energie'] !== []): ?>
                            <h3 class="mt">Energie je Tag</h3>
                            <table class="energie-tabelle">
                                <?php foreach ($a['energie'] as $tag => $wh): ?>
                                    <tr><td class="nowrap"><?= View::e((new DateTimeImmutable($tag))->format('d.m.Y')) ?></td>
                                        <td style="width:70%"><div class="energie-balken" style="width: <?= max(1, round($wh / $maxE * 100)) ?>%"></div></td>
                                        <td class="num nowrap"><?= View::e(Messdaten::format($wh, 'Wh')) ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<script type="application/json" id="statistik-daten"><?= json_encode(['zeitraum' => $vorgabe, 'von' => Zeit::fuerEingabe(Zeit::iso($von)), 'bis' => Zeit::fuerEingabe(Zeit::iso($bis))], JSON_HEX_TAG) ?></script>
