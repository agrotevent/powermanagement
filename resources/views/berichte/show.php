<?php
use App\Berichte\BerichtService;
use App\Services\Eventworx;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$b = $bericht;
$id = (int) $b['id'];
$title = $b['nummer'] ?? ('Entwurf #' . $id);
$subtitle = 'Übergabebericht · ' . ($b['site_name'] ?? ($b['standort_name'] !== '' ? $b['standort_name'] : 'ohne Standort'));
$klasse = ['entwurf' => 'warn', 'geprueft' => 'info', 'abgeschlossen' => 'ok', 'ersetzt' => '', 'verworfen' => ''];
$pruefsumme = static fn (string $h): string => trim(implode(' ', str_split(substr($h, 0, 16), 4)));
$ereignisNamen = [
    'angelegt' => 'angelegt', 'gespeichert' => 'gespeichert', 'pruefer_unterschrieben' => 'vom Prüfer unterschrieben',
    'zurueckgezogen' => 'Unterschrift zurückgezogen', 'kundenmodus_start' => 'an den Kunden übergeben', 'kundenmodus_ende' => 'Kundenmodus beendet',
    'kundenmodus_abgemeldet' => 'Kundenmodus: abgemeldet (falsche Kennwörter)', 'kunde_unterschrieben' => 'vom Kunden unterschrieben',
    'ohne_kunde' => 'ohne Kunde abgeschlossen', 'korrektur_angelegt' => 'Korrektur angelegt', 'ersetzt' => 'durch Korrektur ersetzt',
    'verworfen' => 'verworfen', 'eventworx_uebernommen' => 'aus Eventworx übernommen', 'eventworx_entfernt' => 'Eventworx-Verweis entfernt',
    'pdf_abgerufen' => 'PDF abgerufen',
];
?>
<div class="toolbar-zeile">
    <span class="badge <?= $klasse[$b['status']] ?? '' ?>"><?= View::e(BerichtService::STATUS[$b['status']] ?? $b['status']) ?></span>
    <?php if ((int) $b['fassung'] > 1): ?><span class="badge">Fassung <?= (int) $b['fassung'] ?></span><?php endif; ?>
    <span class="small muted">
        <?= $b['abgeschlossen_am'] ? 'abgeschlossen ' . View::e(Zeit::anzeige((string) $b['abgeschlossen_am'])) : '' ?>
        <?= !$b['abgeschlossen_am'] && $b['eingefroren_am'] ? 'unterschrieben ' . View::e(Zeit::anzeige((string) $b['eingefroren_am'])) : '' ?>
        <?= $b['status'] === 'entwurf' ? 'Entwurf – ausfüllen darf, wer am Standort „bearbeiten“ hat.' : '' ?>
    </span>
    <?php if ((string) $b['ewx_nummer'] !== ''): ?>
        <span class="small">Eventworx <?= View::e(trim((Eventworx::ART_NAMEN[(string) $b['ewx_art']] ?? '') . ' ' . $b['ewx_nummer'])) ?></span>
    <?php endif; ?>
    <div class="spacer"></div>
    <a class="btn btn-sm btn-ghost" href="/berichte">Alle Berichte</a>
</div>

<?php if ((int) $b['fassung'] > 1): ?>
    <p class="notice">Korrektur (Fassung <?= (int) $b['fassung'] ?>)<?php if ($vorgaenger !== null && BerichtService::sichtbar($vorgaenger)): ?>
        zu <a href="/berichte/<?= (int) $vorgaenger['id'] ?>"><?= View::e((string) $vorgaenger['nummer']) ?>, Fassung <?= (int) $vorgaenger['fassung'] ?></a><?php endif; ?>
        – Grund: <?= View::e((string) $b['korrektur_grund']) ?></p>
<?php endif; ?>
<?php if ($b['status'] === 'ersetzt' && $b['ersetzt_durch_id'] !== null): ?>
    <p class="notice warn">Dieser Bericht ist durch eine Korrektur ersetzt – <a href="/berichte/<?= (int) $b['ersetzt_durch_id'] ?>">neue Fassung ansehen</a>.</p>
<?php elseif ($b['status'] === 'verworfen'): ?>
    <p class="notice">Verworfen – die Nummer bleibt als erklärte Lücke in der Nummernfolge.</p>
<?php elseif ($offeneKorrektur !== null): ?>
    <p class="notice warn">Eine Korrektur ist in Arbeit – <a href="/berichte/<?= (int) $offeneKorrektur['id'] ?>">Fassung <?= (int) $offeneKorrektur['fassung'] ?> ansehen</a>.
        Bis sie abgeschlossen ist, gilt diese Fassung.</p>
<?php endif; ?>

<?php if ($b['status'] === 'geprueft' && ($darfKunde || $darfOhneKunde || $zurueckziehbar)): ?>
    <div class="card bericht-aktionen">
        <div class="card-head"><h2>Übergabe an den Kunden</h2></div>
        <div class="card-body">
            <p class="small muted">Der Bericht ist vom Prüfer unterschrieben und eingefroren. Jetzt bestätigt der Kunde den Erhalt – am besten gleich hier
                am Gerät. Im Kundenmodus ist alles andere gesperrt, bis du dein Kennwort eingibst.</p>
            <?php if ($darfKunde): ?>
                <form method="post" action="/berichte/<?= $id ?>/kundenmodus" data-confirm="Das Gerät an den Kunden geben? Alles andere ist dann gesperrt, bis du dein Kennwort eingibst. Zurückziehen geht danach nicht mehr.">
                    <?= Csrf::field() ?><button class="btn btn-primary" type="submit">An Kunden übergeben</button></form>
            <?php endif; ?>
            <?php if ($darfOhneKunde): ?>
                <details class="mt">
                    <summary>Ohne Unterschrift des Kunden abschließen</summary>
                    <form method="post" action="/berichte/<?= $id ?>/ohne-kunde" class="bericht-ohne-kunde" data-confirm="Ohne Unterschrift des Kunden abschließen?">
                        <?= Csrf::field() ?>
                        <div class="field"><label for="ohne-art">Warum</label>
                            <select id="ohne-art" name="art" required>
                                <?php foreach (BerichtService::OHNE_KUNDE as $art => $text): ?><option value="<?= View::e($art) ?>"><?= View::e($text) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field"><label for="ohne-grund">Grund (steht im Bericht)</label>
                            <input id="ohne-grund" name="grund" required minlength="3" maxlength="500" placeholder="z. B. Kunde vor Ort nicht erreichbar, Bericht per E-Mail"></div>
                        <button class="btn" type="submit">Ohne Kunde abschließen</button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if ($zurueckziehbar): ?>
                <details class="mt">
                    <summary>Unterschrift zurückziehen</summary>
                    <p class="small muted">Nur solange der Kunde den Bericht nicht gesehen hat. Der Bericht wird wieder ein Entwurf; die Nummer
                        <?= View::e((string) $b['nummer']) ?> bleibt.</p>
                    <form method="post" action="/berichte/<?= $id ?>/zurueckziehen" class="inline-form" data-confirm="Prüfer-Unterschrift zurückziehen?">
                        <?= Csrf::field() ?>
                        <input name="grund" required minlength="3" maxlength="500" placeholder="Grund" aria-label="Grund für das Zurückziehen">
                        <button class="btn btn-sm" type="submit">Zurückziehen</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($darfKorrektur && $offeneKorrektur === null): ?>
    <details class="card bericht-aktionen">
        <summary class="card-head"><h2>Korrektur anlegen</h2><span class="hint">neue Fassung mit derselben Nummer</span></summary>
        <div class="card-body">
            <p class="small muted">Ein abgeschlossener Bericht bleibt, wie er ist. Eine Korrektur ist eine neue Fassung (<?= View::e((string) $b['nummer']) ?>-K…),
                die Prüfer und Kunde neu unterschreiben; erst dann gilt diese Fassung als ersetzt.</p>
            <form method="post" action="/berichte/<?= $id ?>/korrektur" class="inline-form" data-confirm="Korrektur zu <?= View::e((string) $b['nummer']) ?> anlegen?">
                <?= Csrf::field() ?>
                <input name="grund" required minlength="3" maxlength="500" placeholder="Grund der Korrektur" aria-label="Grund der Korrektur">
                <button class="btn" type="submit">Korrektur anlegen</button>
            </form>
        </div>
    </details>
<?php endif; ?>

<?= View::partial('berichte/_ansicht', ['bericht' => $b, 'inhalt' => $inhalt, 'bewertung' => $bewertung, 'unterschriften' => $unterschriften]) ?>

<?php if ($integritaet['eingefroren']): ?>
    <div class="card">
        <div class="card-head"><h2>Prüfnachweis</h2>
            <?php if ($integritaet['ok']): ?><span class="badge ok">✓ Integrität geprüft</span><?php else: ?><span class="badge bad">! Befund</span><?php endif; ?></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Prüfsumme Inhalt</dt><dd class="mono"><?= View::e($pruefsumme((string) $b['inhalt_hash'])) ?> …</dd>
                <?php foreach ($unterschriften as $rolle => $u): ?>
                    <dt><?= $rolle === 'pruefer' ? 'Siegel Prüfer' : 'Siegel Kunde' ?></dt>
                    <dd class="mono"><?= View::e($pruefsumme((string) $u['siegel'])) ?> · <?= View::e(Zeit::anzeige((string) $u['unterschrieben_am'])) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if (!$integritaet['ok']): ?>
                <ul class="liste-schlicht befunde small mt">
                    <?php foreach ($integritaet['befunde'] as $befund): ?><li><?= View::e($befund) ?></li><?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small muted mt mb0">Inhalt, Kette und Siegel der Unterschriften stimmen mit dem Stand der Unterschrift überein.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($ereignisse !== []): ?>
    <details class="card">
        <summary class="card-head"><h2>Verlauf</h2><span class="hint"><?= count($ereignisse) ?> Einträge</span></summary>
        <div class="card-body tight">
            <ul class="liste-schlicht small">
                <?php foreach ($ereignisse as $e): ?>
                    <li><strong><?= View::e($ereignisNamen[(string) $e['ereignis']] ?? str_replace('_', ' ', (string) $e['ereignis'])) ?></strong>
                        · <?= View::e(Zeit::anzeige((string) $e['am'])) ?> · <?= View::e((string) $e['username']) ?>
                        <?php if (($e['details']['grund'] ?? '') !== ''): ?><div class="muted">Grund: <?= View::e((string) $e['details']['grund']) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>
<?php endif; ?>

<?php if ($darfLoeschen): ?>
    <form method="post" action="/berichte/<?= $id ?>/loeschen" data-confirm="Entwurf #<?= $id ?> löschen?">
        <?= Csrf::field() ?><button class="btn btn-sm btn-ghost" type="submit">Entwurf löschen</button></form>
<?php elseif ($darfVerwerfen): ?>
    <form method="post" action="/berichte/<?= $id ?>/verwerfen" class="inline-form" data-confirm="<?= View::e((string) $b['nummer']) ?> verwerfen?">
        <?= Csrf::field() ?><input name="grund" required minlength="3" maxlength="500" placeholder="Grund" aria-label="Grund">
        <button class="btn btn-sm btn-ghost" type="submit">Verwerfen</button></form>
<?php endif; ?>
