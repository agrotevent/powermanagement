<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Zeit;

/**
 * bin/power update:* – derselbe Weg wie die Oberfläche: ein Auftrag an den
 * Update-Dienst, dann (mit --warten) der Fortschritt im Terminal. Strg+C oder
 * ein Verbindungsabbruch beenden nur die Anzeige; das Update läuft im Dienst
 * weiter.
 *
 *   update:stand                        Einrichtung, installiert, verfügbar, Lauf
 *   update:suchen [--warten]            Auftrag „pruefen“
 *   update:starten [--warten] [--trotz-betrieb] [--ziel=<sha40>]
 *                                       erst suchen (Stand älter als 60 s), dann
 *                                       „aktualisieren“ auf den verfügbaren Stand;
 *                                       Exit wie update-debian.sh (0/1/2/3)
 *   update:automatik [aus|suchen|einspielen] [--tage=1,2,…] [--beginn=HH:MM]
 *                    [--dauer=MIN] [--karenz=STD] [--pause-bis=JJJJ-MM-TT|--pause-bis=]
 *                    [--versatz=MIN]    automatische Updates einstellen (ohne Modus:
 *                                       anzeigen); nicht genannte Werte bleiben
 *   update:zeitplan                     nur für powermanagement-update-zeitplan.timer
 *
 * Als root nur update:stand (lesen) – ein Auftrag von root gehörte root, und
 * der Helfer liest ihn als App-Benutzer; root-eigene -wal/-shm neben der
 * SQLite ebenso. Gehört storage/ nicht root (jede Installation), kommt root
 * gar nicht bis hierher: bin/power lehnt vor dem require ab. Hier zählt
 * zusätzlich storage/update (bzw. update.verzeichnis) – auch als Verweis.
 */
final class UpdateKonsole
{
    /** Wie alt der Stand höchstens sein darf, bevor update:starten erst sucht. */
    private const STAND_FRISCH = 60;
    /** So lange wartet update:suchen --warten. */
    private const SUCHEN_WARTEN = 120;
    /** Länger läuft kein Update (TimeoutStartSec=60min) – plus Zurückrollen. */
    private const UPDATE_WARTEN = 4500;

    /**
     * @param list<string> $werte
     * @param array<string,string|true> $optionen
     * @param callable(string):void $aus
     * @param callable(string):never $fehler
     */
    public static function ausfuehren(string $befehl, array $werte, array $optionen, callable $aus, callable $fehler): int
    {
        $root = UpdateService::fremderRoot();
        if ($befehl !== 'update:stand' && $root) {
            $fehler('Bitte als Anwendungsbenutzer: runuser -u ' . self::appBenutzer() . ' -- php bin/power ' . $befehl
                . ' (als root gehörte der Auftrag root, und der Update-Dienst liest ihn als Anwendungsbenutzer).');
        }
        return match ($befehl) {
            'update:stand'     => self::stand($aus, !$root),
            'update:suchen'    => self::suchen($optionen, $aus, $fehler),
            'update:starten'   => self::starten($optionen, $aus, $fehler),
            'update:automatik' => self::automatik($werte, $optionen, $aus, $fehler),
            'update:zeitplan'  => self::zeitplan($optionen, $aus),
            default            => 1,
        };
    }

    private static function appBenutzer(): string
    {
        $dir = UpdateService::verzeichnis();
        clearstatcache();
        $info = @lstat($dir);
        if ($info === false || is_link($dir)) {
            $info = @lstat(dirname($dir));
        }
        $uid = $info !== false ? (int) $info['uid'] : false;
        if ($uid !== false && $uid !== 0 && function_exists('posix_getpwuid')) {
            $name = (string) (posix_getpwuid($uid)['name'] ?? '');
            if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) === 1) {
                return $name;
            }
        }
        return 'www-data';
    }

    /** Wer auf der Konsole: der Benutzer hinter sudo, sonst „konsole“. */
    private static function von(): string
    {
        $sudo = (string) getenv('SUDO_USER');
        return preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $sudo) === 1 && $sudo !== 'root' ? $sudo : 'konsole';
    }

    private static function kurz(?string $sha): string
    {
        return $sha !== null ? substr($sha, 0, 7) : '–';
    }

    private static function zeit(?int $ts, string $format = 'd.m.Y H:i'): string
    {
        return $ts !== null ? Zeit::anzeige(Zeit::iso($ts), $format) : '–';
    }

    // ================================================================ update:stand

    /** @param callable(string):void $aus */
    private static function stand(callable $aus, bool $vermerken): int
    {
        if ($vermerken) {
            UpdateService::vermerken();
            UpdateService::vergessen();
        }
        $z = UpdateService::zustand();
        if ($z['zustand'] === 'demo') {
            $aus('Update-Funktion: Demo-Modus – hier werden keine Updates eingespielt');
            return 0;
        }
        if ($z['zustand'] === 'nicht_eingerichtet') {
            $aus('Update-Funktion: nicht eingerichtet – einmal auf der Konsole umsteigen (docs/installation-debian-lxc.md, Update)');
        } else {
            $aus('Update-Funktion: eingerichtet (Dienst meldete sich ' . Zeit::abstand(Zeit::iso((int) $z['helfer']['zeit'])) . ')'
                . ($z['still'] ? ' – meldet sich nicht mehr: systemctl status powermanagement-update.path powermanagement-update.timer' : ''));
        }
        $inst = UpdateService::installiert();
        $aus('Installiert:     ' . ($inst['commit'] !== null
            ? $inst['kurz'] . ($inst['zeit'] !== null ? ' vom ' . self::zeit($inst['zeit']) : '') . ($inst['zweig'] !== null ? ' (Zweig ' . $inst['zweig'] . ')' : ' (kein Zweig)')
            : 'unbekannt'));
        $stand = $z['stand'];
        if ($stand !== null) {
            $gesucht = $stand['abruf_zeit'] !== null ? ' (gesucht ' . Zeit::abstand(Zeit::iso($stand['abruf_zeit'])) . ')' : ' (noch nie gesucht)';
            $aus('Verfügbar:       ' . match (true) {
                in_array($stand['abruf'], ['abruf', 'kein_zugang'], true) => 'Suche fehlgeschlagen – ' . UpdateService::grundText($stand['abruf']),
                $stand['zweig'] === null       => 'kein Zweig ausgecheckt – nur über die Konsole',
                $stand['lokal_geaendert']      => 'lokale Änderungen im Code – nur über die Konsole (update-debian.sh --erzwingen)',
                !$stand['linear'] && $stand['verfuegbar'] !== null => 'Verlauf nicht linear – nur über die Konsole',
                $stand['neu'] > 0              => $stand['neu'] . ' Änderung' . ($stand['neu'] === 1 ? '' : 'en') . ' bis ' . self::kurz($stand['verfuegbar']['commit'] ?? null),
                default                        => 'aktuell',
            } . $gesucht);
        }
        $aus('Automatik:       ' . self::automatikZeile($z));
        $lauf = $z['lauf'];
        if (UpdateService::aktiv($z)) {
            $aus('Läuft gerade:    ' . ($z['zustand'] === 'angefordert' ? 'Auftrag wartet auf den Update-Dienst' . ($z['haengt'] ? ' (hängt)' : '')
                : ($lauf['aktion'] ?? 'Update') . ', Schritt ' . $lauf['schritt'] . '/' . $lauf['gesamt'] . ' ' . $lauf['schritt_name']
                  . ($z['keine_rueckmeldung'] ? ' – keine Rückmeldung seit ' . UpdateService::dauerText($z['puls_alter']) : '')));
        }
        $letztes = UpdateService::verlauf(1)[0] ?? null;
        if ($letztes !== null) {
            $wer = match ($letztes['ausloeser']) {
                'hand' => 'von Hand' . ($letztes['von'] !== '' ? ' (' . $letztes['von'] . ')' : ''),
                'automatik' => 'automatisch', 'system' => 'Update-Dienst', default => 'Konsole',
            };
            $aus('Letztes Update:  ' . self::zeit($letztes['beginn']) . ' · ' . $wer . ' · ' . $letztes['ergebnis']
                . ($letztes['grund'] !== null ? ' (' . $letztes['grund'] . ')' : '')
                . ' ' . self::kurz($letztes['alt']) . ($letztes['neu'] !== null && $letztes['neu'] !== $letztes['alt'] ? ' → ' . self::kurz($letztes['neu']) : '')
                . ' (' . UpdateService::dauerText($letztes['dauer']) . ')');
        }
        if ($z['offen'] !== 'nein') {
            $aus('Achtung:         ' . ($z['offen'] === 'aufgegeben'
                ? 'ein unterbrochenes Update ließ sich nicht zurückrollen – cat /var/lib/powermanagement/update/offen'
                : 'ein unterbrochenes Update wird zurückgerollt (Update-Dienst, spätestens nach 15 min)'));
        }
        if ($z['abgeschaltet']) {
            $aus('Oberfläche:      Updates aus der Oberfläche abgeschaltet (/etc/powermanagement/update.conf)');
        }
        return 0;
    }

    /**
     * „nur suchen und anzeigen“, „automatisch einspielen (täglich ab 04:00 …), nächstes Fenster …“
     *
     * @param array<string,mixed> $z wie UpdateService::zustand()
     */
    private static function automatikZeile(array $z): string
    {
        $a = UpdateAutomatik::anzeige(null, $z);
        $e = $a['einstellungen'];
        $zeile = $a['modus_text'];
        if ($e['modus'] === 'einspielen') {
            $zeile .= ' (' . UpdateAutomatik::fensterText($e) . ')';
            if ($a['pausiert']) {
                $zeile .= ', pausiert bis ' . self::zeit($e['pause_bis'], 'd.m.Y');
            } elseif ($a['naechstes'] !== null) {
                $zeile .= ', nächstes Fenster ' . UpdateAutomatik::terminText($a['naechstes']);
            }
            if ($a['letzter'] !== null) {
                $zeile .= '; zuletzt ' . self::zeit($a['letzter']['zeit'], 'd.m. H:i') . ' ' . UpdateAutomatik::letzterText($a['letzter']);
            }
            if ($z['automatik_root']) {
                $zeile .= ' – auf diesem System abgeschaltet (update.conf: AUTOMATIK=nein)';
            }
        }
        if ($a['zeitplan_still']) {
            $zeile .= ' – der Zeitplan läuft nicht: systemctl status powermanagement-update-zeitplan.timer powermanagement-update-zeitplan.service';
        }
        return $zeile;
    }

    // ================================================================ update:automatik

    /**
     * Ohne Modus: anzeigen. Mit Modus: speichern – nicht genannte Werte
     * bleiben, wie sie sind (eine abgelaufene Pause fällt weg).
     *
     * @param list<string> $werte
     * @param array<string,string|true> $optionen
     * @param callable(string):void $aus
     * @param callable(string):never $fehler
     */
    private static function automatik(array $werte, array $optionen, callable $aus, callable $fehler): int
    {
        if (Config::isDemo()) {
            $fehler('Im Demo-Modus werden keine Updates eingestellt.');
        }
        $erlaubt = ['tage', 'beginn', 'dauer', 'karenz', 'pause-bis', 'versatz'];
        foreach (array_keys($optionen) as $k) {
            if (!in_array((string) $k, $erlaubt, true)) {
                $fehler('Unbekannte Option --' . $k . ' (erlaubt: --' . implode(', --', $erlaubt) . ').');
            }
        }
        $e = UpdateAutomatik::einstellungen();
        if ($werte === []) {
            if ($optionen !== []) {
                $fehler('Erst den Modus nennen: php bin/power update:automatik aus|suchen|einspielen [--tage=…] …');
            }
            self::automatikZeigen($aus);
            return 0;
        }
        if (count($werte) > 1) {
            $fehler('Nur ein Modus: aus, suchen oder einspielen.');
        }
        $wert = static function (string $k) use ($optionen, $fehler): ?string {
            if (!array_key_exists($k, $optionen)) {
                return null;
            }
            if ($optionen[$k] === true) {
                $fehler('--' . $k . ' braucht einen Wert (--' . $k . '=…).');
            }
            return trim((string) $optionen[$k]);
        };
        $zahl = static function (string $k, int $vorher) use ($wert, $fehler): int {
            $v = $wert($k);
            if ($v === null) {
                return $vorher;
            }
            if (preg_match('/^[0-9]{1,4}$/', $v) !== 1) {
                $fehler('--' . $k . ' erwartet eine ganze Zahl.');
            }
            return (int) $v;
        };
        $tage = $wert('tage');
        $tage = $tage === null ? $e['tage'] : ($tage === '' ? [] : array_map('trim', explode(',', $tage)));
        $pause = $wert('pause-bis');
        if ($pause === null) {
            $pause = $e['pause_bis'] !== null && $e['pause_bis'] > time() ? Zeit::anzeige(Zeit::iso($e['pause_bis']), 'Y-m-d') : '';
        }
        $erg = UpdateAutomatik::speichern(
            $werte[0],
            $tage,
            $wert('beginn') ?? $e['beginn'],
            $zahl('dauer', $e['dauer']),
            $zahl('karenz', $e['karenz']),
            $pause,
            array_key_exists('versatz', $optionen) ? $zahl('versatz', 0) : null
        );
        if (!$erg['ok']) {
            $fehler($erg['message']);
        }
        Audit::log('system.update.automatik', 'update', '', $erg['werte'] ?? []);
        $aus($erg['message']);
        self::automatikZeigen($aus);
        return 0;
    }

    /** @param callable(string):void $aus */
    private static function automatikZeigen(callable $aus): void
    {
        $a = UpdateAutomatik::anzeige();
        $e = $a['einstellungen'];
        $aus('Modus:           ' . $a['modus_text']);
        $aus('Fenster:         ' . UpdateAutomatik::fensterText($e));
        $aus('Pausiert bis:    ' . ($a['pausiert'] ? self::zeit($e['pause_bis'], 'd.m.Y') . ' (Ende des Tages)' : '–'));
        if ($e['modus'] === 'einspielen') {
            $aus('Nächstes:        ' . ($a['naechstes'] !== null ? UpdateAutomatik::terminText($a['naechstes']) : 'kein Fenster (kein Wochentag)'));
        }
        $aus('Zuletzt:         ' . ($a['letzter'] !== null ? self::zeit($a['letzter']['zeit']) . ' – ' . UpdateAutomatik::letzterText($a['letzter']) : '–'));
        $aus('Zeitplan:        ' . ($a['zeitplan_alter'] === null ? 'noch nie gelaufen' : 'zuletzt ' . Zeit::abstand(Zeit::iso(time() - (int) $a['zeitplan_alter'])))
            . ($a['zeitplan_still'] ? ' – läuft nicht: systemctl status powermanagement-update-zeitplan.timer' : ''));
    }

    // ================================================================ update:zeitplan

    /**
     * Für powermanagement-update-zeitplan.timer: gibt nur aus, was geschah
     * (mit --gespraechig auch, warum nichts).
     *
     * @param array<string,string|true> $optionen
     * @param callable(string):void $aus
     */
    private static function zeitplan(array $optionen, callable $aus): int
    {
        $r = UpdateAutomatik::zeitplan();
        if ($r['meldung'] !== '') {
            $aus($r['meldung']);
        } elseif (isset($optionen['gespraechig'])) {
            $aus('Nichts zu tun (' . $r['grund'] . ').');
        }
        return 0;
    }

    // ================================================================ update:suchen

    /**
     * @param array<string,string|true> $optionen
     * @param callable(string):void $aus
     * @param callable(string):never $fehler
     */
    private static function suchen(array $optionen, callable $aus, callable $fehler): int
    {
        $kennung = self::auftrag('pruefen', null, false, $aus, $fehler);
        if ($kennung === null) {
            return 1;
        }
        $aus('Suche angefordert (Auftrag ' . substr($kennung, 0, 8) . ').');
        if (!isset($optionen['warten'])) {
            return 0;
        }
        $lauf = self::abwarten($kennung, self::SUCHEN_WARTEN, null);
        if ($lauf === null) {
            $aus('Noch kein Ergebnis – später nachsehen: php bin/power update:stand');
            return 1;
        }
        self::standAbwarten((int) ($lauf['ende'] ?? 0));
        $aus('Ergebnis:        ' . self::ergebnisZeile($lauf));
        self::stand($aus, false);
        return UpdateService::exitCode($lauf['ergebnis']);
    }

    // ================================================================ update:starten

    /**
     * @param array<string,string|true> $optionen
     * @param callable(string):void $aus
     * @param callable(string):never $fehler
     */
    private static function starten(array $optionen, callable $aus, callable $fehler): int
    {
        $ziel = $optionen['ziel'] ?? null;
        if ($ziel !== null && (!is_string($ziel) || preg_match('/^[0-9a-f]{40}$/', $ziel) !== 1)) {
            $fehler('--ziel erwartet einen vollständigen Commit (40 Zeichen, 0-9a-f).');
        }
        $stand = UpdateService::stand();
        if ($ziel === null && ($stand === null || $stand['abruf_zeit'] === null || time() - $stand['abruf_zeit'] > self::STAND_FRISCH)) {
            $aus('Erst nach neuen Ständen suchen …');
            // Ohne frischen Stand kein Update – sonst hieße es womöglich
            // „aktuell“, obwohl nur die Suche nicht ging.
            $suche = self::auftrag('pruefen', null, false, $aus, $fehler);
            $lauf = self::abwarten((string) $suche, self::SUCHEN_WARTEN, null);
            if ($lauf === null) {
                $aus('Die Suche meldet sich nicht – später erneut versuchen (php bin/power update:stand).');
                return 1;
            }
            self::standAbwarten((int) ($lauf['ende'] ?? 0));
            if ($lauf['ergebnis'] !== 'geprueft') {
                $aus('Suche: ' . self::ergebnisZeile($lauf));
                return UpdateService::exitCode($lauf['ergebnis']) === 0 ? 1 : UpdateService::exitCode($lauf['ergebnis']);
            }
            UpdateService::vergessen();
            $stand = UpdateService::stand();
        }
        if ($ziel === null) {
            if ($stand !== null && $stand['neu'] === 0 && $stand['linear']) {
                $aus('Bereits auf dem neuesten Stand (' . self::kurz(UpdateService::installiert()['commit']) . ').');
                return 0;
            }
            $ziel = $stand['verfuegbar']['commit'] ?? null;
            if ($ziel === null) {
                $fehler(UpdateService::sperrgrund('aktualisieren') ?? 'Kein verfügbarer Stand bekannt – erst suchen: php bin/power update:suchen --warten');
            }
        }
        $kennung = self::auftrag('aktualisieren', $ziel, isset($optionen['trotz-betrieb']), $aus, $fehler);
        if ($kennung === null) {
            return 1;
        }
        $aus('Update auf ' . substr((string) $ziel, 0, 7) . ' angefordert (Auftrag ' . substr($kennung, 0, 8) . ').');
        if (!isset($optionen['warten'])) {
            $aus('Fortschritt: journalctl -a -f -u powermanagement-update   oder   php bin/power update:stand');
            return 0;
        }
        $aus('Strg+C beendet nur die Anzeige – das Update läuft im Update-Dienst weiter.');
        return self::fortschritt($kennung, $aus);
    }

    /**
     * Auftrag stellen und ins Audit; null, wenn er nicht gestellt wurde (die
     * Meldung steht dann schon da). Ohne $fehler endet ein Fehler nicht mit
     * Exit, sondern nur mit der Meldung.
     *
     * @param callable(string):void $aus
     * @param (callable(string):never)|null $fehler
     */
    private static function auftrag(string $aktion, ?string $ziel, bool $trotz, callable $aus, ?callable $fehler): ?string
    {
        try {
            $erg = UpdateService::anfordern($aktion, $ziel, 'konsole', self::von(), $trotz);
        } catch (\RuntimeException $e) {
            $erg = ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!empty($erg['bestaetigen'])) {
            $aus('Gerade ist Betrieb – ein Update unterbricht Messung und Schalten für 1–3 Minuten:');
            foreach ($erg['hindernisse'] ?? [] as $h) {
                $aus('  · ' . $h['text']);
            }
            $aus('Bewusst trotzdem: php bin/power update:starten --warten --trotz-betrieb');
            return null;
        }
        if (!$erg['ok']) {
            if ($fehler !== null) {
                $fehler((string) $erg['message']);
            }
            $aus((string) $erg['message']);
            return null;
        }
        $kennung = (string) $erg['kennung'];
        $details = ['aktion' => $aktion, 'kennung' => $kennung, 'ausloeser' => 'konsole'];
        if ($aktion === 'aktualisieren') {
            $details += ['ziel7' => substr((string) $ziel, 0, 7), 'trotz_betrieb' => $trotz, 'hindernisse' => array_column($erg['hindernisse'] ?? [], 'art')];
        }
        Audit::log('system.update.anfordern', 'update', $kennung, $details);
        return $kennung;
    }

    /**
     * Bis der Lauf mit dieser Kennung fertig ist (oder abgelehnt wurde).
     *
     * @param (callable(array<string,mixed>):void)|null $jeRunde
     * @return array<string,mixed>|null
     */
    private static function abwarten(string $kennung, int $sekunden, ?callable $jeRunde): ?array
    {
        $bis = time() + $sekunden;
        do {
            UpdateService::vergessen();
            $lauf = UpdateService::lauf();
            if ($lauf !== null && $lauf['kennung'] === $kennung) {
                if ($jeRunde !== null) {
                    $jeRunde($lauf);
                }
                if ($lauf['zustand'] !== 'laeuft') {
                    return $lauf;
                }
            }
            usleep(500_000);
        } while (time() < $bis);
        return null;
    }

    /** Der Helfer schreibt ist/stand gleich nach dem Lauf – kurz darauf warten. */
    private static function standAbwarten(int $ende): void
    {
        for ($i = 0; $i < 20; $i++) {
            UpdateService::vergessen();
            $stand = UpdateService::stand();
            if ($stand !== null && $stand['erzeugt'] >= $ende) {
                return;
            }
            usleep(500_000);
        }
    }

    /** @param array<string,mixed> $lauf */
    private static function ergebnisZeile(array $lauf): string
    {
        $e = (string) ($lauf['ergebnis'] ?? '');
        $zeile = $e !== '' ? UpdateService::ergebnisText($e) : 'ohne Ergebnis';
        if ($lauf['grund'] !== null) {
            $zeile .= '. ' . UpdateService::grundText($lauf['grund']);
        }
        if ($lauf['alt'] !== null && $lauf['neu'] !== null && $lauf['neu'] !== $lauf['alt']) {
            $zeile .= ' (' . self::kurz($lauf['alt']) . ' → ' . self::kurz($lauf['neu']) . ')';
        }
        if ($lauf['beginn'] !== null && $lauf['ende'] !== null) {
            $zeile .= ', ' . UpdateService::dauerText(max(0, $lauf['ende'] - $lauf['beginn']));
        }
        return $zeile;
    }

    /**
     * Den Fortschritt eines Updates zeigen, bis es fertig ist. Stirbt das
     * Update (keine Ergebnisdatei, Marke steht), rollt der Helfer gleich
     * zurück – unter eigener Kennung (wiederaufnehmen); dem wird gefolgt.
     *
     * @param callable(string):void $aus
     */
    private static function fortschritt(string $kennung, callable $aus): int
    {
        // Während des Updates kann die Datenbankdatei ausgetauscht werden
        // (Zurückrollen) – keine offene Verbindung zur alten halten.
        Database::trennen();
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $ende = static function () use ($aus): void {
                $aus('');
                $aus('Anzeige beendet – das Update läuft im Update-Dienst weiter: journalctl -a -f -u powermanagement-update');
                exit(130);
            };
            pcntl_signal(SIGINT, $ende);
            pcntl_signal(SIGTERM, $ende);
            pcntl_signal(SIGHUP, $ende);
        }
        $zuletzt = '';
        $zeigen = static function (array $lauf) use ($aus, &$zuletzt): void {
            if ($lauf['zustand'] !== 'laeuft') {
                return;
            }
            $zeile = '[' . $lauf['schritt'] . '/' . $lauf['gesamt'] . '] ' . ($lauf['schritt_name'] !== '' ? $lauf['schritt_name'] : '…');
            if ($zeile !== $zuletzt) {
                $zuletzt = $zeile;
                $aus(date('H:i:s') . '  ' . $zeile);
            }
        };
        $angefordert = time();
        $bis = time() + self::UPDATE_WARTEN;
        $aktuell = $kennung;
        while (time() < $bis) {
            UpdateService::vergessen();
            $lauf = UpdateService::lauf();
            if ($lauf === null || $lauf['kennung'] !== $aktuell) {
                $auftrag = UpdateService::auftrag();
                if ($aktuell === $kennung && $auftrag !== null && time() - $angefordert > UpdateService::HAENGT) {
                    $aus('Der Update-Dienst übernimmt den Auftrag nicht. Als root:');
                    $aus('  systemctl reset-failed powermanagement-update.service powermanagement-update.path');
                    $aus('  systemctl restart powermanagement-update.path');
                    return 1;
                }
                usleep(500_000);
                continue;
            }
            $zeigen($lauf);
            if ($lauf['zustand'] === 'laeuft') {
                if (time() - (int) ($lauf['puls'] ?? time()) > UpdateService::VERSCHWUNDEN) {
                    $aus('Der Update-Dienst meldet sich seit über 30 Minuten nicht – journalctl -a -u powermanagement-update -n 100');
                    return 3;
                }
                usleep(1_000_000);
                continue;
            }
            // Fertig. Starb das Update mitten im Lauf, rollt der Helfer gleich zurück.
            if ($lauf['ergebnis'] === 'abgebrochen' && $lauf['aktion'] === 'aktualisieren') {
                $folge = self::wiederaufnahmeAbwarten((int) ($lauf['ende'] ?? time()));
                if ($folge !== null) {
                    $aus(date('H:i:s') . '  Das Update wurde unterbrochen – der Update-Dienst rollt zurück …');
                    $aktuell = $folge;
                    $zuletzt = '';
                    continue;
                }
            }
            self::standAbwarten((int) ($lauf['ende'] ?? 0));
            return self::ergebnisZeigen($lauf, $aus);
        }
        $aus('Das Update läuft länger als erwartet – php bin/power update:stand, journalctl -a -u powermanagement-update');
        return 3;
    }

    /** Kennung des Wiederanlaufs, der nach einem gestorbenen Update beginnt – oder null. */
    private static function wiederaufnahmeAbwarten(int $ende): ?string
    {
        for ($i = 0; $i < 30; $i++) {
            UpdateService::vergessen();
            $lauf = UpdateService::lauf();
            if ($lauf !== null && $lauf['aktion'] === 'wiederaufnehmen' && (int) ($lauf['beginn'] ?? 0) >= $ende) {
                return $lauf['kennung'];
            }
            // Keine Marke mehr (oder der Helfer ist fertig): es kommt nichts nach.
            $helfer = UpdateService::helfer();
            if ($i >= 4 && ($helfer === null || ($helfer['offen'] === 'nein' && $helfer['zeit'] >= $ende))) {
                return null;
            }
            usleep(500_000);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $lauf
     * @param callable(string):void $aus
     */
    private static function ergebnisZeigen(array $lauf, callable $aus): int
    {
        $aus('');
        $aus('Ergebnis: ' . self::ergebnisZeile($lauf));
        foreach ($lauf['warnungen'] as $w) {
            $aus('  Hinweis: ' . UpdateService::warnungText($w));
        }
        $rc = UpdateService::exitCode($lauf['ergebnis']);
        if ($lauf['ergebnis'] === 'abgebrochen') {
            UpdateService::vergessen();
            $offen = UpdateService::helfer()['offen'] ?? 'ja';
            $rc = $offen === 'nein' ? 1 : 3;
            $aus($offen === 'nein' ? 'Es wurde nichts verändert.'
                : 'Ein unterbrochenes Update steht noch an – der Update-Dienst rollt es beim nächsten Start zurück (oder von Hand: '
                    . APP_ROOT . '/deploy/update-debian.sh --wiederaufnehmen).');
        }
        if ($rc !== 0) {
            $p = UpdateService::protokollDatei();
            if ($p !== null && $p['kennung'] === $lauf['kennung'] && trim($p['text']) !== '') {
                $aus('');
                $aus('Die letzten Zeilen des Protokolls:');
                foreach (array_slice(explode("\n", rtrim($p['text'], "\n")), -40) as $zeile) {
                    $aus('  | ' . $zeile);
                }
            }
            $aus('');
            $aus('Ganzes Protokoll: journalctl -a -u powermanagement-update -n 400 --no-pager');
        }
        return $rc;
    }
}
