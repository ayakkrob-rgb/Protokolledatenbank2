<?php
/** Bestätigungs-E-Mail erneut senden (nur per POST). */
if (!is_post()) {
    redirect('verify', ['sent' => 1]);
}
$email = mb_strtolower(post_str('email'));
if ($email !== '' && !Auth::tooManyAttempts('resend:' . $email, 3, 60)) {
    Auth::hitAttempt('resend:' . $email);
    $user = DB::one('SELECT * FROM users WHERE email = ? AND is_verified = 0 AND is_blocked = 0', [$email]);
    if ($user) {
        Auth::sendVerification($user);
    }
}
$_SESSION['registered_email'] = $email;
flash('info', 'Falls ein unbestätigtes Konto mit dieser Adresse existiert, haben wir eine neue Bestätigungs-E-Mail verschickt.');
redirect('verify', ['sent' => 1]);
