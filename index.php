<?php
/**
 * Front-Controller: Alle Seiten laufen über index.php?p=<route>.
 * Funktioniert ohne mod_rewrite auf jedem Webhosting.
 */
require __DIR__ . '/app/bootstrap.php';

// route => [Seitendatei in app/pages, Zugriff: public | auth | staff | admin]
$routes = [
    '' => ['home', 'public'],
    'exam' => ['exam', 'public'],
    'examiner' => ['examiner', 'public'],
    'protocol' => ['protocol', 'auth'],
    'file' => ['file', 'auth'],
    'examiner-zip' => ['examiner_zip', 'auth'],
    'buy' => ['buy', 'auth'],
    'checkout-sim' => ['checkout_sim', 'auth'],
    'checkout-success' => ['checkout_success', 'auth'],
    'checkout-cancel' => ['checkout_cancel', 'auth'],
    'upload' => ['upload', 'auth'],
    'account' => ['account', 'auth'],
    'purchase' => ['purchase', 'auth'],
    'login' => ['login', 'public'],
    'register' => ['register', 'public'],
    'logout' => ['logout', 'public'],
    'verify' => ['verify', 'public'],
    'resend' => ['resend', 'public'],
    'forgot' => ['forgot', 'public'],
    'reset' => ['reset', 'public'],
    'page' => ['page', 'public'],

    'admin' => ['admin/dashboard', 'staff'],
    'admin/exams' => ['admin/exams', 'admin'],
    'admin/exam' => ['admin/exam', 'admin'],
    'admin/examiners' => ['admin/examiners', 'staff'],
    'admin/examiner' => ['admin/examiner', 'staff'],
    'admin/protocols' => ['admin/protocols', 'staff'],
    'admin/protocol' => ['admin/protocol', 'staff'],
    'admin/import' => ['admin/import', 'staff'],
    'admin/users' => ['admin/users', 'admin'],
    'admin/user' => ['admin/user', 'admin'],
    'admin/purchases' => ['admin/purchases', 'admin'],
    'admin/purchase' => ['admin/purchase', 'admin'],
    'admin/settings' => ['admin/settings', 'admin'],
    'admin/payments' => ['admin/payments', 'admin'],
    'admin/mail' => ['admin/mail', 'admin'],
    'admin/pages' => ['admin/pages', 'admin'],
    'admin/logs' => ['admin/logs', 'admin'],
    'admin/system' => ['admin/system', 'admin'],
];

$route = trim(get_str('p'), '/');
if (!isset($routes[$route])) {
    abort(404);
}
[$pageFile, $access] = $routes[$route];

if (str_starts_with($route, 'admin')) {
    View::$layout = 'admin';
}

// Wartungsmodus: nur Team und Anmeldung erreichbar
if (Settings::bool('maintenance_mode') && !Auth::isStaff()
    && !in_array($route, ['login', 'logout', 'page', 'verify', 'forgot', 'reset'], true)) {
    abort(503, (string)setting('maintenance_message'));
}

if (is_post()) {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = Protocols::iniBytes((string)ini_get('post_max_size'));
    if (empty($_POST) && empty($_FILES) && $length > 0 && $max > 0 && $length > $max) {
        flash('error', 'Die gesendeten Daten sind zu groß. Das Server-Limit liegt bei ' . human_filesize($max) . '.');
        redirect_back($route);
    }
    if (!csrf_verify()) {
        flash('error', 'Deine Sitzung ist abgelaufen. Bitte versuche es noch einmal.');
        redirect_back($route);
    }
}

switch ($access) {
    case 'auth':
        require_login();
        break;
    case 'staff':
        require_staff();
        break;
    case 'admin':
        require_admin();
        break;
}

function run_page(string $__file): void
{
    require APP_ROOT . '/app/pages/' . $__file . '.php';
}

ob_start();
run_page($pageFile);
$content = (string)ob_get_clean();
View::render($content);
