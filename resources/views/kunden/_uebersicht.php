<?php
/**
 * Reiter Übersicht: was der Kunde jetzt sieht – aus demselben Prädikat wie
 * der Kundenbereich (Kundenbereich::freigaben()).
 *
 * @var array<string,mixed> $kunde
 * @var array{sperre:?string,locations:list<array<string,mixed>>,kommend:list<array<string,mixed>>,zugaenge_aktiv:int} $uebersicht
 * @var array<string,int> $zahlen
 * @var bool $pflegen
 */
use App\Auth\Zugriff;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$u = $uebersicht;
$orte = array_values(array_unique(array_map(static fn (array $f): int => (int) $f['ziel_id'], $u['locations'])));
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Der Kunde sieht derzeit</h2><span class="hint">so wie im Kundenbereich</span></div>
        <div class="card-body">
            <?php if ($u['sperre'] !== null): ?>
                <p class="notice warn">Nichts – der Kunde ist <?= (int) $kunde['aktiv'] === 1 ? 'abgelaufen' : 'beendet' ?>, seine Zugänge sind zu.</p>
            <?php else: ?>
                <p><strong><?= count($orte) ?> <?= count($orte) === 1 ? 'Location' : 'Locations' ?></strong></p>
                <?php if ($u['locations'] === []): ?>
                    <p class="muted">Keine – Standorte gibt eine Freigabe frei<?= $pflegen ? ' (Reiter „Freigaben“)' : '' ?>.</p>
                <?php else: ?>
                    <ul class="liste-schlicht">
                        <?php foreach ($u['locations'] as $f): ?>
                            <li><strong><?= View::e($f['site_name'] !== '' ? (string) $f['site_name'] : 'Standort #' . (int) $f['ziel_id']) ?></strong>
                                <?= (string) $f['notiz'] !== '' ? ' · ' . View::e((string) $f['notiz']) : '' ?>
                                <div class="small muted">sichtbar bis <?= View::e(Zeit::anzeige((string) $f['gueltig_bis'])) ?>
                                    · Messdaten <?= View::e(Zugriff::beschreiben([[(int) Zeit::ts((string) $f['daten_ab']), (int) Zeit::ts((string) $f['daten_bis'])]])) ?></div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($u['kommend'] !== []): ?>
                    <h3 class="mt">Kommt noch</h3>
                    <ul class="liste-schlicht">
                        <?php foreach ($u['kommend'] as $f): ?>
                            <li><?= View::e($f['site_name'] !== '' ? (string) $f['site_name'] : 'Standort #' . (int) $f['ziel_id']) ?>
                                <span class="small muted">ab <?= View::e(Zeit::anzeige((string) $f['gueltig_ab'])) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="help">Protokolle und Prüfnachweise erscheinen im Kundenbereich erst, wenn sie dem Kunden ausdrücklich
                    zugeordnet bzw. freigegeben sind – nie über den Standort allein.</p>
            <?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="card-head"><h2>Zugänge</h2></div>
        <div class="card-body">
            <p><strong><?= (int) $zahlen['zugaenge'] ?></strong> <?= (int) $zahlen['zugaenge'] === 1 ? 'Zugang' : 'Zugänge' ?>,
                davon <?= (int) $u['zugaenge_aktiv'] ?> nutzbar.</p>
            <?php if ($pflegen): ?>
                <p><a class="btn btn-sm" href="/kunden/<?= $id ?>?tab=zugaenge">Zugänge verwalten</a>
                    <a class="btn btn-sm" href="/kunden/<?= $id ?>?tab=freigaben">Freigaben</a></p>
            <?php endif; ?>
            <p class="help">Kunden melden sich mit Benutzername und Kennwort an und sehen nur den Kundenbereich – keine
                Karte, keine Anlagen, keine anderen Kunden.</p>
        </div>
    </section>
</div>
