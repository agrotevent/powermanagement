<?php
/**
 * Reiter Übersicht: was der Kunde jetzt sieht – genau das, was der
 * Kundenbereich ihm zeigt (KundenSicht::zusammenfassung(), dieselben
 * Prädikate).
 *
 * @var array<string,mixed> $kunde
 * @var array{gesperrt:?string,locations:list<array<string,mixed>>,kommend:list<array{name:string,ab:string}>,berichte:list<array<string,mixed>>,nachweise:list<array<string,mixed>>,zugaenge_aktiv:int} $uebersicht
 * @var array<string,int> $zahlen
 * @var bool $pflegen
 */
use App\Kunden\KundenSicht;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$u = $uebersicht;
$n = count($u['locations']);
$p = count($u['berichte']);
$k = count($u['nachweise']);
$nz = KundenSicht::nachweisZahlen($u['nachweise']);
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Der Kunde sieht derzeit</h2><span class="hint">so wie im Kundenbereich</span></div>
        <div class="card-body">
            <?php if ($u['gesperrt'] !== null): ?>
                <p class="notice warn">Nichts – der Kunde ist <?= (int) $kunde['aktiv'] === 1 ? 'abgelaufen' : 'beendet' ?>, seine Zugänge sind zu.</p>
            <?php else: ?>
                <p><strong><?= $n ?> <?= $n === 1 ? 'Location' : 'Locations' ?></strong> · <strong><?= $p ?> <?= $p === 1 ? 'Protokoll' : 'Protokolle' ?></strong>
                    · <strong><?= $k ?> <?= $k === 1 ? 'Prüfnachweis' : 'Prüfnachweise' ?></strong></p>
                <?php if ($u['locations'] === []): ?>
                    <p class="muted">Keine – Standorte gibt eine Freigabe frei<?= $pflegen ? ' (Reiter „Freigaben“)' : '' ?>.</p>
                <?php else: ?>
                    <ul class="liste-schlicht">
                        <?php foreach ($u['locations'] as $l): ?>
                            <li><strong><?= View::e((string) $l['site']['name']) ?></strong>
                                <?php foreach ($l['freigaben'] as $f): ?>
                                    <div class="small muted"><?= (string) $f['veranstaltung'] !== '' ? View::e((string) $f['veranstaltung']) . ' · ' : '' ?>sichtbar bis
                                        <?= View::e(Zeit::anzeige($f['bis'])) ?> · <?= View::e((string) $f['daten']) ?></div>
                                <?php endforeach; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($u['berichte'] !== []): ?>
                    <h3 class="mt">Protokolle</h3>
                    <ul class="liste-schlicht">
                        <?php foreach (array_slice($u['berichte'], 0, 10) as $b): ?>
                            <li><a href="/berichte/<?= (int) $b['id'] ?>"><?= View::e((string) $b['nummer']) ?></a>
                                <span class="small muted"><?= View::e(implode(' · ', array_filter([(string) $b['titel'], 'Prüfung ' . $datum((string) $b['pruefdatum']),
                                    $b['pdf'] ? '' : 'ohne PDF']))) ?><?= $b['fruehere'] !== [] ? ' · ' . count($b['fruehere']) . ' frühere Fassung(en)' : '' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($p > 10): ?><p class="small"><a href="/kunden/<?= $id ?>?tab=protokolle">Alle <?= $p ?> Protokolle</a></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($u['nachweise'] !== []): ?>
                    <h3 class="mt">Prüfnachweise</h3>
                    <p class="small"><?= View::e(implode(' · ', array_filter([
                        $nz['abgelaufen'] > 0 ? $nz['abgelaufen'] . ' abgelaufen' : '',
                        $nz['laeuft_ab'] > 0 ? $nz['laeuft_ab'] . ($nz['laeuft_ab'] === 1 ? ' läuft' : ' laufen') . ' in den nächsten 30 Tagen ab' : '',
                        $nz['unbekannt'] > 0 ? $nz['unbekannt'] . ' ohne Datum der nächsten Prüfung' : '',
                    ])) ?: 'alle gültig') ?> · <a href="/kunden/<?= $id ?>?tab=nachweise">alle Nachweise</a></p>
                <?php endif; ?>
                <?php if ($u['kommend'] !== []): ?>
                    <h3 class="mt">Kommt noch</h3>
                    <ul class="liste-schlicht">
                        <?php foreach ($u['kommend'] as $k): ?>
                            <li><?= View::e($k['name']) ?> <span class="small muted">ab <?= View::e(Zeit::anzeige($k['ab'])) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="help">Je Location: Anschrift, Pläne der Etagen und feste Stromanschlüsse – keine mobilen Anlagen, Kabel,
                    Messgeräte oder Notizen. Protokolle sieht der Kunde erst, wenn sie ihm ausdrücklich zugeordnet und abgeschlossen sind
                    (Reiter „Protokolle“), Prüfnachweise erst nach der Freigabe (Reiter „Prüfnachweise“) – nie über den Standort allein.</p>
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
