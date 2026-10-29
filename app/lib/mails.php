<?php
/**
 * Alle E-Mail-Vorlagen der Webseite.
 */
class Mails
{
    /**
     * Baut Text- und HTML-Version und verschickt die Mail.
     * $blocks: Liste von Absätzen (string) oder ['button' => url, 'text' => beschriftung]
     */
    public static function compose(string $to, string $subject, string $heading, array $blocks): bool
    {
        $textParts = [];
        $htmlParts = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $textParts[] = $block['text'] . ":\n" . $block['button'];
                $htmlParts .= Mailer::button($block['button'], $block['text']);
            } else {
                $textParts[] = $block;
                $htmlParts .= '<p style="margin:0 0 14px">' . nl2br(e($block)) . '</p>';
            }
        }
        $sign = "Viele Grüße\n" . setting('org_name');
        $textParts[] = $sign;
        $htmlParts .= '<p style="margin:24px 0 0">' . nl2br(e($sign)) . '</p>';
        return Mailer::send($to, $subject, implode("\n\n", $textParts), Mailer::wrapHtml($heading, $htmlParts));
    }

    private static function hello(array $user): string
    {
        $first = trim(explode(' ', trim((string)$user['name']))[0] ?? '');
        return 'Hallo' . ($first !== '' ? ' ' . $first : '') . ',';
    }

    public static function verification(array $user, string $token): bool
    {
        $link = url('verify', ['token' => $token]);
        return self::compose($user['email'], 'Bitte bestätige deine E-Mail-Adresse', 'Willkommen bei ' . setting('site_name') . '!', [
            self::hello($user),
            'bitte bestätige deine E-Mail-Adresse, damit wir sicher sein können, dass du an unserer Fakultät studierst. Danach kannst du dich anmelden.',
            ['button' => $link, 'text' => 'E-Mail-Adresse bestätigen'],
            'Der Link ist 48 Stunden gültig. Falls du dich nicht registriert hast, kannst du diese E-Mail ignorieren.',
        ]);
    }

    public static function passwordReset(array $user, string $token): bool
    {
        $link = url('reset', ['token' => $token]);
        return self::compose($user['email'], 'Passwort zurücksetzen', 'Neues Passwort festlegen', [
            self::hello($user),
            'du hast angefordert, dein Passwort zurückzusetzen. Über den folgenden Link kannst du ein neues Passwort festlegen:',
            ['button' => $link, 'text' => 'Neues Passwort festlegen'],
            'Der Link ist 2 Stunden gültig. Falls du das nicht warst, ignoriere diese E-Mail einfach – dein Passwort bleibt unverändert.',
        ]);
    }

    public static function purchaseConfirmed(int $purchaseId): bool
    {
        $p = Payments::purchaseWithDetails($purchaseId);
        if (!$p) {
            return false;
        }
        $names = array_map(fn($x) => '• ' . $x['name'] . ($x['subject'] !== '' ? ' (' . $x['subject'] . ')' : ''), $p['examiners']);
        $blocks = [
            self::hello($p['user']),
            'vielen Dank! Dein Zugang für ' . $p['exam']['name'] . ' ist jetzt freigeschaltet (' . money($p['amount_cents'], $p['currency']) . ').',
            "Freigeschaltete Prüfer:\n" . implode("\n", $names),
            ['button' => url('account'), 'text' => 'Zu meinen Protokollen'],
        ];
        $refund = Payments::refundFor($p['exam']);
        if (Settings::bool('refund_enabled') && $refund > 0 && (int)$p['amount_cents'] > 0) {
            $blocks[] = 'Tipp: Lade nach deiner Prüfung dein eigenes Gedächtnisprotokoll hoch – dann erstatten wir dir ' . money($refund, $p['currency']) . '.';
        }
        if ($p['expires_at']) {
            $blocks[] = 'Dein Zugang ist gültig bis ' . fmt_date($p['expires_at']) . '.';
        }
        if ($p['provider'] === 'simulation') {
            $blocks[] = 'Hinweis: Dies war eine simulierte Zahlung (Testmodus). Es wurde kein Geld abgebucht.';
        }
        return self::compose($p['user']['email'], 'Dein Zugang ist freigeschaltet', 'Zahlung erfolgreich', $blocks);
    }

    public static function refundIssued(int $purchaseId): bool
    {
        $p = Payments::purchaseWithDetails($purchaseId);
        if (!$p) {
            return false;
        }
        $blocks = [
            self::hello($p['user']),
            'danke für dein Gedächtnisprotokoll! Damit hilfst du allen, die nach dir geprüft werden.',
            'Wir haben dir ' . money($p['refund_cents'], $p['currency']) . ' für deinen Kauf vom ' . fmt_date($p['paid_at']) . ' (' . $p['exam']['name'] . ') erstattet.',
        ];
        if ($p['provider'] === 'stripe') {
            $blocks[] = 'Die Gutschrift erfolgt auf das Zahlungsmittel, mit dem du bezahlt hast. Je nach Bank kann es 5–10 Werktage dauern, bis sie sichtbar ist.';
        } elseif ($p['provider'] === 'simulation') {
            $blocks[] = 'Hinweis: Dies war eine simulierte Erstattung (Testmodus).';
        }
        $blocks[] = 'Dein Zugang zu den Protokollen bleibt selbstverständlich bestehen.';
        return self::compose($p['user']['email'], 'Rückerstattung: ' . money($p['refund_cents'], $p['currency']), 'Danke für dein Protokoll!', $blocks);
    }

    public static function protocolPublished(array $protocol): bool
    {
        if (!$protocol['user_id']) {
            return false;
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$protocol['user_id']]);
        if (!$user) {
            return false;
        }
        return self::compose($user['email'], 'Dein Protokoll wurde freigegeben', 'Protokoll freigegeben', [
            self::hello($user),
            'dein Gedächtnisprotokoll „' . $protocol['title'] . '“ wurde geprüft und ist jetzt für andere Studierende sichtbar. Vielen Dank!',
            ['button' => url('account', ['tab' => 'uploads']), 'text' => 'Meine Protokolle ansehen'],
        ]);
    }

    public static function protocolRejected(array $protocol, string $reason): bool
    {
        if (!$protocol['user_id']) {
            return false;
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$protocol['user_id']]);
        if (!$user) {
            return false;
        }
        $blocks = [
            self::hello($user),
            'leider konnten wir dein Protokoll „' . $protocol['title'] . '“ nicht freigeben.',
        ];
        if (trim($reason) !== '') {
            $blocks[] = "Begründung:\n" . $reason;
        }
        $blocks[] = 'Du kannst jederzeit ein überarbeitetes Protokoll hochladen.';
        $blocks[] = ['button' => url('upload'), 'text' => 'Protokoll hochladen'];
        return self::compose($user['email'], 'Dein Protokoll wurde nicht freigegeben', 'Protokoll nicht freigegeben', $blocks);
    }

    /** Benachrichtigung an die Fachschaft (Einstellung notify_email). */
    public static function admin(string $subject, array $lines, ?string $link = null): bool
    {
        $to = trim((string)setting('notify_email', ''));
        if ($to === '') {
            return false;
        }
        $blocks = $lines;
        if ($link) {
            $blocks[] = ['button' => $link, 'text' => 'Im Adminbereich öffnen'];
        }
        return self::compose($to, '[' . setting('site_name') . '] ' . $subject, $subject, $blocks);
    }
}
