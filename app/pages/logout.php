<?php
/** Abmelden (nur per POST, wegen CSRF-Schutz). */
if (is_post()) {
    Auth::logout();
    flash('success', 'Du wurdest abgemeldet. Bis bald!');
}
redirect('');
