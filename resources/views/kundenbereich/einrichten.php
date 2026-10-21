<?php
/**
 * Einrichtungslink des Kundenbereichs: Kennwort festlegen (Layout „blank“,
 * ohne Anmeldung, kein Skript). Für jeden ungültigen Link derselbe Text –
 * ob er falsch, abgelaufen oder schon benutzt ist, soll man nicht sehen.
 * Wer angemeldet ist, bekommt nur einen Hinweis.
 *
 * @var string $zustand formular | ungueltig | angemeldet
 * @var ?array<string,mixed> $konto
 * @var string $aktion Ziel des Formulars (dieselbe Adresse)
 * @var string $datenschutz Datenschutzhinweis als Text
 * @var string $absender Firmenname oder Titel
 */
use App\Kunden\Einrichtung;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\View;

$title = 'Kennwort festlegen';
?>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <div class="logo"><?= View::e($appShort ?? 'PM') ?></div>
            <div>
                <strong><?= View::e($absender) ?></strong>
                <div class="small muted">Kundenbereich</div>
            </div>
        </div>

        <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>

        <?php if ($zustand === 'angemeldet'): ?>
            <h2>Sie sind angemeldet</h2>
            <p>Sie sind angemeldet – bitte zuerst abmelden, dann den Link noch einmal öffnen.</p>
        <?php elseif ($zustand === 'ungueltig'): ?>
            <h2>Link nicht gültig</h2>
            <p><?= View::e(Einrichtung::UNGUELTIG) ?> Bitte wenden Sie sich an Ihren Ansprechpartner – er kann Ihnen einen neuen Link geben.</p>
            <p><a href="/login">Zur Anmeldung</a></p>
        <?php else: ?>
            <h2>Willkommen</h2>
            <p>Legen Sie hier das Kennwort für Ihren Zugang zum Kundenbereich fest. Danach melden Sie sich damit an.</p>
            <form method="post" action="<?= View::e($aktion) ?>">
                <?= Csrf::field() ?>
                <div class="field"><label for="kb-benutzer">Benutzername</label>
                    <input id="kb-benutzer" type="text" name="username" value="<?= View::e((string) $konto['username']) ?>" readonly autocomplete="username"></div>
                <div class="field"><label for="kb-neu">Neues Kennwort</label>
                    <input id="kb-neu" type="password" name="kennwort" autocomplete="new-password" required autofocus></div>
                <div class="field"><label for="kb-wiederholung">Kennwort wiederholen</label>
                    <input id="kb-wiederholung" type="password" name="wiederholung" autocomplete="new-password" required></div>
                <p class="help">Mindestens <?= (int) Config::get('security.password_min_length', 12) ?> Zeichen, mit Buchstaben und Ziffern.</p>
                <button class="btn btn-primary btn-block" type="submit">Kennwort festlegen</button>
            </form>
            <h3 class="mt">Datenschutzhinweis</h3>
            <p class="small muted"><?= nl2br(View::e($datenschutz)) ?></p>
        <?php endif; ?>
    </div>
</div>
