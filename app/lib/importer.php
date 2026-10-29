<?php
/**
 * Import / Migration bestehender Protokollsammlungen.
 *
 * Erwartete Ordnerstruktur (ZIP oder FTP-Ordner storage/import/):
 *   M1/Prof. Dr. Anna Beispiel (Anatomie)/Protokoll 2023-03-14.pdf
 *   <Prüfung>/<Prüfer (Fach)>/<Datei>
 * oder – wenn beim Import eine feste Prüfung gewählt wird –
 *   <Prüfer (Fach)>/<Datei>
 *
 * Jede Datei wird zu einem Protokoll. .txt/.md-Dateien werden als Text übernommen.
 * Unbekannte Prüfer werden automatisch angelegt, Duplikate (gleicher Dateiinhalt) übersprungen.
 */
class Importer
{
    private const SKIP_NAMES = ['__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini', '_importiert'];

    public static function importDir(): string
    {
        return STORAGE_PATH . '/import';
    }

    public static function tmpDir(): string
    {
        return STORAGE_PATH . '/tmp';
    }

    /** Alle Dateien unterhalb von $root als relative Pfade. */
    public static function listFiles(string $root): array
    {
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $parts = explode('/', $rel);
            $skip = false;
            foreach ($parts as $part) {
                if ($part === '' || $part[0] === '.' || in_array($part, self::SKIP_NAMES, true)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip && count($parts) === 1 && in_array($parts[0], ['index.html', 'LIESMICH.txt'], true)) {
                $skip = true;
            }
            if (!$skip) {
                $out[] = $rel;
            }
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function exams(): array
    {
        $map = [];
        foreach (DB::all('SELECT * FROM exams') as $x) {
            $map[(int)$x['id']] = $x;
        }
        return $map;
    }

    /** Ordnet einen Ordnernamen (z. B. "M1", "Physikum", "M1 - Physikum") einer Prüfung zu. */
    public static function matchExam(string $folder, array $exams): ?array
    {
        $f = slugify($folder);
        foreach ($exams as $x) {
            $cands = array_filter([slugify((string)$x['short_name']), (string)$x['slug'], slugify((string)$x['name'])], fn($c) => $c !== '' && $c !== 'eintrag');
            foreach ($cands as $c) {
                if ($f === $c || str_starts_with($f, $c . '-') || str_ends_with($f, '-' . $c)) {
                    return $x;
                }
            }
        }
        return null;
    }

    /** "Prof. Dr. Anna Beispiel (Anatomie)" → ["Prof. Dr. Anna Beispiel", "Anatomie"] */
    public static function parseExaminerFolder(string $folder): array
    {
        $folder = trim((string)preg_replace('/[_\s]+/u', ' ', $folder));
        if (preg_match('/^(.*?)\s*[\(\[]([^\)\]]+)[\)\]]$/u', $folder, $m) && trim($m[1]) !== '') {
            return [trim($m[1]), trim($m[2])];
        }
        return [$folder, ''];
    }

    public static function titleFromFilename(string $file): string
    {
        $t = pathinfo($file, PATHINFO_FILENAME);
        $t = trim((string)preg_replace('/[_\s]+/u', ' ', $t));
        return $t !== '' ? mb_substr($t, 0, 200) : 'Protokoll';
    }

    /** Erkennt Daten wie 2023-03-14, 2023_03_14 oder 14.03.2023 im Text. */
    public static function dateFromString(string $s): ?string
    {
        if (preg_match('/((?:19|20)\d{2})[-_.](\d{1,2})[-_.](\d{1,2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.((?:19|20)\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    public static function allowedExtensions(): array
    {
        return array_values(array_unique(array_merge(Protocols::allowedExtensions(), ['txt', 'md'])));
    }

    /** Analysiert eine Datei: Prüfung, Prüfer, Fach, Titel, Datum oder Fehler. */
    public static function analyze(string $rel, ?int $fixedExamId, array $exams): array
    {
        $parts = explode('/', $rel);
        $file = (string)array_pop($parts);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $item = ['rel' => $rel, 'file' => $file, 'ext' => $ext, 'exam_id' => null, 'exam_label' => '', 'examiner' => '', 'subject' => '', 'title' => self::titleFromFilename($file), 'date' => null, 'error' => null];

        if ($fixedExamId) {
            $item['exam_id'] = $fixedExamId;
            $item['exam_label'] = $exams[$fixedExamId]['name'] ?? '?';
        } else {
            if (count($parts) < 2) {
                $item['error'] = 'Erwartete Struktur: Prüfung/Prüfer/Datei';
                return $item;
            }
            $examFolder = array_shift($parts);
            $exam = self::matchExam($examFolder, $exams);
            if (!$exam) {
                $item['error'] = 'Prüfung „' . $examFolder . '“ nicht gefunden (bitte zuerst anlegen oder Ordner umbenennen)';
                return $item;
            }
            $item['exam_id'] = (int)$exam['id'];
            $item['exam_label'] = $exam['name'];
        }
        if (!$parts) {
            $item['error'] = 'Datei liegt nicht in einem Prüfer-Ordner';
            return $item;
        }
        [$name, $subject] = self::parseExaminerFolder($parts[0]);
        if (name_key($name) === '') {
            $item['error'] = 'Prüfername „' . $parts[0] . '“ ungültig';
            return $item;
        }
        $item['examiner'] = $name;
        $item['subject'] = $subject;
        if (!in_array($ext, self::allowedExtensions(), true)) {
            $item['error'] = 'Dateityp .' . $ext . ' ist nicht erlaubt';
            return $item;
        }
        $item['date'] = self::dateFromString($file) ?? self::dateFromString(implode('/', $parts));
        return $item;
    }

    /** Liest Textdateien und konvertiert sie nach UTF-8. */
    public static function readText(string $path): string
    {
        $s = (string)file_get_contents($path);
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return trim(str_replace(["\r\n", "\r"], "\n", $s));
    }

    /**
     * Importiert eine analysierte Datei.
     * Rückgabe: ['status' => imported|duplicate|error, 'new_examiner' => ?string, 'error' => ?string]
     */
    public static function importItem(string $root, array $item, ?string $doneDir): array
    {
        $path = $root . '/' . $item['rel'];
        $result = ['status' => 'imported', 'new_examiner' => null, 'error' => null];
        try {
            $isText = in_array($item['ext'], ['txt', 'md'], true);
            $text = $isText ? self::readText($path) : null;
            $sha1 = sha1_file($path);

            $created = false;
            $examinerId = Protocols::findOrCreateExaminer((int)$item['exam_id'], $item['examiner'], $item['subject'], null, true, $created);
            if ($created) {
                $result['new_examiner'] = $item['examiner'];
            } elseif ($item['subject'] !== '') {
                DB::q("UPDATE examiners SET subject = ? WHERE id = ? AND subject = ''", [mb_substr($item['subject'], 0, 100), $examinerId]);
            }

            $duplicate = $isText
                ? (int)DB::val('SELECT COUNT(*) FROM protocols WHERE examiner_id = ? AND content = ?', [$examinerId, $text]) > 0
                : (int)DB::val('SELECT COUNT(*) FROM protocol_files WHERE sha1 = ?', [$sha1]) > 0;
            if ($duplicate || ($isText && $text === '')) {
                $result['status'] = 'duplicate';
            } else {
                $pid = Protocols::create([
                    'examiner_id' => $examinerId,
                    'user_id' => null,
                    'title' => $item['title'],
                    'exam_date' => $item['date'],
                    'content' => $text,
                    'status' => 'published',
                    'source' => 'import',
                ]);
                if (!$isText) {
                    Protocols::storeFile($pid, $path, $item['file'], 'copy');
                }
            }
        } catch (Throwable $e) {
            return ['status' => 'error', 'new_examiner' => null, 'error' => $item['rel'] . ': ' . $e->getMessage()];
        }

        // Quelldatei wegräumen, damit der nächste Durchlauf sie nicht erneut findet
        if ($doneDir) {
            $target = $doneDir . '/' . $item['rel'];
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            if (!@rename($path, $target)) {
                @unlink($path);
            }
        } else {
            @unlink($path);
        }
        return $result;
    }

    /** Entpackt ein ZIP sicher (ohne Pfad-Traversal). Gibt die Anzahl der Dateien zurück. */
    public static function extractZip(string $zipFile, string $target): int
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung „zip“ ist nicht verfügbar. Bitte nutze den FTP-Import.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht geöffnet werden.');
        }
        $totalSize = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalSize += (int)($stat['size'] ?? 0);
        }
        if ($totalSize > 4 * 1024 * 1024 * 1024) {
            $zip->close();
            throw new RuntimeException('Das ZIP ist entpackt größer als 4 GB. Bitte in kleinere Archive aufteilen.');
        }
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $raw = (string)$zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            $name = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'CP850');
            $name = str_replace('\\', '/', $name);
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $name), fn($p) => $p !== '' && $p !== '.' && $p !== '..'));
            if (!$parts) {
                continue;
            }
            $safe = implode('/', array_map(fn($p) => Protocols::cleanFilename($p), $parts));
            $dest = $target . '/' . $safe;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true) && !is_dir(dirname($dest))) {
                continue;
            }
            if (method_exists($zip, 'getStreamIndex')) {
                $in = $zip->getStreamIndex($i);
                $out = @fopen($dest, 'wb');
                if ($in && $out) {
                    stream_copy_to_stream($in, $out);
                }
                if ($in) {
                    fclose($in);
                }
                if ($out) {
                    fclose($out);
                }
            } else {
                file_put_contents($dest, (string)$zip->getFromIndex($i));
            }
            $count++;
        }
        $zip->close();
        return $count;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Importiert Protokolle aus einer CSV-Datei.
     * Spalten (Kopfzeile, Reihenfolge egal): Prüfung, Prüfer, Fach, Datum, Titel, Text, Note
     */
    public static function importCsv(string $path, ?int $fixedExamId): array
    {
        $exams = self::exams();
        $raw = (string)file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delims = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = (string)array_key_first($delims);

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim, '"', '\\');
        if (!$header) {
            return ['imported' => 0, 'duplicates' => 0, 'errors' => ['Die CSV-Datei ist leer.'], 'new_examiners' => []];
        }
        $aliases = [
            'exam' => ['pruefung', 'prufung', 'exam', 'abschnitt'],
            'examiner' => ['pruefer', 'prufer', 'prueferin', 'examiner', 'name'],
            'subject' => ['fach', 'subject'],
            'date' => ['datum', 'date', 'pruefungsdatum'],
            'title' => ['titel', 'title', 'ueberschrift'],
            'content' => ['text', 'inhalt', 'protokoll', 'content'],
            'grade' => ['note', 'grade'],
        ];
        $map = [];
        foreach ($header as $idx => $h) {
            $key = str_replace('-', '', slugify((string)$h));
            foreach ($aliases as $field => $names) {
                if (in_array($key, $names, true)) {
                    $map[$field] = $idx;
                }
            }
        }
        $stats = ['imported' => 0, 'duplicates' => 0, 'errors' => [], 'new_examiners' => []];
        if (!isset($map['examiner'], $map['content'])) {
            $stats['errors'][] = 'Die Kopfzeile muss mindestens die Spalten „Prüfer“ und „Text“ enthalten.';
            fclose($fh);
            return $stats;
        }
        if (!$fixedExamId && !isset($map['exam'])) {
            $stats['errors'][] = 'Bitte wähle eine Prüfung oder füge eine Spalte „Prüfung“ hinzu.';
            fclose($fh);
            return $stats;
        }
        $line = 1;
        while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            $line++;
            if ($row === [null] || count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                continue;
            }
            $get = fn(string $f) => isset($map[$f]) ? trim((string)($row[$map[$f]] ?? '')) : '';
            $examId = $fixedExamId;
            if (!$examId) {
                $exam = self::matchExam($get('exam'), $exams);
                if (!$exam) {
                    $stats['errors'][] = 'Zeile ' . $line . ': Prüfung „' . $get('exam') . '“ nicht gefunden.';
                    continue;
                }
                $examId = (int)$exam['id'];
            }
            $name = $get('examiner');
            $content = str_replace(["\r\n", "\r"], "\n", $get('content'));
            if (name_key($name) === '' || $content === '') {
                $stats['errors'][] = 'Zeile ' . $line . ': Prüfer oder Text fehlt.';
                continue;
            }
            $created = false;
            $eid = Protocols::findOrCreateExaminer($examId, $name, $get('subject'), null, true, $created);
            if ($created) {
                $stats['new_examiners'][] = $name;
            }
            if ((int)DB::val('SELECT COUNT(*) FROM protocols WHERE examiner_id = ? AND content = ?', [$eid, $content]) > 0) {
                $stats['duplicates']++;
                continue;
            }
            $date = $get('date') !== '' ? self::dateFromString($get('date')) : null;
            $exName = (string)DB::val('SELECT name FROM examiners WHERE id = ?', [$eid]);
            Protocols::create([
                'examiner_id' => $eid,
                'title' => $get('title') !== '' ? $get('title') : Protocols::defaultTitle($exName, $date),
                'exam_date' => $date,
                'grade' => $get('grade'),
                'content' => $content,
                'status' => 'published',
                'source' => 'import',
            ]);
            $stats['imported']++;
        }
        fclose($fh);
        return $stats;
    }
}
