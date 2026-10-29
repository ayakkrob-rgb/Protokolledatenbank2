<?php
/**
 * Allgemeine Hilfsfunktionen (Ausgabe, URLs, Formulare, Formatierung).
 */

/** HTML-Escaping für jede Ausgabe von Nutzerdaten. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return str_starts_with((string)($GLOBALS['config']['base_url'] ?? ''), 'https://');
}

/** Basis-URL der Installation, immer mit abschließendem Slash. */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $cfg = trim((string)($GLOBALS['config']['base_url'] ?? ''));
        if ($cfg === '') {
            $scheme = is_https() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $cfg = $scheme . '://' . $host . $dir;
        }
        $base = rtrim($cfg, '/') . '/';
    }
    return $base;
}

/** Erzeugt eine URL zu einer Route, z. B. url('exam', ['id' => 3]). */
function url(string $route = '', array $params = []): string
{
    $parts = [];
    if ($route !== '') {
        $parts[] = 'p=' . str_replace('%2F', '/', rawurlencode($route));
    }
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
    }
    if (!$parts) {
        return base_url();
    }
    return base_url() . 'index.php?' . implode('&', $parts);
}

/** Aktuelle URL mit geänderten Query-Parametern (null entfernt einen Parameter). */
function url_with(array $changes): string
{
    $query = array_merge($_GET, $changes);
    $route = (string)($query['p'] ?? '');
    unset($query['p']);
    foreach ($query as $k => $v) {
        if ($v === null || is_array($v)) {
            unset($query[$k]);
        }
    }
    return url($route, $query);
}

function asset(string $path): string
{
    return base_url() . 'assets/' . ltrim($path, '/') . '?v=' . APP_VERSION;
}

function redirect(string $route = '', array $params = []): void
{
    redirect_url(url($route, $params));
}

function redirect_url(string $url): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/** Zurück zur vorherigen Seite (nur innerhalb der eigenen Installation). */
function redirect_back(string $fallbackRoute = '', array $params = []): void
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && str_starts_with($ref, base_url())) {
        redirect_url($ref);
    }
    redirect($fallbackRoute, $params);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function post_bool(string $key): bool
{
    $v = $_POST[$key] ?? '';
    return $v === '1' || $v === 'on' || $v === 'true';
}

function post_array(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}

function get_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

/* ---------- Session, Flash-Meldungen, CSRF ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = parse_url(base_url(), PHP_URL_PATH) ?: '/';
    $savePath = STORAGE_PATH . '/sessions';
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', '14400');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('pdb_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- Formatierung ---------- */

function currency_symbol(string $currency = 'eur'): string
{
    $map = ['eur' => '€', 'chf' => 'CHF', 'usd' => '$', 'gbp' => '£'];
    return $map[strtolower($currency)] ?? strtoupper($currency);
}

/** 3000 → "30,00 €" */
function money($cents, string $currency = ''): string
{
    $currency = $currency !== '' ? $currency : (string)setting('currency', 'eur');
    return number_format(((int)$cents) / 100, 2, ',', '.') . "\u{00A0}" . currency_symbol($currency);
}

/** 3000 → "30 €", 2950 → "29,50 €" */
function money_short($cents, string $currency = ''): string
{
    $cents = (int)$cents;
    $currency = $currency !== '' ? $currency : (string)setting('currency', 'eur');
    $num = $cents % 100 === 0 ? number_format($cents / 100, 0, ',', '.') : number_format($cents / 100, 2, ',', '.');
    return $num . "\u{00A0}" . currency_symbol($currency);
}

/** "30,00" / "30" / "30.5" → Cent-Betrag oder null bei ungültiger Eingabe. */
function parse_money(string $input): ?int
{
    $s = str_replace([' ', "\u{00A0}", '€'], '', trim($input));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int)round(((float)$s) * 100);
}

function money_input($cents): string
{
    return number_format(((int)$cents) / 100, 2, ',', '');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $date, string $empty = '–'): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return $empty;
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $empty;
}

function fmt_datetime(?string $date, string $empty = '–'): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return $empty;
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y, H:i', $ts) . ' Uhr' : $empty;
}

function fmt_month(?string $date, string $empty = '–'): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return $empty;
    }
    $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $ts = strtotime($date);
    return $ts ? $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) : $empty;
}

/** Relative Zeitangabe ("vor 3 Tagen"). */
function time_ago(?string $date): string
{
    if (!$date) {
        return '–';
    }
    $diff = time() - strtotime($date);
    if ($diff < 60) {
        return 'gerade eben';
    }
    if ($diff < 3600) {
        $n = (int)floor($diff / 60);
        return 'vor ' . $n . ' Min.';
    }
    if ($diff < 86400) {
        $n = (int)floor($diff / 3600);
        return 'vor ' . $n . ' Std.';
    }
    if ($diff < 86400 * 30) {
        $n = (int)floor($diff / 86400);
        return $n === 1 ? 'gestern' : 'vor ' . $n . ' Tagen';
    }
    return fmt_date($date);
}

function plural(int $n, string $one, string $many): string
{
    return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
}

function human_filesize($bytes): string
{
    $bytes = (int)$bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

function str_excerpt(?string $text, int $length = 160): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $length - 1)) . '…';
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'í' => 'i', 'ç' => 'c', 'ñ' => 'n']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? substr($text, 0, 90) : 'eintrag';
}

/**
 * Normalisierter Vergleichsschlüssel für Prüfernamen: ignoriert Titel,
 * Groß-/Kleinschreibung, Umlaute, Satzzeichen und Reihenfolge der Namensteile.
 * "Prof. Dr. med. Hans Müller" und "Müller, Hans" ergeben denselben Schlüssel.
 */
function name_key(string $name): string
{
    $n = mb_strtolower($name);
    $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ó' => 'o', 'í' => 'i']);
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
    $titles = ['prof', 'dr', 'med', 'dent', 'rer', 'nat', 'phil', 'pd', 'priv', 'doz', 'habil', 'univ', 'apl', 'em', 'frau', 'herr', 'mult', 'h', 'c', 'hc', 'dipl', 'ing', 'oa', 'ca', 'fa', 'mme', 'msc', 'bsc', 'phd', 'md'];
    $parts = array_filter(explode(' ', trim((string)$n)), fn($p) => $p !== '' && !in_array($p, $titles, true));
    sort($parts);
    return substr(implode(' ', $parts), 0, 150);
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function selected($a, $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked($condition): string
{
    return $condition ? ' checked' : '';
}

/* ---------- Logging ---------- */

function app_log(string $level, string $message): void
{
    $dir = STORAGE_PATH . '/logs';
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log('[' . $level . '] ' . $message);
        return;
    }
    $file = $dir . '/app.log';
    if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

/** Protokolliert eine Aktion im Aktivitätslog (für den Adminbereich). */
function log_activity(string $action, string $details = '', ?int $userId = null): void
{
    try {
        if ($userId === null && class_exists('Auth')) {
            $userId = Auth::id();
        }
        DB::insert('activity_log', [
            'user_id' => $userId,
            'action' => substr($action, 0, 50),
            'details' => $details,
            'ip' => client_ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        app_log('warning', 'Aktivitätslog fehlgeschlagen: ' . $e->getMessage());
    }
}

/* ---------- Fehlerseiten ---------- */

function abort(int $code, string $message = ''): void
{
    $titles = [
        400 => 'Ungültige Anfrage',
        403 => 'Kein Zugriff',
        404 => 'Seite nicht gefunden',
        419 => 'Sitzung abgelaufen',
        500 => 'Fehler',
        503 => 'Wartungsarbeiten',
    ];
    if (!headers_sent()) {
        http_response_code($code === 419 ? 400 : $code);
    }
    $title = $titles[$code] ?? 'Fehler';
    if ($message === '') {
        $message = $code === 404
            ? 'Die angeforderte Seite existiert nicht (mehr).'
            : 'Du hast keine Berechtigung für diese Seite.';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    View::$title = $title;
    if (View::$layout === 'admin' && !Auth::isStaff()) {
        View::$layout = 'public';
    }
    $content = '<div class="container container-narrow"><div class="empty-state card">'
        . '<div class="empty-icon">' . icon($code === 404 ? 'search' : ($code === 503 ? 'clock' : 'alert')) . '</div>'
        . '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>'
        . '<p><a class="btn btn-primary" href="' . e(base_url()) . '">Zur Startseite</a></p></div></div>';
    View::render($content);
    exit;
}

/* ---------- Paginierung ---------- */

function paginate(int $total, int $perPage = 25): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, get_int('page', 1)), $pages);
    return [
        'total' => $total,
        'per_page' => $perPage,
        'page' => $page,
        'pages' => $pages,
        'offset' => ($page - 1) * $perPage,
    ];
}

function pagination_links(array $pg): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Seiten">';
    $cur = $pg['page'];
    if ($cur > 1) {
        $html .= '<a href="' . e(url_with(['page' => $cur - 1])) . '">‹ Zurück</a>';
    }
    $start = max(1, $cur - 2);
    $end = min($pg['pages'], $cur + 2);
    if ($start > 1) {
        $html .= '<a href="' . e(url_with(['page' => 1])) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="gap">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $cur
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . e(url_with(['page' => $i])) . '">' . $i . '</a>';
    }
    if ($end < $pg['pages']) {
        if ($end < $pg['pages'] - 1) {
            $html .= '<span class="gap">…</span>';
        }
        $html .= '<a href="' . e(url_with(['page' => $pg['pages']])) . '">' . $pg['pages'] . '</a>';
    }
    if ($cur < $pg['pages']) {
        $html .= '<a href="' . e(url_with(['page' => $cur + 1])) . '">Weiter ›</a>';
    }
    return $html . '</nav>';
}

/* ---------- Mini-Markdown (sicher: HTML wird immer escaped) ---------- */

function render_markdown(?string $text): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$text));
    $out = [];
    $para = [];
    $list = null;

    $flushPara = function () use (&$para, &$out) {
        if ($para) {
            $out[] = '<p>' . implode('<br>', array_map('md_inline', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$list, &$out) {
        if ($list) {
            $out[] = '</' . $list . '>';
            $list = null;
        }
    };

    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') {
            $flushPara();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/u', $t, $m)) {
            $flushPara();
            $closeList();
            $lvl = strlen($m[1]) + 1;
            $out[] = '<h' . $lvl . '>' . md_inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) {
            $flushPara();
            $closeList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^[-*•]\s+(.+)$/u', $t, $m)) {
            $flushPara();
            if ($list !== 'ul') {
                $closeList();
                $out[] = '<ul>';
                $list = 'ul';
            }
            $out[] = '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\d{1,3}[.)]\s+(.+)$/u', $t, $m)) {
            $flushPara();
            if ($list !== 'ol') {
                $closeList();
                $out[] = '<ol>';
                $list = 'ol';
            }
            $out[] = '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/u', $t, $m)) {
            $flushPara();
            $closeList();
            $out[] = '<blockquote>' . md_inline($m[1]) . '</blockquote>';
            continue;
        }
        $closeList();
        $para[] = $t;
    }
    $flushPara();
    $closeList();
    return implode("\n", $out);
}

function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\(((?:https?:\/\/|mailto:)[^\s)]+)\)/u',
        fn($m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener nofollow">' . $m[1] . '</a>',
        $s
    );
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])/u', '<em>$1</em>', $s);
    return $s;
}

/* ---------- Icons (Inline-SVG im Stil von Feather Icons, MIT) ---------- */

function icon(string $name, string $class = ''): string
{
    static $icons = [
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'unlock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'card' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'merge' => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'refund' => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
        'archive' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'server' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
        'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'award' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'euro' => '<path d="M17.5 6.5A7 7 0 1 0 17.5 17.5"/><line x1="4" y1="10" x2="13" y2="10"/><line x1="4" y1="14" x2="13" y2="14"/>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'pages' => '<path d="M4 4h12l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
    ];
    $paths = $icons[$name] ?? $icons['info'];
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

/** Initialen eines Namens ohne Titel ("Prof. Dr. Anna Beispiel" → "AB"). */
function initials(string $name): string
{
    $clean = preg_replace('/\b(Prof|Dr|med|dent|rer|nat|phil|PD|Priv|Doz|habil|univ|apl|em|h\.\s?c|mult)\b\.?/iu', ' ', $name);
    $parts = array_values(array_filter(preg_split('/[\s,.\-]+/u', (string)$clean) ?: [], fn($p) => $p !== ''));
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Prüft ein Datum im Format JJJJ-MM-TT. */
function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
