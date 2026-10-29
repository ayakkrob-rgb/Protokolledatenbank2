<?php
/**
 * Zentrale Initialisierung – wird von jedem Einstiegspunkt
 * (index.php, stripe-webhook.php) geladen.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Diese Anwendung benötigt PHP 8.0 oder neuer.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Die Anwendung ist noch nicht installiert (config/config.php fehlt).\n");
        exit(1);
    }
    header('Location: install/');
    exit;
}

$GLOBALS['config'] = require $configFile;
define('APP_DEBUG', !empty($GLOBALS['config']['debug']));
define('APP_KEY', (string)($GLOBALS['config']['app_key'] ?? ''));
define('STORAGE_PATH', rtrim((string)($GLOBALS['config']['storage_path'] ?? (APP_ROOT . '/storage')), '/\\'));

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(STORAGE_PATH . '/logs') && is_writable(STORAGE_PATH . '/logs')) {
    ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
}

require APP_ROOT . '/app/lib/helpers.php';
require APP_ROOT . '/app/lib/db.php';
require APP_ROOT . '/app/lib/settings.php';
require APP_ROOT . '/app/lib/view.php';
require APP_ROOT . '/app/lib/auth.php';
require APP_ROOT . '/app/lib/mailer.php';
require APP_ROOT . '/app/lib/mails.php';
require APP_ROOT . '/app/lib/stripe.php';
require APP_ROOT . '/app/lib/payments.php';
require APP_ROOT . '/app/lib/protocols.php';
require APP_ROOT . '/app/lib/importer.php';

set_exception_handler(function (Throwable $e) {
    app_log('error', get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (string)$e . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $details = APP_DEBUG ? '<pre style="white-space:pre-wrap;font-size:12px;background:#f1f5f9;padding:12px;border-radius:8px">' . e((string)$e) . '</pre>' : '';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fehler</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:60px auto;padding:0 20px;color:#1e293b">'
        . '<h1>Es ist ein Fehler aufgetreten</h1><p>Bitte versuche es später erneut. Falls das Problem bestehen bleibt, wende dich an die Fachschaft.</p>'
        . $details . '<p><a href="' . e(base_url()) . '">Zur Startseite</a></p></body></html>';
    exit;
});

DB::connect($GLOBALS['config']['db']);
Settings::load();

if (!defined('NO_SESSION') && PHP_SAPI !== 'cli') {
    start_session();
    send_security_headers();
}
