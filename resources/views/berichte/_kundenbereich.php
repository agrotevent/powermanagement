<?php
/**
 * Karte „Kundenbereich“ am Bericht (KundenService::berichtKarte, nur mit
 * „kunden.view“): wem der Bericht im Kundenbereich gehört, ob der Kunde ihn
 * jetzt sieht und wann er ihn zuletzt geladen hat. Mit „kunden.manage“:
 * zuordnen (ein Vorschlag aus Eventworx, nie automatisch), herausnehmen und
 * die Rückfrage, wenn der Kunde im Bericht anders heißt.
 *
 * Die Zuordnung liegt außerhalb des eingefrorenen Inhalts – sie ändert
 * weder Inhalt noch PDF und lässt sich jederzeit korrigieren.
 *
 * @var array<string,mixed> $bericht
 * @var array<string,mixed> $karte
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$b = $bericht;
$id = (int) $b['id'];
$k = $karte['kunde'];
$abgeschlossen = in_array((string) $b['status'], ['abgeschlossen', 'ersetzt'], true);
$rf = $karte['rueckfrage'];
$abruf = $karte['abruf'];
$kundenname = static fn (array $x): string => (string) $x['name'] . ((string) ($x['kundennummer'] ?? '') !== '' ? ' (' . $x['kundennummer'] . ')' : '');
?>
<div class="card bericht-kundenbereich" id="kundenbereich">
    <div class="card-head"><h2>Kundenbereich</h2><span class="hint">nur ausdrücklich zugeordnet – nie über den Standort</span></div>
    <div class="card-body">
        <?php if ($k === null): ?>
            <p>Keinem Kunden zugeordnet – im Kundenbereich sieht diesen Bericht niemand.</p>
        <?php else: ?>
            <p>Zugeordnet: <strong><a href="/kunden/<?= (int) $k['id'] ?>?tab=protokolle"><?= View::e($kundenname($k)) ?></a></strong></p>
            <p class="small muted">
                <?php if ($karte['gesperrt']): ?>
                    Der Kunde ist beendet – seine Zugänge sind zu, er sieht nichts.
                <?php elseif (!$abgeschlossen): ?>
                    Erscheint nach dem Abschluss im Kundenbereich (mit dem dann gespeicherten PDF).
                <?php elseif ($karte['beim_kunden']): ?>
                    Der Kunde sieht den Bericht und sein PDF im Kundenbereich<?= (int) $karte['zugaenge'] === 0 ? ' – er hat aber noch keinen nutzbaren Zugang' : '' ?>.
                <?php else: ?>
                    Zu diesem Bericht ist kein PDF gespeichert – im Kundenbereich lässt er sich nicht laden.
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($abruf !== null): ?>
            <p class="small<?= $karte['abruf_anderer'] !== null ? ' text-warn' : '' ?>">Zuletzt im Kundenbereich geladen: <?= View::e(Zeit::anzeige((string) $abruf['am'])) ?>
                <?php if ($karte['abruf_anderer'] !== null): ?> – von einem anderen Kunden (<?= View::e($karte['abruf_anderer']) ?>)<?php endif; ?>
                <?php if ($karte['pflegen'] && (string) $abruf['username'] !== ''): ?> · Zugang <?= View::e((string) $abruf['username']) ?><?php endif; ?></p>
        <?php elseif ($k !== null && $abgeschlossen): ?>
            <p class="small muted">Im Kundenbereich noch nicht geladen.</p>
        <?php endif; ?>

        <?php if ($karte['pflegen']): ?>
            <?php if ($rf !== null): ?>
                <form method="post" action="/berichte/<?= $id ?>/kundenbereich" class="notice warn kb-rueckfrage">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="kunde_id" value="<?= (int) $rf['kunde'] ?>">
                    <input type="hidden" name="bestaetigt" value="1">
                    <p class="mb0"><?= View::e($rf['text']) ?></p>
                    <button class="btn btn-sm mt" type="submit">Ja, derselbe Kunde – zuordnen</button>
                </form>
            <?php endif; ?>
            <?php if ($karte['vorschlag'] !== null): ?>
                <form method="post" action="/berichte/<?= $id ?>/kundenbereich" class="notice">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="kunde_id" value="<?= (int) $karte['vorschlag']['kunde']['id'] ?>">
                    <p class="mb0">Vorschlag: <strong><?= View::e($kundenname($karte['vorschlag']['kunde'])) ?></strong> – <?= View::e($karte['vorschlag']['grund']) ?></p>
                    <button class="btn btn-sm mt" type="submit">Diesem Kunden zuordnen</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/berichte/<?= $id ?>/kundenbereich" class="inline-form mt">
                <?= Csrf::field() ?>
                <label for="kb-kunde-<?= $id ?>" class="small">Kunde</label>
                <select id="kb-kunde-<?= $id ?>" name="kunde_id">
                    <option value="">– keinem Kunden –</option>
                    <?php foreach ($karte['kunden'] as $kunde): ?>
                        <option value="<?= (int) $kunde['id'] ?>"<?= $k !== null && (int) $kunde['id'] === (int) $k['id'] ? ' selected' : '' ?>><?= View::e($kundenname($kunde)
                            . ((int) $kunde['aktiv'] === 1 ? '' : ' – beendet')) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm" type="submit">Zuordnung speichern</button>
            </form>
            <p class="help mb0">Im Bericht steht als Kunde „<?= View::e(trim((string) $b['kunde_name']) !== '' ? (string) $b['kunde_name'] : '–') ?>“. Heißt der gewählte
                Kunde anders, fragt die Seite nach. Ein Gelände hat oft mehrere Veranstalter – ordne nur zu, was diesem Kunden gehört.
                Die Zuordnung gilt für alle Fassungen dieser Nummer.</p>
        <?php endif; ?>
    </div>
</div>
