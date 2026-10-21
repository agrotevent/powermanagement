<?php
/**
 * Protokolle: die Übergabeberichte der elektrischen Anlage, die Ihnen
 * zugeordnet und abgeschlossen sind – neueste Prüfung zuerst.
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $berichte KundenSicht::berichte()
 */
use App\Kunden\Kundenbereich;
use App\Support\View;

$title = 'Protokolle';
?>
<h1 class="kb-titel">Protokolle</h1>
<p class="kb-einleitung">Übergabeberichte der elektrischen Anlage mit Messprotokoll – so, wie Sie sie bei der Übergabe bekommen haben.
    Prüfnachweise einzelner Geräte (DGUV V3) sind etwas anderes; was der Unterschied ist, steht in der
    <a href="<?= View::e(Kundenbereich::PFAD . '/hilfe') ?>">Hilfe</a>.</p>

<section class="card" aria-labelledby="kb-protokolle-titel">
    <div class="card-head"><h2 id="kb-protokolle-titel">Ihre Protokolle</h2></div>
    <div class="card-body">
        <?php if ($berichte === []): ?>
            <p class="kb-leer">Für Sie liegt noch kein abgeschlossenes Protokoll vor. Ein Protokoll erscheint hier, sobald es abgeschlossen und Ihnen
                zugeordnet ist.</p>
        <?php else: ?>
            <?= View::partial('kundenbereich/_protokolle', ['berichte' => $berichte]) ?>
        <?php endif; ?>
    </div>
    <div class="card-footer small muted">Wurde ein Protokoll korrigiert, gilt die neueste Fassung; frühere Fassungen bleiben zum Nachlesen da.</div>
</section>
