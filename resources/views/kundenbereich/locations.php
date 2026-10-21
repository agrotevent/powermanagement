<?php
/**
 * Locations des Kunden: die, die er jetzt sieht (mit Anschrift,
 * Veranstaltung, „sichtbar bis“), und die kommenden (nur Name und „ab …“).
 *
 * @var array<string,mixed> $kunde
 * @var list<array<string,mixed>> $locations KundenSicht::locations()
 * @var list<array{name:string,ab:string}> $kommend KundenSicht::kommend()
 */
use App\Support\View;

$title = 'Locations';
?>
<h1 class="kb-titel">Locations</h1>
<p class="kb-einleitung">Die Locations Ihrer Veranstaltungen, solange sie für Sie freigegeben sind – mit Plänen und den
    festen Stromanschlüssen vor Ort.</p>

<section class="card" aria-labelledby="kb-orte-titel">
    <div class="card-head"><h2 id="kb-orte-titel">Jetzt für Sie freigegeben</h2></div>
    <div class="card-body">
        <?php if ($locations === []): ?>
            <p class="kb-leer">Derzeit ist keine Location für Sie freigegeben.</p>
        <?php else: ?>
            <?= View::partial('kundenbereich/_orte', ['locations' => $locations, 'mitAnschrift' => true]) ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($kommend !== []): ?>
    <section class="card" aria-labelledby="kb-kommend-titel">
        <div class="card-head"><h2 id="kb-kommend-titel">Demnächst</h2></div>
        <div class="card-body">
            <?= View::partial('kundenbereich/_kommend', ['kommend' => $kommend]) ?>
            <p class="help">Diese Locations sehen Sie hier ab dem genannten Zeitpunkt.</p>
        </div>
    </section>
<?php endif; ?>
