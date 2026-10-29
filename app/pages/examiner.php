<?php
/** Prüfer mit Liste der Protokolle (gesperrt oder freigeschaltet). */
$user = Auth::user();
$isStaff = Auth::isStaff();
$examiner = DB::one(
    'SELECT e.*, x.name AS exam_name, x.short_name, x.color, x.is_active AS exam_active
     FROM examiners e JOIN exams x ON x.id = e.exam_id WHERE e.id = ?',
    [get_int('id')]
);
if (!$examiner) {
    abort(404);
}
$hasAccess = Payments::hasExaminerAccess($user, (int)$examiner['id']);
if ((!(int)$examiner['is_active'] || !(int)$examiner['exam_active']) && !$isStaff && !$hasAccess) {
    abort(404);
}
View::$title = $examiner['name'] . ' – ' . $examiner['exam_name'];
View::$active = 'exams';

$protocols = DB::all(
    "SELECT p.*, (SELECT COUNT(*) FROM protocol_files f WHERE f.protocol_id = p.id) AS file_count
     FROM protocols p WHERE p.examiner_id = ? AND p.status = 'published'
     ORDER BY COALESCE(p.exam_date, DATE(p.created_at)) DESC, p.id DESC",
    [$examiner['id']]
);
$pendingCount = $isStaff ? (int)DB::val("SELECT COUNT(*) FROM protocols WHERE examiner_id = ? AND status = 'pending'", [$examiner['id']]) : 0;
$exam = DB::one('SELECT * FROM exams WHERE id = ?', [$examiner['exam_id']]);
?>
<div class="container">
  <div class="page-head">
    <div class="breadcrumbs">
      <a href="<?= e(base_url()) ?>">Start</a><span class="sep">/</span>
      <a href="<?= e(url('exam', ['id' => $examiner['exam_id']])) ?>"><?= e($examiner['exam_name']) ?></a><span class="sep">/</span>
      <span><?= e($examiner['name']) ?></span>
    </div>
    <div class="flex-between">
      <div class="flex">
        <span class="avatar" style="width:56px;height:56px;font-size:1.1rem"><?= e(initials($examiner['name'])) ?></span>
        <div>
          <h1 class="mb-0"><?= e($examiner['name']) ?></h1>
          <div class="muted"><?= e($examiner['subject'] !== '' ? $examiner['subject'] . ' · ' : '') ?><?= e($examiner['exam_name']) ?></div>
        </div>
      </div>
      <div class="btn-group">
        <?php if ($hasAccess && $protocols): ?>
          <a class="btn btn-secondary" href="<?= e(url('examiner-zip', ['id' => $examiner['id']])) ?>"><?= icon('download') ?> Alle als ZIP</a>
        <?php endif; ?>
        <?php if ($isStaff): ?>
          <a class="btn btn-ghost" href="<?= e(url('admin/examiner', ['id' => $examiner['id']])) ?>"><?= icon('edit') ?> Bearbeiten</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($pendingCount > 0): ?>
    <div class="alert alert-info"><?= icon('info') ?><div><?= $pendingCount ?> Protokoll(e) dieses Prüfers warten auf Freigabe. <a href="<?= e(url('admin/protocols', ['status' => 'pending', 'examiner' => $examiner['id']])) ?>">Zur Moderation</a></div></div>
  <?php endif; ?>

  <?php if (trim((string)$examiner['info']) !== ''): ?>
    <div class="card card-muted mb-2"><div class="prose"><?= render_markdown($examiner['info']) ?></div></div>
  <?php endif; ?>

  <div class="card card-flush mt-2">
    <div class="card-header">
      <h2 class="h3" style="font-size:1.1rem"><?= e(plural(count($protocols), 'Protokoll', 'Protokolle')) ?></h2>
      <?php if ($hasAccess): ?>
        <?= badge('Freigeschaltet', 'success') ?>
      <?php else: ?>
        <?= badge('Gesperrt', 'neutral') ?>
      <?php endif; ?>
    </div>
    <?php if (!$protocols): ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('file-text') ?></div>
        <h3>Noch keine Protokolle</h3>
        <p>Zu diesem Prüfer gibt es noch keine veröffentlichten Protokolle.</p>
        <a class="btn btn-primary" href="<?= e(url('upload', ['exam' => $examiner['exam_id']])) ?>"><?= icon('upload') ?> Erstes Protokoll hochladen</a>
      </div>
    <?php else: ?>
      <ul class="protocol-list">
        <?php foreach ($protocols as $p): ?>
          <li class="protocol-item <?= $hasAccess ? '' : 'locked' ?>">
            <span class="p-icon"><?= icon($hasAccess ? 'file-text' : 'lock') ?></span>
            <div class="p-body">
              <?php if ($hasAccess): ?>
                <a class="p-title" href="<?= e(url('protocol', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a>
              <?php else: ?>
                <span class="p-title"><?= e($p['title']) ?></span>
              <?php endif; ?>
              <div class="p-meta">
                <span><?= icon('calendar') ?> <?= e($p['exam_date'] ? fmt_date($p['exam_date']) : 'Datum unbekannt') ?></span>
                <?php if ($p['grade']): ?><span><?= icon('award') ?> Note: <?= e($p['grade']) ?></span><?php endif; ?>
                <?php if ($p['content']): ?><span><?= icon('book') ?> Text</span><?php endif; ?>
                <?php if ((int)$p['file_count'] > 0): ?><span><?= icon('file') ?> <?= e(plural((int)$p['file_count'], 'Datei', 'Dateien')) ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($hasAccess): ?>
              <a class="btn btn-secondary btn-sm" href="<?= e(url('protocol', ['id' => $p['id']])) ?>">Lesen <?= icon('arrow-right') ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$hasAccess): ?>
        <div class="locked-overlay">
          <div class="empty-icon"><?= icon('lock') ?></div>
          <h3>Protokolle freischalten</h3>
          <p class="muted">Schalte <?= e($examiner['name']) ?> und bis zu <?= Payments::maxExaminers($exam) - 1 ?> weitere Prüfer der Prüfung <?= e($examiner['exam_name']) ?> für <?= e(money_short(Payments::priceFor($exam))) ?> frei.</p>
          <a class="btn btn-primary" href="<?= e(url('buy', ['exam' => $examiner['exam_id'], 'examiner' => $examiner['id']])) ?>"><?= icon('unlock') ?> Jetzt freischalten</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
