<?php
/** Startseite mit Erklärung und Übersicht der Prüfungen. */
View::$active = 'exams';
$user = Auth::user();

$exams = DB::all(
    "SELECT x.*,
        (SELECT COUNT(*) FROM examiners e WHERE e.exam_id = x.id AND e.is_active = 1) AS examiner_count,
        (SELECT COUNT(*) FROM protocols p JOIN examiners e ON e.id = p.examiner_id
          WHERE e.exam_id = x.id AND e.is_active = 1 AND p.status = 'published') AS protocol_count
     FROM exams x WHERE x.is_active = 1 ORDER BY x.sort_order, x.name"
);
$totalProtocols = array_sum(array_map('intval', array_column($exams, 'protocol_count')));
$totalExaminers = array_sum(array_map('intval', array_column($exams, 'examiner_count')));
$price = Settings::int('price_cents');
$refund = Settings::int('refund_cents');
$maxExaminers = Settings::int('default_max_examiners');
$refundOn = Settings::bool('refund_enabled') && $refund > 0;
?>
<section class="hero">
  <div class="container hero-content">
    <h1><?= e(setting('home_title')) ?></h1>
    <p class="lead"><?= e(setting('home_intro')) ?></p>
    <div class="btn-group">
      <a class="btn btn-primary btn-lg" href="#pruefungen">Prüfungen ansehen <?= icon('arrow-right') ?></a>
      <?php if ($user): ?>
        <a class="btn btn-secondary btn-lg" href="<?= e(url('upload')) ?>"><?= icon('upload') ?> Protokoll hochladen</a>
      <?php else: ?>
        <a class="btn btn-secondary btn-lg" href="<?= e(url('register')) ?>">Jetzt registrieren</a>
      <?php endif; ?>
    </div>
    <div class="hero-badges">
      <span class="hero-badge"><?= icon('unlock') ?> <?= e(money_short($price)) ?> für bis zu <?= $maxExaminers ?> Prüfer</span>
      <?php if ($refundOn): ?>
        <span class="hero-badge"><?= icon('refund') ?> <?= e(money_short($refund)) ?> zurück für dein eigenes Protokoll</span>
      <?php endif; ?>
      <span class="hero-badge"><?= icon('shield') ?> Nur für Studierende mit Uni-E-Mail</span>
    </div>
  </div>
</section>

<div class="container">
  <section class="section">
    <div class="section-head">
      <div>
        <h2>So funktioniert’s</h2>
        <p>In vier Schritten zu den Protokollen deiner Prüferinnen und Prüfer.</p>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <span class="step-num">1</span>
        <div class="step-icon"><?= icon('mail') ?></div>
        <h3>Registrieren</h3>
        <p>Mit deiner studentischen E-Mail-Adresse – ein Klick auf den Bestätigungslink genügt.</p>
      </div>
      <div class="step">
        <span class="step-num">2</span>
        <div class="step-icon"><?= icon('users') ?></div>
        <h3>Prüfer wählen</h3>
        <p>Wähle deine Prüfung und bis zu <?= $maxExaminers ?> Prüfer aus und schalte sie für <?= e(money_short($price)) ?> frei.</p>
      </div>
      <div class="step">
        <span class="step-num">3</span>
        <div class="step-icon"><?= icon('book') ?></div>
        <h3>Lesen &amp; herunterladen</h3>
        <p>Alle Protokolle deiner Prüfer online lesen oder als Datei herunterladen – auch neue, die später dazukommen.</p>
      </div>
      <div class="step <?= $refundOn ? 'step-highlight' : '' ?>">
        <span class="step-num">4</span>
        <div class="step-icon"><?= icon($refundOn ? 'refund' : 'upload') ?></div>
        <?php if ($refundOn): ?>
          <h3>Protokoll hochladen – <?= e(money_short($refund)) ?> zurück</h3>
          <p>Nach deiner Prüfung lädst du dein eigenes Gedächtnisprotokoll hoch und bekommst <?= e(money_short($refund)) ?> automatisch erstattet.</p>
        <?php else: ?>
          <h3>Eigenes Protokoll teilen</h3>
          <p>Nach deiner Prüfung lädst du dein Gedächtnisprotokoll hoch und hilfst allen, die nach dir kommen.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="section" id="pruefungen">
    <div class="section-head">
      <div>
        <h2>Prüfungen</h2>
        <p>Wähle deine Prüfung, um die verfügbaren Prüferinnen und Prüfer zu sehen.</p>
      </div>
    </div>
    <?php if (!$exams): ?>
      <div class="card empty-state">
        <div class="empty-icon"><?= icon('layers') ?></div>
        <h3>Noch keine Prüfungen</h3>
        <p>Im Adminbereich können Prüfungen angelegt werden.</p>
      </div>
    <?php else: ?>
      <div class="exam-grid">
        <?php foreach ($exams as $x): ?>
          <a class="exam-card" href="<?= e(url('exam', ['id' => $x['id']])) ?>" style="--exam-color: <?= e($x['color']) ?>">
            <div class="exam-card-top"></div>
            <div class="exam-card-body">
              <span class="exam-short"><?= e($x['short_name'] ?: mb_substr($x['name'], 0, 2)) ?></span>
              <h3><?= e($x['name']) ?></h3>
              <p><?= e(str_excerpt($x['description'], 170)) ?></p>
              <div class="exam-meta">
                <span><?= icon('users') ?> <?= e(plural((int)$x['examiner_count'], 'Prüfer', 'Prüfer')) ?></span>
                <span><?= icon('file-text') ?> <?= e(plural((int)$x['protocol_count'], 'Protokoll', 'Protokolle')) ?></span>
                <?php if ((int)Payments::priceFor($x) !== $price): ?>
                  <span><?= icon('euro') ?> <?= e(money_short(Payments::priceFor($x))) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="exam-card-cta"><span>Prüfer ansehen</span><?= icon('arrow-right') ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="section">
    <div class="stats-strip">
      <div class="stat"><strong><?= number_format($totalProtocols, 0, ',', '.') ?></strong><span>Gedächtnisprotokolle</span></div>
      <div class="stat"><strong><?= number_format($totalExaminers, 0, ',', '.') ?></strong><span>Prüferinnen und Prüfer</span></div>
      <?php if ($refundOn): ?>
        <div class="stat"><strong><?= e(money_short(max(0, $price - $refund))) ?></strong><span>Eigenanteil, wenn du dein Protokoll teilst</span></div>
      <?php else: ?>
        <div class="stat"><strong><?= e(money_short($price)) ?></strong><span>pro Prüfung für bis zu <?= $maxExaminers ?> Prüfer</span></div>
      <?php endif; ?>
    </div>
  </section>
</div>
