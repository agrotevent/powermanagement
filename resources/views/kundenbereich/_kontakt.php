<?php
/**
 * Ansprechpartner (Einstellung „kundenbereich.kontakt“, sonst das
 * Bereitschaftstelefon der Übergabeberichte); eine Nummer darin zum
 * Antippen.
 *
 * @var array{text:string,telefon:?string} $kontakt
 */
use App\Support\View;
?>
<section class="card kb-kontakt" aria-labelledby="kb-kontakt-titel">
    <div class="card-head"><h2 id="kb-kontakt-titel">Ihr Ansprechpartner</h2></div>
    <div class="card-body">
        <?php if ($kontakt['text'] !== ''): ?>
            <p><?= View::e($kontakt['text']) ?></p>
            <?php if ($kontakt['telefon'] !== null): ?>
                <a class="btn btn-primary" href="tel:<?= View::e($kontakt['telefon']) ?>">Anrufen</a>
            <?php endif; ?>
        <?php else: ?>
            <p class="kb-leer">Bei Fragen wenden Sie sich bitte an Ihren Ansprechpartner bei uns.</p>
        <?php endif; ?>
    </div>
</section>
