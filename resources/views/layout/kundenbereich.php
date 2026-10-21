<?php
/**
 * Layout des Kundenbereichs – für Kundenzugänge (users.kunde_id), nie das
 * des Personals: kein partials/nav, kein Profil, keine Auswertung, kein
 * Inline-Skript. Auch die Fehlerseiten eines Kundenzugangs stehen hier
 * (Response::fehlerSeite). Abmelden ist ein POST mit CSRF-Token.
 *
 * Im Kundenbereich durchgehend „Sie“.
 *
 * @var string $content
 * @var array<string,mixed>|null $kunde
 */
use App\Berichte\Einstellungen;
use App\Kunden\Kundenbereich;
use App\Support\Csrf;
use App\Support\View;

$kunde = $kunde ?? Kundenbereich::kunde();
$firma = Einstellungen::wert('firma.name');
$absender = $firma !== '' ? $firma : (string) ($appName ?? 'PowerManagement');
$pfad = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$navigation = [
    Kundenbereich::PFAD  => 'Übersicht',
    Kundenbereich::KONTO => 'Konto',
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= View::e(($title ?? 'Kundenbereich') . ' · ' . $absender) ?></title>
<?= View::partial('partials/webapp-kopf') ?>
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= View::asset('/assets/css/strom.css') ?>">
<link rel="stylesheet" href="<?= View::asset('/assets/css/kundenbereich.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23b45309'/><path d='M18 3 7 18h7l-2 11 11-15h-7z' fill='white'/></svg>">
</head>
<body class="kundenbereich">
<header class="kunden-kopf kb-kopf">
    <div>
        <strong><?= View::e($absender) ?></strong>
        <span class="kb-unterzeile">Kundenbereich<?= $kunde !== null ? ' · ' . View::e((string) $kunde['name']) : '' ?></span>
    </div>
    <?php if (!empty($demoMode)): ?><span class="demo-flag">DEMO-MODUS</span><?php endif; ?>
</header>
<nav class="kb-nav" aria-label="Kundenbereich">
    <?php foreach ($navigation as $ziel => $text): ?>
        <a href="<?= View::e($ziel) ?>"<?= $pfad === $ziel ? ' aria-current="page"' : '' ?>><?= View::e($text) ?></a>
    <?php endforeach; ?>
    <form method="post" action="/logout" class="kb-abmelden">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-ghost" type="submit">Abmelden</button>
    </form>
</nav>
<main class="kunden-inhalt">
    <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
    <?= $content ?>
</main>
<script src="<?= View::asset('/assets/js/app.js') ?>"></script>
</body>
</html>
