<?php
/**
 * E-Mail-Versand ohne externe Bibliotheken.
 * Modi (Einstellung mail_mode):
 *   mail – PHP mail() (Standard bei fast jedem Webhosting)
 *   smtp – eigener SMTP-Server (z. B. Uni- oder Hoster-Postfach)
 *   log  – nichts versenden, nur im Adminbereich unter „E-Mails“ anzeigen (Testbetrieb)
 * Jede E-Mail wird zusätzlich im Mail-Log gespeichert.
 */
class Mailer
{
    public static function fromAddress(): string
    {
        $from = trim((string)setting('mail_from', ''));
        if ($from === '') {
            $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
            $host = preg_replace('/^www\./', '', $host);
            $from = 'noreply@' . $host;
        }
        return $from;
    }

    public static function fromName(): string
    {
        $name = trim((string)setting('mail_from_name', ''));
        return $name !== '' ? $name : (string)setting('site_name');
    }

    /**
     * Verschickt eine E-Mail. $html ist optional (wird sonst aus dem Text erzeugt).
     * Gibt true bei Erfolg (oder im Log-Modus) zurück.
     */
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        $mode = (string)setting('mail_mode', 'mail');
        $logId = 0;
        try {
            $logId = DB::insert('mail_log', [
                'recipient' => substr($to, 0, 191),
                'subject' => mb_substr($subject, 0, 250),
                'body_text' => $text,
                'status' => 'queued',
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            app_log('warning', 'Mail-Log fehlgeschlagen: ' . $e->getMessage());
        }

        $status = 'sent';
        $error = null;
        try {
            if ($mode === 'log') {
                $status = 'logged';
            } else {
                $html = $html ?? self::htmlFromText($subject, $text);
                [$headers, $body] = self::buildMessage($to, $subject, $text, $html);
                if ($mode === 'smtp') {
                    self::sendSmtp($to, $subject, $headers, $body);
                } else {
                    self::sendPhpMail($to, $subject, $headers, $body);
                }
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            app_log('error', 'E-Mail an ' . $to . ' fehlgeschlagen: ' . $error);
        }

        if ($logId) {
            DB::update('mail_log', ['status' => $status, 'error' => $error], 'id = ?', [$logId]);
        }
        return $status !== 'failed';
    }

    public static function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        return $value;
    }

    /** Erzeugt Header (ohne To/Subject) und MIME-Body. */
    private static function buildMessage(string $to, string $subject, string $text, string $html): array
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $from = self::fromAddress();
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'From: ' . self::encodeHeader(self::fromName()) . ' <' . $from . '>',
            'Reply-To: ' . (setting('contact_email') ?: $from),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'X-Mailer: Protokolldatenbank',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
        return [$headers, $body];
    }

    private static function sendPhpMail(string $to, string $subject, array $headers, string $body): void
    {
        $encodedSubject = self::encodeHeader($subject);
        $headerString = implode("\r\n", $headers);
        $from = self::fromAddress();
        $ok = false;
        if (preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from)) {
            $ok = @mail($to, $encodedSubject, $body, $headerString, '-f' . $from);
        }
        if (!$ok) {
            $ok = @mail($to, $encodedSubject, $body, $headerString);
        }
        if (!$ok) {
            $err = error_get_last();
            throw new RuntimeException('mail() ist fehlgeschlagen' . ($err ? ': ' . $err['message'] : '') . '. Tipp: SMTP im Adminbereich einrichten.');
        }
    }

    private static function sendSmtp(string $to, string $subject, array $headers, string $body): void
    {
        $host = trim((string)setting('smtp_host'));
        $port = (int)setting('smtp_port', 587);
        $secure = (string)setting('smtp_secure', 'tls');
        $user = (string)setting('smtp_user');
        $pass = Settings::secret('smtp_pass');
        if ($host === '') {
            throw new RuntimeException('Kein SMTP-Server eingetragen.');
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new RuntimeException('Keine Verbindung zum SMTP-Server ' . $host . ':' . $port . ' (' . $errstr . ')');
        }
        stream_set_timeout($fp, 20);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $command, array $expect, bool $secret = false) use ($fp, $read): string {
            if ($command !== '') {
                fwrite($fp, $command . "\r\n");
            }
            $resp = $read();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $expect, true)) {
                $shown = $secret ? '[Anmeldedaten]' : ($command === '' ? 'Antwort' : $command);
                throw new RuntimeException('SMTP-Fehler bei "' . $shown . '": ' . trim($resp));
            }
            return $resp;
        };

        try {
            $cmd('', [220]);
            $ehloHost = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
            $cmd('EHLO ' . $ehloHost, [250]);
            if ($secure === 'tls') {
                $cmd('STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!stream_socket_enable_crypto($fp, true, $method)) {
                    throw new RuntimeException('STARTTLS fehlgeschlagen.');
                }
                $cmd('EHLO ' . $ehloHost, [250]);
            }
            if ($user !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334], true);
                $cmd(base64_encode($pass), [235], true);
            }
            $from = self::fromAddress();
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $message = implode("\r\n", array_merge(['To: <' . $to . '>', 'Subject: ' . self::encodeHeader($subject)], $headers))
                . "\r\n\r\n" . $body;
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($fp, $message . "\r\n.\r\n");
            $cmd('', [250]);
            $cmd('QUIT', [221, 250]);
        } finally {
            fclose($fp);
        }
    }

    /** Einfache, gut lesbare HTML-Version einer Text-Mail. */
    public static function htmlFromText(string $subject, string $text): string
    {
        $html = e($text);
        $html = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#0d6e78;word-break:break-all">$1</a>', $html);
        $html = nl2br($html);
        return self::wrapHtml($subject, $html);
    }

    public static function button(string $url, string $text): string
    {
        return '<p style="margin:28px 0"><a href="' . e($url) . '" style="background:#0d6e78;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block">' . e($text) . '</a></p>';
    }

    /** HTML-Rahmen für alle E-Mails. */
    public static function wrapHtml(string $heading, string $innerHtml): string
    {
        $site = e((string)setting('site_name'));
        $org = e((string)setting('org_name'));
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1e293b">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden" cellspacing="0" cellpadding="0">'
            . '<tr><td style="background:#0d6e78;color:#ffffff;padding:18px 28px;font-size:18px;font-weight:700">' . $site . '</td></tr>'
            . '<tr><td style="padding:28px;font-size:15px;line-height:1.6">'
            . '<h1 style="font-size:20px;margin:0 0 16px">' . e($heading) . '</h1>' . $innerHtml
            . '</td></tr><tr><td style="padding:16px 28px;background:#f8fafc;color:#64748b;font-size:12px">' . $org . ' · ' . e(base_url()) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
