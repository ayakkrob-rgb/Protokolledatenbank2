<?php
/** Admin: Zahlungsmodus (Simulation/Stripe), Stripe-Schlüssel, Webhook. */
View::$title = 'Zahlungen / Stripe';
View::$active = 'payments';
$action = post_str('action');

if (is_post()) {
    switch ($action) {
        case 'mode':
            $mode = post_str('payment_mode') === 'stripe' ? 'stripe' : 'simulation';
            if ($mode === 'stripe' && !Stripe::isConfigured()) {
                flash('error', 'Bitte hinterlege zuerst den Stripe-Geheimschlüssel (Schritt 2).');
                break;
            }
            Settings::set('payment_mode', $mode);
            log_activity('payment_mode', $mode);
            flash('success', $mode === 'stripe'
                ? 'Echte Zahlungen über Stripe sind jetzt aktiv' . (Stripe::isLive() ? ' (Live-Modus).' : ' (Stripe-Testmodus – mit Testkarten bezahlen).')
                : 'Der Simulationsmodus ist aktiv. Es werden keine echten Zahlungen abgewickelt.');
            break;

        case 'key':
            if (post_bool('remove')) {
                Settings::setMany(['stripe_secret_key' => '', 'stripe_account_name' => '']);
                if (Payments::mode() === 'stripe') {
                    Settings::set('payment_mode', 'simulation');
                }
                flash('success', 'Der Stripe-Schlüssel wurde entfernt. Der Simulationsmodus ist aktiv.');
                break;
            }
            $key = trim(post_str('stripe_secret_key'));
            if ($key === '') {
                flash('error', 'Bitte füge den Geheimschlüssel ein.');
                break;
            }
            if (str_starts_with($key, 'pk_')) {
                flash('error', 'Das ist der veröffentlichbare Schlüssel (pk_…). Benötigt wird der Geheimschlüssel, der mit „sk_“ beginnt.');
                break;
            }
            if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $key)) {
                flash('error', 'Der Schlüssel muss mit „sk_live_“ oder „sk_test_“ beginnen.');
                break;
            }
            try {
                $acc = Stripe::request('GET', 'account', [], null, $key);
            } catch (Throwable $e) {
                flash('error', 'Der Schlüssel konnte nicht geprüft werden: ' . $e->getMessage());
                break;
            }
            $name = $acc['settings']['dashboard']['display_name'] ?? ($acc['business_profile']['name'] ?? ($acc['email'] ?? $acc['id'] ?? ''));
            Settings::set('stripe_secret_key', $key);
            Settings::set('stripe_account_name', (string)$name);
            log_activity('stripe_key_saved', Stripe::isLive($key) ? 'live' : 'test');
            flash('success', 'Verbunden mit dem Stripe-Konto „' . $name . '“ (' . (Stripe::isLive($key) ? 'Live' : 'Testmodus') . '). Richte jetzt den Webhook ein.');
            break;

        case 'test':
            try {
                $acc = Stripe::request('GET', 'account');
                $name = $acc['settings']['dashboard']['display_name'] ?? ($acc['business_profile']['name'] ?? ($acc['email'] ?? ''));
                $charges = !empty($acc['charges_enabled']);
                flash($charges ? 'success' : 'warning', 'Verbindung OK: „' . $name . '“' . ($charges ? ' – Zahlungen sind aktiviert.' : ' – Achtung: Zahlungen sind im Stripe-Konto noch nicht freigeschaltet (Konto-Verifizierung im Stripe-Dashboard abschließen).'));
            } catch (Throwable $e) {
                flash('error', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
            }
            break;

        case 'webhook_auto':
            try {
                $oldId = (string)setting('stripe_webhook_id');
                if ($oldId !== '') {
                    try {
                        Stripe::request('DELETE', 'webhook_endpoints/' . rawurlencode($oldId));
                    } catch (Throwable $e) {
                        // alter Endpunkt existiert evtl. nicht mehr
                    }
                }
                [$whId, $secret] = Stripe::createWebhookEndpoint(Stripe::webhookUrl());
                Settings::set('stripe_webhook_id', $whId);
                Settings::set('stripe_webhook_secret', $secret);
                log_activity('stripe_webhook_created', $whId);
                flash('success', 'Der Webhook wurde im Stripe-Konto angelegt und der Signaturschlüssel gespeichert.');
            } catch (Throwable $e) {
                flash('error', 'Webhook konnte nicht angelegt werden: ' . $e->getMessage() . ' – Alternativ kannst du ihn im Stripe-Dashboard manuell anlegen und den Signaturschlüssel unten eintragen.');
            }
            break;

        case 'webhook_manual':
            $secret = trim(post_str('stripe_webhook_secret'));
            if ($secret !== '' && !str_starts_with($secret, 'whsec_')) {
                flash('error', 'Der Signaturschlüssel beginnt mit „whsec_“.');
                break;
            }
            Settings::set('stripe_webhook_secret', $secret);
            flash('success', $secret === '' ? 'Der Webhook-Schlüssel wurde entfernt.' : 'Der Webhook-Signaturschlüssel wurde gespeichert.');
            break;

        case 'delete_sim':
            $ids = DB::col("SELECT id FROM purchases WHERE provider = 'simulation'");
            if ($ids) {
                $in = DB::placeholders($ids);
                DB::q("UPDATE protocols SET refund_purchase_id = NULL WHERE refund_purchase_id IN ($in)", $ids);
                DB::q("DELETE FROM purchases WHERE id IN ($in)", $ids);
            }
            log_activity('simulation_deleted', count($ids) . ' simulierte Käufe gelöscht');
            flash('success', count($ids) . ' simulierte Käufe wurden gelöscht.');
            break;
    }
    redirect('admin/payments');
}

$mode = Payments::mode();
$configured = Stripe::isConfigured();
$live = Stripe::isLive();
$webhookSecret = Settings::secret('stripe_webhook_secret') !== '';
$lastWebhook = (string)setting('stripe_last_webhook_at');
$simCount = (int)DB::val("SELECT COUNT(*) FROM purchases WHERE provider = 'simulation'");
$webhookUrl = Stripe::webhookUrl();
$publicUrlOk = str_starts_with($webhookUrl, 'https://') && !preg_match('#//(localhost|127\.|10\.|192\.168\.)#', $webhookUrl);
?>
<?= admin_head('Zahlungen / Stripe', 'Zahlungen laufen über Stripe Checkout (Karte, Apple Pay, Google Pay, SEPA u. v. m.). Rückerstattungen werden automatisch über Stripe ausgelöst.') ?>

<div class="grid grid-2" style="align-items:start">
  <div>
    <form method="post" action="<?= e(url('admin/payments')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mode">
      <h3>1. Zahlungsmodus</h3>
      <div class="radio-cards mb-2">
        <label class="radio-card"><input type="radio" name="payment_mode" value="simulation"<?= checked($mode === 'simulation') ?>><div><strong><?= icon('zap') ?> Simulation (Testbetrieb)</strong><span>Käufe und Erstattungen werden nur simuliert – ideal zum Ausprobieren ohne Stripe-Konto. Auf der Webseite erscheint ein Hinweisbanner.</span></div></label>
        <label class="radio-card"><input type="radio" name="payment_mode" value="stripe"<?= checked($mode === 'stripe') ?><?= $configured ? '' : ' disabled' ?>><div><strong><?= icon('card') ?> Stripe <?= $configured ? ($live ? badge('Live', 'success') : badge('Testmodus', 'info')) : badge('nicht eingerichtet', 'neutral') ?></strong><span>Echte Zahlungen über euer Stripe-Konto. <?= $configured ? '' : 'Zuerst den Schlüssel in Schritt 2 hinterlegen.' ?></span></div></label>
      </div>
      <button type="submit" class="btn btn-primary">Modus speichern</button>
    </form>

    <form method="post" action="<?= e(url('admin/payments')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="key">
      <h3>2. Stripe-Konto verbinden</h3>
      <?php if ($configured): ?>
        <p><?= icon('check-circle') ?> Verbunden<?= setting('stripe_account_name') ? ' mit <strong>' . e(setting('stripe_account_name')) . '</strong>' : '' ?> · Schlüssel <code><?= e(Settings::masked('stripe_secret_key')) ?></code> · <?= $live ? badge('Live', 'success') : badge('Testmodus', 'info') ?></p>
      <?php endif; ?>
      <?= field_text('stripe_secret_key', $configured ? 'Neuen Geheimschlüssel eintragen' : 'Geheimschlüssel (Secret Key)', '', ['type' => 'password', 'placeholder' => 'sk_live_… oder sk_test_…', 'attrs' => ['autocomplete' => 'off'], 'help' => 'Zu finden im <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">Stripe-Dashboard → Entwickler → API-Schlüssel</a>. Der Schlüssel wird verschlüsselt gespeichert.']) ?>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary">Schlüssel prüfen &amp; speichern</button>
        <?php if ($configured): ?>
          <button type="submit" name="remove" value="1" class="btn btn-outline-danger" data-confirm="Stripe-Schlüssel entfernen? Der Simulationsmodus wird aktiviert." formnovalidate>Entfernen</button>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($configured): ?>
      <form method="post" action="<?= e(url('admin/payments')) ?>" class="mb-2" style="margin-top:-8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="test">
        <button type="submit" class="btn btn-ghost btn-sm"><?= icon('refresh') ?> Verbindung testen</button>
      </form>
    <?php endif; ?>

    <div class="card">
      <h3>3. Webhook einrichten <?= $webhookSecret ? badge('eingerichtet', 'success') : badge('fehlt', 'warning') ?></h3>
      <p class="small muted">Über den Webhook meldet Stripe Zahlungen und Erstattungen zuverlässig zurück – auch wenn jemand den Browser nach dem Bezahlen schließt oder per SEPA zahlt.</p>
      <div class="code-box mb-2"><span><?= e($webhookUrl) ?></span><button type="button" class="btn btn-secondary btn-xs" data-copy="<?= e($webhookUrl) ?>"><?= icon('copy') ?> Kopieren</button></div>
      <?php if (!$publicUrlOk): ?>
        <div class="alert alert-warning"><?= icon('alert') ?><div>Die Adresse ist nicht öffentlich per HTTPS erreichbar. Stripe kann den Webhook nur an eine öffentliche HTTPS-Adresse schicken. Bitte die Basis-URL in <code>config/config.php</code> prüfen bzw. SSL beim Hoster aktivieren.</div></div>
      <?php endif; ?>
      <?php if ($lastWebhook !== ''): ?>
        <p class="small"><?= icon('check-circle') ?> Letzter Webhook empfangen: <?= e(fmt_datetime($lastWebhook)) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= e(url('admin/payments')) ?>" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="webhook_auto">
        <button type="submit" class="btn btn-primary"<?= $configured ? '' : ' disabled' ?>><?= icon('zap') ?> Webhook automatisch einrichten</button>
        <small class="help">Legt den Webhook mit allen nötigen Ereignissen in eurem Stripe-Konto an und speichert den Signaturschlüssel.</small>
      </form>
      <details>
        <summary class="small">Webhook manuell eintragen</summary>
        <p class="small muted mt-1">Im Stripe-Dashboard unter Entwickler → Webhooks einen Endpunkt mit obiger URL anlegen und diese Ereignisse auswählen: <code><?= e(implode(', ', Stripe::WEBHOOK_EVENTS)) ?></code>. Danach den Signaturschlüssel (whsec_…) hier eintragen.</p>
        <form method="post" action="<?= e(url('admin/payments')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="webhook_manual">
          <div class="input-group">
            <input type="password" name="stripe_webhook_secret" class="input" placeholder="<?= e(Settings::masked('stripe_webhook_secret') ?: 'whsec_…') ?>" autocomplete="off">
            <button type="submit" class="btn btn-secondary">Speichern</button>
          </div>
        </form>
      </details>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>Schritt für Schritt</h3>
      <ol class="steps-list">
        <li><strong>Stripe-Konto anlegen</strong> unter <a href="https://dashboard.stripe.com/register" target="_blank" rel="noopener">stripe.com</a> und die Angaben zur Fachschaft (Bankkonto, Vertretungsberechtigte) vervollständigen.</li>
        <li><strong>Geheimschlüssel kopieren:</strong> Dashboard → Entwickler → API-Schlüssel → „Geheimschlüssel“ (beginnt mit <code>sk_live_</code>). Zum Testen zuerst den Testmodus-Schlüssel <code>sk_test_</code> verwenden.</li>
        <li><strong>Hier einfügen</strong> (Schritt 2) – die Verbindung wird sofort geprüft.</li>
        <li><strong>„Webhook automatisch einrichten“</strong> klicken (Schritt 3).</li>
        <li><strong>Modus auf „Stripe“ stellen</strong> (Schritt 1) – fertig. Das Hinweisbanner zum Testmodus verschwindet.</li>
      </ol>
      <p class="small muted mb-0">Ein reiner „Link“ zum Stripe-Konto reicht technisch nicht aus, weil die Webseite Zahlungen automatisch freischalten und Erstattungen auslösen muss – dafür braucht sie den Geheimschlüssel.</p>
    </div>

    <div class="card">
      <h3>Testen mit Stripe-Testmodus</h3>
      <p class="small">Mit einem <code>sk_test_</code>-Schlüssel kannst du den echten Ablauf ohne echtes Geld testen. Testkarte: <code>4242 4242 4242 4242</code>, beliebiges Datum in der Zukunft, beliebige Prüfziffer.</p>
      <p class="small muted mb-0">Danach einfach den Live-Schlüssel eintragen und den Webhook erneut automatisch einrichten.</p>
    </div>

    <div class="card">
      <h3>Gebühren beachten</h3>
      <p class="small mb-0">Stripe berechnet pro Zahlung eine Gebühr (für europäische Karten z. B. ca. 1,5 % + 0,25 € – aktuelle Preise unter <a href="https://stripe.com/de/pricing" target="_blank" rel="noopener">stripe.com/de/pricing</a>) und erstattet diese bei Rückerstattungen in der Regel nicht zurück. Bei <?= e(money(Settings::int('price_cents'))) ?> Preis und <?= e(money(Settings::int('refund_cents'))) ?> Erstattung bleibt nach Gebühren nur ein kleiner Betrag – ggf. den Erstattungsbetrag anpassen.</p>
    </div>

    <form method="post" action="<?= e(url('admin/payments')) ?>" class="card danger-zone" data-confirm="Alle simulierten Käufe und Erstattungen endgültig löschen?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_sim">
      <h3>Simulationsdaten löschen</h3>
      <p class="small muted">Vor dem Start mit echten Zahlungen kannst du alle simulierten Käufe entfernen (<?= $simCount ?> vorhanden). Die dadurch freigeschalteten Zugänge entfallen.</p>
      <button type="submit" class="btn btn-outline-danger btn-sm"<?= $simCount ? '' : ' disabled' ?>><?= icon('trash') ?> <?= $simCount ?> simulierte Käufe löschen</button>
    </form>
  </div>
</div>
