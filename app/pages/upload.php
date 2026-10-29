<?php
/**
 * Protokoll hochladen. Ein Upload kann mehrere Prüfer umfassen
 * (z. B. Prüfungskommission) – pro Prüfer entsteht ein Protokoll.
 */
$user = Auth::user();
$isStaff = Auth::isStaff();
View::$title = 'Protokoll hochladen';
View::$active = 'upload';

$exams = DB::all('SELECT * FROM exams WHERE is_active = 1 ORDER BY sort_order, name');
$examMap = [];
foreach ($exams as $x) {
    $examMap[(int)$x['id']] = $x;
}
$byExam = [];
foreach (DB::all('SELECT id, exam_id, name, subject FROM examiners WHERE is_active = 1 ORDER BY subject, name') as $ex) {
    $byExam[(int)$ex['exam_id']][] = ['id' => (int)$ex['id'], 'name' => $ex['name'], 'subject' => $ex['subject']];
}
$subjects = DB::col("SELECT DISTINCT subject FROM examiners WHERE subject <> '' ORDER BY subject");
$maxBlocks = 1;
foreach ($exams as $x) {
    $maxBlocks = max($maxBlocks, Payments::maxExaminers($x));
}
$maxBlocks = min($maxBlocks, 8);
$allowNew = Settings::bool('allow_new_examiners') || $isStaff;
$minLen = max(0, Settings::int('min_content_length'));
$maxFiles = max(1, Settings::int('max_files'));
$exts = Protocols::allowedExtensions();
$maxBytes = Protocols::maxBytes();
$moderation = Settings::bool('moderation') && !$isStaff;

// Info zur Rückerstattung je Prüfung
$refundInfo = [];
foreach ($exams as $x) {
    $pur = Payments::eligiblePurchaseForRefund((int)$user['id'], (int)$x['id']);
    if ($pur) {
        $pExam = $examMap[(int)$pur['exam_id']] ?? ['name' => 'Prüfung'];
        $when = $moderation ? 'nach der Freigabe durch die Fachschaft' : (setting('refund_mode') === 'manual' ? 'nach einer kurzen Prüfung' : 'direkt nach dem Hochladen');
        $refundInfo[(int)$x['id']] = '<strong>' . e(money($pur['refund_amount'])) . ' zurück!</strong> Für deinen Kauf vom ' . e(fmt_date($pur['paid_at'])) . ' (' . e($pExam['name']) . ') erstatten wir dir ' . e($when) . ' ' . e(money($pur['refund_amount'])) . '.';
    }
}

$errors = [];
$values = ['exam_id' => get_int('exam') ?: '', 'exam_date' => '', 'grade' => '', 'blocks' => []];

if (is_post()) {
    $examId = post_int('exam_id');
    $exam = $examMap[$examId] ?? null;
    $examDate = post_str('exam_date');
    $grade = mb_substr(post_str('grade'), 0, 20);
    $values = ['exam_id' => $examId, 'exam_date' => $examDate, 'grade' => $grade, 'blocks' => []];

    if (!$exam) {
        $errors[] = 'Bitte wähle die Prüfung aus.';
    }
    if ($examDate !== '') {
        if (!valid_date($examDate) || $examDate < '1990-01-01' || strtotime($examDate) > time() + 86400) {
            $errors[] = 'Bitte gib ein gültiges Prüfungsdatum an (nicht in der Zukunft).';
        }
    }

    $blocksIn = post_array('blocks');
    $parsed = [];
    $seen = [];
    for ($i = 0; $i < $maxBlocks; $i++) {
        $b = is_array($blocksIn[$i] ?? null) ? $blocksIn[$i] : [];
        $sel = trim((string)($b['examiner_id'] ?? ''));
        $newName = trim((string)($b['new_name'] ?? ''));
        $newSubject = trim((string)($b['new_subject'] ?? ''));
        $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 200);
        $content = trim(str_replace("\r\n", "\n", (string)($b['content'] ?? '')));
        $files = Protocols::normalizeFiles($_FILES['files' . $i] ?? null);
        if ($sel === '' && $newName === '' && $content === '' && !$files) {
            continue;
        }
        $n = count($parsed) + 1;
        $values['blocks'][$i] = ['examiner_id' => $sel, 'new_name' => $newName, 'new_subject' => $newSubject, 'title' => $title, 'content' => $content];

        $examinerId = null;
        $isNew = false;
        if ($sel === '__new' || ($sel === '' && $newName !== '')) {
            if (!$allowNew) {
                $errors[] = 'Prüfer ' . $n . ': Neue Prüfer können derzeit nicht angelegt werden. Bitte wähle einen aus der Liste.';
            } elseif (mb_strlen($newName) < 3 || name_key($newName) === '') {
                $errors[] = 'Prüfer ' . $n . ': Bitte gib den vollständigen Namen des neuen Prüfers an (z. B. „Prof. Dr. Erika Mustermann“).';
            } else {
                $isNew = true;
                $key = 'new:' . name_key($newName);
                if (isset($seen[$key])) {
                    $errors[] = 'Prüfer ' . $n . ': Dieser Prüfer ist doppelt angegeben.';
                }
                $seen[$key] = true;
            }
        } elseif ($sel === '') {
            $errors[] = 'Prüfer ' . $n . ': Bitte wähle den Prüfer aus.';
        } else {
            $examinerId = (int)$sel;
            $exRow = DB::one('SELECT * FROM examiners WHERE id = ? AND is_active = 1', [$examinerId]);
            if (!$exRow || ($exam && (int)$exRow['exam_id'] !== (int)$exam['id'])) {
                $errors[] = 'Prüfer ' . $n . ': Der gewählte Prüfer gehört nicht zur gewählten Prüfung.';
            }
            if (isset($seen['id:' . $examinerId])) {
                $errors[] = 'Prüfer ' . $n . ': Dieser Prüfer ist doppelt angegeben.';
            }
            $seen['id:' . $examinerId] = true;
        }

        if (count($files) > $maxFiles) {
            $errors[] = 'Prüfer ' . $n . ': Bitte lade höchstens ' . $maxFiles . ' Dateien hoch.';
        }
        foreach ($files as $f) {
            $err = Protocols::validateUpload($f);
            if ($err) {
                $errors[] = 'Prüfer ' . $n . ': ' . $err;
            }
        }
        if (!$files && mb_strlen($content) < $minLen) {
            $errors[] = 'Prüfer ' . $n . ': Bitte schreibe dein Protokoll' . ($minLen > 0 ? ' (mindestens ' . $minLen . ' Zeichen)' : '') . ' oder lade eine Datei hoch.';
        }
        $parsed[] = compact('examinerId', 'isNew', 'newName', 'newSubject', 'title', 'content', 'files');
    }

    if (!$parsed && $exam) {
        $errors[] = 'Bitte gib mindestens einen Prüfer mit Protokoll an.';
    }
    if (!post_bool('rights')) {
        $errors[] = 'Bitte bestätige, dass du das Protokoll selbst verfasst hast und es veröffentlicht werden darf.';
    }
    if (!$isStaff && Auth::tooManyAttempts('upload:' . $user['id'], 20, 60)) {
        $errors[] = 'Du hast in kurzer Zeit sehr viele Protokolle hochgeladen. Bitte versuche es später erneut.';
    }

    if (!$errors) {
        $submission = random_token(8);
        $status = $moderation ? 'pending' : 'published';
        $createdIds = [];
        $newExaminers = [];
        try {
            foreach ($parsed as $b) {
                if ($b['isNew']) {
                    $created = false;
                    $eid = Protocols::findOrCreateExaminer((int)$exam['id'], $b['newName'], $b['newSubject'], (int)$user['id'], $isStaff, $created);
                    if ($created) {
                        $newExaminers[] = $b['newName'];
                    }
                } else {
                    $eid = (int)$b['examinerId'];
                }
                $exName = (string)DB::val('SELECT name FROM examiners WHERE id = ?', [$eid]);
                $pid = Protocols::create([
                    'examiner_id' => $eid,
                    'user_id' => (int)$user['id'],
                    'submission_id' => $submission,
                    'title' => $b['title'] !== '' ? $b['title'] : Protocols::defaultTitle($exName, $examDate ?: null),
                    'exam_date' => $examDate ?: null,
                    'grade' => $grade,
                    'content' => $b['content'],
                    'status' => $status,
                    'source' => 'upload',
                ]);
                $createdIds[] = $pid;
                foreach ($b['files'] as $f) {
                    Protocols::storeFile($pid, $f['tmp_name'], $f['name'], 'upload');
                }
            }
        } catch (Throwable $e) {
            foreach ($createdIds as $pid) {
                Protocols::delete($pid);
            }
            app_log('error', 'Upload fehlgeschlagen: ' . $e->getMessage());
            $errors[] = 'Beim Speichern ist ein Fehler aufgetreten: ' . $e->getMessage();
        }

        if (!$errors) {
            Auth::hitAttempt('upload:' . $user['id']);
            log_activity('protocol_uploaded', count($createdIds) . ' Protokoll(e) für ' . $exam['name'] . ($newExaminers ? ', neue Prüfer: ' . implode(', ', $newExaminers) : ''));
            $refund = Payments::refundForProtocol($createdIds[0]);

            if (Settings::bool('notify_uploads')) {
                $lines = [
                    $user['name'] . ' (' . $user['email'] . ') hat ' . plural(count($createdIds), 'Protokoll', 'Protokolle') . ' für ' . $exam['name'] . ' hochgeladen.',
                    $status === 'pending' ? 'Die Protokolle warten auf Freigabe.' : 'Die Protokolle wurden sofort veröffentlicht.',
                ];
                if ($newExaminers) {
                    $lines[] = 'Neu angelegte Prüfer: ' . implode(', ', $newExaminers);
                }
                Mails::admin('Neues Protokoll hochgeladen', $lines, url('admin/protocols', ['status' => $status === 'pending' ? 'pending' : null]));
            }

            $msg = 'Vielen Dank für dein Protokoll!';
            $msg .= $status === 'pending'
                ? ' Es wird jetzt von der Fachschaft geprüft und danach freigeschaltet.'
                : ' Es ist ab sofort für andere Studierende sichtbar.';
            $refundMsg = Payments::refundMessage($refund);
            if ($refundMsg !== '') {
                $msg .= ' ' . $refundMsg;
            }
            flash(($refund['status'] ?? '') === 'failed' ? 'warning' : 'success', $msg);
            redirect('account', ['tab' => 'uploads']);
        }
    }
}

$jsData = [
    'examiners' => (object)$byExam,
    'exams' => (object)array_map(fn($x) => ['max' => Payments::maxExaminers($x), 'refund' => $refundInfo[(int)$x['id']] ?? ''], $examMap),
    'allowNew' => $allowNew,
];
$accept = implode(',', array_map(fn($x) => '.' . $x, $exts));
$selectedExamId = (int)($values['exam_id'] ?: 0);
?>
<div class="container">
  <div class="page-head">
    <h1>Gedächtnisprotokoll hochladen</h1>
    <p class="lead">Danke, dass du deine Erfahrungen teilst! Dein Protokoll wird anderen Studierenden immer anonym angezeigt.</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div><strong>Bitte prüfe deine Eingaben:</strong><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <?php if (!$exams): ?>
    <div class="card empty-state"><p>Es sind noch keine Prüfungen angelegt.</p></div>
  <?php else: ?>
  <form method="post" action="<?= e(url('upload')) ?>" enctype="multipart/form-data" data-upload-form>
    <?= csrf_field() ?>
    <div class="layout-sidebar">
      <div>
        <div class="card mb-2">
          <h2 style="font-size:1.15rem">1. Deine Prüfung</h2>
          <div class="form-row form-row-3">
            <div class="field">
              <label for="f_exam_id">Prüfung <span class="req">*</span></label>
              <select class="input" name="exam_id" id="f_exam_id" required>
                <option value="">– bitte wählen –</option>
                <?php foreach ($exams as $x): ?>
                  <option value="<?= (int)$x['id'] ?>"<?= selected($x['id'], $selectedExamId) ?>><?= e($x['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?= field_text('exam_date', 'Prüfungsdatum', $values['exam_date'], ['type' => 'date', 'attrs' => ['max' => date('Y-m-d')]]) ?>
            <?= field_select('grade', 'Note (optional)', ['' => '– keine Angabe –', '1' => '1 (sehr gut)', '2' => '2 (gut)', '3' => '3 (befriedigend)', '4' => '4 (ausreichend)', 'bestanden' => 'bestanden', 'nicht bestanden' => 'nicht bestanden'], $values['grade']) ?>
          </div>
        </div>

        <h2 style="font-size:1.15rem">2. Prüfer &amp; Protokoll</h2>
        <p class="muted small">Wurdest du von mehreren Prüfern geprüft? Lege für jeden Prüfer einen eigenen Abschnitt an – so finden andere die Fragen zu ihrem Prüfer.</p>

        <?php for ($i = 0; $i < $maxBlocks; $i++): ?>
          <?php $bv = $values['blocks'][$i] ?? ['examiner_id' => '', 'new_name' => '', 'new_subject' => '', 'title' => '', 'content' => '']; ?>
          <div class="upload-block" data-block>
            <div class="upload-block-head">
              <h3><span class="block-num"><?= $i + 1 ?></span> Prüfer</h3>
              <button type="button" class="btn btn-ghost btn-sm<?= $i === 0 ? ' hidden' : '' ?>" data-remove-block><?= icon('x') ?> Entfernen</button>
            </div>
            <div class="field">
              <label for="f_block_<?= $i ?>_examiner">Prüfer<?= $i === 0 ? ' <span class="req">*</span>' : '' ?></label>
              <select class="input" name="blocks[<?= $i ?>][examiner_id]" id="f_block_<?= $i ?>_examiner" data-examiner-select data-value="<?= e($bv['examiner_id']) ?>">
                <option value="">– Prüfer auswählen –</option>
                <?php foreach ($exams as $x): ?>
                  <?php if (empty($byExam[(int)$x['id']])) continue; ?>
                  <optgroup label="<?= e($x['name']) ?>">
                    <?php foreach ($byExam[(int)$x['id']] as $ex): ?>
                      <option value="<?= $ex['id'] ?>"<?= selected($ex['id'], $bv['examiner_id']) ?>><?= e($ex['name'] . ($ex['subject'] !== '' ? ' (' . $ex['subject'] . ')' : '')) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
                <?php if ($allowNew): ?>
                  <option value="__new"<?= selected('__new', $bv['examiner_id']) ?>>＋ Prüfer ist nicht in der Liste – neu anlegen</option>
                <?php endif; ?>
              </select>
            </div>
            <?php if ($allowNew): ?>
              <div class="new-examiner<?= $bv['examiner_id'] === '__new' ? '' : ' hidden' ?>">
                <div class="form-row">
                  <?= field_text('blocks[' . $i . '][new_name]', 'Name des neuen Prüfers', $bv['new_name'], ['placeholder' => 'z. B. Prof. Dr. Erika Mustermann', 'attrs' => ['data-new-name' => true, 'data-required-new' => true, 'list' => null]]) ?>
                  <?= field_text('blocks[' . $i . '][new_subject]', 'Fach', $bv['new_subject'], ['placeholder' => 'z. B. Anatomie', 'attrs' => ['list' => 'subject-list']]) ?>
                </div>
                <div class="suggestions"></div>
                <small class="help">Der Prüfer wird automatisch angelegt. Ist er schon vorhanden (z. B. ohne Titel geschrieben), ordnen wir dein Protokoll dem bestehenden Eintrag zu.</small>
              </div>
            <?php endif; ?>
            <?= field_text('blocks[' . $i . '][title]', 'Titel (optional)', $bv['title'], ['placeholder' => 'Wird automatisch erzeugt, z. B. „Protokoll Prof. Muster – März 2026“']) ?>
            <?= field_textarea('blocks[' . $i . '][content]', 'Dein Gedächtnisprotokoll', $bv['content'], [
                'rows' => 12,
                'placeholder' => "Welche Fragen wurden gestellt? Was wollte der Prüfer hören?\nWie war die Atmosphäre, gab es eine Vorbesprechung, wie lief die praktische Prüfung?\nTipps für die Vorbereitung …",
                'help' => 'Einfache Formatierung möglich: **fett**, Aufzählungen mit „- “, Überschriften mit „# “.' . ($minLen > 0 ? ' Mindestens ' . $minLen . ' Zeichen, falls du keine Datei hochlädst.' : ''),
            ]) ?>
            <div class="field mb-0">
              <label>Dateien (optional)</label>
              <div class="dropzone">
                <input type="file" name="files<?= $i ?>[]" multiple accept="<?= e($accept) ?>">
                <small class="help">Bis zu <?= $maxFiles ?> Dateien, je max. <?= e(human_filesize($maxBytes)) ?>. Erlaubt: <?= e(implode(', ', $exts)) ?></small>
                <ul class="file-names"></ul>
              </div>
            </div>
          </div>
        <?php endfor; ?>
        <button type="button" class="btn btn-secondary mb-3 hidden" data-add-block><?= icon('plus') ?> Weiteren Prüfer hinzufügen</button>

        <div class="card">
          <h2 style="font-size:1.15rem">3. Absenden</h2>
          <label class="checkbox">
            <input type="checkbox" name="rights" value="1" required>
            <span>Ich habe dieses Gedächtnisprotokoll selbst verfasst und räume <?= e(setting('org_name')) ?> das Recht ein, es anderen Studierenden über diese Plattform anonym zugänglich zu machen (siehe <a href="<?= e(url('page', ['slug' => 'nutzungsbedingungen'])) ?>" target="_blank">Nutzungsbedingungen</a>).</span>
          </label>
          <?php if ($moderation): ?>
            <p class="small muted">Neue Protokolle werden vor der Veröffentlichung kurz von der Fachschaft geprüft.</p>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary btn-lg"><?= icon('upload') ?> Protokoll hochladen</button>
        </div>
      </div>

      <aside class="sticky">
        <div class="refund-hint mb-2<?= isset($refundInfo[$selectedExamId]) ? '' : ' hidden' ?>" style="display:block" data-refund-info><?= $refundInfo[$selectedExamId] ?? '' ?></div>
        <div class="card">
          <h3>Was gehört in ein gutes Protokoll?</h3>
          <ul class="summary-list">
            <li><?= icon('check') ?> Alle Fragen, so genau wie möglich</li>
            <li><?= icon('check') ?> Was der Prüfer hören wollte</li>
            <li><?= icon('check') ?> Ablauf, Atmosphäre, Dauer</li>
            <li><?= icon('check') ?> Praktischer Teil / Patientenvorstellung</li>
            <li><?= icon('check') ?> Tipps zur Vorbereitung</li>
          </ul>
          <p class="small muted mb-0">Am besten schreibst du dein Protokoll direkt nach der Prüfung, solange alles noch frisch ist.</p>
        </div>
        <div class="card card-muted">
          <h3><?= icon('shield') ?> Anonym</h3>
          <p class="small muted mb-0">Andere Studierende sehen nie, wer ein Protokoll hochgeladen hat. Nur die Fachschaft kann es zuordnen (wegen der Rückerstattung).</p>
        </div>
      </aside>
    </div>
    <datalist id="subject-list">
      <?php foreach ($subjects as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
    </datalist>
    <script type="application/json" id="upload-data"><?= json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
  </form>
  <?php endif; ?>
</div>
