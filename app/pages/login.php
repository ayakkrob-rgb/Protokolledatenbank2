<?php
/** Anmeldung. */
if (Auth::check()) {
    redirect('account');
}
View::$title = 'Anmelden';
View::$active = 'login';
$email = '';
$error = '';
$unverified = false;

if (is_post()) {
    $email = post_str('email');
    $res = Auth::attempt($email, (string)($_POST['password'] ?? ''));
    if ($res['ok']) {
        $intended = (string)($_SESSION['intended'] ?? '');
        unset($_SESSION['intended']);
        $first = trim(explode(' ', trim((string)$res['user']['name']))[0] ?? '');
        flash('success', 'Willkommen zurück' . ($first !== '' ? ', ' . $first : '') . '!');
        if ($intended !== '' && str_starts_with($intended, base_url())) {
            redirect_url($intended);
        }
        redirect(Auth::isStaff() ? 'admin' : 'account');
    }
    if ($res['error'] === 'unverified') {
        $unverified = true;
        $error = 'Bitte bestätige zuerst deine E-Mail-Adresse über den Link, den wir dir geschickt haben.';
    } else {
        $error = $res['error'];
    }
}
?>
<div class="auth-wrap">
  <div class="card">
    <h1>Anmelden</h1>
    <p class="muted">Melde dich mit deiner studentischen E-Mail-Adresse an.</p>
    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= icon('alert') ?><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <?php if ($unverified): ?>
      <form method="post" action="<?= e(url('resend')) ?>" class="mb-2">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= icon('mail') ?> Bestätigungs-E-Mail erneut senden</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('login')) ?>">
      <?= csrf_field() ?>
      <?= field_text('email', 'E-Mail-Adresse', $email, ['type' => 'email', 'required' => true, 'attrs' => ['autocomplete' => 'email', 'autofocus' => $email === '']]) ?>
      <?= field_text('password', 'Passwort', '', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password', 'autofocus' => $email !== '']]) ?>
      <div class="flex-between mb-2">
        <a href="<?= e(url('forgot')) ?>" class="small">Passwort vergessen?</a>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg"><?= icon('login') ?> Anmelden</button>
    </form>
  </div>
  <p class="auth-footer">Noch kein Konto? <a href="<?= e(url('register')) ?>">Jetzt registrieren</a></p>
</div>
