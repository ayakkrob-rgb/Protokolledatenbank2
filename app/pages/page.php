<?php
/** Inhaltsseiten: Impressum, Datenschutz, Nutzungsbedingungen, FAQ. */
$pages = [
    'impressum' => 'Impressum',
    'datenschutz' => 'Datenschutzerklärung',
    'nutzungsbedingungen' => 'Nutzungsbedingungen',
    'faq' => 'Häufige Fragen',
];
$slug = get_str('slug');
if (!isset($pages[$slug])) {
    abort(404);
}
View::$title = $pages[$slug];
View::$active = $slug === 'faq' ? 'faq' : '';
$text = Settings::replacePlaceholders((string)setting('page_' . $slug, ''));
?>
<div class="container container-narrow">
  <div class="card mt-4">
    <div class="prose"><?= render_markdown($text) ?></div>
  </div>
  <?php if (Auth::isAdmin()): ?>
    <p class="small muted mt-2"><a href="<?= e(url('admin/pages', ['page' => $slug])) ?>"><?= icon('edit') ?> Diesen Text im Adminbereich bearbeiten</a></p>
  <?php endif; ?>
</div>
