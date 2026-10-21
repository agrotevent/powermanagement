<?php
/**
 * Reiter Zugänge (nur mit „kunden.manage“): die Zugänge des Kunden mit
 * Zustand, Bearbeiten in <details>, und „Neuer Zugang“ über
 * Einrichtungslink (Vorgabe, nur mit gültiger app.url) oder vorläufiges
 * Kennwort. Kein Geheimnis steht je auf dieser Seite – nur in der Antwort
 * auf das Anlegen (kunden/einmalig).
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $zugaenge KundenService::zugaenge()
 * @var string $einmal Einmal-Marke des Formulars „Neuer Zugang“
 * @var ?string $adresse KundenEinstellungen::adresse()
 * @var array<string,mixed> $eingabe
 */
use App\Kunden\Kundenbereich;
use App\Kunden\KundenService;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$e = ($eingabe['form'] ?? '') === 'zugang' ? $eingabe : [];
$weg = (string) ($e['weg'] ?? ($adresse !== null ? 'link' : 'kennwort'));
$kundeOffen = Kundenbereich::kundeSperre($id) === null;
$abzeichen = [
    'deaktiviert' => 'bad', 'gesperrt' => 'bad', 'abgelaufen' => 'warn', 'link_offen' => 'info',
    'link_abgelaufen' => 'warn', 'erstanmeldung' => 'info', 'aktiv' => 'ok',
];
?>
<div class="card">
    <div class="card-head"><h2>Zugänge</h2><span class="hint">je Person ein eigener Zugang</span></div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Benutzername</th><th>Name / E-Mail</th><th>Zustand</th><th>Letzte Anmeldung</th><th></th></tr></thead>
            <tbody>
            <?php if ($zugaenge === []): ?>
                <tr><td colspan="5" class="muted">Noch kein Zugang – unten anlegen.</td></tr>
            <?php endif; ?>
            <?php foreach ($zugaenge as $z): ?>
                <?php $uid = (int) $z['id']; $bis = Zeit::anzeige($z['gueltig_bis'] ?? null, 'Y-m-d'); ?>
                <tr id="zugang-<?= $uid ?>">
                    <td data-label="Benutzername" class="mono"><?= View::e((string) $z['username']) ?></td>
                    <td data-label="Name / E-Mail"><?= View::e((string) $z['full_name'] !== '' ? (string) $z['full_name'] : '–') ?>
                        <?php if ((string) $z['email'] !== ''): ?><div class="small muted"><?= View::e((string) $z['email']) ?></div><?php endif; ?></td>
                    <td data-label="Zustand"><span class="badge <?= View::e($abzeichen[$z['zustand']] ?? '') ?>"><?= View::e(KundenService::ZUSTAENDE[$z['zustand']] ?? (string) $z['zustand']) ?></span>
                        <?php if ($z['zustand'] === 'link_offen'): ?><div class="small muted">bis <?= View::e(Zeit::anzeige((string) $z['einrichtung_bis'])) ?></div><?php endif; ?>
                        <?php if ($z['zeitraum'] !== null): ?><div class="small muted"><?= View::e((string) $z['zeitraum']) ?></div><?php endif; ?></td>
                    <td data-label="Letzte Anmeldung" class="small"><?= View::e(Zeit::anzeige($z['last_login_at'] ?? null)) ?></td>
                    <td class="right">
                        <details class="zugang-bearbeiten">
                            <summary class="btn btn-sm">Bearbeiten</summary>
                            <form method="post" action="/kunden/<?= $id ?>/zugang/<?= $uid ?>" class="mt">
                                <?= Csrf::field() ?>
                                <div class="field"><label for="z<?= $uid ?>-name">Name</label>
                                    <input id="z<?= $uid ?>-name" name="full_name" maxlength="190" value="<?= View::e((string) $z['full_name']) ?>"></div>
                                <div class="field"><label for="z<?= $uid ?>-mail">E-Mail</label>
                                    <input id="z<?= $uid ?>-mail" name="email" type="email" maxlength="190" value="<?= View::e((string) $z['email']) ?>"></div>
                                <div class="field"><label for="z<?= $uid ?>-bis">Zugang endet am</label>
                                    <input id="z<?= $uid ?>-bis" name="gueltig_bis" type="date" value="<?= View::e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) === 1 ? $bis : '') ?>"></div>
                                <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $z['is_active'] === 1 ? ' checked' : '' ?>> aktiv</label>
                                <div class="btn-row mt">
                                    <button class="btn btn-sm btn-primary" type="submit" name="aktion" value="speichern">Speichern</button>
                                    <?php if ($kundeOffen && (int) $z['is_active'] === 1): ?>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="link"
                                                data-confirm="Neuen Einrichtungslink erzeugen? Ein früherer gilt dann nicht mehr.">Neuer Einrichtungslink</button>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="kennwort"
                                                data-confirm="Neues vorläufiges Kennwort setzen? Das bisherige Kennwort und offene Sitzungen enden.">Vorläufiges Kennwort</button>
                                    <?php endif; ?>
                                    <?php if ($z['gesperrt']): ?>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="entsperren">Entsperren</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-ghost" type="submit" name="aktion" value="loeschen"
                                            data-confirm="Zugang „<?= View::e((string) $z['username']) ?>“ löschen?">Löschen</button>
                                </div>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$kundeOffen): ?>
    <p class="notice warn mt">Der Kunde ist nicht aktiv – neue Zugänge, Links und Kennwörter gibt es erst, wenn er wieder aktiv ist (Reiter „Stammdaten“).</p>
<?php else: ?>
<form method="post" action="/kunden/<?= $id ?>/zugang" class="card mt kunden-formular" id="neuer-zugang">
    <?= Csrf::field() ?>
    <input type="hidden" name="einmal" value="<?= View::e($einmal) ?>">
    <div class="card-head"><h2>Neuer Zugang</h2></div>
    <div class="card-body">
        <div class="field-row">
            <div class="field"><label for="nz-name">Benutzername</label>
                <input id="nz-name" name="username" required pattern="[A-Za-z0-9._@\-]{2,64}" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false"
                       value="<?= View::e((string) ($e['username'] ?? '')) ?>" placeholder="z. B. vorname.nachname">
                <div class="help">2–64 Zeichen: Buchstaben, Ziffern und . _ @ - (nicht doppelt, auch nicht in anderer Schreibweise).</div></div>
            <div class="field"><label for="nz-voll">Name</label>
                <input id="nz-voll" name="full_name" maxlength="190" value="<?= View::e((string) ($e['full_name'] ?? '')) ?>"></div>
        </div>
        <div class="field"><label for="nz-mail">E-Mail</label>
            <input id="nz-mail" name="email" type="email" maxlength="190" value="<?= View::e((string) ($e['email'] ?? '')) ?>"></div>
        <fieldset><legend>Wie richtet der Kunde sein Kennwort ein?</legend>
            <label class="check"><input type="radio" name="weg" value="link"<?= $weg === 'link' ? ' checked' : '' ?><?= $adresse === null ? ' disabled' : '' ?>>
                <span>Einrichtungslink (gilt einmal, <?= (int) \App\Kunden\KundenEinstellungen::einrichtungStunden() ?> Stunden)</span></label>
            <label class="check"><input type="radio" name="weg" value="kennwort"<?= $weg === 'kennwort' || $adresse === null ? ' checked' : '' ?>>
                <span>Vorläufiges Kennwort (bei der ersten Anmeldung ändern)</span></label>
            <?php if ($adresse === null): ?>
                <p class="help">Für Einrichtungslinks fehlt in <code>config/config.php</code> eine gültige <code>app.url</code> (die Adresse dieser Oberfläche).</p>
            <?php endif; ?>
        </fieldset>
        <p class="help">Link oder Kennwort stehen <strong>nur einmal</strong> auf der nächsten Seite – nicht per unverschlüsselter Mail weitergeben.</p>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">Zugang anlegen</button></div>
</form>
<?php endif; ?>
