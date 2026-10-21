<?php
use App\Berichte\Felder;
use App\Support\View;

$e = $inhalt['ergebnis'];
$anhang = $inhalt['anhang'];
$jaNein = ['ja' => 'Ja', 'nein' => 'Nein'];
$zeilen = array_map(static fn (array $k): array => ['z' => (string) $k['z'], 'nr' => (string) $k['nr'], 'ort' => (string) $k['ort']], $inhalt['kreise']);
/** Eine Zeile der Mängelliste; $m = null ist die Vorlage für neue. */
$mangel = static function (?array $m, string $schluessel) use ($zeilen): string {
    $m ??= ['text' => '', 'kreis' => '', 'frist' => '', 'behoben' => ''];
    $o = ['z' => $schluessel];
    $wahl = '<div class="field"><label for="' . View::e(Felder::id('kreis', $schluessel)) . '">Stromkreis</label>'
        . '<select id="' . View::e(Felder::id('kreis', $schluessel)) . '" data-feld="kreis" data-kreis-wahl><option value="">–</option>';
    foreach ($zeilen as $z) {
        $wahl .= '<option value="' . View::e($z['z']) . '"' . ($z['z'] === $m['kreis'] ? ' selected' : '') . '>'
            . View::e('Kreis ' . $z['nr'] . ($z['ort'] !== '' ? ' · ' . mb_strimwidth($z['ort'], 0, 40, '…') : '')) . '</option>';
    }
    $wahl .= '</select></div>';
    return '<div class="mangel" data-mangel>'
        . Felder::text('text', (string) $m['text'], 'Mangel', $o + ['mehrzeilig' => true, 'zeilen' => 2, 'max' => 1000, 'klasse' => 'breit'])
        . '<div class="feld-raster">' . $wahl
        . Felder::text('frist', (string) $m['frist'], 'Frist', $o + ['typ' => 'date'])
        . Felder::text('behoben', (string) $m['behoben'], 'Behoben am', $o + ['typ' => 'date'])
        . '</div><button class="btn btn-sm btn-ghost" type="button" data-mangel-entfernen>Mangel entfernen</button></div>';
};
?>
<section class="card bericht-abschnitt" id="abschnitt-ergebnis" data-abschnitt="ergebnis">
    <div class="card-head"><h2>Prüfergebnis</h2></div>
    <div class="card-body">
        <p class="vorschlag" data-vorschlag role="status"></p>
        <div class="field" data-feld-ziel="ergebnis.entspricht" tabindex="-1">
            <span class="form-label">Die elektrische Anlage entspricht den anerkannten Regeln der Elektrotechnik</span>
            <?= Felder::dreiwahl('ergebnis.entspricht', (string) $e['entspricht'], $jaNein, ['label' => 'Entspricht den anerkannten Regeln der Elektrotechnik', 'klasse' => 'janein']) ?>
        </div>
        <div class="feld-raster">
            <div class="field"><span class="form-label">Mängelfrei</span>
                <?= Felder::dreiwahl('ergebnis.maengelfrei', (string) $e['maengelfrei'], $jaNein, ['label' => 'Mängelfrei', 'klasse' => 'janein']) ?></div>
            <div data-feld-ziel="ergebnis.instand_bis" tabindex="-1">
                <?= Felder::text('ergebnis.instand_bis', (string) $e['instand_bis'], 'Ordnungsgemäß instand zu setzen bis', ['typ' => 'date']) ?></div>
        </div>

        <div class="kreise-kopf mt">
            <h3 class="mb0">Mängel</h3>
            <div class="spacer"></div>
            <button class="btn btn-sm" type="button" data-maengel-fuellen title="Je n.i.O.-Punkt und je Stromkreis mit Mangel eine Zeile, die noch fehlt">Aus den Befunden füllen</button>
            <button class="btn btn-sm" type="button" data-mangel-neu>+ Mangel</button>
        </div>
        <div class="maengel" data-maengel>
            <?php foreach ($inhalt['maengel'] as $i => $m): ?><?= $mangel($m, 'm' . $i) ?><?php endforeach; ?>
        </div>
        <template id="mangel-vorlage"><?= $mangel(null, Felder::VORLAGE) ?></template>

        <?= Felder::text('bemerkungen', (string) $inhalt['bemerkungen'], 'Bemerkungen', ['mehrzeilig' => true, 'zeilen' => 4, 'max' => 4000]) ?>
        <div class="feld-raster">
            <div class="field"><span class="form-label">Prüfplakette angebracht</span>
                <?= Felder::dreiwahl('ergebnis.plakette', (string) $e['plakette'], $jaNein, ['label' => 'Prüfplakette', 'klasse' => 'janein']) ?></div>
            <?= Felder::text('ergebnis.plakette_nr', (string) $e['plakette_nr'], 'Plakette Nr.', ['max' => 64]) ?>
            <?= Felder::text('ergebnis.naechste', (string) $e['naechste'], 'Nächster Prüftermin', ['max' => 255, 'klasse' => 'breit']) ?>
            <div class="field"><span class="form-label">Thermographie im Anhang</span>
                <?= Felder::dreiwahl('anhang.thermographie', (string) $anhang['thermographie'], $jaNein, ['label' => 'Thermographie', 'klasse' => 'janein']) ?></div>
            <?= Felder::text('anhang.weitere', (string) $anhang['weitere'], 'Weitere Anlagen zum Bericht', ['max' => 255]) ?>
        </div>
        <div class="errichter" data-feld-ziel="ergebnis.errichter" tabindex="-1">
            <?= Felder::haken('ergebnis.errichter', (string) $e['errichter'] === '1', 'Errichterbestätigung (DGUV Vorschrift 3 § 5 Abs. 4)', [
                'hilfe' => $texte['errichter'] . ' – nur bei „entspricht: Ja“.']) ?>
        </div>
    </div>
</section>
