<?php
/** Kauf: Prüfer auswählen und zur Zahlung weiterleiten. */
$user = Auth::user();
$examId = is_post() ? post_int('exam') : get_int('exam');
$exam = DB::one('SELECT * FROM exams WHERE id = ? AND is_active = 1', [$examId]);
if (!$exam) {
    abort(404);
}
View::$title = 'Freischalten – ' . $exam['name'];
View::$active = 'exams';

$examiners = Protocols::examinersForExam((int)$exam['id']);
$owned = Payments::accessibleExaminerIds((int)$user['id']);
$max = Payments::maxExaminers($exam);
$price = Payments::priceFor($exam);
$refund = Payments::refundFor($exam);
$refundOn = Settings::bool('refund_enabled') && $refund > 0;
$errors = [];
$selectedIds = [];
if ($pre = get_int('examiner')) {
    $selectedIds[] = $pre;
}

// Bestehende Käufe mit freien Plätzen → kostenlos nachtragen statt neu kaufen
$openPurchases = array_filter(DB::all(
    "SELECT p.*, (SELECT COUNT(*) FROM purchase_examiners pe WHERE pe.purchase_id = p.id) AS examiner_count
     FROM purchases p WHERE p.user_id = ? AND p.exam_id = ? AND p.status = 'paid' AND (p.expires_at IS NULL OR p.expires_at > ?)",
    [$user['id'], $exam['id'], now()]
), fn($p) => (int)$p['examiner_count'] < $max);

if (is_post()) {
    $validIds = array_map(fn($e) => (int)$e['id'], $examiners);
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', post_array('examiners')))));
    $selectedIds = array_values(array_filter($selectedIds, fn($i) => in_array($i, $validIds, true) && !in_array($i, $owned, true)));
    if (!$selectedIds) {
        $errors[] = 'Bitte wähle mindestens einen Prüfer aus.';
    } elseif (count($selectedIds) > $max) {
        $errors[] = 'Du kannst höchstens ' . $max . ' Prüfer auswählen.';
    }
    if (!post_bool('consent')) {
        $errors[] = 'Bitte bestätige die Nutzungsbedingungen und den Hinweis zum Widerrufsrecht.';
    }
    if (!Payments::available()) {
        $errors[] = 'Zahlungen sind gerade nicht möglich. Bitte versuche es später noch einmal.';
    }
    if (!$errors) {
        $pid = Payments::createPurchase($user, $exam, $selectedIds);
        try {
            $target = Payments::checkoutUrl($pid);
        } catch (Throwable $e) {
            app_log('error', 'Checkout für Kauf #' . $pid . ' fehlgeschlagen: ' . $e->getMessage());
            Payments::markFailed($pid);
            $errors[] = 'Die Zahlung konnte nicht gestartet werden.' . (Auth::isStaff() ? ' (' . $e->getMessage() . ')' : ' Bitte versuche es später erneut.');
            $target = null;
        }
        if ($target) {
            redirect_url($target);
        }
    }
}
$groups = Protocols::groupBySubject($examiners);
$available = array_filter($examiners, fn($e) => !in_array((int)$e['id'], $owned, true));
?>
<div class="container">
  <div class="page-head">
    <div class="breadcrumbs">
      <a href="<?= e(base_url()) ?>">Start</a><span class="sep">/</span>
      <a href="<?= e(url('exam', ['id' => $exam['id']])) ?>"><?= e($exam['name']) ?></a><span class="sep">/</span>
      <span>Freischalten</span>
    </div>
    <h1>Prüfer freischalten</h1>
    <p class="lead">Wähle bis zu <?= $max ?> Prüferinnen und Prüfer der Prüfung <strong><?= e($exam['name']) ?></strong>. Du zahlst einmalig <?= e(money($price)) ?> und kannst danach alle ihre Protokolle lesen und herunterladen.</p>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div><?= e($err) ?></div></div>
  <?php endforeach; ?>

  <?php foreach ($openPurchases as $op): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>
      Du hast bereits einen Zugang für diese Prüfung mit <?= (int)$op['examiner_count'] ?> von <?= $max ?> Prüfern. Weitere Prüfer kannst du dort <strong>kostenlos nachtragen</strong>.
      <a href="<?= e(url('purchase', ['id' => $op['id']])) ?>">Prüfer nachtragen</a>
    </div></div>
  <?php endforeach; ?>

  <?php if (!$available): ?>
    <div class="card empty-state">
      <div class="empty-icon"><?= icon('check-circle') ?></div>
      <h3>Alles freigeschaltet</h3>
      <p>Du hast bereits Zugriff auf alle Prüfer dieser Prüfung.</p>
      <a class="btn btn-primary" href="<?= e(url('exam', ['id' => $exam['id']])) ?>">Zu den Prüfern</a>
    </div>
  <?php else: ?>
  <form method="post" action="<?= e(url('buy')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="exam" value="<?= (int)$exam['id'] ?>">
    <div class="layout-sidebar">
      <div>
        <div class="toolbar">
          <div class="input-prefix"><?= icon('search') ?><input class="input" type="search" placeholder="Prüfer suchen …" data-filter="#pick-area" aria-label="Prüfer suchen"></div>
          <span class="counter"><?= icon('users') ?> <span data-pick-count><?= count($selectedIds) ?> / <?= $max ?></span></span>
        </div>
        <div id="pick-area" data-picker data-max="<?= $max ?>">
          <?php foreach ($groups as $subject => $list): ?>
            <div class="subject-group" data-filter-group>
              <h3 class="subject-title"><?= e($subject) ?></h3>
              <div class="pick-list">
                <?php foreach ($list as $ex): ?>
                  <?php $isOwned = in_array((int)$ex['id'], $owned, true); ?>
                  <?php if ($isOwned): ?>
                    <div class="pick owned" data-filter-item data-filter-text="<?= e($ex['name'] . ' ' . $ex['subject']) ?>">
                      <?= icon('unlock') ?>
                      <span class="info"><span class="name"><?= e($ex['name']) ?></span><span class="meta">Bereits freigeschaltet</span></span>
                    </div>
                  <?php else: ?>
                    <label class="pick" data-filter-item data-filter-text="<?= e($ex['name'] . ' ' . $ex['subject']) ?>">
                      <input type="checkbox" name="examiners[]" value="<?= (int)$ex['id'] ?>" data-pick data-name="<?= e($ex['name']) ?>"<?= checked(in_array((int)$ex['id'], $selectedIds, true)) ?>>
                      <span class="info">
                        <span class="name"><?= e($ex['name']) ?></span>
                        <span class="meta"><?= (int)$ex['protocol_count'] > 0 ? e(plural((int)$ex['protocol_count'], 'Protokoll', 'Protokolle')) . ($ex['last_date'] ? ' · zuletzt ' . e(fmt_month($ex['last_date'])) : '') : 'Noch keine Protokolle' ?></span>
                      </span>
                    </label>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <p class="muted hidden" data-filter-empty>Keine passenden Prüfer gefunden.</p>
        </div>
        <p class="small muted mt-2"><?= icon('info') ?> Dein Prüfer fehlt? Dann gibt es zu ihm noch keine Protokolle. Du kannst weniger als <?= $max ?> Prüfer wählen und später kostenlos weitere nachtragen.</p>
      </div>

      <aside class="sticky">
        <div class="card summary-card">
          <h3>Deine Auswahl</h3>
          <ul class="summary-list" data-pick-summary>
            <li class="muted">Noch keine Prüfer ausgewählt</li>
          </ul>
          <div class="summary-line"><span>Prüfung</span><strong><?= e($exam['short_name'] ?: $exam['name']) ?></strong></div>
          <div class="summary-line"><span>Prüfer</span><span data-pick-count><?= count($selectedIds) ?> / <?= $max ?></span></div>
          <div class="summary-line summary-total"><span>Gesamt</span><span><?= e(money($price)) ?></span></div>
          <?php if ($refundOn): ?>
            <div class="refund-hint mt-1"><?= icon('refund') ?><div>Lade nach der Prüfung dein Protokoll hoch und erhalte <strong><?= e(money($refund)) ?></strong> zurück.</div></div>
          <?php endif; ?>
          <label class="checkbox mt-2">
            <input type="checkbox" name="consent" value="1" required>
            <span>Ich akzeptiere die <a href="<?= e(url('page', ['slug' => 'nutzungsbedingungen'])) ?>" target="_blank">Nutzungsbedingungen</a> und stimme zu, dass der Zugang sofort freigeschaltet wird. Mir ist bekannt, dass ich dadurch mein Widerrufsrecht verliere.</span>
          </label>
          <button type="submit" class="btn btn-primary btn-block btn-lg" data-pick-submit<?= $selectedIds ? '' : ' disabled' ?>>
            <?php if (Payments::isSimulation()): ?>
              <?= icon('zap') ?> Weiter zur Testzahlung
            <?php else: ?>
              <?= icon('lock') ?> Weiter zur Zahlung
            <?php endif; ?>
          </button>
          <p class="small muted mt-1 mb-0 text-center">
            <?php if (Payments::isSimulation()): ?>
              Testmodus: Es wird kein echtes Geld abgebucht.
            <?php else: ?>
              Sichere Zahlung über Stripe (z. B. Karte, Apple Pay, Google Pay, SEPA).
            <?php endif; ?>
          </p>
        </div>
      </aside>
    </div>
  </form>
  <?php endif; ?>
</div>
