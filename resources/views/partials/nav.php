<?php
use App\Support\View;

$path = parse_url((string) ($activePath ?? '/'), PHP_URL_PATH) ?: '/';
$is = static fn (string $prefix): string => ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) ? ' active' : '';

/*
 * Die Gruppen der Seitenleiste – aufgebaut wie in MasterAdmin: schmale
 * Gruppen, einklappbar, die Gruppe der aktuellen Seite steht offen.
 * Vierter Wert: das Recht, ohne das der Eintrag nicht erscheint.
 */
$groups = [
    'Übersicht' => [
        ['/dashboard', 'Übersicht & Live', null, 'dashboard.view'],
        ['/karte',     'Karte',            null, 'karte.view'],
        ['/aufbau',    'Aufbau & Abgleich', null, 'anlagen.view'],
        ['/berichte',  'Übergabeberichte', null, 'berichte.view'],
    ],
    'Anlagen' => [
        ['/anlagen',              'Alle Anlagen',        null, 'anlagen.view'],
        ['/anlagen?fest=1',        'Feststromanschlüsse', 'F', 'anlagen.view'],
        ['/anlagen?art=verteilung','Verteilungen',        'V', 'anlagen.view'],
        ['/anlagen?art=generator', 'Generatoren',         'G', 'anlagen.view'],
        ['/anlagen?art=wechselrichter', 'Wechselrichter', 'W', 'anlagen.view'],
        ['/anlagen?art=tank',      'Tanks',               'T', 'anlagen.view'],
        ['/anlagen/baum',         'Verteilbaum',         null, 'anlagen.view'],
        ['/anlagen/katalog',      'Standard-Verteiler',  null, 'anlagen.view'],
        ['/inventar',             'Inventar',            null, 'datenquellen.view'],
        ['/verteiler-abgleich',   'Verteiler-Abgleich',  null, 'inventory.pull'],
        ['/stecker',              'Steckverbinder',      null, 'stecker.manage'],
    ],
    'Auswertung' => [
        ['/statistik',            'Statistik',           null, 'statistik.view'],
        ['/bereiche',             'Bereiche',            null, 'anlagen.view'],
        ['/steuerung/protokoll',  'Schaltprotokoll',     null, 'steuern.protokoll'],
    ],
    'Messtechnik' => [
        ['/datenquellen',         'Datenquellen & Geräte', null, 'datenquellen.view'],
        ['/datenquellen/profile', 'Registerprofile',       null, 'datenquellen.view'],
        ['/datenquellen/mqtt-zugaenge', 'MQTT-Zugänge',    null, 'datenquellen.view'],
    ],
    'Standorte' => [
        ['/standorte',            'Standorte & Etagen',  null, 'plans.manage'],
        ['/location',             'Location-Abgleich',   null, 'inventory.pull'],
        ['/instanzen',            'Andere Instanzen',    null, 'api.partner'],
        ['/vorschlaege',          'Änderungsvorschläge', null, 'api.vorschlaege'],
    ],
    'System' => [
        ['/system/benutzer',      'Benutzer & Freigaben', null, 'system.users.manage'],
        ['/system/rollen',        'Rollen & Rechte',      null, 'system.users.manage'],
        ['/system/einstellungen', 'Einstellungen',        null, 'settings.manage'],
        ['/system/schnittstelle', 'Schnittstelle (API)',  null, 'api.manage'],
        ['/audit',                'Audit-Log',            null, 'audit.view'],
    ],
];
$query = (string) (parse_url((string) ($activePath ?? '/'), PHP_URL_QUERY) ?? '');
// Der längste passende Pfad gewinnt: auf /anlagen/katalog ist es
// „Standard-Verteiler“, nicht zugleich „Alle Anlagen“ (/anlagen).
$genauester = '';
foreach ($groups as $items) {
    foreach ($items as $item) {
        $pfad = explode('?', $item[0], 2)[0];
        if ($is($pfad) !== '' && strlen($pfad) > strlen($genauester)) {
            $genauester = $pfad;
        }
    }
}
?>
<nav class="sidebar">
    <div class="brand">
        <div class="logo"><?= View::e($appShort ?? 'PM') ?></div>
        <div class="brand-text">
            <strong><?= View::e($appName ?? 'PowerManagement') ?></strong>
            <span><?= View::e($appSubtitle ?? '') ?></span>
        </div>
        <button class="menue-schalter" type="button" id="menue-schalter"
                aria-label="Menü schmaler stellen" aria-pressed="false"
                title="Menü ein- und ausklappen"><span aria-hidden="true"></span></button>
    </div>

    <?php foreach ($groups as $groupName => $items): ?>
        <?php
        $visible = array_values(array_filter($items, static fn ($item) => View::can($item[3])));
        if ($visible === []) {
            continue;
        }
        $aktivIn = static function (string $href) use ($path, $query, $is, $genauester): bool {
            $teile = explode('?', $href, 2);
            if (isset($teile[1])) {
                return $path === $teile[0] && str_contains('&' . $query . '&', '&' . $teile[1] . '&');
            }
            return $is($teile[0]) !== '' && $teile[0] === $genauester
                && ($query === '' || $teile[0] !== '/anlagen' || (!str_contains($query, 'art=') && !str_contains($query, 'fest=1')));
        };
        $offen = false;
        foreach ($visible as $item) {
            if ($aktivIn($item[0])) {
                $offen = true;
                break;
            }
        }
        $schluessel = 'g' . substr(md5($groupName), 0, 8);
        ?>
        <div class="nav-group<?= $offen ? ' offen' : '' ?>" data-nav-gruppe="<?= View::e($schluessel) ?>">
            <button class="nav-group-title" type="button" aria-expanded="<?= $offen ? 'true' : 'false' ?>" <?= $offen ? 'data-nav-aktiv="1"' : '' ?>>
                <span class="nav-text"><?= View::e($groupName) ?></span>
                <span class="nav-group-pfeil" aria-hidden="true"></span>
            </button>
            <div class="nav-group-items">
            <?php foreach ($visible as [$href, $label, $num, $perm]): ?>
                <?php $kuerzel = $num ?? mb_strtoupper(mb_substr($label, 0, 2)); ?>
                <a class="nav-item<?= $aktivIn($href) ? ' active' : '' ?>" href="<?= View::e($href) ?>" title="<?= View::e($label) ?>">
                    <span class="num">
                        <span class="nav-text"><?= $num !== null ? View::e($num) : '·' ?></span>
                        <span class="nav-kuerzel"><?= View::e($kuerzel) ?></span>
                    </span>
                    <span class="nav-text"><?= View::e($label) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="sidebar-footer nav-text">
        <?= View::e($appName ?? 'PowerManagement') ?><br>
        <?php if (!empty($demoMode)): ?>Demo-Daten aktiv<?php else: ?>Produktivbetrieb<?php endif; ?>
    </div>
</nav>
