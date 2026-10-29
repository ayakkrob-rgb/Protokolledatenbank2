<?php
/** Prüfung mit allen Prüferinnen und Prüfern. */
$user = Auth::user();
$isStaff = Auth::isStaff();
$id = get_int('id');
$exam = $id > 0
    ? DB::one('SELECT * FROM exams WHERE id = ?', [$id])
    : DB::one('SELECT * FROM exams WHERE slug = ?', [get_str('slug')]);
if (!$exam || (!(int)$exam['is_active'] && !$isStaff)) {
    abort(404);
}
View::$title = $exam['name'];
View::$active = 'exams';

$examiners = Protocols::examinersForExam((int)$exam['id']);
$access = $user ? Payments::accessibleExaminerIds((int)$user['id']) : [];
$groups = Protocols::groupBySubject($examiners);
$price = Payments::priceFor($exam);
$refund = Payments::refundFor($exam);
$max = Payments::maxExaminers($exam);
$refundOn = Settings::bool('refund_enabled') && $refund > 0;
$totalProtocols = array_sum(array_map('intval', array_column($examiners, 'protocol_count')));
$unlockedHere = count(array_filter($examiners, fn($e) => in_array((int)$e['id'], $access, true)));
?>
<div class="container">
  <div class="page-head">
    <div class="breadcrumbs"><a href="<?= e(base_url()) ?>">Start</a><span class="sep">/</span><span><?= e($exam['name']) ?></span></div>
    <div class="exam-header" style="--exam-color: <?= e($exam['color']) ?>">
      <span class="exam-short"><?= e($exam['short_name'] ?: mb_substr($exam['name'], 0, 2)) ?></span>
      <div>
        <h1><?= e($exam['name']) ?></h1>
        <?php if (trim((string)$exam['description']) !== ''): ?><p class="lead"><?= e($exam['description']) ?></p><?php endif; ?>
        <?php if (!(int)$exam['is_active']): ?><p><?= badge('Inaktiv – nur für das Team sichtbar', 'warning') ?></p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="layout-sidebar mt-3">
    <div>
      <div class="toolbar">
        <div class="input-prefix"><?= icon('search') ?><input class="input" type="search" placeholder="Prüfer oder Fach suchen …" data-filter="#examiner-list" aria-label="Prüfer suchen"></div>
        <span class="muted small"><?= e(plural(count($examiners), 'Prüfer', 'Prüfer')) ?> · <?= e(plural($totalProtocols, 'Protokoll', 'Protokolle')) ?></span>
      </div>

      <div id="examiner-list">
        <?php if (!$examiners): ?>
          <div class="card empty-state">
            <div class="empty-icon"><?= icon('users') ?></div>
            <h3>Noch keine Prüfer</h3>
            <p>Für diese Prüfung gibt es noch keine Protokolle. Lade nach deiner Prüfung das erste hoch!</p>
            <a class="btn btn-primary" href="<?= e(url('upload', ['exam' => $exam['id']])) ?>"><?= icon('upload') ?> Protokoll hochladen</a>
          </div>
        <?php endif; ?>
        <?php foreach ($groups as $subject => $list): ?>
          <div class="subject-group" data-filter-group>
            <h3 class="subject-title"><?= e($subject) ?></h3>
            <div class="examiner-grid">
              <?php foreach ($list as $ex): ?>
                <?php $unlocked = $isStaff || in_array((int)$ex['id'], $access, true); ?>
                <a class="examiner-card <?= $unlocked ? 'unlocked' : '' ?>" href="<?= e(url('examiner', ['id' => $ex['id']])) ?>" data-filter-item data-filter-text="<?= e($ex['name'] . ' ' . $ex['subject']) ?>">
                  <span class="avatar"><?= e(initials($ex['name'])) ?></span>
                  <span class="info">
                    <span class="name"><?= e($ex['name']) ?></span>
                    <span class="meta"><?= e(plural((int)$ex['protocol_count'], 'Protokoll', 'Protokolle')) ?><?= $ex['last_date'] ? ' · zuletzt ' . e(fmt_month($ex['last_date'])) : '' ?></span>
                  </span>
                  <span class="state" title="<?= $unlocked ? 'Freigeschaltet' : 'Gesperrt' ?>"><?= icon($unlocked ? 'unlock' : 'lock') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <p class="muted hidden" data-filter-empty>Keine passenden Prüfer gefunden.</p>
      </div>
    </div>

    <aside class="sticky">
      <div class="card">
        <div class="price-tag"><?= e(money_short($price)) ?><small>einmalig für bis zu <?= $max ?> Prüfer dieser Prüfung</small></div>
        <ul class="summary-list mt-2">
          <li><?= icon('check') ?> Alle Protokolle online lesen</li>
          <li><?= icon('check') ?> Download einzeln oder als ZIP</li>
          <li><?= icon('check') ?> Neue Protokolle deiner Prüfer inklusive</li>
        </ul>
        <?php if ($refundOn): ?>
          <div class="refund-hint"><?= icon('refund') ?><div><strong><?= e(money($refund)) ?> zurück</strong>, wenn du nach deiner Prüfung dein eigenes Gedächtnisprotokoll hochlädst.</div></div>
        <?php endif; ?>
        <?php if ($examiners): ?>
          <a class="btn btn-primary btn-block mt-2" href="<?= e(url('buy', ['exam' => $exam['id']])) ?>"><?= icon('unlock') ?> Prüfer auswählen &amp; freischalten</a>
        <?php endif; ?>
        <?php if ($unlockedHere > 0 && !$isStaff): ?>
          <p class="small muted mt-1 mb-0">Du hast bereits <?= e(plural($unlockedHere, 'Prüfer', 'Prüfer')) ?> dieser Prüfung freigeschaltet.</p>
        <?php elseif (!$user): ?>
          <p class="small muted mt-1 mb-0 text-center">Schon registriert? <a href="<?= e(url('login')) ?>">Anmelden</a></p>
        <?php endif; ?>
      </div>
      <div class="card card-muted">
        <h3>Prüfung schon geschafft?</h3>
        <p class="small muted">Teile dein Gedächtnisprotokoll. Fehlt dein Prüfer in der Liste, legst du ihn beim Hochladen einfach neu an.</p>
        <a class="btn btn-secondary btn-block" href="<?= e(url('upload', ['exam' => $exam['id']])) ?>"><?= icon('upload') ?> Protokoll hochladen</a>
      </div>
    </aside>
  </div>
</div>
