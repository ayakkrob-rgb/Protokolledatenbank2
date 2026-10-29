<?php
/** Admin: Prüfer anlegen / bearbeiten / zusammenführen / löschen. */
View::$active = 'examiners';
$id = is_post() ? post_int('id') : get_int('id');
$examiner = $id ? DB::one('SELECT * FROM examiners WHERE id = ?', [$id]) : null;
if ($id && !$examiner) {
    abort(404);
}
View::$title = $examiner ? 'Prüfer bearbeiten' : 'Neuer Prüfer';
$exams = DB::all('SELECT * FROM exams ORDER BY sort_order, name');
$examOptions = [];
foreach ($exams as $x) {
    $examOptions[$x['id']] = $x['name'];
}
$errors = [];
$v = $examiner ?? ['exam_id' => get_int('exam') ?: ($exams[0]['id'] ?? 0), 'name' => '', 'subject' => '', 'info' => '', 'admin_note' => '', 'is_active' => 1, 'needs_review' => 0];
$action = post_str('action');

if (is_post() && $examiner && $action === 'approve') {
    DB::update('examiners', ['needs_review' => 0], 'id = ?', [$examiner['id']]);
    flash('success', '„' . $examiner['name'] . '“ wurde als geprüft markiert.');
    redirect_back('admin/examiners');
}

if (is_post() && $examiner && $action === 'merge') {
    $target = DB::one('SELECT * FROM examiners WHERE id = ?', [post_int('target_id')]);
    if (!$target || (int)$target['id'] === (int)$examiner['id']) {
        flash('error', 'Bitte wähle den Prüfer aus, in den zusammengeführt werden soll.');
        redirect('admin/examiner', ['id' => $examiner['id']]);
    }
    Protocols::mergeExaminers((int)$examiner['id'], (int)$target['id']);
    log_activity('examiner_merged', $examiner['name'] . ' → ' . $target['name']);
    flash('success', '„' . $examiner['name'] . '“ wurde mit „' . $target['name'] . '“ zusammengeführt. Alle Protokolle und Freischaltungen wurden übernommen.');
    redirect('admin/examiner', ['id' => $target['id']]);
}

if (is_post() && $examiner && $action === 'delete') {
    $used = (int)DB::val('SELECT COUNT(*) FROM protocols WHERE examiner_id = ?', [$examiner['id']])
        + (int)DB::val('SELECT COUNT(*) FROM purchase_examiners WHERE examiner_id = ?', [$examiner['id']]);
    if ($used) {
        flash('error', 'Dieser Prüfer hat Protokolle oder Freischaltungen. Bitte zusammenführen oder deaktivieren.');
        redirect('admin/examiner', ['id' => $examiner['id']]);
    }
    DB::delete('examiners', 'id = ?', [$examiner['id']]);
    log_activity('examiner_deleted', $examiner['name']);
    flash('success', 'Der Prüfer wurde gelöscht.');
    redirect('admin/examiners');
}

if (is_post() && $action === 'save') {
    $v = [
        'exam_id' => post_int('exam_id'),
        'name' => trim((string)preg_replace('/\s+/u', ' ', post_str('name'))),
        'subject' => mb_substr(post_str('subject'), 0, 100),
        'info' => post_str('info'),
        'admin_note' => post_str('admin_note'),
        'is_active' => post_bool('is_active') ? 1 : 0,
        'needs_review' => post_bool('needs_review') ? 1 : 0,
    ];
    if (!isset($examOptions[$v['exam_id']])) {
        $errors[] = 'Bitte wähle eine Prüfung.';
    }
    if (mb_strlen($v['name']) < 2 || name_key($v['name']) === '') {
        $errors[] = 'Bitte gib den Namen des Prüfers ein.';
    }
    $v['name_key'] = name_key($v['name']);
    $dupe = DB::one('SELECT id, name FROM examiners WHERE exam_id = ? AND name_key = ? AND id <> ?', [$v['exam_id'], $v['name_key'], $examiner['id'] ?? 0]);
    if ($dupe && !$errors) {
        $errors[] = 'In dieser Prüfung gibt es bereits „' . $dupe['name'] . '“ (#' . $dupe['id'] . '). Nutze ggf. die Funktion „Zusammenführen“.';
    }
    if (!$errors) {
        if ($examiner) {
            DB::update('examiners', $v, 'id = ?', [$examiner['id']]);
            log_activity('examiner_updated', $v['name']);
            flash('success', 'Der Prüfer wurde gespeichert.');
            redirect('admin/examiner', ['id' => $examiner['id']]);
        }
        $newId = DB::insert('examiners', $v + ['created_by' => null, 'created_at' => now()]);
        log_activity('examiner_created', $v['name']);
        flash('success', 'Der Prüfer wurde angelegt.');
        redirect('admin/examiner', ['id' => $newId]);
    }
}

$subjects = DB::col("SELECT DISTINCT subject FROM examiners WHERE subject <> '' ORDER BY subject");
$protocols = $examiner ? DB::all('SELECT p.*, u.email FROM protocols p LEFT JOIN users u ON u.id = p.user_id WHERE p.examiner_id = ? ORDER BY p.created_at DESC', [$examiner['id']]) : [];
$unlocks = $examiner ? (int)DB::val("SELECT COUNT(*) FROM purchase_examiners pe JOIN purchases p ON p.id = pe.purchase_id WHERE pe.examiner_id = ? AND p.status = 'paid'", [$examiner['id']]) : 0;
$mergeTargets = $examiner ? DB::all('SELECT id, name, subject FROM examiners WHERE exam_id = ? AND id <> ? ORDER BY name', [$examiner['exam_id'], $examiner['id']]) : [];
$similar = [];
if ($examiner) {
    $parts = array_filter(explode(' ', (string)$examiner['name_key']), fn($p) => strlen($p) >= 4);
    foreach ($mergeTargets as $t) {
        foreach ($parts as $part) {
            if (str_contains(name_key($t['name']), $part)) {
                $similar[] = $t;
                break;
            }
        }
    }
}
$creator = $examiner && $examiner['created_by'] ? DB::one('SELECT id, email FROM users WHERE id = ?', [$examiner['created_by']]) : null;
?>
<?= admin_head($examiner ? $examiner['name'] : 'Neuer Prüfer', '<a href="' . e(url('admin/examiners')) . '">← Alle Prüfer</a>', $examiner ? '<a class="btn btn-secondary" href="' . e(url('examiner', ['id' => $examiner['id']])) . '">' . icon('eye') . ' Ansehen</a><a class="btn btn-primary" href="' . e(url('admin/protocol', ['new' => 1, 'examiner' => $examiner['id']])) . '">' . icon('plus') . ' Protokoll</a>' : '') ?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($err) ?></div></div><?php endforeach; ?>

<?php if ($examiner && (int)$examiner['needs_review']): ?>
  <div class="alert alert-warning"><?= icon('info') ?><div>
    Dieser Prüfer wurde <?= $creator ? 'von <a href="' . e(url('admin/user', ['id' => $creator['id']])) . '">' . e($creator['email']) . '</a>' : 'automatisch' ?> beim Hochladen eines Protokolls angelegt. Bitte prüfe Schreibweise und Fach<?= $similar ? ' – es gibt ähnliche Prüfer, ggf. zusammenführen' : '' ?>.
    <div class="mt-1"><?= post_button(url('admin/examiner'), icon('check') . ' Als geprüft markieren', ['id' => $examiner['id'], 'action' => 'approve'], 'btn btn-secondary btn-sm') ?></div>
  </div></div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start">
  <form method="post" action="<?= e(url('admin/examiner')) ?>" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($examiner['id'] ?? 0) ?>">
    <?= field_select('exam_id', 'Prüfung', $examOptions, $v['exam_id'], ['required' => true, 'help' => $examiner ? 'Beim Wechsel der Prüfung wandern alle Protokolle mit.' : '']) ?>
    <div class="form-row">
      <?= field_text('name', 'Name inkl. Titel', $v['name'], ['required' => true, 'placeholder' => 'Prof. Dr. Erika Mustermann']) ?>
      <?= field_text('subject', 'Fach', $v['subject'], ['placeholder' => 'z. B. Anatomie', 'attrs' => ['list' => 'subjects']]) ?>
    </div>
    <datalist id="subjects"><?php foreach ($subjects as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
    <?= field_textarea('info', 'Öffentliche Hinweise (optional)', $v['info'], ['rows' => 3, 'help' => 'Wird auf der Prüferseite angezeigt, z. B. „Prüft meist zusammen mit …“.']) ?>
    <?= field_textarea('admin_note', 'Interne Notiz', $v['admin_note'], ['rows' => 2, 'help' => 'Nur für das Team sichtbar.']) ?>
    <?= field_toggle('is_active', 'Aktiv (für Studierende sichtbar)', (bool)$v['is_active']) ?>
    <?= field_toggle('needs_review', 'Als „neu / zu prüfen“ markieren', (bool)$v['needs_review']) ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
      <a class="btn btn-secondary" href="<?= e(url('admin/examiners')) ?>">Abbrechen</a>
    </div>
  </form>

  <?php if ($examiner): ?>
    <div>
      <div class="card card-flush">
        <div class="card-header"><h3>Protokolle (<?= count($protocols) ?>)</h3><span class="muted small"><?= $unlocks ?> Freischaltungen</span></div>
        <table class="table table-compact"><tbody>
          <?php foreach ($protocols as $p): ?>
            <tr>
              <td><a href="<?= e(url('admin/protocol', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a><span class="table-sub"><?= e(fmt_date($p['exam_date'], 'ohne Datum')) ?> · <?= e($p['email'] ?: ($p['source'] === 'import' ? 'Import' : 'Admin')) ?></span></td>
              <td><?= protocol_status_badge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$protocols): ?><tr><td class="muted">Noch keine Protokolle.</td></tr><?php endif; ?>
        </tbody></table>
      </div>

      <form method="post" action="<?= e(url('admin/examiner')) ?>" class="card" data-confirm="Prüfer wirklich zusammenführen? Dieser Eintrag wird danach gelöscht.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="merge">
        <input type="hidden" name="id" value="<?= (int)$examiner['id'] ?>">
        <h3><?= icon('merge') ?> Zusammenführen</h3>
        <p class="small muted">Doppelt angelegt (z. B. „Müller“ und „Prof. Dr. H. Müller“)? Alle Protokolle und Freischaltungen dieses Eintrags werden auf den gewählten Prüfer übertragen, danach wird dieser Eintrag gelöscht.</p>
        <?php if ($similar): ?>
          <p class="small"><strong>Ähnliche Namen:</strong> <?= e(implode(', ', array_map(fn($s) => $s['name'], $similar))) ?></p>
        <?php endif; ?>
        <div class="input-group">
          <select name="target_id" class="input" required>
            <option value="">– Ziel-Prüfer wählen –</option>
            <?php foreach ($mergeTargets as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['name'] . ($t['subject'] !== '' ? ' (' . $t['subject'] . ')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary">Zusammenführen</button>
        </div>
      </form>

      <form method="post" action="<?= e(url('admin/examiner')) ?>" class="card danger-zone" data-confirm="Prüfer endgültig löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$examiner['id'] ?>">
        <h3>Prüfer löschen</h3>
        <p class="small muted">Nur möglich ohne Protokolle und Freischaltungen.</p>
        <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Löschen</button>
      </form>
    </div>
  <?php endif; ?>
</div>
