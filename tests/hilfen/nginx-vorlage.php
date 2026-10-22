<?php
declare(strict_types=1);

/**
 * Die nginx-Vorlage (deploy/nginx-powermanagement.conf) mit einem echten
 * nginx prüfen – ohne Installation, auf einem freien Port, alles unter $tmp.
 *
 * Hinter nginx steht kein PHP-FPM: 502 heißt „angenommen und an PHP
 * weitergereicht“, 413 „abgelehnt“. So lässt sich prüfen, welche Grenze
 * nginx für eine Adresse wirklich anwendet – mit angekündigter Länge
 * (Content-Length) und in Stücken (Transfer-Encoding: chunked); bei Letzterem
 * zählt nur die location, in der nginx den Körper liest.
 *
 * $faelle: Name => [Pfad, Bytes, chunked]. Ergebnis: Name => [Status, Anfang
 * der Antwort], oder null ohne nginx, curl oder die Debian-Schnipsel
 * (snippets/fastcgi-php.conf) – dann übernimmt das tests/installation-debian.sh.
 *
 * @param array<string,array{string,int,bool}> $faelle
 * @return array<string,array{int,string}>|null
 */
function pruefNginxGrenzen(string $vorlage, string $tmp, array $faelle): ?array
{
    $nginx = trim((string) shell_exec('command -v nginx 2>/dev/null'));
    $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
    if ($nginx === '' || $curl === '' || !is_file('/etc/nginx/snippets/fastcgi-php.conf') || !is_file('/etc/nginx/fastcgi_params')) {
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
    $ergebnis = [];
    try {
        foreach ($faelle as $name => [$pfad, $bytes, $stuecke]) {
            $koerper = $d . '/koerper-' . $bytes;
            if (!is_file($koerper)) {
                // dünn belegt: liest sich schnell, kostet keine Platte
                $f = fopen($koerper, 'wb');
                ftruncate($f, $bytes);
                fclose($f);
            }
            $antwort = $d . '/antwort';
            @unlink($antwort);
            $status = (int) shell_exec(escapeshellarg($curl) . ' -s -o ' . escapeshellarg($antwort) . " -w '%{http_code}' --max-time 60 -H 'Expect:'"
                . ' -H ' . escapeshellarg('Content-Type: application/octet-stream') . ($stuecke ? ' -H ' . escapeshellarg('Transfer-Encoding: chunked') : '')
                . ' --data-binary @' . escapeshellarg($koerper) . ' ' . escapeshellarg('http://127.0.0.1:' . $port . $pfad) . ' 2>/dev/null');
            $ergebnis[$name] = [$status, substr((string) @file_get_contents($antwort), 0, 400)];
        }
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
    return $ergebnis;
}
