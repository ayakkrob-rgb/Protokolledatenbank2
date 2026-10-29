<?php
/** Geschützter Datei-Download bzw. Inline-Ansicht. */
$user = Auth::user();
$f = DB::one(
    'SELECT f.*, p.examiner_id, p.user_id, p.status, p.id AS pid
     FROM protocol_files f JOIN protocols p ON p.id = f.protocol_id WHERE f.id = ?',
    [get_int('id')]
);
if (!$f) {
    abort(404);
}
if (!Payments::canViewProtocol($user, ['user_id' => $f['user_id'], 'status' => $f['status'], 'examiner_id' => $f['examiner_id']])) {
    abort(403, 'Bitte schalte den Prüfer zuerst frei.');
}
$path = Protocols::filePath($f);
if (!is_file($path)) {
    abort(404, 'Die Datei wurde auf dem Server nicht gefunden.');
}

$inline = get_int('inline') === 1 && Protocols::isInline($f['original_name']);
$own = (int)$f['user_id'] === (int)$user['id'] || Auth::isStaff();
if (!$own) {
    DB::insert('download_log', [
        'user_id' => $user['id'],
        'protocol_id' => $f['pid'],
        'file_id' => $f['id'],
        'kind' => $inline ? 'view' : 'download',
        'created_at' => now(),
    ]);
    if (!$inline) {
        DB::q('UPDATE protocols SET download_count = download_count + 1 WHERE id = ?', [$f['pid']]);
    }
}

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}
$name = $f['original_name'];
$mime = Protocols::mimeFor($name);
$fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') ? '; charset=utf-8' : ''));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
if ($mime === 'application/pdf') {
    // Der PDF-Viewer des Browsers verträgt keine restriktive CSP.
    header_remove('Content-Security-Policy');
} else {
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
}
readfile($path);
exit;
