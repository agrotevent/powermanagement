<?php
/**
 * Prüfnachweise aus Eventworx: die PDF-Dateien, die in Eventworx an einem
 * verknüpften Auftrag oder Lieferschein des Kunden hochgeladen sind – zum
 * Auswählen, mit gemeinsamen Angaben aus dem Prüfprotokoll. Nur in dieser
 * Antwort (no-store); die Sitzung merkt sich nur Kennungen, Namen und
 * Zeiten der Dateien, und übernommen wird nur, was Eventworx dann noch am
 * Vorgang führt.
 *
 * @var array<string,mixed> $kunde
 * @var array<string,mixed> $vorgang kunden_vorgaenge
 * @var list<array{id:string,name:string,zeit:?string,beschreibung:string,schon:?string}> $dateien
 * @var bool $mehr mehr Dateien am Vorgang, als gelesen wurden
 * @var string $meldung
 * @var array<string,mixed> $eingabe Eingaben nach einem Fehler (mit gewaehlt)
 * @var list<array{id:int,name:string}> $standorte
 * @var list<array<string,mixed>> $inventar
 */
use App\Kunden\Pruefnachweise;
use App\Services\Eventworx;
use App\Support\Csrf;
use App\Support\View;
use App\Support\Zeit;

$id = (int) $kunde['id'];
$art = Eventworx::ART_NAMEN[(string) $vorgang['ewx_art']] ?? 'Vorgang';
$title = 'Prüfnachweise aus ' . $art . ' ' . (string) $vorgang['ewx_nummer'];
$subtitle = (string) $kunde['name'];
$gewaehlt = array_map('strval', (array) ($eingabe['gewaehlt'] ?? []));
$werte = $eingabe !== [] ? $eingabe : ['einsatz_von' => (string) ($vorgang['von'] ?? ''), 'einsatz_bis' => (string) ($vorgang['bis'] ?? '')];
$offen = array_filter($dateien, static fn (array $d): bool => $d['schon'] === null);
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/kunden/<?= $id ?>?tab=nachweise">← Prüfnachweise des Kunden</a>
</div>

<form method="post" action="/kunden/<?= $id ?>/nachweise/eventworx/uebernehmen" class="kunden-formular" data-laeuft="übernimmt …" data-laeuft-seite>
    <?= Csrf::field() ?>
    <input type="hidden" name="vorgang" value="<?= (int) $vorgang['id'] ?>">
    <div class="card">
        <div class="card-head"><h2>Dateien am Vorgang</h2><span class="hint"><?= View::e($art . ' ' . (string) $vorgang['ewx_nummer']) ?><?= (string) $vorgang['titel'] !== '' ? ' · ' . View::e((string) $vorgang['titel']) : '' ?></span></div>
        <div class="card-body">
            <?php if ($meldung !== ''): ?><p class="muted"><?= View::e($meldung) ?></p><?php endif; ?>
            <?php if ($mehr): ?><p class="notice warn">Am Vorgang liegen mehr Dateien, als gelesen werden (höchstens 200) – gezeigt sind die ersten.</p><?php endif; ?>
            <?php if ($dateien !== []): ?>
                <ul class="liste-schlicht">
                    <?php foreach ($dateien as $d): ?>
                        <li>
                            <label class="check">
                                <input type="checkbox" name="datei[]" value="<?= View::e($d['id']) ?>"<?= $d['schon'] !== null ? ' disabled' : '' ?><?= in_array($d['id'], $gewaehlt, true) ? ' checked' : '' ?>>
                                <span><strong><?= View::e($d['name']) ?></strong>
                                    <?php if ($d['zeit'] !== null): ?><span class="small muted"> · <?= View::e(Zeit::anzeige($d['zeit'])) ?></span><?php endif; ?>
                                    <?php if ($d['schon'] === 'dieser'): ?> <span class="badge ok">schon übernommen</span><?php elseif ($d['schon'] === 'anderer'): ?> <span class="badge">bei einem anderen Kunden übernommen</span><?php endif; ?>
                                    <?php if ($d['beschreibung'] !== ''): ?><span class="small muted"><br><?= View::e($d['beschreibung']) ?></span><?php endif; ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="help">Höchstens <?= Pruefnachweise::EWX_JE_ANFRAGE ?> Dateien je Durchgang. Eine Sammel-PDF für mehrere Geräte nur, wenn sie
                    ausschließlich Geräte dieses Kunden enthält. Gezeigt werden Dateien, die in Eventworx am Auftrag oder Lieferschein hochgeladen
                    sind; Prüfberichte aus „Werkstatt → Prüfungen“ (am Gerät) bitte von Hand hinterlegen.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($offen !== []): ?>
    <div class="card mt">
        <div class="card-head"><h2>Angaben aus dem Prüfprotokoll</h2><span class="hint">gelten für alle gewählten Dateien</span></div>
        <div class="card-body">
            <?= View::partial('kunden/_nachweis_felder', ['p' => 'ewx', 'w' => $werte, 'standorte' => $standorte, 'vorgaenge' => [], 'inventar' => $inventar,
                'bezeichnungOptional' => true]) ?>
            <label class="check"><input type="checkbox" name="freigeben" value="1"<?= !empty($eingabe['freigeben']) ? ' checked' : '' ?>> <span>gleich für den Kunden freigeben</span></label>
            <p class="help">Das PDF wird nicht ausgewertet – Prüfdatum, nächste Prüfung und Ergebnis trägst du aus dem Protokoll ein; ein
                Ergebnis nur, wenn es dort steht. Ohne eigene Mietzeit gilt die des Vorgangs.</p>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Gewählte übernehmen</button></div>
    </div>
    <?php endif; ?>
</form>
