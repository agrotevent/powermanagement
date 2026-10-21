<?php
use App\Services\KatalogImport;
use App\Support\Csrf;
use App\Support\View;

$title = 'Katalog importieren';
$subtitle = 'Standard-Verteiler und Aggregate aus einer Datei – erst Vorschau, dann übernehmen';
?>
<div class="toolbar-zeile">
    <a class="btn btn-sm btn-ghost" href="/anlagen/katalog">← Standard-Verteiler</a>
</div>
<?php if ($bereit !== null): ?>
    <div class="notice">
        Eine Vorschau liegt noch bereit: <strong><?= View::e($bereit['quelle'] !== '' ? $bereit['quelle'] : $bereit['datei']) ?></strong>
        (<?= (int) $bereit['typen'] ?> <?= $bereit['typen'] === 1 ? 'Typ' : 'Typen' ?>).
        <a href="/anlagen/katalog/import?vorschau=1">Zur Vorschau</a>
    </div>
<?php endif; ?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Datei hochladen</h2><span class="hint">.json, höchstens 5 MB</span></div>
        <form method="post" action="/anlagen/katalog/import/vorschau" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <div class="card-body">
                <div class="field">
                    <label for="datei">Katalog-Paket oder einzelner Standard-Verteiler</label>
                    <input id="datei" type="file" name="datei" accept=".json,application/json" required>
                    <div class="help">Ein Paket (<code>verteiler-typen</code>) mit vielen Typen oder eine einzelne Datei
                        <code>verteiler-typ</code>, wie der Verteiler-Abgleich sie schreibt.</div>
                </div>
            </div>
            <div class="card-footer btn-row">
                <button class="btn btn-primary" type="submit">Vorschau</button>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><h2>Mitgelieferte Kataloge</h2><span class="hint">resources/kataloge</span></div>
        <?php if ($mitgeliefert === []): ?>
            <div class="card-body muted small">Noch keine mitgelieferten Kataloge.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="tabelle-karten">
                    <thead><tr><th>Katalog</th><th class="num">Typen</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($mitgeliefert as $m): ?>
                        <tr>
                            <td data-label="Katalog"><strong><?= View::e($m['quelle']) ?></strong>
                                <div class="small muted mono"><?= View::e($m['datei']) ?></div>
                                <?php if ($m['fehler'] !== null): ?><div class="small text-warn"><?= View::e($m['fehler']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Typen" class="num"><?= (int) $m['typen'] ?></td>
                            <td class="right">
                                <form method="post" action="/anlagen/katalog/import/vorschau">
                                    <?= Csrf::field() ?><input type="hidden" name="mitgeliefert" value="<?= View::e($m['datei']) ?>">
                                    <button class="btn btn-sm btn-primary" type="submit">Vorschau</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-head"><h2>So geht der Import</h2></div>
    <div class="card-body small">
        <p>Die Vorschau zeigt je Typ, ob er <strong>neu</strong> ist, <strong>schon da</strong> (hier genauso wie in der Datei),
            <strong>hier anders</strong> (etwa weil jemand einen Fehler von Hand korrigiert hat) oder ob er einen <strong>Fehler</strong>
            hat – dann nennt sie den Grund. Übernommen wird nur, was angehakt ist: neue Typen sind vorausgewählt, geänderte nicht –
            <strong>ein Import überschreibt nie still eine Korrektur.</strong></p>
        <p class="mb0">Danach sind die Typen gewöhnliche Standard-Verteiler: Anschlüsse, Sicherungen und Eigenschaften lassen sich
            bearbeiten, kopieren und löschen wie bei jedem anderen. Fehlende Steckertypen legt der Import mit an – das darf, wer den
            Steckverbinder-Katalog pflegt. Höchstens <?= (int) KatalogImport::HOECHSTENS ?> Typen je Datei.</p>
    </div>
</div>
