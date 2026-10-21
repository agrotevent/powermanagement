<?php
/**
 * Ein Kunde – Reiter Übersicht, Stammdaten, Zugänge, Freigaben, Protokolle,
 * Prüfnachweise, Abrufe (Zugänge, Freigaben und Abrufe nur mit
 * „kunden.manage“). Die Zahl an den Reitern Protokolle und Prüfnachweise:
 * was der Kunde davon sieht.
 *
 * @var array<string,mixed> $kunde
 * @var string $tab
 * @var array<string,array{0:string,1:bool}> $reiter
 * @var bool $pflegen
 * @var array<string,int> $zahlen KundenService::zahlen()
 * @var array<string,mixed> $eingabe Eingaben nach einem Fehler (nur dieses Kunden)
 */
use App\Kunden\Kundenbereich;
use App\Support\View;
use App\Support\Zeit;

$title = (string) $kunde['name'];
$sperre = Kundenbereich::kundeSperre((int) $kunde['id']);
$subtitle = 'Kunde' . ((string) $kunde['kundennummer'] !== '' ? ' ' . (string) $kunde['kundennummer'] : '')
    . ($sperre === null ? '' : ((int) $kunde['aktiv'] === 1 ? ' · abgelaufen' : ' · beendet'));
$id = (int) $kunde['id'];
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/kunden">← Alle Kunden</a>
    <?php if ($sperre === null): ?>
        <span class="badge ok">aktiv</span><?= !empty($kunde['gueltig_bis']) ? ' <span class="small muted">Zugänge bis ' . View::e(Zeit::anzeige((string) $kunde['gueltig_bis'])) . '</span>' : '' ?>
    <?php else: ?>
        <span class="badge bad"><?= (int) $kunde['aktiv'] === 1 ? 'abgelaufen' : 'beendet' ?></span>
        <span class="small muted">Alle Zugänge sind zu, der Kunde sieht nichts.</span>
    <?php endif; ?>
</div>

<nav class="tabs tabs-umbruch" aria-label="Bereiche des Kunden">
    <?php foreach ($reiter as $k => [$name, $_recht]): ?>
        <a class="tab<?= $tab === $k ? ' active' : '' ?>" href="/kunden/<?= $id ?>?tab=<?= View::e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= View::e($name) ?><?php
            echo match ($k) {
                'zugaenge'  => ' (' . (int) $zahlen['zugaenge'] . ')',
                'freigaben' => ' (' . (int) $zahlen['freigaben'] . ')',
                'protokolle' => ' (' . (int) $zahlen['berichte'] . ')',
                'nachweise' => ' (' . (int) $zahlen['nachweise'] . ')',
                default     => '',
            }; ?></a>
    <?php endforeach; ?>
</nav>

<?= View::partial('kunden/_' . $tab, get_defined_vars()) ?>
