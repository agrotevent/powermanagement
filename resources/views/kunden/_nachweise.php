<?php
/**
 * Reiter Prüfnachweise: die Prüfnachweise (DGUV V3) der Geräte, die der
 * Kunde gemietet hat – jeder Stand, mit Frist, ob der Kunde ihn sieht und
 * wann er ihn zuletzt geladen hat. Hinterlegen, korrigieren, freigeben,
 * zurückziehen und löschen nur mit „kunden.nachweise“; ansehen mit
 * „kunden.view“. Der Kunde sieht einen Nachweis erst nach der Freigabe.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $nachweise Pruefnachweise::fuerPersonal()
 * @var bool $darfNachweise
 * @var list<array{id:int,name:string}> $standorte
 * @var list<array<string,mixed>> $vorgaenge
 * @var list<array<string,mixed>> $inventar
 * @var array<string,mixed> $eingabe
 * @var bool $eingerichtet Eventworx eingerichtet
 */
use App\Kunden\Kundenbereich;
use App\Kunden\Pruefnachweise;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$basis = '/kunden/' . $id . '/nachweise/';
$gesperrt = Kundenbereich::kundeSperre($id) !== null;
$statusKlasse = ['entwurf' => '', 'freigegeben' => 'ok', 'zurueckgezogen' => 'warn'];
$fristKlasse = ['gueltig' => 'ok', 'laeuft_ab' => 'warn', 'abgelaufen' => 'bad', 'unbekannt' => ''];
$neu = ($eingabe['form'] ?? '') === 'nachweis' ? $eingabe : [];
$felder = ['standorte' => $standorte, 'vorgaenge' => $vorgaenge, 'inventar' => $inventar];
?>
<div class="card">
    <div class="card-head"><h2>Prüfnachweise</h2><span class="hint">DGUV V3 – Prüfungen der Geräte, nicht der Anlage</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Gerät</th><th>Prüfung</th><th>Frist</th><th>Im Kundenbereich</th><th>Location / Mietzeit</th><th>Geladen</th><th></th></tr></thead>
            <tbody>
            <?php if ($nachweise === []): ?>
                <tr><td colspan="7" class="muted">Noch kein Prüfnachweis für diesen Kunden<?= $darfNachweise ? ' – unten hinterlegen' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($nachweise as $n): ?>
                <?php
                $nid = (int) $n['id'];
                $g = $n['gueltigkeit'];
                $nummern = array_filter([(string) $n['inventarnummer'] !== '' ? 'Inv.-Nr. ' . $n['inventarnummer'] : '', (string) $n['seriennummer'] !== '' ? 'S/N ' . $n['seriennummer'] : '']);
                $bearbeiten = ($eingabe['form'] ?? '') === 'nachweis-' . $nid;
                $werte = $bearbeiten ? $eingabe + ['standort_name' => (string) $n['standort_name']] : $n;
                ?>
                <tr id="nachweis-<?= $nid ?>">
                    <td data-label="Gerät"><strong><?= View::e((string) $n['bezeichnung']) ?></strong>
                        <?php if ($nummern !== []): ?><div class="small muted"><?= View::e(implode(' · ', $nummern)) ?></div><?php endif; ?>
                        <div class="small muted"><?= View::e(Pruefnachweise::quelleText($n)) ?></div></td>
                    <td data-label="Prüfung"><?= View::e(Pruefnachweise::datum($n['geprueft_am'])) ?>
                        <?php if ((string) $n['ergebnis'] !== ''): ?><div class="small"><?= View::e(Pruefnachweise::ERGEBNISSE[(string) $n['ergebnis']] ?? (string) $n['ergebnis']) ?></div><?php endif; ?>
                        <?php if ((string) $n['norm'] !== ''): ?><div class="small muted"><?= View::e((string) $n['norm']) ?></div><?php endif; ?></td>
                    <td data-label="Frist"><span class="badge <?= View::e($fristKlasse[$g['code']] ?? '') ?>"><?= View::e($g['symbol'] . ' ' . $g['wort']) ?></span>
                        <div class="small muted"><?= View::e($g['text']) ?></div>
                        <?php foreach ($g['hinweise'] as $h): ?><div class="small"><?= View::e($h) ?></div><?php endforeach; ?></td>
                    <td data-label="Im Kundenbereich"><span class="badge <?= View::e($statusKlasse[(string) $n['status']] ?? '') ?>"><?= View::e(Pruefnachweise::STATUS[(string) $n['status']] ?? (string) $n['status']) ?></span>
                        <div class="small muted"><?= $n['beim_kunden'] ? 'sieht der Kunde' : ((string) $n['status'] === 'freigegeben' ? ($gesperrt ? 'Kunde beendet – sieht nichts' : 'Datei fehlt') : 'sieht der Kunde nicht') ?></div></td>
                    <td data-label="Location / Mietzeit" class="small"><?= View::e((string) $n['standort_name'] !== '' ? (string) $n['standort_name'] : '–') ?>
                        <?php if ($n['einsatz_von'] !== null || $n['einsatz_bis'] !== null): ?><div class="muted">Mietzeit <?= View::e(Pruefnachweise::datum($n['einsatz_von']) . ' – ' . Pruefnachweise::datum($n['einsatz_bis'])) ?></div><?php endif; ?>
                        <?php if ((string) $n['hinweis'] !== ''): ?><div class="muted">Hinweis: <?= View::e((string) $n['hinweis']) ?></div><?php endif; ?></td>
                    <td data-label="Geladen" class="small"><?= $n['abruf'] !== null ? View::e(Zeit::anzeige((string) $n['abruf']['am'])) : '<span class="muted">nie</span>' ?></td>
                    <td class="right">
                        <div class="aktionen-zeile">
                            <?php if ($n['datei_id'] !== null): ?><a class="btn btn-sm btn-ghost" href="<?= View::e($basis . $nid . '/datei') ?>" download>PDF</a><?php endif; ?>
                            <?php if ($darfNachweise && (string) $n['status'] !== 'freigegeben'): ?>
                                <form method="post" action="<?= View::e($basis . $nid . '/status') ?>" data-confirm="Für den Kunden freigeben? Er sieht den Nachweis sofort im Kundenbereich.">
                                    <?= Csrf::field() ?><input type="hidden" name="status" value="freigegeben"><button class="btn btn-sm" type="submit">Freigeben</button></form>
                            <?php elseif ($darfNachweise): ?>
                                <form method="post" action="<?= View::e($basis . $nid . '/status') ?>" data-confirm="Zurückziehen? Der Kunde sieht den Nachweis sofort nicht mehr.">
                                    <?= Csrf::field() ?><input type="hidden" name="status" value="zurueckgezogen"><button class="btn btn-sm btn-ghost" type="submit">Zurückziehen</button></form>
                            <?php endif; ?>
                        </div>
                        <?php if ($darfNachweise): ?>
                            <details class="zugang-bearbeiten"<?= $bearbeiten ? ' open' : '' ?>>
                                <summary class="btn btn-sm">Bearbeiten</summary>
                                <form method="post" action="<?= View::e($basis . $nid . '/speichern') ?>" class="mt">
                                    <?= Csrf::field() ?>
                                    <?= View::partial('kunden/_nachweis_felder', ['p' => 'n' . $nid, 'w' => $werte] + $felder) ?>
                                    <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                </form>
                                <form method="post" action="<?= View::e($basis . $nid . '/loeschen') ?>" class="mt" data-confirm="Prüfnachweis löschen? Der Kunde sieht ihn sofort nicht mehr; die Datei geht mit, wenn sie sonst niemand nutzt.">
                                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($darfNachweise): ?>
<form method="post" action="<?= View::e($basis . 'hochladen') ?>" enctype="multipart/form-data" class="card mt kunden-formular" id="neuer-nachweis">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Prüfnachweis hinterlegen</h2><span class="hint">PDF aus der Prüfung, höchstens 10 MB</span></div>
    <div class="card-body">
        <div class="field"><label for="nn-datei">Prüfprotokoll (PDF)</label>
            <input id="nn-datei" type="file" name="datei" accept="application/pdf,.pdf" required></div>
        <?= View::partial('kunden/_nachweis_felder', ['p' => 'nn', 'w' => $neu] + $felder) ?>
        <label class="check"><input type="checkbox" name="freigeben" value="1"<?= !empty($neu['freigeben']) ? ' checked' : '' ?>> <span>gleich für den Kunden freigeben</span></label>
        <p class="help">Das PDF wird nicht ausgewertet – Prüfdatum, nächste Prüfung und Ergebnis trägst du aus dem Protokoll ein; ein
            Ergebnis nur, wenn es dort steht. Eine Sammel-PDF für mehrere Geräte geht auch (Bezeichnung etwa „Sammelnachweis 32 Geräte“) –
            aber nur, wenn sie ausschließlich Geräte dieses Kunden enthält. Der Kunde bekommt die Datei unter neutralem Namen.</p>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Hinterlegen</button></div>
</form>
<div class="card mt" id="aus-eventworx">
    <div class="card-head"><h2>Aus Eventworx übernehmen</h2><span class="hint">PDFs am Auftrag oder Lieferschein</span></div>
    <div class="card-body">
        <?php if (!$eingerichtet): ?>
            <p class="muted mb0">Eventworx ist nicht eingerichtet (Systemeinstellungen).</p>
        <?php elseif ($vorgaenge === []): ?>
            <p class="muted mb0">Erst unter „Vorgänge“ einen Eventworx-Auftrag oder -Lieferschein dieses Kunden verknüpfen.</p>
        <?php else: ?>
            <div class="aktionen-zeile">
                <?php foreach ($vorgaenge as $vg): ?>
                    <form method="post" action="<?= View::e($basis . 'eventworx') ?>" data-laeuft="liest Dateien …" data-laeuft-seite>
                        <?= Csrf::field() ?><input type="hidden" name="vorgang" value="<?= (int) $vg['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= View::e((\App\Services\Eventworx::ART_NAMEN[(string) $vg['ewx_art']] ?? 'Vorgang') . ' ' . (string) $vg['ewx_nummer']) ?></button></form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="help">Gezeigt werden Dateien, die in Eventworx am Auftrag oder Lieferschein hochgeladen sind. Prüfberichte aus
            „Werkstatt → Prüfungen“ (am Gerät) sind über die Schnittstelle noch nicht nachgewiesen – bitte von Hand hinterlegen.
            Übernommen wird nur, was du auswählst; Prüfdatum und Frist trägst du aus dem Protokoll ein.</p>
    </div>
</div>
<?php else: ?>
    <p class="notice mt">Prüfnachweise hinterlegen und freigeben darf, wer das Recht „Prüfnachweise (DGUV V3) …“ hat.</p>
<?php endif; ?>
<p class="help">Ein Prüfnachweis belegt die wiederkehrende Prüfung eines Geräts (DGUV Vorschrift 3, DIN EN 50699) – er ersetzt nicht
    den Übergabebericht der Anlage vor Ort (Reiter „Protokolle“). Der Kunde sieht nur freigegebene Nachweise, auch wenn seine
    Location-Freigabe abgelaufen ist; sie bleiben bis zum Ende der Aufbewahrung (31.12. des Prüfjahres plus
    <?= View::e(\App\Kunden\KundenEinstellungen::wert('kundenbereich.nachweise_jahre')) ?> Jahre) oder bis der Kunde gelöscht wird.</p>
