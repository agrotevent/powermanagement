<?php
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
use App\Services\SchutzService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $objekt['id'];
$art = AnlagenService::ARTEN[$objekt['art']];
$vorlage = (int) $objekt['ist_vorlage'] === 1;
$title = $objekt['name'];
$subtitle = ($vorlage ? 'Standard-Verteiler' . ((int) $objekt['fest'] === 1 ? ' (fest)' : '') : $objekt['art_anzeige']) . ($objekt['site_name'] ? ' · ' . $objekt['site_name'] : '') . ($objekt['floor_name'] ? ' / ' . $objekt['floor_name'] : '');
$reiterListe = [
    'uebersicht'  => 'Übersicht',
    'anschluesse' => 'Anschlüsse',
    'schutz'      => 'Sicherungen & RCDs',
    'messung'     => 'Messgeräte',
    'kabel'       => 'Kabel',
];
if ($vorlage) {
    unset($reiterListe['messung'], $reiterListe['kabel']);
}
$reiter = isset($reiterListe[$reiter]) ? $reiter : 'uebersicht';
$schutzNachId = [];
foreach ($schutz as $s) {
    $schutzNachId[(int) $s['id']] = $s;
}
$abgaenge = array_values(array_filter($anschluesse, static fn ($a) => $a['richtung'] === 'abgang'));
$eingaenge = array_values(array_filter($anschluesse, static fn ($a) => $a['richtung'] === 'eingang'));
$mpJeAnschluss = [];
$mpJeSchutz = [];
$mpAllgemein = [];
foreach ($messpunkte as $m) {
    if ($m['anschluss_id'] !== null) {
        $mpJeAnschluss[(int) $m['anschluss_id']][] = $m;
    } elseif ($m['schutzorgan_id'] !== null) {
        $mpJeSchutz[(int) $m['schutzorgan_id']][] = $m;
    } else {
        $mpAllgemein[] = $m;
    }
}
$zahl = static fn ($v, int $n = 1) => $v === null ? '–' : number_format((float) $v, $n, ',', '.');

/** Ein Messgerät als anklickbares Element: Wert, Auswertung, Schaltzustand. */
$messgeraet = static function (array $m) use ($objekt, $auswertung, $live): string {
    $drin = in_array((int) $m['id'], $auswertung, true);
    $w = $live['messpunkte'][(int) $m['id']] ?? null;
    $wert = $w === null ? '' : ($w['online']
        ? Messdaten::format($w['p'], 'W') . ($w['i_max'] !== null ? ' · ' . Messdaten::format($w['i_max'], 'A') : '')
        : 'keine Werte');
    $schalter = $w !== null && $w['schalter'] !== null
        ? '<span class="badge ' . ($w['schalter'] ? 'ok' : 'bad') . '" data-mp-schalter="' . (int) $m['id'] . '">' . ($w['schalter'] ? 'ein' : 'aus') . '</span>'
        : '<span data-mp-schalter="' . (int) $m['id'] . '"></span>';
    $titel = MesspunktService::titel($m + ['objekt_name' => $objekt['name']]);
    return '<span class="messgeraet" title="' . View::e($m['geraet'] . ' · ' . $m['kanal']) . '">'
        . '<span class="messgeraet-zeichen" aria-hidden="true">M</span>'
        . '<span class="messgeraet-name">' . View::e($m['bezeichnung'] !== '' ? $m['bezeichnung'] : $m['geraet']) . '</span>'
        . '<span class="messgeraet-wert" data-mp-wert="' . (int) $m['id'] . '">' . View::e($wert) . '</span>'
        . $schalter
        . (View::can('statistik.view')
            ? '<button type="button" class="btn btn-sm auswahl-knopf' . ($drin ? ' aktiv' : '') . '" data-auswertung="' . (int) $m['id'] . '" aria-pressed="' . ($drin ? 'true' : 'false') . '" title="' . View::e($titel) . '">'
              . '<span data-auswertung-text>' . ($drin ? 'in der Auswertung' : 'zur Auswertung') . '</span></button>'
            : '')
        . '</span>';
};
?>
<div class="toolbar-zeile" data-live-url="/anlagen/<?= $id ?>/live">
    <span class="art-zeichen gross" style="--art-farbe: <?= View::e($art['farbe']) ?>"><?= View::e($art['kuerzel']) ?></span>
    <span class="badge status-<?= View::e($objekt['status']) ?>"><?= View::e(AnlagenService::STATUS[$objekt['status']] ?? '') ?></span>
    <?php if ((int) $objekt['steuerung_gesperrt'] === 1): ?><span class="badge bad">Steuerung gesperrt</span><?php endif; ?>
    <span class="small muted"><?= View::e(AnlagenService::kurzform($id)) ?></span>
    <div class="spacer"></div>
    <div class="btn-row">
        <?php if (!$vorlage && $objekt['floor_id'] !== null): ?><a class="btn btn-sm" href="/karte?etage=<?= (int) $objekt['floor_id'] ?>&amp;anlage=<?= $id ?>">Auf der Karte</a><?php endif; ?>
        <?php $lage = $vorlage ? null : PlanGeoService::fuerObjekt($objekt); ?>
        <?php if ($lage !== null): ?><a class="btn btn-sm" href="<?= View::e(PlanGeoService::kartenLink($lage['lat'], $lage['lon'])) ?>" target="_blank" rel="noopener"
            title="<?= View::e(number_format($lage['lat'], 6, '.', '') . ', ' . number_format($lage['lon'], 6, '.', '')) ?>">Lage (OSM)</a><?php endif; ?>
        <?php if (!$vorlage && View::can('statistik.view') && $messpunkte !== []): ?><a class="btn btn-sm" href="/statistik?anlage=<?= $id ?>">Statistik</a><?php endif; ?>
        <a class="btn btn-sm" href="/anlagen/<?= $id ?>/druck" target="_blank" rel="noopener">Dokumentation drucken</a>
        <?php if (!empty($berichtNeu)): ?><a class="btn btn-sm" href="/berichte/neu?anlage=<?= $id ?>">Übergabebericht</a><?php endif; ?>
        <?php if ($darfAendern): ?>
            <a class="btn btn-sm btn-primary" href="/anlagen/<?= $id ?>/bearbeiten">Bearbeiten</a>
        <?php endif; ?>
    </div>
</div>

<nav class="tabs" aria-label="Bereiche der Anlage">
    <?php foreach ($reiterListe as $k => $name): ?>
        <a class="tab<?= $reiter === $k ? ' active' : '' ?>" href="?reiter=<?= $k ?>"><?= View::e($name) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($reiter === 'uebersicht'): ?>
<div class="grid grid-sidebar">
    <div>
        <?php if (!$vorlage): ?>
            <div class="live-raster einzeln">
                <?= View::partial('partials/live-kachel', ['objekt' => $objekt, 'l' => $live]) ?>
            </div>
            <?php if ($fenster !== null): ?>
                <p class="small muted">Sichtbarer Datenzeitraum: <?= View::e(Zugriff::beschreiben($fenster)) ?>.</p>
            <?php endif; ?>
            <?php if ($kennzahlen !== null): ?>
                <div class="card">
                    <div class="card-head"><h2>Letzte 24 Stunden</h2><span class="hint">Einspeisung</span></div>
                    <div class="card-body">
                        <div class="metric-grid">
                            <div class="metric"><span class="metric-label">Spitzenleistung</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['p_max'], 'W')) ?></span></div>
                            <div class="metric"><span class="metric-label">Mittel</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['p_mittel'], 'W')) ?></span></div>
                            <div class="metric"><span class="metric-label">Energie</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['energie_wh'], 'Wh')) ?></span></div>
                            <div class="metric"><span class="metric-label">Höchster Phasenstrom</span><span class="metric-value"><?= View::e(Messdaten::format($kennzahlen['i_max'], 'A')) ?></span></div>
                            <div class="metric"><span class="metric-label">Spannungsband</span><span class="metric-value"><?= View::e($zahl($kennzahlen['u_min'], 0) . ' – ' . $zahl($kennzahlen['u_max'], 0)) ?> V</span></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($schaltbar !== []): ?>
                <div class="card">
                    <div class="card-head"><h2>Schalten</h2></div>
                    <div class="card-body tight"><?= View::partial('partials/schalter', ['schaltbar' => $schaltbar]) ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="card">
            <div class="card-head"><h2>Verteilerplan</h2><span class="hint">Einspeisung → Schutzorgane → Abgänge</span></div>
            <div class="card-body">
                <?= View::partial('partials/verteilerplan', [
                    'eingaenge' => $eingaenge, 'abgaenge' => $abgaenge, 'schutz' => $schutz,
                    'mpJeAnschluss' => $mpJeAnschluss, 'mpJeSchutz' => $mpJeSchutz, 'mpAllgemein' => $mpAllgemein,
                    'messgeraet' => $messgeraet,
                ]) ?>
            </div>
        </div>
    </div>
    <div>
        <div class="card">
            <div class="card-head"><h2>Daten</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Kennung</dt><dd><?= View::e($objekt['kennung'] ?: '–') ?></dd>
                    <dt>Hersteller / Modell</dt><dd><?= View::e(trim($objekt['hersteller'] . ' ' . $objekt['modell']) ?: '–') ?></dd>
                    <dt>Seriennummer</dt><dd><?= View::e($objekt['seriennummer'] ?: '–') ?></dd>
                    <dt>Nennleistung</dt><dd><?= $objekt['nennleistung_kva'] !== null ? View::e($zahl($objekt['nennleistung_kva'])) . ' kVA' : '–' ?></dd>
                    <dt>Nennstrom</dt><dd><?= View::e($zahl(MesspunktService::nennstrom($objekt, $anschluesse), 0)) ?> A je Phase</dd>
                    <dt>Netz</dt><dd><?= (int) $objekt['phasen'] ?>~ <?= (int) $objekt['spannung_v'] ?> V</dd>
                    <?php if (!$vorlage && View::can('berichte.view')): ?>
                        <dt>Letzte Prüfung</dt><dd><?php if (!empty($letztePruefung)): ?><a href="/berichte/<?= (int) $letztePruefung['id'] ?>"><?= View::e((string) ($letztePruefung['nummer'] ?? ('#' . $letztePruefung['id']))) ?></a>
                            am <?= View::e(preg_replace('/^(\d{4})-(\d{2})-(\d{2})$/', '$3.$2.$1', (string) $letztePruefung['pruefdatum'])) ?><?php else: ?><span class="muted">kein abgeschlossener Übergabebericht</span><?php endif; ?></dd>
                    <?php endif; ?>
                    <?php foreach (AnlagenService::FELDER[$objekt['art']] as $k => [$beschriftung, $typ, $zusatz]): ?>
                        <?php if (isset($objekt['eigenschaften'][$k])): ?>
                            <?php $einheit = AnlagenService::einheit($typ, $zusatz, $objekt['eigenschaften'][$k]); ?>
                            <dt><?= View::e($beschriftung) ?></dt><dd><?= View::e(is_float($objekt['eigenschaften'][$k]) ? $zahl($objekt['eigenschaften'][$k]) : (string) $objekt['eigenschaften'][$k]) ?><?= $einheit !== '' ? ' ' . View::e($einheit) : '' ?></dd>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </dl>
                <?php if ((string) $objekt['notizen'] !== ''): ?><p class="mt small"><?= nl2br(View::e((string) $objekt['notizen'])) ?></p><?php endif; ?>
            </div>
        </div>
        <?php if (!$vorlage): ?>
            <div class="card">
                <div class="card-head"><h2>Gespeist von</h2></div>
                <div class="card-body tight">
                    <?php if ($speisung === []): ?>
                        <p class="muted small">Kein Kabel an einem Eingang – <?= in_array($objekt['art'], ['anschluss', 'generator'], true) ? 'diese Anlage ist selbst eine Quelle.' : 'noch nicht verbunden.' ?></p>
                    <?php else: ?>
                        <ol class="speisekette">
                            <?php foreach ($speisung as $k): ?>
                                <li><a href="/anlagen/<?= (int) $k['von_objekt_id'] ?>"><?= View::e($k['von_name']) ?></a>
                                    <span class="small muted"><?= View::e(trim(($k['kabeltyp'] ?? '') . ' ' . ($k['querschnitt_mm2'] !== null ? str_replace('.', ',', (string) (float) $k['querschnitt_mm2']) . ' mm²' : ''))) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
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
                    <form method="post" action="/anlagen/<?= $id ?>/loeschen" data-confirm="<?= View::e($objekt['name']) ?> wirklich löschen? Kabel bleiben als Kabelweg eingezeichnet.">
                        <?= Csrf::field() ?>
                        <button class="btn btn-sm btn-danger" type="submit">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($reiter === 'anschluesse'): ?>
<div class="card">
    <div class="card-head"><h2>Anschlüsse je Steckertyp</h2><span class="hint">Kombination und Anzahl frei wählbar</span></div>
    <form method="post" action="/anlagen/<?= $id ?>/anschluesse" class="card-body">
        <?= Csrf::field() ?>
        <div class="table-wrap">
            <table class="anschluss-matrix">
                <thead><tr><th>Steckverbinder</th><th class="num">Nennstrom</th><th class="num">Eingänge</th><th class="num">Abgänge</th></tr></thead>
                <tbody>
                <?php foreach ($steckertypen as $t): ?>
                    <tr>
                        <td><span class="stecker" style="--stecker-farbe: <?= View::e($t['farbe']) ?>"></span> <?= View::e($t['name']) ?>
                            <?= (int) $t['ist_standard'] === 1 ? '' : '<span class="badge">eigener Typ</span>' ?></td>
                        <td class="num small"><?= View::e($zahl($t['strom_a'], 0)) ?> A · <?= (int) $t['phasen'] ?>~</td>
                        <?php foreach (['eingang', 'abgang'] as $richtung): ?>
                            <td class="num">
                                <input class="zahl-feld" type="number" min="0" max="96" name="<?= $richtung ?>[<?= (int) $t['id'] ?>]"
                                       value="<?= (int) ($anzahlen[$richtung][(int) $t['id']] ?? 0) ?>"<?= $darfAendern ? '' : ' disabled' ?>
                                       aria-label="<?= View::e($t['name'] . ' ' . ($richtung === 'eingang' ? 'Eingänge' : 'Abgänge')) ?>">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($darfAendern): ?>
            <div class="btn-row mt">
                <button class="btn btn-primary" type="submit">Anzahl übernehmen</button>
                <span class="small muted">Wegnehmen trifft nur freie Anschlüsse – an denen kein Kabel hängt. Weitere Stecker (CEE blau, Harting …) unter <a href="/stecker">Steckverbinder</a>.</span>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($anschluesse !== []): ?>
<form method="post" action="/anlagen/<?= $id ?>/anschluesse/details" class="card">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Die einzelnen Anschlüsse</h2><span class="hint">Bezeichnung, Sicherung, Phase</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Richtung</th><th>Bezeichnung</th><th>Stecker</th><th>Sicherung / RCD</th><th>Phase</th><th>Belegung</th><th>Notiz</th></tr></thead>
            <tbody>
            <?php foreach ($anschluesse as $a): ?>
                <tr>
                    <td data-label="Richtung"><span class="badge <?= $a['richtung'] === 'eingang' ? 'info' : '' ?>"><?= $a['richtung'] === 'eingang' ? 'Eingang' : 'Abgang' ?></span></td>
                    <td data-label="Bezeichnung"><input name="anschluss[<?= (int) $a['id'] ?>][bezeichnung]" value="<?= View::e($a['bezeichnung']) ?>" class="kurz"<?= $darfAendern ? '' : ' disabled' ?>></td>
                    <td data-label="Stecker"><span class="stecker" style="--stecker-farbe: <?= View::e($a['typ_farbe']) ?>"></span> <?= View::e($a['typ_name']) ?></td>
                    <td data-label="Sicherung">
                        <?php if ($a['richtung'] === 'abgang'): ?>
                            <select name="anschluss[<?= (int) $a['id'] ?>][schutzorgan_id]"<?= $darfAendern ? '' : ' disabled' ?>>
                                <option value="0">– keine Zuordnung –</option>
                                <?php foreach ($schutz as $s): ?>
                                    <?php if (in_array($s['art'], ['ls', 'rcbo', 'nh', 'rcd', 'sonstiges'], true)): ?>
                                        <option value="<?= (int) $s['id'] ?>"<?= (int) $a['schutzorgan_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= str_repeat('· ', (int) $s['tiefe']) . View::e($s['text']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($a['schutz_text'] !== ''): ?><div class="small muted"><?= View::e($a['schutz_text']) ?></div><?php endif; ?>
                        <?php else: ?>
                            <input type="hidden" name="anschluss[<?= (int) $a['id'] ?>][schutzorgan_id]" value="0">–
                        <?php endif; ?>
                    </td>
                    <td data-label="Phase">
                        <?php if ((int) $a['typ_phasen'] === 1): ?>
                            <select name="anschluss[<?= (int) $a['id'] ?>][phase]"<?= $darfAendern ? '' : ' disabled' ?>>
                                <option value="">–</option>
                                <?php foreach (['L1', 'L2', 'L3'] as $l): ?><option<?= $a['phase'] === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?><input type="hidden" name="anschluss[<?= (int) $a['id'] ?>][phase]" value="">3~<?php endif; ?>
                    </td>
                    <td data-label="Belegung" class="small">
                        <?php if ($a['leitung'] !== null): ?>
                            <?= $a['richtung'] === 'eingang' ? '← ' : '→ ' ?><a href="/anlagen/<?= (int) $a['leitung']['gegenstelle_id'] ?>"><?= View::e((string) $a['leitung']['gegenstelle']) ?></a>
                        <?php else: ?><span class="muted">frei</span><?php endif; ?>
                        <?php foreach ($mpJeAnschluss[(int) $a['id']] ?? [] as $m): ?><div><?= $messgeraet($m) ?></div><?php endforeach; ?>
                    </td>
                    <td data-label="Notiz"><input name="anschluss[<?= (int) $a['id'] ?>][notizen]" value="<?= View::e((string) $a['notizen']) ?>"<?= $darfAendern ? '' : ' disabled' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($darfAendern): ?><div class="card-footer"><button class="btn btn-primary" type="submit">Anschlüsse speichern</button></div><?php endif; ?>
</form>
<?php endif; ?>

<?php elseif ($reiter === 'schutz'): ?>
<div class="grid grid-sidebar">
    <div class="card">
        <div class="card-head"><h2>Sicherungen & RCDs</h2><span class="hint">Baum: was hinter wem hängt</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Schutzorgan</th><th>Abgänge</th><th></th></tr></thead>
                <tbody>
                <?php if ($schutz === []): ?><tr><td colspan="3" class="muted">Noch keine Schutzorgane eingetragen.</td></tr><?php endif; ?>
                <?php foreach ($schutz as $s): ?>
                    <?php $hier = array_filter($abgaenge, static fn ($a) => (int) $a['schutzorgan_id'] === (int) $s['id']); ?>
                    <tr>
                        <td data-label="Schutzorgan">
                            <span class="baum-einzug" style="--tiefe: <?= (int) $s['tiefe'] ?>"></span>
                            <span class="schutz-art schutz-<?= View::e($s['art']) ?>"><?= View::e(SchutzService::ARTEN[$s['art']] ?? $s['art']) ?></span>
                            <strong><?= View::e($s['text']) ?></strong>
                            <?php if ((string) $s['notizen'] !== ''): ?><div class="small muted"><?= View::e((string) $s['notizen']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Abgänge" class="small"><?= View::e(implode(', ', array_map(static fn ($a) => $a['bezeichnung'] . ' (' . $a['typ_name'] . ')', $hier)) ?: '–') ?></td>
                        <td class="right">
                            <?php if ($darfAendern): ?>
                                <details class="inline-details">
                                    <summary class="btn btn-sm">Ändern</summary>
                                    <?= View::partial('partials/schutz-form', ['objektId' => $id, 's' => $s, 'schutz' => $schutz]) ?>
                                    <form method="post" action="/anlagen/<?= $id ?>/schutz/loeschen" data-confirm="Schutzorgan entfernen? Was dahinter hängt, rückt eine Stufe nach oben.">
                                        <?= Csrf::field() ?><input type="hidden" name="schutz_id" value="<?= (int) $s['id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">Entfernen</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div>
        <?php if ($darfAendern): ?>
            <div class="card">
                <div class="card-head"><h2>Schutzorgan eintragen</h2></div>
                <div class="card-body"><?= View::partial('partials/schutz-form', ['objektId' => $id, 's' => null, 'schutz' => $schutz]) ?></div>
            </div>
            <div class="card">
                <div class="card-body">
                    <form method="post" action="/anlagen/<?= $id ?>/schutz/vorschlag">
                        <?= Csrf::field() ?>
                        <button class="btn btn-sm" type="submit">Übliche Bestückung vorschlagen</button>
                    </form>
                    <p class="help">Legt für jeden Abgang ohne Zuordnung einen LS in Steckerstärke an, bis 32 A je vier hinter einem RCD 30 mA Typ A,
                        ab 63 A mit eigenem RCD 300 mA, davor den Hauptschalter. Ein Vorschlag, kein Bemessungsnachweis – bitte mit der echten Bestückung abgleichen.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($reiter === 'messung'): ?>
<div class="grid grid-sidebar">
    <div class="card">
        <div class="card-head"><h2>Messgeräte in dieser Anlage</h2><span class="hint">Klick auf „zur Auswertung“ übernimmt das Gerät in die Statistik</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Messgerät</th><th>Misst</th><th>Gerät · Kanal</th><th></th></tr></thead>
                <tbody>
                <?php if ($messpunkte === []): ?><tr><td colspan="4" class="muted">Noch kein Messgerät zugeordnet.</td></tr><?php endif; ?>
                <?php foreach ($messpunkte as $m): ?>
                    <tr>
                        <td data-label="Messgerät"><?= $messgeraet($m) ?></td>
                        <td data-label="Misst"><?= View::e(MesspunktService::ROLLEN[$m['rolle']] ?? $m['rolle']) ?>
                            <?= $m['anschluss_name'] ? '<div class="small muted">Abgang ' . View::e($m['anschluss_name']) . '</div>' : '' ?>
                            <?= $m['schutz_name'] ? '<div class="small muted">hinter ' . View::e($m['schutz_name']) . '</div>' : '' ?>
                            <?= !empty($m['inventar_kennung']) ? '<div class="small muted">aus ' . View::e($m['inventar_kennung']) . '</div>' : '' ?></td>
                        <td data-label="Gerät" class="small mono"><?= View::e($m['geraet']) ?> · <?= View::e($m['kanal']) ?></td>
                        <td class="right">
                            <?php if ($darfAendern): ?>
                                <form method="post" action="/anlagen/<?= $id ?>/messpunkt/loeschen" data-confirm="Messgerät von der Anlage lösen?">
                                    <?= Csrf::field() ?><input type="hidden" name="messpunkt_id" value="<?= (int) $m['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Lösen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div>
    <?php if (!$vorlage && ($einsaetze !== [] || $inventar !== [])): ?>
    <?php $laufend = array_values(array_filter($einsaetze, static fn ($e) => $e['bis'] === null)); $frueher = array_values(array_filter($einsaetze, static fn ($e) => $e['bis'] !== null)); ?>
    <div class="card">
        <div class="card-head"><h2>Aus dem Inventar</h2><span class="hint">Verteiler und Zwischenstecker mit ihren Shellys</span></div>
        <div class="card-body">
            <?php if ($laufend === []): ?><p class="small muted">Hier steckt nichts aus dem Inventar.</p><?php endif; ?>
            <ul class="liste-schlicht einsatz-liste">
                <?php foreach ($laufend as $e): ?>
                    <li><strong><?= View::can('datenquellen.view') ? '<a href="/inventar/' . (int) $e['inventar_id'] . '">' . View::e($e['kennung']) . '</a>' : View::e($e['kennung']) ?></strong>
                        <span class="small"><?= View::e(InventarService::ARTEN[$e['inventar_art']] ?? '') ?><?= $e['anschluss_name'] ? ' · ' . View::e($e['anschluss_name']) : ($e['inventar_art'] === 'zwischenstecker' ? ' · Einspeisung' : '') ?></span>
                        <div class="small muted">seit <?= View::e(Zeit::anzeige((string) $e['ab'], 'd.m.Y H:i')) ?></div>
                        <?php if ($darfAendern): ?>
                            <form method="post" action="/einsatz/<?= (int) $e['id'] ?>/beenden" data-confirm="<?= View::e($e['kennung']) ?> abbauen? Die Shellys messen dann nicht mehr hier.">
                                <?= Csrf::field() ?><button class="btn btn-sm" type="submit">Abbauen</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($darfAendern && $inventar !== []): ?>
                <?php
                $passend = []; $andere = []; $stecker = [];
                foreach ($inventar as $i) {
                    if ($i['art'] === 'zwischenstecker') { $stecker[] = $i; }
                    elseif ($objekt['art'] === 'verteilung' && trim((string) $i['vorlage_modell']) === trim((string) $objekt['modell'])) { $passend[] = $i; }
                    elseif ($objekt['art'] === 'verteilung') { $andere[] = $i; }
                }
                $option = static fn ($i) => '<option value="' . (int) $i['id'] . '">' . View::e($i['kennung'] . ' – ' . $i['typ']
                    . ($i['einsatz'] !== null ? ' (jetzt: ' . ($i['einsatz']['anlage_name'] ?? $i['einsatz']['objekt_name']) . ')' : '')) . '</option>';
                ?>
                <form method="post" action="/anlagen/<?= $id ?>/einsetzen" class="mt">
                    <?= Csrf::field() ?>
                    <div class="field"><label for="inventar_id">Einsetzen</label>
                        <select id="inventar_id" name="inventar_id" required>
                            <option value="">– Stück wählen –</option>
                            <?php if ($passend !== []): ?><optgroup label="Verteiler, gleicher Typ"><?= implode('', array_map($option, $passend)) ?></optgroup><?php endif; ?>
                            <?php if ($andere !== []): ?><optgroup label="andere Verteiler (Typ wird umgestellt)"><?= implode('', array_map($option, $andere)) ?></optgroup><?php endif; ?>
                            <?php if ($stecker !== []): ?><optgroup label="Zwischenstecker"><?= implode('', array_map($option, $stecker)) ?></optgroup><?php endif; ?>
                        </select></div>
                    <?php if ($stecker !== []): ?>
                        <div class="field"><label for="einsatz_anschluss">Zwischenstecker steckt an</label>
                            <select id="einsatz_anschluss" name="anschluss_id">
                                <option value="0">der Einspeisung</option>
                                <?php foreach ($anschluesse as $a): ?>
                                    <?php if ($a['richtung'] === 'abgang'): ?><option value="<?= (int) $a['id'] ?>">Abgang <?= View::e($a['bezeichnung'] . ' (' . $a['typ_name'] . ')') ?></option><?php endif; ?>
                                <?php endforeach; ?>
                            </select></div>
                    <?php endif; ?>
                    <input type="hidden" name="typ_uebernehmen" value="0">
                    <?php if ($andere !== []): ?><label class="check"><input type="checkbox" name="typ_uebernehmen" value="1" checked> <span>Typ der Anlage an den Verteiler anpassen, wenn er abweicht</span></label><?php else: ?><input type="hidden" name="typ_uebernehmen" value="1"><?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit">Einsetzen</button>
                    <p class="help">Ab jetzt messen die Shellys des Stücks hier. Steckt es noch woanders, wird es dort abgezogen.</p>
                </form>
            <?php endif; ?>
            <?php if ($frueher !== []): ?>
                <h3 class="mt">Früher hier</h3>
                <ul class="liste-schlicht small">
                    <?php foreach ($frueher as $e): ?><li><?= View::e($e['kennung']) ?> · <?= View::e(InventarService::zeitraum($e)) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($darfAendern): ?>
    <div class="card">
        <div class="card-head"><h2>Messgerät einzeichnen</h2></div>
        <form method="post" action="/anlagen/<?= $id ?>/messpunkt" class="card-body">
            <?= Csrf::field() ?>
            <?php if ($geraete !== []): ?>
                <div class="field">
                    <label for="geraet_kanal">Gerät und Kanal (aus der InfluxDB)</label>
                    <select id="geraet_kanal" name="geraet_kanal">
                        <option value="">– von Hand eintragen –</option>
                        <?php foreach ($geraete as $g): ?>
                            <option value="<?= View::e($g['geraet'] . '|' . $g['kanal']) ?>"><?= View::e($g['geraet'] . ' · ' . $g['kanal'] . ($g['name'] !== '' ? ' – ' . $g['name'] : '') . ($g['p'] !== null ? ' (' . Messdaten::format((float) $g['p'], 'W') . ')' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Shelly-Geräte erscheinen hier von selbst, sobald sie über MQTT senden.</div>
                </div>
            <?php endif; ?>
            <div class="field-row">
                <div class="field"><label for="mp_geraet">Gerät</label><input id="mp_geraet" name="geraet" placeholder="shellypro3em-…"></div>
                <div class="field"><label for="mp_kanal">Kanal</label><input id="mp_kanal" name="kanal" placeholder="em:0"></div>
            </div>
            <div class="field">
                <label for="mp_rolle">Misst</label>
                <select id="mp_rolle" name="rolle">
                    <?php foreach (array_diff_key(MesspunktService::ROLLEN, array_flip(MesspunktService::NUR_INVENTAR)) as $k => $r): ?><option value="<?= $k ?>"><?= View::e($r) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="mp_ort">Eingebaut an</label>
                <select id="mp_ort" name="anschluss_id">
                    <option value="0">der ganzen Anlage (Einspeisung)</option>
                    <?php foreach ($abgaenge as $a): ?><option value="<?= (int) $a['id'] ?>">Abgang <?= View::e($a['bezeichnung'] . ' (' . $a['typ_name'] . ')') ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($schutz !== []): ?>
                <div class="field">
                    <label for="mp_schutz">oder hinter Schutzorgan</label>
                    <select id="mp_schutz" name="schutzorgan_id">
                        <option value="0">–</option>
                        <?php foreach ($schutz as $s): ?><option value="<?= (int) $s['id'] ?>"><?= str_repeat('· ', (int) $s['tiefe']) . View::e($s['text']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="field"><label for="mp_bez">Bezeichnung</label><input id="mp_bez" name="bezeichnung" placeholder="z. B. Einspeisung (Pro 3EM)"></div>
            <button class="btn btn-primary" type="submit">Zuordnen</button>
        </form>
    </div>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($reiter === 'kabel'): ?>
<div class="card">
    <div class="card-head"><h2>Kabel an dieser Anlage</h2></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Richtung</th><th>Gegenstelle</th><th>Kabel</th><th class="num">Länge</th><th class="num">Spannungsfall</th><th>Hinweise</th></tr></thead>
            <tbody>
            <?php if ($kabel === []): ?><tr><td colspan="6" class="muted">Kein Kabel angeschlossen. Kabel zeichnet man auf der Karte.</td></tr><?php endif; ?>
            <?php foreach ($kabel as $k): ?>
                <?php
                $eingehend = (int) $k['zu_objekt_id'] === $id;
                $strom = null;
                foreach (($live['i'] ?? []) as $wert) { $strom = max($strom ?? 0.0, (float) $wert); }
                $du = $eingehend && $strom !== null && $k['laenge'] !== null && $k['querschnitt_mm2'] !== null
                    ? LeitungService::spannungsfall($strom, (float) $k['laenge'], (float) $k['querschnitt_mm2']) : null;
                ?>
                <tr>
                    <td data-label="Richtung"><?= $eingehend ? '← Einspeisung' : '→ Abgang' ?> <span class="small muted"><?= View::e((string) ($eingehend ? $k['zu_anschluss'] : $k['von_anschluss'])) ?></span></td>
                    <td data-label="Gegenstelle"><a href="/anlagen/<?= (int) ($eingehend ? $k['von_objekt_id'] : $k['zu_objekt_id']) ?>"><?= View::e((string) ($eingehend ? $k['von_name'] : $k['zu_name'])) ?></a></td>
                    <td data-label="Kabel"><?= View::e(trim($k['kabeltyp'] . ' ' . ($k['adern'] ? $k['adern'] . 'G' : '') . ($k['querschnitt_mm2'] !== null ? str_replace('.', ',', (string) (float) $k['querschnitt_mm2']) . ' mm²' : ''))) ?: '–' ?>
                        <?= $k['fest'] === 1 ? '<span class="badge info" title="gehört zur Location">fest verlegt</span>' : '' ?>
                        <?= $k['belastbarkeit_a'] !== null ? '<div class="small muted">trägt rund ' . (int) $k['belastbarkeit_a'] . ' A</div>' : '' ?></td>
                    <td data-label="Länge" class="num"><?= $k['laenge'] !== null ? View::e($zahl($k['laenge'])) . ' m' . ($k['laenge_m'] === null ? ' <span class="small muted">(Plan)</span>' : '') : '–' ?></td>
                    <td data-label="Spannungsfall" class="num"><?= $du !== null ? View::e($zahl($du, 2)) . ' %<div class="small muted">bei ' . View::e($zahl($strom)) . ' A jetzt</div>' : '–' ?></td>
                    <td data-label="Hinweise" class="small"><?php foreach ($k['hinweise'] as $h): ?><div class="text-warn"><?= View::e($h) ?></div><?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
