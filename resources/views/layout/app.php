<?php
/** @var string $content */
use App\Support\View;

$title    = $title ?? ($appName ?? 'PowerManagement');
$subtitle = $subtitle ?? '';
$user     = $currentUser ?? null;
$initials = $user ? strtoupper(substr((string) ($user['full_name'] ?: $user['username']), 0, 2)) : '–';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= View::e($title) ?> · <?= View::e($appName ?? 'PowerManagement') ?></title>
<?= View::partial('partials/webapp-kopf') ?>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= View::asset('/assets/css/strom.css') ?>">
<?php foreach (($stylesheets ?? []) as $css): ?>
<link rel="stylesheet" href="<?= View::asset($css) ?>">
<?php endforeach; ?>
<?php /* Der Menüzustand muss vor dem ersten Zeichnen stehen, sonst springt die Seite. */ ?>
<script nonce="<?= \App\Support\View::e(\App\Support\View::nonce()) ?>">
try {
    if (localStorage.getItem('menue') === 'schmal') {
        document.documentElement.classList.add('menue-schmal');
    }
} catch (e) { /* privater Modus: dann eben breit */ }
</script>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23b45309'/><path d='M18 3 7 18h7l-2 11 11-15h-7z' fill='white'/></svg>">
</head>
<body>
<div class="layout">
    <div class="nav-backdrop" id="nav-backdrop"></div>
    <?= View::partial('partials/nav', ['activePath' => $_SERVER['REQUEST_URI'] ?? '/']) ?>
    <script nonce="<?= \App\Support\View::e(\App\Support\View::nonce()) ?>">
    try {
        var offen = JSON.parse(localStorage.getItem('menue-gruppen') || 'null');
        if (offen !== null) {
            document.querySelectorAll('[data-nav-gruppe]').forEach(function (gruppe) {
                if (gruppe.querySelector('[data-nav-aktiv]')) { return; }
                var auf = offen.indexOf(gruppe.dataset.navGruppe) !== -1;
                gruppe.classList.toggle('offen', auf);
                var titel = gruppe.querySelector('.nav-group-title');
                if (titel) { titel.setAttribute('aria-expanded', auf ? 'true' : 'false'); }
            });
        }
    } catch (e) { /* privater Modus */ }
    </script>
    <div class="main">
        <header class="topbar">
            <button class="nav-toggle" type="button" id="nav-toggle" aria-label="Navigation anzeigen" aria-expanded="false">
                <span></span>
            </button>
            <div>
                <h1 class="page-title"><?= View::e($title) ?></h1>
                <?php if ($subtitle !== ''): ?><div class="page-sub"><?= View::e($subtitle) ?></div><?php endif; ?>
            </div>
            <div class="spacer"></div>
            <?php if (!empty($demoMode)): ?><span class="demo-flag">DEMO-MODUS</span><?php endif; ?>
            <?php $auswahl = count(\App\Services\MesspunktService::auswertung()); ?>
            <?php if ($auswahl > 0 && View::can('statistik.view')): ?>
                <a class="auswertung-zeichen" href="/statistik" title="Messgeräte in der Auswertung">
                    <span aria-hidden="true">📈</span> <span data-auswertung-zahl><?= $auswahl ?></span>
                    <span class="sr-only">Messgeräte in der Auswertung</span>
                </a>
            <?php endif; ?>
            <div class="userbox">
                <div class="avatar"><?= View::e($initials) ?></div>
                <div class="userbox-name">
                    <div style="font-weight:600"><?= View::e($user !== null ? (string) ($user['full_name'] ?: $user['username']) : '') ?></div>
                    <div class="small muted"><a href="/profil">Profil</a></div>
                </div>
                <form method="post" action="/logout" style="margin:0">
                    <?= \App\Support\Csrf::field() ?>
                    <button class="btn btn-sm btn-ghost" type="submit">Abmelden</button>
                </form>
            </div>
        </header>
        <main class="content">
            <?php if ($user !== null && \App\Auth\Auth::anmeldeweg() === 'notzugang'): ?>
                <div class="notzugang" role="alert"><strong>Angemeldet über den Notzugang</strong> – nur für den Notfall.
                    Diese Anmeldung steht im Audit und im Fehlerprotokoll; im Alltag über SSO anmelden.</div>
            <?php endif; ?>
            <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script nonce="<?= \App\Support\View::e(\App\Support\View::nonce()) ?>">window.PM_TOKEN = <?= json_encode(\App\Support\Csrf::token()) ?>;</script>
<script src="<?= View::asset('/assets/js/app.js') ?>"></script>
<?php foreach (($scripts ?? []) as $js): ?>
<script src="<?= View::asset($js) ?>"<?= str_ends_with($js, '.mjs') || str_contains($js, '/module/') ? ' type="module"' : '' ?>></script>
<?php endforeach; ?>
</body>
</html>
