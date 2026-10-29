<?php
/**
 * Zahlungen, Freischaltungen und automatische Rückerstattungen.
 *
 * Ablauf:
 *  1. Studierende wählen eine Prüfung und bis zu N Prüfer → Kauf (status=pending)
 *  2. Zahlung über Stripe Checkout bzw. im Simulationsmodus über eine Testseite
 *  3. Bestätigung (Webhook oder Rückkehr von Stripe) → status=paid, Zugang aktiv
 *  4. Eigenes Protokoll wird veröffentlicht → automatische Teil-Erstattung
 *
 * Status eines Kaufs:        pending | paid | cancelled | failed | revoked
 * Status der Rückerstattung: none | processing | pending | succeeded | failed | manual
 * Anbieter (provider):       stripe | simulation | manual
 */
class Payments
{
    private static array $accessCache = [];

    /* ---------- Preise ---------- */

    public static function priceFor(array $exam): int
    {
        return isset($exam['price_cents']) && $exam['price_cents'] !== null && $exam['price_cents'] !== ''
            ? (int)$exam['price_cents']
            : Settings::int('price_cents');
    }

    public static function refundFor(array $exam): int
    {
        return isset($exam['refund_cents']) && $exam['refund_cents'] !== null && $exam['refund_cents'] !== ''
            ? (int)$exam['refund_cents']
            : Settings::int('refund_cents');
    }

    public static function maxExaminers(array $exam): int
    {
        return max(1, (int)($exam['max_examiners'] ?? Settings::int('default_max_examiners')));
    }

    /* ---------- Modus ---------- */

    public static function mode(): string
    {
        return setting('payment_mode') === 'stripe' ? 'stripe' : 'simulation';
    }

    public static function isSimulation(): bool
    {
        return self::mode() === 'simulation';
    }

    /** Können gerade Zahlungen angenommen werden? */
    public static function available(): bool
    {
        return self::isSimulation() || Stripe::isConfigured();
    }

    /* ---------- Zugriffsrechte ---------- */

    public static function accessibleExaminerIds(int $userId): array
    {
        if (!isset(self::$accessCache[$userId])) {
            self::$accessCache[$userId] = array_map('intval', DB::col(
                "SELECT DISTINCT pe.examiner_id FROM purchase_examiners pe
                 JOIN purchases p ON p.id = pe.purchase_id
                 WHERE p.user_id = ? AND p.status = 'paid' AND (p.expires_at IS NULL OR p.expires_at > ?)",
                [$userId, now()]
            ));
        }
        return self::$accessCache[$userId];
    }

    public static function clearCache(): void
    {
        self::$accessCache = [];
    }

    public static function hasExaminerAccess(?array $user, int $examinerId): bool
    {
        if (!$user) {
            return false;
        }
        if (in_array($user['role'], ['admin', 'moderator'], true)) {
            return true;
        }
        return in_array($examinerId, self::accessibleExaminerIds((int)$user['id']), true);
    }

    public static function canViewProtocol(?array $user, array $protocol): bool
    {
        if (!$user) {
            return false;
        }
        if (in_array($user['role'], ['admin', 'moderator'], true)) {
            return true;
        }
        if ((int)$protocol['user_id'] === (int)$user['id']) {
            return true;
        }
        return $protocol['status'] === 'published' && self::hasExaminerAccess($user, (int)$protocol['examiner_id']);
    }

    /* ---------- Käufe ---------- */

    public static function purchaseWithDetails(int $purchaseId): ?array
    {
        $p = DB::one('SELECT * FROM purchases WHERE id = ?', [$purchaseId]);
        if (!$p) {
            return null;
        }
        $p['user'] = DB::one('SELECT * FROM users WHERE id = ?', [$p['user_id']]) ?? ['id' => 0, 'email' => '', 'name' => ''];
        $p['exam'] = DB::one('SELECT * FROM exams WHERE id = ?', [$p['exam_id']]) ?? ['id' => 0, 'name' => '?'];
        $p['examiners'] = DB::all(
            'SELECT e.* FROM purchase_examiners pe JOIN examiners e ON e.id = pe.examiner_id WHERE pe.purchase_id = ? ORDER BY e.subject, e.name',
            [$purchaseId]
        );
        return $p;
    }

    public static function createPurchase(array $user, array $exam, array $examinerIds, ?string $provider = null, ?int $amount = null): int
    {
        $provider = $provider ?? self::mode();
        $amount = $amount ?? self::priceFor($exam);
        $id = DB::tx(function () use ($user, $exam, $examinerIds, $provider, $amount) {
            $pid = DB::insert('purchases', [
                'user_id' => (int)$user['id'],
                'exam_id' => (int)$exam['id'],
                'status' => 'pending',
                'provider' => $provider,
                'amount_cents' => $amount,
                'currency' => (string)setting('currency', 'eur'),
                'created_at' => now(),
            ]);
            foreach (array_unique(array_map('intval', $examinerIds)) as $eid) {
                DB::insert('purchase_examiners', ['purchase_id' => $pid, 'examiner_id' => $eid]);
            }
            return $pid;
        });
        log_activity('purchase_created', 'Kauf #' . $id . ' (' . $exam['name'] . ', ' . count($examinerIds) . ' Prüfer, ' . $provider . ')', (int)$user['id']);
        return $id;
    }

    /**
     * Startet die Zahlung und gibt die URL zurück, zu der weitergeleitet wird.
     * @throws StripeException
     */
    public static function checkoutUrl(int $purchaseId): string
    {
        $p = self::purchaseWithDetails($purchaseId);
        if (!$p) {
            throw new RuntimeException('Kauf nicht gefunden.');
        }
        if ((int)$p['amount_cents'] <= 0) {
            self::markPaid($purchaseId);
            return url('checkout-success', ['purchase' => $purchaseId]);
        }
        if ($p['provider'] === 'simulation') {
            return url('checkout-sim', ['id' => $purchaseId]);
        }

        $names = implode(', ', array_map(fn($e) => $e['name'], $p['examiners']));
        $session = Stripe::request('POST', 'checkout/sessions', [
            'mode' => 'payment',
            'success_url' => url('checkout-success', ['purchase' => $purchaseId]) . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => url('checkout-cancel', ['purchase' => $purchaseId]),
            'customer_email' => $p['user']['email'],
            'client_reference_id' => (string)$purchaseId,
            'locale' => 'de',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $p['currency'],
                    'unit_amount' => (int)$p['amount_cents'],
                    'product_data' => [
                        'name' => 'Protokollzugang ' . $p['exam']['name'],
                        'description' => mb_substr('Prüfer: ' . $names, 0, 480),
                    ],
                ],
            ]],
            'metadata' => [
                'purchase_id' => (string)$purchaseId,
                'user_id' => (string)$p['user_id'],
            ],
            'payment_intent_data' => [
                'description' => mb_substr(setting('site_name') . ' – ' . $p['exam']['name'] . ': ' . $names, 0, 340),
                'metadata' => ['purchase_id' => (string)$purchaseId],
            ],
        ], 'checkout_' . $purchaseId . '_' . substr(hash('sha256', APP_KEY . $purchaseId), 0, 12));

        DB::update('purchases', ['stripe_session_id' => $session['id']], 'id = ?', [$purchaseId]);
        if (empty($session['url'])) {
            throw new StripeException('Stripe hat keine Zahlungsseite zurückgegeben.');
        }
        return $session['url'];
    }

    /** Markiert einen Kauf als bezahlt (idempotent). */
    public static function markPaid(int $purchaseId, array $data = []): bool
    {
        $changed = DB::tx(function () use ($purchaseId, $data) {
            $p = DB::one('SELECT * FROM purchases WHERE id = ? FOR UPDATE', [$purchaseId]);
            if (!$p || !in_array($p['status'], ['pending', 'cancelled', 'failed'], true)) {
                return false;
            }
            $days = Settings::int('access_days');
            $update = [
                'status' => 'paid',
                'paid_at' => now(),
                'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
            ];
            if (!empty($data['payment_intent'])) {
                $update['stripe_payment_intent'] = $data['payment_intent'];
            }
            if (!empty($data['session_id'])) {
                $update['stripe_session_id'] = $data['session_id'];
            }
            if (isset($data['amount']) && $data['amount'] !== null) {
                $update['amount_cents'] = (int)$data['amount'];
            }
            DB::update('purchases', $update, 'id = ?', [$purchaseId]);
            return (int)$p['user_id'];
        });
        if ($changed) {
            self::clearCache();
            log_activity('purchase_paid', 'Kauf #' . $purchaseId . ' bezahlt', (int)$changed);
            Mails::purchaseConfirmed($purchaseId);
            return true;
        }
        return false;
    }

    public static function markCancelled(int $purchaseId): void
    {
        DB::q("UPDATE purchases SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$purchaseId]);
    }

    public static function markFailed(int $purchaseId): void
    {
        DB::q("UPDATE purchases SET status = 'failed' WHERE id = ? AND status = 'pending'", [$purchaseId]);
    }

    /** Verarbeitet eine Stripe-Checkout-Session (aus Webhook oder Rückleitung). */
    public static function handleCheckoutSession(array $session): ?int
    {
        $pid = (int)($session['metadata']['purchase_id'] ?? ($session['client_reference_id'] ?? 0));
        if ($pid <= 0) {
            return null;
        }
        $p = DB::one('SELECT * FROM purchases WHERE id = ?', [$pid]);
        if (!$p || $p['provider'] !== 'stripe') {
            return null;
        }
        if ($p['stripe_session_id'] && $p['stripe_session_id'] !== ($session['id'] ?? '')) {
            app_log('warning', 'Stripe-Session ' . ($session['id'] ?? '?') . ' passt nicht zu Kauf #' . $pid);
            return null;
        }
        $paymentStatus = (string)($session['payment_status'] ?? '');
        if ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required') {
            $intent = $session['payment_intent'] ?? null;
            if (is_array($intent)) {
                $intent = $intent['id'] ?? null;
            }
            if (isset($session['amount_total']) && (int)$session['amount_total'] !== (int)$p['amount_cents']) {
                app_log('warning', 'Kauf #' . $pid . ': Stripe-Betrag ' . $session['amount_total'] . ' weicht vom erwarteten Betrag ' . $p['amount_cents'] . ' ab.');
            }
            self::markPaid($pid, [
                'payment_intent' => $intent,
                'session_id' => $session['id'] ?? null,
                'amount' => $session['amount_total'] ?? null,
            ]);
        }
        return $pid;
    }

    /** Fügt einem bezahlten Kauf weitere Prüfer hinzu (bis zum Maximum). */
    public static function addExaminers(int $purchaseId, array $examinerIds): int
    {
        $p = DB::one('SELECT * FROM purchases WHERE id = ?', [$purchaseId]);
        $exam = $p ? DB::one('SELECT * FROM exams WHERE id = ?', [$p['exam_id']]) : null;
        if (!$p || !$exam) {
            return 0;
        }
        $current = array_map('intval', DB::col('SELECT examiner_id FROM purchase_examiners WHERE purchase_id = ?', [$purchaseId]));
        $free = self::maxExaminers($exam) - count($current);
        $added = 0;
        foreach (array_unique(array_map('intval', $examinerIds)) as $eid) {
            if ($free <= 0) {
                break;
            }
            if (in_array($eid, $current, true)) {
                continue;
            }
            $valid = DB::val('SELECT COUNT(*) FROM examiners WHERE id = ? AND exam_id = ?', [$eid, $p['exam_id']]);
            if (!$valid) {
                continue;
            }
            DB::insert('purchase_examiners', ['purchase_id' => $purchaseId, 'examiner_id' => $eid]);
            $free--;
            $added++;
        }
        if ($added) {
            self::clearCache();
            log_activity('purchase_examiners_added', 'Kauf #' . $purchaseId . ': ' . $added . ' Prüfer nachgetragen');
        }
        return $added;
    }

    /** Admin: Zugang manuell gewähren (z. B. Barzahlung oder Freizugang). */
    public static function grantManual(int $userId, int $examId, array $examinerIds, int $amountCents, string $note = ''): int
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $exam = DB::one('SELECT * FROM exams WHERE id = ?', [$examId]);
        if (!$user || !$exam) {
            throw new RuntimeException('Nutzer oder Prüfung nicht gefunden.');
        }
        $pid = self::createPurchase($user, $exam, $examinerIds, 'manual', max(0, $amountCents));
        if ($note !== '') {
            DB::update('purchases', ['admin_note' => $note], 'id = ?', [$pid]);
        }
        self::markPaid($pid);
        return $pid;
    }

    /** Offene Käufe älter als 24 Stunden als abgebrochen markieren. */
    public static function cleanupPending(): int
    {
        return DB::q(
            "UPDATE purchases SET status = 'cancelled' WHERE status = 'pending' AND created_at < ?",
            [date('Y-m-d H:i:s', time() - 86400)]
        )->rowCount();
    }

    /* ---------- Rückerstattungen ---------- */

    /** Kauf, für den beim Upload eines Protokolls erstattet werden kann. */
    public static function eligiblePurchaseForRefund(int $userId, int $examId): ?array
    {
        if (!Settings::bool('refund_enabled')) {
            return null;
        }
        $sql = "SELECT * FROM purchases WHERE user_id = ? AND status = 'paid' AND refund_status = 'none' AND amount_cents > 0";
        $params = [$userId];
        if (Settings::bool('refund_same_exam')) {
            $sql .= ' AND exam_id = ?';
            $params[] = $examId;
        }
        $days = Settings::int('refund_days');
        if ($days > 0) {
            $sql .= ' AND paid_at >= ?';
            $params[] = date('Y-m-d H:i:s', time() - $days * 86400);
        }
        $sql .= ' ORDER BY (exam_id = ?) DESC, paid_at DESC LIMIT 1';
        $params[] = $examId;
        $p = DB::one($sql, $params);
        if (!$p) {
            return null;
        }
        $exam = DB::one('SELECT * FROM exams WHERE id = ?', [$p['exam_id']]);
        $p['refund_amount'] = min(self::refundFor($exam ?? []), (int)$p['amount_cents']);
        return $p['refund_amount'] > 0 ? $p : null;
    }

    /**
     * Löst die Rückerstattung für ein hochgeladenes Protokoll aus.
     * $manual = true: von Admin ausgelöst (ignoriert Freigabe-/Moderationsregeln).
     * Rückgabe: ['status' => ..., 'amount' => cents, 'purchase_id' => id, 'error' => ...]
     * Status: succeeded | pending | failed | manual | waiting | manual_review | no_purchase | already | disabled | not_applicable
     */
    public static function refundForProtocol(int $protocolId, bool $manual = false): array
    {
        $pr = DB::one('SELECT p.*, e.exam_id FROM protocols p JOIN examiners e ON e.id = p.examiner_id WHERE p.id = ?', [$protocolId]);
        if (!$pr || !$pr['user_id'] || $pr['source'] !== 'upload') {
            return ['status' => 'not_applicable'];
        }
        if (!Settings::bool('refund_enabled')) {
            return ['status' => 'disabled'];
        }
        if ($pr['refund_purchase_id']) {
            return ['status' => 'already'];
        }
        if ($pr['submission_id'] && (int)DB::val('SELECT COUNT(*) FROM protocols WHERE submission_id = ? AND refund_purchase_id IS NOT NULL', [$pr['submission_id']]) > 0) {
            return ['status' => 'already'];
        }
        $purchase = self::eligiblePurchaseForRefund((int)$pr['user_id'], (int)$pr['exam_id']);
        if (!$purchase) {
            return ['status' => 'no_purchase'];
        }
        if (!$manual) {
            if ($pr['status'] !== 'published') {
                return ['status' => 'waiting', 'amount' => $purchase['refund_amount']];
            }
            if (setting('refund_mode') === 'manual') {
                return ['status' => 'manual_review', 'amount' => $purchase['refund_amount']];
            }
        }
        $amount = (int)$purchase['refund_amount'];
        $claimed = DB::tx(function () use ($purchase, $protocolId, $amount) {
            $row = DB::one('SELECT refund_status FROM purchases WHERE id = ? FOR UPDATE', [$purchase['id']]);
            if (!$row || $row['refund_status'] !== 'none') {
                return false;
            }
            DB::update('purchases', [
                'refund_status' => 'processing',
                'refund_cents' => $amount,
                'refund_protocol_id' => $protocolId,
                'refund_error' => null,
            ], 'id = ?', [$purchase['id']]);
            DB::update('protocols', ['refund_purchase_id' => $purchase['id']], 'id = ?', [$protocolId]);
            return true;
        });
        if (!$claimed) {
            return ['status' => 'already'];
        }
        return self::executeRefund((int)$purchase['id']);
    }

    /** Admin: Erstattung für einen Kauf direkt auslösen (beliebiger Betrag). */
    public static function adminRefund(int $purchaseId, int $amount, ?int $protocolId = null): array
    {
        $claimed = DB::tx(function () use ($purchaseId, $amount, $protocolId) {
            $p = DB::one('SELECT * FROM purchases WHERE id = ? FOR UPDATE', [$purchaseId]);
            if (!$p || $p['status'] !== 'paid' || !in_array($p['refund_status'], ['none', 'failed'], true)) {
                return false;
            }
            $amount = min($amount, (int)$p['amount_cents']);
            if ($amount <= 0) {
                return false;
            }
            DB::update('purchases', [
                'refund_status' => 'processing',
                'refund_cents' => $amount,
                'refund_protocol_id' => $protocolId ?? $p['refund_protocol_id'],
                'refund_error' => null,
            ], 'id = ?', [$purchaseId]);
            if ($protocolId) {
                DB::update('protocols', ['refund_purchase_id' => $purchaseId], 'id = ?', [$protocolId]);
            }
            return true;
        });
        if (!$claimed) {
            return ['status' => 'not_applicable', 'error' => 'Für diesen Kauf ist keine Erstattung möglich.'];
        }
        log_activity('refund_admin', 'Kauf #' . $purchaseId . ': ' . money($amount));
        return self::executeRefund($purchaseId);
    }

    /** Erneuter Versuch nach fehlgeschlagener Erstattung. */
    public static function retryRefund(int $purchaseId): array
    {
        $ok = DB::q("UPDATE purchases SET refund_status = 'processing', refund_error = NULL WHERE id = ? AND refund_status = 'failed'", [$purchaseId])->rowCount();
        if (!$ok) {
            return ['status' => 'not_applicable', 'error' => 'Keine fehlgeschlagene Erstattung vorhanden.'];
        }
        return self::executeRefund($purchaseId);
    }

    /** Manuelle Erstattung (z. B. Barauszahlung) als erledigt markieren. */
    public static function markRefundDone(int $purchaseId): void
    {
        DB::q("UPDATE purchases SET refund_status = 'succeeded', refunded_at = ? WHERE id = ? AND refund_status IN ('manual','failed','pending','processing')", [now(), $purchaseId]);
        log_activity('refund_marked_done', 'Kauf #' . $purchaseId);
    }

    /** Führt eine bereits reservierte Erstattung (status=processing) beim Anbieter aus. */
    public static function executeRefund(int $purchaseId): array
    {
        $p = DB::one('SELECT * FROM purchases WHERE id = ?', [$purchaseId]);
        if (!$p) {
            return ['status' => 'not_applicable'];
        }
        $amount = (int)$p['refund_cents'];
        $attempt = (int)$p['refund_attempts'] + 1;
        DB::update('purchases', ['refund_attempts' => $attempt], 'id = ?', [$purchaseId]);
        $result = ['amount' => $amount, 'purchase_id' => $purchaseId];

        if ($p['provider'] === 'simulation') {
            DB::update('purchases', [
                'refund_status' => 'succeeded',
                'refund_id' => 'sim_re_' . random_token(8),
                'refunded_at' => now(),
            ], 'id = ?', [$purchaseId]);
            log_activity('refund_succeeded', 'Kauf #' . $purchaseId . ': ' . money($amount) . ' (simuliert)', (int)$p['user_id']);
            Mails::refundIssued($purchaseId);
            return $result + ['status' => 'succeeded'];
        }

        if ($p['provider'] === 'stripe') {
            if (!$p['stripe_payment_intent']) {
                return self::failRefund($purchaseId, 'Zu diesem Kauf ist keine Stripe-Zahlungs-ID gespeichert.') + $result;
            }
            try {
                $refund = Stripe::request('POST', 'refunds', [
                    'payment_intent' => $p['stripe_payment_intent'],
                    'amount' => $amount,
                    'reason' => 'requested_by_customer',
                    'metadata' => [
                        'purchase_id' => (string)$purchaseId,
                        'protocol_id' => (string)($p['refund_protocol_id'] ?? ''),
                    ],
                ], 'refund_' . $purchaseId . '_' . $attempt . '_' . substr(hash('sha256', APP_KEY . $purchaseId), 0, 8));
            } catch (Throwable $e) {
                return self::failRefund($purchaseId, $e->getMessage()) + $result;
            }
            $st = (string)($refund['status'] ?? 'pending');
            $mapped = $st === 'succeeded' ? 'succeeded' : (in_array($st, ['failed', 'canceled'], true) ? 'failed' : 'pending');
            DB::update('purchases', [
                'refund_status' => $mapped,
                'refund_id' => $refund['id'] ?? null,
                'refunded_at' => $mapped === 'succeeded' ? now() : null,
                'refund_error' => $mapped === 'failed' ? 'Stripe-Status: ' . $st : null,
            ], 'id = ?', [$purchaseId]);
            if ($mapped === 'failed') {
                return self::failRefund($purchaseId, 'Stripe hat die Erstattung abgelehnt (Status: ' . $st . ').') + $result;
            }
            log_activity('refund_' . $mapped, 'Kauf #' . $purchaseId . ': ' . money($amount) . ' (Stripe ' . ($refund['id'] ?? '') . ')', (int)$p['user_id']);
            Mails::refundIssued($purchaseId);
            return $result + ['status' => $mapped];
        }

        // Manuell bezahlte Käufe (z. B. bar): Fachschaft zahlt selbst aus.
        DB::update('purchases', ['refund_status' => 'manual'], 'id = ?', [$purchaseId]);
        log_activity('refund_manual', 'Kauf #' . $purchaseId . ': ' . money($amount) . ' manuell auszuzahlen', (int)$p['user_id']);
        Mails::admin('Manuelle Rückerstattung nötig', [
            'Für Kauf #' . $purchaseId . ' (manuell bezahlt) wurde ein Protokoll hochgeladen.',
            'Bitte ' . money($amount) . ' persönlich auszahlen und die Erstattung anschließend im Adminbereich als erledigt markieren.',
        ], url('admin/purchase', ['id' => $purchaseId]));
        return $result + ['status' => 'manual'];
    }

    private static function failRefund(int $purchaseId, string $error): array
    {
        DB::update('purchases', ['refund_status' => 'failed', 'refund_error' => $error], 'id = ?', [$purchaseId]);
        app_log('error', 'Rückerstattung für Kauf #' . $purchaseId . ' fehlgeschlagen: ' . $error);
        log_activity('refund_failed', 'Kauf #' . $purchaseId . ': ' . $error);
        if (Settings::bool('notify_refund_errors')) {
            Mails::admin('Rückerstattung fehlgeschlagen', [
                'Die automatische Rückerstattung für Kauf #' . $purchaseId . ' ist fehlgeschlagen.',
                'Fehler: ' . $error,
                'Du kannst sie im Adminbereich erneut auslösen oder als manuell erledigt markieren.',
            ], url('admin/purchase', ['id' => $purchaseId]));
        }
        return ['status' => 'failed', 'error' => $error];
    }

    /** Webhook: Status einer Stripe-Erstattung aktualisieren. */
    public static function syncStripeRefund(array $refund): void
    {
        $p = null;
        if (!empty($refund['id'])) {
            $p = DB::one('SELECT * FROM purchases WHERE refund_id = ?', [$refund['id']]);
        }
        if (!$p && !empty($refund['metadata']['purchase_id'])) {
            $p = DB::one('SELECT * FROM purchases WHERE id = ?', [(int)$refund['metadata']['purchase_id']]);
        }
        if (!$p) {
            return;
        }
        $st = (string)($refund['status'] ?? '');
        if ($st === 'succeeded' && $p['refund_status'] !== 'succeeded') {
            DB::update('purchases', ['refund_status' => 'succeeded', 'refunded_at' => now(), 'refund_id' => $refund['id'], 'refund_error' => null], 'id = ?', [$p['id']]);
        } elseif (in_array($st, ['failed', 'canceled'], true)) {
            self::failRefund((int)$p['id'], 'Stripe meldet Status „' . $st . '“' . (!empty($refund['failure_reason']) ? ' (' . $refund['failure_reason'] . ')' : '') . '.');
        }
    }

    /** Webhook: Zahlung wurde (teilweise) erstattet – auch bei Erstattungen direkt im Stripe-Dashboard. */
    public static function syncChargeRefunded(array $charge): void
    {
        $intent = $charge['payment_intent'] ?? null;
        if (is_array($intent)) {
            $intent = $intent['id'] ?? null;
        }
        if (!$intent) {
            return;
        }
        $p = DB::one('SELECT * FROM purchases WHERE stripe_payment_intent = ?', [$intent]);
        if (!$p) {
            return;
        }
        $refunded = (int)($charge['amount_refunded'] ?? 0);
        if ($refunded <= 0) {
            return;
        }
        if (in_array($p['refund_status'], ['processing', 'pending', 'failed'], true) && $refunded >= (int)$p['refund_cents']) {
            DB::update('purchases', ['refund_status' => 'succeeded', 'refunded_at' => now(), 'refund_error' => null], 'id = ?', [$p['id']]);
        } elseif ($p['refund_status'] === 'none') {
            DB::update('purchases', [
                'refund_status' => 'succeeded',
                'refund_cents' => $refunded,
                'refunded_at' => now(),
                'admin_note' => trim(($p['admin_note'] ?? '') . "\nErstattung über das Stripe-Dashboard: " . money($refunded)),
            ], 'id = ?', [$p['id']]);
            log_activity('refund_external', 'Kauf #' . $p['id'] . ': ' . money($refunded) . ' im Stripe-Dashboard erstattet', (int)$p['user_id']);
        }
    }

    /** Text für Studierende nach dem Upload, passend zum Ergebnis der Erstattung. */
    public static function refundMessage(array $result): string
    {
        $amount = isset($result['amount']) ? money($result['amount']) : '';
        switch ($result['status'] ?? '') {
            case 'succeeded':
                return 'Wir haben dir ' . $amount . ' erstattet' . (self::isSimulation() ? ' (simuliert – Testmodus)' : '') . '.';
            case 'pending':
                return 'Die Erstattung über ' . $amount . ' wurde veranlasst und ist in wenigen Tagen auf deinem Konto.';
            case 'waiting':
                return 'Sobald die Fachschaft dein Protokoll freigegeben hat, erstatten wir dir automatisch ' . $amount . '.';
            case 'manual_review':
                return 'Nach einer kurzen Prüfung durch die Fachschaft erstatten wir dir ' . $amount . '.';
            case 'manual':
                return 'Die Fachschaft zahlt dir ' . $amount . ' persönlich zurück und meldet sich bei dir.';
            case 'failed':
                return 'Die automatische Erstattung hat leider nicht geklappt – die Fachschaft wurde informiert und kümmert sich darum.';
            default:
                return '';
        }
    }

    /* ---------- Beschriftungen ---------- */

    public static function statusBadge(string $status): string
    {
        $map = [
            'pending' => ['Offen', 'warning'],
            'paid' => ['Bezahlt', 'success'],
            'cancelled' => ['Abgebrochen', 'neutral'],
            'failed' => ['Fehlgeschlagen', 'danger'],
            'revoked' => ['Zugang entzogen', 'danger'],
        ];
        [$t, $v] = $map[$status] ?? [$status, 'neutral'];
        return badge($t, $v);
    }

    public static function refundBadge(string $status): string
    {
        $map = [
            'none' => ['–', 'neutral'],
            'processing' => ['In Bearbeitung', 'info'],
            'pending' => ['Wird ausgezahlt', 'info'],
            'succeeded' => ['Erstattet', 'success'],
            'failed' => ['Fehlgeschlagen', 'danger'],
            'manual' => ['Manuell auszuzahlen', 'warning'],
        ];
        [$t, $v] = $map[$status] ?? [$status, 'neutral'];
        return $status === 'none' ? '<span class="muted">–</span>' : badge($t, $v);
    }

    public static function providerLabel(string $provider): string
    {
        return ['stripe' => 'Stripe', 'simulation' => 'Simulation', 'manual' => 'Manuell'][$provider] ?? $provider;
    }
}
