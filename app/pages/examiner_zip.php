<?php
/** Alle Protokolle eines Prüfers als ZIP-Archiv herunterladen. */
$user = Auth::user();
$examiner = DB::one('SELECT e.*, x.name AS exam_name FROM examiners e JOIN exams x ON x.id = e.exam_id WHERE e.id = ?', [get_int('id')]);
if (!$examiner) {
    abort(404);
}
if (!Payments::hasExaminerAccess($user, (int)$examiner['id'])) {
    abort(403, 'Bitte schalte den Prüfer zuerst frei.');
}
if (!class_exists('ZipArchive')) {
    flash('error', 'Der ZIP-Download ist auf diesem Server nicht verfügbar. Bitte lade die Dateien einzeln herunter.');
    redirect('examiner', ['id' => $examiner['id']]);
}
$protocols = DB::all(
    "SELECT * FROM protocols WHERE examiner_id = ? AND status = 'published' ORDER BY COALESCE(exam_date, DATE(created_at)) DESC, id DESC",
    [$examiner['id']]
);
if (!$protocols) {
    flash('info', 'Für diesen Prüfer gibt es noch keine Protokolle.');
    redirect('examiner', ['id' => $examiner['id']]);
}

$tmpDir = is_writable(STORAGE_PATH . '/tmp') ? STORAGE_PATH . '/tmp' : sys_get_temp_dir();
$tmp = tempnam($tmpDir, 'zip');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    abort(500, 'Das ZIP-Archiv konnte nicht erstellt werden.');
}
$notice = 'Heruntergeladen von ' . $user['email'] . ' am ' . date('d.m.Y H:i') . ' – nur zur persönlichen Prüfungsvorbereitung, Weitergabe nicht gestattet.';
$zip->setArchiveComment($notice);
foreach ($protocols as $i => $p) {
    $folder = sprintf('%02d', $i + 1) . ' ' . ($p['exam_date'] ? $p['exam_date'] . ' ' : '') . Protocols::cleanFilename(mb_substr($p['title'], 0, 80));
    if (trim((string)$p['content']) !== '') {
        $text = $p['title'] . "\r\n"
            . str_repeat('=', min(70, mb_strlen($p['title']))) . "\r\n"
            . 'Prüfer: ' . $examiner['name'] . ($examiner['subject'] !== '' ? ' (' . $examiner['subject'] . ')' : '') . "\r\n"
            . 'Prüfung: ' . $examiner['exam_name'] . "\r\n"
            . 'Datum: ' . fmt_date($p['exam_date'], 'unbekannt') . "\r\n"
            . ($p['grade'] ? 'Note: ' . $p['grade'] . "\r\n" : '')
            . "\r\n" . str_replace(["\r\n", "\n"], "\r\n", (string)$p['content']) . "\r\n\r\n---\r\n" . $notice . "\r\n";
        $zip->addFromString($folder . '/Protokoll.txt', "\xEF\xBB\xBF" . $text);
    }
    foreach (DB::all('SELECT * FROM protocol_files WHERE protocol_id = ?', [$p['id']]) as $f) {
        $path = Protocols::filePath($f);
        if (is_file($path)) {
            $zip->addFile($path, $folder . '/' . $f['original_name']);
        }
    }
}
$zip->close();

DB::insert('download_log', ['user_id' => $user['id'], 'protocol_id' => null, 'file_id' => null, 'kind' => 'zip:' . $examiner['id'], 'created_at' => now()]);
log_activity('download_zip', $examiner['name'] . ' (' . count($protocols) . ' Protokolle)');

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}
$filename = 'Protokolle ' . $examiner['name'] . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
readfile($tmp);
@unlink($tmp);
exit;
