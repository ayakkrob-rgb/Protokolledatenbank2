<?php
/** Admin: Protokolle verwalten und moderieren. */
View::$title = 'Protokolle';
View::$active = 'protocols';

if (is_post()) {
    $ids = array_values(array_filter(array_map('intval', post_array('ids'))));
    $bulk = post_str('bulk');
    if (!$ids) {
        flash('error', 'Bitte wähle mindestens ein Protokoll aus.');
        redirect_back('admin/protocols');
    }
    $count = 0;
    $refunds = 0;
    foreach ($ids as $id) {
        if ($bulk === 'publish') {
            $res = Protocols::publish($id);
            if (in_array($res['status'] ?? '', ['succeeded', 'pending', 'manual'], true)) {
                $refunds++;
            }
        } elseif ($bulk === 'reject') {
            Protocols::reject($id, post_str('reason'));
        } elseif ($bulk === 'delete' && Auth::isAdmin()) {
            Protocols::delete($id);
        } else {
            continue;
        }
        $count++;
    }
    $labels = ['publish' => 'veröffentlicht', 'reject' => 'abgelehnt', 'delete' => 'gelöscht'];
    flash('success', plural($count, 'Protokoll', 'Protokolle') . ' ' . ($labels[$bulk] ?? 'bearbeitet') . '.' . ($refunds ? ' ' . plural($refunds, 'Rückerstattung', 'Rückerstattungen') . ' ausgelöst.' : ''));
    redirect_back('admin/protocols');
}

$status = get_str('status');
$examId = get_int('exam');
$examinerId = get_int('examiner');
$source = get_str('source');
$userId = get_int('user');
$q = get_str('q');
$where = ['1=1'];
$params = [];
if (in_array($status, ['published', 'pending', 'rejected'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($examId) {
    $where[] = 'e.exam_id = ?';
    $params[] = $examId;
}
if ($examinerId) {
    $where[] = 'p.examiner_id = ?';
    $params[] = $examinerId;
}
if (in_array($source, ['upload', 'admin', 'import'], true)) {
    $where[] = 'p.source = ?';
    $params[] = $source;
}
if ($userId) {
    $where[] = 'p.user_id = ?';
    $params[] = $userId;
}
if ($q !== '') {
    $where[] = '(p.title LIKE ? OR p.content LIKE ? OR e.name LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$whereSql = implode(' AND ', $where);
$from = 'FROM protocols p JOIN examiners e ON e.id = p.examiner_id JOIN exams x ON x.id = e.exam_id LEFT JOIN users u ON u.id = p.user_id LEFT JOIN purchases pu ON pu.id = p.refund_purchase_id';
$total = (int)DB::val("SELECT COUNT(*) $from WHERE $whereSql", $params);
$pg = paginate($total, 40);
$rows = DB::all(
    "SELECT p.*, e.name AS examiner_name, x.short_name, u.email, pu.refund_status, pu.refund_cents,
        (SELECT COUNT(*) FROM protocol_files f WHERE f.protocol_id = p.id) AS file_count
     $from WHERE $whereSql ORDER BY (p.status = 'pending') DESC, p.created_at DESC
     LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'],
    $params
);
$exams = DB::all('SELECT id, name FROM exams ORDER BY sort_order, name');
$examOptions = ['' => 'Alle Prüfungen'];
foreach ($exams as $x) {
    $examOptions[$x['id']] = $x['name'];
}
$counts = [];
foreach (DB::all('SELECT status, COUNT(*) AS c FROM protocols GROUP BY status') as $c) {
    $counts[$c['status']] = (int)$c['c'];
}
$filterExaminer = $examinerId ? DB::one('SELECT name FROM examiners WHERE id = ?', [$examinerId]) : null;
$filterUser = $userId ? DB::one('SELECT email FROM users WHERE id = ?', [$userId]) : null;
$sourceLabels = ['upload' => 'Upload', 'admin' => 'Admin', 'import' => 'Import'];
?>
<?= admin_head('Protokolle', 'Alle Gedächtnisprotokolle. Hochgeladene Protokolle können hier freigegeben oder abgelehnt werden.', '<a class="btn btn-primary" href="' . e(url('admin/protocol', ['new' => 1, 'examiner' => $examinerId ?: null])) . '">' . icon('plus') . ' Neues Protokoll</a>') ?>

<nav class="tabs">
  <a href="<?= e(url_with(['status' => null, 'page' => null])) ?>" class="<?= $status === '' ? 'active' : '' ?>">Alle <span class="count"><?= array_sum($counts) ?></span></a>
  <a href="<?= e(url_with(['status' => 'pending', 'page' => null])) ?>" class="<?= $status === 'pending' ? 'active' : '' ?>">Warten auf Freigabe <span class="count"><?= $counts['pending'] ?? 0 ?></span></a>
  <a href="<?= e(url_with(['status' => 'published', 'page' => null])) ?>" class="<?= $status === 'published' ? 'active' : '' ?>">Veröffentlicht <span class="count"><?= $counts['published'] ?? 0 ?></span></a>
  <a href="<?= e(url_with(['status' => 'rejected', 'page' => null])) ?>" class="<?= $status === 'rejected' ? 'active' : '' ?>">Abgelehnt <span class="count"><?= $counts['rejected'] ?? 0 ?></span></a>
</nav>

<form method="get" action="<?= e(base_url() . 'index.php') ?>" class="filters">
  <input type="hidden" name="p" value="admin/protocols">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <?php if ($examinerId): ?><input type="hidden" name="examiner" value="<?= $examinerId ?>"><?php endif; ?>
  <?php if ($userId): ?><input type="hidden" name="user" value="<?= $userId ?>"><?php endif; ?>
  <div class="field grow"><label>Suche</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Titel, Inhalt oder Prüfer"></div>
  <?= field_select('exam', 'Prüfung', $examOptions, $examId ?: '', ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('source', 'Herkunft', ['' => 'Alle', 'upload' => 'Upload durch Studierende', 'admin' => 'Admin', 'import' => 'Import'], $source, ['attrs' => ['data-autosubmit' => true]]) ?>
  <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Filtern</button></div>
</form>
<?php if ($filterExaminer || $filterUser): ?>
  <p class="small">Gefiltert nach <?= $filterExaminer ? 'Prüfer <strong>' . e($filterExaminer['name']) . '</strong>' : '' ?><?= $filterUser ? ' Nutzer <strong>' . e($filterUser['email']) . '</strong>' : '' ?> · <a href="<?= e(url('admin/protocols', ['status' => $status ?: null])) ?>">Filter entfernen</a></p>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/protocols')) ?>">
  <?= csrf_field() ?>
  <div class="card card-flush">
    <div class="card-header">
      <span class="muted small"><?= e(plural($total, 'Protokoll', 'Protokolle')) ?></span>
      <div class="btn-group">
        <select name="bulk" class="input input-sm" style="width:auto">
          <option value="publish">Freigeben / veröffentlichen</option>
          <option value="reject">Ablehnen</option>
          <?php if (Auth::isAdmin()): ?><option value="delete">Löschen</option><?php endif; ?>
        </select>
        <input type="text" name="reason" class="input input-sm" style="width:200px" placeholder="Begründung (bei Ablehnung)">
        <button type="submit" class="btn btn-secondary btn-sm" data-confirm="Aktion für die ausgewählten Protokolle ausführen?">Ausführen</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:32px"><input type="checkbox" data-check-all aria-label="Alle auswählen"></th><th>Protokoll</th><th>Von</th><th>Prüfungsdatum</th><th class="num">Dateien</th><th class="num">Abrufe</th><th>Status</th><th>Erstattung</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Auswählen"></td>
              <td><a href="<?= e(url('admin/protocol', ['id' => $r['id']])) ?>"><strong><?= e($r['title']) ?></strong></a><span class="table-sub"><?= e($r['short_name'] . ' · ' . $r['examiner_name']) ?></span></td>
              <td class="small"><?= $r['email'] ? '<a href="' . e(url('admin/user', ['id' => $r['user_id']])) . '">' . e($r['email']) . '</a>' : e($sourceLabels[$r['source']] ?? $r['source']) ?><span class="table-sub"><?= e(fmt_date($r['created_at'])) ?></span></td>
              <td class="nowrap"><?= e(fmt_date($r['exam_date'])) ?></td>
              <td class="num"><?= (int)$r['file_count'] ?></td>
              <td class="num"><?= (int)$r['view_count'] ?> / <?= (int)$r['download_count'] ?></td>
              <td><?= protocol_status_badge($r['status']) ?></td>
              <td><?= $r['refund_status'] ? Payments::refundBadge($r['refund_status']) : '<span class="muted">–</span>' ?></td>
              <td class="actions">
                <?php if ($r['status'] === 'pending'): ?>
                  <button type="submit" class="btn btn-success btn-xs" form="publish-<?= (int)$r['id'] ?>"><?= icon('check') ?> Freigeben</button>
                <?php endif; ?>
                <a class="btn btn-secondary btn-xs" href="<?= e(url('admin/protocol', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine Protokolle gefunden.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php foreach ($rows as $r): ?>
  <?php if ($r['status'] === 'pending'): ?>
    <form id="publish-<?= (int)$r['id'] ?>" method="post" action="<?= e(url('admin/protocol')) ?>" class="hidden"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="back" value="list"></form>
  <?php endif; ?>
<?php endforeach; ?>
<?= pagination_links($pg) ?>
<p class="small muted mt-2">Abrufe = Ansichten / Downloads durch Studierende.</p>
