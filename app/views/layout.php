<?php
/** Öffentliches Layout. Erwartet $content. */
$user = Auth::user();
$siteName = (string)setting('site_name');
$orgName = (string)setting('org_name');
$flashes = take_flashes();
$pageTitle = View::$title !== '' ? View::$title . ' · ' . $siteName : $siteName . ' · ' . $orgName;
$active = View::$active;
$announcement = trim((string)setting('announcement'));
$contact = trim((string)setting('contact_email'));
$flashIcons = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="<?= e(str_excerpt((string)setting('home_intro'), 150)) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e(View::$bodyClass) ?>">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<?php if (Payments::isSimulation()): ?>
<div class="topbar topbar-sim"><?= icon('zap') ?><span><strong>Testmodus:</strong> Zahlungen und Erstattungen werden nur simuliert – es wird kein echtes Geld bewegt.</span></div>
<?php endif; ?>
<?php if ($announcement !== ''): ?>
<div class="topbar topbar-info"><?= icon('info') ?><span><?= md_inline($announcement) ?></span></div>
<?php endif; ?>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= e(base_url()) ?>">
      <span class="brand-logo"><?= icon('book') ?></span>
      <span class="brand-text"><strong><?= e($siteName) ?></strong><small><?= e($orgName) ?></small></span>
    </a>
    <button class="nav-toggle" type="button" data-nav-toggle aria-label="Menü öffnen" aria-expanded="false"><?= icon('menu') ?></button>
    <nav class="main-nav" data-nav>
      <a href="<?= e(base_url()) ?>#pruefungen" class="<?= $active === 'exams' ? 'active' : '' ?>">Prüfungen</a>
      <a href="<?= e(url('upload')) ?>" class="<?= $active === 'upload' ? 'active' : '' ?>">Protokoll hochladen</a>
      <a href="<?= e(url('page', ['slug' => 'faq'])) ?>" class="<?= $active === 'faq' ? 'active' : '' ?>">FAQ</a>
      <?php if ($user): ?>
        <a href="<?= e(url('account')) ?>" class="<?= $active === 'account' ? 'active' : '' ?>"><?= icon('user') ?> Mein Bereich</a>
        <?php if (Auth::isStaff()): ?>
          <a href="<?= e(url('admin')) ?>" class="nav-admin"><?= icon('sliders') ?> Admin</a>
        <?php endif; ?>
        <form method="post" action="<?= e(url('logout')) ?>" class="nav-form">
          <?= csrf_field() ?>
          <button type="submit" class="nav-link-button" title="Abmelden"><?= icon('logout') ?><span class="only-mobile"> Abmelden</span></button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('login')) ?>" class="<?= $active === 'login' ? 'active' : '' ?>">Anmelden</a>
        <a href="<?= e(url('register')) ?>" class="btn btn-primary btn-sm">Registrieren</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main id="main" class="site-main">
<?php if ($flashes): ?>
  <div class="container flash-stack">
  <?php foreach ($flashes as [$type, $msg]): ?>
    <div class="alert alert-<?= e($type) ?>" role="status"><?= icon($flashIcons[$type] ?? 'info') ?><div><?= e($msg) ?></div><button type="button" class="alert-close" data-dismiss aria-label="Schließen"><?= icon('x') ?></button></div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-brand">
      <strong><?= e($siteName) ?></strong>
      <span><?= e($orgName) ?> · Gedächtnisprotokolle von Studierenden für Studierende</span>
    </div>
    <nav class="footer-nav">
      <a href="<?= e(url('page', ['slug' => 'faq'])) ?>">FAQ</a>
      <a href="<?= e(url('page', ['slug' => 'nutzungsbedingungen'])) ?>">Nutzungsbedingungen</a>
      <a href="<?= e(url('page', ['slug' => 'datenschutz'])) ?>">Datenschutz</a>
      <a href="<?= e(url('page', ['slug' => 'impressum'])) ?>">Impressum</a>
      <?php if ($contact !== ''): ?><a href="mailto:<?= e($contact) ?>">Kontakt</a><?php endif; ?>
    </nav>
  </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
