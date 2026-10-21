<?php use App\Support\Csrf; use App\Support\View; ?>
<?php
/*
 * Anmeldung. Mit SSO steht oben der Knopf – ein Link, kein Formular: CSP
 * form-action 'self' gälte in Chromium sonst auch für die Weiterleitung zum
 * Anmeldedienst. Bei „nur SSO“ ist das Kennwortformular eingeklappt (für
 * Kundenzugänge und den Notzugang), ganz ohne JavaScript (<details>).
 *
 * @var ?string $sso Beschriftung („Authentik“) oder null ohne SSO
 * @var bool $nurSso
 * @var bool $lokal Kennwortformular aufgeklappt
 */
$sso = $sso ?? null;
$next = (string) ($next ?? '');
$formular = static function (bool $hauptweg) use ($next): string {
    ob_start(); ?>
        <form method="post" action="/login">
            <?= Csrf::field() ?>
            <input type="hidden" name="next" value="<?= View::e($next) ?>">
            <div class="field">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username" autocomplete="username"<?= $hauptweg ? ' autofocus' : '' ?> required>
            </div>
            <div class="field">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-block<?= $hauptweg ? ' btn-primary' : '' ?>" type="submit">Anmelden</button>
        </form>
    <?php return (string) ob_get_clean();
};
?>
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

        <?php if ($sso !== null): ?>
            <a class="btn btn-primary btn-block sso-knopf" href="/login/sso?next=<?= View::e(rawurlencode($next)) ?>">Mit <?= View::e($sso) ?> anmelden</a>
            <div class="oder">oder</div>
            <?php if (!empty($nurSso)): ?>
                <details class="lokal"<?= !empty($lokal) ? ' open' : '' ?>>
                    <summary>Mit Benutzername und Kennwort (Kunden, Notzugang)</summary>
                    <?= $formular(false) ?>
                </details>
            <?php else: ?>
                <?= $formular(false) ?>
            <?php endif; ?>
        <?php else: ?>
            <?= $formular(true) ?>
        <?php endif; ?>

        <?php if (!empty($demoMode)): ?>
            <div class="notice" style="margin-top:1rem">
                <strong>Demo-Modus.</strong> Zugang <code>admin</code> / <code>PowerAdmin2026!</code> – außerdem
                <code>veranstalter</code> / <code>Veranstalter2026!</code> (sieht nur die Festivalwoche) und
                <code>technik</code> / <code>Technik2026!!</code> (darf nur das Licht schalten).
                Kundenbereich: <code>muster-events</code> / <code>Kundenbereich2026!</code> (Sommerfestival)
                und <code>stadtmarketing</code> / <code>Stadtmarketing2026!</code> (dasselbe Gelände erst in einem Monat).
                Alle Messwerte sind erzeugt.
            </div>
        <?php endif; ?>
    </div>
</div>
