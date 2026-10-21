<?php use App\Support\View; ?>
<?php
/*
 * Nach dem Abmelden einer Sitzung über SSO – einmal (der Hinweis ist danach
 * aus der Sitzung): hier abgemeldet, beim Anmeldedienst womöglich nicht.
 * „Auch bei … abmelden“ ist ein Link, keine Weiterleitung; sein Ziel kommt
 * nur aus der geprüften Discovery (Anbieter::abmeldeAdresse), nie aus der
 * Anfrage. Die Seite hat no-store und no-referrer (SsoController).
 */
?>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <div class="logo"><?= View::e($appShort ?? 'PM') ?></div>
            <div>
                <strong><?= View::e($appName ?? 'PowerManagement') ?></strong>
                <div class="small muted"><?= View::e($appSubtitle ?: 'Stromverteilung & Energiedaten') ?></div>
            </div>
        </div>

        <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>

        <p>Sie sind hier abgemeldet. Bei <?= View::e($name) ?> sind Sie womöglich noch angemeldet – auf einem geteilten Gerät dort auch abmelden.</p>
        <p><a class="btn btn-primary btn-block" href="<?= View::e($adresse) ?>" rel="noreferrer">Auch bei <?= View::e($name) ?> abmelden</a></p>
        <p><a class="btn btn-block" href="/login">Zur Anmeldung</a></p>
    </div>
</div>
