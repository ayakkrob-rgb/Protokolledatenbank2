<?php
/** Simulierte Zahlungsseite (Testmodus ohne Stripe). */
$user = Auth::user();
$id = is_post() ? post_int('id') : get_int('id');
$p = Payments::purchaseWithDetails($id);
if (!$p || (int)$p['user_id'] !== (int)$user['id'] || $p['provider'] !== 'simulation') {
    abort(404);
}
if ($p['status'] === 'paid') {
    redirect('checkout-success', ['purchase' => $p['id']]);
}
if (!Payments::isSimulation()) {
    Payments::markCancelled((int)$p['id']);
    flash('error', 'Der Testmodus wurde inzwischen deaktiviert. Bitte starte den Kauf erneut.');
    redirect('buy', ['exam' => $p['exam_id']]);
}
if (is_post()) {
    if (post_str('action') === 'pay') {
        Payments::markPaid((int)$p['id']);
        redirect('checkout-success', ['purchase' => $p['id']]);
    }
    Payments::markCancelled((int)$p['id']);
    redirect('checkout-cancel', ['purchase' => $p['id']]);
}
View::$title = 'Testzahlung';
?>
<div class="container">
  <div class="checkout-sim">
    <div class="card">
      <div class="checkout-sim-head"><?= icon('zap') ?> Simulierte Zahlung – Testmodus</div>
      <div class="checkout-sim-body">
        <p class="muted small">Diese Seite ersetzt im Testmodus die Zahlungsseite von Stripe. Es wird kein Geld bewegt. Im Adminbereich unter „Zahlungen / Stripe“ kann der Testmodus abgeschaltet werden.</p>
        <div class="summary-line"><span>Produkt</span><strong>Protokollzugang <?= e($p['exam']['name']) ?></strong></div>
        <div class="summary-line"><span>Prüfer</span><span class="text-right"><?= e(implode(', ', array_map(fn($x) => $x['name'], $p['examiners']))) ?></span></div>
        <div class="summary-line"><span>E-Mail</span><span><?= e($p['user']['email']) ?></span></div>
        <div class="summary-line summary-total"><span>Betrag</span><span><?= e(money($p['amount_cents'], $p['currency'])) ?></span></div>
        <div class="fake-card"><span>4242 4242 4242 4242</span><span>12/34 · 123</span></div>
        <form method="post" action="<?= e(url('checkout-sim')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" name="action" value="pay" class="btn btn-success btn-block btn-lg"><?= icon('check') ?> Zahlung erfolgreich simulieren</button>
          <button type="submit" name="action" value="cancel" class="btn btn-secondary btn-block mt-1">Zahlung abbrechen</button>
        </form>
      </div>
    </div>
  </div>
</div>
