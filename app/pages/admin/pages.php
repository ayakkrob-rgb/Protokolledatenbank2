<?php
/** Admin: Texte der Startseite, FAQ und Rechtstexte bearbeiten. */
View::$title = 'Texte & Rechtliches';
View::$active = 'pages';
$pages = [
    'home' => ['Startseite', ''],
    'faq' => ['FAQ', 'page_faq'],
    'impressum' => ['Impressum', 'page_impressum'],
    'datenschutz' => ['Datenschutz', 'page_datenschutz'],
    'nutzungsbedingungen' => ['Nutzungsbedingungen', 'page_nutzungsbedingungen'],
];
$page = is_post() ? post_str('page') : get_str('page', 'home');
if (!isset($pages[$page])) {
    $page = 'home';
}

if (is_post()) {
    if ($page === 'home') {
        Settings::setMany([
            'home_title' => post_str('home_title'),
            'home_intro' => post_str('home_intro'),
        ]);
    } elseif (post_bool('reset')) {
        DB::delete('settings', 'name = ?', [$pages[$page][1]]);
    } else {
        Settings::set($pages[$page][1], str_replace("\r\n", "\n", (string)($_POST['content'] ?? '')));
    }
    log_activity('page_saved', $page);
    flash('success', post_bool('reset') ? 'Der Standardtext wurde wiederhergestellt.' : 'Der Text wurde gespeichert.');
    redirect('admin/pages', ['page' => $page]);
}
?>
<?= admin_head('Texte & Rechtliches', 'Inhalte, die auf der Webseite angezeigt werden.') ?>

<nav class="tabs">
  <?php foreach ($pages as $key => [$label]): ?>
    <a href="<?= e(url('admin/pages', ['page' => $key])) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<div class="layout-sidebar">
  <form method="post" action="<?= e(url('admin/pages')) ?>" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="page" value="<?= e($page) ?>">
    <?php if ($page === 'home'): ?>
      <?= field_text('home_title', 'Überschrift auf der Startseite', (string)setting('home_title')) ?>
      <?= field_textarea('home_intro', 'Einleitungstext', (string)setting('home_intro'), ['rows' => 4]) ?>
    <?php else: ?>
      <?= field_textarea('content', $pages[$page][0], (string)setting($pages[$page][1]), ['rows' => 26, 'mono' => true]) ?>
    <?php endif; ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
      <?php if ($page !== 'home'): ?>
        <a class="btn btn-secondary" href="<?= e(url('page', ['slug' => $page])) ?>" target="_blank"><?= icon('eye') ?> Ansehen</a>
        <button type="submit" name="reset" value="1" class="btn btn-ghost" data-confirm="Eigenen Text verwerfen und Standardtext wiederherstellen?">Standardtext wiederherstellen</button>
      <?php endif; ?>
    </div>
  </form>
  <aside class="card">
    <h3>Formatierung</h3>
    <ul class="small">
      <li><code># Überschrift</code>, <code>## Unterüberschrift</code></li>
      <li><code>**fett**</code>, <code>*kursiv*</code></li>
      <li><code>- Aufzählung</code>, <code>1. Nummerierung</code></li>
      <li><code>[Linktext](https://…)</code></li>
      <li><code>&gt; Hinweisbox</code></li>
    </ul>
    <h3 class="mt-3">Platzhalter</h3>
    <ul class="small mb-0">
      <li><code>{preis}</code> → <?= e(money_short(Settings::int('price_cents'))) ?></li>
      <li><code>{erstattung}</code> → <?= e(money_short(Settings::int('refund_cents'))) ?></li>
      <li><code>{eigenanteil}</code> → <?= e(money_short(max(0, Settings::int('price_cents') - Settings::int('refund_cents')))) ?></li>
      <li><code>{max_pruefer}</code> → <?= Settings::int('default_max_examiners') ?></li>
      <li><code>{fachschaft}</code>, <code>{seitenname}</code>, <code>{kontakt}</code></li>
    </ul>
  </aside>
</div>
