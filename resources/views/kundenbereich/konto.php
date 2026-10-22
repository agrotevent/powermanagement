<?php
/**
 * Konto des Kundenzugangs: Angaben (nur lesen), Kennwort ändern – bei einem
 * Zugang aus der Anmeldung über SSO stattdessen „Anmeldung über …“ (sein
 * Kennwort gibt es nur beim Anmeldedienst) –, Datenschutzhinweis (Art. 13
 * DSGVO, Text aus den Einstellungen).
 *
 * @var array<string,mixed> $kunde
 * @var array<string,mixed> $konto
 * @var array{name:string, identitaet:?array<string,mixed>}|null $sso nur bei einem Zugang „nur über SSO“
 * @var string $datenschutz KundenEinstellungen::datenschutz() – als Text
 */
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$title = 'Konto';
$mussAendern = (int) ($konto['must_change_password'] ?? 0) === 1;
?>
<h1 class="kb-titel">Konto</h1>
<?php /* Der eine Hinweis zum vorläufigen Kennwort – Anmeldung und Tor schicken nur hierher, ohne eigene Meldung. */ ?>
<?php if ($mussAendern): ?><p class="notice warn">Bitte legen Sie zuerst ein eigenes Kennwort fest – vorher öffnen sich die anderen Seiten nicht.</p><?php endif; ?>
<div class="kb-karten">
    <section class="card">
        <div class="card-head"><h2>Ihr Zugang</h2></div>
        <div class="card-body">
            <dl class="kb-felder">
                <dt>Benutzername</dt><dd><?= View::e((string) $konto['username']) ?></dd>
                <?php if ((string) $konto['full_name'] !== ''): ?><dt>Name</dt><dd><?= View::e((string) $konto['full_name']) ?></dd><?php endif; ?>
                <?php if ((string) $konto['email'] !== ''): ?><dt>E-Mail</dt><dd><?= View::e((string) $konto['email']) ?></dd><?php endif; ?>
                <dt>Kunde</dt><dd><?= View::e((string) $kunde['name']) ?></dd>
                <?php if (!empty($konto['gueltig_bis'])): ?><dt>Gültig bis</dt><dd><?= View::e(Zeit::anzeige((string) $konto['gueltig_bis'])) ?></dd><?php endif; ?>
                <dt>Letzte Anmeldung</dt><dd><?= View::e(Zeit::anzeige($konto['last_login_at'] ?? null)) ?></dd>
            </dl>
            <p class="help">Name und E-Mail-Adresse ändern wir für Sie – sprechen Sie uns an.</p>
        </div>
    </section>

    <?php if (!empty($sso)): ?>
    <section class="card">
        <div class="card-head"><h2>Anmeldung über <?= View::e((string) $sso['name']) ?></h2></div>
        <div class="card-body">
            <p>Sie melden sich über <?= View::e((string) $sso['name']) ?> an. Ein Kennwort gibt es hier nicht – Ihr Kennwort ändern Sie dort.</p>
            <?php if (!empty($sso['identitaet'])): ?>
                <dl class="kb-felder">
                    <dt>Verbunden seit</dt><dd><?= View::e(Zeit::anzeige((string) $sso['identitaet']['angelegt_am'])) ?></dd>
                    <?php if ((string) $sso['identitaet']['name_dort'] !== ''): ?><dt>Name dort</dt><dd><?= View::e((string) $sso['identitaet']['name_dort']) ?></dd><?php endif; ?>
                    <dt>Letzte Anmeldung dort</dt><dd><?= View::e(Zeit::anzeige($sso['identitaet']['zuletzt_am'] ?? null)) ?></dd>
                </dl>
            <?php endif; ?>
            <p class="help">Beim Abmelden können Sie sich auch bei <?= View::e((string) $sso['name']) ?> abmelden – auf einem gemeinsam genutzten Gerät bitte immer.</p>
        </div>
    </section>
    <?php else: ?>
    <form method="post" action="/kundenbereich/konto/kennwort" class="card kb-formular">
        <?= Csrf::field() ?>
        <div class="card-head"><h2>Kennwort ändern</h2></div>
        <div class="card-body">
            <div class="field"><label for="kb-aktuell">Aktuelles Kennwort</label>
                <input id="kb-aktuell" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label for="kb-neu">Neues Kennwort</label>
                <input id="kb-neu" name="new_password" type="password" autocomplete="new-password" required></div>
            <div class="field"><label for="kb-wiederholung">Neues Kennwort wiederholen</label>
                <input id="kb-wiederholung" name="new_password_repeat" type="password" autocomplete="new-password" required></div>
            <p class="help">Mindestens <?= (int) \App\Support\Config::get('security.password_min_length', 12) ?> Zeichen, mit Buchstaben und Ziffern.</p>
            <button class="btn btn-primary" type="submit">Kennwort ändern</button>
        </div>
    </form>
    <?php endif; ?>

    <section class="card kb-breit" id="datenschutz" aria-labelledby="kb-datenschutz-titel">
        <div class="card-head"><h2 id="kb-datenschutz-titel">Datenschutzhinweis</h2></div>
        <div class="card-body">
            <p class="kb-datenschutz"><?= View::e($datenschutz) ?></p>
            <p class="help">Wenn Sie sich auf einem gemeinsam genutzten Rechner angemeldet haben, melden Sie sich danach bitte ab.</p>
        </div>
    </section>
</div>
