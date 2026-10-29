<?php
/** Mein Bereich: Freischaltungen, eigene Protokolle, Kontoeinstellungen. */
$user = Auth::user();
View::$title = 'Mein Bereich';
View::$active = 'account';
$tab = get_str('tab', 'access');
if (!in_array($tab, ['access', 'uploads', 'profile'], true)) {
    $tab = 'access';
}

if (is_post()) {
    $action = post_str('action');
    if ($action === 'profile') {
        $name = post_str('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            flash('error', 'Bitte gib deinen Namen an.');
        } else {
            DB::update('users', ['name' => $name], 'id = ?', [$user['id']]);
            flash('success', 'Dein Name wurde gespeichert.');
        }
        redirect('account', ['tab' => 'profile']);
    }
    if ($action === 'password') {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Das aktuelle Passwort ist falsch.');
        } elseif (mb_strlen($new) < 8) {
            flash('error', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.');
        } elseif ($new !== (string)($_POST['new2'] ?? '')) {
            flash('error', 'Die neuen Passwörter stimmen nicht überein.');
        } else {
            DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
            log_activity('password_changed', '');
            flash('success', 'Dein Passwort wurde geändert.');
        }
        redirect('account', ['tab' => 'profile']);
    }
    if ($action === 'delete') {
        if (!password_verify((string)($_POST['password'] ?? ''), $user['password_hash']) || !post_bool('confirm')) {
            flash('error', 'Bitte bestätige die Löschung mit deinem Passwort.');
            redirect('account', ['tab' => 'profile']);
        }
        if ($user['role'] === 'admin' && (int)DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_blocked = 0") <= 1) {
            flash('error', 'Du bist der einzige Admin. Bitte ernenne zuerst einen weiteren Admin.');
            redirect('account', ['tab' => 'profile']);
        }
        DB::update('users', [
            'email' => 'geloescht-' . $user['id'] . '-' . random_token(4) . '@konto.invalid',
            'name' => 'Gelöschtes Konto',
            'password_hash' => password_hash(random_token(), PASSWORD_DEFAULT),
            'role' => 'student',
            'is_blocked' => 1,
            'verify_token' => null,
            'reset_token' => null,
        ], 'id = ?', [$user['id']]);
        log_activity('account_deleted', 'Konto #' . $user['id'] . ' gelöscht/anonymisiert', (int)$user['id']);
        Auth::logout();
        flash('success', 'Dein Konto wurde gelöscht. Deine Protokolle bleiben anonym erhalten.');
        redirect('');
    }
}

$purchases = DB::all(
    "SELECT p.*, x.name AS exam_name, x.short_name, x.color, x.max_examiners
     FROM purchases p JOIN exams x ON x.id = p.exam_id
     WHERE p.user_id = ? AND p.status IN ('paid','revoked') ORDER BY p.paid_at DESC, p.id DESC",
    [$user['id']]
);
$otherPurchases = DB::all(
    "SELECT p.*, x.name AS exam_name FROM purchases p JOIN exams x ON x.id = p.exam_id
     WHERE p.user_id = ? AND p.status IN ('pending','failed','cancelled') ORDER BY p.id DESC LIMIT 10",
    [$user['id']]
);
$uploads = DB::all(
    "SELECT p.*, e.name AS examiner_name, x.name AS exam_name, pu.refund_status, pu.refund_cents
     FROM protocols p JOIN examiners e ON e.id = p.examiner_id JOIN exams x ON x.id = e.exam_id
     LEFT JOIN purchases pu ON pu.id = COALESCE(p.refund_purchase_id,
        (SELECT p2.refund_purchase_id FROM protocols p2 WHERE p2.submission_id = p.submission_id AND p2.refund_purchase_id IS NOT NULL LIMIT 1))
     WHERE p.user_id = ? ORDER BY p.created_at DESC, p.id",
    [$user['id']]
);
$refundOn = Settings::bool('refund_enabled');
?>
<div class="container">
  <div class="page-head">
    <h1>Mein Bereich</h1>
    <p class="lead">Hallo <?= e($user['name']) ?>! Hier findest du deine freigeschalteten Prüfer und deine Protokolle.</p>
  </div>

  <nav class="tabs">
    <a href="<?= e(url('account')) ?>" class="<?= $tab === 'access' ? 'active' : '' ?>"><?= icon('unlock') ?> Freischaltungen <span class="count"><?= count($purchases) ?></span></a>
    <a href="<?= e(url('account', ['tab' => 'uploads'])) ?>" class="<?= $tab === 'uploads' ? 'active' : '' ?>"><?= icon('upload') ?> Meine Protokolle <span class="count"><?= count($uploads) ?></span></a>
    <a href="<?= e(url('account', ['tab' => 'profile'])) ?>" class="<?= $tab === 'profile' ? 'active' : '' ?>"><?= icon('user') ?> Konto</a>
  </nav>

  <?php if ($tab === 'access'): ?>
    <?php if (!$purchases): ?>
      <div class="card empty-state">
        <div class="empty-icon"><?= icon('lock') ?></div>
        <h3>Noch keine Freischaltungen</h3>
        <p>Wähle eine Prüfung und deine Prüfer aus, um ihre Protokolle freizuschalten.</p>
        <a class="btn btn-primary" href="<?= e(base_url()) ?>#pruefungen">Prüfungen ansehen</a>
      </div>
    <?php endif; ?>
    <?php foreach ($purchases as $p): ?>
      <?php
        $exs = DB::all(
            "SELECT e.*, (SELECT COUNT(*) FROM protocols pr WHERE pr.examiner_id = e.id AND pr.status = 'published') AS protocol_count
             FROM purchase_examiners pe JOIN examiners e ON e.id = pe.examiner_id WHERE pe.purchase_id = ? ORDER BY e.name",
            [$p['id']]
        );
        $free = max(1, (int)$p['max_examiners']) - count($exs);
        $expired = $p['expires_at'] && strtotime($p['expires_at']) < time();
        $active = $p['status'] === 'paid' && !$expired;
      ?>
      <div class="purchase-card">
        <div class="purchase-head">
          <span class="exam-short" style="--exam-color: <?= e($p['color']) ?>"><?= e($p['short_name'] ?: mb_substr($p['exam_name'], 0, 2)) ?></span>
          <div class="title">
            <strong><?= e($p['exam_name']) ?></strong>
            <span>Freigeschaltet am <?= e(fmt_date($p['paid_at'])) ?> · <?= e(money($p['amount_cents'], $p['currency'])) ?><?= $p['provider'] === 'simulation' ? ' · Testzahlung' : '' ?></span>
          </div>
          <?php if ($p['status'] === 'revoked'): ?>
            <?= badge('Zugang entzogen', 'danger') ?>
          <?php elseif ($expired): ?>
            <?= badge('Abgelaufen', 'neutral') ?>
          <?php else: ?>
            <?= badge('Aktiv', 'success') ?>
          <?php endif; ?>
        </div>
        <div class="purchase-body">
          <?php foreach ($exs as $ex): ?>
            <?php if ($active): ?>
              <a class="chip" href="<?= e(url('examiner', ['id' => $ex['id']])) ?>"><?= icon('unlock') ?> <?= e($ex['name']) ?> <span class="muted">(<?= (int)$ex['protocol_count'] ?>)</span></a>
            <?php else: ?>
              <span class="chip"><?= icon('lock') ?> <?= e($ex['name']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($active && $free > 0): ?>
            <a class="chip" href="<?= e(url('purchase', ['id' => $p['id']])) ?>" style="border-style:dashed"><?= icon('plus') ?> <?= e(plural($free, 'Prüfer', 'Prüfer')) ?> kostenlos nachtragen</a>
          <?php endif; ?>
        </div>
        <div class="purchase-foot">
          <span>
            <?php if ($p['refund_status'] === 'succeeded'): ?>
              <?= icon('check-circle') ?> <?= e(money($p['refund_cents'], $p['currency'])) ?> erstattet am <?= e(fmt_date($p['refunded_at'])) ?> – danke für dein Protokoll!
            <?php elseif (in_array($p['refund_status'], ['processing', 'pending', 'manual'], true)): ?>
              <?= icon('clock') ?> Erstattung über <?= e(money($p['refund_cents'], $p['currency'])) ?> ist in Bearbeitung.
            <?php elseif ($p['refund_status'] === 'failed'): ?>
              <?= icon('alert') ?> Bei deiner Erstattung gab es ein Problem – die Fachschaft kümmert sich darum.
            <?php elseif ($refundOn && $p['status'] === 'paid' && (int)$p['amount_cents'] > 0): ?>
              <?= icon('refund') ?> Nach der Prüfung Protokoll hochladen und <?= e(money(min(Payments::refundFor(DB::one('SELECT * FROM exams WHERE id = ?', [$p['exam_id']]) ?? []), (int)$p['amount_cents']), $p['currency'])) ?> zurückbekommen.
            <?php endif; ?>
            <?php if ($p['expires_at'] && !$expired): ?> · Gültig bis <?= e(fmt_date($p['expires_at'])) ?><?php endif; ?>
          </span>
          <?php if ($active && $p['refund_status'] === 'none' && $refundOn): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('upload', ['exam' => $p['exam_id']])) ?>"><?= icon('upload') ?> Protokoll hochladen</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($otherPurchases): ?>
      <details class="card mt-3">
        <summary>Offene oder abgebrochene Zahlungen (<?= count($otherPurchases) ?>)</summary>
        <div class="table-wrap">
          <table class="table table-compact">
            <thead><tr><th>Datum</th><th>Prüfung</th><th class="num">Betrag</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($otherPurchases as $op): ?>
                <tr><td><?= e(fmt_datetime($op['created_at'])) ?></td><td><?= e($op['exam_name']) ?></td><td class="num"><?= e(money($op['amount_cents'], $op['currency'])) ?></td><td><?= Payments::statusBadge($op['status']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>

  <?php elseif ($tab === 'uploads'): ?>
    <div class="flex-between mb-2">
      <p class="muted mb-0">Alle Protokolle, die du hochgeladen hast.</p>
      <a class="btn btn-primary" href="<?= e(url('upload')) ?>"><?= icon('upload') ?> Neues Protokoll</a>
    </div>
    <?php if (!$uploads): ?>
      <div class="card empty-state">
        <div class="empty-icon"><?= icon('upload') ?></div>
        <h3>Noch keine Protokolle</h3>
        <p>Nach deiner Prüfung kannst du hier dein Gedächtnisprotokoll hochladen<?= $refundOn ? ' und bekommst dafür Geld zurück' : '' ?>.</p>
      </div>
    <?php else: ?>
      <div class="card card-flush">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Protokoll</th><th>Prüfung</th><th>Hochgeladen</th><th>Status</th><th>Erstattung</th></tr></thead>
            <tbody>
              <?php foreach ($uploads as $u): ?>
                <tr>
                  <td><a href="<?= e(url('protocol', ['id' => $u['id']])) ?>"><strong><?= e($u['title']) ?></strong></a><span class="table-sub"><?= e($u['examiner_name']) ?></span></td>
                  <td><?= e($u['exam_name']) ?></td>
                  <td class="nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
                  <td><?= protocol_status_badge($u['status']) ?><?php if ($u['status'] === 'rejected' && $u['reject_reason']): ?><span class="table-sub"><?= e($u['reject_reason']) ?></span><?php endif; ?></td>
                  <td><?= $u['refund_status'] ? Payments::refundBadge($u['refund_status']) . ($u['refund_status'] !== 'none' ? '<span class="table-sub">' . e(money($u['refund_cents'])) . '</span>' : '') : '<span class="muted">–</span>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="grid grid-2">
      <div class="card">
        <h3>Persönliche Daten</h3>
        <form method="post" action="<?= e(url('account', ['tab' => 'profile'])) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <?= field_text('name', 'Name', $user['name'], ['required' => true]) ?>
          <?= field_text('email_display', 'E-Mail-Adresse', $user['email'], ['attrs' => ['disabled' => true], 'help' => 'Die E-Mail-Adresse kann nur von der Fachschaft geändert werden.']) ?>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </form>
      </div>
      <div class="card">
        <h3>Passwort ändern</h3>
        <form method="post" action="<?= e(url('account', ['tab' => 'profile'])) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <?= field_text('current', 'Aktuelles Passwort', '', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
          <?= field_text('new', 'Neues Passwort', '', ['type' => 'password', 'required' => true, 'help' => 'Mindestens 8 Zeichen.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8]]) ?>
          <?= field_text('new2', 'Neues Passwort wiederholen', '', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
          <button type="submit" class="btn btn-primary">Passwort ändern</button>
        </form>
      </div>
    </div>
    <div class="card danger-zone mt-3">
      <h3>Konto löschen</h3>
      <p class="muted small">Dein Konto wird gelöscht und deine persönlichen Daten anonymisiert. Bereits hochgeladene Protokolle bleiben anonym erhalten, Buchungsdaten werden wegen gesetzlicher Aufbewahrungspflichten aufbewahrt. Danach hast du keinen Zugriff mehr auf deine Freischaltungen.</p>
      <form method="post" action="<?= e(url('account', ['tab' => 'profile'])) ?>" data-confirm="Konto wirklich unwiderruflich löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <div class="form-row">
          <?= field_text('password', 'Passwort zur Bestätigung', '', ['type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
        </div>
        <label class="checkbox"><input type="checkbox" name="confirm" value="1" required><span>Ja, ich möchte mein Konto endgültig löschen.</span></label>
        <button type="submit" class="btn btn-outline-danger"><?= icon('trash') ?> Konto löschen</button>
      </form>
    </div>
  <?php endif; ?>
</div>
