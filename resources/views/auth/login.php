<?php use App\Support\Csrf; use App\Support\View; ?>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <div class="logo"><?= View::e($appShort ?? 'PM') ?></div>
            <div>
                <strong style="font-size:1.05rem"><?= View::e($appName ?? 'PowerManagement') ?></strong>
                <div class="small muted"><?= View::e($appSubtitle ?: 'Stromverteilung & Energiedaten') ?></div>
            </div>
        </div>

        <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>

        <form method="post" action="/login">
            <?= Csrf::field() ?>
            <input type="hidden" name="next" value="<?= View::e($next ?? '') ?>">
            <div class="field">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Anmelden</button>
        </form>

        <?php if (!empty($demoMode)): ?>
            <div class="notice" style="margin-top:1rem">
                <strong>Demo-Modus.</strong> Zugang <code>admin</code> / <code>PowerAdmin2026!</code> – außerdem
                <code>veranstalter</code> / <code>Veranstalter2026!</code> (sieht nur die Festivalwoche) und
                <code>technik</code> / <code>Technik2026!!</code> (darf nur das Licht schalten).
                Alle Messwerte sind erzeugt.
            </div>
        <?php endif; ?>
    </div>
</div>
