<?php
/** Passwort vergessen. */
View::$title = 'Passwort vergessen';
View::$active = 'login';
$sent = false;
if (is_post()) {
    $email = post_str('email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Auth::sendPasswordReset($email);
    }
    $sent = true;
}
?>
<div class="auth-wrap">
  <div class="card">
    <h1>Passwort vergessen</h1>
    <?php if ($sent): ?>
      <div class="alert alert-success"><?= icon('check-circle') ?><div>Falls ein Konto mit dieser Adresse existiert, haben wir dir eine E-Mail mit einem Link zum Zurücksetzen geschickt. Der Link ist 2 Stunden gültig.</div></div>
      <a class="btn btn-secondary btn-block" href="<?= e(url('login')) ?>">Zurück zur Anmeldung</a>
    <?php else: ?>
      <p class="muted">Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link, mit dem du ein neues Passwort festlegen kannst.</p>
      <form method="post" action="<?= e(url('forgot')) ?>">
        <?= csrf_field() ?>
        <?= field_text('email', 'E-Mail-Adresse', '', ['type' => 'email', 'required' => true, 'attrs' => ['autocomplete' => 'email', 'autofocus' => true]]) ?>
        <button type="submit" class="btn btn-primary btn-block">Link anfordern</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="auth-footer"><a href="<?= e(url('login')) ?>">Zur Anmeldung</a></p>
</div>
