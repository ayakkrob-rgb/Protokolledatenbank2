<?php
/** Zahlung abgebrochen. */
$user = Auth::user();
$p = DB::one('SELECT * FROM purchases WHERE id = ? AND user_id = ?', [get_int('purchase'), $user['id']]);
if (!$p) {
    abort(404);
}
if ($p['status'] === 'paid') {
    redirect('checkout-success', ['purchase' => $p['id']]);
}
Payments::markCancelled((int)$p['id']);
View::$title = 'Zahlung abgebrochen';
?>
<div class="container container-narrow">
  <div class="card empty-state mt-4">
    <div class="empty-icon"><?= icon('x') ?></div>
    <h1>Zahlung abgebrochen</h1>
    <p>Kein Problem – es wurde nichts abgebucht. Du kannst die Freischaltung jederzeit erneut starten.</p>
    <div class="btn-group" style="justify-content:center">
      <a class="btn btn-primary" href="<?= e(url('buy', ['exam' => $p['exam_id']])) ?>">Erneut versuchen</a>
      <a class="btn btn-secondary" href="<?= e(url('exam', ['id' => $p['exam_id']])) ?>">Zurück zur Prüfung</a>
    </div>
  </div>
</div>
