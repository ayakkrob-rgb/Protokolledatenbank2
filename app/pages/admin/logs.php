<?php
/** Admin: Aktivitätslog und Download-Protokoll. */
View::$title = 'Aktivitätslog';
View::$active = 'logs';
$tab = get_str('tab', 'activity');

if ($tab === 'downloads') {
    $since = date('Y-m-d H:i:s', time() - 30 * 86400);
    $top = DB::all(
        "SELECT d.user_id, u.email, u.name, COUNT(*) AS c,
            SUM(d.kind = 'download') AS downloads, SUM(d.kind = 'view') AS views, SUM(d.kind LIKE 'zip%') AS zips
         FROM download_log d JOIN users u ON u.id = d.user_id WHERE d.created_at >= ?
         GROUP BY d.user_id, u.email, u.name ORDER BY c DESC LIMIT 20",
        [$since]
    );
    $total = (int)DB::val('SELECT COUNT(*) FROM download_log');
    $pg = paginate($total, 50);
    $rows = DB::all(
        'SELECT d.*, u.email, p.title FROM download_log d LEFT JOIN users u ON u.id = d.user_id LEFT JOIN protocols p ON p.id = d.protocol_id
         ORDER BY d.id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset']
    );
} else {
    $action = get_str('action');
    $q = get_str('q');
    $where = ['1=1'];
    $params = [];
    if ($action !== '') {
        $where[] = 'a.action = ?';
        $params[] = $action;
    }
    if ($q !== '') {
        $where[] = '(u.email LIKE ? OR a.details LIKE ? OR a.ip = ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', $q);
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)DB::val("SELECT COUNT(*) FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql", $params);
    $pg = paginate($total, 50);
    $rows = DB::all(
        "SELECT a.*, u.email FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql ORDER BY a.id DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'],
        $params
    );
    $actions = DB::col('SELECT DISTINCT action FROM activity_log ORDER BY action');
    $actionOptions = ['' => 'Alle Aktionen'];
    foreach ($actions as $a) {
        $actionOptions[$a] = activity_label($a);
    }
}
?>
<?= admin_head('Aktivitätslog', 'Nachvollziehbarkeit aller wichtigen Aktionen und Abrufe.') ?>
<nav class="tabs">
  <a href="<?= e(url('admin/logs')) ?>" class="<?= $tab !== 'downloads' ? 'active' : '' ?>"><?= icon('activity') ?> Aktivitäten</a>
  <a href="<?= e(url('admin/logs', ['tab' => 'downloads'])) ?>" class="<?= $tab === 'downloads' ? 'active' : '' ?>"><?= icon('download') ?> Abrufe &amp; Downloads</a>
</nav>

<?php if ($tab === 'downloads'): ?>
  <div class="card card-flush mb-2">
    <div class="card-header"><h3>Meiste Abrufe (letzte 30 Tage)</h3><span class="small muted">Hilft, auffällige Massen-Downloads zu erkennen</span></div>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>Nutzer</th><th class="num">Gesamt</th><th class="num">Ansichten</th><th class="num">Downloads</th><th class="num">ZIP</th></tr></thead>
      <tbody>
        <?php foreach ($top as $t): ?>
          <tr><td><a href="<?= e(url('admin/user', ['id' => $t['user_id']])) ?>"><?= e($t['email']) ?></a><span class="table-sub"><?= e($t['name']) ?></span></td><td class="num"><strong><?= (int)$t['c'] ?></strong></td><td class="num"><?= (int)$t['views'] ?></td><td class="num"><?= (int)$t['downloads'] ?></td><td class="num"><?= (int)$t['zips'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="5" class="muted">Noch keine Abrufe.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card card-flush">
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>Zeit</th><th>Nutzer</th><th>Art</th><th>Protokoll</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
            <td><?= $r['email'] ? '<a href="' . e(url('admin/user', ['id' => $r['user_id']])) . '">' . e($r['email']) . '</a>' : '–' ?></td>
            <td><?= e(['view' => 'Ansicht', 'download' => 'Download'][$r['kind']] ?? 'ZIP-Download') ?></td>
            <td><?= $r['protocol_id'] ? '<a href="' . e(url('admin/protocol', ['id' => $r['protocol_id']])) . '">' . e($r['title'] ?: '#' . $r['protocol_id']) . '</a>' : '–' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php else: ?>
  <form method="get" action="<?= e(base_url() . 'index.php') ?>" class="filters">
    <input type="hidden" name="p" value="admin/logs">
    <div class="field grow"><label>Suche</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="E-Mail, Details oder IP"></div>
    <?= field_select('action', 'Aktion', $actionOptions, $action, ['attrs' => ['data-autosubmit' => true]]) ?>
    <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Filtern</button></div>
  </form>
  <div class="card card-flush">
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>Zeit</th><th>Aktion</th><th>Details</th><th>Nutzer</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
            <td><strong><?= e(activity_label($r['action'])) ?></strong></td>
            <td class="small"><?= e($r['details']) ?></td>
            <td class="small"><?= $r['email'] ? '<a href="' . e(url('admin/user', ['id' => $r['user_id']])) . '">' . e($r['email']) . '</a>' : '–' ?></td>
            <td class="small muted"><?= e($r['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Keine Einträge.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
<?= pagination_links($pg) ?>
