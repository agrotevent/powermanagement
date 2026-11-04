<?php
/**
 * Eine Prüfung der Geräteprüfung: Eckdaten, Sichtprüfung, Messwerte (wie
 * vom Prüfgerät), die Prüfberichte aller Fassungen mit PDF, Unterschreiben
 * bzw. Korrektur und „Als Prüfnachweis an einen Kunden geben“.
 *
 * @var array<string,mixed> $pruefung Pruefungen::find() (daten = Normalform)
 * @var array<string,mixed>|null $pruefling
 * @var array<string,mixed>|null $kunde
 * @var array<string,mixed>|null $pruefgeraet Eintrag unter „Prüfgeräte“ mit derselben Seriennummer
 * @var list<array<string,mixed>> $berichte PruefberichtService::fuerPruefung()
 * @var array<string,mixed>|null $aktuell jüngste Fassung
 * @var bool $offen
 * @var bool $nachweisMoeglich
 * @var list<array{id:int,name:string}> $nachweisKunden
 * @var array{darf:bool,recht:bool,name:string,qualifikation:string,ort:string} $unterschrift
 */
use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\PruefberichtService;
use App\Geraetepruefung\Pruefungen;
use App\Kunden\Pruefnachweise;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$d = is_array($pruefung['daten'] ?? null) ? $pruefung['daten'] : [];
$kennung = (string) ($pruefung['kennung'] ?? '');
$title = 'Prüfung ' . ($kennung !== '' ? $kennung : '#' . (int) $pruefung['id']);
$subtitle = (string) ($pruefung['bezeichnung'] ?? '');
$bestanden = $pruefung['ergebnis'] === 'bestanden';
$wann = static fn (?string $iso, string $f = 'd.m.Y H:i'): string => $iso !== null && $iso !== '' ? (string) Messarten::ortszeit($iso, $f) : '–';
$schritte = array_values(array_filter(is_array($d['schritte'] ?? null) ? $d['schritte'] : [], 'is_array'));
$antworten = array_values(array_filter(is_array($d['sicht_antworten'] ?? null) ? $d['sicht_antworten'] : [], 'is_array'));
?>
<div class="toolbar-zeile">
    <a class="btn btn-ghost" href="/geraetepruefung/pruefungen">← Prüfungen</a>
    <?php if ($pruefling !== null): ?><a class="btn btn-ghost" href="/geraetepruefung/prueflinge/<?= (int) $pruefling['id'] ?>">Prüfling</a><?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Prüfung</h2>
        <span class="badge <?= $bestanden ? 'ok' : 'bad' ?>"><?= $bestanden ? '✓' : '✗' ?> <?= View::e(Pruefungen::ERGEBNISSE[$pruefung['ergebnis']] ?? $pruefung['ergebnis']) ?></span></div>
    <div class="card-body">
        <dl class="kv">
            <dt>Prüfling</dt><dd><strong><?= View::e($kennung !== '' ? $kennung : '–') ?></strong>
                <?= (string) ($pruefung['bezeichnung'] ?? '') !== '' ? ' · ' . View::e((string) $pruefung['bezeichnung']) : '' ?></dd>
            <dt>Besitzer</dt><dd><?= $pruefling === null ? '–' : ($pruefling['kunde_id'] === null ? 'eigener Bestand' : View::e((string) ($kunde['name'] ?? 'Kunde #' . (int) $pruefling['kunde_id']))) ?></dd>
            <dt>Geprüft</dt><dd><?= View::e($wann((string) $pruefung['geprueft_am'])) ?> <span class="small muted">(im Gerät: <?= View::e((string) $pruefung['am_geraet']) ?>)</span></dd>
            <dt>Prüfer laut Gerät</dt><dd><?= View::e((string) $pruefung['pruefer'] !== '' ? (string) $pruefung['pruefer'] : '–') ?>
                <?= (string) $pruefung['prueffirma'] !== '' ? '<span class="small muted">· ' . View::e((string) $pruefung['prueffirma']) . '</span>' : '' ?></dd>
            <dt>Prüfablauf</dt><dd><?= View::e((string) $pruefung['ablauf_name'] !== '' ? (string) $pruefung['ablauf_name'] : '–') ?></dd>
            <dt>Prüfgerät</dt><dd><?= View::e(trim((string) $pruefung['messgeraet'] . ((string) $pruefung['messgeraet_sn'] !== '' ? ', Nr. ' . $pruefung['messgeraet_sn'] : '')
                . ((string) $pruefung['firmware'] !== '' ? ', Firmware ' . $pruefung['firmware'] : ''))) ?>
                <?php $kal = (string) ($pruefgeraet['kalibriert_bis'] ?? ''); $tag = (string) Messarten::ortsdatum((string) $pruefung['geprueft_am']);
                $spaeter = $pruefgeraet !== null && ($geaendert = Zeit::ts((string) ($pruefgeraet['updated_at'] ?? ''))) !== null
                    && $geaendert > (int) Zeit::ts((string) $pruefung['geprueft_am']); ?>
                <div class="small <?= $pruefgeraet === null || $kal === '' || $kal < $tag || $spaeter ? 'text-warn' : 'muted' ?>"><?= $pruefgeraet === null
                    ? 'Nicht unter „Prüfgeräte“ eingetragen – der Bericht nennt keine Kalibrierung.'
                    : ($kal === '' ? 'Unter „Prüfgeräte“ ohne Kalibrierung eingetragen.'
                        : 'Kalibriert bis ' . View::e(Pruefnachweise::datum($kal)) . ($kal < $tag ? ' – am Prüftag abgelaufen' : '') . ' (laut „Prüfgeräte“, Stand jetzt)')
                    . ($spaeter ? ' Nach dem Prüftag geändert – der Stand am Prüftag ist nicht belegt; der Bericht nennt den Stand bei der Unterschrift.' : '') ?></div></dd>
            <?php if ((string) $pruefung['auftrag'] !== '' || (string) $pruefung['ort'] !== ''): ?>
                <dt>Auftrag / Ort</dt><dd><?= View::e(trim((string) $pruefung['auftrag'] . ' ' . (string) $pruefung['ort'])) ?></dd>
            <?php endif; ?>
            <?php if ((string) $pruefung['bemerkung'] !== ''): ?><dt>Bemerkung</dt><dd><?= nl2br(View::e((string) $pruefung['bemerkung'])) ?></dd><?php endif; ?>
            <dt>Sichtprüfung</dt><dd><?= View::e(Pruefungen::SICHT[(string) $pruefung['sicht']] ?? (string) $pruefung['sicht']) ?></dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Sichtprüfung</h2></div>
    <?php if ($antworten === []): ?>
        <div class="card-body"><p class="small muted">Keine Einzelfragen im Prüfgerät erfasst.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Prüfpunkt</th><th>Bewertung</th></tr></thead>
                <tbody>
                <?php foreach ($antworten as $a): $ok = ($a['antwort'] ?? '') === 'ok'; ?>
                    <tr><td data-label="Prüfpunkt"><?= View::e((string) (($a['text'] ?? '') !== '' ? $a['text'] : 'Frage ' . ($a['frage'] ?? ''))) ?></td>
                        <td data-label="Bewertung" class="<?= $ok ? 'text-ok' : 'text-bad' ?>"><?= $ok ? '✓ in Ordnung' : '✗ nicht in Ordnung' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Messungen</h2><span class="small muted">Messwerte, Grenzwerte und Bewertung wie vom Prüfgerät</span></div>
    <?php if ($schritte === []): ?>
        <div class="card-body"><p class="small muted">Keine Messschritte (nur Sichtprüfung).</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Schritt</th><th>Messwert</th><th>Grenzwert</th><th>Bewertung</th></tr></thead>
                <tbody>
                <?php foreach ($schritte as $s): $ok = ($s['ok'] ?? false) === true; ?>
                    <tr>
                        <td data-label="Schritt"><strong><?= View::e((string) (($s['titel'] ?? '') !== '' ? $s['titel'] : ($s['art'] ?? ''))) ?></strong>
                            <div class="small muted"><?= View::e((string) ($s['art'] ?? '')) ?></div></td>
                        <td data-label="Messwert">
                            <?php foreach (is_array($s['werte'] ?? null) ? $s['werte'] : [] as $w): if (!is_array($w)) { continue; } ?>
                                <div class="<?= ($w['gueltig'] ?? false) === true ? '' : 'text-bad' ?>"><?= View::e((string) ($w['name'] ?? '')) ?>:
                                    <strong><?= View::e(Messarten::anzeige((string) ($w['wert'] ?? ''))) ?></strong><?= ($w['gueltig'] ?? false) === true ? '' : ' ✗' ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Grenzwert">
                            <?php foreach (is_array($s['grenzwerte'] ?? null) ? $s['grenzwerte'] : [] as $g): if (!is_array($g)) { continue; } ?>
                                <div><?= View::e((string) ($g['name'] ?? '')) ?> <?= View::e(Messarten::anzeige((string) ($g['wert'] ?? ''))) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Bewertung" class="<?= $ok ? 'text-ok' : 'text-bad' ?>"><?= $ok ? '✓ i. O.' : '✗ nicht i. O.' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Prüfbericht</h2>
        <?php if ($aktuell === null): ?><span class="badge warn">offen</span><?php endif; ?></div>
    <?php if ($berichte !== []): ?>
        <div class="table-wrap">
            <table class="tabelle-karten">
                <thead><tr><th>Nummer</th><th>Stand</th><th>Unterschrieben</th><th>Prüfnachweis</th><th>PDF</th></tr></thead>
                <tbody>
                <?php foreach ($berichte as $b): ?>
                    <tr>
                        <td data-label="Nummer"><strong><?= View::e((string) $b['nummer']) ?></strong> <span class="small muted">Fassung <?= (int) $b['fassung'] ?></span>
                            <?php if ((string) $b['grund'] !== ''): ?><div class="small muted">Grund der Korrektur: <?= View::e((string) $b['grund']) ?></div><?php endif; ?></td>
                        <td data-label="Stand"><span class="badge <?= $b['status'] === 'gueltig' ? 'ok' : 'warn' ?>"><?= View::e(PruefberichtService::STATUS[$b['status']] ?? (string) $b['status']) ?></span>
                            <?php if ($b['status'] === 'korrektur' && (string) $b['korrektur_grund'] !== ''): ?><div class="small muted"><?= View::e((string) $b['korrektur_grund']) ?></div><?php endif; ?></td>
                        <td data-label="Unterschrieben"><?= View::e((string) ($b['u_name'] ?? '')) ?>
                            <div class="small muted"><?= View::e(trim((string) ($b['u_ort'] ?? '') . ', ' . $wann((string) ($b['eingefroren_am'] ?? '')), ', ')) ?></div></td>
                        <td data-label="Prüfnachweis">
                            <?php if ($b['nachweis_id'] !== null): ?>
                                <?php $nStatus = Pruefnachweise::STATUS[(string) $b['nachweis_status']] ?? (string) $b['nachweis_status']; ?>
                                <?php if (View::can('kunden.view')): ?><a href="/kunden/<?= (int) $b['nachweis_kunde_id'] ?>?tab=nachweise"><?= View::e($nStatus) ?></a>
                                <?php else: ?><?= View::e($nStatus) ?><?php endif; ?>
                            <?php else: ?>–<?php endif; ?>
                        </td>
                        <td data-label="PDF"><a class="btn btn-sm" href="/geraetepruefung/berichte/<?= (int) $b['id'] ?>/pdf">PDF</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <div class="card-body">
        <?php if ($offen): ?>
            <?php if ($aktuell === null): ?>
                <p class="small muted">Noch kein Prüfbericht – mit der Unterschrift des Prüfers entsteht er eingefroren (Nummer, Prüfsumme, PDF).</p>
            <?php else: ?>
                <p class="small muted">Korrektur angefordert – mit der nächsten Unterschrift entsteht Fassung <?= (int) $aktuell['fassung'] + 1 ?> mit dem jetzigen Stand von Prüfling und Kunde.</p>
            <?php endif; ?>
            <?= View::partial('geraetepruefung/_bericht-unterschreiben', ['u' => $unterschrift, 'ids' => (string) (int) $pruefung['id'],
                'knopf' => $aktuell === null ? 'Prüfbericht unterschreiben' : 'Korrektur unterschreiben (Fassung ' . ((int) $aktuell['fassung'] + 1) . ')']) ?>
        <?php elseif ($aktuell !== null && $aktuell['status'] === 'gueltig' && $unterschrift['darf']): ?>
            <form method="post" action="/geraetepruefung/berichte/<?= (int) $aktuell['id'] ?>/korrektur" class="inline-form"
                  data-confirm="Korrektur für <?= View::e((string) $aktuell['nummer']) ?> anfordern? Die Prüfung ist danach wieder offen; die neue Fassung entsteht beim nächsten Unterschreiben.">
                <?= Csrf::field() ?>
                <input name="grund" required minlength="3" maxlength="500" placeholder="Grund der Korrektur" aria-label="Grund der Korrektur">
                <button class="btn btn-sm" type="submit">Korrektur anfordern</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($nachweisMoeglich && $aktuell !== null): ?>
    <div class="card">
        <div class="card-head"><h2>Als Prüfnachweis an einen Kunden geben</h2></div>
        <div class="card-body">
            <p class="small muted">Das gespeicherte PDF von <?= View::e((string) $aktuell['nummer']) ?> wird Prüfnachweis im Kundenbereich – als Entwurf; der Kunde sieht ihn
                erst, wenn er in der Kundenverwaltung freigegeben ist. Bezeichnung, Nummern, Norm und Daten kommen aus dem Bericht.</p>
            <form method="post" action="/geraetepruefung/berichte/<?= (int) $aktuell['id'] ?>/nachweis">
                <?= Csrf::field() ?>
                <?php if ($kunde !== null): ?>
                    <p>Kunde: <strong><?= View::e((string) $kunde['name']) ?></strong></p>
                <?php else: ?>
                    <div class="field"><label for="gp-nachweis-kunde">Kunde</label>
                        <select id="gp-nachweis-kunde" name="kunde_id" required>
                            <option value="">– bitte wählen –</option>
                            <?php foreach ($nachweisKunden as $k): ?><option value="<?= (int) $k['id'] ?>"><?= View::e($k['name']) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <div class="field-row">
                    <div class="field"><label for="gp-nachweis-von">Mietzeit von</label><input id="gp-nachweis-von" type="date" name="einsatz_von"></div>
                    <div class="field"><label for="gp-nachweis-bis">Mietzeit bis</label><input id="gp-nachweis-bis" type="date" name="einsatz_bis"></div>
                </div>
                <div class="field"><label for="gp-nachweis-hinweis">Hinweis für den Kunden</label>
                    <input id="gp-nachweis-hinweis" name="hinweis" maxlength="500"></div>
                <button class="btn" type="submit">Als Prüfnachweis hinterlegen</button>
            </form>
        </div>
    </div>
<?php endif; ?>
