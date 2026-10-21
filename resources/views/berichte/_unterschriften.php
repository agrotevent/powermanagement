<?php
/**
 * Die Unterschriften eines Berichts: Prüfer und Kundenblock, die Striche
 * als SVG (nur Zahlen, Unterschrift::svg). Texte der Bestätigungen aus den
 * festen Kopien des eingefrorenen Inhalts.
 *
 * @var array $bericht
 * @var array $inhalt vollständig
 * @var array<string,array<string,mixed>> $unterschriften
 */
use App\Berichte\BerichtService;
use App\Berichte\Katalog;
use App\Berichte\Unterschrift;
use App\Support\View;
use App\Support\Zeit;

$pruefer = $unterschriften['pruefer'] ?? null;
$kunde = $unterschriften['kunde'] ?? null;
$texte = is_array($inhalt['texte'] ?? null) ? $inhalt['texte'] : [];
$bst = Katalog::inReihenfolge(is_array($inhalt['labels']['bestaetigungen'] ?? null) ? $inhalt['labels']['bestaetigungen']
    : array_map(static fn (array $b): string => $b['text'], Katalog::bestaetigungen()), Katalog::bestaetigungen());
$wann = static fn (array $u): string => trim((string) $u['ort'] . ', ' . Zeit::anzeige((string) $u['unterschrieben_am']), ', ');
?>
<div class="card">
    <div class="card-head"><h2>Unterschriften</h2></div>
    <div class="card-body">
        <div class="unterschriften">
            <div class="unterschrift-block">
                <h3>Prüfer</h3>
                <?php if ($pruefer === null): ?>
                    <p class="muted small">Noch nicht unterschrieben.</p>
                <?php else: ?>
                    <div class="unterschrift-bild"><?= Unterschrift::svg((array) $pruefer['striche']) ?></div>
                    <div><strong><?= View::e((string) $pruefer['name']) ?></strong></div>
                    <div class="small"><?= View::e((string) $pruefer['funktion']) ?></div>
                    <div class="small muted"><?= View::e($wann($pruefer)) ?></div>
                <?php endif; ?>
            </div>
            <div class="unterschrift-block">
                <h3>Kunde</h3>
                <?php if ($kunde === null): ?>
                    <p class="muted small"><?= $pruefer !== null ? 'Die Bestätigung des Kunden steht noch aus.' : '–' ?></p>
                <?php elseif ((string) $kunde['art'] !== 'gezeichnet'): ?>
                    <p><strong>Ohne Unterschrift des Kunden:</strong> <?= View::e(BerichtService::OHNE_KUNDE[(string) $kunde['art']] ?? (string) $kunde['art']) ?>
                        – <?= View::e((string) ($kunde['angaben']['grund'] ?? '')) ?></p>
                    <div class="small muted"><?= View::e(Zeit::anzeige((string) $kunde['unterschrieben_am'])) ?> · <?= View::e((string) $kunde['username']) ?></div>
                <?php else: ?>
                    <?php if (($texte['kunde'] ?? '') !== ''): ?><p class="small muted"><?= View::e((string) $texte['kunde']) ?></p><?php endif; ?>
                    <ul class="liste-schlicht small">
                        <?php foreach ($bst as $schluessel => $text): ?>
                            <?php $an = in_array((string) $schluessel, (array) ($kunde['angaben']['bestaetigungen'] ?? []), true); ?>
                            <li><span class="bewertung <?= $an ? 'ok' : '' ?>"><?= $an ? '✓ ja' : '– nein' ?></span> <?= View::e((string) $text) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ((string) ($kunde['angaben']['vorbehalte'] ?? '') !== ''): ?>
                        <p class="small"><strong>Vorbehalte:</strong> <?= nl2br(View::e((string) $kunde['angaben']['vorbehalte'])) ?></p>
                    <?php endif; ?>
                    <div class="unterschrift-bild"><?= Unterschrift::svg((array) $kunde['striche']) ?></div>
                    <div><strong><?= View::e((string) $kunde['name']) ?></strong></div>
                    <?php if ((string) $kunde['funktion'] !== ''): ?><div class="small"><?= View::e((string) $kunde['funktion']) ?></div><?php endif; ?>
                    <div class="small muted"><?= View::e($wann($kunde)) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
