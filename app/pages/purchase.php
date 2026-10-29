<?php
/** Bestehenden Zugang um weitere Prüfer ergänzen (kostenlos bis zum Maximum). */
$user = Auth::user();
$p = Payments::purchaseWithDetails(is_post() ? post_int('id') : get_int('id'));
if (!$p || (int)$p['user_id'] !== (int)$user['id'] || $p['status'] !== 'paid') {
    abort(404);
}
$max = Payments::maxExaminers($p['exam']);
$currentIds = array_map(fn($e) => (int)$e['id'], $p['examiners']);
$free = $max - count($currentIds);
$owned = Payments::accessibleExaminerIds((int)$user['id']);
$candidates = array_filter(Protocols::examinersForExam((int)$p['exam_id']), fn($e) => !in_array((int)$e['id'], $owned, true));
View::$title = 'Prüfer nachtragen';
View::$active = 'account';

if (is_post()) {
    if ($free <= 0) {
        flash('error', 'Für diesen Zugang sind bereits alle ' . $max . ' Prüfer ausgewählt.');
    } else {
        $ids = array_slice(array_values(array_unique(array_map('intval', post_array('examiners')))), 0, $free);
        $added = $ids ? Payments::addExaminers((int)$p['id'], $ids) : 0;
        if ($added > 0) {
            flash('success', plural($added, 'Prüfer wurde', 'Prüfer wurden') . ' freigeschaltet.');
            redirect('account');
        }
        flash('error', 'Bitte wähle mindestens einen Prüfer aus.');
    }
    redirect('purchase', ['id' => $p['id']]);
}
$groups = Protocols::groupBySubject($candidates);
?>
<div class="container">
  <div class="page-head">
    <div class="breadcrumbs"><a href="<?= e(url('account')) ?>">Mein Bereich</a><span class="sep">/</span><span>Prüfer nachtragen</span></div>
    <h1>Prüfer nachtragen</h1>
    <p class="lead">Dein Zugang für <strong><?= e($p['exam']['name']) ?></strong> umfasst bis zu <?= $max ?> Prüfer. Du kannst noch <strong><?= e(plural(max(0, $free), 'weiteren Prüfer', 'weitere Prüfer')) ?></strong> kostenlos hinzufügen.</p>
  </div>
  <div class="card mb-2">
    <strong>Bereits freigeschaltet:</strong>
    <div class="purchase-body" style="padding:10px 0 0">
      <?php foreach ($p['examiners'] as $ex): ?><span class="chip"><?= icon('unlock') ?> <?= e($ex['name']) ?></span><?php endforeach; ?>
    </div>
  </div>
  <?php if ($free <= 0): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>Alle Plätze dieses Zugangs sind belegt. Für weitere Prüfer kannst du einen neuen Zugang freischalten.</div></div>
  <?php elseif (!$candidates): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>Aktuell gibt es keine weiteren Prüfer für diese Prüfung. Schau später wieder vorbei – neue Prüfer kommen mit jedem hochgeladenen Protokoll dazu.</div></div>
  <?php else: ?>
    <form method="post" action="<?= e(url('purchase')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <div class="toolbar">
        <div class="input-prefix"><?= icon('search') ?><input class="input" type="search" placeholder="Prüfer suchen …" data-filter="#pick-area" aria-label="Prüfer suchen"></div>
        <span class="counter"><?= icon('users') ?> <span data-pick-count>0 / <?= $free ?></span></span>
      </div>
      <div id="pick-area" data-picker data-max="<?= $free ?>">
        <?php foreach ($groups as $subject => $list): ?>
          <div class="subject-group" data-filter-group>
            <h3 class="subject-title"><?= e($subject) ?></h3>
            <div class="pick-list">
              <?php foreach ($list as $ex): ?>
                <label class="pick" data-filter-item data-filter-text="<?= e($ex['name'] . ' ' . $ex['subject']) ?>">
                  <input type="checkbox" name="examiners[]" value="<?= (int)$ex['id'] ?>" data-pick data-name="<?= e($ex['name']) ?>">
                  <span class="info"><span class="name"><?= e($ex['name']) ?></span><span class="meta"><?= e(plural((int)$ex['protocol_count'], 'Protokoll', 'Protokolle')) ?></span></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="form-actions mt-3">
        <button type="submit" class="btn btn-primary" data-pick-submit disabled><?= icon('unlock') ?> Ausgewählte Prüfer freischalten</button>
        <a class="btn btn-secondary" href="<?= e(url('account')) ?>">Abbrechen</a>
      </div>
    </form>
  <?php endif; ?>
</div>
