<?php
/** Rückkehr nach der Zahlung: Bestätigung bzw. Warten auf Stripe. */
$user = Auth::user();
$p = Payments::purchaseWithDetails(get_int('purchase'));
if (!$p || (int)$p['user_id'] !== (int)$user['id']) {
    abort(404);
}

// Direkt bei Stripe nachfragen – funktioniert auch, wenn der Webhook (noch) nicht eingerichtet ist.
$sessionId = get_str('session_id');
if ($p['provider'] === 'stripe' && $p['status'] !== 'paid' && $sessionId !== '' && $sessionId === $p['stripe_session_id']) {
    try {
        $session = Stripe::request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
        Payments::handleCheckoutSession($session);
    } catch (Throwable $e) {
        app_log('warning', 'Stripe-Session konnte nicht abgerufen werden: ' . $e->getMessage());
    }
    $p = Payments::purchaseWithDetails((int)$p['id']);
}

View::$title = $p['status'] === 'paid' ? 'Zahlung erfolgreich' : 'Zahlung';
$refund = Payments::refundFor($p['exam']);
$tries = get_int('try');
if ($p['status'] === 'pending' && $tries < 20) {
    header('Refresh: 4; url=' . url('checkout-success', ['purchase' => $p['id'], 'session_id' => $sessionId ?: null, 'try' => $tries + 1]));
}
?>
<div class="container container-narrow">
  <?php if ($p['status'] === 'paid'): ?>
    <div class="card empty-state mt-4">
      <div class="empty-icon" style="background:var(--c-success-bg);color:var(--c-success)"><?= icon('check-circle') ?></div>
      <h1>Zahlung erfolgreich!</h1>
      <p>Dein Zugang für <strong><?= e($p['exam']['name']) ?></strong> ist freigeschaltet. Du findest ihn jederzeit unter „Mein Bereich“.<?= $p['provider'] === 'simulation' ? ' (Testzahlung)' : '' ?></p>
      <div class="purchase-body" style="justify-content:center">
        <?php foreach ($p['examiners'] as $ex): ?>
          <a class="chip" href="<?= e(url('examiner', ['id' => $ex['id']])) ?>"><?= icon('unlock') ?> <?= e($ex['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (Settings::bool('refund_enabled') && $refund > 0 && (int)$p['amount_cents'] > 0): ?>
        <div class="refund-hint mt-3" style="text-align:left"><?= icon('refund') ?><div><strong>Nicht vergessen:</strong> Nach deiner Prüfung dein eigenes Gedächtnisprotokoll hochladen – dann erstatten wir dir automatisch <?= e(money($refund)) ?>.</div></div>
      <?php endif; ?>
      <div class="btn-group mt-3" style="justify-content:center">
        <?php if (count($p['examiners']) === 1): ?>
          <a class="btn btn-primary" href="<?= e(url('examiner', ['id' => $p['examiners'][0]['id']])) ?>">Protokolle lesen <?= icon('arrow-right') ?></a>
        <?php else: ?>
          <a class="btn btn-primary" href="<?= e(url('exam', ['id' => $p['exam_id']])) ?>">Zu den Prüfern <?= icon('arrow-right') ?></a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(url('account')) ?>">Mein Bereich</a>
      </div>
    </div>
  <?php elseif ($p['status'] === 'pending'): ?>
    <div class="card empty-state mt-4">
      <div class="empty-icon"><?= icon('clock') ?></div>
      <h1>Zahlung wird bestätigt …</h1>
      <p>Wir warten auf die Bestätigung von Stripe. Diese Seite aktualisiert sich automatisch. Bei manchen Zahlungsarten (z. B. SEPA-Lastschrift) kann die Bestätigung einige Tage dauern – du bekommst dann eine E-Mail.</p>
      <a class="btn btn-secondary" href="<?= e(url('account')) ?>">Zu meinem Bereich</a>
    </div>
  <?php else: ?>
    <div class="card empty-state mt-4">
      <div class="empty-icon" style="background:var(--c-danger-bg);color:var(--c-danger)"><?= icon('alert') ?></div>
      <h1>Zahlung nicht abgeschlossen</h1>
      <p>Die Zahlung wurde abgebrochen oder ist fehlgeschlagen. Es wurde nichts abgebucht.</p>
      <a class="btn btn-primary" href="<?= e(url('buy', ['exam' => $p['exam_id']])) ?>">Erneut versuchen</a>
    </div>
  <?php endif; ?>
</div>
