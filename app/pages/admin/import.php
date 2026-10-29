<?php
/** Admin: Import / Migration bestehender Protokolle (ZIP, FTP-Ordner, CSV). */
View::$title = 'Import / Migration';
View::$active = 'import';
@set_time_limit(300);

$exams = Importer::exams();
$examOptions = ['' => 'Aus der Ordnerstruktur ablesen (Prüfung/Prüfer/Datei)'];
foreach ($exams as $x) {
    $examOptions[$x['id']] = 'Alles importieren in: ' . $x['name'];
}
$job = $_SESSION['import_job'] ?? null;
$step = get_str('step');
$action = post_str('action');
$tmpDir = Importer::tmpDir();
$importDir = Importer::importDir();

/* ---------- Aktionen ---------- */

if (is_post() && $action === 'zip') {
    $f = $_FILES['zip'] ?? null;
    if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $code = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        flash('error', $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Die ZIP-Datei ist größer als das Upload-Limit des Servers (' . human_filesize(Protocols::serverUploadLimit()) . '). Nutze bitte den FTP-Import.'
            : 'Bitte wähle eine ZIP-Datei aus.');
        redirect('admin/import');
    }
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') {
        flash('error', 'Bitte lade eine .zip-Datei hoch.');
        redirect('admin/import');
    }
    $token = random_token(8);
    $dir = $tmpDir . '/import_' . $token;
    $zipPath = $dir . '.zip';
    if (!@mkdir($dir, 0775, true) || !move_uploaded_file($f['tmp_name'], $zipPath)) {
        flash('error', 'Die Datei konnte nicht gespeichert werden. Ist storage/tmp beschreibbar?');
        redirect('admin/import');
    }
    try {
        $n = Importer::extractZip($zipPath, $dir);
    } catch (Throwable $e) {
        Importer::removeDir($dir);
        @unlink($zipPath);
        flash('error', $e->getMessage());
        redirect('admin/import');
    }
    @unlink($zipPath);
    $_SESSION['import_job'] = ['mode' => 'zip', 'root' => $dir, 'exam_id' => post_int('exam_id') ?: null, 'token' => $token, 'running' => false, 'source' => $f['name'] . ' (' . $n . ' Dateien)'];
    redirect('admin/import', ['step' => 'preview']);
}

if (is_post() && $action === 'folder') {
    $_SESSION['import_job'] = ['mode' => 'folder', 'root' => $importDir, 'exam_id' => post_int('exam_id') ?: null, 'token' => random_token(8), 'running' => false, 'source' => 'FTP-Ordner storage/import/'];
    redirect('admin/import', ['step' => 'preview']);
}

if (is_post() && $action === 'csv') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        flash('error', 'Bitte wähle eine CSV-Datei aus.');
        redirect('admin/import');
    }
    $stats = Importer::importCsv($f['tmp_name'], post_int('exam_id') ?: null);
    log_activity('import_csv', $stats['imported'] . ' importiert, ' . $stats['duplicates'] . ' Duplikate, ' . count($stats['errors']) . ' Fehler');
    $_SESSION['import_result'] = $stats + ['source' => 'CSV ' . $f['name']];
    redirect('admin/import', ['step' => 'done']);
}

if (is_post() && $action === 'cancel') {
    if ($job && $job['mode'] === 'zip') {
        Importer::removeDir($job['root']);
    }
    unset($_SESSION['import_job']);
    flash('info', 'Der Import wurde abgebrochen.');
    redirect('admin/import');
}

if (is_post() && $action === 'start' && $job) {
    $job['running'] = true;
    $job['imported'] = 0;
    $job['duplicates'] = 0;
    $job['errors'] = [];
    $job['skipped'] = [];
    $job['new_examiners'] = [];
    $job['started'] = time();
    $job['done_dir'] = $job['mode'] === 'folder' ? $importDir . '/_importiert/' . date('Y-m-d_His') : null;
    $_SESSION['import_job'] = $job;
    redirect('admin/import', ['step' => 'run', 'token' => $job['token']]);
}

/* ---------- Stapelverarbeitung ---------- */

if ($step === 'run' && $job && !empty($job['running']) && hash_equals($job['token'], get_str('token'))) {
    $start = microtime(true);
    $processed = 0;
    $remaining = 0;
    foreach (Importer::listFiles($job['root']) as $rel) {
        if (isset($job['skipped'][$rel])) {
            continue;
        }
        $item = Importer::analyze($rel, $job['exam_id'], $exams);
        if ($item['error']) {
            $job['skipped'][$rel] = $item['error'];
            continue;
        }
        if ($processed >= 40 || microtime(true) - $start > 15) {
            $remaining++;
            continue;
        }
        $res = Importer::importItem($job['root'], $item, $job['done_dir']);
        $processed++;
        if ($res['status'] === 'imported') {
            $job['imported']++;
        } elseif ($res['status'] === 'duplicate') {
            $job['duplicates']++;
        } else {
            $job['skipped'][$rel] = $res['error'];
            $job['errors'][] = $res['error'];
        }
        if ($res['new_examiner']) {
            $job['new_examiners'][] = $item['exam_label'] . ': ' . $res['new_examiner'];
        }
    }
    $_SESSION['import_job'] = $job;

    if ($remaining > 0) {
        header('Refresh: 1; url=' . url('admin/import', ['step' => 'run', 'token' => $job['token']]));
        $done = $job['imported'] + $job['duplicates'];
        $percent = (int)round($done / max(1, $done + $remaining) * 100);
        echo admin_head('Import läuft …', 'Bitte dieses Fenster geöffnet lassen. Die Seite lädt automatisch weiter.');
        echo '<div class="card"><div class="progress"><span style="width:' . $percent . '%"></span></div>'
            . '<p class="mt-2 mb-0"><strong>' . $done . '</strong> verarbeitet, noch <strong>' . $remaining . '</strong> Dateien … (' . $percent . ' %)</p></div>';
        return;
    }

    if ($job['mode'] === 'zip') {
        Importer::removeDir($job['root']);
    }
    $skippedList = [];
    foreach ($job['skipped'] as $rel => $err) {
        $skippedList[] = str_starts_with((string)$err, $rel) ? $err : $rel . ': ' . $err;
    }
    $_SESSION['import_result'] = [
        'imported' => $job['imported'],
        'duplicates' => $job['duplicates'],
        'errors' => $skippedList,
        'new_examiners' => $job['new_examiners'],
        'source' => $job['source'],
        'done_dir' => $job['done_dir'],
    ];
    unset($_SESSION['import_job']);
    log_activity('import', $job['source'] . ': ' . $job['imported'] . ' importiert, ' . $job['duplicates'] . ' Duplikate, ' . count($skippedList) . ' übersprungen');
    redirect('admin/import', ['step' => 'done']);
}

/* ---------- Ergebnis ---------- */

if ($step === 'done' && isset($_SESSION['import_result'])) {
    $r = $_SESSION['import_result'];
    unset($_SESSION['import_result']);
    ?>
    <?= admin_head('Import abgeschlossen', e($r['source'])) ?>
    <div class="kpis">
      <?= kpi('check-circle', 'Importiert', (string)$r['imported'], 'Protokolle', 'success') ?>
      <?= kpi('copy', 'Übersprungen (Duplikate)', (string)$r['duplicates'], 'bereits vorhanden') ?>
      <?= kpi('users', 'Neue Prüfer', (string)count($r['new_examiners']), 'automatisch angelegt') ?>
      <?= kpi('alert', 'Nicht importiert', (string)count($r['errors']), 'siehe Liste', $r['errors'] ? 'warn' : '') ?>
    </div>
    <?php if (!empty($r['done_dir'])): ?>
      <div class="alert alert-info"><?= icon('info') ?><div>Die importierten Dateien wurden im FTP-Ordner nach <code><?= e(str_replace(APP_ROOT . '/', '', $r['done_dir'])) ?></code> verschoben. Du kannst sie dort nach einer Kontrolle löschen.</div></div>
    <?php endif; ?>
    <div class="grid grid-2">
      <?php if ($r['new_examiners']): ?>
        <div class="card"><h3>Neu angelegte Prüfer</h3><ul class="small"><?php foreach (array_unique($r['new_examiners']) as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/examiners')) ?>">Prüfer prüfen / zusammenführen</a></div>
      <?php endif; ?>
      <?php if ($r['errors']): ?>
        <div class="card"><h3>Nicht importiert</h3><ul class="small"><?php foreach (array_slice($r['errors'], 0, 200) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
    </div>
    <div class="form-actions mt-3">
      <a class="btn btn-primary" href="<?= e(url('admin/protocols', ['source' => 'import'])) ?>">Importierte Protokolle ansehen</a>
      <a class="btn btn-secondary" href="<?= e(url('admin/import')) ?>">Weiteren Import starten</a>
    </div>
    <?php
    return;
}

/* ---------- Vorschau ---------- */

if ($job && empty($job['running'])) {
    $files = Importer::listFiles($job['root']);
    $valid = [];
    $invalid = [];
    $groups = [];
    foreach ($files as $rel) {
        $item = Importer::analyze($rel, $job['exam_id'], $exams);
        if ($item['error']) {
            $invalid[] = $item;
            continue;
        }
        $valid[] = $item;
        $key = $item['exam_label'] . '|' . name_key($item['examiner']);
        if (!isset($groups[$key])) {
            $exists = DB::one('SELECT id, name FROM examiners WHERE exam_id = ? AND name_key = ?', [$item['exam_id'], name_key($item['examiner'])]);
            $groups[$key] = ['exam' => $item['exam_label'], 'examiner' => $item['examiner'], 'subject' => $item['subject'], 'existing' => $exists, 'count' => 0, 'dates' => 0, 'texts' => 0];
        }
        $groups[$key]['count']++;
        $groups[$key]['dates'] += $item['date'] ? 1 : 0;
        $groups[$key]['texts'] += in_array($item['ext'], ['txt', 'md'], true) ? 1 : 0;
    }
    $newCount = count(array_filter($groups, fn($g) => !$g['existing']));
    ?>
    <?= admin_head('Import-Vorschau', 'Quelle: ' . e($job['source']) . ($job['exam_id'] ? ' · Ziel: ' . e($exams[$job['exam_id']]['name'] ?? '') : ' · Prüfung aus Ordnernamen')) ?>
    <div class="kpis">
      <?= kpi('file-text', 'Dateien zum Import', (string)count($valid), 'werden zu Protokollen', 'success') ?>
      <?= kpi('users', 'Prüfer', (string)count($groups), $newCount . ' davon neu') ?>
      <?= kpi('alert', 'Werden übersprungen', (string)count($invalid), 'siehe unten', $invalid ? 'warn' : '') ?>
    </div>
    <?php if (!$valid): ?>
      <div class="alert alert-warning"><?= icon('alert') ?><div>Es wurden keine importierbaren Dateien gefunden. Prüfe die Ordnerstruktur (siehe Anleitung) und ob die Dateitypen erlaubt sind.</div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/import')) ?>" class="form-actions mb-3">
      <?= csrf_field() ?>
      <?php if ($valid): ?>
        <button type="submit" name="action" value="start" class="btn btn-primary btn-lg"><?= icon('upload') ?> <?= count($valid) ?> Dateien jetzt importieren</button>
      <?php endif; ?>
      <button type="submit" name="action" value="cancel" class="btn btn-secondary">Abbrechen</button>
    </form>
    <?php if ($groups): ?>
      <div class="card card-flush mb-2">
        <div class="card-header"><h3>Zuordnung</h3></div>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th>Prüfung</th><th>Prüfer</th><th>Fach</th><th class="num">Dateien</th><th class="num">davon mit Datum</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($groups as $g): ?>
              <tr>
                <td><?= e($g['exam']) ?></td>
                <td><strong><?= e($g['examiner']) ?></strong><?= $g['existing'] && $g['existing']['name'] !== $g['examiner'] ? '<span class="table-sub">→ bestehend: ' . e($g['existing']['name']) . '</span>' : '' ?></td>
                <td><?= e($g['subject'] ?: '–') ?></td>
                <td class="num"><?= $g['count'] ?><?= $g['texts'] ? '<span class="table-sub">' . $g['texts'] . ' als Text</span>' : '' ?></td>
                <td class="num"><?= $g['dates'] ?></td>
                <td><?= $g['existing'] ? badge('vorhanden', 'neutral') : badge('wird neu angelegt', 'warning') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endif; ?>
    <?php if ($invalid): ?>
      <div class="card card-flush">
        <div class="card-header"><h3>Werden übersprungen</h3></div>
        <div class="table-wrap"><table class="table table-compact"><tbody>
          <?php foreach (array_slice($invalid, 0, 200) as $it): ?>
            <tr><td class="mono small"><?= e($it['rel']) ?></td><td class="small"><?= e($it['error']) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
    <?php endif; ?>
    <?php
    return;
}

/* ---------- Startseite des Imports ---------- */
$ftpFiles = Importer::listFiles($importDir);
$serverLimit = Protocols::serverUploadLimit();
?>
<?= admin_head('Import / Migration', 'Bestehende Protokollsammlungen in die Datenbank übernehmen. Prüfer werden automatisch angelegt und zugeordnet.') ?>

<div class="grid grid-2" style="align-items:start">
  <div>
    <form method="post" action="<?= e(url('admin/import')) ?>" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="zip">
      <h3><?= icon('archive') ?> ZIP-Archiv hochladen</h3>
      <p class="small muted">Ideal für kleinere Sammlungen. Upload-Limit dieses Servers: <strong><?= e($serverLimit ? human_filesize($serverLimit) : 'unbekannt') ?></strong>. Größere Sammlungen bitte in mehrere ZIPs aufteilen oder per FTP importieren.</p>
      <div class="dropzone mb-2"><input type="file" name="zip" accept=".zip" required><ul class="file-names"></ul></div>
      <?= field_select('exam_id', 'Prüfung', $examOptions, '', ['id' => 'f_exam_zip']) ?>
      <button type="submit" class="btn btn-primary"><?= icon('search') ?> Hochladen &amp; Vorschau</button>
      <?php if (!class_exists('ZipArchive')): ?><p class="small" style="color:var(--c-danger)">Die PHP-Erweiterung „zip“ fehlt auf diesem Server – bitte den FTP-Import nutzen.</p><?php endif; ?>
    </form>

    <form method="post" action="<?= e(url('admin/import')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="folder">
      <h3><?= icon('folder') ?> Import aus FTP-Ordner</h3>
      <p class="small muted">Für große Sammlungen: Lade die Ordner per FTP nach <code><?= e(str_replace(APP_ROOT . '/', '', $importDir)) ?>/</code> hoch und starte dann hier den Import. Er läuft in Etappen und funktioniert auch mit tausenden Dateien. Importierte Dateien werden nach <code>_importiert/</code> verschoben.</p>
      <p><strong><?= count($ftpFiles) ?></strong> Dateien im Ordner gefunden.</p>
      <?= field_select('exam_id', 'Prüfung', $examOptions, '', ['id' => 'f_exam_folder']) ?>
      <button type="submit" class="btn btn-primary"<?= $ftpFiles ? '' : ' disabled' ?>><?= icon('search') ?> Ordner analysieren &amp; Vorschau</button>
    </form>

    <form method="post" action="<?= e(url('admin/import')) ?>" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="csv">
      <h3><?= icon('file-text') ?> CSV-Import (Text-Protokolle)</h3>
      <p class="small muted">Für Protokolle, die als Tabelle vorliegen (z. B. Export aus einer alten Datenbank oder Excel). Kopfzeile mit den Spalten <code>Prüfung; Prüfer; Fach; Datum; Titel; Text; Note</code> – nur <code>Prüfer</code> und <code>Text</code> sind Pflicht. Trennzeichen <code>;</code> oder <code>,</code>.</p>
      <div class="dropzone mb-2"><input type="file" name="csv" accept=".csv,.txt" required><ul class="file-names"></ul></div>
      <?= field_select('exam_id', 'Prüfung', $examOptions, '', ['id' => 'f_exam_csv']) ?>
      <button type="submit" class="btn btn-primary"><?= icon('upload') ?> CSV importieren</button>
    </form>
  </div>

  <div class="card">
    <h3>So bereitest du die Sammlung vor</h3>
    <p class="small">Lege für jede Prüfung einen Ordner an (Name wie der Kurzname, z. B. <code>M1</code>, <code>M2</code>, <code>M3</code>) und darin für jeden Prüfer einen Unterordner. Das Fach kann in Klammern angegeben werden:</p>
    <div class="mail-body" style="max-height:none">M1/
  Prof. Dr. Anna Beispiel (Anatomie)/
    Protokoll 2023-03-14.pdf
    Protokoll Herbst 2022.docx
  PD Dr. Clara Probe (Physiologie)/
    2024-09-02 Gedächtnisprotokoll.pdf
    notizen.txt
M3/
  Prof. Dr. Ingo Schablone (Chirurgie)/
    ...</div>
    <ul class="small mt-2">
      <li>Jede Datei wird zu einem eigenen Protokoll. Der Dateiname wird zum Titel.</li>
      <li>Ein Datum im Dateinamen (<code>2023-03-14</code> oder <code>14.03.2023</code>) wird als Prüfungsdatum übernommen.</li>
      <li><code>.txt</code>-Dateien werden als lesbarer Text übernommen, alle anderen als Datei (PDF, Word, Bilder …).</li>
      <li>Bestehende Prüfer werden erkannt, auch wenn Titel oder Schreibweise leicht abweichen („Prof. Dr. Müller“ = „Müller“).</li>
      <li>Doppelte Dateien werden automatisch übersprungen – ein Import kann also gefahrlos wiederholt werden.</li>
      <li>Wählst du oben eine feste Prüfung, entfällt die oberste Ordnerebene: <code>Prüfer/Datei</code>.</li>
    </ul>
    <p class="small muted mb-0">Erlaubte Dateitypen: <?= e(implode(', ', Importer::allowedExtensions())) ?> (änderbar unter Einstellungen).</p>
  </div>
</div>
