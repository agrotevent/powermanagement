<?php
/**
 * Entsperren des Kundenmodus – auf der Kundenseite und unter „Gerät
 * gesperrt“: das Kennwort dessen, der ihn gestartet hat, und – ist sein
 * Konto mit dem Anmeldedienst verknüpft und darf es sich dort anmelden –
 * der Link „Mit … entsperren“ (eine frische Anmeldung dort mit derselben
 * Identität, GET /login/sso/bestaetigen). Ein Konto „nur über SSO“ hat kein
 * Kennwortfeld: ein Kennwort gälte dafür nicht.
 *
 * Ein Link, kein Formular: die Weiterleitung zum Anmeldedienst ginge an CSP
 * form-action 'self' nicht vorbei.
 *
 * @var int $id
 * @var string $kennwortVon
 * @var array{name:string, link:bool, nur_sso:bool}|null $sso
 */
use App\Berichte\Kundenmodus;
use App\Support\Csrf;
use App\Support\View;

$id = (int) $id;
$sso = ($sso ?? null) ?: ['name' => '', 'link' => false, 'nur_sso' => false];
$nurSso = !empty($sso['nur_sso']);
$link = '/login/sso/bestaetigen?' . http_build_query(['zweck' => 'kundenmodus:' . $id, 'zurueck' => '/berichte/' . $id], '', '&', PHP_QUERY_RFC3986);
?>
<?php if (!$nurSso): ?>
    <form method="post" action="/berichte/<?= $id ?>/kunde/beenden" class="inline-form">
        <?= Csrf::field() ?>
        <?php /* new-password: kein automatisches Ausfüllen des gespeicherten Kennworts – sonst entsperrte ein Tipp des Kunden das Gerät. */ ?>
        <input type="password" name="kennwort" required autocomplete="new-password" aria-label="<?= View::e($kennwortVon) ?>"
               placeholder="<?= View::e($kennwortVon) ?>">
        <button class="btn" type="submit">Entsperren</button>
    </form>
<?php endif; ?>
<?php if (!empty($sso['link'])): ?>
    <?php if (!$nurSso): ?><div class="oder">oder</div><?php endif; ?>
    <a class="btn<?= $nurSso ? ' btn-primary' : '' ?> btn-block sso-knopf" href="<?= View::e($link) ?>">Mit <?= View::e((string) $sso['name']) ?> entsperren</a>
<?php elseif ($nurSso): ?>
    <p class="notice warn">Dieses Konto entsperrt nur über <?= View::e((string) $sso['name']) ?> – das geht gerade nicht
        (Anmeldung über SSO aus oder für dieses Konto nicht freigegeben). Bitte an die Verwaltung wenden.</p>
<?php endif; ?>
<p class="small muted mb0">Nach <?= Kundenmodus::HOECHSTENS_FEHL ?> Fehlversuchen wird das Entsperren für einige Minuten gebremst.</p>
