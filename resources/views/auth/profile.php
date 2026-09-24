<?php
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Mein Konto';
$subtitle = 'Rollen, Freigaben und Passwort';
?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Mein Konto</h2></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Benutzername</dt><dd><?= View::e($currentUser['username']) ?></dd>
                <dt>Name</dt><dd><?= View::e($currentUser['full_name'] ?: '–') ?></dd>
                <dt>Zugang gültig</dt>
                <dd>
                    <?php if (empty($currentUser['gueltig_ab']) && empty($currentUser['gueltig_bis'])): ?>
                        unbefristet
                    <?php else: ?>
                        <?= !empty($currentUser['gueltig_ab']) ? 'ab ' . View::e(Zeit::anzeige($currentUser['gueltig_ab'])) : '' ?>
                        <?= !empty($currentUser['gueltig_bis']) ? ' bis ' . View::e(Zeit::anzeige($currentUser['gueltig_bis'])) . ' (' . View::e(Zeit::abstand($currentUser['gueltig_bis'])) . ')' : '' ?>
                    <?php endif; ?>
                </dd>
                <dt>Letzte Anmeldung</dt><dd><?= View::e(Zeit::anzeige($currentUser['last_login_at'])) ?></dd>
                <dt>Rollen</dt>
                <dd>
                    <?php foreach ($roles as $role): ?>
                        <span class="badge primary"><?= View::e($role['name']) ?><?= !empty($role['gueltig_bis']) ? ' bis ' . View::e(Zeit::anzeige($role['gueltig_bis'], 'd.m.Y')) : '' ?></span>
                    <?php endforeach; ?>
                </dd>
            </dl>

            <h3 class="mt">Was ich sehen darf</h3>
            <?php if ($alles): ?>
                <p>Alle Standorte und Anlagen, Messdaten ohne Zeitgrenze.</p>
            <?php elseif ($freigaben === []): ?>
                <p class="muted">Keine wirksame Freigabe – es sind keine Anlagen sichtbar.</p>
            <?php else: ?>
                <ul class="liste-schlicht">
                <?php foreach ($freigaben as $f): ?>
                    <li>
                        <strong><?= View::e($f['ziel_name']) ?></strong>
                        <?= (int) $f['darf_bearbeiten'] === 1 ? '<span class="badge info">bearbeiten</span>' : '' ?>
                        <?= (int) $f['darf_steuern'] === 1 ? '<span class="badge warn">schalten</span>' : '' ?>
                        <div class="small muted">
                            gültig <?= $f['gueltig_bis'] ? 'bis ' . View::e(Zeit::anzeige($f['gueltig_bis'])) : 'unbefristet' ?> ·
                            Daten <?= $f['daten_ab'] || $f['daten_bis']
                                ? View::e(($f['daten_ab'] ? 'ab ' . Zeit::anzeige($f['daten_ab']) : '') . ($f['daten_bis'] ? ' bis ' . Zeit::anzeige($f['daten_bis']) : ''))
                                : 'ohne Zeitgrenze' ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Passwort ändern</h2></div>
        <div class="card-body">
            <form method="post" action="/profil/passwort">
                <?= Csrf::field() ?>
                <div class="field">
                    <label>Aktuelles Passwort</label>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label>Neues Passwort</label>
                    <input type="password" name="new_password" autocomplete="new-password" required>
                    <div class="help">Mindestens 12 Zeichen, Buchstaben und Ziffern.</div>
                </div>
                <div class="field">
                    <label>Neues Passwort wiederholen</label>
                    <input type="password" name="new_password_repeat" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Passwort speichern</button>
            </form>
        </div>
    </div>
</div>
