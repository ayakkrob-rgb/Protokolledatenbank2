<?php
/** Neues Passwort über Link aus der E-Mail festlegen. */
View::$title = 'Neues Passwort';
View::$active = 'login';
$token = is_post() ? post_str('token') : get_str('token');
$user = Auth::userByResetToken($token);
$error = '';

if ($user && is_post()) {
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        DB::update('users', [
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'reset_token' => null,
            'reset_expires' => null,
            'is_verified' => 1,
            'verified_at' => $user['verified_at'] ?: now(),
        ], 'id = ?', [$user['id']]);
        log_activity('password_reset', $user['email'], (int)$user['id']);
        $fresh = DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        if ($fresh && !(int)$fresh['is_blocked']) {
            Auth::login($fresh);
        }
        flash('success', 'Dein neues Passwort wurde gespeichert.');
        redirect('account');
    }
}
?>
<div class="auth-wrap">
  <div class="card">
    <h1>Neues Passwort festlegen</h1>
    <?php if (!$user): ?>
      <div class="alert alert-error"><?= icon('alert') ?><div>Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.</div></div>
      <a class="btn btn-primary btn-block" href="<?= e(url('forgot')) ?>">Neuen Link anfordern</a>
    <?php else: ?>
      <?php if ($error !== ''): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
      <form method="post" action="<?= e(url('reset')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?= field_text('password', 'Neues Passwort', '', ['type' => 'password', 'required' => true, 'help' => 'Mindestens 8 Zeichen.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8, 'autofocus' => true]]) ?>
        <?= field_text('password2', 'Passwort wiederholen', '', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <button type="submit" class="btn btn-primary btn-block">Passwort speichern</button>
      </form>
    <?php endif; ?>
  </div>
</div>
