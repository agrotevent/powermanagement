/*
 * Bildschirmfotos für die Dokumentation – aus dem Demo-Gelände.
 *
 * Voraussetzung: eine Installation im Demo-Modus mit frischen Demo-Daten und
 * Playwright (etwa `npm i -g playwright`, Chromium dazu). Dann:
 *
 *   php bin/power demo:seed --reset
 *   php -S 127.0.0.1:8080 -t public &
 *   NPM_ROOT=$(npm root -g) node docs/bilder/aufnehmen.mjs http://127.0.0.1:8080
 *
 * Die Bilder landen neben diesem Skript und werden von docs/*.md und der
 * README eingebunden. Nach Änderungen an der Oberfläche einfach neu aufnehmen.
 */
import { createRequire } from 'module';
import { dirname } from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const { chromium } = require((process.env.NPM_ROOT ? process.env.NPM_ROOT + '/' : '') + 'playwright');
const basis = (process.argv[2] || 'http://127.0.0.1:8080').replace(/\/$/, '');
const benutzer = process.env.PM_BENUTZER || 'admin';
const kennwort = process.env.PM_KENNWORT || 'PowerAdmin2026!';
const ziel = dirname(fileURLToPath(import.meta.url));

const DESKTOP = { width: 1280, height: 820 };
const HANDY = { width: 390, height: 844 };

// Deutsche Oberfläche auch für Datums- und Zeitfelder des Browsers.
const browser = await chromium.launch({
    args: ['--lang=de-DE'], env: { ...process.env, LANGUAGE: 'de', LANG: 'de_DE.UTF-8' },
    ...(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}),
});
const fehler = [];

async function sitzung(viewport, extra = {}) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, locale: 'de-DE', timezoneId: 'Europe/Berlin', ...extra });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => fehler.push(page.url() + ': ' + e.message));
    await page.goto(basis + '/login');
    await page.fill('#username', benutzer);
    await page.fill('#password', kennwort);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return { ctx, page };
}

async function oeffnen(page, pfad, warten = 400) {
    await page.goto(basis + pfad, { waitUntil: 'networkidle' });
    await page.waitForTimeout(warten);
}

async function foto(page, name, optionen = {}) {
    await page.screenshot({ path: `${ziel}/${name}.png`, ...optionen });
    console.log('  ' + name + '.png');
}

/** Der obere Teil einer langen Seite – höchstens `hoehe` Pixel. */
async function oben(page, name, hoehe) {
    const gesamt = await page.evaluate(() => document.documentElement.scrollHeight);
    await foto(page, name, { fullPage: true, clip: { x: 0, y: 0, width: page.viewportSize().width, height: Math.min(hoehe, gesamt) } });
}

async function element(page, locator, name) {
    await locator.first().screenshot({ path: `${ziel}/${name}.png` });
    console.log('  ' + name + '.png');
}

/** Adresse des ersten Links, dessen Text passt. */
async function link(page, text) {
    return page.locator('a', { hasText: text }).first().getAttribute('href');
}

// ------------------------------------------------------------------ Desktop
{
    const { ctx, page } = await sitzung(DESKTOP);
    await oeffnen(page, '/dashboard', 1200);
    await foto(page, 'uebersicht');

    await oeffnen(page, '/karte', 1800);
    await foto(page, 'karte');
    // Wert neben dem Hauptverteiler antippen: die Werte je Phase im Plan
    await page.locator('.pm-anlage', { hasText: 'Hauptverteiler' }).locator('[data-wert]').click();
    await page.waitForTimeout(900);
    await element(page, page.locator('#karte'), 'karte-phasen');
    await page.keyboard.press('Escape');
    // Ein Kabel antippen: Länge, Belastbarkeit, Spannungsfall
    await oeffnen(page, '/karte', 1500);
    await page.locator('.pm-anlage', { hasText: 'Foodtruck-Verteiler' }).locator('.pm-symbol').click();
    await page.waitForTimeout(900);
    await foto(page, 'karte-anlage');
    // Georeferenz
    await page.click('[data-modus="geo"]');
    await page.waitForTimeout(500);
    await foto(page, 'georeferenz');

    await oeffnen(page, '/anlagen');
    const hv = await link(page, 'Hauptverteiler');
    await oeffnen(page, hv, 1200);
    await foto(page, 'anlage');
    await element(page, page.locator('.card', { has: page.locator('h2', { hasText: 'Verteilerplan' }) }), 'verteilerplan');
    await oeffnen(page, '/anlagen/katalog');
    await foto(page, 'katalog');
    await oeffnen(page, '/statistik?anlage=' + hv.split('/').pop(), 2200);
    await oben(page, 'statistik', 1500);

    await oeffnen(page, '/bereiche');
    const bereich = await link(page, 'Foodtruck-Meile');
    await oeffnen(page, bereich, 2200);
    await oben(page, 'bereich', 1300);
    await oeffnen(page, bereich + '?reiter=material');
    await foto(page, 'material', { fullPage: true });

    await oeffnen(page, '/inventar');
    await foto(page, 'inventar');
    await oeffnen(page, await link(page, 'PD63-07'));
    await foto(page, 'inventar-stueck');
    await oeffnen(page, '/anlagen');
    await oeffnen(page, (await link(page, 'Foodtruck-Verteiler')) + '?reiter=messung', 800);
    await foto(page, 'einsetzen');

    await oeffnen(page, '/aufbau');
    const aufbau = await link(page, 'Sommerfestival');
    await oeffnen(page, aufbau);
    await foto(page, 'aufbau');
    await oeffnen(page, aufbau + '/abgleich', 600);
    await oben(page, 'abgleich', 1400);

    await oeffnen(page, '/system/benutzer');
    // Die Zeile des Demo-Kontos „veranstalter“ (nicht die Rolle „Kunde / Veranstalter“)
    const zeile = page.locator('tr').filter({ has: page.locator('td', { hasText: /^\s*veranstalter\s*$/ }) })
        .or(page.locator('tr').filter({ has: page.locator('strong', { hasText: /^veranstalter$/ }) }));
    await oeffnen(page, await zeile.locator('a[href*="freigaben"]').first().getAttribute('href'));
    await foto(page, 'freigaben');
    await oeffnen(page, '/location');
    await foto(page, 'location');
    await oeffnen(page, '/datenquellen');
    await foto(page, 'datenquellen');
    await ctx.close();
}

// ------------------------------------------------------------------ Handy
{
    // GPS mitten auf dem Gelände: aus der Georeferenz der Etage gerechnet.
    const { ctx, page } = await sitzung(HANDY);
    await oeffnen(page, '/karte', 1500);
    await foto(page, 'karte-handy');
    const etage = await page.locator('#karte').getAttribute('data-etage');
    const geo = await page.evaluate(async (e) => (await (await fetch(`/karte/${e}/stand`, { headers: { Accept: 'application/json' } })).json()).geo, etage);
    await ctx.close();
    if (geo) {
        const R = 6371008.8;
        const k = R * Math.PI / 180;
        const kx = k * Math.cos(geo.a.lat * Math.PI / 180);
        const px = geo.b.x - geo.a.x;
        const py = -(geo.b.y - geo.a.y) * geo.verhaeltnis;
        const ost = (geo.b.lon - geo.a.lon) * kx;
        const nord = (geo.b.lat - geo.a.lat) * k;
        const n = px * px + py * py;
        const sr = (ost * px + nord * py) / n;
        const si = (nord * px - ost * py) / n;
        const qx = 50 - geo.a.x;
        const qy = -(60 - geo.a.y) * geo.verhaeltnis;
        const lat = geo.a.lat + (sr * qy + si * qx) / k;
        const lon = geo.a.lon + (sr * qx - si * qy) / kx;
        const gps = await sitzung(HANDY, { geolocation: { latitude: lat, longitude: lon, accuracy: 6 }, permissions: ['geolocation'] });
        await oeffnen(gps.page, '/karte', 1500);
        await gps.page.click('[data-aktion="position"]');
        await gps.page.waitForTimeout(1500);
        await foto(gps.page, 'gps-handy');
        await gps.ctx.close();
    }
    const auf = await sitzung(HANDY);
    await oeffnen(auf.page, '/karte?modus=aufbau', 1500);
    await auf.page.locator('.pm-anlage', { hasText: 'Kasse Einlass' }).locator('.pm-symbol').click();
    await auf.page.waitForTimeout(1200);
    await foto(auf.page, 'aufbau-handy', { fullPage: true });
    await oeffnen(auf.page, '/bereiche', 800);
    await foto(auf.page, 'bereiche-handy');
    await auf.ctx.close();
}

await browser.close();
if (fehler.length) {
    console.error('Fehler auf den Seiten:\n' + fehler.join('\n'));
    process.exit(1);
}
