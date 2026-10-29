<?php
/** Admin: E-Mail-Versand einstellen, Testmail senden, Mail-Log einsehen. */
View::$title = 'E-Mail';
View::$active = 'mail';
$action = post_str('action');

if (is_post()) {
    if ($action === 'save') {
        $mode = in_array(post_str('mail_mode'), ['mail', 'smtp', 'log'], true) ? post_str('mail_mode') : 'mail';
        $from = post_str('mail_from');
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte gib eine gültige Absenderadresse ein.');
            redirect('admin/mail');
        }
        Settings::setMany([
            'mail_mode' => $mode,
            'mail_from' => $from,
            'mail_from_name' => post_str('mail_from_name'),
            'smtp_host' => post_str('smtp_host'),
            'smtp_port' => (string)max(1, post_int('smtp_port', 587)),
            'smtp_secure' => in_array(post_str('smtp_secure'), ['tls', 'ssl', 'none'], true) ? post_str('smtp_secure') : 'tls',
            'smtp_user' => post_str('smtp_user'),
        ]);
        if (post_str('smtp_pass') !== '') {
            Settings::set('smtp_pass', post_str('smtp_pass'));
        }
        log_activity('mail_settings', $mode);
        flash('success', 'Die E-Mail-Einstellungen wurden gespeichert.');
    } elseif ($action === 'test') {
        $to = post_str('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte gib eine gültige Empfängeradresse ein.');
        } else {
            $ok = Mails::compose($to, 'Test-E-Mail von ' . setting('site_name'), 'Test erfolgreich', [
                'Hallo,',
                'diese Test-E-Mail bestätigt, dass der E-Mail-Versand der Protokolldatenbank funktioniert.',
                'Versandart: ' . setting('mail_mode') . ' · Absender: ' . Mailer::fromAddress(),
            ]);
            if (setting('mail_mode') === 'log') {
                flash('info', 'Die E-Mail wurde nur protokolliert (Modus „nur protokollieren“) – siehe Liste unten.');
            } else {
                $err = (string)DB::val('SELECT error FROM mail_log ORDER BY id DESC LIMIT 1');
                flash($ok ? 'success' : 'error', $ok ? 'Die Test-E-Mail wurde an ' . $to . ' verschickt. Bitte auch den Spam-Ordner prüfen.' : 'Versand fehlgeschlagen: ' . $err);
            }
        }
    } elseif ($action === 'clear') {
        DB::q('DELETE FROM mail_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        flash('success', 'E-Mails älter als 30 Tage wurden aus dem Protokoll gelöscht.');
    }
    redirect('admin/mail');
}

$viewId = get_int('view');
$viewMail = $viewId ? DB::one('SELECT * FROM mail_log WHERE id = ?', [$viewId]) : null;
$total = (int)DB::val('SELECT COUNT(*) FROM mail_log');
$pg = paginate($total, 30);
$mails = DB::all('SELECT id, recipient, subject, status, error, created_at FROM mail_log ORDER BY id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset']);
$failed = (int)DB::val("SELECT COUNT(*) FROM mail_log WHERE status = 'failed' AND created_at > ?", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
$statusBadges = ['sent' => ['Versendet', 'success'], 'logged' => ['Nur protokolliert', 'info'], 'failed' => ['Fehler', 'danger'], 'queued' => ['Wartend', 'neutral']];
$me = Auth::user();
?>
<?= admin_head('E-Mail', 'Bestätigungslinks, Kaufbestätigungen und Erstattungsmitteilungen werden per E-Mail verschickt.') ?>

<?php if ($failed > 0): ?>
  <div class="alert alert-error"><?= icon('alert') ?><div><?= $failed ?> E-Mail(s) konnten in den letzten 7 Tagen nicht verschickt werden. Prüfe die Einstellungen oder nutze SMTP.</div></div>
<?php endif; ?>

<?php if ($viewMail): ?>
  <div class="card mb-2">
    <div class="card-header"><h3><?= e($viewMail['subject']) ?></h3><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/mail')) ?>"><?= icon('x') ?> Schließen</a></div>
    <p class="small muted">An <?= e($viewMail['recipient']) ?> · <?= e(fmt_datetime($viewMail['created_at'])) ?> · <?= badge(...($statusBadges[$viewMail['status']] ?? [$viewMail['status'], 'neutral'])) ?></p>
    <?php if ($viewMail['error']): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($viewMail['error']) ?></div></div><?php endif; ?>
    <div class="mail-body"><?= e($viewMail['body_text']) ?></div>
  </div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start">
  <form method="post" action="<?= e(url('admin/mail')) ?>" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <h3>Versand</h3>
    <div class="radio-cards mb-2">
      <label class="radio-card"><input type="radio" name="mail_mode" value="mail"<?= checked(setting('mail_mode') === 'mail') ?>><div><strong>PHP mail() – Standard beim Webhosting</strong><span>Funktioniert bei den meisten Hostern ohne weitere Einstellungen.</span></div></label>
      <label class="radio-card"><input type="radio" name="mail_mode" value="smtp"<?= checked(setting('mail_mode') === 'smtp') ?>><div><strong>SMTP-Server</strong><span>Zuverlässiger (weniger Spam): z. B. das Postfach der Fachschaft beim Hoster oder der Uni.</span></div></label>
      <label class="radio-card"><input type="radio" name="mail_mode" value="log"<?= checked(setting('mail_mode') === 'log') ?>><div><strong>Nur protokollieren (Testbetrieb)</strong><span>Es wird nichts verschickt. Die E-Mails erscheinen nur unten in der Liste; Bestätigungslinks werden nach der Registrierung direkt angezeigt.</span></div></label>
    </div>
    <div class="form-row">
      <?= field_text('mail_from', 'Absenderadresse', (string)setting('mail_from'), ['type' => 'email', 'placeholder' => Mailer::fromAddress(), 'help' => 'Sollte zur Domain der Webseite gehören, sonst landen Mails im Spam.']) ?>
      <?= field_text('mail_from_name', 'Absendername', (string)setting('mail_from_name'), ['placeholder' => (string)setting('site_name')]) ?>
    </div>
    <div data-show-when="mail_mode=smtp">
      <div class="form-row form-row-3">
        <?= field_text('smtp_host', 'SMTP-Server', (string)setting('smtp_host'), ['placeholder' => 'smtp.example.de', 'class' => '']) ?>
        <?= field_text('smtp_port', 'Port', (string)setting('smtp_port'), ['type' => 'number']) ?>
        <?= field_select('smtp_secure', 'Verschlüsselung', ['tls' => 'STARTTLS (Port 587)', 'ssl' => 'SSL/TLS (Port 465)', 'none' => 'Keine'], (string)setting('smtp_secure')) ?>
      </div>
      <div class="form-row">
        <?= field_text('smtp_user', 'Benutzername', (string)setting('smtp_user'), ['attrs' => ['autocomplete' => 'off']]) ?>
        <?= field_text('smtp_pass', 'Passwort', '', ['type' => 'password', 'placeholder' => Settings::secret('smtp_pass') !== '' ? '•••••••• (gespeichert)' : '', 'help' => 'Leer lassen, um das gespeicherte Passwort zu behalten.', 'attrs' => ['autocomplete' => 'new-password']]) ?>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
  </form>

  <div>
    <form method="post" action="<?= e(url('admin/mail')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <h3>Test-E-Mail senden</h3>
      <div class="input-group">
        <input type="email" name="to" class="input" value="<?= e($me['email']) ?>" required aria-label="Empfänger">
        <button type="submit" class="btn btn-secondary"><?= icon('mail') ?> Senden</button>
      </div>
    </form>
    <div class="card">
      <h3>Tipps</h3>
      <ul class="small mb-0">
        <li>Uni-Adressen filtern oft streng. Nutze eine Absenderadresse eurer eigenen Domain und – wenn möglich – SMTP.</li>
        <li>Beim Hoster lässt sich meist ein Postfach wie <code>noreply@eure-domain.de</code> anlegen; dessen Zugangsdaten hier als SMTP eintragen.</li>
        <li>Alle E-Mails werden unten protokolliert. So siehst du, ob z. B. ein Bestätigungslink verschickt wurde.</li>
      </ul>
    </div>
  </div>
</div>

<div class="card card-flush mt-3">
  <div class="card-header">
    <h3>Versendete E-Mails (<?= $total ?>)</h3>
    <?= post_button(url('admin/mail'), icon('trash') . ' Ältere als 30 Tage löschen', ['action' => 'clear'], 'btn btn-ghost btn-sm', 'Alte Einträge löschen?') ?>
  </div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Datum</th><th>Empfänger</th><th>Betreff</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($mails as $m): ?>
          <tr>
            <td class="small nowrap"><?= e(fmt_datetime($m['created_at'])) ?></td>
            <td><?= e($m['recipient']) ?></td>
            <td><?= e($m['subject']) ?><?= $m['error'] ? '<span class="table-sub" style="color:var(--c-danger)">' . e(str_excerpt($m['error'], 120)) . '</span>' : '' ?></td>
            <td><?= badge(...($statusBadges[$m['status']] ?? [$m['status'], 'neutral'])) ?></td>
            <td class="actions"><a class="btn btn-secondary btn-xs" href="<?= e(url('admin/mail', ['view' => $m['id'], 'page' => get_int('page') ?: null])) ?>"><?= icon('eye') ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$mails): ?><tr><td colspan="5" class="muted">Noch keine E-Mails.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= pagination_links($pg) ?>
