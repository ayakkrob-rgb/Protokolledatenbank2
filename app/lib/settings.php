<?php
/**
 * Einstellungen der Webseite (in der Tabelle `settings` gespeichert,
 * im Adminbereich bearbeitbar). Geheime Werte werden verschlüsselt abgelegt.
 */
class Settings
{
    private static array $values = [];

    /** Einstellungen, die verschlüsselt gespeichert werden. */
    public const SECRET_KEYS = ['stripe_secret_key', 'stripe_webhook_secret', 'smtp_pass'];

    public static function defaults(): array
    {
        return [
            // Allgemein
            'site_name' => 'Protokolldatenbank',
            'org_name' => 'Fachschaft Medizin',
            'contact_email' => '',
            'home_title' => 'Gedächtnisprotokolle für deine Prüfung',
            'home_intro' => 'Bereite dich gezielt auf deine Prüferinnen und Prüfer vor: Lies, was in den letzten Prüfungen gefragt wurde, und lade nach deiner Prüfung dein eigenes Protokoll hoch – dann bekommst du fast den gesamten Betrag zurück.',
            'announcement' => '',
            'maintenance_mode' => '0',
            'maintenance_message' => 'Die Webseite wird gerade gewartet. Bitte schau später wieder vorbei.',
            // Registrierung
            'allowed_domains' => '',
            'registration_open' => '1',
            // Preise & Zugang
            'price_cents' => '3000',
            'refund_cents' => '2900',
            'currency' => 'eur',
            'default_max_examiners' => '4',
            'access_days' => '0',
            // Zahlungen
            'payment_mode' => 'simulation',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
            'stripe_webhook_id' => '',
            'stripe_account_name' => '',
            'stripe_last_webhook_at' => '',
            // Rückerstattung
            'refund_enabled' => '1',
            'refund_mode' => 'auto',
            'refund_days' => '0',
            'refund_same_exam' => '1',
            // Uploads & Moderation
            'moderation' => '0',
            'allow_new_examiners' => '1',
            'new_examiner_review' => '1',
            'allowed_extensions' => 'pdf,doc,docx,odt,rtf,txt,jpg,jpeg,png',
            'max_upload_mb' => '20',
            'max_files' => '5',
            'min_content_length' => '150',
            'watermark' => '1',
            // E-Mail
            'mail_mode' => 'mail',
            'mail_from' => '',
            'mail_from_name' => '',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_secure' => 'tls',
            'smtp_user' => '',
            'smtp_pass' => '',
            'notify_email' => '',
            'notify_uploads' => '1',
            'notify_refund_errors' => '1',
            // Rechtstexte & Inhalte
            'page_impressum' => self::defaultImpressum(),
            'page_datenschutz' => self::defaultDatenschutz(),
            'page_nutzungsbedingungen' => self::defaultNutzungsbedingungen(),
            'page_faq' => self::defaultFaq(),
            'db_version' => '1',
        ];
    }

    public static function load(): void
    {
        self::$values = [];
        try {
            foreach (DB::all('SELECT name, value FROM settings') as $row) {
                self::$values[$row['name']] = $row['value'];
            }
        } catch (PDOException $e) {
            // Tabelle existiert (noch) nicht – Standardwerte verwenden.
        }
    }

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$values) && self::$values[$key] !== null) {
            return self::$values[$key];
        }
        $defaults = self::defaults();
        return $defaults[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int)self::get($key, 0);
    }

    public static function bool(string $key): bool
    {
        return (string)self::get($key, '0') === '1';
    }

    public static function set(string $key, $value): void
    {
        $value = $value === null ? null : (string)$value;
        if (in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
            $value = self::encrypt($value);
        }
        DB::q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
        self::$values[$key] = $value;
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    /** Entschlüsselter Wert einer geheimen Einstellung. */
    public static function secret(string $key): string
    {
        return self::decrypt((string)self::get($key, ''));
    }

    /** Für die Anzeige: "sk_live_••••••1234". */
    public static function masked(string $key): string
    {
        $v = self::secret($key);
        if ($v === '') {
            return '';
        }
        $prefix = '';
        if (preg_match('/^([a-z]+_(?:test|live)_|whsec_|[a-z]+_)/', $v, $m)) {
            $prefix = $m[1];
        }
        return $prefix . str_repeat('•', 8) . substr($v, -4);
    }

    public static function encrypt(string $plain): string
    {
        if (APP_KEY === '' || !function_exists('openssl_encrypt')) {
            return $plain;
        }
        $key = hash('sha256', APP_KEY, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return 'enc:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:')) {
            return $value;
        }
        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) < 29 || APP_KEY === '') {
            return '';
        }
        $key = hash('sha256', APP_KEY, true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** Ersetzt Platzhalter wie {preis} in Inhaltsseiten. */
    public static function replacePlaceholders(string $text): string
    {
        $price = self::int('price_cents');
        $refund = self::int('refund_cents');
        return strtr($text, [
            '{seitenname}' => (string)self::get('site_name'),
            '{fachschaft}' => (string)self::get('org_name'),
            '{kontakt}' => (string)(self::get('contact_email') ?: '[E-Mail-Adresse]'),
            '{preis}' => money_short($price),
            '{erstattung}' => money_short($refund),
            '{eigenanteil}' => money_short(max(0, $price - $refund)),
            '{max_pruefer}' => (string)self::int('default_max_examiners'),
        ]);
    }

    private static function defaultImpressum(): string
    {
        return <<<TXT
# Impressum

**Angaben gemäß § 5 DDG**

{fachschaft}
[Straße und Hausnummer]
[PLZ Ort]

**Vertreten durch:** [Name der bzw. des Vorsitzenden]

**Kontakt:** {kontakt}

**Verantwortlich für den Inhalt:** [Name, Anschrift]

> Bitte passe diesen Text im Adminbereich unter „Rechtstexte“ an.
TXT;
    }

    private static function defaultDatenschutz(): string
    {
        return <<<TXT
# Datenschutzerklärung

## 1. Verantwortliche Stelle
{fachschaft}, Kontakt: {kontakt}

## 2. Welche Daten wir verarbeiten
- **Konto:** Name, studentische E-Mail-Adresse, verschlüsseltes Passwort, Zeitpunkt der Registrierung und der letzten Anmeldung.
- **Käufe:** gewählte Prüfung und Prüfer, Betrag, Zahlungsstatus, Rückerstattungen. Zahlungsdaten (z. B. Kartennummer) werden ausschließlich vom Zahlungsdienstleister Stripe verarbeitet, nicht von uns.
- **Protokolle:** von dir hochgeladene Texte und Dateien. Anderen Studierenden wird nie angezeigt, wer ein Protokoll hochgeladen hat.
- **Sicherheit:** IP-Adresse bei Anmeldeversuchen und wichtigen Aktionen, Protokoll der Abrufe und Downloads (Missbrauchsschutz).

## 3. Zweck und Rechtsgrundlage
Die Verarbeitung erfolgt zur Bereitstellung der Protokolldatenbank und zur Abwicklung der Zahlungen (Art. 6 Abs. 1 lit. b DSGVO) sowie zum Schutz vor Missbrauch (Art. 6 Abs. 1 lit. f DSGVO).

## 4. Zahlungsdienstleister Stripe
Zahlungen werden über Stripe Payments Europe Ltd., 1 Grand Canal Street Lower, Dublin 2, Irland, abgewickelt. Dabei gelten die Datenschutzbestimmungen von Stripe: https://stripe.com/de/privacy

## 5. Cookies
Wir verwenden ausschließlich ein technisch notwendiges Sitzungs-Cookie für die Anmeldung. Es werden keine Tracking- oder Analyse-Dienste eingesetzt.

## 6. Speicherdauer
Kontodaten werden gelöscht bzw. anonymisiert, wenn du dein Konto löschst. Buchungsdaten bewahren wir im Rahmen der gesetzlichen Aufbewahrungspflichten auf.

## 7. Deine Rechte
Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht auf Beschwerde bei einer Datenschutz-Aufsichtsbehörde.

> Bitte lasse diesen Text prüfen und passe ihn im Adminbereich unter „Rechtstexte“ an.
TXT;
    }

    private static function defaultNutzungsbedingungen(): string
    {
        return <<<TXT
# Nutzungsbedingungen

## 1. Angebot
{fachschaft} stellt mit {seitenname} eine Sammlung von Gedächtnisprotokollen zur Prüfungsvorbereitung bereit. Die Protokolle wurden von Studierenden verfasst; für Richtigkeit und Vollständigkeit wird keine Gewähr übernommen.

## 2. Zugang und Preis
Der Zugang zu den Protokollen einer Prüfung für bis zu {max_pruefer} Prüferinnen bzw. Prüfer kostet einmalig {preis}. Der Zugang ist persönlich und darf nicht an Dritte weitergegeben werden. Das Weiterverbreiten von Protokollen ist nicht gestattet.

## 3. Rückerstattung bei eigenem Protokoll
Wenn du nach deiner Prüfung ein eigenes, vollständiges Gedächtnisprotokoll hochlädst, erstatten wir dir {erstattung} auf das ursprüngliche Zahlungsmittel. Pro Kauf ist eine Erstattung möglich. Offensichtlich unbrauchbare oder kopierte Protokolle können abgelehnt werden.

## 4. Hochgeladene Protokolle
Mit dem Hochladen bestätigst du, dass du das Protokoll selbst verfasst hast, und räumst {fachschaft} ein einfaches, unbefristetes Nutzungsrecht ein, das Protokoll anderen Studierenden über diese Plattform zugänglich zu machen.

## 5. Widerrufsrecht
Da es sich um digitale Inhalte handelt, die sofort bereitgestellt werden, erlischt das Widerrufsrecht mit Beginn der Bereitstellung, sofern du dem ausdrücklich zugestimmt hast.

## 6. Sperrung
Bei Missbrauch (z. B. Weitergabe von Zugangsdaten oder Protokollen) kann das Konto gesperrt werden.

> Bitte passe diesen Text im Adminbereich unter „Rechtstexte“ an.
TXT;
    }

    private static function defaultFaq(): string
    {
        return <<<TXT
# Häufige Fragen

## Wie funktioniert die Protokolldatenbank?
Du registrierst dich mit deiner studentischen E-Mail-Adresse, wählst deine Prüfung und bis zu {max_pruefer} Prüferinnen bzw. Prüfer aus und schaltest deren Protokolle für {preis} frei. Danach kannst du alle Protokolle dieser Prüfer lesen und herunterladen.

## Wie bekomme ich mein Geld zurück?
Lade nach deiner Prüfung dein eigenes Gedächtnisprotokoll hoch. Dann erstatten wir dir automatisch {erstattung} – dein Eigenanteil beträgt also nur {eigenanteil}. Die Erstattung geht auf das Zahlungsmittel, mit dem du bezahlt hast.

## Mein Prüfer steht nicht in der Liste – was nun?
Dann gibt es zu ihm noch keine Protokolle. Wenn du nach deiner Prüfung ein Protokoll hochlädst, kannst du den Prüfer einfach neu anlegen. Du hilfst damit allen, die nach dir kommen.

## Kann ich später noch Prüfer hinzufügen?
Ja. Wenn du weniger als {max_pruefer} Prüfer ausgewählt hast, kannst du in „Mein Bereich“ weitere Prüfer derselben Prüfung kostenlos nachtragen.

## Warum brauche ich eine Uni-E-Mail-Adresse?
Die Protokolle sind nur für Studierende unserer Fakultät gedacht. Über die Bestätigung deiner Uni-Adresse stellen wir das sicher.

## Sieht jemand, dass ich ein Protokoll hochgeladen habe?
Nein. Protokolle werden anderen Studierenden immer anonym angezeigt.

## Ich habe noch eine Frage.
Schreib uns: {kontakt}
TXT;
    }
}

function setting(string $key, $default = null)
{
    return Settings::get($key, $default);
}
