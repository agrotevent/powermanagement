<?php
/**
 * Konto des Kundenzugangs: Angaben (nur lesen), Kennwort ändern,
 * Datenschutzhinweis (Art. 13 DSGVO, Text aus den Einstellungen).
 *
 * @var array<string,mixed> $kunde
 * @var array<string,mixed> $konto
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

    <section class="card kb-breit" id="datenschutz" aria-labelledby="kb-datenschutz-titel">
        <div class="card-head"><h2 id="kb-datenschutz-titel">Datenschutzhinweis</h2></div>
        <div class="card-body">
            <p class="kb-datenschutz"><?= View::e($datenschutz) ?></p>
            <p class="help">Wenn Sie sich auf einem gemeinsam genutzten Rechner angemeldet haben, melden Sie sich danach bitte ab.</p>
        </div>
    </section>
</div>
