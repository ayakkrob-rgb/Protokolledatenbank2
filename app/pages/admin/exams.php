<?php
/** Admin: Prüfungen verwalten. */
View::$title = 'Prüfungen';
View::$active = 'exams';

if (is_post() && post_str('action') === 'toggle') {
    $id = post_int('id');
    DB::q('UPDATE exams SET is_active = 1 - is_active WHERE id = ?', [$id]);
    log_activity('exam_toggled', 'Prüfung #' . $id);
    flash('success', 'Status der Prüfung wurde geändert.');
    redirect('admin/exams');
}

$exams = DB::all(
    "SELECT x.*,
       (SELECT COUNT(*) FROM examiners e WHERE e.exam_id = x.id) AS examiner_count,
       (SELECT COUNT(*) FROM protocols p JOIN examiners e ON e.id = p.examiner_id WHERE e.exam_id = x.id AND p.status = 'published') AS protocol_count,
       (SELECT COUNT(*) FROM purchases pu WHERE pu.exam_id = x.id AND pu.status = 'paid') AS purchase_count
     FROM exams x ORDER BY x.sort_order, x.name"
);
?>
<?= admin_head('Prüfungen', 'Die großen Prüfungsabschnitte (z. B. Physikum, M2, M3). Jede Prüfung hat eigene Prüfer.', '<a class="btn btn-primary" href="' . e(url('admin/exam', ['new' => 1])) . '">' . icon('plus') . ' Neue Prüfung</a>') ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Prüfung</th><th class="num">Prüfer</th><th class="num">Protokolle</th><th class="num">Käufe</th><th class="num">Preis</th><th class="num">Erstattung</th><th class="num">Max. Prüfer</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($exams as $x): ?>
          <tr>
            <td>
              <div class="flex" style="gap:10px;flex-wrap:nowrap">
                <span class="exam-short" style="--exam-color: <?= e($x['color']) ?>;min-width:40px;height:40px;font-size:.85rem;margin:0;border-radius:10px"><?= e($x['short_name'] ?: '–') ?></span>
                <div><a href="<?= e(url('admin/exam', ['id' => $x['id']])) ?>"><strong><?= e($x['name']) ?></strong></a><span class="table-sub">Reihenfolge <?= (int)$x['sort_order'] ?> · /<?= e($x['slug']) ?></span></div>
              </div>
            </td>
            <td class="num"><a href="<?= e(url('admin/examiners', ['exam' => $x['id']])) ?>"><?= (int)$x['examiner_count'] ?></a></td>
            <td class="num"><a href="<?= e(url('admin/protocols', ['exam' => $x['id']])) ?>"><?= (int)$x['protocol_count'] ?></a></td>
            <td class="num"><?= (int)$x['purchase_count'] ?></td>
            <td class="num"><?= e(money(Payments::priceFor($x))) ?><?= $x['price_cents'] === null ? '<span class="table-sub">Standard</span>' : '' ?></td>
            <td class="num"><?= e(money(Payments::refundFor($x))) ?><?= $x['refund_cents'] === null ? '<span class="table-sub">Standard</span>' : '' ?></td>
            <td class="num"><?= (int)$x['max_examiners'] ?></td>
            <td><?= (int)$x['is_active'] ? badge('Aktiv', 'success') : badge('Inaktiv', 'neutral') ?></td>
            <td class="actions">
              <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/exam', ['id' => $x['id']])) ?>"><?= icon('edit') ?> Bearbeiten</a>
              <?= post_button(url('admin/exams'), (int)$x['is_active'] ? 'Deaktivieren' : 'Aktivieren', ['action' => 'toggle', 'id' => $x['id']], 'btn btn-ghost btn-sm') ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('exam', ['id' => $x['id']])) ?>" title="Ansehen"><?= icon('eye') ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$exams): ?><tr><td colspan="9" class="muted">Noch keine Prüfungen angelegt.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small muted mt-2">Preis und Erstattung lassen sich pro Prüfung abweichend vom Standard festlegen (Standard: <?= e(money(Settings::int('price_cents'))) ?> / <?= e(money(Settings::int('refund_cents'))) ?>, änderbar unter <a href="<?= e(url('admin/settings')) ?>">Einstellungen</a>).</p>
