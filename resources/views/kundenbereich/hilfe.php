<?php
/**
 * Hilfe im Kundenbereich: resources/hilfe/kundenbereich.md (über
 * App\Support\Markdown – alles maskiert, nur dessen eigenes HTML), dazu
 * Datenschutzhinweis (Anker #datenschutz, Ziel des Links im Fuß) und
 * Ansprechpartner.
 *
 * @var string $hilfe HTML aus Markdown::toHtml()
 * @var string $datenschutz KundenEinstellungen::datenschutz() – als Text
 * @var array{text:string,telefon:?string} $kontakt
 */
use App\Support\View;

$title = 'Hilfe';
?>
<div class="kb-hilfe">
    <article class="card">
        <div class="card-body handbuch"><?= $hilfe ?></div>
    </article>

    <section class="card" id="datenschutz" aria-labelledby="kb-datenschutz-titel">
        <div class="card-head"><h2 id="kb-datenschutz-titel">Datenschutzhinweis</h2></div>
        <div class="card-body"><p class="kb-datenschutz"><?= View::e($datenschutz) ?></p></div>
    </section>

    <?= View::partial('kundenbereich/_kontakt', ['kontakt' => $kontakt]) ?>
</div>
