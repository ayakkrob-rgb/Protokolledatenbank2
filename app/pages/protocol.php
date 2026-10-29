<?php
/** Einzelnes Protokoll lesen (nur mit Freischaltung). */
$user = Auth::user();
$isStaff = Auth::isStaff();
$p = DB::one(
    'SELECT p.*, e.name AS examiner_name, e.subject, x.id AS exam_id, x.name AS exam_name, x.short_name
     FROM protocols p JOIN examiners e ON e.id = p.examiner_id JOIN exams x ON x.id = e.exam_id WHERE p.id = ?',
    [get_int('id')]
);
if (!$p) {
    abort(404);
}
if (!Payments::canViewProtocol($user, $p)) {
    if ($p['status'] === 'published') {
        flash('info', 'Bitte schalte diesen Prüfer zuerst frei, um das Protokoll zu lesen.');
        redirect('examiner', ['id' => $p['examiner_id']]);
    }
    abort(404);
}
$isOwn = (int)$p['user_id'] === (int)$user['id'];
if (!$isOwn && !$isStaff) {
    DB::q('UPDATE protocols SET view_count = view_count + 1 WHERE id = ?', [$p['id']]);
    DB::insert('download_log', ['user_id' => $user['id'], 'protocol_id' => $p['id'], 'file_id' => null, 'kind' => 'view', 'created_at' => now()]);
}
View::$title = $p['title'];
View::$active = 'exams';

$files = DB::all('SELECT * FROM protocol_files WHERE protocol_id = ? ORDER BY id', [$p['id']]);
$others = DB::all(
    "SELECT id, title, exam_date FROM protocols WHERE examiner_id = ? AND status = 'published' AND id <> ?
     ORDER BY COALESCE(exam_date, DATE(created_at)) DESC LIMIT 8",
    [$p['examiner_id'], $p['id']]
);
$watermark = Protocols::watermarkStyle($user);
?>
<div class="container">
  <div class="page-head">
    <div class="breadcrumbs">
      <a href="<?= e(base_url()) ?>">Start</a><span class="sep">/</span>
      <a href="<?= e(url('exam', ['id' => $p['exam_id']])) ?>"><?= e($p['exam_name']) ?></a><span class="sep">/</span>
      <a href="<?= e(url('examiner', ['id' => $p['examiner_id']])) ?>"><?= e($p['examiner_name']) ?></a><span class="sep">/</span>
      <span>Protokoll</span>
    </div>
    <div class="flex-between">
      <h1 class="mb-0"><?= e($p['title']) ?></h1>
      <?php if ($isStaff): ?>
        <a class="btn btn-ghost" href="<?= e(url('admin/protocol', ['id' => $p['id']])) ?>"><?= icon('edit') ?> Bearbeiten</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($p['status'] === 'pending'): ?>
    <div class="alert alert-warning"><?= icon('clock') ?><div>Dieses Protokoll wartet noch auf die Freigabe durch die Fachschaft und ist für andere noch nicht sichtbar.</div></div>
  <?php elseif ($p['status'] === 'rejected'): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div>Dieses Protokoll wurde nicht freigegeben.<?= $p['reject_reason'] ? ' Begründung: ' . e($p['reject_reason']) : '' ?></div></div>
  <?php endif; ?>

  <div class="layout-sidebar mt-2">
    <div>
      <?php if (trim((string)$p['content']) !== ''): ?>
        <article class="card protocol-content" style="<?= e($watermark) ?>">
          <div class="prose"><?= render_markdown($p['content']) ?></div>
        </article>
      <?php endif; ?>

      <?php if ($files): ?>
        <div class="<?= trim((string)$p['content']) !== '' ? 'mt-3' : '' ?>">
          <h2 style="font-size:1.1rem">Dateien</h2>
          <ul class="file-list">
            <?php foreach ($files as $i => $f): ?>
              <?php
                $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                $inline = Protocols::isInline($f['original_name']);
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
              ?>
              <li class="file-item">
                <div class="file-row">
                  <?= icon('file') ?>
                  <span class="f-name" title="<?= e($f['original_name']) ?>"><?= e($f['original_name']) ?></span>
                  <span class="f-size"><?= e(human_filesize($f['size'])) ?></span>
                  <?php if ($inline): ?>
                    <button type="button" class="btn btn-secondary btn-sm" data-viewer="<?= e(url('file', ['id' => $f['id'], 'inline' => 1])) ?>" data-type="<?= $isImage ? 'image' : 'frame' ?>" data-label-open="Ansehen">Ansehen</button>
                  <?php endif; ?>
                  <a class="btn btn-primary btn-sm" href="<?= e(url('file', ['id' => $f['id']])) ?>"><?= icon('download') ?> Download</a>
                </div>
                <?php if ($inline): ?><div class="file-viewer hidden"></div><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (trim((string)$p['content']) === '' && !$files): ?>
        <div class="card empty-state"><p>Dieses Protokoll enthält keinen Inhalt.</p></div>
      <?php endif; ?>

      <p class="small muted mt-2"><?= icon('shield') ?> Die Protokolle sind nur für deine persönliche Prüfungsvorbereitung bestimmt. Bitte gib sie nicht weiter.</p>
    </div>

    <aside class="sticky">
      <div class="card">
        <dl class="meta-list">
          <dt>Prüfer</dt><dd><a href="<?= e(url('examiner', ['id' => $p['examiner_id']])) ?>"><?= e($p['examiner_name']) ?></a></dd>
          <?php if ($p['subject'] !== ''): ?><dt>Fach</dt><dd><?= e($p['subject']) ?></dd><?php endif; ?>
          <dt>Prüfung</dt><dd><?= e($p['exam_name']) ?></dd>
          <dt>Prüfungsdatum</dt><dd><?= e(fmt_date($p['exam_date'], 'unbekannt')) ?></dd>
          <?php if ($p['grade']): ?><dt>Note</dt><dd><?= e($p['grade']) ?></dd><?php endif; ?>
          <dt>Eingestellt</dt><dd><?= e(fmt_date($p['published_at'] ?: $p['created_at'])) ?></dd>
        </dl>
      </div>
      <?php if ($others): ?>
        <div class="card">
          <h3 style="font-size:1rem">Weitere Protokolle</h3>
          <ul class="summary-list mb-0">
            <?php foreach ($others as $o): ?>
              <li><?= icon('file-text') ?><a href="<?= e(url('protocol', ['id' => $o['id']])) ?>"><?= e($o['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <a class="btn btn-secondary btn-block mt-2" href="<?= e(url('examiner', ['id' => $p['examiner_id']])) ?>"><?= icon('arrow-left') ?> Zurück zum Prüfer</a>
    </aside>
  </div>
</div>
