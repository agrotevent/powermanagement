<?php
declare(strict_types=1);

/**
 * Die nginx-Vorlage (deploy/nginx-powermanagement.conf) mit einem echten
 * nginx prüfen – ohne Installation, auf einem freien Port, alles unter $tmp.
 *
 * Hinter nginx steht kein PHP-FPM: 502 heißt „angenommen und an PHP
 * weitergereicht“, 413 „abgelehnt“, 429 „gebremst“. So lässt sich prüfen,
 * welche Grenze nginx für eine Adresse wirklich anwendet – mit angekündigter
 * Länge (Content-Length) und in Stücken (Transfer-Encoding: chunked); bei
 * Letzterem zählt nur die location, in der nginx den Körper liest.
 *
 * Ohne nginx, curl oder die Debian-Schnipsel (snippets/fastcgi-php.conf)
 * geben die Funktionen null zurück – dann übernimmt das
 * tests/installation-debian.sh.
 */

/**
 * nginx mit der Vorlage starten, $tun(Port, Ordner) aufrufen, nginx beenden.
 * null ohne nginx/curl/Schnipsel, [] wenn nginx mit der Vorlage nicht startet
 * (als Fehlschlag gemeldet).
 *
 * @param callable(int,string):array $tun
 */
function mitNginxVorlage(string $vorlage, string $tmp, callable $tun): ?array
{
    $nginx = trim((string) shell_exec('command -v nginx 2>/dev/null'));
    if ($nginx === '' || nginxCurl() === '' || !is_file('/etc/nginx/snippets/fastcgi-php.conf') || !is_file('/etc/nginx/fastcgi_params')) {
        return null;
    }
    $d = $tmp . '/nginx';
    @mkdir($d . '/snippets', 0700, true);
    @mkdir($d . '/public', 0700, true);
    foreach (['snippets/fastcgi-php.conf', 'fastcgi.conf', 'fastcgi_params', 'mime.types'] as $datei) {
        if (is_file('/etc/nginx/' . $datei)) {
            copy('/etc/nginx/' . $datei, $d . '/' . $datei);
        }
    }
    touch($d . '/public/index.php');
    $port = freierPort();
    file_put_contents($d . '/site.conf', str_replace(
        ['__LISTEN__', '__PORT__', '__APP_DIR__', '__FPM_SOCKET__', '__SERVER_NAME__', '/var/log/nginx/'],
        ['127.0.0.1', (string) $port, $d, $d . '/kein-fpm.sock', 'pruefung.intern', $d . '/'],
        (string) file_get_contents($vorlage)
    ));
    $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
    file_put_contents($d . '/nginx.conf', ($root ? "user root;\n" : '') . "worker_processes 1;\npid {$d}/nginx.pid;\nerror_log {$d}/error.log;\n"
        . "events { worker_connections 32; }\nhttp {\n    include {$d}/mime.types;\n"
        . "    client_body_temp_path {$d}/koerper;\n    fastcgi_temp_path {$d}/fcgi;\n    proxy_temp_path {$d}/proxy;\n"
        . "    uwsgi_temp_path {$d}/uwsgi;\n    scgi_temp_path {$d}/scgi;\n    include {$d}/site.conf;\n}\n");
    exec(escapeshellarg($nginx) . ' -e ' . escapeshellarg($d . '/error.log') . ' -p ' . escapeshellarg($d) . ' -c ' . escapeshellarg($d . '/nginx.conf') . ' 2>&1', $aus, $rc);
    if ($rc !== 0) {
        pruefe(false, 'nginx startet mit der Vorlage: ' . implode(' ', $aus));
        return [];
    }
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    try {
        return $tun($port, $d);
    } finally {
        $pid = (int) @file_get_contents($d . '/nginx.pid');
        if ($pid > 0) {
            exec('kill -QUIT ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 30 && is_file($d . '/nginx.pid'); $i++) {
                usleep(100_000);
            }
        }
        exec('rm -rf ' . escapeshellarg($d));
    }
}

function nginxCurl(): string
{
    return trim((string) shell_exec('command -v curl 2>/dev/null'));
}

/** Eine dünn belegte Datei mit $bytes Bytes unter $d (liest sich schnell, kostet keine Platte). */
function nginxKoerper(string $d, int $bytes): string
{
    $koerper = $d . '/koerper-' . $bytes;
    if (!is_file($koerper)) {
        $f = fopen($koerper, 'wb');
        ftruncate($f, $bytes);
        fclose($f);
    }
    return $koerper;
}

/**
 * Eine Anfrage an nginx: [Status, Anfang der Antwort]. Ohne $bytes ein GET.
 *
 * @return array{int,string}
 */
function nginxAnfrage(string $d, int $port, string $pfad, ?int $bytes = null, bool $stuecke = false): array
{
    $antwort = $d . '/antwort';
    @unlink($antwort);
    $befehl = escapeshellarg(nginxCurl()) . ' -s -o ' . escapeshellarg($antwort) . " -w '%{http_code}' --max-time 60 -H 'Expect:'";
    if ($bytes !== null) {
        $befehl .= ' -H ' . escapeshellarg('Content-Type: application/octet-stream') . ($stuecke ? ' -H ' . escapeshellarg('Transfer-Encoding: chunked') : '')
            . ' --data-binary @' . escapeshellarg(nginxKoerper($d, $bytes));
    }
    $status = (int) shell_exec($befehl . ' ' . escapeshellarg('http://127.0.0.1:' . $port . $pfad) . ' 2>/dev/null');
    return [$status, substr((string) @file_get_contents($antwort), 0, 400)];
}

/**
 * $faelle: Name => [Pfad, Bytes, chunked]. Ergebnis: Name => [Status, Anfang
 * der Antwort].
 *
 * @param array<string,array{string,int,bool}> $faelle
 * @return array<string,array{int,string}>|null
 */
function pruefNginxGrenzen(string $vorlage, string $tmp, array $faelle): ?array
{
    return mitNginxVorlage($vorlage, $tmp, static function (int $port, string $d) use ($faelle): array {
        $ergebnis = [];
        foreach ($faelle as $name => [$pfad, $bytes, $stuecke]) {
            $ergebnis[$name] = nginxAnfrage($d, $port, $pfad, $bytes, $stuecke);
        }
        return $ergebnis;
    });
}

/**
 * Die Bremsen der Vorlage:
 *
 *   plan-zweiter   Upload an /standorte/grundriss, während ein langsamer läuft
 *   plan-dritter   … während zwei langsame laufen (limit_conn pm_plan 2)
 *   plan-danach    … nachdem die langsamen beendet sind
 *   login-get      Status je GET /login (30 schnell hintereinander)
 *   login-post     Status je POST /login (30 schnell hintereinander, klein)
 *   login-429      Anfang der Antwort auf den ersten gebremsten POST
 *
 * @return array<string,mixed>|null
 */
function pruefNginxBremsen(string $vorlage, string $tmp): ?array
{
    return mitNginxVorlage($vorlage, $tmp, static function (int $port, string $d): array {
        $ergebnis = [];
        // Langsame Uploads: in Stücken, 2 KB/s – nginx liest den Körper
        // selbst (ohne PHP dahinter), die Verbindung bleibt so lange offen.
        $langsam = [];
        $starten = static function () use (&$langsam, $d, $port): void {
            $langsam[] = proc_open([nginxCurl(), '-s', '-o', '/dev/null', '--max-time', '30', '--limit-rate', '2k', '-H', 'Expect:',
                '-H', 'Transfer-Encoding: chunked', '-H', 'Content-Type: application/octet-stream', '--data-binary', '@' . nginxKoerper($d, 1024 * 1024),
                'http://127.0.0.1:' . $port . '/standorte/grundriss'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre);
            usleep(700_000);
        };
        $starten();
        $ergebnis['plan-zweiter'] = nginxAnfrage($d, $port, '/standorte/grundriss', 4096, true);
        $starten();
        $ergebnis['plan-dritter'] = nginxAnfrage($d, $port, '/standorte/grundriss', 4096, true);
        foreach ($langsam as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        usleep(300_000);
        $ergebnis['plan-danach'] = nginxAnfrage($d, $port, '/standorte/grundriss', 4096, true);

        $ergebnis['login-get'] = [];
        for ($i = 0; $i < 30; $i++) {
            $ergebnis['login-get'][] = nginxAnfrage($d, $port, '/login')[0];
        }
        $ergebnis['login-post'] = [];
        $ergebnis['login-429'] = '';
        for ($i = 0; $i < 30; $i++) {
            [$status, $anfang] = nginxAnfrage($d, $port, '/login', 200);
            $ergebnis['login-post'][] = $status;
            if ($status === 429 && $ergebnis['login-429'] === '') {
                $ergebnis['login-429'] = $anfang;
            }
        }
        return $ergebnis;
    });
}
