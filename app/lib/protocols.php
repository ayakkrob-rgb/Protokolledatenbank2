<?php
/**
 * Protokolle, Dateien und Prüfer.
 */
class Protocols
{
    /** Erlaubte Dateitypen und der beim Ausliefern verwendete MIME-Typ. */
    public const MIME = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'txt' => 'text/plain',
        'md' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'pages' => 'application/octet-stream',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];

    /** Im Browser direkt anzeigbare Dateitypen. */
    public const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'md'];

    public static function storageDir(): string
    {
        return STORAGE_PATH . '/uploads';
    }

    public static function allowedExtensions(): array
    {
        $list = preg_split('/[\s,;]+/', mb_strtolower((string)setting('allowed_extensions'))) ?: [];
        return array_values(array_filter(array_unique(array_map(fn($x) => ltrim(trim($x), '.'), $list)), fn($x) => isset(self::MIME[$x])));
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        switch ($unit) {
            case 'g':
                return (int)($num * 1073741824);
            case 'm':
                return (int)($num * 1048576);
            case 'k':
                return (int)($num * 1024);
        }
        return (int)$num;
    }

    public static function serverUploadLimit(): int
    {
        $limits = array_filter([self::iniBytes((string)ini_get('upload_max_filesize')), self::iniBytes((string)ini_get('post_max_size'))]);
        return $limits ? min($limits) : 0;
    }

    public static function maxBytes(): int
    {
        $configured = max(1, Settings::int('max_upload_mb')) * 1048576;
        $server = self::serverUploadLimit();
        return $server > 0 ? min($configured, $server) : $configured;
    }

    public static function mimeFor(string $filename): string
    {
        return self::MIME[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    public static function isInline(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::INLINE, true);
    }

    public static function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name);
        $name = trim((string)$name, " .\t");
        return $name !== '' ? mb_substr($name, 0, 200) : 'datei';
    }

    /* ---------- Prüfer ---------- */

    /**
     * Sucht einen Prüfer per normalisiertem Namen innerhalb der Prüfung
     * oder legt ihn neu an. $created wird auf true gesetzt, wenn neu angelegt.
     */
    public static function findOrCreateExaminer(int $examId, string $name, string $subject = '', ?int $userId = null, bool $byStaff = false, ?bool &$created = null): int
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        $key = name_key($name);
        if ($key === '') {
            throw new InvalidArgumentException('Bitte gib den Namen des Prüfers an.');
        }
        $existing = DB::one('SELECT id FROM examiners WHERE exam_id = ? AND name_key = ? ORDER BY is_active DESC, id LIMIT 1', [$examId, $key]);
        if ($existing) {
            $created = false;
            return (int)$existing['id'];
        }
        $created = true;
        $id = DB::insert('examiners', [
            'exam_id' => $examId,
            'name' => mb_substr($name, 0, 150),
            'name_key' => $key,
            'subject' => mb_substr(trim($subject), 0, 100),
            'is_active' => 1,
            'needs_review' => (!$byStaff && Settings::bool('new_examiner_review')) ? 1 : 0,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
        log_activity('examiner_created', $name . ' (Prüfung #' . $examId . ')', $userId);
        return $id;
    }

    /** Führt Prüfer $sourceId in $targetId zusammen (Protokolle & Freischaltungen wandern mit). */
    public static function mergeExaminers(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }
        DB::tx(function () use ($sourceId, $targetId) {
            DB::q('UPDATE protocols SET examiner_id = ? WHERE examiner_id = ?', [$targetId, $sourceId]);
            DB::q('INSERT IGNORE INTO purchase_examiners (purchase_id, examiner_id) SELECT purchase_id, ? FROM purchase_examiners WHERE examiner_id = ?', [$targetId, $sourceId]);
            DB::q('DELETE FROM purchase_examiners WHERE examiner_id = ?', [$sourceId]);
            DB::q('DELETE FROM examiners WHERE id = ?', [$sourceId]);
        });
        Payments::clearCache();
    }

    /* ---------- Protokolle ---------- */

    public static function create(array $d): int
    {
        $status = $d['status'] ?? 'published';
        return DB::insert('protocols', [
            'examiner_id' => (int)$d['examiner_id'],
            'user_id' => $d['user_id'] ?? null,
            'submission_id' => $d['submission_id'] ?? null,
            'title' => mb_substr(trim((string)$d['title']), 0, 200),
            'exam_date' => !empty($d['exam_date']) ? $d['exam_date'] : null,
            'grade' => isset($d['grade']) && $d['grade'] !== '' ? mb_substr((string)$d['grade'], 0, 20) : null,
            'content' => isset($d['content']) && trim((string)$d['content']) !== '' ? (string)$d['content'] : null,
            'status' => $status,
            'source' => $d['source'] ?? 'upload',
            'created_at' => $d['created_at'] ?? now(),
            'updated_at' => now(),
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    public static function defaultTitle(string $examinerName, ?string $examDate): string
    {
        return 'Protokoll ' . $examinerName . ($examDate ? ' – ' . fmt_month($examDate) : '');
    }

    /** Veröffentlicht ein Protokoll und löst ggf. die Rückerstattung aus. */
    public static function publish(int $id): array
    {
        $p = DB::one('SELECT * FROM protocols WHERE id = ?', [$id]);
        if (!$p) {
            return ['status' => 'not_applicable'];
        }
        if ($p['status'] !== 'published') {
            DB::update('protocols', ['status' => 'published', 'published_at' => now(), 'reject_reason' => null, 'updated_at' => now()], 'id = ?', [$id]);
            log_activity('protocol_published', 'Protokoll #' . $id);
            if ($p['user_id'] && $p['source'] === 'upload') {
                Mails::protocolPublished($p);
            }
        }
        return Payments::refundForProtocol($id);
    }

    public static function reject(int $id, string $reason): void
    {
        $p = DB::one('SELECT * FROM protocols WHERE id = ?', [$id]);
        if (!$p) {
            return;
        }
        DB::update('protocols', ['status' => 'rejected', 'reject_reason' => $reason, 'updated_at' => now()], 'id = ?', [$id]);
        log_activity('protocol_rejected', 'Protokoll #' . $id . ($reason !== '' ? ': ' . $reason : ''));
        if ($p['user_id'] && $p['source'] === 'upload') {
            Mails::protocolRejected($p, $reason);
        }
    }

    public static function delete(int $id): void
    {
        foreach (DB::all('SELECT * FROM protocol_files WHERE protocol_id = ?', [$id]) as $f) {
            @unlink(self::filePath($f));
        }
        DB::delete('protocols', 'id = ?', [$id]);
        log_activity('protocol_deleted', 'Protokoll #' . $id);
    }

    /* ---------- Dateien ---------- */

    /** $_FILES-Eintrag (einzeln oder mehrfach) in eine Liste umwandeln. */
    public static function normalizeFiles($f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $out = [];
        if (!is_array($f['name'])) {
            if ((int)$f['error'] !== UPLOAD_ERR_NO_FILE) {
                $out[] = $f;
            }
            return $out;
        }
        foreach ($f['name'] as $i => $name) {
            if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'type' => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => (int)$f['error'][$i],
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /** Prüft eine hochgeladene Datei. Gibt eine Fehlermeldung oder null zurück. */
    public static function validateUpload(array $file): ?string
    {
        $name = (string)$file['name'];
        switch ((int)$file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '„' . $name . '“ ist zu groß (maximal ' . human_filesize(self::maxBytes()) . ').';
            case UPLOAD_ERR_PARTIAL:
                return '„' . $name . '“ wurde nur teilweise hochgeladen. Bitte erneut versuchen.';
            default:
                return '„' . $name . '“ konnte nicht hochgeladen werden (Fehlercode ' . (int)$file['error'] . ').';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::allowedExtensions(), true)) {
            return '„' . $name . '“: Dateityp nicht erlaubt. Erlaubt sind: ' . implode(', ', self::allowedExtensions()) . '.';
        }
        if ((int)$file['size'] > self::maxBytes()) {
            return '„' . $name . '“ ist zu groß (maximal ' . human_filesize(self::maxBytes()) . ').';
        }
        if ((int)$file['size'] === 0) {
            return '„' . $name . '“ ist leer.';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return '„' . $name . '“: ungültiger Upload.';
        }
        return self::checkSignature($file['tmp_name'], $ext) ? null : '„' . $name . '“ scheint beschädigt zu sein oder hat nicht den angegebenen Dateityp.';
    }

    /** Einfache Prüfung der Dateisignatur für häufige Formate. */
    public static function checkSignature(string $path, string $ext): bool
    {
        $head = (string)@file_get_contents($path, false, null, 0, 16);
        switch ($ext) {
            case 'pdf':
                return str_contains(substr($head, 0, 1024), '%PDF') || str_contains((string)@file_get_contents($path, false, null, 0, 1024), '%PDF');
            case 'png':
                return str_starts_with($head, "\x89PNG");
            case 'jpg':
            case 'jpeg':
                return str_starts_with($head, "\xFF\xD8");
            case 'docx':
            case 'odt':
            case 'pptx':
            case 'xlsx':
            case 'zip':
                return str_starts_with($head, "PK");
            case 'doc':
            case 'xls':
            case 'ppt':
                return str_starts_with($head, "\xD0\xCF\x11\xE0") || str_starts_with($head, '{\rtf');
            default:
                return true;
        }
    }

    /**
     * Speichert eine Datei im geschützten Speicher und verknüpft sie mit dem Protokoll.
     * $mode: 'upload' (move_uploaded_file), 'move' oder 'copy'
     */
    public static function storeFile(int $protocolId, string $sourcePath, string $originalName, string $mode = 'upload'): int
    {
        $sub = date('Y/m');
        $dir = self::storageDir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Speicherordner konnte nicht angelegt werden: ' . $dir);
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        $stored = $sub . '/' . random_token(16) . '.bin';
        $target = self::storageDir() . '/' . $stored;
        if ($mode === 'upload') {
            $ok = move_uploaded_file($sourcePath, $target);
        } elseif ($mode === 'move') {
            $ok = @rename($sourcePath, $target);
            if (!$ok && @copy($sourcePath, $target)) {
                @unlink($sourcePath);
                $ok = true;
            }
        } else {
            $ok = @copy($sourcePath, $target);
        }
        if (!$ok) {
            throw new RuntimeException('Die Datei „' . $originalName . '“ konnte nicht gespeichert werden.');
        }
        @chmod($target, 0644);
        return DB::insert('protocol_files', [
            'protocol_id' => $protocolId,
            'original_name' => self::cleanFilename($originalName),
            'stored_name' => $stored,
            'mime' => self::mimeFor($originalName),
            'size' => (int)filesize($target),
            'sha1' => sha1_file($target) ?: null,
            'created_at' => now(),
        ]);
    }

    /** Speichert einen Dateiinhalt (z. B. generiertes PDF) direkt. */
    public static function storeContent(int $protocolId, string $content, string $originalName): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdb');
        file_put_contents($tmp, $content);
        return self::storeFile($protocolId, $tmp, $originalName, 'move');
    }

    public static function filePath(array $file): string
    {
        return self::storageDir() . '/' . $file['stored_name'];
    }

    public static function deleteFile(int $fileId): void
    {
        $f = DB::one('SELECT * FROM protocol_files WHERE id = ?', [$fileId]);
        if ($f) {
            @unlink(self::filePath($f));
            DB::delete('protocol_files', 'id = ?', [$fileId]);
        }
    }

    public static function fileIcon(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'], true) ? 'eye' : 'file-text';
    }

    /* ---------- Hilfen für Listen ---------- */

    /** Alle aktiven Prüfer einer Prüfung mit Anzahl veröffentlichter Protokolle. */
    public static function examinersForExam(int $examId, bool $includeInactive = false): array
    {
        return DB::all(
            "SELECT e.*,
                (SELECT COUNT(*) FROM protocols p WHERE p.examiner_id = e.id AND p.status = 'published') AS protocol_count,
                (SELECT MAX(COALESCE(p.exam_date, DATE(p.created_at))) FROM protocols p WHERE p.examiner_id = e.id AND p.status = 'published') AS last_date
             FROM examiners e WHERE e.exam_id = ?" . ($includeInactive ? '' : ' AND e.is_active = 1') . "
             ORDER BY (e.subject = ''), e.subject, e.name",
            [$examId]
        );
    }

    /** Gruppiert Prüfer nach Fach. */
    public static function groupBySubject(array $examiners): array
    {
        $groups = [];
        foreach ($examiners as $e) {
            $key = trim((string)$e['subject']) !== '' ? $e['subject'] : 'Weitere Prüferinnen und Prüfer';
            $groups[$key][] = $e;
        }
        return $groups;
    }

    /** Wasserzeichen (SVG als Data-URI) mit der E-Mail-Adresse der Betrachterin/des Betrachters. */
    public static function watermarkStyle(array $user): string
    {
        if (!Settings::bool('watermark')) {
            return '';
        }
        $text = htmlspecialchars($user['email'] . ' · ' . date('d.m.Y'), ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="200"><text x="20" y="120" transform="rotate(-22 180 100)" fill="rgba(120,120,120,0.10)" font-family="Arial" font-size="15">' . $text . '</text></svg>';
        return "background-image:url(\"data:image/svg+xml;charset=utf-8," . rawurlencode($svg) . "\");";
    }
}
