<?php
/** Admin: Allgemeine Einstellungen. */
View::$title = 'Einstellungen';
View::$active = 'settings';
$tabs = [
    'general' => ['Allgemein', 'sliders'],
    'registration' => ['Registrierung', 'user'],
    'pricing' => ['Preise & Erstattung', 'euro'],
    'uploads' => ['Uploads & Moderation', 'upload'],
];
$tab = get_str('tab', 'general');
if (!isset($tabs[$tab])) {
    $tab = 'general';
}
$errors = [];
$save = [];

if (is_post()) {
    $tab = post_str('tab');
    switch ($tab) {
        case 'general':
            $save = [
                'site_name' => post_str('site_name') ?: 'Protokolldatenbank',
                'org_name' => post_str('org_name'),
                'contact_email' => post_str('contact_email'),
                'notify_email' => post_str('notify_email'),
                'notify_uploads' => post_bool('notify_uploads') ? '1' : '0',
                'notify_refund_errors' => post_bool('notify_refund_errors') ? '1' : '0',
                'announcement' => post_str('announcement'),
                'maintenance_mode' => post_bool('maintenance_mode') ? '1' : '0',
                'maintenance_message' => post_str('maintenance_message'),
            ];
            foreach (['contact_email', 'notify_email'] as $k) {
                if ($save[$k] !== '' && !filter_var($save[$k], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Bitte gib eine gültige E-Mail-Adresse ein (' . $k . ').';
                }
            }
            break;
        case 'registration':
            $domains = preg_split('/[\s,;]+/', mb_strtolower(post_str('allowed_domains'))) ?: [];
            $clean = [];
            foreach ($domains as $d) {
                $d = ltrim(trim($d), '@');
                if ($d === '') {
                    continue;
                }
                if (!preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) {
                    $errors[] = '„' . $d . '“ ist keine gültige Domain.';
                }
                $clean[] = $d;
            }
            $save = [
                'allowed_domains' => implode("\n", array_unique($clean)),
                'registration_open' => post_bool('registration_open') ? '1' : '0',
            ];
            break;
        case 'pricing':
            $price = parse_money(post_str('price'));
            $refund = parse_money(post_str('refund'));
            if ($price === null || ($price > 0 && $price < 50)) {
                $errors[] = 'Bitte gib einen gültigen Preis ein (mindestens 0,50 € – Vorgabe von Stripe).';
            }
            if ($refund === null) {
                $errors[] = 'Bitte gib einen gültigen Erstattungsbetrag ein.';
            } elseif ($price !== null && $refund > $price) {
                $errors[] = 'Die Erstattung darf nicht höher sein als der Preis.';
            }
            $max = max(1, min(10, post_int('default_max_examiners', 4)));
            $save = [
                'price_cents' => (string)$price,
                'refund_cents' => (string)$refund,
                'default_max_examiners' => (string)$max,
                'access_days' => (string)max(0, post_int('access_days')),
                'refund_enabled' => post_bool('refund_enabled') ? '1' : '0',
                'refund_mode' => post_str('refund_mode') === 'manual' ? 'manual' : 'auto',
                'refund_same_exam' => post_bool('refund_same_exam') ? '1' : '0',
                'refund_days' => (string)max(0, post_int('refund_days')),
            ];
            if (!$errors && post_bool('apply_max')) {
                DB::q('UPDATE exams SET max_examiners = ?', [$max]);
            }
            break;
        case 'uploads':
            $exts = preg_split('/[\s,;]+/', mb_strtolower(post_str('allowed_extensions'))) ?: [];
            $exts = array_values(array_filter(array_unique(array_map(fn($x) => ltrim(trim($x), '.'), $exts))));
            $unknown = array_diff($exts, array_keys(Protocols::MIME));
            if ($unknown) {
                $errors[] = 'Nicht unterstützte Dateitypen: ' . implode(', ', $unknown) . '. Möglich sind: ' . implode(', ', array_keys(Protocols::MIME));
            }
            $save = [
                'moderation' => post_bool('moderation') ? '1' : '0',
                'allow_new_examiners' => post_bool('allow_new_examiners') ? '1' : '0',
                'new_examiner_review' => post_bool('new_examiner_review') ? '1' : '0',
                'allowed_extensions' => implode(',', $exts),
                'max_upload_mb' => (string)max(1, min(500, post_int('max_upload_mb', 20))),
                'max_files' => (string)max(1, min(20, post_int('max_files', 5))),
                'min_content_length' => (string)max(0, post_int('min_content_length')),
                'watermark' => post_bool('watermark') ? '1' : '0',
            ];
            break;
        default:
            abort(400);
    }
    if (!$errors) {
        Settings::setMany($save);
        log_activity('settings_saved', $tab);
        flash('success', 'Die Einstellungen wurden gespeichert.');
        redirect('admin/settings', ['tab' => $tab]);
    }
}
$val = fn(string $k) => is_post() && isset($save[$k]) ? $save[$k] : (string)setting($k);
?>
<?= admin_head('Einstellungen', 'Grundlegende Einstellungen der Webseite. Zahlungen, E-Mail und Texte findest du in eigenen Bereichen.') ?>

<nav class="tabs">
  <?php foreach ($tabs as $key => [$label, $ic]): ?>
    <a href="<?= e(url('admin/settings', ['tab' => $key])) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= icon($ic) ?> <?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert') ?><div><?= e($err) ?></div></div><?php endforeach; ?>

<form method="post" action="<?= e(url('admin/settings')) ?>" class="card" style="max-width:860px">
  <?= csrf_field() ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">

  <?php if ($tab === 'general'): ?>
    <div class="form-section">
      <h3>Webseite</h3>
      <div class="form-row">
        <?= field_text('site_name', 'Name der Webseite', $val('site_name'), ['required' => true]) ?>
        <?= field_text('org_name', 'Fachschaft / Organisation', $val('org_name')) ?>
      </div>
      <?= field_text('contact_email', 'Kontakt-E-Mail (öffentlich)', $val('contact_email'), ['type' => 'email', 'help' => 'Wird im Footer und in E-Mails als Antwortadresse angezeigt.']) ?>
      <?= field_textarea('announcement', 'Hinweisbanner (optional)', $val('announcement'), ['rows' => 2, 'help' => 'Wird oben auf jeder Seite angezeigt, z. B. „Neue M3-Protokolle aus dem Herbst sind online!“. Leer = kein Banner.']) ?>
    </div>
    <div class="form-section">
      <h3>Benachrichtigungen an die Fachschaft</h3>
      <?= field_text('notify_email', 'E-Mail für Benachrichtigungen', $val('notify_email'), ['type' => 'email', 'help' => 'Hierhin gehen Hinweise zu neuen Uploads und Problemen. Leer = keine Benachrichtigungen.']) ?>
      <?= field_toggle('notify_uploads', 'Bei jedem neuen Upload benachrichtigen', $val('notify_uploads') === '1') ?>
      <?= field_toggle('notify_refund_errors', 'Bei fehlgeschlagenen oder manuellen Rückerstattungen benachrichtigen', $val('notify_refund_errors') === '1') ?>
    </div>
    <div class="form-section">
      <h3>Wartungsmodus</h3>
      <?= field_toggle('maintenance_mode', 'Wartungsmodus aktivieren', $val('maintenance_mode') === '1', 'Nur das Team kann die Seite nutzen. Studierende sehen die folgende Meldung.') ?>
      <?= field_textarea('maintenance_message', 'Meldung im Wartungsmodus', $val('maintenance_message'), ['rows' => 2]) ?>
    </div>

  <?php elseif ($tab === 'registration'): ?>
    <div class="form-section">
      <h3>Erlaubte E-Mail-Domains</h3>
      <p class="section-help">Nur Adressen mit diesen Domains können sich registrieren. Eine Domain pro Zeile. Mit <code>*.</code> davor sind alle Subdomains erlaubt.</p>
      <?= field_textarea('allowed_domains', 'Domains', $val('allowed_domains'), ['rows' => 4, 'mono' => true, 'placeholder' => "stud.uni-beispiel.de\n*.uni-beispiel.de", 'help' => 'Beispiel: <code>stud.uni-heidelberg.de</code> erlaubt nur diese Domain, <code>*.uni-heidelberg.de</code> erlaubt auch <code>stud.uni-heidelberg.de</code>, <code>med.uni-heidelberg.de</code> usw. Leer = alle Adressen erlaubt (nicht empfohlen).']) ?>
      <?php if (Auth::allowedDomains()): ?>
        <p class="small">Aktuell erlaubt: <?= implode(' ', array_map(fn($d) => badge('@' . $d, 'primary'), Auth::allowedDomains())) ?></p>
      <?php endif; ?>
    </div>
    <div class="form-section">
      <h3>Registrierung</h3>
      <?= field_toggle('registration_open', 'Neue Registrierungen erlauben', $val('registration_open') === '1', 'Ausgeschaltet können nur noch bestehende Konten genutzt und neue Konten vom Team angelegt werden.') ?>
      <p class="small muted">Jede neue Registrierung muss ihre E-Mail-Adresse über einen Bestätigungslink bestätigen (48 Stunden gültig). Ohne Bestätigung ist keine Anmeldung möglich.</p>
    </div>

  <?php elseif ($tab === 'pricing'): ?>
    <div class="form-section">
      <h3>Preis &amp; Umfang</h3>
      <div class="form-row form-row-3">
        <?= field_text('price', 'Preis pro Freischaltung', money_input($val('price_cents')), ['help' => 'in Euro, z. B. 30,00']) ?>
        <?= field_text('default_max_examiners', 'Max. Prüfer pro Freischaltung', $val('default_max_examiners'), ['type' => 'number', 'attrs' => ['min' => 1, 'max' => 10]]) ?>
        <?= field_text('access_days', 'Zugang gültig (Tage)', $val('access_days'), ['type' => 'number', 'attrs' => ['min' => 0], 'help' => '0 = unbegrenzt']) ?>
      </div>
      <label class="checkbox"><input type="checkbox" name="apply_max" value="1"> Max. Prüferzahl auch für alle bestehenden Prüfungen übernehmen</label>
      <p class="small muted">Preis, Erstattung und Prüferzahl können je Prüfung abweichend festgelegt werden (unter <a href="<?= e(url('admin/exams')) ?>">Prüfungen</a>).</p>
    </div>
    <div class="form-section">
      <h3>Rückerstattung bei eigenem Protokoll</h3>
      <?= field_toggle('refund_enabled', 'Rückerstattung aktiv', $val('refund_enabled') === '1') ?>
      <div class="form-row">
        <?= field_text('refund', 'Erstattungsbetrag', money_input($val('refund_cents')), ['help' => 'Wird nach dem Upload eines eigenen Protokolls zurückgezahlt.']) ?>
        <?= field_text('refund_days', 'Frist (Tage nach Kauf)', $val('refund_days'), ['type' => 'number', 'attrs' => ['min' => 0], 'help' => '0 = keine Frist. Bei sehr alten Zahlungen kann eine Erstattung je nach Zahlungsart nicht mehr möglich sein – sie erscheint dann im Adminbereich als fehlgeschlagen.']) ?>
      </div>
      <div class="field">
        <label>Wann wird erstattet?</label>
        <div class="radio-cards">
          <label class="radio-card"><input type="radio" name="refund_mode" value="auto"<?= checked($val('refund_mode') !== 'manual') ?>><div><strong>Automatisch</strong><span>Sobald das Protokoll veröffentlicht ist – also sofort nach dem Upload bzw. nach der Freigabe, wenn die Moderation aktiv ist.</span></div></label>
          <label class="radio-card"><input type="radio" name="refund_mode" value="manual"<?= checked($val('refund_mode') === 'manual') ?>><div><strong>Nach manueller Prüfung</strong><span>Das Team löst die Erstattung im Adminbereich per Klick aus (Dashboard zeigt offene Fälle).</span></div></label>
        </div>
      </div>
      <?= field_toggle('refund_same_exam', 'Nur für Protokolle derselben Prüfung erstatten', $val('refund_same_exam') === '1', 'Empfohlen: Wer M2 gekauft hat, bekommt das Geld für ein M2-Protokoll zurück.') ?>
      <p class="small muted">Pro Kauf wird höchstens einmal erstattet. Ein Upload mit mehreren Prüfern zählt als ein Protokoll.</p>
    </div>

  <?php elseif ($tab === 'uploads'): ?>
    <div class="form-section">
      <h3>Moderation</h3>
      <?= field_toggle('moderation', 'Neue Protokolle vor der Veröffentlichung prüfen', $val('moderation') === '1', 'Aus = Protokolle sind sofort sichtbar (vollautomatisch). An = das Team gibt jedes Protokoll frei; die Erstattung erfolgt erst danach.') ?>
      <?= field_toggle('allow_new_examiners', 'Studierende dürfen beim Upload neue Prüfer anlegen', $val('allow_new_examiners') === '1') ?>
      <?= field_toggle('new_examiner_review', 'Neu angelegte Prüfer zur Kontrolle markieren', $val('new_examiner_review') === '1', 'Markierte Prüfer erscheinen im Dashboard, damit doppelte Einträge schnell zusammengeführt werden können.') ?>
    </div>
    <div class="form-section">
      <h3>Dateien</h3>
      <?= field_text('allowed_extensions', 'Erlaubte Dateitypen', $val('allowed_extensions'), ['help' => 'Kommagetrennt. Möglich: ' . e(implode(', ', array_keys(Protocols::MIME)))]) ?>
      <div class="form-row form-row-3">
        <?= field_text('max_upload_mb', 'Max. Dateigröße (MB)', $val('max_upload_mb'), ['type' => 'number', 'attrs' => ['min' => 1], 'help' => 'Server-Limit: ' . e(human_filesize(Protocols::serverUploadLimit()))]) ?>
        <?= field_text('max_files', 'Max. Dateien je Prüfer', $val('max_files'), ['type' => 'number', 'attrs' => ['min' => 1, 'max' => 20]]) ?>
        <?= field_text('min_content_length', 'Mindestlänge Text (Zeichen)', $val('min_content_length'), ['type' => 'number', 'attrs' => ['min' => 0], 'help' => 'Gilt nur, wenn keine Datei hochgeladen wird.']) ?>
      </div>
    </div>
    <div class="form-section">
      <h3>Schutz vor Weitergabe</h3>
      <?= field_toggle('watermark', 'Wasserzeichen mit E-Mail-Adresse beim Lesen anzeigen', $val('watermark') === '1', 'Blendet die E-Mail-Adresse der lesenden Person dezent im Hintergrund des Protokolltexts ein. Zusätzlich werden alle Abrufe protokolliert.') ?>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
  </div>
</form>
