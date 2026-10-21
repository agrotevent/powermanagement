<?php
/**
 * Liste der Prüfnachweise (DGUV V3) des Kunden – je Gerät (oder
 * Sammelnachweis) eine Zeile: Prüfung, Frist mit Symbol und Wort (nie nur
 * Farbe), Hinweise zur Mietzeit, Herkunft und das PDF als Datei. Am Telefon
 * Karten (tabelle-karten), gedruckt eine Tabelle.
 *
 * @var list<array<string,mixed>> $nachweise KundenSicht::nachweise()
 * @var bool $mitLocation Location je Eintrag zeigen (nicht auf der Location-Seite)
 */
use App\Kunden\Kundenbereich;
use App\Kunden\Pruefnachweise;
use App\Support\View;

$mitLocation = $mitLocation ?? true;
$basis = Kundenbereich::PFAD . '/pruefnachweise/';
?>
<div class="table-wrap">
    <table class="tabelle-karten kb-nachweise">
        <thead><tr><th>Gerät</th><th>Geprüft</th><th>Nächste Prüfung</th><th>Angaben</th><th><span class="sr-only">Datei</span></th></tr></thead>
        <tbody>
            <?php foreach ($nachweise as $n): ?>
                <?php
                $g = $n['gueltigkeit'];
                $nummern = array_filter([$n['inventarnummer'] !== '' ? 'Inv.-Nr. ' . $n['inventarnummer'] : '', $n['seriennummer'] !== '' ? 'Serien-Nr. ' . $n['seriennummer'] : '']);
                $angaben = array_filter([
                    $n['norm'],
                    $n['ergebnis'] !== '' ? 'Ergebnis: ' . (Pruefnachweise::ERGEBNISSE[$n['ergebnis']] ?? $n['ergebnis']) : '',
                    $mitLocation ? (string) $n['standort'] : '',
                    $n['einsatz_von'] !== null || $n['einsatz_bis'] !== null
                        ? 'Mietzeit ' . Pruefnachweise::datum($n['einsatz_von']) . ' – ' . Pruefnachweise::datum($n['einsatz_bis']) : '',
                    $n['quelle'],
                ]);
                ?>
                <tr>
                    <td data-label="Gerät"><strong class="kb-geraet"><?= View::e($n['bezeichnung']) ?></strong>
                        <?php if ($nummern !== []): ?><span class="kb-meta"><?= View::e(implode(' · ', $nummern)) ?></span><?php endif; ?></td>
                    <td data-label="Geprüft"><?= View::e(Pruefnachweise::datum($n['geprueft_am'])) ?></td>
                    <td data-label="Nächste Prüfung">
                        <span class="kb-frist kb-frist-<?= View::e($g['code']) ?>"><span aria-hidden="true"><?= View::e($g['symbol']) ?></span> <?= View::e($g['wort']) ?></span>
                        <span class="kb-meta"><?= View::e($g['text']) ?></span>
                        <?php foreach ($g['hinweise'] as $h): ?><span class="kb-hinweis"><?= View::e($h) ?></span><?php endforeach; ?></td>
                    <td data-label="Angaben"><?php foreach ($angaben as $a): ?><span class="kb-meta"><?= View::e((string) $a) ?></span><?php endforeach; ?>
                        <?php if ($n['hinweis'] !== ''): ?><span class="kb-notiz">Hinweis: <?= View::e($n['hinweis']) ?></span><?php endif; ?></td>
                    <td class="right"><a class="btn btn-sm kb-pdf" href="<?= View::e($basis . (int) $n['id'] . '/pdf') ?>" download>PDF laden</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
