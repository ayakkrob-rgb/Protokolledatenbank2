<?php
/** E-Mail-Bestätigung: Hinweis nach der Registrierung bzw. Verarbeitung des Links. */
View::$title = 'E-Mail bestätigen';
$token = get_str('token');

if ($token !== '') {
    $user = Auth::verify($token);
    if ($user) {
        Auth::login($user);
        unset($_SESSION['registered_email']);
        flash('success', 'Deine E-Mail-Adresse ist bestätigt. Willkommen bei ' . setting('site_name') . '!');
        $intended = (string)($_SESSION['intended'] ?? '');
        unset($_SESSION['intended']);
        if ($intended !== '' && str_starts_with($intended, base_url())) {
            redirect_url($intended);
        }
        redirect('');
    }
    ?>
    <div class="auth-wrap">
      <div class="card text-center">
        <div class="empty-icon" style="background:var(--c-danger-bg);color:var(--c-danger)"><?= icon('alert') ?></div>
        <h1>Link ungültig</h1>
        <p class="muted">Dieser Bestätigungslink ist ungültig oder abgelaufen. Falls du deine Adresse schon bestätigt hast, kannst du dich einfach anmelden.</p>
        <a class="btn btn-primary" href="<?= e(url('login')) ?>">Zur Anmeldung</a>
        <form method="post" action="<?= e(url('resend')) ?>" class="mt-3">
          <?= csrf_field() ?>
          <?= field_text('email', 'Neuen Link anfordern', '', ['type' => 'email', 'placeholder' => 'Deine E-Mail-Adresse', 'required' => true]) ?>
          <button type="submit" class="btn btn-secondary btn-block">Neuen Bestätigungslink senden</button>
        </form>
      </div>
    </div>
    <?php
    return;
}

$email = (string)($_SESSION['registered_email'] ?? '');
$testLink = '';
if ($email !== '' && setting('mail_mode') === 'log') {
    $body = (string)DB::val('SELECT body_text FROM mail_log WHERE recipient = ? ORDER BY id DESC LIMIT 1', [$email]);
    if (preg_match('#https?://\S*p=verify&token=[a-f0-9]{64}#', $body, $m)) {
        $testLink = $m[0];
    }
}
?>
<div class="auth-wrap">
  <div class="card text-center">
    <div class="empty-icon"><?= icon('mail') ?></div>
    <h1>Fast geschafft!</h1>
    <p>Wir haben dir eine E-Mail<?= $email !== '' ? ' an <strong>' . e($email) . '</strong>' : '' ?> geschickt. Klicke auf den Link in der E-Mail, um deine Adresse zu bestätigen.</p>
    <p class="muted small">Keine E-Mail bekommen? Schau auch im Spam-Ordner nach. Der Link ist 48 Stunden gültig.</p>
    <?php if ($testLink !== ''): ?>
      <div class="alert alert-warning" style="text-align:left"><?= icon('zap') ?><div><strong>Testbetrieb:</strong> Der E-Mail-Versand ist auf „nur protokollieren“ gestellt, deshalb wird keine echte E-Mail verschickt. <a href="<?= e($testLink) ?>">E-Mail-Adresse jetzt bestätigen</a></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('resend')) ?>" class="mt-2">
      <?= csrf_field() ?>
      <input type="hidden" name="email" value="<?= e($email) ?>">
      <?php if ($email === ''): ?>
        <?= field_text('email', 'E-Mail-Adresse', '', ['type' => 'email', 'required' => true]) ?>
      <?php endif; ?>
      <button type="submit" class="btn btn-secondary"><?= icon('refresh') ?> E-Mail erneut senden</button>
    </form>
  </div>
</div>
