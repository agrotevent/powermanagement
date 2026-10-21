<?php
/**
 * Reiter Vorgänge: die Eventworx-Vorgänge (Aufträge, Lieferscheine) des
 * Kunden – Quelle für Prüfnachweise am Vorgang und für Vorschläge, welche
 * Übergabeberichte ihm gehören. Ansehen mit „kunden.view“; suchen und
 * verknüpfen mit „kunden.manage“ und „berichte.eventworx“ (die Suche zeigt
 * alle Vorgänge des Mandanten), lösen mit „kunden.manage“.
 *
 * Treffer und Rückfrage stehen nur in der Antwort auf die Suche bzw. das
 * Verknüpfen – nicht in der Sitzung, nicht zwischengespeichert.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $vorgaenge KundenService::vorgaenge()
 * @var bool $pflegen
 * @var bool $darfSuchen
 * @var bool $darfNachweise
 * @var bool $eingerichtet
 * @var bool $links Links in die Web-App von Eventworx (nur mit „berichte.eventworx“)
 * @var ?array{text:string,treffer:list<array<string,mixed>>,message:string} $suche
 * @var ?array{job_id:string,text:string,nummer:string,stammdaten:bool} $rueckfrage
 */
use App\Kunden\Pruefnachweise;
use App\Services\Eventworx;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$suche = $suche ?? null;
$rueckfrage = $rueckfrage ?? null;
$art = static fn (string $a): string => Eventworx::ART_NAMEN[$a] ?? 'Vorgang';
$tag = static fn (?string $iso): string => $iso === null ? '' : Zeit::anzeige($iso, 'd.m.Y');
$mietzeit = static fn (?string $von, ?string $bis): string => $von === null && $bis === null ? ''
    : ($von === $bis || $bis === null ? Pruefnachweise::datum($von) : Pruefnachweise::datum($von) . ' – ' . Pruefnachweise::datum($bis));
?>
<?php if ($rueckfrage !== null): ?>
    <form method="post" action="/kunden/<?= $id ?>/eventworx/verknuepfen" class="notice warn" id="eventworx-rueckfrage">
        <?= Csrf::field() ?>
        <input type="hidden" name="job_id" value="<?= View::e($rueckfrage['job_id']) ?>">
        <?php if ($rueckfrage['stammdaten']): ?><input type="hidden" name="stammdaten" value="1"><?php endif; ?>
        <p><?= View::e($rueckfrage['text']) ?></p>
        <label class="check"><input type="checkbox" name="bestaetigt" value="1" required> <span>Ja, das ist dieser Kunde</span></label>
        <button class="btn btn-sm mt" type="submit">Verknüpfen</button>
        <a class="btn btn-sm btn-ghost mt" href="/kunden/<?= $id ?>?tab=vorgaenge">Abbrechen</a>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Verknüpfte Vorgänge</h2><span class="hint">Aufträge und Lieferscheine in Eventworx</span></div>
    <?php if ((string) ($kunde['ewx_kunde_ref'] ?? '') !== ''): ?>
        <p class="card-body small mb0">In Eventworx: <strong><?= View::e((string) $kunde['ewx_kunde_name'] !== '' ? (string) $kunde['ewx_kunde_name'] : 'Kunde ohne Namen') ?></strong>
            – weitere Vorgänge müssen zu diesem Eventworx-Kunden gehören.</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Vorgang</th><th>Titel</th><th>Mietzeit</th><th>Prüfnachweise</th><th>Verknüpft</th><th></th></tr></thead>
            <tbody>
            <?php if ($vorgaenge === []): ?>
                <tr><td colspan="6" class="muted">Noch kein Vorgang verknüpft<?= $darfSuchen ? ' – unten in Eventworx suchen' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($vorgaenge as $v): ?>
                <?php $link = $links ? Eventworx::link((string) $v['ewx_art'], (string) $v['ewx_id']) : ''; ?>
                <tr id="vorgang-<?= (int) $v['id'] ?>">
                    <td data-label="Vorgang"><strong><?= View::e($art((string) $v['ewx_art']) . ' ' . (string) $v['ewx_nummer']) ?></strong>
                        <?php if ($link !== ''): ?><div class="small"><a href="<?= View::e($link) ?>" target="_blank" rel="noopener noreferrer">in Eventworx öffnen</a></div><?php endif; ?>
                        <?php if ((string) $v['ewx_kunde_ref'] === ''): ?><div class="small muted">ohne Kunden-Kennung verknüpft</div><?php endif; ?></td>
                    <td data-label="Titel"><?= View::e((string) $v['titel'] !== '' ? (string) $v['titel'] : '–') ?></td>
                    <td data-label="Mietzeit" class="small"><?= View::e($mietzeit($v['von'], $v['bis']) ?: '–') ?></td>
                    <td data-label="Prüfnachweise" class="small"><?= (int) $v['nachweise'] ?>
                        <?php if ($v['dokumente_am'] !== null): ?><div class="muted">Dateien zuletzt gelesen <?= View::e(Zeit::anzeige((string) $v['dokumente_am'])) ?></div><?php endif; ?></td>
                    <td data-label="Verknüpft" class="small"><?= View::e($tag((string) $v['verknuepft_am'])) ?></td>
                    <td class="right">
                        <div class="aktionen-zeile">
                            <?php if ($darfNachweise && $eingerichtet): ?>
                                <form method="post" action="/kunden/<?= $id ?>/nachweise/eventworx">
                                    <?= Csrf::field() ?><input type="hidden" name="vorgang" value="<?= (int) $v['id'] ?>">
                                    <button class="btn btn-sm" type="submit">PDF-Dateien ansehen</button></form>
                            <?php endif; ?>
                            <?php if ($pflegen): ?>
                                <form method="post" action="/kunden/<?= $id ?>/vorgang/<?= (int) $v['id'] ?>/loesen"
                                      data-confirm="Verknüpfung mit <?= View::e((string) $v['ewx_nummer']) ?> lösen? Prüfnachweise daraus bleiben beim Kunden.">
                                    <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Lösen</button></form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$eingerichtet): ?>
    <p class="notice mt">Eventworx ist nicht eingerichtet (Systemeinstellungen) – Vorgänge lassen sich erst danach verknüpfen.</p>
<?php elseif ($darfSuchen): ?>
<form method="post" action="/kunden/<?= $id ?>/eventworx/suche" class="card mt kunden-formular" id="eventworx-suche">
    <?= Csrf::field() ?>
    <div class="card-head"><h2>Vorgang verknüpfen</h2><span class="hint">Aufträge und Lieferscheine</span></div>
    <div class="card-body">
        <div class="field"><label for="ewx-q">Nummer oder Text</label>
            <input id="ewx-q" name="q" required minlength="2" maxlength="64" value="<?= View::e((string) ($suche['text'] ?? '')) ?>" placeholder="z. B. AU-1042"></div>
        <p class="help">Am sichersten mit der Auftrags- oder Lieferscheinnummer. Eventworx zeigt hier alle Vorgänge des Mandanten –
            verknüpft wird nur, was zu diesem Kunden gehört.</p>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">In Eventworx suchen</button></div>
</form>

<?php if ($suche !== null): ?>
<div class="card mt" id="eventworx-treffer">
    <div class="card-head"><h2>Treffer</h2><span class="hint"><?= count($suche['treffer']) ?></span></div>
    <?php if ($suche['message'] !== ''): ?><p class="card-body muted mb0"><?= View::e($suche['message']) ?></p><?php endif; ?>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Vorgang</th><th>Titel</th><th>Zeitraum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($suche['treffer'] as $t): ?>
                <tr>
                    <td data-label="Vorgang"><strong><?= View::e((string) $t['art_name'] . ' ' . (string) $t['nummer']) ?></strong>
                        <?php if ((string) $t['projekt'] !== ''): ?><div class="small muted">Projekt <?= View::e((string) $t['projekt']) ?></div><?php endif; ?></td>
                    <td data-label="Titel"><?= View::e((string) $t['titel'] !== '' ? (string) $t['titel'] : '–') ?></td>
                    <td data-label="Zeitraum" class="small"><?= View::e(trim($tag($t['von']) . ($t['bis'] !== null && $tag($t['bis']) !== $tag($t['von']) ? ' – ' . $tag($t['bis']) : '')) ?: '–') ?></td>
                    <td class="right">
                        <?php if ($t['verknuepft'] === 'dieser'): ?>
                            <span class="badge ok">verknüpft</span>
                        <?php elseif ($t['verknuepft'] !== null): ?>
                            <span class="badge">bei „<?= View::e((string) $t['verknuepft']) ?>“</span>
                        <?php else: ?>
                            <form method="post" action="/kunden/<?= $id ?>/eventworx/verknuepfen">
                                <?= Csrf::field() ?><input type="hidden" name="job_id" value="<?= View::e((string) $t['id']) ?>">
                                <?php if ((string) $kunde['kundennummer'] === ''): ?>
                                    <label class="check small"><input type="checkbox" name="stammdaten" value="1" checked> <span>Kundennummer aus Eventworx</span></label>
                                <?php endif; ?>
                                <button class="btn btn-sm" type="submit">Verknüpfen</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php elseif ($pflegen): ?>
    <p class="notice mt">Vorgänge in Eventworx suchen und verknüpfen darf, wer zusätzlich das Recht „Kundendaten aus Eventworx suchen und übernehmen“ hat.</p>
<?php endif; ?>
<p class="help">Ein verknüpfter Vorgang bringt zweierlei: Prüfnachweise, die in Eventworx am Auftrag oder Lieferschein hochgeladen sind,
    lassen sich übernehmen (Reiter „Prüfnachweise“), und Übergabeberichte zu diesem Vorgang erscheinen als Vorschlag (Reiter „Protokolle“).
    Zugeordnet und freigegeben wird nie automatisch. Der Kunde sieht im Kundenbereich nichts aus Eventworx, was nicht eine Person übernommen hat.</p>
