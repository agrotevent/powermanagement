<?php
/**
 * Layout des Kundenbereichs – für Kundenzugänge (users.kunde_id), nie das
 * des Personals: kein partials/nav, kein Profil, keine Auswertung, kein
 * Inline-Skript. Auch die Fehlerseiten eines Kundenzugangs stehen hier
 * (Response::fehlerSeite). Abmelden ist ein POST mit CSRF-Token.
 *
 * Mobil zuerst: am Telefon ist die Navigation eine feste Leiste unten (für
 * den Daumen, Ziele ≥ 48 px, über dem Home-Balken), ab 720 px Reiter oben.
 * Keine Bedienung nur über Hover. Im Kundenbereich durchgehend „Sie“.
 *
 * Neue Seiten (Prüfnachweise) kommen in $navigation dazu.
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

/*
 * Ziel => [Wort, Symbol (ein Pfad im 24er-Raster, nur Striche)]. Aktiv ist
 * ein Eintrag auf seiner Seite und auf allem darunter (eine Location →
 * „Locations“), die Übersicht nur auf sich selbst – auf einer Fehlerseite
 * keiner: ihr Rumpf ist für jede Adresse derselbe (fremd = nicht vorhanden).
 */
$navigation = [
    Kundenbereich::PFAD                => ['Übersicht', 'M3 11 12 4l9 7M5 10v10h5v-6h4v6h5V10'],
    Kundenbereich::PFAD . '/locations' => ['Locations', 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21zM12 12.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'],
    Kundenbereich::PFAD . '/protokolle' => ['Protokolle', 'M7 3h7l5 5v13H7zM14 3v5h5M10 12.5h6M10 16.5h6'],
    Kundenbereich::KONTO               => ['Konto', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4.4 3.6-7 8-7s8 2.6 8 7'],
];
$fehlerseite = !empty($fehlerseite);
$aktiv = static fn (string $ziel): bool => !$fehlerseite
    && ($ziel === Kundenbereich::PFAD ? $pfad === $ziel : ($pfad === $ziel || str_starts_with($pfad, $ziel . '/')));
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
<a class="kb-sprung" href="#inhalt">Zum Inhalt</a>
<header class="kb-kopf">
    <div class="kb-absender">
        <strong><?= View::e($absender) ?></strong>
        <span class="kb-unterzeile">Kundenbereich<?= $kunde !== null ? ' · ' . View::e((string) $kunde['name']) : '' ?></span>
    </div>
    <?php if (!empty($demoMode)): ?><span class="demo-flag">DEMO-MODUS</span><?php endif; ?>
    <form method="post" action="/logout" class="kb-abmelden">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-ghost" type="submit">Abmelden</button>
    </form>
</header>
<nav class="kb-nav" aria-label="Kundenbereich">
    <?php foreach ($navigation as $ziel => [$text, $symbol]): ?>
        <a href="<?= View::e($ziel) ?>"<?= $aktiv($ziel) ? ' aria-current="page"' : '' ?>>
            <svg class="kb-symbol" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= View::e($symbol) ?>"/></svg>
            <span><?= View::e($text) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<main class="kunden-inhalt kb-inhalt" id="inhalt" tabindex="-1">
    <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
    <?= $content ?>
</main>
<footer class="kb-fuss">
    <a href="<?= View::e(Kundenbereich::PFAD . '/hilfe') ?>">Hilfe</a>
    <a href="<?= View::e(Kundenbereich::PFAD . '/hilfe#datenschutz') ?>">Datenschutz</a>
    <span class="muted"><?= View::e($absender) ?></span>
</footer>
<script src="<?= View::asset('/assets/js/app.js') ?>"></script>
</body>
</html>
