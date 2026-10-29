<?php
/** Admin: Nutzer verwalten. */
View::$title = 'Nutzer';
View::$active = 'users';

if (is_post() && post_str('action') === 'create') {
    $email = mb_strtolower(post_str('email'));
    $name = post_str('name');
    $role = in_array(post_str('role'), ['student', 'moderator', 'admin'], true) ? post_str('role') : 'student';
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Bitte gib eine gültige E-Mail-Adresse ein.');
    } elseif (DB::val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        flash('error', 'Es gibt bereits ein Konto mit dieser E-Mail-Adresse.');
    } elseif ($password !== '' && mb_strlen($password) < 8) {
        flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein (oder leer lassen und Einladung senden).');
    } else {
        $id = DB::insert('users', [
            'email' => $email,
            'name' => $name !== '' ? $name : explode('@', $email)[0],
            'password_hash' => password_hash($password !== '' ? $password : random_token(), PASSWORD_DEFAULT),
            'role' => $role,
            'is_verified' => 1,
            'verified_at' => now(),
            'created_at' => now(),
        ]);
        log_activity('user_created', $email . ' (' . $role . ')');
        if ($password === '' || post_bool('invite')) {
            Auth::sendPasswordReset($email);
            flash('success', 'Das Konto wurde angelegt und eine E-Mail zum Festlegen des Passworts verschickt.');
        } else {
            flash('success', 'Das Konto wurde angelegt.');
        }
        redirect('admin/user', ['id' => $id]);
    }
    redirect('admin/users');
}

$q = get_str('q');
$role = get_str('role');
$status = get_str('status');
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.email LIKE ? OR u.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($role, ['student', 'moderator', 'admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
if ($status === 'unverified') {
    $where[] = 'u.is_verified = 0 AND u.is_blocked = 0';
} elseif ($status === 'blocked') {
    $where[] = 'u.is_blocked = 1';
} elseif ($status === 'buyers') {
    $where[] = "EXISTS (SELECT 1 FROM purchases p WHERE p.user_id = u.id AND p.status = 'paid')";
}
$whereSql = implode(' AND ', $where);
$total = (int)DB::val("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
$pg = paginate($total, 50);
$rows = DB::all(
    "SELECT u.*,
        (SELECT COUNT(*) FROM purchases p WHERE p.user_id = u.id AND p.status = 'paid') AS purchase_count,
        (SELECT COUNT(*) FROM protocols p WHERE p.user_id = u.id) AS upload_count,
        (SELECT COUNT(*) FROM download_log d WHERE d.user_id = u.id) AS download_count
     FROM users u WHERE $whereSql ORDER BY u.created_at DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'],
    $params
);
?>
<?= admin_head('Nutzer', 'Registrierte Studierende und das Team (Moderation/Admin).') ?>

<details class="card mb-2">
  <summary><?= icon('plus') ?> Konto manuell anlegen (z. B. für Fachschaftsmitglieder oder Ehemalige ohne Uni-Adresse)</summary>
  <form method="post" action="<?= e(url('admin/users')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <?= field_text('name', 'Name', '') ?>
      <?= field_text('email', 'E-Mail-Adresse', '', ['type' => 'email', 'required' => true, 'help' => 'Die Domain-Beschränkung gilt hier nicht.']) ?>
    </div>
    <div class="form-row">
      <?= field_text('password', 'Passwort (optional)', '', ['type' => 'password', 'help' => 'Leer lassen: Die Person bekommt eine E-Mail und legt das Passwort selbst fest.', 'attrs' => ['autocomplete' => 'new-password']]) ?>
      <?= field_select('role', 'Rolle', ['student' => 'Studierende*r', 'moderator' => 'Moderation (Protokolle, Prüfer, Import)', 'admin' => 'Admin (alles)'], 'student') ?>
    </div>
    <label class="checkbox"><input type="checkbox" name="invite" value="1"> Einladungs-E-Mail zum Festlegen des Passworts senden</label>
    <button type="submit" class="btn btn-primary">Konto anlegen</button>
  </form>
</details>

<form method="get" action="<?= e(base_url() . 'index.php') ?>" class="filters">
  <input type="hidden" name="p" value="admin/users">
  <div class="field grow"><label>Suche</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="E-Mail oder Name"></div>
  <?= field_select('role', 'Rolle', ['' => 'Alle', 'student' => 'Studierende', 'moderator' => 'Moderation', 'admin' => 'Admin'], $role, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('status', 'Status', ['' => 'Alle', 'buyers' => 'Mit Kauf', 'unverified' => 'Nicht bestätigt', 'blocked' => 'Gesperrt'], $status, ['attrs' => ['data-autosubmit' => true]]) ?>
  <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Filtern</button></div>
</form>

<div class="card card-flush">
  <div class="card-header"><span class="muted small"><?= e(plural($total, 'Konto', 'Konten')) ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nutzer</th><th>Rolle</th><th>Status</th><th class="num">Käufe</th><th class="num">Uploads</th><th class="num">Abrufe</th><th>Registriert</th><th>Letzter Login</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $u): ?>
          <tr>
            <td><a href="<?= e(url('admin/user', ['id' => $u['id']])) ?>"><strong><?= e($u['email']) ?></strong></a><span class="table-sub"><?= e($u['name']) ?></span></td>
            <td><?= $u['role'] === 'student' ? '<span class="muted small">Studierende*r</span>' : badge(role_label($u['role']), 'primary') ?></td>
            <td><?= (int)$u['is_blocked'] ? badge('Gesperrt', 'danger') : ((int)$u['is_verified'] ? badge('Bestätigt', 'success') : badge('Unbestätigt', 'warning')) ?><?= (int)$u['is_demo'] ? ' ' . badge('Demo', 'info') : '' ?></td>
            <td class="num"><?= (int)$u['purchase_count'] ?></td>
            <td class="num"><?= (int)$u['upload_count'] ?></td>
            <td class="num"><?= (int)$u['download_count'] ?></td>
            <td class="small nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
            <td class="small nowrap"><?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : '–') ?></td>
            <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/user', ['id' => $u['id']])) ?>"><?= icon('edit') ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine Nutzer gefunden.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= pagination_links($pg) ?>
