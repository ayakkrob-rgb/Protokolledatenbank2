<?php
/**
 * Stripe-Webhook: Stripe meldet hier Zahlungen und Erstattungen.
 * Adresse: https://eure-domain.de/stripe-webhook.php
 * (wird im Adminbereich unter „Zahlungen / Stripe“ automatisch eingerichtet)
 */
define('NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['info' => 'Stripe-Webhook-Endpunkt der Protokolldatenbank. Erwartet POST-Anfragen von Stripe.']);
    exit;
}

$payload = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

try {
    $event = Stripe::verifyWebhook($payload, $signature, Settings::secret('stripe_webhook_secret'));
} catch (Throwable $e) {
    app_log('warning', 'Stripe-Webhook abgelehnt: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$eventId = (string)($event['id'] ?? '');
if ($eventId !== '' && (int)DB::val('SELECT COUNT(*) FROM stripe_events WHERE id = ?', [$eventId]) > 0) {
    echo json_encode(['received' => true, 'duplicate' => true]);
    exit;
}
Settings::set('stripe_last_webhook_at', now());

$object = $event['data']['object'] ?? [];
$purchaseId = (int)($object['metadata']['purchase_id'] ?? ($object['client_reference_id'] ?? 0));

try {
    switch ((string)$event['type']) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            Payments::handleCheckoutSession($object);
            break;
        case 'checkout.session.async_payment_failed':
            if ($purchaseId > 0) {
                Payments::markFailed($purchaseId);
            }
            break;
        case 'checkout.session.expired':
            if ($purchaseId > 0) {
                Payments::markCancelled($purchaseId);
            }
            break;
        case 'charge.refunded':
            Payments::syncChargeRefunded($object);
            break;
        case 'charge.refund.updated':
        case 'refund.updated':
        case 'refund.failed':
            Payments::syncStripeRefund($object);
            break;
    }
    if ($eventId !== '') {
        DB::q('INSERT IGNORE INTO stripe_events (id, type, processed_at) VALUES (?, ?, ?)', [$eventId, (string)$event['type'], now()]);
    }
} catch (Throwable $e) {
    app_log('error', 'Stripe-Webhook ' . ($event['type'] ?? '?') . ' fehlgeschlagen: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Verarbeitung fehlgeschlagen']);
    exit;
}

echo json_encode(['received' => true]);
