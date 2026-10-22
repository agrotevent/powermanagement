<?php
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Benutzer & Freigaben';
$subtitle = 'Konten, Rollen auf Zeit und Freigaben mit Zeitfenstern';
$form = $benutzer ?? ($neu ? ['id' => 0, 'username' => '', 'full_name' => '', 'email' => '', 'is_active' => 1, 'gueltig_ab' => null, 'gueltig_bis' => null, 'notizen' => '', 'pruefer_qualifikation' => '', 'nur_sso' => 0] : null);
/*
 * Anmeldung über SSO ($sso aus BenutzerController::index): Abzeichen in der
 * Liste, im Formular der Abschnitt mit „nur über SSO“ (das versteckte
 * nur_sso_feld sagt dem Server, dass das Häkchen da war) und – als eigenes
 * Formular außerhalb, die Knöpfe hängen über form="…" daran – Lösen und
 * SSO-Sitzungen beenden. Rollen aus Gruppen (quelle sso) sind hier nur zu
 * sehen: angehakt, gesperrt, mit Ende der Sitzung.
 */
$sso = $sso ?? ['eingerichtet' => false, 'aktiv' => false, 'name' => 'SSO', 'nur_sso' => false, 'notzugaenge' => [], 'anfragen' => 0];
$identitaet = $identitaet ?? null;
$ssoName = (string) $sso['name'];
$ssoSpalte = $sso['eingerichtet'] || array_filter($alle, static fn (array $b): bool => $b['sso'] !== null || (int) ($b['nur_sso'] ?? 0) === 1) !== [];
$ssoAbschnitt = $form !== null && ($sso['eingerichtet'] || $identitaet !== null || (int) ($form['nur_sso'] ?? 0) === 1);
$wegText = ['profil' => 'selbst im Profil', 'freischaltung' => 'Freischaltung einer Anmeldeanfrage', 'anlage' => 'automatisch angelegt', 'cli' => 'Kommandozeile'];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-primary" href="/system/benutzer?neu=1">+ Neuer Benutzer</a>
    <span class="small muted">Was ein Konto sieht, regeln Rechte (über Rollen) und Freigaben. Beides lässt sich zeitlich begrenzen.</span>
    <?php if (($kundenzugaenge ?? 0) > 0): ?>
        <span class="small muted"><?= (int) $kundenzugaenge ?> <?= (int) $kundenzugaenge === 1 ? 'Kundenzugang' : 'Kundenzugänge' ?> – nicht in dieser Liste, Kundenzugänge pflegt die Kundenverwaltung.<?= View::can('kunden.view') ? ' <a href="/kunden">Zu den Kunden</a>' : '' ?></span>
    <?php endif; ?>
    <?php if ((int) $sso['anfragen'] > 0): ?>
        <span class="small"><span class="badge warn"><?= (int) $sso['anfragen'] ?> <?= (int) $sso['anfragen'] === 1 ? 'SSO-Anmeldeanfrage' : 'SSO-Anmeldeanfragen' ?></span>
            <a href="/system/sso#anfragen">freischalten oder verwerfen</a></span>
    <?php endif; ?>
</div>

<?php if ($form !== null): ?>
<form method="post" action="/system/benutzer/speichern" class="card">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
    <div class="card-head"><h2><?= (int) $form['id'] > 0 ? View::e($form['username']) . ' bearbeiten' : 'Neuer Benutzer' ?></h2></div>
    <div class="card-body grid grid-2">
        <div>
            <div class="field-row">
                <div class="field"><label for="b-user">Benutzername</label><input id="b-user" name="username" required value="<?= View::e($form['username']) ?>" autocomplete="off"></div>
                <div class="field"><label for="b-name">Name</label><input id="b-name" name="full_name" value="<?= View::e($form['full_name']) ?>"></div>
            </div>
            <div class="field"><label for="b-mail">E-Mail</label><input id="b-mail" name="email" type="email" value="<?= View::e($form['email']) ?>"></div>
            <div class="field-row">
                <div class="field"><label for="b-pw">Passwort<?= (int) $form['id'] > 0 ? ' (leer: unverändert)' : '' ?></label><input id="b-pw" name="password" type="password" autocomplete="new-password"></div>
                <div class="field"><label class="check mt"><input type="checkbox" name="must_change_password" value="1"<?= (int) $form['id'] === 0 ? ' checked' : '' ?>> bei erster Anmeldung ändern</label></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="b-ab">Konto gültig ab</label><input id="b-ab" type="datetime-local" name="gueltig_ab" value="<?= View::e(Zeit::fuerEingabe($form['gueltig_ab'])) ?>"></div>
                <div class="field"><label for="b-bis">Konto gültig bis</label><input id="b-bis" type="datetime-local" name="gueltig_bis" value="<?= View::e(Zeit::fuerEingabe($form['gueltig_bis'])) ?>"></div>
            </div>
            <p class="help">Außerhalb dieses Zeitraums ist keine Anmeldung möglich – eine offene Sitzung endet mit.</p>
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $form['is_active'] === 1 ? ' checked' : '' ?>> Konto aktiv</label>
            <div class="field"><label for="b-notiz">Notiz</label><input id="b-notiz" name="notizen" value="<?= View::e((string) $form['notizen']) ?>"></div>
            <div class="field"><label for="b-quali">Prüfer-Qualifikation</label><input id="b-quali" name="pruefer_qualifikation" maxlength="120"
                value="<?= View::e((string) ($form['pruefer_qualifikation'] ?? '')) ?>" placeholder="z. B. Elektrofachkraft für Veranstaltungstechnik">
                <div class="help">Steht im Übergabebericht beim Prüfer. Ohne Qualifikation keine Prüfer-Unterschrift – auch mit dem Recht dazu.</div></div>
            <?php if ($ssoAbschnitt): ?>
                <h3 class="mt">Anmeldung über <?= View::e($ssoName) ?></h3>
                <?php if ($identitaet !== null): ?>
                    <p class="small">Verknüpft – <?= View::e($wegText[(string) $identitaet['weg']] ?? (string) $identitaet['weg']) ?>,
                        Name dort <strong><?= View::e((string) $identitaet['name_dort'] !== '' ? (string) $identitaet['name_dort'] : '–') ?></strong>,
                        zuletzt <?= $identitaet['zuletzt_am'] !== null ? View::e(Zeit::anzeige((string) $identitaet['zuletzt_am'])) : 'noch nie' ?>.
                        <?= $identitaet['anderer_aussteller'] ? '<span class="badge warn">anderer Aussteller</span>' : '' ?></p>
                    <div class="btn-row">
                        <button class="btn btn-sm" type="submit" form="sso-konto" name="aktion" value="sitzungen"
                                data-confirm="Die laufenden Sitzungen dieses Kontos über <?= View::e($ssoName) ?> beenden?">SSO-Sitzungen beenden</button>
                        <button class="btn btn-sm btn-ghost" type="submit" form="sso-konto" name="aktion" value="loesen"
                                data-confirm="Die Verknüpfung lösen? Sitzungen über <?= View::e($ssoName) ?> und Rollen aus Gruppen enden.">Verknüpfung lösen</button>
                    </div>
                <?php else: ?>
                    <p class="small muted">Nicht verknüpft. Verbinden geht im Profil des Kontos (mit Kennwort angemeldet) oder über eine Anmeldeanfrage unter <em>Anmeldung (SSO)</em>.</p>
                <?php endif; ?>
                <input type="hidden" name="nur_sso_feld" value="1">
                <label class="check mt"><input type="checkbox" name="nur_sso" value="1"<?= (int) ($form['nur_sso'] ?? 0) === 1 ? ' checked' : '' ?>> nur über <?= View::e($ssoName) ?> anmelden</label>
                <p class="help">Ein Kennwort gilt dann nicht (ein neues Konto braucht keins). Ausschalten nur zusammen mit einem neuen Kennwort;
                    das eigene Konto nur in einer Sitzung über <?= View::e($ssoName) ?>.</p>
                <?php if (in_array((int) $form['id'], $sso['notzugaenge'], true)): ?>
                    <p class="notice warn">Notzugang: alle Rechte, nicht verknüpft – meldet sich bei „nur SSO“ weiter mit Kennwort an.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div>
            <h3>Rollen</h3>
            <table class="tabelle-karten">
                <thead><tr><th>Rolle</th><th>ab</th><th>bis</th></tr></thead>
                <tbody>
                <?php foreach ($rollen as $r): ?>
                    <?php $z = $zuweisungen[(int) $r['id']] ?? null; $ausGruppe = $z !== null && ($z['quelle'] ?? 'hand') === 'sso'; ?>
                    <tr>
                        <td data-label="Rolle"><?php if ($ausGruppe): ?>
                            <label class="check"><input type="checkbox" checked disabled> <?= View::e($r['name']) ?></label>
                            <div class="small muted">über Gruppe bei <?= View::e($ssoName) ?><?= $z['gueltig_bis'] ? ', bis ' . View::e(Zeit::anzeige($z['gueltig_bis'])) : '' ?></div>
                        <?php else: ?>
                            <label class="check"><input type="checkbox" name="rollen[<?= (int) $r['id'] ?>][aktiv]" value="1"<?= $z !== null ? ' checked' : '' ?>> <?= View::e($r['name']) ?></label>
                        <?php endif; ?></td>
                        <?php if ($ausGruppe): ?>
                            <td data-label="ab"><input type="date" readonly value="<?= View::e($z['gueltig_ab'] ? Zeit::anzeige($z['gueltig_ab'], 'Y-m-d') : '') ?>" aria-label="ab (aus der Gruppe)"></td>
                            <td data-label="bis"><input type="date" readonly value="<?= View::e($z['gueltig_bis'] ? Zeit::anzeige($z['gueltig_bis'], 'Y-m-d') : '') ?>" aria-label="bis (aus der Gruppe)"></td>
                        <?php else: ?>
                            <td data-label="ab"><input type="date" name="rollen[<?= (int) $r['id'] ?>][ab]" value="<?= View::e($z && $z['gueltig_ab'] ? Zeit::anzeige($z['gueltig_ab'], 'Y-m-d') : '') ?>"></td>
                            <td data-label="bis"><input type="date" name="rollen[<?= (int) $r['id'] ?>][bis]" value="<?= View::e($z && $z['gueltig_bis'] ? Zeit::anzeige($z['gueltig_bis'], 'Y-m-d') : '') ?>"></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="help">Eine Rolle mit Datum gilt nur in diesem Zeitraum (bis einschließlich des Enddatums).<?= $ssoAbschnitt ? ' Rollen aus Gruppen bei ' . View::e($ssoName) . ' setzt jede Anmeldung darüber neu, eine Anmeldung mit Kennwort entfernt sie.' : '' ?></p>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="/system/benutzer">Abbrechen</a>
        <?php if ((int) $form['id'] > 0): ?><a class="btn" href="/system/freigaben?art=user&amp;id=<?= (int) $form['id'] ?>">Freigaben</a><?php endif; ?>
    </div>
</form>
<?php if ($ssoAbschnitt && $identitaet !== null): ?>
    <form method="post" action="/system/benutzer/<?= (int) $form['id'] ?>/sso" id="sso-konto"><?= Csrf::field() ?></form>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Benutzer</th><th>Rollen</th><th>Gültigkeit</th><?= $ssoSpalte ? '<th>Anmeldung</th>' : '' ?><th class="num">Freigaben</th><th>Letzte Anmeldung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($alle as $b): ?>
                <tr>
                    <td data-label="Benutzer"><strong><?= View::e($b['username']) ?></strong><?= (int) $b['is_active'] === 1 ? '' : ' <span class="badge">deaktiviert</span>' ?>
                        <?= !empty($b['locked_until']) && strtotime((string) $b['locked_until']) > time() ? ' <span class="badge bad">gesperrt</span>' : '' ?>
                        <div class="small muted"><?= View::e($b['full_name']) ?></div></td>
                    <td data-label="Rollen" class="small"><?php foreach ($b['rollen'] as $r): ?>
                        <span class="badge <?= ($r['quelle'] ?? 'hand') === 'sso' ? 'info' : 'primary' ?>"><?= View::e($r['name']) ?><?= ($r['quelle'] ?? 'hand') === 'sso' ? ' · Gruppe' : '' ?><?= $r['gueltig_bis'] ? ' bis ' . View::e(Zeit::anzeige($r['gueltig_bis'], 'd.m.')) : '' ?></span>
                    <?php endforeach; ?></td>
                    <td data-label="Gültigkeit" class="small"><?= $b['gueltig_ab'] || $b['gueltig_bis']
                        ? View::e(($b['gueltig_ab'] ? 'ab ' . Zeit::anzeige($b['gueltig_ab'], 'd.m.Y') . ' ' : '') . ($b['gueltig_bis'] ? 'bis ' . Zeit::anzeige($b['gueltig_bis'], 'd.m.Y H:i') : ''))
                        : 'unbefristet' ?>
                        <?= $b['zeitraum_hinweis'] !== null ? '<div class="text-warn">' . View::e($b['zeitraum_hinweis']) . '</div>' : '' ?></td>
                    <?php if ($ssoSpalte): ?>
                        <td data-label="Anmeldung" class="small"><?php
                            $nurSso = (int) ($b['nur_sso'] ?? 0) === 1 || ($sso['nur_sso'] && $b['sso'] !== null && !$b['sso']['anderer_aussteller']);
                            if ($nurSso) {
                                echo '<span class="badge info">nur SSO</span>';
                            } elseif ($b['sso'] !== null) {
                                echo '<span class="badge">Kennwort</span> <span class="badge ' . ($b['sso']['anderer_aussteller'] ? 'warn' : 'info') . '">SSO</span>';
                            } else {
                                echo '<span class="badge">Kennwort</span>';
                            }
                            if (in_array((int) $b['id'], $sso['notzugaenge'], true)) {
                                echo ' <span class="badge warn">Notzugang</span>';
                            }
                            if ($b['sso'] !== null && (string) $b['sso']['name_dort'] !== '') {
                                echo '<div class="muted">dort: ' . View::e((string) $b['sso']['name_dort']) . '</div>';
                            }
                        ?></td>
                    <?php endif; ?>
                    <td data-label="Freigaben" class="num"><a href="/system/freigaben?art=user&amp;id=<?= (int) $b['id'] ?>"><?= (int) $b['freigaben'] ?></a></td>
                    <td data-label="Letzte Anmeldung" class="small"><?= View::e(Zeit::anzeige($b['last_login_at'])) ?></td>
                    <td class="right nowrap">
                        <a class="btn btn-sm" href="/system/benutzer?id=<?= (int) $b['id'] ?>">Bearbeiten</a>
                        <?php if (!empty($b['locked_until'])): ?>
                            <form method="post" action="/system/benutzer/entsperren" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-sm" type="submit">Entsperren</button></form>
                        <?php endif; ?>
                        <form method="post" action="/system/benutzer/loeschen" style="display:inline" data-confirm="Benutzer <?= View::e($b['username']) ?> löschen?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Löschen</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
