<?php
/** Registrierung mit studentischer E-Mail-Adresse. */
if (Auth::check()) {
    redirect('account');
}
View::$title = 'Registrieren';
View::$active = 'login';
$domains = Auth::allowedDomains();
$errors = [];
$name = '';
$email = '';
$open = Settings::bool('registration_open');

if (is_post() && $open) {
    $name = post_str('name');
    $email = post_str('email');
    if (!post_bool('terms')) {
        $errors['terms'] = 'Bitte akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.';
    } else {
        $res = Auth::register($name, $email, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        $errors = $res['errors'];
        if ($res['ok']) {
            $_SESSION['registered_email'] = mb_strtolower(trim($email));
            redirect('verify', ['sent' => 1]);
        }
    }
}
$fieldError = fn(string $k) => isset($errors[$k]) ? '<span class="error-text">' . e($errors[$k]) . '</span>' : '';
$examplePlaceholder = $domains ? 'vorname.nachname@' . ltrim($domains[0], '*.') : 'name@uni.de';
?>
<div class="auth-wrap">
  <div class="card">
    <h1>Konto erstellen</h1>
    <?php if (!$open): ?>
      <div class="alert alert-info"><?= icon('info') ?><div>Die Registrierung ist derzeit geschlossen. Bitte wende dich an die Fachschaft.</div></div>
    <?php else: ?>
      <p class="muted">Die Protokolldatenbank ist nur für Studierende. Bitte registriere dich mit deiner Uni-E-Mail-Adresse<?= $domains ? ' (' . e(implode(', ', array_map(fn($d) => '@' . ltrim($d, '*.'), $domains))) . ')' : '' ?>.</p>
      <form method="post" action="<?= e(url('register')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="field<?= isset($errors['name']) ? ' field-error' : '' ?>">
          <label for="f_name">Vor- und Nachname <span class="req">*</span></label>
          <input class="input" type="text" id="f_name" name="name" value="<?= e($name) ?>" required autocomplete="name">
          <?= $fieldError('name') ?>
        </div>
        <div class="field<?= isset($errors['email']) ? ' field-error' : '' ?>">
          <label for="f_email">Studentische E-Mail-Adresse <span class="req">*</span></label>
          <input class="input" type="email" id="f_email" name="email" value="<?= e($email) ?>" placeholder="<?= e($examplePlaceholder) ?>" required autocomplete="email">
          <?= $fieldError('email') ?>
        </div>
        <div class="field<?= isset($errors['password']) ? ' field-error' : '' ?>">
          <label for="f_password">Passwort <span class="req">*</span></label>
          <input class="input" type="password" id="f_password" name="password" required minlength="8" autocomplete="new-password">
          <?= $fieldError('password') ?: '<small class="help">Mindestens 8 Zeichen.</small>' ?>
        </div>
        <div class="field<?= isset($errors['password2']) ? ' field-error' : '' ?>">
          <label for="f_password2">Passwort wiederholen <span class="req">*</span></label>
          <input class="input" type="password" id="f_password2" name="password2" required autocomplete="new-password">
          <?= $fieldError('password2') ?>
        </div>
        <label class="checkbox<?= isset($errors['terms']) ? ' field-error' : '' ?>">
          <input type="checkbox" name="terms" value="1"<?= checked(post_bool('terms')) ?>>
          <span>Ich akzeptiere die <a href="<?= e(url('page', ['slug' => 'nutzungsbedingungen'])) ?>" target="_blank">Nutzungsbedingungen</a> und habe die <a href="<?= e(url('page', ['slug' => 'datenschutz'])) ?>" target="_blank">Datenschutzerklärung</a> gelesen.</span>
        </label>
        <?= $fieldError('terms') ?>
        <button type="submit" class="btn btn-primary btn-block btn-lg mt-2">Registrieren</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="auth-footer">Schon registriert? <a href="<?= e(url('login')) ?>">Anmelden</a></p>
</div>
