<?php
/**
 * Minimaler Stripe-Client über cURL (kein Composer nötig).
 * Verwendet Stripe Checkout (gehostete Zahlungsseite) und die Refund-API.
 */
class StripeException extends RuntimeException
{
}

class Stripe
{
    public const API_BASE = 'https://api.stripe.com/v1/';
    public const API_VERSION = '2024-06-20';

    /** Events, die der Webhook verarbeitet. */
    public const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
        'charge.refund.updated',
    ];

    /** API-Adresse; in config.php über 'stripe_api_base' überschreibbar (z. B. für stripe-mock in Tests). */
    public static function apiBase(): string
    {
        $base = (string)($GLOBALS['config']['stripe_api_base'] ?? '');
        return $base !== '' ? rtrim($base, '/') . '/' : self::API_BASE;
    }

    public static function secretKey(): string
    {
        return Settings::secret('stripe_secret_key');
    }

    public static function isConfigured(): bool
    {
        return self::secretKey() !== '';
    }

    public static function isLive(?string $key = null): bool
    {
        $key = $key ?? self::secretKey();
        return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
    }

    public static function webhookUrl(): string
    {
        return base_url() . 'stripe-webhook.php';
    }

    /**
     * Führt eine API-Anfrage aus und gibt die dekodierte Antwort zurück.
     * @throws StripeException
     */
    public static function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null, ?string $key = null): array
    {
        $key = $key ?? self::secretKey();
        if ($key === '') {
            throw new StripeException('Es ist kein Stripe-Geheimschlüssel hinterlegt.');
        }
        if (!function_exists('curl_init')) {
            throw new StripeException('Die PHP-Erweiterung cURL ist nicht verfügbar. Bitte beim Hoster aktivieren.');
        }
        $url = self::apiBase() . ltrim($path, '/');
        $body = http_build_query(self::normalize($params), '', '&');
        $headers = [
            'Authorization: Bearer ' . $key,
            'Stripe-Version: ' . self::API_VERSION,
            'User-Agent: Protokolldatenbank/' . APP_VERSION,
        ];
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($method === 'GET' || $method === 'DELETE') {
            $opts[CURLOPT_URL] = $url . ($body !== '' ? '?' . $body : '');
        } else {
            $opts[CURLOPT_URL] = $url;
            $opts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new StripeException('Verbindung zu Stripe fehlgeschlagen: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new StripeException('Ungültige Antwort von Stripe (HTTP ' . $status . ').');
        }
        if ($status >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $status);
            throw new StripeException('Stripe meldet: ' . $msg, $status);
        }
        return $data;
    }

    /** Wandelt Booleans in "true"/"false" um (Stripe erwartet Strings). */
    private static function normalize(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            } elseif (is_array($v)) {
                $params[$k] = self::normalize($v);
            } elseif ($v === null) {
                unset($params[$k]);
            }
        }
        return $params;
    }

    /**
     * Prüft die Signatur eines Webhook-Aufrufs und gibt das Event zurück.
     * @throws StripeException
     */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): array
    {
        if ($secret === '') {
            throw new StripeException('Kein Webhook-Signaturschlüssel hinterlegt.');
        }
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int)$kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === 0 || !$signatures) {
            throw new StripeException('Signatur-Header fehlt oder ist ungültig.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new StripeException('Signatur stimmt nicht überein.');
        }
        if (abs(time() - $timestamp) > $tolerance) {
            throw new StripeException('Zeitstempel außerhalb der Toleranz.');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) {
            throw new StripeException('Ungültiger Event-Inhalt.');
        }
        return $event;
    }

    /** Legt den Webhook im Stripe-Konto an und gibt [id, secret] zurück. */
    public static function createWebhookEndpoint(string $url): array
    {
        $res = self::request('POST', 'webhook_endpoints', [
            'url' => $url,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'api_version' => self::API_VERSION,
            'description' => 'Protokolldatenbank (' . setting('site_name') . ')',
        ]);
        return [$res['id'], $res['secret'] ?? ''];
    }

    /** Link ins Stripe-Dashboard für eine Zahlung. */
    public static function dashboardPaymentUrl(string $paymentIntent, bool $live): string
    {
        return 'https://dashboard.stripe.com/' . ($live ? '' : 'test/') . 'payments/' . rawurlencode($paymentIntent);
    }
}
