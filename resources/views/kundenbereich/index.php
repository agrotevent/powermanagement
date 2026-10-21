<?php
/**
 * Übersicht des Kundenbereichs. In diesem Stand nur Begrüßung und Kunde –
 * Locations, Protokolle und Prüfnachweise folgen.
 *
 * @var array<string,mixed> $kunde
 */
use App\Support\View;
use App\Support\Zeit;

$title = 'Übersicht';
?>
<div class="kb-karten">
    <section class="card">
        <div class="card-head"><h2>Willkommen</h2></div>
        <div class="card-body">
            <dl class="kb-felder">
                <dt>Kunde</dt><dd><?= View::e((string) $kunde['name']) ?></dd>
                <?php if ((string) $kunde['kundennummer'] !== ''): ?>
                    <dt>Kundennummer</dt><dd><?= View::e((string) $kunde['kundennummer']) ?></dd>
                <?php endif; ?>
                <?php if (!empty($kunde['gueltig_bis'])): ?>
                    <dt>Zugang bis</dt><dd><?= View::e(Zeit::anzeige((string) $kunde['gueltig_bis'], 'd.m.Y')) ?></dd>
                <?php endif; ?>
            </dl>
            <p class="muted">Hier finden Sie die Locations, die wir für Sie betreuen, Ihre Prüfprotokolle und die
                Prüfnachweise der gemieteten Geräte – sobald wir sie für Sie bereitgestellt haben.</p>
        </div>
    </section>
</div>
