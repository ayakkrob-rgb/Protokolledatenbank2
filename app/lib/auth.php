<?php
/**
 * Anmeldung, Registrierung, Rollen und Schutz vor Brute-Force-Angriffen.
 * Rollen: student, moderator (Protokolle/Prüfer/Import), admin (alles).
 */
class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id > 0) {
                $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($u && !(int)$u['is_blocked'] && (int)$u['is_verified']) {
                    self::$user = $u;
                } else {
                    unset($_SESSION['user_id']);
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isStaff(): bool
    {
        $u = self::user();
        return $u !== null && in_array($u['role'], ['admin', 'moderator'], true);
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        DB::update('users', ['last_login_at' => now()], 'id = ?', [$user['id']]);
        self::$user = DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        self::$loaded = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$loaded = true;
    }

    /**
     * Prüft Zugangsdaten. Rückgabe: ['ok' => bool, 'error' => string, 'user' => ?array]
     * error = 'unverified' wenn die E-Mail-Adresse noch nicht bestätigt wurde.
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $key = 'login:' . $email;
        if (self::tooManyAttempts($key, 8, 15) || self::tooManyAttemptsFromIp(40, 15)) {
            return ['ok' => false, 'error' => 'Zu viele Anmeldeversuche. Bitte warte 15 Minuten und versuche es dann erneut.', 'user' => null];
        }
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        $hash = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuNkpCx0d0GJ7C2eM8lH4xMbNnXx8aW5u';
        if (!password_verify($password, $hash) || !$user) {
            self::hitAttempt($key);
            return ['ok' => false, 'error' => 'E-Mail-Adresse oder Passwort ist falsch.', 'user' => null];
        }
        if ((int)$user['is_blocked']) {
            return ['ok' => false, 'error' => 'Dein Konto wurde gesperrt. Bitte wende dich an die Fachschaft.', 'user' => null];
        }
        if (!(int)$user['is_verified']) {
            return ['ok' => false, 'error' => 'unverified', 'user' => $user];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        self::clearAttempts($key);
        self::login($user);
        return ['ok' => true, 'error' => '', 'user' => $user];
    }

    /**
     * Registriert ein neues Konto und verschickt den Bestätigungslink.
     * Rückgabe: ['ok' => bool, 'errors' => [feld => meldung], 'user_id' => ?int]
     */
    public static function register(string $name, string $email, string $password, string $password2): array
    {
        $errors = [];
        $email = mb_strtolower(trim($email));
        $name = trim($name);

        if (!Settings::bool('registration_open')) {
            return ['ok' => false, 'errors' => ['email' => 'Die Registrierung ist derzeit geschlossen.'], 'user_id' => null];
        }
        if (self::tooManyAttempts('register:' . client_ip(), 10, 60)) {
            return ['ok' => false, 'errors' => ['email' => 'Zu viele Registrierungen von deinem Anschluss. Bitte versuche es später erneut.'], 'user_id' => null];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Bitte gib deinen Namen an.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        } elseif (!self::emailAllowed($email)) {
            $errors['email'] = 'Bitte verwende deine studentische E-Mail-Adresse (' . implode(', ', array_map(fn($d) => '@' . ltrim($d, '*.'), self::allowedDomains())) . ').';
        }
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($password !== $password2) {
            $errors['password2'] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'user_id' => null];
        }

        $existing = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($existing) {
            if (!(int)$existing['is_verified'] && !(int)$existing['is_blocked']) {
                self::sendVerification($existing);
                return ['ok' => true, 'errors' => [], 'user_id' => (int)$existing['id'], 'resent' => true];
            }
            return ['ok' => false, 'errors' => ['email' => 'Für diese E-Mail-Adresse gibt es bereits ein Konto. Hast du dein Passwort vergessen?'], 'user_id' => null];
        }

        self::hitAttempt('register:' . client_ip());
        $id = DB::insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name' => $name,
            'role' => 'student',
            'created_at' => now(),
        ]);
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        self::sendVerification($user);
        log_activity('register', $email, $id);
        return ['ok' => true, 'errors' => [], 'user_id' => $id];
    }

    public static function sendVerification(array $user): void
    {
        $token = random_token(32);
        DB::update('users', [
            'verify_token' => hash('sha256', $token),
            'verify_expires' => date('Y-m-d H:i:s', time() + 48 * 3600),
        ], 'id = ?', [$user['id']]);
        Mails::verification($user, $token);
    }

    /** Bestätigt eine E-Mail-Adresse. Gibt den Nutzer zurück oder null. */
    public static function verify(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $user = DB::one('SELECT * FROM users WHERE verify_token = ?', [hash('sha256', $token)]);
        if (!$user || strtotime((string)$user['verify_expires']) < time()) {
            return null;
        }
        DB::update('users', [
            'is_verified' => 1,
            'verified_at' => now(),
            'verify_token' => null,
            'verify_expires' => null,
        ], 'id = ?', [$user['id']]);
        log_activity('verify', $user['email'], (int)$user['id']);
        return DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }

    public static function sendPasswordReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (self::tooManyAttempts('reset:' . $email, 3, 60)) {
            return;
        }
        self::hitAttempt('reset:' . $email);
        $user = DB::one('SELECT * FROM users WHERE email = ? AND is_blocked = 0', [$email]);
        if (!$user) {
            return;
        }
        $token = random_token(32);
        DB::update('users', [
            'reset_token' => hash('sha256', $token),
            'reset_expires' => date('Y-m-d H:i:s', time() + 2 * 3600),
        ], 'id = ?', [$user['id']]);
        Mails::passwordReset($user, $token);
        log_activity('password_reset_requested', $email, (int)$user['id']);
    }

    public static function userByResetToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $user = DB::one('SELECT * FROM users WHERE reset_token = ?', [hash('sha256', $token)]);
        if (!$user || strtotime((string)$user['reset_expires']) < time()) {
            return null;
        }
        return $user;
    }

    /* ---------- Erlaubte E-Mail-Domains ---------- */

    public static function allowedDomains(): array
    {
        $raw = mb_strtolower((string)setting('allowed_domains', ''));
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = ltrim(trim($p), '@');
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /** Einträge: "uni-x.de" (exakt) oder "*.uni-x.de" (inkl. aller Subdomains). Leer = alle erlaubt. */
    public static function emailAllowed(string $email): bool
    {
        $domains = self::allowedDomains();
        if (!$domains) {
            return true;
        }
        $at = strrchr($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = mb_strtolower(substr($at, 1));
        foreach ($domains as $d) {
            if (str_starts_with($d, '*.')) {
                $baseDomain = substr($d, 2);
                if ($domain === $baseDomain || str_ends_with($domain, '.' . $baseDomain)) {
                    return true;
                }
            } elseif ($domain === $d) {
                return true;
            }
        }
        return false;
    }

    /* ---------- Begrenzung von Versuchen ---------- */

    public static function tooManyAttempts(string $identifier, int $max, int $minutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        return (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND created_at > ?', [$identifier, $since]) >= $max;
    }

    public static function tooManyAttemptsFromIp(int $max, int $minutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        return (int)DB::val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND identifier LIKE 'login:%' AND created_at > ?", [client_ip(), $since]) >= $max;
    }

    public static function hitAttempt(string $identifier): void
    {
        DB::insert('login_attempts', ['identifier' => substr($identifier, 0, 191), 'ip' => client_ip(), 'created_at' => now()]);
        if (random_int(1, 50) === 1) {
            DB::q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 2)]);
        }
    }

    public static function clearAttempts(string $identifier): void
    {
        DB::q('DELETE FROM login_attempts WHERE identifier = ?', [$identifier]);
    }
}

/** URL der aktuellen Seite (für die Weiterleitung nach dem Login). */
function current_url(): string
{
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    return base_url() . ($qs !== '' ? 'index.php?' . $qs : '');
}

function require_login(): array
{
    $u = Auth::user();
    if (!$u) {
        if (!is_post()) {
            $_SESSION['intended'] = current_url();
        }
        flash('info', 'Bitte melde dich zuerst an.');
        redirect('login');
    }
    return $u;
}

function require_staff(): array
{
    $u = require_login();
    if (!Auth::isStaff()) {
        abort(403);
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!Auth::isAdmin()) {
        abort(403, 'Dieser Bereich ist nur für Administratorinnen und Administratoren.');
    }
    return $u;
}
