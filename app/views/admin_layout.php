<?php
/** Layout des Adminbereichs. Erwartet $content. */
$user = Auth::user();
$isAdmin = Auth::isAdmin();
$flashes = take_flashes();
$siteName = (string)setting('site_name');
$active = View::$active;
$flashIcons = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];

$counts = [
    'pending' => (int)DB::val("SELECT COUNT(*) FROM protocols WHERE status = 'pending'"),
    'review' => (int)DB::val('SELECT COUNT(*) FROM examiners WHERE needs_review = 1'),
    'refunds' => (int)DB::val("SELECT COUNT(*) FROM purchases WHERE refund_status IN ('failed','manual')"),
];

$menu = [
    'Übersicht' => [
        ['admin', 'Dashboard', 'grid', 'dashboard', false, 0],
    ],
    'Inhalte' => [
        ['admin/exams', 'Prüfungen', 'layers', 'exams', true, 0],
        ['admin/examiners', 'Prüfer', 'users', 'examiners', false, $counts['review']],
        ['admin/protocols', 'Protokolle', 'file-text', 'protocols', false, $counts['pending']],
        ['admin/import', 'Import / Migration', 'archive', 'import', false, 0],
    ],
    'Verkauf' => [
        ['admin/purchases', 'Käufe & Erstattungen', 'euro', 'purchases', true, $counts['refunds']],
        ['admin/users', 'Nutzer', 'user', 'users', true, 0],
    ],
    'Einstellungen' => [
        ['admin/settings', 'Einstellungen', 'sliders', 'settings', true, 0],
        ['admin/payments', 'Zahlungen / Stripe', 'card', 'payments', true, 0],
        ['admin/mail', 'E-Mail', 'mail', 'mail', true, 0],
        ['admin/pages', 'Texte & Rechtliches', 'pages', 'pages', true, 0],
    ],
    'System' => [
        ['admin/logs', 'Aktivitätslog', 'activity', 'logs', true, 0],
        ['admin/system', 'System & Wartung', 'server', 'system', true, 0],
    ],
];
$modeBadge = Payments::isSimulation()
    ? '<span class="badge badge-warning">' . icon('zap') . ' Simulation</span>'
    : (Stripe::isLive() ? '<span class="badge badge-success">' . icon('card') . ' Stripe live</span>' : '<span class="badge badge-info">' . icon('card') . ' Stripe Testmodus</span>');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((View::$title !== '' ? View::$title . ' · ' : '') . 'Admin · ' . $siteName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="admin-sidebar" data-nav>
    <a class="brand brand-admin" href="<?= e(url('admin')) ?>">
      <span class="brand-logo"><?= icon('book') ?></span>
      <span class="brand-text"><strong><?= e($siteName) ?></strong><small>Adminbereich</small></span>
    </a>
    <nav class="admin-nav">
      <?php foreach ($menu as $section => $items): ?>
        <?php $visible = array_filter($items, fn($i) => $isAdmin || !$i[4]); if (!$visible) continue; ?>
        <div class="admin-nav-section"><?= e($section) ?></div>
        <?php foreach ($visible as [$r, $label, $ic, $key, $adminOnly, $count]): ?>
          <a href="<?= e(url($r)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($count > 0): ?><span class="nav-count"><?= $count ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-footer">
      <a href="<?= e(base_url()) ?>"><?= icon('external') ?><span>Zur Webseite</span></a>
      <form method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit"><?= icon('logout') ?><span>Abmelden</span></button>
      </form>
      <div class="admin-user"><?= icon('user') ?><span><?= e($user['name'] ?: $user['email']) ?><small><?= e(role_label($user['role'])) ?></small></span></div>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="nav-toggle" type="button" data-nav-toggle aria-label="Menü öffnen" aria-expanded="false"><?= icon('menu') ?></button>
      <div class="admin-topbar-title"><?= e(View::$title) ?></div>
      <div class="admin-topbar-meta"><a href="<?= e(url('admin/payments')) ?>" class="plain"><?= $modeBadge ?></a></div>
    </header>
    <div class="admin-content">
      <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>" role="status"><?= icon($flashIcons[$type] ?? 'info') ?><div><?= e($msg) ?></div><button type="button" class="alert-close" data-dismiss aria-label="Schließen"><?= icon('x') ?></button></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
