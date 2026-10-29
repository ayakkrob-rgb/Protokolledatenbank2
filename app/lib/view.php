<?php
/**
 * Darstellung: Layout-Auswahl, Seitentitel und Formular-Hilfsfunktionen.
 */
class View
{
    public static string $title = '';
    /** 'public', 'admin' oder 'none' */
    public static string $layout = 'public';
    public static string $active = '';
    public static string $bodyClass = '';

    public static function render(string $content): void
    {
        if (self::$layout === 'none') {
            echo $content;
            return;
        }
        $file = self::$layout === 'admin' ? 'admin_layout.php' : 'layout.php';
        require APP_ROOT . '/app/views/' . $file;
    }
}

function field_id(string $name): string
{
    return 'f_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);
}

function field_attrs(array $attrs): string
{
    $html = '';
    foreach ($attrs as $k => $v) {
        if ($v === false || $v === null) {
            continue;
        }
        $html .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    return $html;
}

function field_help(array $o): string
{
    return !empty($o['help']) ? '<small class="help">' . $o['help'] . '</small>' : '';
}

/** Textfeld. Optionen: type, help (HTML), required, placeholder, attrs, class */
function field_text(string $name, string $label, $value = '', array $o = []): string
{
    $id = $o['id'] ?? field_id($name);
    $attrs = array_merge([
        'type' => $o['type'] ?? 'text',
        'name' => $name,
        'id' => $id,
        'value' => (string)$value,
        'required' => !empty($o['required']),
        'placeholder' => $o['placeholder'] ?? null,
        'class' => 'input',
    ], $o['attrs'] ?? []);
    return '<div class="field' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"><label for="' . e($id) . '">' . e($label)
        . (!empty($o['required']) ? ' <span class="req">*</span>' : '') . '</label>'
        . '<input' . field_attrs($attrs) . '>' . field_help($o) . '</div>';
}

function field_textarea(string $name, string $label, $value = '', array $o = []): string
{
    $id = $o['id'] ?? field_id($name);
    $attrs = array_merge([
        'name' => $name,
        'id' => $id,
        'rows' => $o['rows'] ?? 5,
        'required' => !empty($o['required']),
        'placeholder' => $o['placeholder'] ?? null,
        'class' => 'input' . (!empty($o['mono']) ? ' mono' : ''),
    ], $o['attrs'] ?? []);
    return '<div class="field' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"><label for="' . e($id) . '">' . e($label)
        . (!empty($o['required']) ? ' <span class="req">*</span>' : '') . '</label>'
        . '<textarea' . field_attrs($attrs) . '>' . e((string)$value) . '</textarea>' . field_help($o) . '</div>';
}

/** Auswahlfeld. $options: [Wert => Beschriftung] */
function field_select(string $name, string $label, array $options, $selectedValue = '', array $o = []): string
{
    $id = $o['id'] ?? field_id($name);
    $attrs = array_merge(['name' => $name, 'id' => $id, 'class' => 'input', 'required' => !empty($o['required'])], $o['attrs'] ?? []);
    $html = '<div class="field' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"><label for="' . e($id) . '">' . e($label)
        . (!empty($o['required']) ? ' <span class="req">*</span>' : '') . '</label><select' . field_attrs($attrs) . '>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . selected($value, $selectedValue) . '>' . e($text) . '</option>';
    }
    return $html . '</select>' . field_help($o) . '</div>';
}

/** Schalter (Checkbox), sendet immer 0 oder 1. */
function field_toggle(string $name, string $label, bool $isChecked, string $help = ''): string
{
    $id = field_id($name);
    return '<div class="field field-toggle"><input type="hidden" name="' . e($name) . '" value="0">'
        . '<label class="switch" for="' . e($id) . '"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . checked($isChecked) . '>'
        . '<span class="switch-track"></span><span class="switch-label">' . e($label) . '</span></label>'
        . ($help !== '' ? '<small class="help">' . $help . '</small>' : '') . '</div>';
}

function badge(string $text, string $variant = 'neutral'): string
{
    return '<span class="badge badge-' . e($variant) . '">' . e($text) . '</span>';
}

function protocol_status_badge(string $status): string
{
    $map = [
        'published' => ['Veröffentlicht', 'success'],
        'pending' => ['Wartet auf Freigabe', 'warning'],
        'rejected' => ['Abgelehnt', 'danger'],
    ];
    [$t, $v] = $map[$status] ?? [$status, 'neutral'];
    return badge($t, $v);
}

function role_label(string $role): string
{
    return ['admin' => 'Admin', 'moderator' => 'Moderation', 'student' => 'Studierende*r'][$role] ?? $role;
}

/** Kopfbereich einer Admin-Seite. $actions ist fertiges HTML (Buttons). */
function admin_head(string $title, string $subtitle = '', string $actions = ''): string
{
    return '<div class="admin-page-head"><div><h1>' . e($title) . '</h1>' . ($subtitle !== '' ? '<p>' . $subtitle . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="btn-group">' . $actions . '</div>' : '') . '</div>';
}

/** Kennzahl-Kachel für das Dashboard. */
function kpi(string $iconName, string $label, string $value, string $sub = '', string $variant = '', ?string $link = null): string
{
    $inner = '<div class="kpi-icon">' . icon($iconName) . '</div><div><div class="kpi-label">' . e($label) . '</div><div class="kpi-value">' . e($value) . '</div>'
        . ($sub !== '' ? '<div class="kpi-sub">' . $sub . '</div>' : '') . '</div>';
    $class = 'kpi' . ($variant !== '' ? ' ' . $variant : '');
    return $link ? '<a class="' . $class . ' plain" href="' . e($link) . '" style="color:inherit">' . $inner . '</a>' : '<div class="' . $class . '">' . $inner . '</div>';
}

/** Kleiner POST-Button (für Aktionen in Tabellen). */
function post_button(string $action, string $label, array $fields = [], string $class = 'btn btn-secondary btn-sm', string $confirm = ''): string
{
    $html = '<form method="post" action="' . e($action) . '" class="inline-form"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $html . '<button type="submit" class="' . e($class) . '">' . $label . '</button></form>';
}

/** Lesbare Bezeichnung für Einträge im Aktivitätslog. */
function activity_label(string $action): string
{
    static $map = [
        'register' => 'Registrierung', 'verify' => 'E-Mail bestätigt', 'password_reset_requested' => 'Passwort-Reset angefordert',
        'password_reset' => 'Passwort zurückgesetzt', 'password_changed' => 'Passwort geändert', 'account_deleted' => 'Konto gelöscht',
        'purchase_created' => 'Kauf gestartet', 'purchase_paid' => 'Kauf bezahlt', 'purchase_examiners_added' => 'Prüfer nachgetragen',
        'purchase_marked_paid' => 'Als bezahlt markiert', 'purchase_revoked' => 'Zugang entzogen', 'purchase_restored' => 'Zugang wiederhergestellt',
        'purchase_deleted' => 'Kauf gelöscht', 'purchase_examiner_added' => 'Prüfer zu Kauf hinzugefügt', 'purchase_examiner_removed' => 'Prüfer aus Kauf entfernt',
        'refund_succeeded' => 'Erstattung erfolgreich', 'refund_pending' => 'Erstattung veranlasst', 'refund_failed' => 'Erstattung fehlgeschlagen',
        'refund_manual' => 'Manuelle Erstattung nötig', 'refund_admin' => 'Erstattung durch Admin', 'refund_marked_done' => 'Erstattung erledigt',
        'refund_external' => 'Erstattung im Stripe-Dashboard',
        'protocol_uploaded' => 'Protokoll hochgeladen', 'protocol_published' => 'Protokoll freigegeben', 'protocol_rejected' => 'Protokoll abgelehnt',
        'protocol_deleted' => 'Protokoll gelöscht', 'protocol_updated' => 'Protokoll bearbeitet', 'protocol_created' => 'Protokoll angelegt',
        'examiner_created' => 'Prüfer angelegt', 'examiner_updated' => 'Prüfer bearbeitet', 'examiner_merged' => 'Prüfer zusammengeführt',
        'examiner_deleted' => 'Prüfer gelöscht', 'examiners_bulk' => 'Prüfer-Sammelaktion',
        'exam_created' => 'Prüfung angelegt', 'exam_updated' => 'Prüfung bearbeitet', 'exam_deleted' => 'Prüfung gelöscht', 'exam_toggled' => 'Prüfung (de)aktiviert',
        'user_created' => 'Konto angelegt', 'user_updated' => 'Konto bearbeitet', 'user_deleted' => 'Konto gelöscht', 'user_anonymized' => 'Konto anonymisiert',
        'settings_saved' => 'Einstellungen gespeichert', 'payment_mode' => 'Zahlungsmodus geändert', 'stripe_key_saved' => 'Stripe-Schlüssel gespeichert',
        'stripe_webhook_created' => 'Stripe-Webhook eingerichtet', 'simulation_deleted' => 'Simulationsdaten gelöscht', 'mail_settings' => 'E-Mail-Einstellungen',
        'page_saved' => 'Text gespeichert', 'import' => 'Import', 'import_csv' => 'CSV-Import', 'download_zip' => 'ZIP-Download',
        'demo_seeded' => 'Demodaten angelegt', 'demo_removed' => 'Demodaten entfernt', 'installed' => 'Installation',
    ];
    return $map[$action] ?? $action;
}
