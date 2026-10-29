<?php
/** Admin: Prüfung anlegen / bearbeiten. */
View::$active = 'exams';
$id = is_post() ? post_int('id') : get_int('id');
$exam = $id ? DB::one('SELECT * FROM exams WHERE id = ?', [$id]) : null;
if ($id && !$exam) {
    abort(404);
}
View::$title = $exam ? 'Prüfung bearbeiten' : 'Neue Prüfung';
$errors = [];
$v = $exam ?? [
    'name' => '', 'short_name' => '', 'slug' => '', 'description' => '', 'color' => '#0d6e78',
    'price_cents' => null, 'refund_cents' => null, 'max_examiners' => Settings::int('default_max_examiners'),
    'is_active' => 1, 'sort_order' => (int)DB::val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM exams'),
];

if (is_post() && post_str('action') === 'delete' && $exam) {
    $examiners = (int)DB::val('SELECT COUNT(*) FROM examiners WHERE exam_id = ?', [$exam['id']]);
    $purchases = (int)DB::val('SELECT COUNT(*) FROM purchases WHERE exam_id = ?', [$exam['id']]);
    if ($examiners || $purchases) {
        flash('error', 'Die Prüfung hat noch ' . $examiners . ' Prüfer und ' . $purchases . ' Käufe und kann nicht gelöscht werden. Deaktiviere sie stattdessen.');
        redirect('admin/exam', ['id' => $exam['id']]);
    }
    DB::delete('exams', 'id = ?', [$exam['id']]);
    log_activity('exam_deleted', $exam['name']);
    flash('success', 'Die Prüfung wurde gelöscht.');
    redirect('admin/exams');
}

if (is_post() && post_str('action') === 'save') {
    $v = [
        'name' => post_str('name'),
        'short_name' => mb_substr(post_str('short_name'), 0, 30),
        'slug' => slugify(post_str('slug') !== '' ? post_str('slug') : (post_str('short_name') !== '' ? post_str('short_name') : post_str('name'))),
        'description' => post_str('description'),
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', post_str('color')) ? post_str('color') : '#0d6e78',
        'price_cents' => post_str('price') === '' ? null : parse_money(post_str('price')),
        'refund_cents' => post_str('refund') === '' ? null : parse_money(post_str('refund')),
        'max_examiners' => max(1, min(10, post_int('max_examiners', 4))),
        'is_active' => post_bool('is_active') ? 1 : 0,
        'sort_order' => post_int('sort_order'),
    ];
    if (mb_strlen($v['name']) < 2) {
        $errors[] = 'Bitte gib einen Namen ein.';
    }
    if (post_str('price') !== '' && $v['price_cents'] === null) {
        $errors[] = 'Der Preis ist ungültig (Beispiel: 30,00).';
    }
    if (post_str('refund') !== '' && $v['refund_cents'] === null) {
        $errors[] = 'Der Erstattungsbetrag ist ungültig (Beispiel: 29,00).';
    }
    $price = $v['price_cents'] ?? Settings::int('price_cents');
    $refund = $v['refund_cents'] ?? Settings::int('refund_cents');
    if ($refund > $price) {
        $errors[] = 'Die Erstattung darf nicht höher sein als der Preis.';
    }
    if ($price > 0 && $price < 50) {
        $errors[] = 'Stripe erfordert einen Mindestbetrag von 0,50 €.';
    }
    if ((int)DB::val('SELECT COUNT(*) FROM exams WHERE slug = ? AND id <> ?', [$v['slug'], $exam['id'] ?? 0])) {
        $errors[] = 'Das Kürzel für die Adresse („' . $v['slug'] . '“) ist bereits vergeben.';
    }
    if (!$errors) {
        if ($exam) {
            DB::update('exams', $v, 'id = ?', [$exam['id']]);
            log_activity('exam_updated', $v['name']);
            flash('success', 'Die Prüfung wurde gespeichert.');
            redirect('admin/exam', ['id' => $exam['id']]);
        }
        $newId = DB::insert('exams', $v + ['created_at' => now()]);
        log_activity('exam_created', $v['name']);
        flash('success', 'Die Prüfung wurde angelegt. Jetzt kannst du Prüfer hinzufügen.');
        redirect('admin/exam', ['id' => $newId]);
    }
}
$examiners = $exam ? Protocols::examinersForExam((int)$exam['id'], true) : [];
?>
<?= admin_head($exam ? $exam['name'] : 'Neue Prüfung', '<a href="' . e(url('admin/exams')) . '">← Alle Prüfungen</a>', $exam ? '<a class="btn btn-secondary" href="' . e(url('exam', ['id' => $exam['id']])) . '">' . icon('eye') . ' Ansehen</a>' : '') ?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($err) ?></div></div><?php endforeach; ?>

<div class="grid grid-2" style="align-items:start">
  <form method="post" action="<?= e(url('admin/exam')) ?>" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($exam['id'] ?? 0) ?>">
    <div class="form-row">
      <?= field_text('name', 'Name', $v['name'], ['required' => true, 'placeholder' => 'z. B. Physikum (M1)']) ?>
      <?= field_text('short_name', 'Kurzname', $v['short_name'], ['placeholder' => 'z. B. M1', 'help' => 'Wird als Symbol angezeigt.']) ?>
    </div>
    <?= field_textarea('description', 'Beschreibung', $v['description'], ['rows' => 3]) ?>
    <div class="form-row">
      <?= field_text('price', 'Preis (leer = Standard)', $v['price_cents'] !== null ? money_input($v['price_cents']) : '', ['placeholder' => money_input(Settings::int('price_cents')), 'help' => 'in Euro, z. B. 30,00']) ?>
      <?= field_text('refund', 'Erstattung bei Upload (leer = Standard)', $v['refund_cents'] !== null ? money_input($v['refund_cents']) : '', ['placeholder' => money_input(Settings::int('refund_cents'))]) ?>
    </div>
    <div class="form-row form-row-3">
      <?= field_text('max_examiners', 'Max. Prüfer pro Kauf', $v['max_examiners'], ['type' => 'number', 'attrs' => ['min' => 1, 'max' => 10]]) ?>
      <?= field_text('sort_order', 'Reihenfolge', $v['sort_order'], ['type' => 'number']) ?>
      <?= field_text('color', 'Farbe', $v['color'], ['type' => 'color', 'attrs' => ['style' => 'height:44px;padding:4px']]) ?>
    </div>
    <?= field_text('slug', 'Adresskürzel', $v['slug'], ['help' => 'Wird automatisch aus dem Kurznamen erzeugt.']) ?>
    <?= field_toggle('is_active', 'Prüfung ist aktiv (für Studierende sichtbar)', (bool)$v['is_active']) ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
      <a class="btn btn-secondary" href="<?= e(url('admin/exams')) ?>">Abbrechen</a>
    </div>
  </form>

  <?php if ($exam): ?>
    <div>
      <div class="card card-flush">
        <div class="card-header">
          <h3>Prüfer dieser Prüfung (<?= count($examiners) ?>)</h3>
          <a class="btn btn-primary btn-sm" href="<?= e(url('admin/examiner', ['new' => 1, 'exam' => $exam['id']])) ?>"><?= icon('plus') ?> Prüfer</a>
        </div>
        <table class="table table-compact"><tbody>
          <?php foreach ($examiners as $ex): ?>
            <tr>
              <td><a href="<?= e(url('admin/examiner', ['id' => $ex['id']])) ?>"><?= e($ex['name']) ?></a><span class="table-sub"><?= e($ex['subject'] ?: '–') ?></span></td>
              <td class="num"><?= e(plural((int)$ex['protocol_count'], 'Protokoll', 'Protokolle')) ?></td>
              <td><?= (int)$ex['is_active'] ? '' : badge('inaktiv', 'neutral') ?><?= (int)$ex['needs_review'] ? badge('neu', 'warning') : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$examiners): ?><tr><td class="muted">Noch keine Prüfer.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
      <form method="post" action="<?= e(url('admin/exam')) ?>" class="card danger-zone" data-confirm="Prüfung endgültig löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$exam['id'] ?>">
        <h3>Prüfung löschen</h3>
        <p class="small muted">Nur möglich, wenn die Prüfung keine Prüfer und keine Käufe hat. Ansonsten bitte deaktivieren.</p>
        <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Löschen</button>
      </form>
    </div>
  <?php endif; ?>
</div>
