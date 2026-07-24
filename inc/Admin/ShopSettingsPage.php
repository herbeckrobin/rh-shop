<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhShop\Orders\Order;
use RhShop\Stripe\Config;
use RhShop\Stripe\StripeClient;
use RhShop\Stripe\WebhookInstaller;
use RhShop\Support\Money;
use RhShop\Support\Secret;
use WP_Error;

/**
 * Der Shop-Settings-Tab: Stripe-Anbindung (Keys) und Währung.
 *
 * Bespoke gerendert über die tab_content-Hooks des Core (KEINE GroupInterface),
 * weil die Secret-Felder write-only sind: der gespeicherte Key wird nie ins HTML
 * zurückgeschrieben (Klartext-Leak im DOM/der DB vermeiden), nur bei nicht-leerer
 * Eingabe aktualisiert. Der Publishable Key ist öffentlich und liegt im Klartext.
 * Speicherung in derselben Option `rhbp_settings_shop`, aus der Config liest.
 */
final class ShopSettingsPage
{
    public const TAB_ID = 'shop';
    private const CAPABILITY = 'manage_options';
    private const NONCE = 'rhshop_settings_save';

    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_rhshop_settings_save', [$this, 'handleSave']);
        add_action('admin_post_rhshop_webhook_install', [$this, 'handleWebhookInstall']);
        add_action('admin_post_rhshop_webhook_remove', [$this, 'handleWebhookRemove']);
    }

    public function renderMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige nach Redirect.
        $message = isset($_GET['rhbp_message']) ? sanitize_key(wp_unslash($_GET['rhbp_message'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $detail = isset($_GET['rhbp_detail']) ? sanitize_text_field(wp_unslash($_GET['rhbp_detail'])) : '';

        $map = [
            'shop_saved' => ['success', __('Einstellungen wurden gespeichert.', 'rh-shop')],
            'webhook_installed' => ['success', __('Webhook wurde bei Stripe eingerichtet.', 'rh-shop')],
            'webhook_removed' => ['success', __('Webhook wurde entfernt.', 'rh-shop')],
            'webhook_error' => ['warn', $detail !== '' ? $detail : __('Webhook konnte nicht eingerichtet werden.', 'rh-shop')],
        ];

        if (isset($map[$message])) {
            [$type, $text] = $map[$message];
            printf('<div class="rhbp-callout rhbp-callout--%s">%s</div>', esc_attr($type === 'success' ? 'success' : 'warn'), esc_html($text));
        }
    }

    public function render(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        echo '<div class="rhbp-card" style="max-width:640px">';

        // Status-Pill
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statusPill() liefert bereits escaptes Markup.
        echo '<p>' . $this->statusPill() . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="rhshop_settings_save" />';

        // Publishable Key (öffentlich)
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-pk">' . esc_html__('Publishable Key', 'rh-shop') . '</label>';
        printf(
            '<input type="text" id="rhshop-pk" name="publishable_key" value="%s" placeholder="pk_test_..." class="regular-text" autocomplete="off" />',
            esc_attr($this->config->publishableKey())
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Öffentlicher Schlüssel, wird im Frontend genutzt (pk_test_ im Test-, pk_live_ im Live-Modus).', 'rh-shop') . '</p>';
        echo '</div>';

        // Secret Key (write-only)
        $this->renderSecretField(
            'secret_key',
            __('Secret Key', 'rh-shop'),
            'sk_test_...',
            __('Geheimer Schlüssel für serverseitige Stripe-Aufrufe. Wird verschlüsselt gespeichert und nie wieder angezeigt.', 'rh-shop'),
            $this->config->hasStoredSecret(),
            defined(Config::CONST_SECRET) && constant(Config::CONST_SECRET) !== ''
        );

        // Webhook Signing Secret (write-only)
        $this->renderSecretField(
            'webhook_secret',
            __('Webhook Signing Secret', 'rh-shop'),
            'whsec_...',
            __('Signatur-Geheimnis aus dem Stripe-Webhook. Verifiziert, dass Zahlungs-Events wirklich von Stripe kommen.', 'rh-shop'),
            $this->config->hasStoredWebhookSecret(),
            defined(Config::CONST_WEBHOOK) && constant(Config::CONST_WEBHOOK) !== ''
        );

        // Währung
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-currency">' . esc_html__('Währung', 'rh-shop') . '</label>';
        echo '<select id="rhshop-currency" name="currency">';
        foreach (Config::currencies() as $code => $symbol) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($code),
                selected($this->config->currency(), $code, false),
                esc_html(strtoupper($code)),
                esc_html($symbol)
            );
        }
        echo '</select>';
        echo '</div>';

        // Steuer-Modus
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-tax">' . esc_html__('Steuer', 'rh-shop') . '</label>';
        echo '<select id="rhshop-tax" name="tax_mode">';
        $mode = $this->config->taxMode();
        $modes = [
            Order::TAX_KLEINUNTERNEHMER => __('Kleinunternehmer (§ 19 UStG, keine USt)', 'rh-shop'),
            Order::TAX_VAT => __('Regelbesteuerung (19 % USt im Preis enthalten)', 'rh-shop'),
        ];
        foreach ($modes as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($mode, $value, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Kleinunternehmer weist keine USt aus (§19-Hinweis auf der Kasse). Regelbesteuerung rechnet die enthaltene USt aus dem Bruttopreis heraus.', 'rh-shop') . '</p>';
        echo '</div>';

        // Versandpauschale
        $shippingCents = $this->config->shippingCents();
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-shipping">' . esc_html__('Versandpauschale', 'rh-shop') . '</label>';
        printf(
            '<input type="text" id="rhshop-shipping" name="shipping_cents" value="%s" placeholder="0,00" class="regular-text" style="max-width:140px" /> €',
            esc_attr($shippingCents > 0 ? number_format($shippingCents / 100, 2, ',', '') : '')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Pauschale Versandkosten pro Bestellung. Leer oder 0 = kostenloser Versand.', 'rh-shop') . '</p>';
        echo '</div>';

        // Widerrufs-Button (§356a)
        echo '<div class="rhbp-field">';
        echo '<label><input type="checkbox" name="widerruf_button" value="1" ' . checked($this->config->widerrufButtonEnabled(), true, false) . ' /> '
            . esc_html__('"Vertrag widerrufen"-Button auf jeder Seite anzeigen (§356a)', 'rh-shop') . '</label>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Pflicht für B2C-Shops mit Widerrufsrecht. Nur abschalten, wenn du den Button selbst im Template platzierst oder kein Widerrufsrecht besteht.', 'rh-shop') . '</p>';
        echo '</div>';

        // Rechnung über Stripe Invoicing
        echo '<div class="rhbp-field">';
        echo '<label><input type="checkbox" name="invoice_enabled" value="1" ' . checked($this->config->invoiceEnabled(), true, false) . ' /> '
            . esc_html__('Rechnung nach der Zahlung über Stripe erstellen', 'rh-shop') . '</label>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Stripe erzeugt eine fortlaufende PDF-Rechnung und schickt sie dem Kunden (Stripe Invoicing, kostenpflichtiges Add-on). Verkäufer-Stammdaten (Anschrift, Steuernummer) pflegst du in den Stripe-Rechnungseinstellungen.', 'rh-shop') . '</p>';
        echo '</div>';

        echo '<p><button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Speichern', 'rh-shop') . '</button></p>';
        echo '</form>';
        echo '</div>';

        $this->renderWebhookCard();
    }

    /**
     * Eigene Karte (ausserhalb des Haupt-Formulars, weil eigene admin-post-Forms)
     * für den automatischen Webhook.
     */
    private function renderWebhookCard(): void
    {
        $installer = new WebhookInstaller($this->config, new StripeClient($this->config));
        $installed = $this->config->webhookEndpointId() !== '';

        echo '<div class="rhbp-card" style="max-width:640px;margin-top:1rem">';
        echo '<h3 style="margin-top:0">' . esc_html__('Webhook', 'rh-shop') . '</h3>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Der Webhook bestätigt Zahlungen serverseitig (Bestellung wird auf bezahlt gesetzt). Auf einer öffentlich erreichbaren Seite richtet ihn das Plugin per Klick selbst ein, kein Kopieren im Stripe-Dashboard nötig.', 'rh-shop') . '</p>';

        if ($installed) {
            $pill = '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('Automatisch eingerichtet', 'rh-shop') . '</span>';
        } elseif ($this->config->hasStoredWebhookSecret()) {
            $pill = '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('Manuell / CLI konfiguriert (Secret gesetzt)', 'rh-shop') . '</span>';
        } else {
            $pill = '<span class="rhbp-pill rhbp-pill--warn">' . esc_html__('Nicht eingerichtet', 'rh-shop') . '</span>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pill-Markup bereits escapt.
        echo '<p>' . $pill . '</p>';

        if ($installer->isLocalUrl()) {
            echo '<div class="rhbp-callout rhbp-callout--warn">' . esc_html__('Diese Seite ist lokal und für Stripe nicht erreichbar. Für lokale Tests die Stripe-CLI nutzen und das Signing-Secret oben eintragen. Der automatische Webhook ist für die öffentliche Live-Seite gedacht.', 'rh-shop') . '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.5rem">';
        wp_nonce_field('rhshop_webhook_install');
        echo '<input type="hidden" name="action" value="rhshop_webhook_install" />';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">'
            . ($installed ? esc_html__('Webhook neu einrichten', 'rh-shop') : esc_html__('Webhook automatisch einrichten', 'rh-shop'))
            . '</button>';
        echo '</form>';

        if ($installed) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
            wp_nonce_field('rhshop_webhook_remove');
            echo '<input type="hidden" name="action" value="rhshop_webhook_remove" />';
            echo '<button type="submit" class="rhbp-btn rhbp-btn--ghost">' . esc_html__('Webhook entfernen', 'rh-shop') . '</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    private function renderSecretField(string $name, string $label, string $placeholder, string $desc, bool $isSet, bool $fromConstant): void
    {
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-' . esc_attr($name) . '">' . esc_html($label) . '</label>';

        if ($fromConstant) {
            echo '<p><span class="rhbp-pill rhbp-pill--ok">' . esc_html__('Über Konstante in wp-config.php gesetzt', 'rh-shop') . '</span></p>';
            echo '<p class="rhbp-field__desc">' . esc_html($desc) . '</p>';
            echo '</div>';
            return;
        }

        printf(
            '<input type="password" id="rhshop-%1$s" name="%1$s" value="" placeholder="%2$s" class="regular-text" autocomplete="new-password" />',
            esc_attr($name),
            esc_attr($isSet ? '•••••••••• (gesetzt, zum Ändern neu eingeben)' : $placeholder)
        );

        if ($isSet) {
            printf(
                '<label style="display:block;margin-top:6px"><input type="checkbox" name="%s_remove" value="1" /> %s</label>',
                esc_attr($name),
                esc_html__('Gespeicherten Schlüssel entfernen', 'rh-shop')
            );
        }

        echo '<p class="rhbp-field__desc">' . esc_html($desc) . '</p>';
        echo '</div>';
    }

    private function statusPill(): string
    {
        if (! $this->config->isConfigured()) {
            return '<span class="rhbp-pill rhbp-pill--warn">' . esc_html__('Nicht konfiguriert', 'rh-shop') . '</span>';
        }

        $mode = $this->config->isTestMode()
            ? __('Verbunden (Test-Modus)', 'rh-shop')
            : __('Verbunden (Live-Modus)', 'rh-shop');

        return '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html($mode) . '</span>';
    }

    public function handleSave(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-shop'));
        }
        check_admin_referer(self::NONCE);

        $values = [
            Config::FIELD_PUBLISHABLE => isset($_POST['publishable_key']) ? sanitize_text_field(wp_unslash($_POST['publishable_key'])) : '',
            Config::FIELD_CURRENCY => $this->sanitizeCurrency($_POST['currency'] ?? 'eur'),
            Config::FIELD_TAX_MODE => $this->sanitizeTaxMode($_POST['tax_mode'] ?? ''),
            Config::FIELD_SHIPPING => Money::toCents(isset($_POST['shipping_cents']) ? sanitize_text_field(wp_unslash($_POST['shipping_cents'])) : ''),
            Config::FIELD_WIDERRUF_BUTTON => isset($_POST['widerruf_button']),
            Config::FIELD_INVOICE => isset($_POST['invoice_enabled']),
        ];

        $this->collectSecret($values, 'secret_key', Config::FIELD_SECRET_ENC);
        $this->collectSecret($values, 'webhook_secret', Config::FIELD_WEBHOOK_ENC);

        rhbp_update_settings(Config::GROUP, $values);

        $this->redirect();
    }

    /**
     * Write-only-Logik: entfernen -> leeren; neue Eingabe -> verschlüsselt setzen;
     * leere Eingabe ohne Entfernen -> Feld gar nicht anfassen (bestehender Wert bleibt).
     *
     * @param array<string, mixed> $values
     */
    private function collectSecret(array &$values, string $inputName, string $storeKey): void
    {
        if (isset($_POST[$inputName . '_remove'])) {
            $values[$storeKey] = '';
            return;
        }

        $raw = isset($_POST[$inputName]) ? trim((string) wp_unslash($_POST[$inputName])) : '';
        if ($raw !== '') {
            $values[$storeKey] = Secret::encrypt(sanitize_text_field($raw));
        }
    }

    private function sanitizeCurrency(mixed $value): string
    {
        $value = strtolower(sanitize_key((string) wp_unslash($value)));

        return array_key_exists($value, Config::currencies()) ? $value : 'eur';
    }

    private function sanitizeTaxMode(mixed $value): string
    {
        $value = sanitize_key((string) wp_unslash($value));

        return in_array($value, [Order::TAX_VAT, Order::TAX_KLEINUNTERNEHMER], true) ? $value : Order::TAX_KLEINUNTERNEHMER;
    }

    private function redirect(): never
    {
        $this->redirectWith('shop_saved');
    }

    public function handleWebhookInstall(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-shop'));
        }
        check_admin_referer('rhshop_webhook_install');

        $installer = new WebhookInstaller($this->config, new StripeClient($this->config));
        $result = $installer->install();

        if ($result instanceof WP_Error) {
            $this->redirectWith('webhook_error', $result->get_error_message());
        }

        $this->redirectWith('webhook_installed');
    }

    public function handleWebhookRemove(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-shop'));
        }
        check_admin_referer('rhshop_webhook_remove');

        (new WebhookInstaller($this->config, new StripeClient($this->config)))->remove();

        $this->redirectWith('webhook_removed');
    }

    private function redirectWith(string $message, string $detail = ''): never
    {
        $args = [
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            'rhbp_message' => $message,
        ];
        if ($detail !== '') {
            $args['rhbp_detail'] = $detail;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
