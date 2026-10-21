<?php
/**
 * Ein Protokoll zum Lesen – derselbe Inhalt wie im PDF (berichte/_ansicht
 * mit fuerKunde): Kopf, Besichtigung, Erprobung, Messprotokoll, Ergebnis,
 * Unterschriften. Nichts vom Personal: kein Lebenslauf, keine Knöpfe,
 * keine Verweise nach Eventworx. Dazu das gespeicherte PDF mit Prüfsumme.
 *
 * @var array<string,mixed> $kunde
 * @var array<string,mixed> $bericht nur Listenfelder (KundenSicht::bericht)
 * @var array $inhalt
 * @var array $bewertung
 * @var array $unterschriften
 * @var ?array{name:string,sha256:string} $pdf
 * @var ?array{id:int,nummer:string,fassung:int} $vorgaenger
 * @var ?array{id:int,nummer:string,fassung:int} $nachfolger
 */
use App\Kunden\Kundenbereich;
use App\Support\View;
use App\Support\Zeit;

$b = $bericht;
$title = (string) $b['nummer'];
$basis = Kundenbereich::PFAD . '/protokolle/';
$datum = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
$fassung = static fn (array $f): string => $f['nummer'] . ((int) $f['fassung'] > 1 ? ' (Fassung ' . (int) $f['fassung'] . ')' : '');
?>
<a class="kb-zurueck" href="<?= View::e(Kundenbereich::PFAD . '/protokolle') ?>">← Alle Protokolle</a>
<h1 class="kb-titel">Übergabebericht <?= View::e($fassung($b)) ?></h1>
<p class="kb-einleitung"><?= View::e(implode(' · ', array_filter([(string) $b['titel'], (string) $b['veranstaltung'], (string) $b['standort'],
    'Prüfung am ' . $datum((string) $b['pruefdatum'])]))) ?></p>

<?php if ($b['status'] === 'ersetzt'): ?>
    <p class="notice warn">Dieses Protokoll ist durch eine Korrektur ersetzt<?php if ($nachfolger !== null): ?> –
        gültig ist <a href="<?= View::e($basis . (int) $nachfolger['id']) ?>"><?= View::e($fassung($nachfolger)) ?></a><?php endif; ?>.</p>
<?php elseif ((int) $b['fassung'] > 1): ?>
    <p class="notice">Korrigierte Fassung<?php if ($vorgaenger !== null): ?> von <a href="<?= View::e($basis . (int) $vorgaenger['id']) ?>"><?= View::e($fassung($vorgaenger)) ?></a><?php endif; ?>
        <?= (string) $b['korrektur_grund'] !== '' ? '– Grund: ' . View::e((string) $b['korrektur_grund']) : '' ?></p>
<?php endif; ?>

<section class="card kb-protokoll-pdf" aria-labelledby="kb-pdf-titel">
    <div class="card-head"><h2 id="kb-pdf-titel">PDF</h2>
        <?php if ($b['abgeschlossen_am'] !== null): ?><span class="hint">abgeschlossen <?= View::e(Zeit::anzeige((string) $b['abgeschlossen_am'])) ?></span><?php endif; ?></div>
    <div class="card-body">
        <?php if ($pdf === null): ?>
            <p class="kb-leer">Zu diesem Protokoll liegt kein PDF vor – bitte sprechen Sie uns an.</p>
        <?php else: ?>
            <p><a class="btn btn-primary" href="<?= View::e($basis . (int) $b['id'] . '/pdf') ?>" target="_blank" rel="noopener">PDF ansehen</a>
                <a class="btn" href="<?= View::e($basis . (int) $b['id'] . '/pdf') ?>" download="<?= View::e($pdf['name']) ?>">Herunterladen</a></p>
            <p class="small muted mb0">Prüfsumme der Datei (SHA-256): <span class="mono kb-pruefsumme"><?= View::e($pdf['sha256']) ?></span> – so lässt sich
                prüfen, dass Ihr Exemplar genau das bei der Übergabe gespeicherte ist.</p>
        <?php endif; ?>
    </div>
</section>

<?= View::partial('berichte/_ansicht', ['bericht' => $b, 'inhalt' => $inhalt, 'bewertung' => $bewertung, 'unterschriften' => $unterschriften, 'fuerKunde' => true]) ?>
