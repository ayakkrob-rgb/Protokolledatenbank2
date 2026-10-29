<?php
/** Admin: Prüfer verwalten. */
View::$title = 'Prüfer';
View::$active = 'examiners';

if (is_post()) {
    $ids = array_values(array_filter(array_map('intval', post_array('ids'))));
    $bulk = post_str('bulk');
    if (!$ids) {
        flash('error', 'Bitte wähle mindestens einen Prüfer aus.');
        redirect_back('admin/examiners');
    }
    $in = DB::placeholders($ids);
    switch ($bulk) {
        case 'approve':
            DB::q("UPDATE examiners SET needs_review = 0 WHERE id IN ($in)", $ids);
            flash('success', count($ids) . ' Prüfer als geprüft markiert.');
            break;
        case 'activate':
            DB::q("UPDATE examiners SET is_active = 1 WHERE id IN ($in)", $ids);
            flash('success', count($ids) . ' Prüfer aktiviert.');
            break;
        case 'deactivate':
            DB::q("UPDATE examiners SET is_active = 0 WHERE id IN ($in)", $ids);
            flash('success', count($ids) . ' Prüfer deaktiviert.');
            break;
        case 'delete':
            $deleted = 0;
            foreach ($ids as $id) {
                $used = (int)DB::val('SELECT COUNT(*) FROM protocols WHERE examiner_id = ?', [$id])
                    + (int)DB::val('SELECT COUNT(*) FROM purchase_examiners WHERE examiner_id = ?', [$id]);
                if (!$used) {
                    DB::delete('examiners', 'id = ?', [$id]);
                    $deleted++;
                }
            }
            flash($deleted === count($ids) ? 'success' : 'warning', $deleted . ' Prüfer gelöscht.' . ($deleted < count($ids) ? ' Prüfer mit Protokollen oder Freischaltungen können nicht gelöscht werden – bitte zusammenführen oder deaktivieren.' : ''));
            break;
    }
    log_activity('examiners_bulk', $bulk . ': ' . implode(',', $ids));
    redirect_back('admin/examiners');
}

$examId = get_int('exam');
$q = get_str('q');
$review = get_int('review') === 1;
$status = get_str('status');
$where = ['1=1'];
$params = [];
if ($examId) {
    $where[] = 'e.exam_id = ?';
    $params[] = $examId;
}
if ($q !== '') {
    $where[] = '(e.name LIKE ? OR e.subject LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($review) {
    $where[] = 'e.needs_review = 1';
}
if ($status === 'active') {
    $where[] = 'e.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'e.is_active = 0';
}
$whereSql = implode(' AND ', $where);
$total = (int)DB::val("SELECT COUNT(*) FROM examiners e WHERE $whereSql", $params);
$pg = paginate($total, 50);
$rows = DB::all(
    "SELECT e.*, x.short_name, x.name AS exam_name, u.email AS creator_email,
        (SELECT COUNT(*) FROM protocols p WHERE p.examiner_id = e.id AND p.status = 'published') AS published_count,
        (SELECT COUNT(*) FROM protocols p WHERE p.examiner_id = e.id) AS protocol_count,
        (SELECT COUNT(*) FROM purchase_examiners pe JOIN purchases pu ON pu.id = pe.purchase_id WHERE pe.examiner_id = e.id AND pu.status = 'paid') AS unlock_count
     FROM examiners e JOIN exams x ON x.id = e.exam_id LEFT JOIN users u ON u.id = e.created_by
     WHERE $whereSql ORDER BY x.sort_order, e.subject, e.name LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'],
    $params
);
$exams = DB::all('SELECT id, name FROM exams ORDER BY sort_order, name');
$examOptions = ['' => 'Alle Prüfungen'];
foreach ($exams as $x) {
    $examOptions[$x['id']] = $x['name'];
}
?>
<?= admin_head('Prüfer', 'Prüferinnen und Prüfer je Prüfung. Neue Prüfer, die beim Upload angelegt wurden, sind als „neu“ markiert.', '<a class="btn btn-primary" href="' . e(url('admin/examiner', ['new' => 1, 'exam' => $examId ?: null])) . '">' . icon('plus') . ' Neuer Prüfer</a>') ?>

<form method="get" action="<?= e(base_url() . 'index.php') ?>" class="filters">
  <input type="hidden" name="p" value="admin/examiners">
  <div class="field grow"><label>Suche</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Name oder Fach"></div>
  <?= field_select('exam', 'Prüfung', $examOptions, $examId ?: '', ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('status', 'Status', ['' => 'Alle', 'active' => 'Aktiv', 'inactive' => 'Inaktiv'], $status, ['attrs' => ['data-autosubmit' => true]]) ?>
  <div class="field"><label>&nbsp;</label><label class="checkbox mb-0" style="padding:10px 0"><input type="checkbox" name="review" value="1"<?= checked($review) ?> data-autosubmit> Nur neue</label></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Filtern</button></div>
</form>

<form method="post" action="<?= e(url('admin/examiners')) ?>">
  <?= csrf_field() ?>
  <div class="card card-flush">
    <div class="card-header">
      <span class="muted small"><?= e(plural($total, 'Prüfer', 'Prüfer')) ?></span>
      <div class="btn-group">
        <select name="bulk" class="input input-sm" style="width:auto">
          <option value="approve">Als geprüft markieren</option>
          <option value="activate">Aktivieren</option>
          <option value="deactivate">Deaktivieren</option>
          <option value="delete">Löschen (nur ohne Protokolle)</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm" data-confirm="Aktion für die ausgewählten Prüfer ausführen?">Ausführen</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:32px"><input type="checkbox" data-check-all aria-label="Alle auswählen"></th><th>Prüfer</th><th>Prüfung</th><th class="num">Protokolle</th><th class="num">Freischaltungen</th><th>Angelegt</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Auswählen"></td>
              <td><a href="<?= e(url('admin/examiner', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a><span class="table-sub"><?= e($r['subject'] ?: '–') ?></span></td>
              <td><?= e($r['short_name'] ?: $r['exam_name']) ?></td>
              <td class="num"><a href="<?= e(url('admin/protocols', ['examiner' => $r['id']])) ?>"><?= (int)$r['published_count'] ?></a><?= (int)$r['protocol_count'] > (int)$r['published_count'] ? '<span class="table-sub">+' . ((int)$r['protocol_count'] - (int)$r['published_count']) . ' weitere</span>' : '' ?></td>
              <td class="num"><?= (int)$r['unlock_count'] ?></td>
              <td class="small"><?= e(fmt_date($r['created_at'])) ?><span class="table-sub"><?= $r['creator_email'] ? 'durch ' . e($r['creator_email']) : ((int)$r['is_demo'] ? 'Demodaten' : 'Admin/Import') ?></span></td>
              <td><?= (int)$r['is_active'] ? badge('Aktiv', 'success') : badge('Inaktiv', 'neutral') ?> <?= (int)$r['needs_review'] ? badge('Neu', 'warning') : '' ?></td>
              <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/examiner', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="8" class="muted">Keine Prüfer gefunden.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?= pagination_links($pg) ?>
