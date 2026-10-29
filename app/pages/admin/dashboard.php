<?php
/** Admin-Dashboard: Kennzahlen, offene Aufgaben, Warnungen. */
View::$title = 'Dashboard';
View::$active = 'dashboard';
$isAdmin = Auth::isAdmin();
Payments::cleanupPending();

$weekAgo = date('Y-m-d H:i:s', time() - 7 * 86400);
$stats = [
    'users' => (int)DB::val("SELECT COUNT(*) FROM users WHERE is_blocked = 0"),
    'users_verified' => (int)DB::val("SELECT COUNT(*) FROM users WHERE is_verified = 1 AND is_blocked = 0"),
    'users_week' => (int)DB::val('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$weekAgo]),
    'protocols' => (int)DB::val("SELECT COUNT(*) FROM protocols WHERE status = 'published'"),
    'protocols_week' => (int)DB::val("SELECT COUNT(*) FROM protocols WHERE created_at >= ?", [$weekAgo]),
    'pending' => (int)DB::val("SELECT COUNT(*) FROM protocols WHERE status = 'pending'"),
    'examiners' => (int)DB::val('SELECT COUNT(*) FROM examiners WHERE is_active = 1'),
    'review' => (int)DB::val('SELECT COUNT(*) FROM examiners WHERE needs_review = 1'),
    'paid' => (int)DB::val("SELECT COUNT(*) FROM purchases WHERE status = 'paid' AND provider <> 'simulation'"),
    'revenue' => (int)DB::val("SELECT COALESCE(SUM(amount_cents),0) FROM purchases WHERE status = 'paid' AND provider <> 'simulation'"),
    'refunded' => (int)DB::val("SELECT COALESCE(SUM(refund_cents),0) FROM purchases WHERE refund_status = 'succeeded' AND provider <> 'simulation'"),
    'sim_paid' => (int)DB::val("SELECT COUNT(*) FROM purchases WHERE status = 'paid' AND provider = 'simulation'"),
    'refund_problems' => (int)DB::val("SELECT COUNT(*) FROM purchases WHERE refund_status IN ('failed','manual')"),
];

// Warnungen & Hinweise
$warnings = [];
if ($isAdmin) {
    if (Payments::isSimulation()) {
        $warnings[] = ['warning', 'Der <strong>Simulationsmodus</strong> ist aktiv – Zahlungen werden nur simuliert. <a href="' . e(url('admin/payments')) . '">Stripe einrichten</a>'];
    } elseif (!Stripe::isConfigured()) {
        $warnings[] = ['error', 'Stripe-Modus ist aktiv, aber es ist kein Geheimschlüssel hinterlegt – Käufe sind nicht möglich. <a href="' . e(url('admin/payments')) . '">Jetzt einrichten</a>'];
    } elseif (Settings::secret('stripe_webhook_secret') === '') {
        $warnings[] = ['warning', 'Der Stripe-Webhook ist noch nicht eingerichtet. Zahlungen werden zwar bei der Rückkehr erkannt, für Zuverlässigkeit (z. B. SEPA) bitte den Webhook einrichten. <a href="' . e(url('admin/payments')) . '">Einrichten</a>'];
    }
    if (setting('mail_mode') === 'log') {
        $warnings[] = ['warning', 'E-Mails werden nur <strong>protokolliert</strong> und nicht verschickt. <a href="' . e(url('admin/mail')) . '">E-Mail-Versand einstellen</a>'];
    }
    if (!Auth::allowedDomains()) {
        $warnings[] = ['warning', 'Es sind keine erlaubten E-Mail-Domains eingetragen – jede E-Mail-Adresse kann sich registrieren. <a href="' . e(url('admin/settings')) . '">Uni-Domains festlegen</a>'];
    }
    if (str_contains((string)setting('page_impressum'), '[Straße')) {
        $warnings[] = ['info', 'Impressum und Datenschutzerklärung enthalten noch Platzhalter. <a href="' . e(url('admin/pages')) . '">Texte anpassen</a>'];
    }
    if (is_dir(APP_ROOT . '/install')) {
        $warnings[] = ['warning', 'Der Ordner <code>install/</code> existiert noch. Bitte lösche ihn per FTP (der Installer ist zwar gesperrt, sicherer ist aber das Löschen).'];
    }
    if ((int)DB::val('SELECT COUNT(*) FROM examiners WHERE is_demo = 1') > 0) {
        $warnings[] = ['info', 'Es sind noch <strong>Demodaten</strong> (Testprüfer und -protokolle) vorhanden. Vor dem Start kannst du sie unter <a href="' . e(url('admin/system')) . '">System &amp; Wartung</a> entfernen.'];
    }
    if (!is_writable(Protocols::storageDir())) {
        $warnings[] = ['error', 'Der Speicherordner <code>storage/uploads</code> ist nicht beschreibbar – Uploads schlagen fehl.'];
    }
}

$pendingProtocols = DB::all(
    "SELECT p.id, p.title, p.created_at, e.name AS examiner_name, x.short_name, u.name AS user_name
     FROM protocols p JOIN examiners e ON e.id = p.examiner_id JOIN exams x ON x.id = e.exam_id LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'pending' ORDER BY p.created_at LIMIT 6"
);
$reviewExaminers = DB::all(
    "SELECT e.*, x.short_name, (SELECT COUNT(*) FROM protocols p WHERE p.examiner_id = e.id) AS protocol_count
     FROM examiners e JOIN exams x ON x.id = e.exam_id WHERE e.needs_review = 1 ORDER BY e.created_at DESC LIMIT 6"
);
$refundProblems = $isAdmin ? DB::all(
    "SELECT p.*, u.email, x.short_name FROM purchases p JOIN users u ON u.id = p.user_id JOIN exams x ON x.id = p.exam_id
     WHERE p.refund_status IN ('failed','manual') ORDER BY p.id DESC LIMIT 6"
) : [];
$manualRefunds = [];
if ($isAdmin && setting('refund_mode') === 'manual' && Settings::bool('refund_enabled')) {
    foreach (DB::all(
        "SELECT p.id, p.title, p.user_id, p.submission_id, p.created_at, e.exam_id, u.email
         FROM protocols p JOIN examiners e ON e.id = p.examiner_id JOIN users u ON u.id = p.user_id
         WHERE p.source = 'upload' AND p.status = 'published' AND p.refund_purchase_id IS NULL ORDER BY p.created_at DESC LIMIT 100"
    ) as $row) {
        if ($row['submission_id'] && (int)DB::val('SELECT COUNT(*) FROM protocols WHERE submission_id = ? AND refund_purchase_id IS NOT NULL', [$row['submission_id']])) {
            continue;
        }
        $pur = Payments::eligiblePurchaseForRefund((int)$row['user_id'], (int)$row['exam_id']);
        if ($pur) {
            $row['refund_amount'] = $pur['refund_amount'];
            $manualRefunds[] = $row;
        }
    }
}
$recentPurchases = $isAdmin ? DB::all(
    "SELECT p.*, u.email, x.short_name FROM purchases p JOIN users u ON u.id = p.user_id JOIN exams x ON x.id = p.exam_id
     WHERE p.status = 'paid' ORDER BY p.paid_at DESC LIMIT 6"
) : [];
$activity = DB::all('SELECT a.*, u.email FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 10');
?>
<?= admin_head('Dashboard', 'Willkommen im Adminbereich von ' . e(setting('site_name')) . '.', '<a class="btn btn-primary" href="' . e(url('admin/protocol', ['new' => 1])) . '">' . icon('plus') . ' Protokoll anlegen</a><a class="btn btn-secondary" href="' . e(url('admin/import')) . '">' . icon('archive') . ' Import</a>') ?>

<?php foreach ($warnings as [$type, $html]): ?>
  <div class="alert alert-<?= e($type) ?>"><?= icon($type === 'info' ? 'info' : 'alert') ?><div><?= $html ?></div></div>
<?php endforeach; ?>

<div class="kpis">
  <?= kpi('file-text', 'Protokolle', number_format($stats['protocols'], 0, ',', '.'), '+' . $stats['protocols_week'] . ' in 7 Tagen', '', url('admin/protocols')) ?>
  <?= kpi('clock', 'Warten auf Freigabe', (string)$stats['pending'], 'Protokolle', $stats['pending'] ? 'warn' : '', url('admin/protocols', ['status' => 'pending'])) ?>
  <?= kpi('users', 'Aktive Prüfer', (string)$stats['examiners'], $stats['review'] . ' neu zu prüfen', $stats['review'] ? 'warn' : '', url('admin/examiners', ['review' => 1])) ?>
  <?php if ($isAdmin): ?>
    <?= kpi('user', 'Nutzer', number_format($stats['users'], 0, ',', '.'), $stats['users_verified'] . ' bestätigt · +' . $stats['users_week'] . ' in 7 Tagen', '', url('admin/users')) ?>
    <?= kpi('euro', 'Umsatz (echt)', money($stats['revenue']), $stats['paid'] . ' Käufe' . ($stats['sim_paid'] ? ' · ' . $stats['sim_paid'] . ' simuliert' : ''), 'success', url('admin/purchases')) ?>
    <?= kpi('refund', 'Erstattet', money($stats['refunded']), 'Netto: ' . e(money($stats['revenue'] - $stats['refunded'])), '', url('admin/purchases', ['refund' => 'succeeded'])) ?>
    <?php if ($stats['refund_problems']): ?>
      <?= kpi('alert', 'Erstattungen mit Handlungsbedarf', (string)$stats['refund_problems'], 'fehlgeschlagen oder manuell', 'danger', url('admin/purchases', ['refund' => 'problem'])) ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card card-flush">
    <div class="card-header"><h3>Warten auf Freigabe</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/protocols', ['status' => 'pending'])) ?>">Alle</a></div>
    <?php if (!$pendingProtocols): ?>
      <p class="muted" style="padding:16px 20px;margin:0"><?= icon('check-circle') ?> Keine offenen Protokolle.<?= !Settings::bool('moderation') ? ' (Moderation ist ausgeschaltet – Uploads erscheinen sofort.)' : '' ?></p>
    <?php else: ?>
      <table class="table table-compact"><tbody>
        <?php foreach ($pendingProtocols as $p): ?>
          <tr>
            <td><a href="<?= e(url('admin/protocol', ['id' => $p['id']])) ?>"><strong><?= e($p['title']) ?></strong></a><span class="table-sub"><?= e($p['short_name'] . ' · ' . $p['examiner_name'] . ' · ' . ($p['user_name'] ?: 'Admin')) ?></span></td>
            <td class="nowrap muted small"><?= e(time_ago($p['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </div>

  <div class="card card-flush">
    <div class="card-header"><h3>Neue Prüfer prüfen</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/examiners', ['review' => 1])) ?>">Alle</a></div>
    <?php if (!$reviewExaminers): ?>
      <p class="muted" style="padding:16px 20px;margin:0"><?= icon('check-circle') ?> Keine neuen Prüfer.</p>
    <?php else: ?>
      <table class="table table-compact"><tbody>
        <?php foreach ($reviewExaminers as $ex): ?>
          <tr>
            <td><a href="<?= e(url('admin/examiner', ['id' => $ex['id']])) ?>"><strong><?= e($ex['name']) ?></strong></a><span class="table-sub"><?= e($ex['short_name'] . ($ex['subject'] !== '' ? ' · ' . $ex['subject'] : '') . ' · ' . plural((int)$ex['protocol_count'], 'Protokoll', 'Protokolle')) ?></span></td>
            <td class="actions"><?= post_button(url('admin/examiner'), icon('check') . ' OK', ['id' => $ex['id'], 'action' => 'approve'], 'btn btn-secondary btn-xs') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin && ($refundProblems || $manualRefunds)): ?>
    <div class="card card-flush">
      <div class="card-header"><h3>Erstattungen mit Handlungsbedarf</h3></div>
      <table class="table table-compact"><tbody>
        <?php foreach ($refundProblems as $p): ?>
          <tr>
            <td><a href="<?= e(url('admin/purchase', ['id' => $p['id']])) ?>">Kauf #<?= (int)$p['id'] ?></a> <?= Payments::refundBadge($p['refund_status']) ?><span class="table-sub"><?= e($p['email'] . ' · ' . $p['short_name'] . ' · ' . money($p['refund_cents'])) ?><?= $p['refund_error'] ? ' · ' . e(str_excerpt($p['refund_error'], 80)) : '' ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($manualRefunds as $r): ?>
          <tr>
            <td><a href="<?= e(url('admin/protocol', ['id' => $r['id']])) ?>"><?= e($r['title']) ?></a> <?= badge('Erstattung freigeben', 'warning') ?><span class="table-sub"><?= e($r['email'] . ' · ' . money($r['refund_amount'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <div class="card card-flush">
      <div class="card-header"><h3>Letzte Käufe</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/purchases')) ?>">Alle</a></div>
      <?php if (!$recentPurchases): ?>
        <p class="muted" style="padding:16px 20px;margin:0">Noch keine Käufe.</p>
      <?php else: ?>
        <table class="table table-compact"><tbody>
          <?php foreach ($recentPurchases as $p): ?>
            <tr>
              <td><a href="<?= e(url('admin/purchase', ['id' => $p['id']])) ?>"><?= e($p['email']) ?></a><span class="table-sub"><?= e($p['short_name'] . ' · ' . Payments::providerLabel($p['provider']) . ' · ' . time_ago($p['paid_at'])) ?></span></td>
              <td class="num"><?= e(money($p['amount_cents'], $p['currency'])) ?><br><?= Payments::refundBadge($p['refund_status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card card-flush">
    <div class="card-header"><h3>Letzte Aktivitäten</h3><?php if ($isAdmin): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/logs')) ?>">Alle</a><?php endif; ?></div>
    <table class="table table-compact"><tbody>
      <?php foreach ($activity as $a): ?>
        <tr><td><strong><?= e(activity_label($a['action'])) ?></strong><span class="table-sub"><?= e(str_excerpt($a['details'], 90)) ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?></span></td><td class="nowrap muted small"><?= e(time_ago($a['created_at'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$activity): ?><tr><td class="muted">Noch keine Aktivitäten.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
