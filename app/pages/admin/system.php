<?php
/** Admin: Systemprüfung, Wartung, Demodaten. */
require_once APP_ROOT . '/app/lib/demo.php';
View::$title = 'System & Wartung';
View::$active = 'system';
$action = post_str('action');

if (is_post()) {
    switch ($action) {
        case 'demo_remove':
            $r = Demo::remove();
            flash('success', 'Demodaten entfernt: ' . $r['examiners'] . ' Prüfer, ' . $r['protocols'] . ' Protokolle, ' . $r['users'] . ' Demo-Konten.');
            break;
        case 'demo_seed':
            $r = Demo::seed(true);
            flash('success', 'Demodaten angelegt: ' . $r['examiners'] . ' Prüfer mit ' . $r['protocols'] . ' Protokollen.' . ($r['student'] ? ' Demo-Konto: ' . $r['student']['email'] . ' / ' . $r['student']['password'] : ''));
            break;
        case 'cleanup':
            $pending = Payments::cleanupPending();
            $attempts = DB::q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 2 * 86400)])->rowCount();
            $tokens = DB::q('UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE reset_expires < ?', [now()])->rowCount();
            $events = DB::q('DELETE FROM stripe_events WHERE processed_at < ?', [date('Y-m-d H:i:s', time() - 90 * 86400)])->rowCount();
            $files = 0;
            foreach (glob(Importer::tmpDir() . '/*') ?: [] as $f) {
                if (basename($f) === 'index.html' || filemtime($f) > time() - 86400) {
                    continue;
                }
                is_dir($f) ? Importer::removeDir($f) : @unlink($f);
                $files++;
            }
            flash('success', "Aufgeräumt: $pending offene Käufe abgebrochen, $attempts alte Anmeldeversuche, $tokens abgelaufene Reset-Links, $events alte Webhook-Einträge, $files temporäre Dateien.");
            break;
        case 'prune_logs':
            $days = max(30, post_int('days', 365));
            $cut = date('Y-m-d H:i:s', time() - $days * 86400);
            $a = DB::q('DELETE FROM activity_log WHERE created_at < ?', [$cut])->rowCount();
            $d = DB::q('DELETE FROM download_log WHERE created_at < ?', [$cut])->rowCount();
            $m = DB::q('DELETE FROM mail_log WHERE created_at < ?', [$cut])->rowCount();
            flash('success', "Gelöscht: $a Aktivitäten, $d Abrufe, $m E-Mails (älter als $days Tage).");
            break;
        case 'db_update':
            $sql = (string)file_get_contents(APP_ROOT . '/app/schema.sql');
            $count = 0;
            foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
                if ($stmt !== '') {
                    DB::pdo()->exec($stmt);
                    $count++;
                }
            }
            flash('success', 'Datenbankstruktur geprüft (' . $count . ' Tabellen).');
            break;
        case 'storage_test':
            $testFile = Protocols::storageDir() . '/schutztest.txt';
            @file_put_contents($testFile, 'Wenn du diesen Text im Browser siehst, ist der Speicherordner NICHT geschützt.');
            $url = base_url() . 'storage/uploads/schutztest.txt';
            $code = 0;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $exposed = $code === 0 ? null : ($code === 200 && str_contains($body, 'NICHT geschützt'));
            } else {
                $exposed = null;
            }
            @unlink($testFile);
            if ($exposed === null) {
                flash('warning', 'Automatischer Test nicht möglich. Rufe ' . $url . ' manuell auf – es sollte ein Fehler 403 erscheinen.');
            } elseif ($exposed) {
                flash('error', 'Achtung: Der Speicherordner ist öffentlich erreichbar (' . $url . '). Dein Server beachtet die .htaccess-Dateien nicht (z. B. nginx). Lege den Ordner storage außerhalb des Webverzeichnisses ab (siehe README) oder sperre ihn in der Serverkonfiguration.');
            } else {
                flash('success', 'Der Speicherordner ist geschützt (HTTP ' . $code . ').');
            }
            break;
    }
    redirect('admin/system');
}

$checks = [];
$add = function (string $label, $state, string $detail = '') use (&$checks) {
    $checks[] = [$label, $state, $detail];
};
$add('PHP-Version ' . PHP_VERSION, PHP_VERSION_ID >= 80000 ? 'ok' : 'bad', 'mind. 8.0, empfohlen 8.2+');
foreach (['pdo_mysql' => 'Datenbank', 'curl' => 'Stripe-Verbindung', 'mbstring' => 'Umlaute/Texte', 'openssl' => 'Verschlüsselung, SMTP', 'fileinfo' => 'Dateiprüfung', 'zip' => 'ZIP-Import und ZIP-Download'] as $ext => $why) {
    $add('PHP-Erweiterung ' . $ext, extension_loaded($ext) ? 'ok' : ($ext === 'zip' || $ext === 'fileinfo' ? 'warn' : 'bad'), $why);
}
foreach ([Protocols::storageDir() => 'Uploads', Importer::importDir() => 'FTP-Import', Importer::tmpDir() => 'Temporäre Dateien', STORAGE_PATH . '/logs' => 'Logs', STORAGE_PATH . '/sessions' => 'Sitzungen'] as $dir => $why) {
    $add('Ordner ' . str_replace(APP_ROOT . '/', '', $dir), is_dir($dir) && is_writable($dir) ? 'ok' : 'bad', $why . (is_dir($dir) ? (is_writable($dir) ? ' – beschreibbar' : ' – nicht beschreibbar') : ' – fehlt'));
}
$add('Upload-Limit (upload_max_filesize / post_max_size)', Protocols::serverUploadLimit() >= 8 * 1048576 ? 'ok' : 'warn', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size') . ' – anpassbar über die Datei .user.ini');
$add('max_execution_time', ((int)ini_get('max_execution_time') === 0 || (int)ini_get('max_execution_time') >= 30) ? 'ok' : 'warn', ini_get('max_execution_time') . ' Sekunden');
$add('HTTPS', is_https() ? 'ok' : 'warn', is_https() ? 'aktiv' : 'Bitte SSL beim Hoster aktivieren – Pflicht für Stripe-Webhooks und sichere Anmeldung.');
$add('Installationsordner entfernt', is_dir(APP_ROOT . '/install') ? 'warn' : 'ok', is_dir(APP_ROOT . '/install') ? 'Bitte den Ordner install/ per FTP löschen.' : '');
$add('Fehleranzeige (debug)', APP_DEBUG ? 'warn' : 'ok', APP_DEBUG ? 'debug ist in config/config.php aktiviert – im Livebetrieb abschalten.' : 'aus');
$dbVersion = (string)DB::val('SELECT VERSION()');
$storageBytes = (int)DB::val('SELECT COALESCE(SUM(size),0) FROM protocol_files');
$fileCount = (int)DB::val('SELECT COUNT(*) FROM protocol_files');
$demo = Demo::exists();
$demoExaminers = (int)DB::val('SELECT COUNT(*) FROM examiners WHERE is_demo = 1');
$demoStudent = (string)setting('demo_student', '');
$icons = ['ok' => ['check-circle', 'ok'], 'warn' => ['alert', 'warn'], 'bad' => ['x', 'bad']];
?>
<?= admin_head('System & Wartung', 'Version ' . APP_VERSION . ' · MySQL/MariaDB ' . e($dbVersion) . ' · ' . $fileCount . ' Dateien (' . e(human_filesize($storageBytes)) . ')') ?>

<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h3>Systemprüfung</h3>
    <ul class="check-list">
      <?php foreach ($checks as [$label, $state, $detail]): ?>
        <li><span class="<?= $icons[$state][1] ?>"><?= icon($icons[$state][0]) ?></span><div><strong><?= e($label) ?></strong><?= $detail !== '' ? '<br><span class="small muted">' . e($detail) . '</span>' : '' ?></div></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= e(url('admin/system')) ?>" class="mt-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="storage_test">
      <button type="submit" class="btn btn-secondary btn-sm"><?= icon('shield') ?> Schutz des Speicherordners testen</button>
    </form>
  </div>

  <div>
    <div class="card">
      <h3>Demodaten</h3>
      <?php if ($demo): ?>
        <p class="small">Es sind <strong><?= $demoExaminers ?> Demo-Prüfer</strong> mit Protokollen vorhanden<?= $demoStudent !== '' ? ' sowie ein Demo-Studierendenkonto' : '' ?>.</p>
        <?php if ($demoStudent !== ''): ?>
          <div class="code-box mb-2"><span><?= e($demoStudent) ?></span></div>
          <p class="small muted">Mit diesem Konto siehst du die Webseite aus Sicht einer Studentin, die zwei M1-Prüfer (simuliert) gekauft hat. Lade damit ein M1-Protokoll hoch, um die automatische Erstattung zu testen.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/system')) ?>" data-confirm="Alle Demo-Prüfer, deren Protokolle und Demo-Konten endgültig löschen? Eigene Daten bleiben erhalten.">
          <?= csrf_field() ?><input type="hidden" name="action" value="demo_remove">
          <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Demodaten entfernen</button>
        </form>
      <?php else: ?>
        <p class="small muted">Keine Demodaten vorhanden. Du kannst Testprüfer mit Beispielprotokollen anlegen, um die Funktionen auszuprobieren.</p>
        <form method="post" action="<?= e(url('admin/system')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="demo_seed">
          <button type="submit" class="btn btn-secondary btn-sm"><?= icon('plus') ?> Demodaten anlegen</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Wartung</h3>
      <p class="small muted">Diese Aufgaben laufen teilweise automatisch. Auf Webhosting ohne Cronjobs kannst du sie hier manuell anstoßen.</p>
      <form method="post" action="<?= e(url('admin/system')) ?>" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="cleanup">
        <button type="submit" class="btn btn-secondary btn-sm"><?= icon('refresh') ?> Aufräumen (offene Käufe, temporäre Dateien, alte Tokens)</button>
      </form>
      <form method="post" action="<?= e(url('admin/system')) ?>" class="mb-2" data-confirm="Alte Protokolleinträge löschen?">
        <?= csrf_field() ?><input type="hidden" name="action" value="prune_logs">
        <div class="input-group">
          <select name="days" class="input input-sm"><option value="180">älter als 180 Tage</option><option value="365" selected>älter als 1 Jahr</option><option value="730">älter als 2 Jahre</option></select>
          <button type="submit" class="btn btn-secondary btn-sm">Logs löschen</button>
        </div>
        <small class="help">Datenschutz: Aktivitäts-, Abruf- und E-Mail-Logs nicht länger als nötig aufbewahren.</small>
      </form>
      <form method="post" action="<?= e(url('admin/system')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="db_update">
        <button type="submit" class="btn btn-ghost btn-sm"><?= icon('server') ?> Datenbankstruktur prüfen / ergänzen</button>
        <small class="help">Nach einem Update der Software ausführen. Bestehende Daten bleiben erhalten.</small>
      </form>
    </div>

    <div class="card">
      <h3>Datensicherung</h3>
      <p class="small mb-0">Sichere regelmäßig <strong>die Datenbank</strong> (z. B. Export über phpMyAdmin im Kundenmenü des Hosters) und den Ordner <code>storage/uploads</code> (per FTP). Viele Hoster bieten zusätzlich automatische Backups an.</p>
    </div>
  </div>
</div>
