<?php
/**
 * Ein Prüfling: Stammdaten, Fälligkeit, Prüfungsverlauf (aus dem Prüfgerät,
 * jede mit Link auf die Prüfung) und Prüfberichte aller Fassungen.
 * Bearbeiten und Löschen nur mit „geraetepruefung.manage“; gelöscht wird
 * nur ein Prüfling ohne Prüfungen (sonst „außer Betrieb“).
 *
 * @var array<string,mixed> $p Prueflinge::find()
 * @var string $besitzer
 * @var array{code:string,symbol:string,wort:string,text:string,klasse:string} $faelligkeit
 * @var array<string,mixed>|null $ablauf
 * @var array<string,mixed>|null $inventar
 * @var list<array<string,mixed>> $pruefungen Pruefungen::fuerPruefling()
 * @var list<array<string,mixed>> $berichte Prueflinge::berichte()
 * @var bool $pflegen
 */
use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\Prueflinge;
use App\Kunden\Pruefnachweise;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $p['id'];
$title = (string) $p['kennung'];
$subtitle = 'Prüfling · ' . ((string) $p['bezeichnung'] !== '' ? (string) $p['bezeichnung'] . ' · ' : '') . $besitzer;
$zahl = static fn (string $wert, string $einheit): string => $wert === '' ? '–' : Messarten::anzeige($wert) . ($einheit !== '' ? ' ' . $einheit : '');
$stamm = [
    'Besitzer'      => $besitzer,
    'Bezeichnung'   => (string) $p['bezeichnung'],
    'Hersteller'    => (string) $p['hersteller'],
    'Typ'           => (string) $p['typ'],
    'Modell'        => (string) $p['modell'],
    'Seriennummer'  => (string) $p['seriennummer'],
    'Abteilung'     => (string) $p['abteilung'],
    'Gebäude'       => (string) $p['gebaeude'],
    'Schutzklasse'  => Prueflinge::SCHUTZKLASSEN[(string) $p['schutzklasse']] ?? (string) $p['schutzklasse'],
    'Prüfintervall' => (int) $p['intervall_monate'] === 0 ? 'keine Wiederholung' : (int) $p['intervall_monate'] . ' Monate',
    'Leitung'       => implode(' · ', array_filter([
        (string) $p['leitungslaenge'] !== '' ? $zahl((string) $p['leitungslaenge'], 'm') : '',
        (string) $p['querschnitt'] !== '' ? $zahl((string) $p['querschnitt'], 'mm²') : '',
        (string) $p['leiter'] !== '' ? $zahl((string) $p['leiter'], 'Leiter') : '',
    ])),
    'Nennleistung'  => (string) $p['nennleistung'] !== '' ? $zahl((string) $p['nennleistung'], 'W') : '',
];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/prueflinge?besitzer=<?= $p['kunde_id'] === null ? 'eigen' : (int) $p['kunde_id'] ?>">← Prüflinge</a>
    <?= View::partial('geraetepruefung/_pruefling-faelligkeit', ['f' => $faelligkeit]) ?>
    <div class="spacer"></div>
    <?php if ($pflegen): ?>
        <div class="btn-row">
            <a class="btn btn-sm btn-primary" href="/geraetepruefung/prueflinge/<?= $id ?>/bearbeiten">Bearbeiten</a>
            <?php if ($pruefungen === []): ?>
                <form method="post" action="/geraetepruefung/prueflinge/<?= $id ?>/loeschen" data-confirm="Prüfling <?= View::e((string) $p['kennung']) ?> löschen?">
                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-sidebar">
    <div>
        <section class="card">
            <div class="card-head"><h2>Stammdaten</h2><span class="hint">so steht der Prüfling auch im Prüfgerät</span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Kennung</dt><dd class="mono"><?= View::e((string) $p['kennung']) ?></dd>
                    <?php foreach ($stamm as $name => $wert): ?>
                        <?php if ($wert !== ''): ?><dt><?= View::e($name) ?></dt><dd><?= View::e($wert) ?></dd><?php endif; ?>
                    <?php endforeach; ?>
                    <dt>Prüfablauf</dt><dd><?php if ($ablauf === null): ?><span class="muted">keiner – für den Export nötig</span><?php else: ?>
                        <?= View::e(trim((string) $ablauf['nr'] . ' ' . (string) $ablauf['name'])) ?>
                        <?= (int) $ablauf['ungueltig'] === 1 ? ' <span class="badge warn">am Prüfgerät ungültig</span>' : '' ?><?php endif; ?></dd>
                    <?php if ($inventar !== null): ?>
                        <dt>Inventar</dt><dd><?= View::linkIf('datenquellen.view', '/inventar/' . (int) $inventar['id'], (string) $inventar['kennung']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['ausser_betrieb']): ?><dt>Betrieb</dt><dd><span class="badge">außer Betrieb</span></dd><?php endif; ?>
                </dl>
                <?php if ((string) $p['bemerkung'] !== ''): ?><p class="small mt"><?= nl2br(View::e((string) $p['bemerkung'])) ?></p><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Prüfungen</h2><span class="hint">aus dem Prüfgerät – unveränderlich</span></div>
            <div class="table-wrap">
                <table class="tabelle-karten">
                    <thead><tr><th>Geprüft am</th><th>Prüfer laut Gerät</th><th>Prüfablauf</th><th>Ergebnis</th><th>Prüfbericht</th></tr></thead>
                    <tbody>
                    <?php if ($pruefungen === []): ?>
                        <tr><td colspan="5" class="muted">Noch keine Prüfung hier – Prüfungen kommen aus der Datenbank des Prüfgeräts (Import).</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pruefungen as $pr): ?>
                        <tr>
                            <td data-label="Geprüft am"><a href="/geraetepruefung/pruefungen/<?= (int) $pr['id'] ?>"><?= View::e((string) Messarten::ortszeit((string) $pr['geprueft_am'], 'd.m.Y H:i')) ?></a></td>
                            <td data-label="Prüfer laut Gerät" class="small"><?= View::e((string) $pr['pruefer'] !== '' ? (string) $pr['pruefer'] : '–') ?></td>
                            <td data-label="Prüfablauf" class="small"><?= View::e((string) $pr['ablauf_name'] !== '' ? (string) $pr['ablauf_name'] : '–') ?></td>
                            <td data-label="Ergebnis"><?= View::partial('geraetepruefung/_pruefling-ergebnis', ['ergebnis' => (string) $pr['ergebnis']]) ?></td>
                            <td data-label="Prüfbericht"><?php if ($pr['bericht_id'] !== null): ?>
                                <a href="/geraetepruefung/berichte/<?= (int) $pr['bericht_id'] ?>/pdf">unterschrieben (PDF)</a>
                            <?php else: ?><span class="badge warn">offen</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card-head"><h2>Fälligkeit</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Letzte Prüfung</dt><dd><?= $p['letzte_pruefung'] !== null ? View::e(Pruefnachweise::datum((string) $p['letzte_pruefung'])) : '<span class="muted">–</span>' ?></dd>
                    <dt>Ergebnis</dt><dd><?= View::partial('geraetepruefung/_pruefling-ergebnis', ['ergebnis' => (string) $p['letztes_ergebnis'], 'geprueft' => $p['letzte_pruefung'] !== null]) ?></dd>
                    <dt>Nächste Prüfung</dt><dd><?= $p['naechste_pruefung'] !== null ? View::e(Pruefnachweise::datum((string) $p['naechste_pruefung'])) : '<span class="muted">–</span>' ?></dd>
                </dl>
                <?= View::partial('geraetepruefung/_pruefling-faelligkeit', ['f' => $faelligkeit, 'mitText' => true]) ?>
                <p class="help mt"><?= $pruefungen === []
                    ? 'Ohne Prüfung hier stehen die Daten, wie sie das Prüfgerät gemeldet oder jemand eingetragen hat.'
                    : 'Nach jeder Prüfung neu gerechnet: bestanden – Prüftag plus Intervall; nicht bestanden – vor Wiederinbetriebnahme.' ?></p>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Prüfberichte</h2></div>
            <div class="card-body">
                <?php if ($berichte === []): ?><p class="small muted">Noch kein Prüfbericht unterschrieben.</p><?php endif; ?>
                <ul class="liste-schlicht">
                    <?php foreach ($berichte as $b): ?>
                        <li><a href="/geraetepruefung/berichte/<?= (int) $b['id'] ?>/pdf"><strong><?= View::e($b['nummer']) ?></strong></a>
                            <?= $b['fassung'] > 1 ? '<span class="small">Fassung ' . (int) $b['fassung'] . '</span>' : '' ?>
                            <?= $b['status'] === 'ersetzt' ? '<span class="badge">ersetzt</span>' : '' ?>
                            <?= View::partial('geraetepruefung/_pruefling-ergebnis', ['ergebnis' => $b['ergebnis']]) ?>
                            <div class="small muted">unterschrieben <?= View::e(Zeit::anzeige($b['eingefroren_am'], 'd.m.Y')) ?> ·
                                <a href="/geraetepruefung/pruefungen/<?= (int) $b['pruefung_id'] ?>">Prüfung</a></div></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Herkunft</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Angelegt</dt><dd><?= View::e(Zeit::anzeige((string) $p['created_at'])) ?></dd>
                    <dt>Geändert</dt><dd><?= View::e(Zeit::anzeige($p['updated_at'] ?? null)) ?></dd>
                </dl>
            </div>
        </section>
    </div>
</div>
