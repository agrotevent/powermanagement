<?php
/*
 * Vorschau eines Imports: Zuordnung der Kunden im Gerät, Prüflinge mit
 * Zustand, Prüfungen, Vorlage. Erwartet $stand (GeraeteImport::pruefen),
 * $kennung (Vorschau), $datei (Name beim Hochladen).
 *
 * Das Formular schickt nur die Auswahl. `zuordnung_stand` sagt, für welche
 * Zuordnung die Prüflinge unten gezeigt sind; `vollstaendig` steht als
 * letztes Feld vor den Knöpfen – fehlt es, hat PHP abgeschnitten.
 */
use App\Geraetepruefung\GeraeteImport;
use App\Geraetepruefung\Prueflinge;
use App\Kunden\Pruefnachweise;
use App\Services\SiteService;
use App\Support\Csrf;
use App\Support\View;

$title = 'Geräteprüfung: Import';
$q = $stand['quelle'];
$subtitle = 'Vorschau: ' . ($q['dateiname'] !== '' ? $q['dateiname'] : $datei);
$z = $stand['zahlen'];
$kundenHier = [];
foreach ($stand['kunden_hier'] as $k) {
    $kundenHier['k' . $k['id']] = $k['name'] . ($k['kundennummer'] !== '' ? ' (' . $k['kundennummer'] . ')' : '');
}
$wahlText = static function (string $w) use ($kundenHier): string {
    return match (true) {
        $w === 'eigen' => 'Eigener Bestand',
        $w === 'neu'   => 'neuer Kunde',
        $w === 'nicht' => 'nicht übernehmen',
        default        => $kundenHier[$w] ?? $w,
    };
};
$kundenName = [];
foreach ($stand['kunden'] as $k) {
    $kundenName[(string) $k['ref']] = $k['name'];
}
// Zeilen mit Haken, Fehlern und „hier anders“ stehen immer da; von den
// übrigen höchstens 300 – die Seite bleibt auch bei Tausenden Geräten leicht.
$zeigen = static function (array $liste): array {
    $aus = [];
    $schlicht = 0;
    $weitere = 0;
    foreach ($liste as $p) {
        $immer = in_array($p['zustand'], ['anders', 'fehler'], true) || $p['inventar'] !== null;
        if (!$immer && ++$schlicht > 300) {
            $weitere++;
            continue;
        }
        $aus[] = $p;
    }
    return [$aus, $weitere];
};
[$aktiv, $aktivWeitere] = $zeigen(array_values(array_filter($stand['prueflinge'], static fn (array $p): bool => $p['zustand'] !== 'gleich')));
$gleichAlle = count(array_filter($stand['prueflinge'], static fn (array $p): bool => $p['zustand'] === 'gleich'));
[$gleich, $gleichWeitere] = $zeigen(array_values(array_filter($stand['prueflinge'], static fn (array $p): bool => $p['zustand'] === 'gleich')));
$anders = count(array_filter($stand['prueflinge'], static fn (array $p): bool => $p['zustand'] === 'anders'));
$inventarVorschlaege = count(array_filter($stand['prueflinge'], static fn (array $p): bool => $p['inventar'] !== null));
$datum = static fn (string $d): string => $d !== '' ? Pruefnachweise::datum($d) : '–';
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung">← Geräteprüfung</a>
    <div class="spacer"></div>
    <form method="post" action="/geraetepruefung/import/verwerfen">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-ghost" type="submit">Vorschau verwerfen (andere Datei)</button>
    </form>
</div>

<div class="card">
    <div class="card-head"><h2><?= View::e($q['dateiname'] !== '' ? $q['dateiname'] : 'Datenbank des Prüfgeräts') ?></h2>
        <span class="hint"><?= View::e(SiteService::mb((int) $q['groesse'])) ?> · <span class="mono"><?= View::e(substr((string) $q['sha256'], 0, 12)) ?></span></span></div>
    <div class="card-body">
        <dl class="kv small">
            <dt>Prüfgerät</dt><dd><?= View::e(trim($q['messgeraet'] . ' ' . $q['messgeraet_sn']) ?: '–') ?><?= $q['firmware'] !== '' ? ' · Firmware ' . View::e($q['firmware']) : '' ?></dd>
            <dt>DB-Version</dt><dd><?= View::e($q['db_version'] ?: '–') ?></dd>
            <dt>In der Datei</dt><dd><?= (int) $z['kunden'] ?> Kunden, <?= (int) $z['geraete'] ?> Geräte, <?= (int) $z['pruefungen'] ?> Prüfungen, <?= (int) $stand['pruefablaeufe'] ?> Prüfabläufe</dd>
        </dl>
        <div class="import-zahlen mt">
            <?php foreach (GeraeteImport::ZUSTAENDE as $k => [$text, $klasse]): ?>
                <?php if ($z['prueflinge_' . $k] > 0): ?><span class="badge <?= View::e($klasse) ?>"><?= (int) $z['prueflinge_' . $k] ?> Prüflinge <?= View::e($text) ?></span><?php endif; ?>
            <?php endforeach; ?>
            <span class="badge info"><?= (int) $z['pruefungen_neu'] ?> Prüfungen neu</span>
            <?php if ($z['pruefungen_da'] > 0): ?><span class="badge ok"><?= (int) $z['pruefungen_da'] ?> Prüfungen schon da</span><?php endif; ?>
            <?php if ($z['pruefungen_uebergangen'] > 0): ?><span class="badge"><?= (int) $z['pruefungen_uebergangen'] ?> Prüfungen übergangen</span><?php endif; ?>
        </div>
        <?php foreach ($stand['hinweise'] as $h): ?><div class="notice warn mt small"><?= View::e($h) ?></div><?php endforeach; ?>
    </div>
</div>

<form method="post" action="/geraetepruefung/import/uebernehmen" id="gp-import" data-laeuft="übernimmt …"
      data-laeuft-hinweis="Prüflinge und Prüfungen werden übernommen. Bitte nicht noch einmal klicken.">
    <?= Csrf::field() ?>
    <input type="hidden" name="kennung" value="<?= View::e($kennung) ?>">
    <input type="hidden" name="zuordnung_stand" value="<?= View::e($stand['zuordnung_stand']) ?>">

    <div class="card">
        <div class="card-head"><h2>Kunden im Prüfgerät</h2><span class="hint">wohin gehören ihre Geräte?</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten import-tabelle">
                <thead><tr><th>Kunde im Gerät</th><th class="num">Geräte</th><th class="num">Prüfungen</th><th>Zuordnung</th></tr></thead>
                <tbody>
                <?php foreach ($stand['kunden'] as $k): ?>
                    <?php $ref = (string) $k['ref']; ?>
                    <tr>
                        <td data-label="Kunde im Gerät"><strong><?= View::e($k['name'] !== '' ? $k['name'] : 'ohne Namen') ?></strong>
                            <div class="small muted"><?= View::e(implode(' · ', array_filter([$k['kundennummer'] !== '' ? 'Nr. ' . $k['kundennummer'] : '',
                                trim($k['plz'] . ' ' . $k['ort'])]))) ?></div></td>
                        <td data-label="Geräte" class="num"><?= (int) $k['geraete'] ?></td>
                        <td data-label="Prüfungen" class="num"><?= (int) $k['pruefungen'] ?>
                            <?php if ($k['wahl'] !== 'nicht'): ?><div class="small muted"><?= (int) $k['pruefungen_neu'] ?> neu</div><?php endif; ?></td>
                        <td data-label="Zuordnung">
                            <label class="sr-only" for="gp-z-<?= View::e($ref) ?>">Zuordnung für <?= View::e($k['name']) ?></label>
                            <select id="gp-z-<?= View::e($ref) ?>" name="zuordnung[<?= View::e($ref) ?>]">
                                <option value="nicht"<?= $k['wahl'] === 'nicht' ? ' selected' : '' ?>>nicht übernehmen</option>
                                <option value="eigen"<?= $k['wahl'] === 'eigen' ? ' selected' : '' ?>>Eigener Bestand</option>
                                <?php if ($stand['erlaubt']['neu_kunde']): ?>
                                    <option value="neu"<?= $k['wahl'] === 'neu' ? ' selected' : '' ?>>als neuen Kunden anlegen</option>
                                <?php endif; ?>
                                <?php if ($kundenHier !== []): ?>
                                    <optgroup label="Kunden hier">
                                        <?php foreach ($kundenHier as $wert => $text): ?>
                                            <option value="<?= View::e($wert) ?>"<?= $k['wahl'] === $wert ? ' selected' : '' ?>><?= View::e($text) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                            <?php if ($k['vorschlag'] !== 'nicht' || $k['vorschlag_grund'] !== ''): ?>
                                <div class="small muted">Vorschlag: <?= View::e($wahlText($k['vorschlag'])) ?><?= $k['vorschlag_grund'] !== '' ? ' – ' . View::e($k['vorschlag_grund']) : '' ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer btn-row">
            <button class="btn btn-sm" type="submit" name="aktion" value="zuordnen" data-laeuft="wendet an …">Zuordnung anwenden</button>
            <span class="small muted">Danach stehen unten die Prüflinge zu dieser Zuordnung.<?= $stand['erlaubt']['neu_kunde'] ? '' : ' Neue Kunden anlegen darf nur, wer Kunden pflegt.' ?></span>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Prüflinge</h2>
            <?php if ($anders > 0 || $inventarVorschlaege > 0): ?>
                <div class="btn-row">
                    <?php if ($anders > 0): ?><button class="btn btn-sm btn-ghost" type="button" data-auswahl-an="#gp-import input[name='ersetzen[]']">alle „hier anders“ ersetzen</button><?php endif; ?>
                    <button class="btn btn-sm btn-ghost" type="button" data-auswahl-aus="#gp-import input[type=checkbox][data-zeile]">keine Haken</button>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($anders > 0): ?>
            <div class="card-body small"><strong>hier anders</strong> heißt: die Stammdaten stehen hier anders als im Prüfgerät – meist,
                weil sie jemand korrigiert hat. Sie bleiben, wie sie sind, außer du hakst <em>ersetzen</em> an. Neue Prüfungen kommen
                in jedem Fall dazu.</div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="tabelle-karten import-tabelle">
                <thead><tr><th>Auswahl</th><th>Prüfling</th><th>Besitzer</th><th class="num">Prüfungen</th><th>Zustand</th></tr></thead>
                <tbody>
                <?php if ($aktiv === [] && $aktivWeitere === 0): ?>
                    <tr><td colspan="5" class="muted"><?= $stand['prueflinge'] === [] ? 'Keine Prüflinge – noch ist kein Kunde zugeordnet.' : 'Alle Prüflinge stehen schon genauso hier.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($aktiv as $p): ?>
                    <?php [$text, $klasse] = GeraeteImport::ZUSTAENDE[$p['zustand']]; ?>
                    <tr class="import-<?= View::e($p['zustand']) ?>">
                        <td class="import-haken">
                            <?php if ($p['zustand'] === 'anders'): ?>
                                <?php /* Mit dem Stand, den diese Vorschau zeigt – eine spätere Korrektur wird nicht ersetzt. */ ?>
                                <label class="check"><input type="checkbox" name="ersetzen[]" value="<?= View::e($p['form'] . ':' . $p['stand']) ?>" data-zeile> ersetzen</label>
                            <?php endif; ?>
                            <?php if ($p['inventar'] !== null): ?>
                                <label class="check"><input type="checkbox" name="inventar[]" value="<?= View::e($p['form'] . ':' . $p['inventar']['id']) ?>" data-zeile checked>
                                    mit Inventar <?= View::e($p['inventar']['kennung']) ?></label>
                            <?php endif; ?>
                            <?php if ($p['zustand'] !== 'anders' && $p['inventar'] === null): ?>
                                <span class="muted small"><?= $p['zustand'] === 'neu' ? 'wird angelegt' : 'nicht übernehmbar' ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Prüfling">
                            <strong><?= $p['id'] !== null ? '<a href="/geraetepruefung/prueflinge/' . (int) $p['id'] . '">' . View::e($p['kennung']) . '</a>' : View::e($p['kennung']) ?></strong>
                            <div class="small"><?= View::e($p['bezeichnung'] !== '' ? $p['bezeichnung'] : '–') ?></div>
                            <div class="small muted"><?= View::e(Prueflinge::SCHUTZKLASSEN[(string) $p['daten']['schutzklasse']] ?? '') ?>
                                <?= $p['daten']['naechste_pruefung'] !== '' ? ' · nächste laut Gerät ' . View::e($datum((string) $p['daten']['naechste_pruefung'])) : '' ?></div>
                        </td>
                        <td data-label="Besitzer" class="small"><?= View::e($p['besitzer'] === 'neu' ? 'neu: ' . ($kundenName[$p['kunde_ref']] ?? '') : $wahlText($p['besitzer'])) ?></td>
                        <td data-label="Prüfungen" class="num"><?= (int) $p['pruefungen_neu'] ?> neu<?= $p['pruefungen_da'] > 0 ? '<div class="small muted">' . (int) $p['pruefungen_da'] . ' schon da</div>' : '' ?></td>
                        <td data-label="Zustand">
                            <span class="badge <?= View::e($klasse) ?>"><?= View::e($text) ?></span>
                            <?php if ($p['fehler'] !== null): ?><div class="small import-grund"><?= View::e($p['fehler']) ?></div><?php endif; ?>
                            <?php if ($p['zustand'] === 'anders'): ?>
                                <details class="import-unterschiede">
                                    <summary class="small">Unterschiede: <?= View::e(implode(', ', $p['unterschiede'])) ?></summary>
                                    <dl class="import-abweichungen">
                                        <?php foreach ($p['abweichungen'] as $a): ?>
                                            <div>
                                                <dt><?= View::e($a['feld']) ?></dt>
                                                <dd><span class="import-wo">hier</span><?= View::e($a['hier']) ?></dd>
                                                <dd><span class="import-wo">Prüfgerät</span><?= View::e($a['datei']) ?></dd>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>
                            <?php foreach ($p['hinweise'] as $h): ?><div class="small muted"><?= View::e($h) ?></div><?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($aktivWeitere > 0): ?>
                    <tr><td colspan="5" class="muted small">… und <?= (int) $aktivWeitere ?> weitere neue Prüflinge – sie werden ebenso angelegt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($gleichAlle > 0): ?>
            <details class="card-body">
                <summary class="small"><?= (int) $gleichAlle ?> Prüflinge stehen schon genauso hier – neue Prüfungen kommen dazu.</summary>
                <div class="table-wrap">
                    <table class="tabelle-karten import-tabelle">
                        <thead><tr><th>Auswahl</th><th>Prüfling</th><th>Besitzer</th><th class="num">Prüfungen</th></tr></thead>
                        <tbody>
                        <?php foreach ($gleich as $p): ?>
                            <tr class="import-gleich">
                                <td class="import-haken">
                                    <?php if ($p['inventar'] !== null): ?>
                                        <label class="check"><input type="checkbox" name="inventar[]" value="<?= View::e($p['form'] . ':' . $p['inventar']['id']) ?>" data-zeile checked>
                                            mit Inventar <?= View::e($p['inventar']['kennung']) ?></label>
                                    <?php else: ?><span class="muted small">nichts zu tun</span><?php endif; ?>
                                </td>
                                <td data-label="Prüfling"><strong><a href="/geraetepruefung/prueflinge/<?= (int) $p['id'] ?>"><?= View::e($p['kennung']) ?></a></strong>
                                    <div class="small"><?= View::e($p['bezeichnung'] !== '' ? $p['bezeichnung'] : '–') ?></div></td>
                                <td data-label="Besitzer" class="small"><?= View::e($wahlText($p['besitzer'])) ?></td>
                                <td data-label="Prüfungen" class="num"><?= (int) $p['pruefungen_neu'] ?> neu<?= $p['pruefungen_da'] > 0 ? ', ' . (int) $p['pruefungen_da'] . ' schon da' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($gleichWeitere > 0): ?>
                            <tr><td colspan="4" class="muted small">… und <?= (int) $gleichWeitere ?> weitere.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Vorlage für den Export</h2></div>
        <div class="card-body small">
            <?php if ($stand['vorlage']['moeglich']): ?>
                <label class="check"><input type="checkbox" name="vorlage" value="1"<?= $stand['vorlage']['vorauswahl'] ? ' checked' : '' ?>>
                    <span>Einstellungen des Geräts als Vorlage für den Export übernehmen (Prüfabläufe, Grenzwerte, Fragen der Sichtprüfung –
                        ohne Kunden, Geräte und Prüfungen)</span></label>
                <p class="mb0 muted"><?= $stand['vorlage']['gleich'] ? 'Die Einstellungen gleichen der Vorlage, die schon gilt.'
                    : ($stand['vorlage']['aktiv'] ? 'Die Einstellungen weichen von der Vorlage ab, die jetzt gilt.' : 'Es gibt noch keine Vorlage.') ?>
                    Mit Haken werden auch die Prüfabläufe hier abgeglichen (fehlende werden ungültig); ohne kommen nur neue dazu.</p>
            <?php else: ?>
                <p class="mb0">Diese Datei taugt nicht als Vorlage: <?= View::e($stand['vorlage']['grund'] !== '' ? $stand['vorlage']['grund'] : 'unbekannter Aufbau') ?>
                    Prüflinge und Prüfungen lassen sich trotzdem übernehmen.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-footer btn-row import-fuss">
            <input type="hidden" name="vollstaendig" value="1">
            <button class="btn btn-primary" type="submit" name="aktion" value="uebernehmen">Übernehmen</button>
            <span class="small muted"><?= (int) $z['prueflinge_neu'] ?> Prüflinge neu, <?= (int) $z['pruefungen_neu'] ?> Prüfungen neu<?= $z['geraete_uebergangen'] > 0
                ? ' · ' . (int) $z['geraete_uebergangen'] . ' Geräte nicht zugeordnet' : '' ?></span>
        </div>
    </div>
</form>
