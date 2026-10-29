<?php
/** Admin: Protokoll anlegen, bearbeiten, freigeben, ablehnen, erstatten, löschen. */
View::$active = 'protocols';
$id = is_post() ? post_int('id') : get_int('id');
$protocol = $id ? DB::one('SELECT * FROM protocols WHERE id = ?', [$id]) : null;
if ($id && !$protocol) {
    abort(404);
}
View::$title = $protocol ? 'Protokoll bearbeiten' : 'Neues Protokoll';
$action = post_str('action');
$back = post_str('back') === 'list' ? 'list' : 'edit';
$goBack = function () use ($back, $protocol) {
    if ($back === 'list') {
        redirect_back('admin/protocols');
    }
    redirect('admin/protocol', ['id' => $protocol['id']]);
};

if (is_post() && $protocol) {
    switch ($action) {
        case 'publish':
            $res = Protocols::publish((int)$protocol['id']);
            $msg = 'Das Protokoll wurde veröffentlicht.';
            if (in_array($res['status'] ?? '', ['succeeded', 'pending'], true)) {
                $msg .= ' Rückerstattung über ' . money($res['amount']) . ' wurde ausgelöst.';
            } elseif (($res['status'] ?? '') === 'manual_review') {
                $msg .= ' Die Rückerstattung kann jetzt manuell ausgelöst werden.';
            } elseif (($res['status'] ?? '') === 'failed') {
                $msg .= ' Achtung: Die Rückerstattung ist fehlgeschlagen (' . ($res['error'] ?? '') . ').';
            }
            flash(($res['status'] ?? '') === 'failed' ? 'warning' : 'success', $msg);
            $goBack();
            break;
        case 'reject':
            Protocols::reject((int)$protocol['id'], post_str('reason'));
            flash('success', 'Das Protokoll wurde abgelehnt' . ($protocol['user_id'] ? ' und die Person per E-Mail informiert.' : '.'));
            $goBack();
            break;
        case 'refund':
            if (!Auth::isAdmin()) {
                abort(403, 'Rückerstattungen können nur Admins auslösen.');
            }
            $res = Payments::refundForProtocol((int)$protocol['id'], true);
            $labels = [
                'succeeded' => ['success', 'Erstattung über ' . money($res['amount'] ?? 0) . ' erfolgreich.'],
                'pending' => ['success', 'Erstattung über ' . money($res['amount'] ?? 0) . ' wurde veranlasst.'],
                'manual' => ['info', 'Der Kauf wurde manuell bezahlt – bitte Betrag persönlich auszahlen und als erledigt markieren.'],
                'failed' => ['error', 'Erstattung fehlgeschlagen: ' . ($res['error'] ?? '')],
                'no_purchase' => ['warning', 'Keine erstattungsfähige Zahlung für diese Person und Prüfung gefunden.'],
                'already' => ['info', 'Für dieses Protokoll wurde bereits erstattet.'],
            ];
            [$t, $m] = $labels[$res['status']] ?? ['info', 'Keine Erstattung möglich (' . $res['status'] . ').'];
            flash($t, $m);
            $goBack();
            break;
        case 'delete':
            if (!Auth::isAdmin()) {
                abort(403);
            }
            Protocols::delete((int)$protocol['id']);
            flash('success', 'Das Protokoll wurde gelöscht.');
            redirect('admin/protocols');
            break;
        case 'delete_file':
            $fid = post_int('file_id');
            if (DB::val('SELECT COUNT(*) FROM protocol_files WHERE id = ? AND protocol_id = ?', [$fid, $protocol['id']])) {
                Protocols::deleteFile($fid);
                flash('success', 'Die Datei wurde entfernt.');
            }
            $goBack();
            break;
    }
}

$errors = [];
$v = $protocol ?? [
    'examiner_id' => get_int('examiner'), 'title' => '', 'exam_date' => '', 'grade' => '',
    'content' => '', 'status' => 'published',
];

if (is_post() && $action === 'save') {
    $v = [
        'examiner_id' => post_int('examiner_id'),
        'title' => mb_substr(post_str('title'), 0, 200),
        'exam_date' => post_str('exam_date'),
        'grade' => mb_substr(post_str('grade'), 0, 20),
        'content' => trim(str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''))),
        'status' => in_array(post_str('status'), ['published', 'pending', 'rejected'], true) ? post_str('status') : 'published',
    ];
    $examinerRow = DB::one('SELECT * FROM examiners WHERE id = ?', [$v['examiner_id']]);
    if (!$examinerRow) {
        $errors[] = 'Bitte wähle einen Prüfer.';
    }
    if ($v['exam_date'] !== '' && !valid_date($v['exam_date'])) {
        $errors[] = 'Das Prüfungsdatum ist ungültig.';
    }
    $files = Protocols::normalizeFiles($_FILES['files'] ?? null);
    foreach ($files as $f) {
        if ($err = Protocols::validateUpload($f)) {
            $errors[] = $err;
        }
    }
    $hasFiles = $protocol ? (int)DB::val('SELECT COUNT(*) FROM protocol_files WHERE protocol_id = ?', [$protocol['id']]) > 0 : false;
    if ($v['content'] === '' && !$files && !$hasFiles) {
        $errors[] = 'Bitte gib einen Text ein oder lade eine Datei hoch.';
    }
    if (!$errors) {
        if ($v['title'] === '') {
            $v['title'] = Protocols::defaultTitle($examinerRow['name'], $v['exam_date'] ?: null);
        }
        if ($protocol) {
            $wasPublished = $protocol['status'] === 'published';
            DB::update('protocols', [
                'examiner_id' => $v['examiner_id'],
                'title' => $v['title'],
                'exam_date' => $v['exam_date'] ?: null,
                'grade' => $v['grade'] !== '' ? $v['grade'] : null,
                'content' => $v['content'] !== '' ? $v['content'] : null,
                'updated_at' => now(),
            ], 'id = ?', [$protocol['id']]);
            $pid = (int)$protocol['id'];
            foreach ($files as $f) {
                Protocols::storeFile($pid, $f['tmp_name'], $f['name'], 'upload');
            }
            log_activity('protocol_updated', 'Protokoll #' . $pid);
            $msg = 'Das Protokoll wurde gespeichert.';
            if ($v['status'] !== $protocol['status']) {
                if ($v['status'] === 'published') {
                    $res = Protocols::publish($pid);
                    if (in_array($res['status'] ?? '', ['succeeded', 'pending'], true)) {
                        $msg .= ' Rückerstattung über ' . money($res['amount']) . ' ausgelöst.';
                    }
                } elseif ($v['status'] === 'rejected') {
                    Protocols::reject($pid, (string)($protocol['reject_reason'] ?? ''));
                } else {
                    DB::update('protocols', ['status' => 'pending'], 'id = ?', [$pid]);
                }
            }
            flash('success', $msg);
            redirect('admin/protocol', ['id' => $pid]);
        }
        $pid = Protocols::create([
            'examiner_id' => $v['examiner_id'],
            'user_id' => null,
            'title' => $v['title'],
            'exam_date' => $v['exam_date'] ?: null,
            'grade' => $v['grade'],
            'content' => $v['content'],
            'status' => $v['status'],
            'source' => 'admin',
        ]);
        foreach ($files as $f) {
            Protocols::storeFile($pid, $f['tmp_name'], $f['name'], 'upload');
        }
        log_activity('protocol_created', 'Protokoll #' . $pid . ' (Admin)');
        flash('success', 'Das Protokoll wurde angelegt.');
        redirect('admin/protocol', ['id' => $pid]);
    }
}

// Prüferauswahl, gruppiert nach Prüfung
$examinerGroups = [];
foreach (DB::all('SELECT e.id, e.name, e.subject, e.is_active, x.name AS exam_name FROM examiners e JOIN exams x ON x.id = e.exam_id ORDER BY x.sort_order, x.name, e.name') as $ex) {
    $examinerGroups[$ex['exam_name']][] = $ex;
}
$files = $protocol ? DB::all('SELECT * FROM protocol_files WHERE protocol_id = ? ORDER BY id', [$protocol['id']]) : [];
$uploader = $protocol && $protocol['user_id'] ? DB::one('SELECT * FROM users WHERE id = ?', [$protocol['user_id']]) : null;
$examinerRow = $protocol ? DB::one('SELECT e.*, x.name AS exam_name FROM examiners e JOIN exams x ON x.id = e.exam_id WHERE e.id = ?', [$protocol['examiner_id']]) : null;
$refundPurchase = $protocol && $protocol['refund_purchase_id'] ? DB::one('SELECT * FROM purchases WHERE id = ?', [$protocol['refund_purchase_id']]) : null;
$eligible = null;
$siblingRefunded = false;
if ($protocol && $uploader && !$refundPurchase && $protocol['source'] === 'upload') {
    $eligible = Payments::eligiblePurchaseForRefund((int)$uploader['id'], (int)$examinerRow['exam_id']);
    if ($protocol['submission_id']) {
        $siblingRefunded = (int)DB::val('SELECT COUNT(*) FROM protocols WHERE submission_id = ? AND refund_purchase_id IS NOT NULL', [$protocol['submission_id']]) > 0;
    }
}
$siblings = $protocol && $protocol['submission_id']
    ? DB::all('SELECT p.id, p.title, e.name AS examiner_name FROM protocols p JOIN examiners e ON e.id = p.examiner_id WHERE p.submission_id = ? AND p.id <> ?', [$protocol['submission_id'], $protocol['id']])
    : [];
$sourceLabels = ['upload' => 'Upload durch Studierende', 'admin' => 'Im Adminbereich angelegt', 'import' => 'Import / Migration'];
?>
<?= admin_head($protocol ? $protocol['title'] : 'Neues Protokoll', '<a href="' . e(url('admin/protocols')) . '">← Alle Protokolle</a>', $protocol ? '<a class="btn btn-secondary" href="' . e(url('protocol', ['id' => $protocol['id']])) . '">' . icon('eye') . ' Ansehen</a>' : '') ?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($err) ?></div></div><?php endforeach; ?>

<?php if ($protocol && $protocol['status'] === 'pending'): ?>
  <div class="card mb-2" style="border-color:var(--c-warning)">
    <div class="flex-between">
      <div><strong><?= icon('clock') ?> Dieses Protokoll wartet auf Freigabe.</strong><div class="small muted">Prüfe Inhalt und Prüferzuordnung. Beim Freigeben wird ggf. automatisch erstattet.</div></div>
      <div class="btn-group">
        <?= post_button(url('admin/protocol'), icon('check') . ' Freigeben', ['id' => $protocol['id'], 'action' => 'publish'], 'btn btn-success') ?>
      </div>
    </div>
    <form method="post" action="<?= e(url('admin/protocol')) ?>" class="mt-2">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$protocol['id'] ?>">
      <input type="hidden" name="action" value="reject">
      <div class="input-group">
        <input type="text" name="reason" class="input" placeholder="Begründung für die Ablehnung (wird per E-Mail mitgeteilt)">
        <button type="submit" class="btn btn-outline-danger" data-confirm="Protokoll ablehnen?">Ablehnen</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="layout-sidebar">
  <form method="post" action="<?= e(url('admin/protocol')) ?>" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($protocol['id'] ?? 0) ?>">
    <div class="field">
      <label for="f_examiner_id">Prüfer <span class="req">*</span></label>
      <select class="input" name="examiner_id" id="f_examiner_id" required>
        <option value="">– Prüfer wählen –</option>
        <?php foreach ($examinerGroups as $examName => $list): ?>
          <optgroup label="<?= e($examName) ?>">
            <?php foreach ($list as $ex): ?>
              <option value="<?= (int)$ex['id'] ?>"<?= selected($ex['id'], $v['examiner_id']) ?>><?= e($ex['name'] . ($ex['subject'] !== '' ? ' (' . $ex['subject'] . ')' : '') . ((int)$ex['is_active'] ? '' : ' – inaktiv')) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <small class="help">Fehlt der Prüfer? <a href="<?= e(url('admin/examiner', ['new' => 1])) ?>">Neuen Prüfer anlegen</a></small>
    </div>
    <?= field_text('title', 'Titel', $v['title'], ['help' => 'Leer lassen für automatischen Titel.']) ?>
    <div class="form-row form-row-3">
      <?= field_text('exam_date', 'Prüfungsdatum', (string)$v['exam_date'], ['type' => 'date']) ?>
      <?= field_text('grade', 'Note', (string)$v['grade']) ?>
      <?= field_select('status', 'Status', ['published' => 'Veröffentlicht', 'pending' => 'Wartet auf Freigabe', 'rejected' => 'Abgelehnt'], $v['status']) ?>
    </div>
    <?= field_textarea('content', 'Protokolltext', (string)$v['content'], ['rows' => 18, 'help' => 'Formatierung: **fett**, „- “ für Aufzählungen, „# “ für Überschriften.']) ?>

    <?php if ($files): ?>
      <div class="field">
        <label>Vorhandene Dateien</label>
        <ul class="file-list">
          <?php foreach ($files as $f): ?>
            <li class="file-item"><div class="file-row">
              <?= icon('file') ?><span class="f-name"><?= e($f['original_name']) ?></span><span class="f-size"><?= e(human_filesize($f['size'])) ?></span>
              <a class="btn btn-secondary btn-sm" href="<?= e(url('file', ['id' => $f['id']])) ?>"><?= icon('download') ?></a>
              <button type="submit" class="btn btn-outline-danger btn-sm" form="delete-file-<?= (int)$f['id'] ?>" data-confirm="Datei entfernen?"><?= icon('trash') ?></button>
            </div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="field">
      <label>Dateien hinzufügen</label>
      <div class="dropzone">
        <input type="file" name="files[]" multiple>
        <small class="help">Erlaubt: <?= e(implode(', ', Protocols::allowedExtensions())) ?> · max. <?= e(human_filesize(Protocols::maxBytes())) ?> je Datei</small>
        <ul class="file-names"></ul>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
      <a class="btn btn-secondary" href="<?= e(url('admin/protocols')) ?>">Abbrechen</a>
    </div>
  </form>
  <?php foreach ($files as $f): ?>
    <form id="delete-file-<?= (int)$f['id'] ?>" method="post" action="<?= e(url('admin/protocol')) ?>" class="hidden"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$protocol['id'] ?>"><input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"></form>
  <?php endforeach; ?>

  <?php if ($protocol): ?>
    <aside>
      <div class="card">
        <h3>Details</h3>
        <dl class="meta-list">
          <dt>Status</dt><dd><?= protocol_status_badge($protocol['status']) ?></dd>
          <dt>Prüfung</dt><dd><?= e($examinerRow['exam_name'] ?? '–') ?></dd>
          <dt>Herkunft</dt><dd><?= e($sourceLabels[$protocol['source']] ?? $protocol['source']) ?></dd>
          <dt>Von</dt><dd><?= $uploader ? '<a href="' . e(url('admin/user', ['id' => $uploader['id']])) . '">' . e($uploader['email']) . '</a>' : '–' ?></dd>
          <dt>Erstellt</dt><dd><?= e(fmt_datetime($protocol['created_at'])) ?></dd>
          <dt>Veröffentlicht</dt><dd><?= e(fmt_datetime($protocol['published_at'])) ?></dd>
          <dt>Ansichten</dt><dd><?= (int)$protocol['view_count'] ?></dd>
          <dt>Downloads</dt><dd><?= (int)$protocol['download_count'] ?></dd>
        </dl>
        <?php if ($protocol['status'] === 'rejected' && $protocol['reject_reason']): ?>
          <p class="small mt-2"><strong>Ablehnungsgrund:</strong> <?= e($protocol['reject_reason']) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($siblings): ?>
        <div class="card">
          <h3>Gleicher Upload</h3>
          <p class="small muted">Diese Protokolle wurden zusammen hochgeladen (eine Prüfung, mehrere Prüfer):</p>
          <ul class="summary-list mb-0">
            <?php foreach ($siblings as $s): ?><li><?= icon('file-text') ?><a href="<?= e(url('admin/protocol', ['id' => $s['id']])) ?>"><?= e($s['examiner_name']) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($uploader && $protocol['source'] === 'upload' && Auth::isAdmin()): ?>
        <div class="card">
          <h3><?= icon('refund') ?> Rückerstattung</h3>
          <?php if ($refundPurchase): ?>
            <p class="small">Für dieses Protokoll wurde <a href="<?= e(url('admin/purchase', ['id' => $refundPurchase['id']])) ?>">Kauf #<?= (int)$refundPurchase['id'] ?></a> erstattet:</p>
            <p><?= Payments::refundBadge($refundPurchase['refund_status']) ?> <strong><?= e(money($refundPurchase['refund_cents'], $refundPurchase['currency'])) ?></strong></p>
          <?php elseif ($siblingRefunded): ?>
            <p class="small muted mb-0">Für diesen Upload wurde bereits über ein anderes Protokoll erstattet.</p>
          <?php elseif ($eligible): ?>
            <p class="small">Erstattungsfähig: <a href="<?= e(url('admin/purchase', ['id' => $eligible['id']])) ?>">Kauf #<?= (int)$eligible['id'] ?></a> vom <?= e(fmt_date($eligible['paid_at'])) ?> – <strong><?= e(money($eligible['refund_amount'])) ?></strong>.</p>
            <?php if ($protocol['status'] !== 'published'): ?><p class="small muted">Wird bei Freigabe automatisch erstattet<?= setting('refund_mode') === 'manual' ? ' (Modus „manuell“: bitte unten auslösen)' : '' ?>.</p><?php endif; ?>
            <?= post_button(url('admin/protocol'), icon('refund') . ' Jetzt erstatten', ['id' => $protocol['id'], 'action' => 'refund'], 'btn btn-primary btn-sm', 'Jetzt ' . money($eligible['refund_amount']) . ' erstatten?') ?>
          <?php else: ?>
            <p class="small muted mb-0">Keine erstattungsfähige Zahlung (kein passender, noch nicht erstatteter Kauf<?= Settings::int('refund_days') > 0 ? ' innerhalb von ' . Settings::int('refund_days') . ' Tagen' : '' ?>).</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (Auth::isAdmin()): ?>
        <form method="post" action="<?= e(url('admin/protocol')) ?>" class="card danger-zone" data-confirm="Protokoll inkl. Dateien endgültig löschen?">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$protocol['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <h3>Protokoll löschen</h3>
          <p class="small muted">Löscht das Protokoll und alle Dateien endgültig. Bereits erfolgte Erstattungen bleiben bestehen.</p>
          <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Löschen</button>
        </form>
      <?php endif; ?>
    </aside>
  <?php endif; ?>
</div>
