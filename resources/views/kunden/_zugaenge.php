<?php
/**
 * Reiter Zugänge (nur mit „kunden.manage“): die Zugänge des Kunden mit
 * Zustand, Bearbeiten in <details>, und „Neuer Zugang“ über
 * Einrichtungslink (Vorgabe, nur mit gültiger app.url) oder vorläufiges
 * Kennwort. Kein Geheimnis steht je auf dieser Seite – nur in der Antwort
 * auf das Anlegen (kunden/einmalig).
 *
 * Anmeldung über SSO (017): Zugänge aus SSO tragen das Abzeichen „über …“
 * und haben weder Einrichtungslink noch Kennwort; die Kundengruppen dieses
 * Kunden stehen darunter (ändern nur mit „SSO einrichten“, über die Routen
 * unter /system/sso – zurück geht es hierher).
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $zugaenge KundenService::zugaenge()
 * @var string $einmal Einmal-Marke des Formulars „Neuer Zugang“
 * @var ?string $adresse KundenEinstellungen::adresse()
 * @var bool $darfAusgeben KundenService::darfZugangAusgeben() – sonst kein neuer Zugang, Link oder Kennwort
 * @var array<string,mixed> $eingabe
 * @var array{zeigen:bool,name:string,an:bool,kunden:bool,gruppen:list<array<string,mixed>>,pflegen:bool} $sso
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
$sso = ($sso ?? null) ?: ['zeigen' => false, 'name' => 'SSO', 'an' => false, 'kunden' => false, 'gruppen' => [], 'pflegen' => false];
$ssoName = (string) $sso['name'];
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
                <?php $uid = (int) $z['id']; $bis = Zeit::anzeige($z['gueltig_bis'] ?? null, 'Y-m-d'); $nurSso = (int) ($z['nur_sso'] ?? 0) === 1; ?>
                <tr id="zugang-<?= $uid ?>">
                    <td data-label="Benutzername"><span class="mono"><?= View::e((string) $z['username']) ?></span>
                        <?php if ($nurSso || $z['sso'] !== null): ?>
                            <span class="badge info">über <?= View::e($ssoName) ?></span>
                            <?php if ($z['sso'] !== null && (string) $z['sso']['name_dort'] !== ''): ?><div class="small muted">dort: <?= View::e((string) $z['sso']['name_dort']) ?></div><?php endif; ?>
                        <?php endif; ?></td>
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
                                    <?php if ($kundeOffen && $darfAusgeben && (int) $z['is_active'] === 1 && !$nurSso): ?>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="link"
                                                data-confirm="Neuen Einrichtungslink erzeugen? Ein früherer gilt dann nicht mehr.">Neuer Einrichtungslink</button>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="kennwort"
                                                data-confirm="Neues vorläufiges Kennwort setzen? Das bisherige Kennwort und offene Sitzungen enden.">Vorläufiges Kennwort</button>
                                    <?php endif; ?>
                                    <?php if ($z['sso'] !== null): ?>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="sso_sitzungen"
                                                data-confirm="Die Sitzungen von „<?= View::e((string) $z['username']) ?>“ über <?= View::e($ssoName) ?> beenden?">Sitzungen über <?= View::e($ssoName) ?> beenden</button>
                                    <?php endif; ?>
                                    <?php if ($z['gesperrt']): ?>
                                        <button class="btn btn-sm" type="submit" name="aktion" value="entsperren">Entsperren</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-ghost" type="submit" name="aktion" value="loeschen"
                                            data-confirm="Zugang „<?= View::e((string) $z['username']) ?>“ löschen?">Löschen</button>
                                </div>
                                <?php if ($nurSso): ?>
                                    <p class="help mb0">Meldet sich nur über <?= View::e($ssoName) ?> an<?= $z['sso'] !== null && $z['sso']['zuletzt_am'] !== null
                                        ? ', zuletzt ' . View::e(Zeit::anzeige((string) $z['sso']['zuletzt_am'])) : '' ?> – ohne Einrichtungslink und Kennwort.
                                        Gehört die Person zu einem anderen Kunden, den Zugang löschen: die nächste Anmeldung legt ihn dort an.</p>
                                <?php endif; ?>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($sso['zeigen']): ?>
<div class="card mt" id="sso-gruppen">
    <div class="card-head"><h2>Anmeldung über <?= View::e($ssoName) ?></h2><span class="hint">Kundengruppen</span></div>
    <div class="card-body">
        <p class="small muted mb0">Wer bei <?= View::e($ssoName) ?> in einer dieser Gruppen ist (und in keiner Personalgruppe), bekommt bei der ersten
            Anmeldung selbst einen Zugang dieses Kunden – ohne Kennwort, ohne Rolle, er sieht, was dem Kunden freigegeben ist.
            Zugänge mit Kennwort werden nie mit einer Anmeldung bei <?= View::e($ssoName) ?> verknüpft.</p>
        <?php if (!$sso['kunden']): ?>
            <p class="notice mt mb0">Kundenzugänge über <?= View::e($ssoName) ?> sind aus<?= $sso['pflegen'] ? ' – einschalten unter <a href="/system/sso#kunden">System → Anmeldung (SSO)</a>' : '' ?>.
                <?= $sso['gruppen'] !== [] ? 'Die Gruppen unten wirken erst dann.' : '' ?></p>
        <?php elseif (!$sso['an']): ?>
            <p class="notice mt mb0">Die Anmeldung über <?= View::e($ssoName) ?> ist aus – die Gruppen wirken erst, wenn sie eingeschaltet ist.</p>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Gruppe</th><th>seit</th><th>von</th><th></th></tr></thead>
            <tbody>
            <?php if ($sso['gruppen'] === []): ?><tr><td colspan="4" class="muted">Keine Kundengruppe.</td></tr><?php endif; ?>
            <?php foreach ($sso['gruppen'] as $g): ?>
                <tr>
                    <td data-label="Gruppe"><code><?= View::e((string) $g['gruppe']) ?></code></td>
                    <td data-label="seit" class="small"><?= View::e(Zeit::anzeige((string) $g['created_at'], 'd.m.Y')) ?></td>
                    <td data-label="von" class="small"><?= View::e((string) $g['created_by']) ?></td>
                    <td class="right">
                        <?php if ($sso['pflegen']): ?>
                            <form method="post" action="/system/sso/zuordnung/<?= (int) $g['id'] ?>/loeschen"
                                  data-confirm="Gruppe <?= View::e((string) $g['gruppe']) ?> entfernen? Die Sitzungen der Zugänge dieses Kunden über <?= View::e($ssoName) ?> enden.">
                                <?= Csrf::field() ?><input type="hidden" name="zurueck" value="kunde">
                                <button class="btn btn-sm btn-ghost" type="submit">Entfernen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php // Darunter nur, wenn es etwas zu sagen gibt – sind Kundenzugänge über Gruppen aus, steht das schon oben. ?>
    <?php if (!$sso['pflegen'] || $sso['kunden']): ?>
    <div class="card-body">
        <?php if (!$sso['pflegen']): ?>
            <p class="muted mb0">Kundengruppen ordnet zu, wer die Anmeldung über SSO einrichten darf.</p>
        <?php elseif ($kundeOffen && $darfAusgeben): ?>
            <form method="post" action="/system/sso/zuordnung">
                <?= Csrf::field() ?>
                <input type="hidden" name="art" value="kunde">
                <input type="hidden" name="ziel_id" value="<?= $id ?>">
                <input type="hidden" name="zurueck" value="kunde">
                <div class="field"><label for="sso-kundengruppe">Gruppe bei <?= View::e($ssoName) ?></label>
                    <input id="sso-kundengruppe" name="gruppe" maxlength="150" required spellcheck="false" autocapitalize="off" placeholder="pm-kunde-…"></div>
                <button class="btn btn-primary" type="submit">Gruppe zuordnen</button>
                <p class="help mt mb0">Genau so, wie <?= View::e($ssoName) ?> die Gruppe nennt. Eine Gruppe gehört zu genau einem Kunden – und ist nie zugleich eine Personalgruppe.</p>
            </form>
        <?php elseif (!$kundeOffen): ?>
            <p class="muted mb0">Neue Kundengruppen gibt es erst, wenn der Kunde wieder aktiv ist.</p>
        <?php else: ?>
            <p class="muted mb0">Kundengruppen ordnet zu, wer selbst alles sieht, was der Kunde sieht – wie beim Zugang von Hand.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$kundeOffen): ?>
    <p class="notice warn mt">Der Kunde ist nicht aktiv – neue Zugänge, Links und Kennwörter gibt es erst, wenn er wieder aktiv ist (Reiter „Stammdaten“).</p>
<?php elseif (!$darfAusgeben): ?>
    <p class="notice mt">Neue Zugänge, Einrichtungslinks und vorläufige Kennwörter gibt nur aus, wer selbst alles sieht, was der Kunde sieht
        (Recht „Alle Standorte, Anlagen und Messdaten“, dazu die Protokolle des Kunden) – ein Zugang erbt alles, was dem Kunden freigegeben ist.</p>
<?php else: ?>
<form method="post" action="/kunden/<?= $id ?>/zugang" class="card mt kunden-formular" id="neuer-zugang">
    <?= Csrf::field() ?>
    <input type="hidden" name="einmal" value="<?= View::e($einmal) ?>">
    <div class="card-head"><h2>Neuer Zugang</h2></div>
    <div class="card-body">
        <?php if ($sso['an'] && $sso['gruppen'] !== []): ?>
            <p class="notice">Wer bei <?= View::e($ssoName) ?> in einer Kundengruppe dieses Kunden ist, bekommt seinen Zugang bei der ersten Anmeldung selbst –
                ein Zugang hier ist nur für Personen ohne Konto bei <?= View::e($ssoName) ?> (er wird nie mit einer Anmeldung dort verknüpft).</p>
        <?php endif; ?>
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
