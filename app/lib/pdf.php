<?php
/**
 * Minimaler PDF-Generator (nur Text, Helvetica) – wird für die Demo-Protokolle verwendet.
 */
class SimplePdf
{
    private static function enc(string $s): string
    {
        $s = strtr($s, ['≥' => '>=', '≤' => '<=', '→' => '->', '←' => '<-', '↑' => '(erhöht)', '↓' => '(erniedrigt)', '✓' => '+', '…' => '...']);
        $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return $out === false ? $s : $out;
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    /** Erzeugt ein PDF aus Titel und (Mini-Markdown-)Text. */
    public static function fromText(string $title, string $body, string $footer = ''): string
    {
        $lines = [['F2', 16, self::enc($title)], ['F1', 6, '']];
        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $raw) {
            $raw = rtrim($raw);
            $font = 'F1';
            $size = 10.5;
            if (preg_match('/^#{1,4}\s*(.*)$/', $raw, $m)) {
                $lines[] = ['F1', 5, ''];
                $font = 'F2';
                $size = 12;
                $raw = $m[1];
            }
            $raw = str_replace('**', '', $raw);
            $indent = '';
            if (preg_match('/^[-*•]\s+(.*)$/u', $raw, $m)) {
                $raw = "\u{2022}  " . $m[1];
                $indent = '    ';
            }
            $encoded = self::enc($raw);
            $max = (int)floor(495 / ($size * 0.5));
            $wrapped = $encoded === '' ? [''] : explode("\n", wordwrap($encoded, $max, "\n", true));
            foreach ($wrapped as $i => $part) {
                $lines[] = [$font, $size, ($i > 0 ? $indent : '') . $part];
            }
        }

        // Seiten aufteilen
        $pages = [];
        $current = [];
        $y = 792;
        foreach ($lines as [$font, $size, $text]) {
            $lh = $size * 1.45;
            if ($y - $lh < 60) {
                $pages[] = $current;
                $current = [];
                $y = 792;
            }
            $y -= $lh;
            $current[] = [$font, $size, $text, $y];
        }
        $pages[] = $current;

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 6;
        $total = count($pages);
        foreach ($pages as $idx => $pageLines) {
            $stream = '';
            foreach ($pageLines as [$font, $size, $text, $yy]) {
                if ($text === '') {
                    continue;
                }
                $stream .= sprintf("BT /%s %.1F Tf 50 %.2F Td (%s) Tj ET\n", $font, $size, $yy, self::esc($text));
            }
            $foot = self::enc(($footer !== '' ? $footer . '   ·   ' : '') . 'Seite ' . ($idx + 1) . ' von ' . $total);
            $stream .= sprintf("BT /F3 8 Tf 50 30 Td (%s) Tj ET\n", self::esc($foot));
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $obj) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
