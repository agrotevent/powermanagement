<?php
/**
 * Die Seite eines Tanks (oder Tank-Typs im Katalog).
 *
 * Ein Tank hat keine Anschlüsse, Sicherungen und Kabel – statt der Reiter
 * der elektrischen Anlagen stehen hier untereinander (auf breiten
 * Bildschirmen in zwei Spalten):
 *   Jetzt              Füllstand live (live.js über /anlagen/{id}/live)
 *   Vor Ort            Peilung und Betankung eintragen (nur mit „bearbeiten“)
 *   Verlauf            Liter, %, mm oder Rohwert (statistik.js über
 *                      /anlagen/{id}/tank/verlauf)
 *   Verbrauch je Tag   Tage im Zeitraum, erkannte Betankungen ohne Eintrag
 *                      (tank.js über /anlagen/{id}/tank/tage)
 *   Protokoll          Peilungen und Betankungen: kalibrieren, löschen, CSV;
 *                      das Angebot „Steigung aus beiden Peilungen“
 *   Sensor & Kennlinie der eingebaute Tanksensor, die jetzige Fassung, die
 *                      Fassungen im Datenfenster, „Erweitert“ (Anker und
 *                      Steigung von Hand), frühere Sensoren
 *   Versorgung         Kraftstoffschläuche, Generatoren mit Kennlinie, Vorrat,
 *                      Verbrauch und Restlaufzeit, Gegenprüfung Pegel ↔ Last
 *   Daten              die Eigenschaften in den Gruppen des Formulars
 *
 * Felder je Peilart und das Einbaumaß je Messprinzip blendet tank.js um
 * (data-Attribute, kein Inline-JS); ohne Skript geht alles auch – bis auf
 * die Tage, die tank.js lädt.
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
 * @var list<array<string,mixed>> $ereignisse Peilungen und Betankungen im Datenfenster (TankService::ereignisse)
 * @var ?array<string,mixed> $steigung Angebot aus zwei Peilungen (TankService::steigungAngebot)
 * @var bool $rueckwirkend „rückwirkend für diesen Einbau“ erlaubt (unbegrenztes Datenfenster)
 * @var ?float $nenn_l Nennvolumen
 * @var ?array<string,mixed> $versorgung Schläuche, Generatoren, Gegenprüfung (TankService::versorgungSeite)
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
$sensorArt = (string) ($fassung['rechnung']['sensor']['art'] ?? '');
$rohEinheit = (string) (\App\Tanks\TankRechnung::ROHFELDER[(string) ($fassung['rechnung']['feld'] ?? '')][1] ?? '');
$jetztMax = Zeit::fuerEingabe(Zeit::iso(time()));
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
        <?php if (!$vorlage && $darfAendern): ?>
            <div class="card">
                <div class="card-head"><h2>Vor Ort</h2><span class="hint">peilen und betanken</span></div>
                <div class="card-body grid grid-2 tank-vorort">
                    <form method="post" action="/anlagen/<?= $id ?>/tank/peilung" class="tank-peilung">
                        <?= Csrf::field() ?>
                        <h3 class="abschnitt">Peilung</h3>
                        <div class="field"><label for="peil_art">Abgelesen</label>
                            <select id="peil_art" name="art" data-peilart>
                                <option value="prozent" data-einheit="%"<?= $nenn_l === null ? ' disabled' : ' selected' ?>>Anzeige am Tank (%)<?= $nenn_l === null ? ' – Nennvolumen fehlt' : '' ?></option>
                                <option value="liter" data-einheit="l"<?= $nenn_l === null ? ' selected' : '' ?>>Inhalt (l)</option>
                                <option value="hoehe_mm" data-einheit="mm">Peilstab (mm)</option>
                            </select></div>
                        <div class="field-row">
                            <div class="field"><label for="peil_wert">Wert <span data-peileinheit><?= $nenn_l === null ? '(l)' : '(%)' ?></span></label>
                                <input id="peil_wert" name="wert" inputmode="decimal" autocomplete="off" required></div>
                            <div class="field"><label for="peil_notiz">Notiz</label><input id="peil_notiz" name="notizen" maxlength="500"></div>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Peilung eintragen</button>
                        <p class="help">Zeit: jetzt. Den Rohwert des Sensors nimmt der Server. Der Peilstab setzt den Nullpunkt der Kennlinie;
                            Anzeige oder Inhalt bestimmen beim ersten Mal den Inhalt je mm. Nicht peilen, während getankt wird.</p>
                    </form>
                    <form method="post" action="/anlagen/<?= $id ?>/tank/betankung" class="tank-betankung">
                        <?= Csrf::field() ?>
                        <h3 class="abschnitt">Betankung</h3>
                        <div class="field-row">
                            <div class="field"><label for="tank_liter">Geliefert (l)</label>
                                <input id="tank_liter" name="liter" inputmode="decimal" autocomplete="off" required></div>
                            <div class="field"><label for="tank_ende">Ende des Tankens</label>
                                <input id="tank_ende" name="zeit" type="datetime-local" max="<?= View::e($jetztMax) ?>"></div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label for="tank_ls">Lieferschein</label><input id="tank_ls" name="lieferschein" maxlength="64" autocomplete="off"></div>
                            <div class="field"><label for="tank_bnotiz">Notiz</label><input id="tank_bnotiz" name="notizen" maxlength="500"></div>
                        </div>
                        <input type="hidden" name="kalibrieren" value="0">
                        <div class="check"><input type="checkbox" id="tank_kal" name="kalibrieren" value="1" checked>
                            <label for="tank_kal">Gleich kalibrieren – aus dem Sprung in den Messwerten (ab 30 min nach dem Tanken)</label></div>
                        <button class="btn btn-primary btn-sm" type="submit">Betankung eintragen</button>
                        <p class="help">Ende leer = jetzt; höchstens 7 Tage zurück. Später kalibrieren geht im Protokoll.</p>
                    </form>
                </div>
            </div>
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
            <div class="card" id="tage">
                <div class="card-head"><h2>Verbrauch je Tag</h2><span class="hint">ganze Tage im gewählten Zeitraum</span></div>
                <div class="card-body">
                    <?= View::partial('partials/tank-tage', ['tankId' => $id, 'quelle' => '/anlagen/' . $id . '/tank/tage' . $query([]), 'eintragen' => $darfAendern,
                        'csv' => View::can('statistik.export') ? '/anlagen/' . $id . '/tank/csv' . $query(['inhalt' => 'tage']) : null]) ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!$vorlage && ($ereignisse !== [] || $steigung !== null)): ?>
            <div class="card" id="protokoll">
                <div class="card-head"><h2>Protokoll</h2><span class="hint">Peilungen und Betankungen</span></div>
                <?php if ($steigung !== null): ?>
                    <div class="card-body">
                        <form method="post" action="/anlagen/<?= $id ?>/tank/steigung" class="notice<?= $steigung['warnung'] !== null ? ' warn' : '' ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="ereignis_id" value="<?= (int) $steigung['ereignis'] ?>">
                            <p><strong>Zwei Peilungen, <?= View::e(TankService::zahl(round(abs($steigung['neu']['hoehe_mm'] - $steigung['alt']['hoehe_mm'])))) ?> mm auseinander:</strong>
                                Steigung <?= View::e(TankService::zahl(round($steigung['mm_je_roh'], 2))) ?> mm je <?= View::e($steigung['einheit'] !== '' ? $steigung['einheit'] : 'Rohwert') ?>
                                statt <?= View::e(TankService::zahl(round($steigung['bisher'], 2))) ?><?= $steigung['abweichung'] !== null
                                    ? ' (' . View::e(($steigung['abweichung'] > 0 ? '+' : '') . TankService::zahl($steigung['abweichung'])) . ' % gegenüber den Sensordaten)' : '' ?>.</p>
                            <?php if ($steigung['warnung'] !== null): ?><p class="small"><?= View::e($steigung['warnung']) ?></p><?php endif; ?>
                            <div class="check"><input type="checkbox" id="steigung_ok" name="uebernehmen" value="1" required>
                                <label for="steigung_ok">Steigung aus beiden Peilungen setzen (<?= View::e(Zeit::anzeige($steigung['alt']['zeit'], 'd.m. H:i')) ?>
                                    und <?= View::e(Zeit::anzeige($steigung['neu']['zeit'], 'd.m. H:i')) ?>)</label></div>
                            <button class="btn btn-sm" type="submit">Übernehmen</button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if ($ereignisse !== []): ?>
                    <div class="table-wrap">
                        <table class="tabelle-karten tank-protokoll">
                            <thead><tr><th>Zeit</th><th>Eintrag</th><th>Rohwert</th><th>Von</th><?= $darfAendern ? '<th></th>' : '' ?></tr></thead>
                            <tbody>
                            <?php foreach ($ereignisse as $e): ?>
                                <?php $einheit = $e['roh_einheit'] !== '' ? ' ' . $e['roh_einheit'] : '';
                                $rohText = $e['roh'] !== null ? TankService::zahl(round($e['roh'], 3)) . $einheit
                                    : ($e['roh_vor'] !== null && $e['roh_nach'] !== null ? TankService::zahl(round($e['roh_vor'], 3)) . ' → ' . TankService::zahl(round($e['roh_nach'], 3)) . $einheit : '–'); ?>
                                <tr>
                                    <td data-label="Zeit"><?= View::e(Zeit::anzeige($e['zeit'], 'd.m.Y H:i')) ?></td>
                                    <td data-label="Eintrag"><?= View::e($e['text']) ?>
                                        <?= $e['fassung'] ? '<span class="badge ok">kalibriert</span>' : '' ?>
                                        <?php if ($e['notizen'] !== ''): ?><div class="small muted"><?= View::e($e['notizen']) ?></div><?php endif; ?></td>
                                    <td data-label="Rohwert" class="small"><?= View::e($rohText) ?></td>
                                    <td data-label="Von" class="small"><?= View::e($e['von']) ?></td>
                                    <?php if ($darfAendern): ?>
                                        <td><div class="btn-row">
                                            <?php if ($e['auswerten_ab'] !== null): ?>
                                                <form method="post" action="/anlagen/<?= $id ?>/tank/betankung/auswerten" class="btn-row">
                                                    <?= Csrf::field() ?><input type="hidden" name="ereignis_id" value="<?= (int) $e['id'] ?>">
                                                    <?php if ($e['auswerten_jetzt']): ?>
                                                        <button class="btn btn-sm" type="submit">Kalibrieren aus dieser Betankung</button>
                                                        <span class="check"><input type="checkbox" id="trotzdem_<?= (int) $e['id'] ?>" name="trotzdem" value="1">
                                                            <label for="trotzdem_<?= (int) $e['id'] ?>">auch über 25 % Abweichung</label></span>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm" type="submit" disabled>Kalibrieren ab <?= View::e(Zeit::anzeige($e['auswerten_ab'], 'H:i')) ?></button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($e['loeschbar']): ?>
                                                <form method="post" action="/anlagen/<?= $id ?>/tank/ereignis/loeschen"
                                                      data-confirm="<?= View::e($e['text']) ?> löschen?<?= $e['fassung'] ? ' Die Kennlinie rechnet dann wieder wie davor.' : '' ?>">
                                                    <?= Csrf::field() ?><input type="hidden" name="ereignis_id" value="<?= (int) $e['id'] ?>">
                                                    <button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
                                            <?php elseif ($e['fassung']): ?>
                                                <span class="small muted">danach neu kalibriert</span>
                                            <?php endif; ?>
                                        </div></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (View::can('statistik.export')): ?>
                        <div class="card-body small"><a href="/anlagen/<?= $id ?>/tank/csv?inhalt=protokoll">Protokoll als CSV</a></div>
                    <?php endif; ?>
                <?php endif; ?>
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
                        <?php if ($darfAendern && $fassung !== null && in_array($sensorArt, ['linear', 'roh'], true)): ?>
                            <?php $s = $fassung['rechnung']['sensor']; $zahlFeld = static fn (mixed $w): string => is_numeric($w) ? View::zahlFeld((float) $w) : ''; ?>
                            <details class="mt tank-erweitert">
                                <summary class="small">Erweitert: Anker und Steigung von Hand</summary>
                                <form method="post" action="/anlagen/<?= $id ?>/tank/kennlinie" class="mt">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="messpunkt" value="<?= (int) $sensor['id'] ?>">
                                    <div class="field-row">
                                        <div class="field"><label for="hand_roh">Rohwert am Anker<?= $rohEinheit !== '' ? ' (' . View::e($rohEinheit) . ')' : '' ?></label>
                                            <input id="hand_roh" name="sensor[roh_anker]" inputmode="decimal" required value="<?= View::e($zahlFeld($s['roh_anker'])) ?>"></div>
                                        <div class="field"><label for="hand_mm">Füllhöhe dort (mm)</label>
                                            <input id="hand_mm" name="sensor[mm_anker]" inputmode="decimal" required value="<?= View::e($zahlFeld($s['mm_anker'])) ?>"></div>
                                    </div>
                                    <div class="field"><label for="hand_steigung">Steigung (mm je <?= View::e($rohEinheit !== '' ? $rohEinheit : 'Rohwert') ?>)</label>
                                        <input id="hand_steigung" name="sensor[mm_je_roh]" inputmode="decimal" required value="<?= View::e($zahlFeld($s['mm_je_roh'])) ?>"></div>
                                    <?php if ($rueckwirkend): ?>
                                        <div class="check"><input type="checkbox" id="hand_rueck" name="rueckwirkend" value="1">
                                            <label for="hand_rueck">Rückwirkend für diesen Einbau (ab <?= View::e(Zeit::anzeige((string) $sensor['gueltig_ab'], 'd.m.Y H:i')) ?>) – auch der Verlauf rechnet dann so</label></div>
                                    <?php endif; ?>
                                    <button class="btn btn-sm" type="submit">Kennlinie setzen</button>
                                    <p class="help">Eine neue Fassung, ungeprüft – ältere bleiben, wie sie sind. Meist besser: mit dem Peilstab peilen.</p>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($sensorFrei !== []): ?>
                        <form method="post" action="/anlagen/<?= $id ?>/einsetzen" class="mt">
                            <?= Csrf::field() ?>
                            <div class="field"><label for="ts_inventar"><?= $sensor === null ? 'Tanksensor einbauen' : 'Anderen Tanksensor einbauen' ?></label>
                                <select id="ts_inventar" name="inventar_id" required data-prinzip-wahl>
                                    <option value="">– Sensor wählen –</option>
                                    <?php foreach ($sensorFrei as $i): ?>
                                        <option value="<?= (int) $i['id'] ?>" data-prinzip="<?= View::e((string) ($i['eigenschaften']['prinzip'] ?? '')) ?>"><?= View::e($i['kennung'] . ' – ' . $i['typ']
                                            . ($i['einsatz'] !== null ? ' (jetzt: ' . ($i['einsatz']['anlage_name'] ?? $i['einsatz']['objekt_name']) . ')' : '')) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="field-row">
                                <div class="field" data-ausser-prinzip="ultraschall"><label for="ts_offset">Sondenspitze über dem Boden (mm)</label>
                                    <input id="ts_offset" name="einbau[offset_mm]" inputmode="decimal" placeholder="20"></div>
                                <div class="field" data-nur-prinzip="ultraschall"><label for="ts_dleer">Ultraschall: Sensorfläche über dem Boden (mm)</label>
                                    <input id="ts_dleer" name="einbau[d_leer_mm]" inputmode="decimal" placeholder="nur Ultraschall"></div>
                            </div>
                            <?php if ($nenn_l !== null): ?>
                                <div class="field"><label for="ts_peilung">Anzeige am Tank (%) – erste Peilung, optional</label>
                                    <input id="ts_peilung" name="peilung_prozent" inputmode="decimal" autocomplete="off" placeholder="z. B. 60"></div>
                            <?php endif; ?>
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
                                    <div class="muted"><?= View::e(implode(' · ', array_filter([(string) $f['kennung'], $ljm !== null ? TankService::zahl(round((float) $ljm, 3)) . ' l/mm' : 'Inhalt je mm unbekannt',
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
        <?php if (!$vorlage && $versorgung !== null): ?>
            <?php $tl = (array) ($live['tank'] ?? []); $zahlL = static fn (mixed $w): string => is_numeric($w) ? TankService::liter((float) $w) : '–'; ?>
            <div class="card" id="versorgung">
                <div class="card-head"><h2>Versorgung</h2><span class="hint">Kraftstoffschlauch zum Generator</span></div>
                <div class="card-body">
                    <?php if ($versorgung['schlaeuche'] === []): ?>
                        <p class="small muted">Kein Kraftstoffschlauch. Verbrauch und Restlaufzeit kommen dann aus dem Pegel (Gefälle der letzten 6 h),
                            sobald der Tank kalibriert ist. Den Schlauch zieht man auf der Karte (Werkzeug „Schlauch“) vom Tank zum Generator.</p>
                    <?php else: ?>
                        <ul class="liste-schlicht small schlaeuche">
                            <?php foreach ($versorgung['schlaeuche'] as $s): ?>
                                <li><strong><?= $s['generator_id'] !== null
                                        ? '→ <a href="/anlagen/' . (int) $s['generator_id'] . '">' . View::e((string) $s['generator']) . '</a>'
                                        : '→ Generator (nicht freigegeben)' ?></strong>
                                    <div class="muted"><?= View::e(implode(' · ', array_filter([
                                        $s['name'],
                                        $s['anzahl'] . ' × ' . trim($s['typ'] . ' ' . $s['kupplung']),
                                        $s['anzahl'] === 1 ? 'nur Vorlauf' : 'Vor- und Rücklauf',
                                        $s['laenge'] !== null ? TankService::zahl((float) $s['laenge']) . ' m' : '',
                                        $s['fest'] ? 'fest verlegt' : '',
                                    ]))) ?></div>
                                    <?php foreach ($s['hinweise'] as $h): ?><div class="notice warn small"><?= View::e($h) ?></div><?php endforeach; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($versorgung['generatoren'] !== [] || $versorgung['verborgen'] > 0): ?>
                        <h3 class="abschnitt">Generatoren</h3>
                        <ul class="liste-schlicht small">
                            <?php foreach ($versorgung['generatoren'] as $g): ?>
                                <li><a href="/anlagen/<?= (int) $g['id'] ?>"><strong><?= View::e($g['name']) ?></strong></a>
                                    <?= $g['laeuft'] === true ? '<span class="badge ok">läuft</span>' : ($g['laeuft'] === false ? '<span class="badge">steht</span>' : '') ?>
                                    <div class="muted"><?= View::e(trim($g['modell'] . ' · ' . $g['kennlinie'], ' ·')) ?></div></li>
                            <?php endforeach; ?>
                            <?php if ($versorgung['verborgen'] > 0): ?>
                                <li class="muted"><?= (int) $versorgung['verborgen'] ?> Generator(en) ohne Freigabe – der Verbrauch kommt dann aus dem Pegel.</li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($tl['sensor'])): ?>
                        <dl class="kv small mt">
                            <dt>Vorrat</dt><dd><?= View::e($zahlL($tl['vorrat_l'] ?? null)) ?><?= ($tl['vorrat_tanks'] ?? 0) > 1 ? ' aus ' . (int) $tl['vorrat_tanks'] . ' Tanks' : '' ?>
                                <?= empty($tl['vorrat_vollstaendig']) && $versorgung['tanks'] > 1 ? ' <span class="muted">(nicht jeder Tank hat einen Wert)</span>' : '' ?></dd>
                            <dt>Verbrauch</dt><dd><?= View::e(($tl['verbrauch_text'] ?? '') !== '' ? (string) $tl['verbrauch_text'] : 'unbekannt') ?></dd>
                            <dt>Restlaufzeit</dt><dd><?= View::e((string) ($tl['restlaufzeit_text'] ?? '–')) ?></dd>
                            <dt>Leer um</dt><dd><?= View::e((string) ($tl['leer_um_text'] ?? '–')) ?></dd>
                            <dt>Nachbestellen bis</dt><dd><?= View::e((string) ($tl['nachbestellen_text'] ?? '–')) ?></dd>
                            <?php if (is_numeric($tl['intern_l'] ?? null)): ?><dt>Intern im Generator</dt><dd>≈ <?= View::e($zahlL($tl['intern_l'])) ?> (zählt nicht mit)</dd><?php endif; ?>
                            <?php if ($versorgung['gemessen_l_h'] !== null): ?><dt>Aus dem Pegel (6 h)</dt><dd>≈ <?= View::e(TankService::zahl((float) $versorgung['gemessen_l_h'])) ?> l/h</dd><?php endif; ?>
                        </dl>
                    <?php endif; ?>
                    <?php if ($versorgung['gegenpruefung'] !== null): ?><div class="notice warn small mt"><?= View::e($versorgung['gegenpruefung']) ?></div><?php endif; ?>
                    <p class="help">Verbrauch aus Kennlinie × Last, wenn jeder Generator am Schlauch sichtbar ist und Livewerte hat – ohne Kennlinie
                        gilt der Volllastverbrauch (eher zu viel). Sonst aus dem Pegel. „Nachbestellen bis“ = leer um minus Lieferzeit.</p>
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
