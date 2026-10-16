<?php
declare(strict_types=1);

use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Settings;
use App\Support\View;

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = APP_ROOT . '/config/config.php';
// Eine zweite Instanz aus derselben Arbeitskopie (Prüfskripte der
// Schnittstelle) bekommt ihre Konfiguration über die Umgebung – nur auf der
// Kommandozeile und im eingebauten Server, nie unter FPM oder Apache.
$eigeneKonfiguration = getenv('POWERMANAGEMENT_CONFIG');
if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_string($eigeneKonfiguration)
    && $eigeneKonfiguration !== '' && is_file($eigeneKonfiguration)) {
    $configFile = $eigeneKonfiguration;
} elseif (!is_file($configFile)) {
    $configFile = APP_ROOT . '/config/config.example.php';
}
unset($eigeneKonfiguration);
Config::load($configFile);

date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Berlin'));

if (Config::get('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

Logger::setFile(APP_ROOT . '/storage/logs/app.log');
View::setBase(APP_ROOT . '/resources/views');

set_exception_handler(static function (\Throwable $e): void {
    Logger::error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    if (Config::get('app.debug')) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        return;
    }
    echo View::render('errors/error', ['code' => 500, 'message' => 'Unerwarteter Fehler. Details stehen im Log.']);
});

// Fatale Fehler (Zeitlimit, Speichergrenze) erreichen den Handler oben
// nicht und enden sonst als weiße Seite ohne Eintrag im Log.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Logger::error('Fataler Fehler: ' . $error['message'], [
        'file' => $error['file'],
        'line' => $error['line'],
    ]);
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
        echo View::render('errors/error', ['code' => 500, 'message' => 'Unerwarteter Fehler. Details stehen im Log.']);
    }
});

// Datenbankverbindung früh herstellen, damit Konfigurationsfehler sofort auffallen.
Database::pdo();

// In der Oberfläche gesetzte Werte überschreiben die Konfigurationsdatei.
Settings::apply();

View::share('appName', (string) Config::get('app.name', 'PowerManagement'));
View::share('appSubtitle', (string) Config::get('app.subtitle', 'Stromverteilung & Energiedaten'));
View::share('appShort', Settings::shortName());
View::share('demoMode', Config::isDemo());
