<?php
use App\Support\Settings;
use App\Support\View;

/**
 * Angaben, die aus der Seite eine Web-App machen.
 *
 * Wozu: legt man MasterAdmin auf einem iPhone oder iPad auf den
 * Home-Bildschirm, startet Safari es sonst wie eine gewöhnliche Webseite –
 * mit Adressleiste oben und Tableiste unten. Auf einem Tablet, das in der
 * Halle zum Einzeichnen von Kabeln mitläuft, sind das zwei Streifen
 * Arbeitsfläche, die fehlen. Safari lässt sie nur weg, wenn die Seite sich
 * ausdrücklich als eigenständige Web-App ausweist.
 *
 * Warum als eigener Baustein und nicht im Layout: die Anmeldung benutzt
 * ein anderes Layout. Stünde das nur im großen, käme jeder, der aus der
 * Web-App heraus abgemeldet wird, auf einer Seite heraus, deren Inhalt
 * unter der Uhr klebt.
 *
 * viewport-fit=cover lässt die Seite bis an die abgerundeten Ränder
 * reichen; den Abstand zu Uhr und Home-Indikator holt sich das
 * Stylesheet aus env(safe-area-inset-*).
 */
?>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="manifest" href="/manifest">
<?php /* Die beiden apple-Zeilen sind die ältere Schreibweise desselben und
         werden von iOS-Ständen gebraucht, die das Manifest noch nicht
         auswerten. */ ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<?php /* „black-translucent": die Seite reicht unter die Statusleiste. */ ?>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= View::e(Settings::homeName()) ?>">
<meta name="theme-color" content="#ffffff">
<?php /* iOS nimmt für den Home-Bildschirm kein SVG – daher ein echtes PNG. */ ?>
<link rel="apple-touch-icon" sizes="180x180" href="<?= View::asset('/assets/img/icon-180.png') ?>">
