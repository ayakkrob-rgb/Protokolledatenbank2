<?php
/** Admin: Kaufdetails, Prüfer ändern, Erstattung auslösen, Zugang entziehen. */
View::$active = 'purchases';
$id = is_post() ? post_int('id') : get_int('id');
$p = Payments::purchaseWithDetails($id);
if (!$p) {
    abort(404);
}
View::$title = 'Kauf #' . $p['id'];
$action = post_str('action');

if (is_post()) {
    switch ($action) {
        case 'mark_paid':
            if (Payments::markPaid((int)$p['id'])) {
                log_activity('purchase_marked_paid', 'Kauf #' . $p['id'] . ' manuell als bezahlt markiert');
                flash('success', 'Der Kauf wurde als bezahlt markiert und der Zugang freigeschaltet.');
            } else {
                flash('error', 'Der Kauf konnte nicht als bezahlt markiert werden.');
            }
            break;
        case 'revoke':
            DB::q("UPDATE purchases SET status = 'revoked' WHERE id = ? AND status = 'paid'", [$p['id']]);
            log_activity('purchase_revoked', 'Kauf #' . $p['id']);
            flash('success', 'Der Zugang wurde entzogen.');
            break;
        case 'restore':
            DB::q("UPDATE purchases SET status = 'paid' WHERE id = ? AND status = 'revoked'", [$p['id']]);
            log_activity('purchase_restored', 'Kauf #' . $p['id']);
            flash('success', 'Der Zugang wurde wiederhergestellt.');
            break;
        case 'refund':
            $amount = parse_money(post_str('amount'));
            if ($amount === null || $amount <= 0) {
                flash('error', 'Bitte gib einen gültigen Betrag ein.');
                break;
            }
            $res = Payments::adminRefund((int)$p['id'], $amount);
            $msgs = [
                'succeeded' => ['success', 'Erstattung über ' . money($res['amount'] ?? $amount) . ' war erfolgreich.'],
                'pending' => ['success', 'Erstattung über ' . money($res['amount'] ?? $amount) . ' wurde veranlasst.'],
                'manual' => ['info', 'Manuell bezahlter Kauf: Bitte Betrag persönlich auszahlen und anschließend als erledigt markieren.'],
                'failed' => ['error', 'Erstattung fehlgeschlagen: ' . ($res['error'] ?? '')],
            ];
            [$t, $m] = $msgs[$res['status']] ?? ['error', $res['error'] ?? 'Keine Erstattung möglich.'];
            flash($t, $m);
            break;
        case 'retry':
            $res = Payments::retryRefund((int)$p['id']);
            flash(in_array($res['status'], ['succeeded', 'pending', 'manual'], true) ? 'success' : 'error', in_array($res['status'], ['succeeded', 'pending', 'manual'], true) ? 'Die Erstattung wurde erneut ausgelöst.' : 'Erneuter Versuch fehlgeschlagen: ' . ($res['error'] ?? ''));
            break;
        case 'refund_done':
            Payments::markRefundDone((int)$p['id']);
            flash('success', 'Die Erstattung wurde als erledigt markiert.');
            break;
        case 'note':
            $expires = post_str('expires_at');
            DB::update('purchases', [
                'admin_note' => post_str('admin_note'),
                'expires_at' => valid_date($expires) ? $expires . ' 23:59:59' : null,
            ], 'id = ?', [$p['id']]);
            Payments::clearCache();
            flash('success', 'Gespeichert.');
            break;
        case 'add_examiner':
            $eid = post_int('examiner_id');
            if (DB::val('SELECT COUNT(*) FROM examiners WHERE id = ? AND exam_id = ?', [$eid, $p['exam_id']])) {
                DB::q('INSERT IGNORE INTO purchase_examiners (purchase_id, examiner_id) VALUES (?, ?)', [$p['id'], $eid]);
                log_activity('purchase_examiner_added', 'Kauf #' . $p['id'] . ': Prüfer #' . $eid);
                flash('success', 'Prüfer hinzugefügt.');
            }
            break;
        case 'remove_examiner':
            DB::q('DELETE FROM purchase_examiners WHERE purchase_id = ? AND examiner_id = ?', [$p['id'], post_int('examiner_id')]);
            log_activity('purchase_examiner_removed', 'Kauf #' . $p['id'] . ': Prüfer #' . post_int('examiner_id'));
            flash('success', 'Prüfer entfernt.');
            break;
        case 'delete':
            if (!in_array($p['status'], ['pending', 'cancelled', 'failed'], true) && $p['provider'] !== 'simulation') {
                flash('error', 'Bezahlte echte Käufe können nicht gelöscht werden (Buchhaltung). Entziehe stattdessen den Zugang.');
                break;
            }
            DB::q('UPDATE protocols SET refund_purchase_id = NULL WHERE refund_purchase_id = ?', [$p['id']]);
            DB::delete('purchases', 'id = ?', [$p['id']]);
            log_activity('purchase_deleted', 'Kauf #' . $p['id']);
            flash('success', 'Der Kauf wurde gelöscht.');
            redirect('admin/purchases');
    }
    redirect('admin/purchase', ['id' => $p['id']]);
}

$max = Payments::maxExaminers($p['exam']);
$currentIds = array_map(fn($e) => (int)$e['id'], $p['examiners']);
$addable = array_filter(Protocols::examinersForExam((int)$p['exam_id'], true), fn($e) => !in_array((int)$e['id'], $currentIds, true));
$refundProtocol = $p['refund_protocol_id'] ? DB::one('SELECT id, title FROM protocols WHERE id = ?', [$p['refund_protocol_id']]) : null;
$live = Stripe::isLive();
$defaultRefund = min(Payments::refundFor($p['exam']), (int)$p['amount_cents']);
?>
<?= admin_head('Kauf #' . $p['id'], '<a href="' . e(url('admin/purchases')) . '">← Alle Käufe</a>') ?>

<?php if ($p['refund_status'] === 'failed'): ?>
  <div class="alert alert-error"><?= icon('alert') ?><div><strong>Rückerstattung fehlgeschlagen:</strong> <?= e($p['refund_error']) ?>
    <div class="mt-1 btn-group"><?= post_button(url('admin/purchase'), icon('refresh') . ' Erneut versuchen', ['id' => $p['id'], 'action' => 'retry'], 'btn btn-primary btn-sm') ?><?= post_button(url('admin/purchase'), icon('check') . ' Manuell erledigt', ['id' => $p['id'], 'action' => 'refund_done'], 'btn btn-secondary btn-sm', 'Hast du den Betrag auf anderem Weg erstattet?') ?></div>
  </div></div>
<?php elseif ($p['refund_status'] === 'manual'): ?>
  <div class="alert alert-warning"><?= icon('info') ?><div><strong>Manuelle Auszahlung nötig:</strong> Bitte <?= e(money($p['refund_cents'], $p['currency'])) ?> an <?= e($p['user']['name'] ?: $p['user']['email']) ?> auszahlen.
    <div class="mt-1"><?= post_button(url('admin/purchase'), icon('check') . ' Als ausgezahlt markieren', ['id' => $p['id'], 'action' => 'refund_done'], 'btn btn-primary btn-sm') ?></div>
  </div></div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start">
  <div>
    <div class="card">
      <div class="card-header"><h3>Übersicht</h3><div><?= Payments::statusBadge($p['status']) ?> <?= Payments::refundBadge($p['refund_status']) ?></div></div>
      <dl class="meta-list">
        <dt>Nutzer</dt><dd><a href="<?= e(url('admin/user', ['id' => $p['user_id']])) ?>"><?= e($p['user']['email']) ?></a> <span class="muted"><?= e($p['user']['name']) ?></span></dd>
        <dt>Prüfung</dt><dd><?= e($p['exam']['name']) ?></dd>
        <dt>Betrag</dt><dd><?= e(money($p['amount_cents'], $p['currency'])) ?></dd>
        <dt>Anbieter</dt><dd><?= e(Payments::providerLabel($p['provider'])) ?></dd>
        <dt>Erstellt</dt><dd><?= e(fmt_datetime($p['created_at'])) ?></dd>
        <dt>Bezahlt</dt><dd><?= e(fmt_datetime($p['paid_at'])) ?></dd>
        <dt>Gültig bis</dt><dd><?= e($p['expires_at'] ? fmt_date($p['expires_at']) : 'unbegrenzt') ?></dd>
        <?php if ($p['refund_status'] !== 'none'): ?>
          <dt>Erstattung</dt><dd><?= e(money($p['refund_cents'], $p['currency'])) ?><?= $p['refunded_at'] ? ' am ' . e(fmt_datetime($p['refunded_at'])) : '' ?></dd>
          <?php if ($refundProtocol): ?><dt>Für Protokoll</dt><dd><a href="<?= e(url('admin/protocol', ['id' => $refundProtocol['id']])) ?>"><?= e($refundProtocol['title']) ?></a></dd><?php endif; ?>
          <?php if ($p['refund_id']): ?><dt>Refund-ID</dt><dd class="mono small"><?= e($p['refund_id']) ?></dd><?php endif; ?>
        <?php endif; ?>
        <?php if ($p['stripe_session_id']): ?><dt>Checkout-Session</dt><dd class="mono small" style="overflow-wrap:anywhere"><?= e($p['stripe_session_id']) ?></dd><?php endif; ?>
        <?php if ($p['stripe_payment_intent']): ?><dt>Payment Intent</dt><dd class="mono small"><a href="<?= e(Stripe::dashboardPaymentUrl($p['stripe_payment_intent'], $live)) ?>" target="_blank" rel="noopener"><?= e($p['stripe_payment_intent']) ?> <?= icon('external') ?></a></dd><?php endif; ?>
      </dl>
    </div>

    <div class="card card-flush">
      <div class="card-header"><h3>Freigeschaltete Prüfer (<?= count($p['examiners']) ?> / <?= $max ?>)</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($p['examiners'] as $ex): ?>
          <tr>
            <td><a href="<?= e(url('admin/examiner', ['id' => $ex['id']])) ?>"><?= e($ex['name']) ?></a><span class="table-sub"><?= e($ex['subject'] ?: '–') ?></span></td>
            <td class="actions"><?= post_button(url('admin/purchase'), icon('x'), ['id' => $p['id'], 'action' => 'remove_examiner', 'examiner_id' => $ex['id']], 'btn btn-ghost btn-xs', 'Prüfer aus diesem Kauf entfernen?') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php if ($addable): ?>
        <form method="post" action="<?= e(url('admin/purchase')) ?>" style="padding:14px 20px;border-top:1px solid var(--c-border)">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="action" value="add_examiner">
          <div class="input-group">
            <select name="examiner_id" class="input input-sm">
              <?php foreach ($addable as $ex): ?><option value="<?= (int)$ex['id'] ?>"><?= e($ex['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('plus') ?> Hinzufügen</button>
          </div>
          <?php if (count($p['examiners']) >= $max): ?><small class="help">Achtung: Das Maximum von <?= $max ?> Prüfern ist bereits erreicht – als Admin kannst du trotzdem weitere hinzufügen.</small><?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>Aktionen</h3>
      <div class="btn-group">
        <?php if (in_array($p['status'], ['pending', 'processing', 'cancelled', 'failed'], true)): ?>
          <?= post_button(url('admin/purchase'), icon('check') . ' Als bezahlt markieren', ['id' => $p['id'], 'action' => 'mark_paid'], 'btn btn-success btn-sm', 'Zahlung wirklich als eingegangen markieren? Der Zugang wird sofort freigeschaltet.') ?>
        <?php endif; ?>
        <?php if ($p['status'] === 'paid'): ?>
          <?= post_button(url('admin/purchase'), icon('lock') . ' Zugang entziehen', ['id' => $p['id'], 'action' => 'revoke'], 'btn btn-outline-danger btn-sm', 'Zugang entziehen? Die Person kann die Protokolle dann nicht mehr sehen.') ?>
        <?php elseif ($p['status'] === 'revoked'): ?>
          <?= post_button(url('admin/purchase'), icon('unlock') . ' Zugang wiederherstellen', ['id' => $p['id'], 'action' => 'restore'], 'btn btn-secondary btn-sm') ?>
        <?php endif; ?>
        <?php if (in_array($p['refund_status'], ['processing', 'pending'], true)): ?>
          <?= post_button(url('admin/purchase'), icon('check') . ' Erstattung als erledigt markieren', ['id' => $p['id'], 'action' => 'refund_done'], 'btn btn-secondary btn-sm') ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($p['status'] === 'paid' && in_array($p['refund_status'], ['none', 'failed'], true) && (int)$p['amount_cents'] > 0): ?>
      <form method="post" action="<?= e(url('admin/purchase')) ?>" class="card" data-confirm="Erstattung jetzt auslösen?">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="refund">
        <h3><?= icon('refund') ?> Erstattung auslösen</h3>
        <p class="small muted">Normalerweise passiert das automatisch, sobald die Person ein Protokoll hochlädt. Hier kannst du manuell erstatten, z. B. aus Kulanz (auch den vollen Betrag).</p>
        <div class="input-group">
          <input type="text" name="amount" class="input" value="<?= e(money_input($defaultRefund)) ?>" aria-label="Betrag">
          <button type="submit" class="btn btn-primary">Erstatten</button>
        </div>
        <small class="help">Maximal <?= e(money($p['amount_cents'], $p['currency'])) ?><?= $p['provider'] === 'stripe' ? ' · wird über Stripe auf das Zahlungsmittel zurückgebucht' : ($p['provider'] === 'simulation' ? ' · Simulation' : ' · manuelle Auszahlung') ?></small>
      </form>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/purchase')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="action" value="note">
      <h3>Notiz &amp; Laufzeit</h3>
      <?= field_textarea('admin_note', 'Interne Notiz', (string)$p['admin_note'], ['rows' => 3]) ?>
      <?= field_text('expires_at', 'Zugang gültig bis (leer = unbegrenzt)', $p['expires_at'] ? substr($p['expires_at'], 0, 10) : '', ['type' => 'date']) ?>
      <button type="submit" class="btn btn-secondary">Speichern</button>
    </form>

    <?php if (in_array($p['status'], ['pending', 'cancelled', 'failed'], true) || $p['provider'] === 'simulation'): ?>
      <form method="post" action="<?= e(url('admin/purchase')) ?>" class="card danger-zone" data-confirm="Kauf endgültig löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <h3>Kauf löschen</h3>
        <p class="small muted">Nur für nicht bezahlte oder simulierte Käufe möglich.</p>
        <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Löschen</button>
      </form>
    <?php endif; ?>
  </div>
</div>
