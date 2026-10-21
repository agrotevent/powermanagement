<?php
/**
 * Verbrauch je Tag eines Tanks – auf der Tankseite und in der Statistik.
 *
 * Die Zeilen holt tank.js von $quelle (/anlagen/{id}/tank/tage, JSON) und
 * setzt sie per textContent: je Kalendertag Verbrauch, getankt, Ø l/h und
 * Hinweis, darunter die erkannten Betankungen ohne Eintrag. Mit $eintragen
 * bekommt jede davon (höchstens 7 Tage zurück) ein Formular „Als Betankung
 * eintragen“ (zum Aufklappen) – tank.js kopiert dafür die Vorlage unten
 * (mit CSRF-Feld) und füllt Zeit und Menge vor.
 *
 * @var int $tankId
 * @var string $quelle Adresse der Tage, mit Zeitraum (und ggf. mp)
 * @var bool $eintragen Formular zum Eintragen (nur mit „bearbeiten“ am Tank)
 * @var ?string $csv Adresse der CSV, null ohne „statistik.export“
 */
use App\Support\Csrf;
use App\Support\View;

$eintragen ??= false;
$csv ??= null;
?>
<div class="tank-tage" data-tank-tage="<?= View::e($quelle) ?>">
    <p class="small muted" data-tage-status>Lade …</p>
    <div class="table-wrap" data-tage-tabelle hidden>
        <table class="tabelle-karten">
            <thead><tr><th>Tag</th><th class="num">Verbrauch</th><th class="num">Getankt</th><th class="num">Ø l/h</th><th>Hinweis</th></tr></thead>
            <tbody data-tage-zeilen></tbody>
        </table>
    </div>
    <p class="small" data-tage-summe hidden></p>
    <div data-tage-erkannt-block hidden>
        <h3 class="abschnitt">Erkannte Betankungen ohne Eintrag</h3>
        <ul class="liste-schlicht small tank-erkannt" data-tage-erkannt></ul>
    </div>
    <p class="help">Je Kalendertag: Inhalt am Anfang + getankt − Inhalt am Ende. Eine Betankung erkennt die App am Anstieg in höchstens 30 min
        (<span data-tage-schwelle>ab der Schwelle</span>); eine eingetragene Betankung bis 1 h davor oder danach ersetzt die Menge.<?php if ($csv !== null): ?>
        <a href="<?= View::e($csv) ?>">CSV herunterladen</a><?php endif; ?></p>
    <?php if ($eintragen): ?>
        <template data-tage-vorlage>
            <details class="tank-eintragen">
                <summary class="small">Als Betankung eintragen …</summary>
                <form method="post" action="/anlagen/<?= (int) $tankId ?>/tank/betankung">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="zeit" data-tage-wert="zeit">
                    <input type="hidden" name="notizen" value="aus den Messwerten erkannt">
                    <input type="hidden" name="kalibrieren" value="0">
                    <div class="field-row">
                        <div class="field"><label data-tage-fuer="liter">Geliefert laut Lieferschein (l)</label>
                            <input name="liter" inputmode="decimal" autocomplete="off" required data-tage-wert="liter"></div>
                        <div class="field"><label data-tage-fuer="lieferschein">Lieferschein</label>
                            <input name="lieferschein" maxlength="64" autocomplete="off" data-tage-wert="lieferschein"></div>
                    </div>
                    <div class="check"><input type="checkbox" name="kalibrieren" value="1" data-tage-wert="kalibrieren">
                        <label data-tage-fuer="kalibrieren">gleich kalibrieren – nur mit der Menge laut Lieferschein</label></div>
                    <button class="btn btn-primary btn-sm" type="submit">Eintragen</button>
                    <p class="help">Vorbelegt: der Anstieg in den Messwerten und sein Ende als Zeit der Betankung.</p>
                </form>
            </details>
        </template>
    <?php endif; ?>
</div>
