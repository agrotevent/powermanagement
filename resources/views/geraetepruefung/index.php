<?php
/**
 * Übersicht der Geräteprüfung (DGUV V3): Zahlen, fällige und überfällige
 * Prüflinge, offene Prüfungen (noch ohne gültigen Prüfbericht), die letzten
 * Importe und die Wege zu Import, Export, Prüfungen und Prüfabläufen – je
 * nach Recht.
 *
 * @var array{in_betrieb:int,ueberfaellig:int,bald:int,ohne_termin:int,ausser_betrieb:int} $zahlen
 * @var list<array<string,mixed>> $faellig Prueflinge::liste(faellig)
 * @var list<array<string,mixed>> $offen Pruefungen::liste(offen)
 * @var int $offenAnzahl
 * @var list<array{id:int,created_at:string,messgeraet:string,messgeraet_sn:string,zahlen:array<string,int>}> $importe
 * @var bool $pflegen
 * @var bool $unterschreiben
 * @var bool $leer noch gar keine Prüflinge
 */
use App\Geraetepruefung\Messarten;
use App\Kunden\Pruefnachweise;
use App\Support\View;
use App\Support\Zeit;

$title = 'Geräteprüfung';
$subtitle = 'Ortsveränderliche elektrische Betriebsmittel nach DGUV Vorschrift 3 – mit dem Prüfgerät BENNING ST 755+';
// Bekannte Zahlen eines Imports; was der Import sonst zählt, steht mit seinem Schlüssel da.
$zahlNamen = [
    'pruefungen_neu' => 'neue Prüfungen', 'pruefungen_da' => 'Prüfungen schon da', 'prueflinge_neu' => 'neue Prüflinge',
    'prueflinge_ersetzt' => 'Prüflinge ersetzt', 'prueflinge_gleich' => 'Prüflinge unverändert', 'kunden_neu' => 'neue Kunden',
    'uebergangen' => 'übergangen',
];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-primary" href="/geraetepruefung/prueflinge">Prüflinge</a>
    <a class="btn btn-sm" href="/geraetepruefung/pruefungen">Prüfungen und Prüfberichte</a>
    <?php if ($pflegen): ?>
        <a class="btn btn-sm" href="/geraetepruefung/import">Datenbank des Prüfgeräts importieren</a>
        <a class="btn btn-sm" href="/geraetepruefung/export">Datenbank für das Prüfgerät schreiben</a>
        <a class="btn btn-sm btn-ghost" href="/geraetepruefung/prueflinge/neu">+ Prüfling</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-ghost" href="/geraetepruefung/pruefablaeufe">Prüfabläufe</a>
</div>

<div class="grid grid-4 kennzahlen">
    <div class="card stat-card"><div class="stat"><span class="label">In Betrieb</span><span class="value"><?= (int) $zahlen['in_betrieb'] ?></span>
        <span class="trend"><?= (int) $zahlen['ausser_betrieb'] ?> außer Betrieb</span></div></div>
    <div class="card stat-card"><div class="stat"><span class="label"><span aria-hidden="true">✗</span> Überfällig</span><span class="value"><?= (int) $zahlen['ueberfaellig'] ?></span>
        <span class="trend">Frist abgelaufen oder nicht bestanden</span></div></div>
    <div class="card stat-card"><div class="stat"><span class="label"><span aria-hidden="true">!</span> Bald fällig</span><span class="value"><?= (int) $zahlen['bald'] ?></span>
        <span class="trend">in den nächsten <?= (int) Pruefnachweise::BALD_TAGE ?> Tagen</span></div></div>
    <div class="card stat-card"><div class="stat"><span class="label">Offene Prüfungen</span><span class="value"><?= (int) $offenAnzahl ?></span>
        <span class="trend">noch ohne unterschriebenen Prüfbericht</span></div></div>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Fällig</h2><span class="hint">überfällig oder in <?= (int) Pruefnachweise::BALD_TAGE ?> Tagen</span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Prüfling</th><th>Besitzer</th><th>Fällig</th></tr></thead>
                <tbody>
                <?php if ($faellig === []): ?>
                    <tr><td colspan="3" class="muted">Nichts fällig.<?= (int) $zahlen['ohne_termin'] > 0 ? ' ' . (int) $zahlen['ohne_termin'] . ' Prüflinge haben noch keinen Termin.' : '' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($faellig as $p): ?>
                    <tr>
                        <td data-label="Prüfling"><a href="/geraetepruefung/prueflinge/<?= (int) $p['id'] ?>"><strong><?= View::e((string) $p['kennung']) ?></strong></a>
                            <?php if ((string) $p['bezeichnung'] !== ''): ?><div class="small muted"><?= View::e((string) $p['bezeichnung']) ?></div><?php endif; ?></td>
                        <td data-label="Besitzer" class="small"><?= View::e((string) $p['besitzer']) ?></td>
                        <td data-label="Fällig"><?= View::partial('geraetepruefung/_pruefling-faelligkeit', ['f' => $p['faelligkeit'], 'mitText' => true]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) $zahlen['ueberfaellig'] + (int) $zahlen['bald'] > count($faellig)): ?>
            <div class="card-body small"><a href="/geraetepruefung/prueflinge?faellig=faellig">Alle <?= (int) $zahlen['ueberfaellig'] + (int) $zahlen['bald'] ?> fälligen Prüflinge</a></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Offene Prüfungen</h2><span class="hint">ohne gültigen Prüfbericht<?= $unterschreiben ? ' – zum Unterschreiben' : '' ?></span></div>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Geprüft am</th><th>Prüfling</th><th>Ergebnis</th></tr></thead>
                <tbody>
                <?php if ($offen === []): ?><tr><td colspan="3" class="muted">Keine – jede Prüfung hat einen unterschriebenen Prüfbericht.</td></tr><?php endif; ?>
                <?php foreach ($offen as $o): ?>
                    <tr>
                        <td data-label="Geprüft am"><a href="/geraetepruefung/pruefungen/<?= (int) $o['id'] ?>"><?= View::e(Pruefnachweise::datum(Messarten::ortsdatum((string) $o['geprueft_am']))) ?></a>
                            <div class="small muted"><?= View::e((string) $o['pruefer']) ?></div></td>
                        <td data-label="Prüfling"><?= View::e((string) ($o['kennung'] ?? '')) ?><?php if ((string) ($o['bezeichnung'] ?? '') !== ''): ?>
                            <div class="small muted"><?= View::e((string) $o['bezeichnung']) ?></div><?php endif; ?></td>
                        <td data-label="Ergebnis"><?= View::partial('geraetepruefung/_pruefling-ergebnis', ['ergebnis' => (string) $o['ergebnis']]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($offenAnzahl > count($offen)): ?>
            <div class="card-body small"><a href="/geraetepruefung/pruefungen?offen=1">Alle <?= (int) $offenAnzahl ?> offenen Prüfungen</a></div>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="card-head"><h2>Letzte Importe</h2><span class="hint">aus der Datenbank des Prüfgeräts</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Wann</th><th>Prüfgerät</th><th>Ergebnis</th></tr></thead>
            <tbody>
            <?php if ($importe === []): ?>
                <tr><td colspan="3" class="muted">Noch kein Import.<?= $pflegen ? ' Die Datenbank des Prüfgeräts (oder eine Sicherung davon) lässt sich unter „Datenbank des Prüfgeräts importieren“ einlesen.' : '' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($importe as $i): ?>
                <tr>
                    <td data-label="Wann"><?= View::e(Zeit::anzeige($i['created_at'])) ?></td>
                    <td data-label="Prüfgerät" class="small"><?= View::e(trim($i['messgeraet'] . ($i['messgeraet_sn'] !== '' ? ' · Nr. ' . $i['messgeraet_sn'] : '')) ?: '–') ?></td>
                    <td data-label="Ergebnis" class="small"><?php
                        $teile = [];
                        foreach ($i['zahlen'] as $k => $n) {
                            $teile[] = $n . ' ' . ($zahlNamen[$k] ?? str_replace('_', ' ', $k));
                        }
                        echo View::e($teile === [] ? '–' : implode(' · ', $teile)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<p class="help">Prüfabläufe und Grenzwerte sind Sache des Prüfgeräts; bewertet wird dort. PowerManagement übernimmt Prüflinge und Prüfungen,
    leitet das Gesamtergebnis ab (ein Schritt, ein Wert oder eine Antwort der Sichtprüfung „nicht in Ordnung“ heißt nicht bestanden) und
    lässt zu jeder Prüfung einen Prüfbericht unterschreiben. Bestandene Prüfberichte von Kundengeräten werden Prüfnachweise im Kundenbereich –
    sichtbar erst nach der Freigabe von Hand.</p>
<?php if ($leer && $pflegen): ?>
    <div class="notice">Noch keine Prüflinge. Sie kommen aus der Datenbank des Prüfgeräts (Import) oder werden hier angelegt
        (<a href="/geraetepruefung/prueflinge/neu">+ Prüfling</a>).</div>
<?php endif; ?>
