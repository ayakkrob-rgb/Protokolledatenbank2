<?php
/**
 * Installer der Protokolldatenbank.
 * Aufruf im Browser: https://eure-domain.de/install/
 * Nach erfolgreicher Installation sperrt sich der Installer selbst.
 * Bitte den Ordner install/ danach per FTP löschen.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Berlin');

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $dir = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function requirements(string $root): array
{
    $r = [];
    $r[] = ['PHP 8.0 oder neuer (installiert: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80000, true];
    foreach (['pdo_mysql' => 'Datenbankzugriff', 'mbstring' => 'Texte & Umlaute', 'json' => 'Datenformat', 'openssl' => 'Verschlüsselung', 'curl' => 'Stripe-Anbindung'] as $ext => $why) {
        $r[] = ['PHP-Erweiterung „' . $ext . '“ (' . $why . ')', extension_loaded($ext), true];
    }
    foreach (['zip' => 'ZIP-Import & ZIP-Download', 'fileinfo' => 'Dateiprüfung'] as $ext => $why) {
        $r[] = ['PHP-Erweiterung „' . $ext . '“ (' . $why . ', optional)', extension_loaded($ext), false];
    }
    $r[] = ['Ordner storage/ beschreibbar', is_writable($root . '/storage'), true];
    $r[] = ['Ordner config/ beschreibbar (sonst Konfiguration manuell anlegen)', is_writable($root . '/config'), false];
    return $r;
}

function pdo_connect(array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['name']);
    return new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
}

function config_content(array $db, string $baseUrl): string
{
    $key = bin2hex(random_bytes(32));
    return "<?php\n"
        . "// Konfiguration der Protokolldatenbank – erstellt am " . date('d.m.Y H:i') . " durch den Installer.\n"
        . "// Enthält Zugangsdaten: Diese Datei niemals weitergeben oder veröffentlichen!\n"
        . "return [\n"
        . "    'db' => [\n"
        . "        'host' => " . var_export($db['host'], true) . ",\n"
        . "        'port' => " . (int)$db['port'] . ",\n"
        . "        'name' => " . var_export($db['name'], true) . ",\n"
        . "        'user' => " . var_export($db['user'], true) . ",\n"
        . "        'pass' => " . var_export($db['pass'], true) . ",\n"
        . "    ],\n"
        . "    // Öffentliche Adresse der Webseite (mit / am Ende)\n"
        . "    'base_url' => " . var_export($baseUrl, true) . ",\n"
        . "    // Schlüssel zur Verschlüsselung gespeicherter Geheimnisse (z. B. Stripe-Schlüssel). Nicht ändern!\n"
        . "    'app_key' => " . var_export($key, true) . ",\n"
        . "    // Speicherort für hochgeladene Dateien. Kann (und sollte bei nginx-Servern) außerhalb des Webverzeichnisses liegen.\n"
        . "    'storage_path' => dirname(__DIR__) . '/storage',\n"
        . "    // Fehlerdetails anzeigen – nur zur Fehlersuche auf true setzen\n"
        . "    'debug' => false,\n"
        . "];\n";
}

/** Führt die eigentliche Installation aus (Konfiguration existiert bereits). */
function run_install(string $root, array $in): array
{
    define('NO_SESSION', true);
    require $root . '/app/bootstrap.php';
    require_once $root . '/app/lib/demo.php';

    $sql = (string)file_get_contents($root . '/app/schema.sql');
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim((string)preg_replace('/^--.*$/m', '', $stmt));
        if ($stmt !== '') {
            DB::pdo()->exec($stmt);
        }
    }
    Settings::load();

    foreach (['uploads', 'import', 'tmp', 'logs', 'sessions'] as $dir) {
        $path = STORAGE_PATH . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (!is_file($path . '/index.html')) {
            @file_put_contents($path . '/index.html', '');
        }
    }

    $domains = preg_split('/[\s,;]+/', mb_strtolower($in['domains'])) ?: [];
    $domains = array_values(array_filter(array_map(fn($d) => ltrim(trim($d), '@'), $domains)));
    Settings::setMany([
        'site_name' => $in['site_name'],
        'org_name' => $in['org_name'],
        'contact_email' => $in['admin_email'],
        'notify_email' => $in['admin_email'],
        'allowed_domains' => implode("\n", $domains),
        'mail_mode' => $in['mail_mode'],
        'payment_mode' => 'simulation',
        'db_version' => '1',
    ]);

    $existing = DB::one('SELECT id FROM users WHERE email = ?', [$in['admin_email']]);
    if ($existing) {
        DB::update('users', [
            'password_hash' => password_hash($in['admin_pass'], PASSWORD_DEFAULT),
            'role' => 'admin', 'is_verified' => 1, 'is_blocked' => 0, 'name' => $in['admin_name'],
        ], 'id = ?', [$existing['id']]);
    } else {
        DB::insert('users', [
            'email' => $in['admin_email'],
            'password_hash' => password_hash($in['admin_pass'], PASSWORD_DEFAULT),
            'name' => $in['admin_name'],
            'role' => 'admin',
            'is_verified' => 1,
            'verified_at' => now(),
            'created_at' => now(),
        ]);
    }

    Demo::seedExams();
    $demo = null;
    if ($in['demo'] && !Demo::exists()) {
        $demo = Demo::seed($in['demo_student']);
    }
    log_activity('installed', 'Installation abgeschlossen (Version ' . APP_VERSION . ')');

    // Installer sperren
    @file_put_contents(__DIR__ . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    return ['demo' => $demo, 'base' => base_url()];
}

/* ---------- Zustand ermitteln ---------- */
$state = 'new';
$dbError = '';
if (is_file($configFile)) {
    $cfg = require $configFile;
    try {
        $pdo = pdo_connect($cfg['db']);
        $hasAdmin = false;
        try {
            $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        } catch (PDOException $e) {
            $hasAdmin = false;
        }
        $state = $hasAdmin ? 'installed' : 'finish';
    } catch (PDOException $e) {
        $state = 'db_error';
        $dbError = $e->getMessage();
    }
}

$errors = [];
$result = null;
$manualConfig = null;
$v = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'base_url' => detect_base_url(), 'site_name' => 'Protokolldatenbank', 'org_name' => 'Fachschaft Medizin',
    'domains' => '', 'admin_name' => '', 'admin_email' => '', 'mail_mode' => 'mail', 'demo' => true, 'demo_student' => true,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($state, ['new', 'finish'], true)) {
    foreach ($v as $k => $default) {
        if (is_bool($default)) {
            $v[$k] = !empty($_POST[$k]);
        } elseif (isset($_POST[$k]) && is_string($_POST[$k])) {
            $v[$k] = trim($_POST[$k]);
        }
    }
    $v['db_pass'] = (string)($_POST['db_pass'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $v['admin_email'] = mb_strtolower($v['admin_email']);

    if (mb_strlen($v['site_name']) < 2) {
        $errors[] = 'Bitte gib einen Namen für die Webseite an.';
    }
    if (!filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte gib eine gültige E-Mail-Adresse für den Admin an.';
    }
    if (mb_strlen($adminPass) < 10) {
        $errors[] = 'Das Admin-Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif ($adminPass !== (string)($_POST['admin_pass2'] ?? '')) {
        $errors[] = 'Die Admin-Passwörter stimmen nicht überein.';
    }
    if (!in_array($v['mail_mode'], ['mail', 'smtp', 'log'], true)) {
        $v['mail_mode'] = 'mail';
    }

    if ($state === 'new') {
        if (!preg_match('#^https?://#', $v['base_url'])) {
            $errors[] = 'Die Adresse der Webseite muss mit http:// oder https:// beginnen.';
        }
        $v['base_url'] = rtrim($v['base_url'], '/') . '/';
        $db = ['host' => $v['db_host'], 'port' => (int)$v['db_port'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $v['db_pass']];
        if (!$errors) {
            try {
                $test = pdo_connect($db);
                $ver = (string)$test->query('SELECT VERSION()')->fetchColumn();
            } catch (PDOException $e) {
                $errors[] = 'Verbindung zur Datenbank fehlgeschlagen: ' . $e->getMessage();
            }
        }
        if (!$errors) {
            $content = config_content($db, $v['base_url']);
            if (@file_put_contents($configFile, $content) === false) {
                $manualConfig = $content;
            } else {
                @chmod($configFile, 0640);
            }
        }
    }

    if (!$errors && $manualConfig === null) {
        try {
            $result = run_install($root, [
                'site_name' => $v['site_name'], 'org_name' => $v['org_name'], 'domains' => $v['domains'],
                'admin_name' => $v['admin_name'] ?: 'Admin', 'admin_email' => $v['admin_email'], 'admin_pass' => $adminPass,
                'mail_mode' => $v['mail_mode'], 'demo' => $v['demo'], 'demo_student' => $v['demo_student'],
            ]);
            $state = 'done';
        } catch (Throwable $e) {
            $errors[] = 'Bei der Installation ist ein Fehler aufgetreten: ' . $e->getMessage();
        }
    }
}
$reqs = requirements($root);
$reqOk = !array_filter($reqs, fn($r) => $r[2] && !$r[1]);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Installation – Protokolldatenbank</title>
<link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<header class="site-header"><div class="container header-inner"><span class="brand"><span class="brand-logo"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span><span class="brand-text"><strong>Protokolldatenbank</strong><small>Installation</small></span></span></div></header>
<main class="site-main">
<div class="container container-narrow">
  <div class="page-head"><h1>Installation</h1></div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><div><?= h($err) ?></div></div>
  <?php endforeach; ?>

  <?php if ($state === 'installed'): ?>
    <div class="card">
      <h2>Bereits installiert</h2>
      <p>Die Protokolldatenbank ist bereits eingerichtet. Aus Sicherheitsgründen ist der Installer gesperrt.</p>
      <p><strong>Bitte lösche den Ordner <code>install/</code> per FTP.</strong></p>
      <p>Für eine Neuinstallation müsste zuerst die Datei <code>config/config.php</code> gelöscht werden.</p>
      <a class="btn btn-primary" href="../">Zur Webseite</a>
    </div>

  <?php elseif ($state === 'db_error'): ?>
    <div class="card">
      <h2>Datenbankverbindung fehlgeschlagen</h2>
      <p>Die Datei <code>config/config.php</code> existiert, aber die Verbindung zur Datenbank klappt nicht:</p>
      <pre class="mail-body"><?= h($dbError) ?></pre>
      <p>Bitte prüfe die Zugangsdaten in <code>config/config.php</code> oder lösche die Datei, um die Installation neu zu starten.</p>
    </div>

  <?php elseif ($state === 'done'): ?>
    <div class="card">
      <div class="empty-icon" style="background:var(--c-success-bg);color:var(--c-success)">✓</div>
      <h2>Installation abgeschlossen!</h2>
      <p>Die Protokolldatenbank ist eingerichtet. Die Prüfungen <strong>Physikum (M1)</strong>, <strong>M2</strong> und <strong>M3</strong> wurden angelegt<?= $result['demo'] ? ', dazu ' . (int)$result['demo']['examiners'] . ' Testprüfer mit ' . (int)$result['demo']['protocols'] . ' Beispielprotokollen' : '' ?>.</p>
      <p>Zahlungen laufen zunächst im <strong>Simulationsmodus</strong> – du kannst alles gefahrlos ausprobieren. Stripe richtest du später im Adminbereich unter „Zahlungen / Stripe“ ein.</p>
      <?php if (!empty($result['demo']['student'])): ?>
        <div class="alert alert-info"><div><strong>Demo-Studierendenkonto</strong> (zum Ausprobieren aus Sicht der Studierenden):<br>E-Mail: <code><?= h($result['demo']['student']['email']) ?></code><br>Passwort: <code><?= h($result['demo']['student']['password']) ?></code><br><small>Die Zugangsdaten findest du auch im Adminbereich unter „System &amp; Wartung“.</small></div></div>
      <?php endif; ?>
      <div class="alert alert-warning"><div><strong>Wichtig:</strong> Lösche jetzt den Ordner <code>install/</code> per FTP. Passe danach im Adminbereich Impressum und Datenschutzerklärung an.</div></div>
      <div class="btn-group">
        <a class="btn btn-primary" href="<?= h($result['base']) ?>index.php?p=login">Zum Login (Adminbereich)</a>
        <a class="btn btn-secondary" href="<?= h($result['base']) ?>">Zur Webseite</a>
      </div>
    </div>

  <?php elseif ($manualConfig !== null): ?>
    <div class="card">
      <h2>Konfigurationsdatei bitte manuell anlegen</h2>
      <p>Der Ordner <code>config/</code> ist nicht beschreibbar. Lege per FTP die Datei <code>config/config.php</code> mit folgendem Inhalt an und lade diese Seite danach neu. Anschließend gibst du die Daten für den Admin erneut ein.</p>
      <textarea class="input mono" rows="22" readonly><?= h($manualConfig) ?></textarea>
      <a class="btn btn-primary mt-2" href="./">Seite neu laden</a>
    </div>

  <?php else: ?>
    <div class="card mb-2">
      <h2>1. Voraussetzungen</h2>
      <ul class="check-list">
        <?php foreach ($reqs as [$label, $ok, $required]): ?>
          <li><span class="<?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>"><?= $ok ? '✓' : ($required ? '✗' : '!') ?></span><div><?= h($label) ?></div></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$reqOk): ?><div class="alert alert-error mt-2"><div>Bitte behebe zuerst die rot markierten Punkte (z. B. im Kundenmenü des Hosters die PHP-Version erhöhen oder Ordnerrechte per FTP auf 755/775 setzen).</div></div><?php endif; ?>
    </div>

    <?php if ($reqOk): ?>
    <form method="post" action="./" class="card">
      <?php if ($state === 'new'): ?>
        <div class="form-section">
          <h3>2. Datenbank</h3>
          <p class="section-help">Die Zugangsdaten zur MySQL-Datenbank findest du im Kundenmenü deines Webhosters. Die Datenbank muss bereits existieren (sie kann leer sein).</p>
          <div class="form-row">
            <div class="field"><label>Datenbank-Server</label><input class="input" name="db_host" value="<?= h($v['db_host']) ?>" required></div>
            <div class="field"><label>Port</label><input class="input" name="db_port" value="<?= h($v['db_port']) ?>" required></div>
          </div>
          <div class="field"><label>Name der Datenbank</label><input class="input" name="db_name" value="<?= h($v['db_name']) ?>" required></div>
          <div class="form-row">
            <div class="field"><label>Benutzername</label><input class="input" name="db_user" value="<?= h($v['db_user']) ?>" required autocomplete="off"></div>
            <div class="field"><label>Passwort</label><input class="input" type="password" name="db_pass" value="<?= h($v['db_pass']) ?>" autocomplete="off"></div>
          </div>
          <div class="field"><label>Adresse der Webseite</label><input class="input" name="base_url" value="<?= h($v['base_url']) ?>" required><small class="help">Wird automatisch erkannt. Möglichst mit <code>https://</code>.</small></div>
        </div>
      <?php else: ?>
        <div class="alert alert-info"><div>Die Konfigurationsdatei ist vorhanden und die Datenbankverbindung funktioniert. Jetzt nur noch die Webseite und den Admin einrichten.</div></div>
      <?php endif; ?>

      <div class="form-section">
        <h3><?= $state === 'new' ? '3' : '2' ?>. Webseite</h3>
        <div class="form-row">
          <div class="field"><label>Name der Webseite</label><input class="input" name="site_name" value="<?= h($v['site_name']) ?>" required></div>
          <div class="field"><label>Fachschaft</label><input class="input" name="org_name" value="<?= h($v['org_name']) ?>"></div>
        </div>
        <div class="field"><label>Erlaubte studentische E-Mail-Domains</label><input class="input" name="domains" value="<?= h($v['domains']) ?>" placeholder="z. B. stud.uni-beispiel.de"><small class="help">Mehrere Domains mit Komma trennen. Mit <code>*.uni-beispiel.de</code> sind alle Subdomains erlaubt. Später im Adminbereich änderbar.</small></div>
        <div class="field">
          <label>E-Mail-Versand</label>
          <select class="input" name="mail_mode">
            <option value="mail"<?= $v['mail_mode'] === 'mail' ? ' selected' : '' ?>>PHP mail() – Standard beim Webhosting</option>
            <option value="log"<?= $v['mail_mode'] === 'log' ? ' selected' : '' ?>>Nur protokollieren (Testbetrieb, keine echten E-Mails)</option>
            <option value="smtp"<?= $v['mail_mode'] === 'smtp' ? ' selected' : '' ?>>SMTP (Zugangsdaten später im Adminbereich eintragen)</option>
          </select>
        </div>
      </div>

      <div class="form-section">
        <h3><?= $state === 'new' ? '4' : '3' ?>. Admin-Konto</h3>
        <div class="form-row">
          <div class="field"><label>Name</label><input class="input" name="admin_name" value="<?= h($v['admin_name']) ?>"></div>
          <div class="field"><label>E-Mail-Adresse</label><input class="input" type="email" name="admin_email" value="<?= h($v['admin_email']) ?>" required></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Passwort (mind. 10 Zeichen)</label><input class="input" type="password" name="admin_pass" required minlength="10" autocomplete="new-password"></div>
          <div class="field"><label>Passwort wiederholen</label><input class="input" type="password" name="admin_pass2" required autocomplete="new-password"></div>
        </div>
      </div>

      <div class="form-section">
        <h3><?= $state === 'new' ? '5' : '4' ?>. Demodaten</h3>
        <label class="checkbox"><input type="checkbox" name="demo" value="1"<?= $v['demo'] ? ' checked' : '' ?>><span><strong>Testprüfer mit Beispielprotokollen anlegen</strong> (3–4 Prüfer je Prüfung mit je 2 Protokollen, teils mit PDF). Lassen sich später mit einem Klick entfernen.</span></label>
        <label class="checkbox"><input type="checkbox" name="demo_student" value="1"<?= $v['demo_student'] ? ' checked' : '' ?>><span>Zusätzlich ein Demo-Studierendenkonto mit simuliertem Kauf anlegen</span></label>
      </div>
      <button type="submit" class="btn btn-primary btn-lg">Jetzt installieren</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
</main>
</body>
</html>
