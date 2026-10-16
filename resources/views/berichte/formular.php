<?php
use App\Berichte\BerichtService;
use App\Support\View;
use App\Support\Zeit;

/*
 * Das Formular eines Entwurfs. Der Zustand steht in den Feldern (data-feld);
 * bericht.js baut daraus das JSON und speichert laufend (Autosave, lokaler
 * Entwurf bei schwacher Verbindung). Bewertet wird nur auf dem Server – die
 * Antwort jedes Autosaves bringt die Bewertung je Stromkreis mit.
 */
$b = $bericht;
$id = (int) $b['id'];
$title = $b['nummer'] ?? ('Entwurf #' . $id);
$subtitle = 'Übergabebericht · ' . ($b['site_name'] ?? ($b['standort_name'] !== '' ? $b['standort_name'] : 'ohne Standort'));
$namen = ['kopf' => 'Kopf & Kunde', 'anlage' => 'Anlage & Netz', 'punkte' => 'Prüfpunkte', 'messung' => 'Messung', 'ergebnis' => 'Ergebnis', 'abschluss' => 'Abschluss'];
$stand = static function (array $a): array {
    if ($a['mangel'] > 0) {
        return ['mangel', '! ' . $a['mangel']];
    }
    return $a['offen'] > 0 ? ['offen', (string) $a['offen']] : ['ok', '✓'];
};
$teil = [
    'bericht' => $b, 'inhalt' => $inhalt, 'bewertung' => $bewertung, 'hinweise' => $hinweise, 'netzVorschlag' => $netzVorschlag,
    'geraete' => $geraete, 'anlagen' => $anlagen, 'quellen' => $quellen, 'eventworx' => $eventworx, 'pruefer' => $pruefer,
    'texte' => $texte, 'firmaFehlt' => $firmaFehlt, 'darfVerwerfen' => $darfVerwerfen, 'darfUnterschreiben' => $darfUnterschreiben,
];
?>
<div class="bericht" data-bericht="<?= $id ?>" data-version="<?= (int) $b['version'] ?>" data-benutzer="<?= (int) ($currentUser['id'] ?? 0) ?>">
    <div class="toolbar-zeile bericht-kopfzeile">
        <span class="badge warn"><?= View::e(BerichtService::STATUS[$b['status']] ?? $b['status']) ?></span>
        <span class="small muted">angelegt <?= View::e(Zeit::anzeige((string) $b['created_at'])) ?><?= $b['updated_at'] && (string) ($b['geaendert_von_name'] ?? '') !== ''
            ? ' · zuletzt geändert ' . View::e(Zeit::anzeige((string) $b['updated_at'])) . ' von ' . View::e((string) $b['geaendert_von_name']) : '' ?></span>
        <div class="spacer"></div>
        <a class="btn btn-sm btn-ghost" href="/berichte">Alle Berichte</a>
    </div>

    <div class="bericht-leiste">
        <nav class="abschnittsleiste" aria-label="Abschnitte des Berichts">
            <?php foreach ($namen as $k => $n): ?>
                <?php [$sk, $st] = $k === 'abschluss' ? ['', ''] : $stand($abschnitte[$k] ?? ['offen' => 0, 'mangel' => 0]); ?>
                <a href="#abschnitt-<?= $k ?>" data-abschnitt-link="<?= $k ?>"><span><?= View::e($n) ?></span><?php if ($k !== 'abschluss'): ?>
                    <span class="stand <?= $sk ?>" data-stand="<?= $k ?>" title="<?= $sk === 'ok' ? 'vollständig' : ($sk === 'mangel' ? 'Mangel' : 'offen') ?>"><?= View::e($st) ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="speicherstand ok" role="status" aria-live="polite" data-speicherstand><span class="speicher-wort">gespeichert</span> <?= View::e(Zeit::anzeige((string) ($b['updated_at'] ?? $b['created_at']), 'H:i')) ?></span>
    </div>
    <div class="speicher-meldung" data-speicher-meldung hidden>
        <span data-speicher-text></span>
        <button class="btn btn-sm" type="button" data-erneut hidden>Erneut senden</button>
        <a class="btn btn-sm btn-primary" href="/login?next=<?= rawurlencode('/berichte/' . $id) ?>" data-anmelden hidden>Neu anmelden</a>
        <button class="btn btn-sm btn-primary" type="button" data-neu-laden hidden>Neu laden</button>
    </div>
    <div class="notice warn lokal-leiste" data-lokal-leiste hidden>
        <span>Eingaben von <strong data-lokal-zeit></strong> auf diesem Gerät, die noch nicht gespeichert sind.</span>
        <span class="btn-row"><button class="btn btn-sm btn-primary" type="button" data-lokal-uebernehmen>Übernehmen</button>
            <button class="btn btn-sm" type="button" data-lokal-verwerfen>Verwerfen</button></span>
    </div>
    <div class="notice" data-hinweise hidden></div>
    <?php if ((int) $b['fassung'] > 1): ?>
        <p class="notice warn" data-feld-ziel="korrektur_grund" tabindex="-1">Korrektur (Fassung <?= (int) $b['fassung'] ?>)<?= $vorgaenger !== null
            ? ' zu <a href="/berichte/' . (int) $vorgaenger['id'] . '">' . View::e((string) $vorgaenger['nummer']) . '</a>' : '' ?> – Grund: <?= View::e((string) $b['korrektur_grund']) ?>.
            Bis Prüfer und Kunde diese Fassung unterschrieben haben, gilt die vorige.</p>
    <?php endif; ?>

    <?= View::partial('berichte/_kopf', $teil) ?>
    <?= View::partial('berichte/_anlage', $teil) ?>
    <?= View::partial('berichte/_punkte', $teil) ?>
    <?= View::partial('berichte/_messung', $teil) ?>
    <?= View::partial('berichte/_ergebnis', $teil) ?>
    <?= View::partial('berichte/_abschluss', $teil) ?>

    <dialog class="bericht-dialog" data-ewx-dialog aria-labelledby="ewx-dialog-titel">
        <div class="bericht-dialog-kopf"><h2 id="ewx-dialog-titel">Aus Eventworx übernehmen</h2>
            <span class="small muted" data-ewx-dialog-vorgang></span></div>
        <div class="bericht-dialog-inhalt">
            <p class="small muted">Angehakte Gruppen werden in den Bericht geschrieben; leere Felder sind vorausgewählt. Danach bleibt alles als Text änderbar –
                Eventworx wird nicht verändert.</p>
            <div data-ewx-gruppen></div>
        </div>
        <div class="bericht-dialog-fuss btn-row">
            <button class="btn btn-primary" type="button" data-ewx-uebernehmen>Übernehmen</button>
            <button class="btn" type="button" data-dialog-zu>Abbrechen</button>
        </div>
    </dialog>
</div>
