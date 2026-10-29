<?php
/** Admin: Einzelnes Konto bearbeiten, Zugang gewähren, Aktivität einsehen. */
View::$active = 'users';
$id = is_post() ? post_int('id') : get_int('id');
$u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
if (!$u) {
    abort(404);
}
View::$title = $u['email'];
$me = Auth::user();
$isSelf = (int)$u['id'] === (int)$me['id'];
$adminCount = (int)DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_blocked = 0");
$action = post_str('action');

if (is_post()) {
    switch ($action) {
        case 'save':
            $email = mb_strtolower(post_str('email'));
            $role = in_array(post_str('role'), ['student', 'moderator', 'admin'], true) ? post_str('role') : 'student';
            $blocked = post_bool('is_blocked') ? 1 : 0;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Ungültige E-Mail-Adresse.');
                break;
            }
            if (DB::val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
                flash('error', 'Diese E-Mail-Adresse wird bereits von einem anderen Konto verwendet.');
                break;
            }
            if ($u['role'] === 'admin' && ($role !== 'admin' || $blocked) && $adminCount <= 1) {
                flash('error', 'Es muss mindestens ein aktiver Admin bestehen bleiben.');
                break;
            }
            if ($isSelf && ($blocked || $role !== 'admin')) {
                flash('error', 'Du kannst dich nicht selbst sperren oder deine Admin-Rolle entfernen.');
                break;
            }
            DB::update('users', [
                'name' => post_str('name'),
                'email' => $email,
                'role' => $role,
                'is_verified' => post_bool('is_verified') ? 1 : 0,
                'verified_at' => post_bool('is_verified') ? ($u['verified_at'] ?: now()) : null,
                'is_blocked' => $blocked,
                'admin_note' => post_str('admin_note'),
            ], 'id = ?', [$u['id']]);
            log_activity('user_updated', $email . ' (Rolle: ' . $role . ($blocked ? ', gesperrt' : '') . ')');
            flash('success', 'Das Konto wurde gespeichert.');
            break;

        case 'reset':
            Auth::sendPasswordReset($u['email']);
            flash('success', 'Eine E-Mail zum Zurücksetzen des Passworts wurde an ' . $u['email'] . ' gesendet.');
            break;

        case 'resend':
            Auth::sendVerification($u);
            flash('success', 'Die Bestätigungs-E-Mail wurde erneut gesendet.');
            break;

        case 'grant':
            $examId = post_int('exam_id');
            $exam = DB::one('SELECT * FROM exams WHERE id = ?', [$examId]);
            $ids = array_values(array_unique(array_map('intval', post_array('examiners'))));
            $valid = $exam ? array_map('intval', DB::col('SELECT id FROM examiners WHERE exam_id = ?', [$examId])) : [];
            $ids = array_values(array_filter($ids, fn($i) => in_array($i, $valid, true)));
            $amount = post_str('amount') === '' ? 0 : parse_money(post_str('amount'));
            if (!$exam || !$ids) {
                flash('error', 'Bitte wähle eine Prüfung und mindestens einen Prüfer dieser Prüfung aus.');
            } elseif (count($ids) > Payments::maxExaminers($exam)) {
                flash('error', 'Höchstens ' . Payments::maxExaminers($exam) . ' Prüfer pro Zugang.');
            } elseif ($amount === null) {
                flash('error', 'Ungültiger Betrag.');
            } else {
                $pid = Payments::grantManual((int)$u['id'], $examId, $ids, $amount, post_str('note'));
                flash('success', 'Zugang gewährt (Kauf #' . $pid . ').');
            }
            break;

        case 'delete':
            if ($isSelf || ($u['role'] === 'admin' && $adminCount <= 1)) {
                flash('error', 'Dieses Konto kann nicht gelöscht werden.');
                break;
            }
            $hasPurchases = (int)DB::val('SELECT COUNT(*) FROM purchases WHERE user_id = ?', [$u['id']]) > 0;
            if ($hasPurchases) {
                DB::update('users', [
                    'email' => 'geloescht-' . $u['id'] . '-' . random_token(4) . '@konto.invalid',
                    'name' => 'Gelöschtes Konto',
                    'password_hash' => password_hash(random_token(), PASSWORD_DEFAULT),
                    'role' => 'student',
                    'is_blocked' => 1,
                    'verify_token' => null,
                    'reset_token' => null,
                ], 'id = ?', [$u['id']]);
                log_activity('user_anonymized', 'Konto #' . $u['id']);
                flash('success', 'Das Konto hatte Käufe und wurde daher anonymisiert (Buchungsdaten bleiben erhalten).');
                redirect('admin/users');
            }
            DB::q('UPDATE protocols SET user_id = NULL WHERE user_id = ?', [$u['id']]);
            DB::q('UPDATE examiners SET created_by = NULL WHERE created_by = ?', [$u['id']]);
            DB::delete('users', 'id = ?', [$u['id']]);
            log_activity('user_deleted', $u['email']);
            flash('success', 'Das Konto wurde gelöscht.');
            redirect('admin/users');
    }
    redirect('admin/user', ['id' => $u['id']]);
}

$purchases = DB::all(
    "SELECT p.*, x.short_name, x.name AS exam_name, (SELECT COUNT(*) FROM purchase_examiners pe WHERE pe.purchase_id = p.id) AS examiner_count
     FROM purchases p JOIN exams x ON x.id = p.exam_id WHERE p.user_id = ? ORDER BY p.id DESC",
    [$u['id']]
);
$uploads = DB::all(
    'SELECT p.*, e.name AS examiner_name FROM protocols p JOIN examiners e ON e.id = p.examiner_id WHERE p.user_id = ? ORDER BY p.created_at DESC',
    [$u['id']]
);
$downloads = DB::all(
    'SELECT d.*, p.title FROM download_log d LEFT JOIN protocols p ON p.id = d.protocol_id WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 25',
    [$u['id']]
);
$downloadTotal = (int)DB::val('SELECT COUNT(*) FROM download_log WHERE user_id = ?', [$u['id']]);
$activity = DB::all('SELECT * FROM activity_log WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$u['id']]);
$examinerGroups = [];
foreach (DB::all('SELECT e.id, e.name, e.exam_id, x.name AS exam_name FROM examiners e JOIN exams x ON x.id = e.exam_id WHERE e.is_active = 1 ORDER BY x.sort_order, e.name') as $ex) {
    $examinerGroups[$ex['exam_id']]['name'] = $ex['exam_name'];
    $examinerGroups[$ex['exam_id']]['items'][] = $ex;
}
$examOptions = ['' => '– Prüfung wählen –'];
foreach ($examinerGroups as $eid => $g) {
    $examOptions[$eid] = $g['name'];
}
?>
<?= admin_head($u['email'], '<a href="' . e(url('admin/users')) . '">← Alle Nutzer</a> · registriert am ' . e(fmt_date($u['created_at'])) . ($u['last_login_at'] ? ' · letzter Login ' . e(time_ago($u['last_login_at'])) : '')) ?>

<div class="grid grid-2" style="align-items:start">
  <div>
    <form method="post" action="<?= e(url('admin/user')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <h3>Konto</h3>
      <div class="form-row">
        <?= field_text('name', 'Name', $u['name']) ?>
        <?= field_text('email', 'E-Mail-Adresse', $u['email'], ['type' => 'email', 'required' => true]) ?>
      </div>
      <?= field_select('role', 'Rolle', ['student' => 'Studierende*r', 'moderator' => 'Moderation (Protokolle, Prüfer, Import)', 'admin' => 'Admin (alles)'], $u['role']) ?>
      <?= field_toggle('is_verified', 'E-Mail-Adresse bestätigt', (bool)(int)$u['is_verified']) ?>
      <?= field_toggle('is_blocked', 'Konto gesperrt', (bool)(int)$u['is_blocked'], 'Gesperrte Konten können sich nicht mehr anmelden und verlieren den Zugriff.') ?>
      <?= field_textarea('admin_note', 'Interne Notiz', (string)$u['admin_note'], ['rows' => 2]) ?>
      <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
    </form>

    <div class="card">
      <h3>Aktionen</h3>
      <div class="btn-group">
        <?= post_button(url('admin/user'), icon('mail') . ' Passwort-Link senden', ['id' => $u['id'], 'action' => 'reset']) ?>
        <?php if (!(int)$u['is_verified']): ?><?= post_button(url('admin/user'), icon('refresh') . ' Bestätigung erneut senden', ['id' => $u['id'], 'action' => 'resend']) ?><?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/protocols', ['user' => $u['id']])) ?>"><?= icon('file-text') ?> Uploads</a>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/purchases', ['q' => $u['email']])) ?>"><?= icon('euro') ?> Käufe</a>
      </div>
    </div>

    <form method="post" action="<?= e(url('admin/user')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="grant">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <h3><?= icon('unlock') ?> Zugang manuell gewähren</h3>
      <p class="small muted">Z. B. bei Barzahlung im Fachschaftsbüro oder als Freizugang. Wird ein Betrag eingetragen, wird eine spätere Rückerstattung als „manuell auszuzahlen“ markiert.</p>
      <?= field_select('exam_id', 'Prüfung', $examOptions, '', ['required' => true]) ?>
      <?php foreach ($examinerGroups as $eid => $g): ?>
        <div class="field" data-show-when="exam_id=<?= (int)$eid ?>">
          <label>Prüfer (<?= e($g['name']) ?>)</label>
          <div class="pick-list">
            <?php foreach ($g['items'] as $ex): ?>
              <label class="pick" style="padding:8px 12px"><input type="checkbox" name="examiners[]" value="<?= (int)$ex['id'] ?>"><span class="info"><span class="name" style="font-weight:500"><?= e($ex['name']) ?></span></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="form-row">
        <?= field_text('amount', 'Bezahlter Betrag', '', ['placeholder' => '0,00', 'help' => 'Leer = kostenlos']) ?>
        <?= field_text('note', 'Notiz', '', ['placeholder' => 'z. B. bar bezahlt am …']) ?>
      </div>
      <button type="submit" class="btn btn-primary">Zugang gewähren</button>
    </form>
  </div>

  <div>
    <div class="card card-flush">
      <div class="card-header"><h3>Käufe (<?= count($purchases) ?>)</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($purchases as $p): ?>
          <tr>
            <td><a href="<?= e(url('admin/purchase', ['id' => $p['id']])) ?>">#<?= (int)$p['id'] ?> · <?= e($p['short_name'] ?: $p['exam_name']) ?></a><span class="table-sub"><?= e(fmt_date($p['paid_at'] ?: $p['created_at'])) ?> · <?= (int)$p['examiner_count'] ?> Prüfer · <?= e(Payments::providerLabel($p['provider'])) ?></span></td>
            <td class="num"><?= e(money($p['amount_cents'], $p['currency'])) ?></td>
            <td><?= Payments::statusBadge($p['status']) ?> <?= Payments::refundBadge($p['refund_status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$purchases): ?><tr><td class="muted">Keine Käufe.</td></tr><?php endif; ?>
      </tbody></table>
    </div>

    <div class="card card-flush">
      <div class="card-header"><h3>Hochgeladene Protokolle (<?= count($uploads) ?>)</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($uploads as $p): ?>
          <tr><td><a href="<?= e(url('admin/protocol', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a><span class="table-sub"><?= e($p['examiner_name'] . ' · ' . fmt_date($p['created_at'])) ?></span></td><td><?= protocol_status_badge($p['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$uploads): ?><tr><td class="muted">Keine Uploads.</td></tr><?php endif; ?>
      </tbody></table>
    </div>

    <div class="card card-flush">
      <div class="card-header"><h3>Abrufe &amp; Downloads (<?= $downloadTotal ?>)</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($downloads as $d): ?>
          <tr><td><?= e($d['title'] ?: (str_starts_with($d['kind'], 'zip') ? 'ZIP-Download' : '–')) ?><span class="table-sub"><?= e(['view' => 'Ansicht', 'download' => 'Download'][$d['kind']] ?? 'ZIP') ?></span></td><td class="small nowrap muted"><?= e(fmt_datetime($d['created_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$downloads): ?><tr><td class="muted">Noch keine Abrufe.</td></tr><?php endif; ?>
      </tbody></table>
    </div>

    <div class="card card-flush">
      <div class="card-header"><h3>Aktivität</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($activity as $a): ?>
          <tr><td><strong><?= e(activity_label($a['action'])) ?></strong><span class="table-sub"><?= e(str_excerpt($a['details'], 100)) ?></span></td><td class="small nowrap muted"><?= e(fmt_datetime($a['created_at'])) ?><span class="table-sub"><?= e($a['ip']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$activity): ?><tr><td class="muted">Keine Einträge.</td></tr><?php endif; ?>
      </tbody></table>
    </div>

    <?php if (!$isSelf): ?>
      <form method="post" action="<?= e(url('admin/user')) ?>" class="card danger-zone" data-confirm="Konto wirklich löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <h3>Konto löschen</h3>
        <p class="small muted">Konten mit Käufen werden anonymisiert statt gelöscht. Hochgeladene Protokolle bleiben anonym erhalten.</p>
        <button type="submit" class="btn btn-outline-danger btn-sm"><?= icon('trash') ?> Löschen</button>
      </form>
    <?php endif; ?>
  </div>
</div>
