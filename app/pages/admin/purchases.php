<?php
/** Admin: Käufe & Erstattungen inkl. CSV-Export. */
View::$title = 'Käufe & Erstattungen';
View::$active = 'purchases';
Payments::cleanupPending();

$status = get_str('status');
$refund = get_str('refund');
$provider = get_str('provider');
$examId = get_int('exam');
$q = get_str('q');
$from = get_str('from');
$to = get_str('to');

$where = ['1=1'];
$params = [];
if (in_array($status, ['pending', 'paid', 'cancelled', 'failed', 'revoked'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($refund === 'problem') {
    $where[] = "p.refund_status IN ('failed','manual')";
} elseif (in_array($refund, ['none', 'processing', 'pending', 'succeeded', 'failed', 'manual'], true)) {
    $where[] = 'p.refund_status = ?';
    $params[] = $refund;
}
if (in_array($provider, ['stripe', 'simulation', 'manual'], true)) {
    $where[] = 'p.provider = ?';
    $params[] = $provider;
}
if ($examId) {
    $where[] = 'p.exam_id = ?';
    $params[] = $examId;
}
if ($q !== '') {
    $where[] = '(u.email LIKE ? OR u.name LIKE ? OR p.stripe_payment_intent = ? OR p.id = ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', $q, (int)ltrim($q, '#'));
}
if (valid_date($from)) {
    $where[] = 'p.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if (valid_date($to)) {
    $where[] = 'p.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);
$base = "FROM purchases p JOIN users u ON u.id = p.user_id JOIN exams x ON x.id = p.exam_id WHERE $whereSql";

if (get_int('export') === 1) {
    $rows = DB::all(
        "SELECT p.*, u.email, u.name AS user_name, x.name AS exam_name,
            (SELECT GROUP_CONCAT(e.name ORDER BY e.name SEPARATOR ', ') FROM purchase_examiners pe JOIN examiners e ON e.id = pe.examiner_id WHERE pe.purchase_id = p.id) AS examiner_names
         $base ORDER BY p.id",
        $params
    );
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kaeufe-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $fmt = fn($c) => number_format(((int)$c) / 100, 2, ',', '');
    // Schutz vor Formel-Injektion in Excel/LibreOffice
    $cell = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    fputcsv($out, ['Kauf-Nr.', 'Erstellt', 'Bezahlt am', 'Name', 'E-Mail', 'Prüfung', 'Prüfer', 'Anbieter', 'Status', 'Betrag', 'Erstattet', 'Erstattet am', 'Erstattungsstatus', 'Netto', 'Stripe Payment Intent', 'Stripe Refund', 'Notiz'], ';', '"', '\\');
    foreach ($rows as $r) {
        $refunded = $r['refund_status'] === 'succeeded' ? (int)$r['refund_cents'] : 0;
        $net = $r['status'] === 'paid' || $r['status'] === 'revoked' ? (int)$r['amount_cents'] - $refunded : 0;
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['paid_at'], $cell($r['user_name']), $cell($r['email']), $cell($r['exam_name']), $cell($r['examiner_names']),
            Payments::providerLabel($r['provider']), $r['status'], $fmt($r['amount_cents']), $fmt($refunded), $r['refunded_at'],
            $r['refund_status'], $fmt($net), $r['stripe_payment_intent'], $r['refund_id'], $cell(str_replace(["\r", "\n"], ' ', (string)$r['admin_note'])),
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$total = (int)DB::val("SELECT COUNT(*) $base", $params);
$sums = DB::one(
    "SELECT COALESCE(SUM(CASE WHEN p.status IN ('paid','revoked') THEN p.amount_cents ELSE 0 END),0) AS revenue,
            COALESCE(SUM(CASE WHEN p.refund_status = 'succeeded' THEN p.refund_cents ELSE 0 END),0) AS refunded,
            COALESCE(SUM(CASE WHEN p.refund_status IN ('processing','pending','manual') THEN p.refund_cents ELSE 0 END),0) AS refund_open
     $base",
    $params
);
$pg = paginate($total, 50);
$rows = DB::all(
    "SELECT p.*, u.email, u.name AS user_name, x.short_name, x.name AS exam_name,
        (SELECT COUNT(*) FROM purchase_examiners pe WHERE pe.purchase_id = p.id) AS examiner_count
     $base ORDER BY p.id DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'],
    $params
);
$examOptions = ['' => 'Alle'];
foreach (DB::all('SELECT id, name FROM exams ORDER BY sort_order, name') as $x) {
    $examOptions[$x['id']] = $x['name'];
}
?>
<?= admin_head('Käufe & Erstattungen', 'Alle Freischaltungen, Zahlungen und Rückerstattungen.', '<a class="btn btn-secondary" href="' . e(url_with(['export' => 1, 'page' => null])) . '">' . icon('download') . ' CSV-Export</a>') ?>

<form method="get" action="<?= e(base_url() . 'index.php') ?>" class="filters">
  <input type="hidden" name="p" value="admin/purchases">
  <div class="field grow"><label>Suche</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="E-Mail, Name, Kauf-Nr. oder pi_…"></div>
  <?= field_select('status', 'Status', ['' => 'Alle', 'paid' => 'Bezahlt', 'pending' => 'Offen', 'cancelled' => 'Abgebrochen', 'failed' => 'Fehlgeschlagen', 'revoked' => 'Entzogen'], $status, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('refund', 'Erstattung', ['' => 'Alle', 'none' => 'Keine', 'succeeded' => 'Erstattet', 'pending' => 'Wird ausgezahlt', 'problem' => 'Handlungsbedarf', 'failed' => 'Fehlgeschlagen', 'manual' => 'Manuell'], $refund, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('provider', 'Anbieter', ['' => 'Alle', 'stripe' => 'Stripe', 'simulation' => 'Simulation', 'manual' => 'Manuell'], $provider, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_select('exam', 'Prüfung', $examOptions, $examId ?: '', ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= field_text('from', 'Von', $from, ['type' => 'date']) ?>
  <?= field_text('to', 'Bis', $to, ['type' => 'date']) ?>
  <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Filtern</button></div>
</form>

<div class="kpis">
  <?= kpi('card', 'Käufe (Filter)', (string)$total) ?>
  <?= kpi('euro', 'Umsatz', money($sums['revenue']), 'bezahlte Käufe', 'success') ?>
  <?= kpi('refund', 'Erstattet', money($sums['refunded']), $sums['refund_open'] > 0 ? e(money($sums['refund_open'])) . ' in Bearbeitung' : '') ?>
  <?= kpi('chart', 'Netto', money((int)$sums['revenue'] - (int)$sums['refunded']), 'vor Stripe-Gebühren') ?>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>Datum</th><th>Nutzer</th><th>Prüfung</th><th class="num">Betrag</th><th>Anbieter</th><th>Status</th><th>Erstattung</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="<?= e(url('admin/purchase', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
            <td class="small nowrap"><?= e(fmt_datetime($r['paid_at'] ?: $r['created_at'])) ?></td>
            <td><a href="<?= e(url('admin/user', ['id' => $r['user_id']])) ?>"><?= e($r['email']) ?></a><span class="table-sub"><?= e($r['user_name']) ?></span></td>
            <td><?= e($r['short_name'] ?: $r['exam_name']) ?><span class="table-sub"><?= (int)$r['examiner_count'] ?> Prüfer</span></td>
            <td class="num"><?= e(money($r['amount_cents'], $r['currency'])) ?></td>
            <td class="small"><?= e(Payments::providerLabel($r['provider'])) ?></td>
            <td><?= Payments::statusBadge($r['status']) ?></td>
            <td><?= Payments::refundBadge($r['refund_status']) ?><?= $r['refund_status'] !== 'none' ? '<span class="table-sub">' . e(money($r['refund_cents'], $r['currency'])) . '</span>' : '' ?></td>
            <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/purchase', ['id' => $r['id']])) ?>">Details</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine Käufe gefunden.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= pagination_links($pg) ?>
<p class="small muted mt-2">Hinweis: Stripe erstattet bei Rückerstattungen die ursprünglichen Transaktionsgebühren nicht zurück. Die tatsächlichen Auszahlungen siehst du im Stripe-Dashboard.</p>
