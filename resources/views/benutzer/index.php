<?php
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Benutzer & Freigaben';
$subtitle = 'Konten, Rollen auf Zeit und Freigaben mit Zeitfenstern';
$form = $benutzer ?? ($neu ? ['id' => 0, 'username' => '', 'full_name' => '', 'email' => '', 'is_active' => 1, 'gueltig_ab' => null, 'gueltig_bis' => null, 'notizen' => ''] : null);
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-primary" href="/system/benutzer?neu=1">+ Neuer Benutzer</a>
    <span class="small muted">Was ein Konto sieht, regeln Rechte (über Rollen) und Freigaben. Beides lässt sich zeitlich begrenzen.</span>
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
        </div>
        <div>
            <h3>Rollen</h3>
            <table class="tabelle-karten">
                <thead><tr><th>Rolle</th><th>ab</th><th>bis</th></tr></thead>
                <tbody>
                <?php foreach ($rollen as $r): ?>
                    <?php $z = $zuweisungen[(int) $r['id']] ?? null; ?>
                    <tr>
                        <td data-label="Rolle"><label class="check"><input type="checkbox" name="rollen[<?= (int) $r['id'] ?>][aktiv]" value="1"<?= $z !== null ? ' checked' : '' ?>> <?= View::e($r['name']) ?></label></td>
                        <td data-label="ab"><input type="date" name="rollen[<?= (int) $r['id'] ?>][ab]" value="<?= View::e($z && $z['gueltig_ab'] ? Zeit::anzeige($z['gueltig_ab'], 'Y-m-d') : '') ?>"></td>
                        <td data-label="bis"><input type="date" name="rollen[<?= (int) $r['id'] ?>][bis]" value="<?= View::e($z && $z['gueltig_bis'] ? Zeit::anzeige($z['gueltig_bis'], 'Y-m-d') : '') ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="help">Eine Rolle mit Datum gilt nur in diesem Zeitraum (bis einschließlich des Enddatums).</p>
        </div>
    </div>
    <div class="card-footer btn-row">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="/system/benutzer">Abbrechen</a>
        <?php if ((int) $form['id'] > 0): ?><a class="btn" href="/system/freigaben?art=user&amp;id=<?= (int) $form['id'] ?>">Freigaben</a><?php endif; ?>
    </div>
</form>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Benutzer</th><th>Rollen</th><th>Gültigkeit</th><th class="num">Freigaben</th><th>Letzte Anmeldung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($alle as $b): ?>
                <tr>
                    <td data-label="Benutzer"><strong><?= View::e($b['username']) ?></strong><?= (int) $b['is_active'] === 1 ? '' : ' <span class="badge">deaktiviert</span>' ?>
                        <?= !empty($b['locked_until']) && strtotime((string) $b['locked_until']) > time() ? ' <span class="badge bad">gesperrt</span>' : '' ?>
                        <div class="small muted"><?= View::e($b['full_name']) ?></div></td>
                    <td data-label="Rollen" class="small"><?php foreach ($b['rollen'] as $r): ?>
                        <span class="badge primary"><?= View::e($r['name']) ?><?= $r['gueltig_bis'] ? ' bis ' . View::e(Zeit::anzeige($r['gueltig_bis'], 'd.m.')) : '' ?></span>
                    <?php endforeach; ?></td>
                    <td data-label="Gültigkeit" class="small"><?= $b['gueltig_ab'] || $b['gueltig_bis']
                        ? View::e(($b['gueltig_ab'] ? 'ab ' . Zeit::anzeige($b['gueltig_ab'], 'd.m.Y') . ' ' : '') . ($b['gueltig_bis'] ? 'bis ' . Zeit::anzeige($b['gueltig_bis'], 'd.m.Y H:i') : ''))
                        : 'unbefristet' ?>
                        <?= $b['zeitraum_hinweis'] !== null ? '<div class="text-warn">' . View::e($b['zeitraum_hinweis']) . '</div>' : '' ?></td>
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
