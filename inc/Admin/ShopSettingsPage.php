<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhBlueprint\Core\Admin\Ui;
use RhBlueprint\Core\Admin\Assets;
use RhBlueprint\Core\Admin\Guard;
use RhBlueprint\Core\Admin\MailPanel;
defined( 'ABSPATH' ) || exit;

use RhBlueprint\Core\Settings\SettingsPage;
use RhShop\Catalog\ProductType;
use RhShop\Mail\MailRegistry;
use RhShop\Mail\MailType;
use RhShop\Orders\Order;
use RhShop\Shipping\Carrier;
use RhShop\Shipping\ShippingMethod;
use RhShop\Shipping\ShippingMethods;
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
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('rh-blueprint/dependencies', [$this, 'dependencies']);
    }

    /**
     * Ohne Mail-Anbindung verschickt der Shop keine Bestellbestätigung.
     *
     * Das faellt niemandem auf: es bricht nichts, es kommt nur nie eine Mail
     * an, und gemerkt wird es beim ersten Kunden, der nachfragt. Deshalb als
     * Voraussetzung gemeldet und nicht als Empfehlung.
     *
     * @param array<int, array<string, mixed>> $deps
     * @return array<int, array<string, mixed>>
     */
    public function dependencies(array $deps): array
    {
        $deps[] = [
            'module' => 'rh-shop',
            'needs' => 'rh-smtp',
            'for' => __('Bestellbestätigung, Rechnung und Versandmeldung', 'rh-shop'),
            'tab' => self::TAB_ID,
            'required' => true,
        ];

        return $deps;
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

    /**
     * Lädt CSS/JS für die Sub-Tabs, nur auf der Blueprint-Settings-Seite. Nutzt die
     * Core-Settings-Assets als Abhängigkeit (gleiches Muster wie rh-seo).
     */
    public function enqueueAssets(string $hook): void
    {
        if (! Assets::onSettings(self::TAB_ID)) {
            return;
        }

        wp_enqueue_style('rh-shop-settings-tabs', RHSHOP_PLUGIN_URL . 'assets/admin/settings-tabs.css', ['rh-blueprint-settings'], RHSHOP_VERSION);
        wp_enqueue_script('rh-shop-settings-tabs', RHSHOP_PLUGIN_URL . 'assets/admin/settings-tabs.js', [], RHSHOP_VERSION, true);
        // Medien-Picker fürs Mail-Logo im E-Mail-Tab.
        wp_enqueue_media();
    }

    public function render(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        echo '<div class="rhshop-settings-tabs">';

        echo '<p class="rhbp-field__desc" style="margin-top:0">'
            . esc_html__('Alle Shop-Einstellungen an einem Ort. Was gerade los ist (Bestellungen, Umsatz), siehst du unter ', 'rh-shop')
            . '<a href="' . esc_url($this->overviewUrl()) . '">' . esc_html__('Shop → Übersicht', 'rh-shop') . '</a>.</p>';

        // Erster Tab = Status/Einrichtung (Startklar-Check + Webhook), das ist der
        // Setup-Kram OHNE Speichern. Danach die Config-Tabs, die nur Felder + Speichern
        // enthalten. So klebt kein Webhook mehr unter dem Speichern-Knopf.
        echo Ui::subtabs([
            'status' => __('Status', 'rh-shop'),
            'zahlung' => __('Zahlung', 'rh-shop'),
            'preise' => __('Preise & Steuer', 'rh-shop'),
            'versand' => __('Versand', 'rh-shop'),
            'email' => __('E-Mail', 'rh-shop'),
            'rechtlich' => __('Rechtliches', 'rh-shop'),
        ], 'status');

        // Status-Tab: Startklar-Check + Webhook. Ausserhalb des Formulars (der Webhook
        // hat eigene Formulare, und hier gibt es nichts zu speichern).
        echo Ui::paneOpen('status', true);
        (new GoLiveCheck($this->config))->render(self::TAB_ID);
        $this->renderWebhookCard();
        echo '</div>';

        // Config-Formular: nur speicherbare Felder. Ein Speichern sichert alle Tabs
        // (versteckte Felder werden mit abgeschickt).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="rhshop_settings_save" />';

        $this->pane('zahlung', [$this, 'renderPaymentSection'], false);
        $this->pane('preise', [$this, 'renderPricingSection'], false);
        $this->pane('versand', [$this, 'renderShippingSection'], false);
        $this->pane('email', [$this, 'renderMailSection'], false);
        $this->pane('rechtlich', [$this, 'renderLegalSection'], false);

        // Speichern auf dem Status-Tab ausgeblendet (dort gibt es nichts zu speichern).
        // Initial versteckt, weil Status der Default-Tab ist.
        echo '<p class="rhshop-hidden" data-rhshop-hide="status" style="max-width:640px"><button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Speichern', 'rh-shop') . '</button></p>';
        echo '</form>';

        // Die Mail-Einstellungen des Core, mit eigenem Formular und eigenem
        // Speichern. Derselbe Reiter-Schlüssel wie oben: das Core-Skript
        // schaltet beide Bereiche zusammen.
        echo Ui::paneOpen('email', false);
        (new MailPanel())->render(self::TAB_ID);
        echo '</div>';

        echo '</div>';
    }

    /**
     * @param callable(): void $render
     */
    private function pane(string $key, callable $render, bool $active): void
    {
        echo Ui::paneOpen($key, $active);
        $render();
        echo '</div>';
    }

    /**
     * Zeile von Cross-Links (Verweise zu Stripe, Produkten, Rechtstext-Seiten).
     *
     * @param array<int, array{0: string, 1: string, 2: bool}> $links [Label, URL, extern]
     */
    private function xlinks(array $links): void
    {
        echo '<div class="rhshop-xlinks">';
        foreach ($links as [$label, $url, $external]) {
            printf(
                '<a href="%s"%s>%s%s</a>',
                esc_url($url),
                $external ? ' target="_blank" rel="noopener"' : '',
                esc_html($label),
                $external ? ' ↗' : ''
            );
        }
        echo '</div>';
    }

    private function overviewUrl(): string
    {
        return admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=rhshop-overview');
    }

    /**
     * Bearbeiten- bzw. Anlegen-Link für eine Rechtstext-Seite: existiert sie, führt der
     * Link zum Bearbeiten, sonst zum Anlegen (Titel vorbelegt).
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private function legalPageLink(string $slug, string $title): array
    {
        $page = get_page_by_path($slug);
        if ($page instanceof \WP_Post) {
            return [
                /* translators: %s: Seitentitel */
                sprintf(__('%s bearbeiten', 'rh-shop'), $title),
                (string) get_edit_post_link($page->ID, 'url'),
                false,
            ];
        }

        return [
            /* translators: %s: Seitentitel */
            sprintf(__('%s anlegen', 'rh-shop'), $title),
            admin_url('post-new.php?post_type=page&post_title=' . rawurlencode($title)),
            false,
        ];
    }

    private function sectionOpen(string $title, string $intro): void
    {
        echo '<div class="rhbp-card" style="max-width:640px;margin-top:1rem">';
        echo '<h3 style="margin-top:0">' . esc_html($title) . '</h3>';
        if ($intro !== '') {
            echo '<p class="rhbp-field__desc" style="margin:-0.2rem 0 1rem">' . esc_html($intro) . '</p>';
        }
    }

    private function sectionClose(): void
    {
        echo '</div>';
    }

    /**
     * Abschnitt 1: Zahlung. Ohne Stripe geht nichts, darum zuerst.
     */
    private function renderPaymentSection(): void
    {
        $this->sectionOpen(
            __('Zahlung', 'rh-shop'),
            __('Verbinde deinen Stripe-Account. Die Schlüssel findest du im Stripe-Dashboard unter „Entwickler".', 'rh-shop')
        );

        $this->xlinks([
            [__('Stripe-Dashboard öffnen', 'rh-shop'), 'https://dashboard.stripe.com/apikeys', true],
        ]);

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
            __('Signatur-Geheimnis aus dem Stripe-Webhook. Verifiziert, dass Zahlungs-Events wirklich von Stripe kommen. Auf einer Live-Seite füllt das der Webhook-Knopf im Status-Tab automatisch.', 'rh-shop'),
            $this->config->hasStoredWebhookSecret(),
            defined(Config::CONST_WEBHOOK) && constant(Config::CONST_WEBHOOK) !== ''
        );

        $this->sectionClose();
    }

    /**
     * Abschnitt 2: Preise & Steuer.
     */
    private function renderPricingSection(): void
    {
        $this->sectionOpen(
            __('Preise & Steuer', 'rh-shop'),
            __('Währung und wie die Steuer berechnet wird. Produktpreise pflegst du am Produkt selbst.', 'rh-shop')
        );

        $this->xlinks([
            [__('Produkte verwalten', 'rh-shop'), admin_url('edit.php?post_type=' . ProductType::POST_TYPE), false],
        ]);

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
            Order::TAX_VAT => __('Regelbesteuerung (USt im Preis enthalten)', 'rh-shop'),
        ];
        foreach ($modes as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($mode, $value, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Kleinunternehmer weist keine USt aus (§19-Hinweis auf der Kasse). Regelbesteuerung rechnet die enthaltene USt aus dem Bruttopreis heraus.', 'rh-shop') . '</p>';
        echo '</div>';

        // USt-Satz (nur bei Regelbesteuerung relevant)
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-tax-rate">' . esc_html__('USt-Satz (%)', 'rh-shop') . '</label>';
        printf(
            '<input type="number" id="rhshop-tax-rate" name="tax_rate" value="%d" min="0" max="100" step="1" style="max-width:100px">',
            $this->config->taxRatePercent()
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Prozentsatz für die Regelbesteuerung. Deutschland 19, ermäßigt 7. Bei Kleinunternehmer ohne Wirkung.', 'rh-shop') . '</p>';
        echo '</div>';

        // Lager-Warnschwelle
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-low-stock">' . esc_html__('Lager-Warnung ab', 'rh-shop') . '</label>';
        printf(
            '<input type="number" id="rhshop-low-stock" name="low_stock_threshold" value="%d" min="0" max="999" step="1" style="max-width:100px">',
            $this->config->lowStockThreshold()
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Ab diesem Restbestand zeigt das Produkt "Nur noch X verfügbar". 0 = aus (nur Ausverkauft). Gilt nur für Varianten mit verfolgtem Bestand.', 'rh-shop') . '</p>';
        echo '</div>';

        // Reservierungs-Haltedauer
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-hold-minutes">' . esc_html__('Bestand reservieren (Minuten)', 'rh-shop') . '</label>';
        printf(
            '<input type="number" id="rhshop-hold-minutes" name="reservation_hold_minutes" value="%d" min="1" max="1440" step="1" style="max-width:100px">',
            $this->config->reservationHoldMinutes()
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Wie lange der Bestand beim Bestellen reserviert wird, damit zwei Kunden nicht denselben letzten Artikel kaufen. Bleibt die Zahlung aus, wird er danach wieder frei. Standard 30.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->sectionClose();
    }

    /**
     * Abschnitt 3: Versand. Oben die Versandarten, die der Kunde im Checkout wählt
     * (Amazon-Prinzip: eine flache Liste, jede Zeile trägt Anbieter + Preis). Ohne
     * angelegte Methode gilt weiterhin die einfache Pauschale darunter (Rückwärtskompat).
     */
    private function renderShippingSection(): void
    {
        $this->sectionOpen(
            __('Versand', 'rh-shop'),
            __('Die Versandarten, die der Kunde im Checkout wählt: Bezeichnung, Anbieter und Preis. Ist keine Methode angelegt, gilt die einfache Pauschale ganz unten.', 'rh-shop')
        );

        $methods = (new ShippingMethods($this->config))->all();

        echo '<div class="rhshop-methods" data-rhshop-methods>';
        echo '<table class="rhshop-methods__table"><thead><tr>';
        foreach ([
            __('Aktiv', 'rh-shop'),
            __('Bezeichnung', 'rh-shop'),
            __('Anbieter', 'rh-shop'),
            __('Preis (€)', 'rh-shop'),
            __('Gratis ab (€)', 'rh-shop'),
            __('Lieferzeit', 'rh-shop'),
            '',
        ] as $head) {
            printf('<th>%s</th>', esc_html($head));
        }
        echo '</tr></thead><tbody data-rhshop-method-rows>';
        foreach ($methods as $method) {
            $this->renderMethodRow($method->id, $method);
        }
        echo '</tbody></table>';
        printf(
            '<p><button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhshop-add-method>%s</button></p>',
            esc_html__('+ Versandart hinzufügen', 'rh-shop')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Beispiele: „Abholung im Laden" (Anbieter: Abholung, Preis 0), „DHL nach Hause", „Hermes nach Hause". Der Anbieter bestimmt den Sendungsverfolgungs-Link, den der Kunde nach dem Versand bekommt.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->renderMethodTemplateAndScript();

        // Fallback-Pauschale: greift nur, solange keine Methode angelegt ist.
        echo '<hr style="margin:1.4rem 0">';
        echo '<h4 style="margin:0 0 0.2rem">' . esc_html__('Einfache Pauschale (Fallback)', 'rh-shop') . '</h4>';
        echo '<p class="rhbp-field__desc" style="margin:0 0 1rem">' . esc_html__('Gilt nur, wenn oben keine Versandart angelegt ist. Sobald du Methoden pflegst, wählt der Kunde daraus.', 'rh-shop') . '</p>';

        $shippingCents = $this->config->shippingCents();
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-shipping">' . esc_html__('Versandpauschale', 'rh-shop') . '</label>';
        printf(
            '<input type="text" id="rhshop-shipping" name="shipping_cents" value="%s" placeholder="0,00" class="regular-text" style="max-width:140px" /> €',
            esc_attr($shippingCents > 0 ? number_format($shippingCents / 100, 2, ',', '') : '')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Pauschale Versandkosten pro Bestellung. Leer oder 0 = kostenloser Versand.', 'rh-shop') . '</p>';
        echo '</div>';

        $freeShippingCents = $this->config->freeShippingThresholdCents();
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-free-shipping">' . esc_html__('Gratisversand ab', 'rh-shop') . '</label>';
        printf(
            '<input type="text" id="rhshop-free-shipping" name="free_shipping_cents" value="%s" placeholder="0,00" class="regular-text" style="max-width:140px" /> €',
            esc_attr($freeShippingCents > 0 ? number_format($freeShippingCents / 100, 2, ',', '') : '')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Ab diesem Warenwert (Zwischensumme) entfällt die Versandpauschale. Leer oder 0 = aus.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->sectionClose();
    }

    /**
     * Eine Versandmethoden-Zeile. Alle Felder unter dem gemeinsamen Zeilen-Schlüssel
     * `shipping_method[<key>][...]` (verschachteltes Array), damit die Checkbox „aktiv"
     * eindeutig zur Zeile gehört, ohne fragilen laufenden Index. Der Schlüssel selbst ist
     * nur die POST-Gruppierung; die stabile Id steht im versteckten id-Feld.
     */
    private function renderMethodRow(string $key, ?ShippingMethod $method): void
    {
        $price = $method !== null && $method->priceCents > 0 ? number_format($method->priceCents / 100, 2, ',', '') : '';
        $free = $method !== null && $method->freeFromCents !== null && $method->freeFromCents > 0
            ? number_format($method->freeFromCents / 100, 2, ',', '') : '';
        $name = 'shipping_method[' . $key . ']';

        echo '<tr class="rhshop-methods__row">';
        printf(
            '<td class="rhshop-methods__c-active"><input type="checkbox" name="%s[enabled]" value="1" %s></td>',
            esc_attr($name),
            checked($method === null ? true : $method->enabled, true, false)
        );
        printf(
            '<td><input type="hidden" name="%1$s[id]" value="%2$s"><input type="text" name="%1$s[label]" value="%3$s" placeholder="%4$s"></td>',
            esc_attr($name),
            esc_attr($method?->id ?? ''),
            esc_attr($method?->label ?? ''),
            esc_attr__('z.B. DHL nach Hause', 'rh-shop')
        );
        echo '<td><select name="' . esc_attr($name) . '[carrier]">';
        $current = $method?->carrier ?? Carrier::NONE;
        foreach (Carrier::options() as $id => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($current, $id, false), esc_html($label));
        }
        echo '</select></td>';
        printf('<td><input type="text" name="%s[price]" value="%s" placeholder="4,90" style="max-width:80px"></td>', esc_attr($name), esc_attr($price));
        printf('<td><input type="text" name="%s[free]" value="%s" placeholder="aus" style="max-width:80px"></td>', esc_attr($name), esc_attr($free));
        printf('<td><input type="text" name="%s[time]" value="%s" placeholder="%s"></td>', esc_attr($name), esc_attr($method?->deliveryTime ?? ''), esc_attr__('2-3 Werktage', 'rh-shop'));
        printf('<td><button type="button" class="button-link-delete" data-rhshop-remove-method>%s</button></td>', esc_html__('Entfernen', 'rh-shop'));
        echo '</tr>';
    }

    /**
     * Leere Vorlagen-Zeile (per JS geklont, __KEY__ wird durch einen frischen Schlüssel
     * ersetzt) + die Add/Remove-Mechanik und etwas Layout. Buildless, Vanilla-JS,
     * Event-Delegation.
     */
    private function renderMethodTemplateAndScript(): void
    {
        ob_start();
        $this->renderMethodRow('__KEY__', null);
        $rowHtml = (string) ob_get_clean();
        ?>
        <template data-rhshop-method-template><?php echo $rowHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- eigenes Markup, oben escapt. ?></template>
        <script>
        ( function () {
            var box = document.querySelector( '[data-rhshop-methods]' );
            if ( ! box ) { return; }
            var rows = box.querySelector( '[data-rhshop-method-rows]' );
            var tpl = document.querySelector( '[data-rhshop-method-template]' );
            if ( ! rows || ! tpl ) { return; }
            var seq = 0;
            box.addEventListener( 'click', function ( e ) {
                var add = e.target.closest( '[data-rhshop-add-method]' );
                if ( add ) {
                    var html = tpl.innerHTML.replace( /__KEY__/g, 'new_' + ( seq++ ) + '_' + Date.now() );
                    var tmp = document.createElement( 'tbody' );
                    tmp.innerHTML = html;
                    if ( tmp.firstElementChild ) { rows.appendChild( tmp.firstElementChild ); }
                    return;
                }
                var rm = e.target.closest( '[data-rhshop-remove-method]' );
                if ( rm ) { var tr = rm.closest( 'tr' ); if ( tr ) { tr.remove(); } }
            } );
        } )();
        </script>
        <style>
        .rhshop-methods__table { width: 100%; border-collapse: collapse; margin: 0.6rem 0; }
        .rhshop-methods__table th { text-align: left; font-size: 12px; color: #646970; font-weight: 600; padding: 4px 8px 4px 0; }
        .rhshop-methods__table td { padding: 4px 8px 4px 0; vertical-align: middle; }
        .rhshop-methods__table input[type="text"] { width: 100%; }
        .rhshop-methods__c-active { text-align: center; }
        .rhshop-methods__row td:last-child { white-space: nowrap; }
        </style>
        <?php
    }

    /**
     * Abschnitt: E-Mail. Wie Kunde und Betreiber über eine Bestellung informiert werden.
     */
    private function renderMailSection(): void
    {
        $this->sectionOpen(
            __('E-Mail', 'rh-shop'),
            __('Absender der Bestell-Mails und wohin die Benachrichtigung über neue Bestellungen geht.', 'rh-shop')
        );

        // Absender-Name
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-from-name">' . esc_html__('Absender-Name', 'rh-shop') . '</label>';
        printf(
            '<input type="text" id="rhshop-from-name" name="mail_from_name" value="%s" placeholder="%s" class="regular-text" />',
            esc_attr($this->config->mailFromName()),
            esc_attr__('z.B. Michelberger Shop', 'rh-shop')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Der Name, der beim Kunden als Absender steht. Leer = WordPress-Standard.', 'rh-shop') . '</p>';
        echo '</div>';

        // Absender-Adresse
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-from-address">' . esc_html__('Absender-Adresse', 'rh-shop') . '</label>';
        printf(
            '<input type="email" id="rhshop-from-address" name="mail_from_address" value="%s" placeholder="shop@deine-domain.de" class="regular-text" />',
            esc_attr($this->config->mailFromAddress())
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Von dieser Adresse gehen die Mails raus. Am besten eine Adresse deiner eigenen Domain. Leer = WordPress-Standard.', 'rh-shop') . '</p>';
        echo '</div>';

        // Benachrichtigungs-Adresse für neue Bestellungen
        $notify = trim((string) rhbp_setting(Config::GROUP, Config::FIELD_MAIL_NOTIFY, ''));
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-notify">' . esc_html__('Bestell-Benachrichtigung an', 'rh-shop') . '</label>';
        printf(
            '<input type="email" id="rhshop-notify" name="mail_notify" value="%s" placeholder="%s" class="regular-text" />',
            esc_attr($notify),
            esc_attr(sprintf(/* translators: %s: Admin-E-Mail */ __('leer = %s', 'rh-shop'), (string) get_option('admin_email')))
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Hierhin geht die Info über jede neue Bestellung. Leer = die Administrator-Adresse dieser Website.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->sectionClose();

        // Erscheinungsbild (gilt für alle Mails).
        $this->sectionOpen(
            __('Erscheinungsbild der Mails', 'rh-shop'),
            __('Logo, Farbe und Fusstext, die in jeder Shop-Mail erscheinen.', 'rh-shop')
        );

        // Logo (Medien-Picker). Leer = automatisch das Website-Logo.
        $logoId = (int) rhbp_setting(Config::GROUP, Config::FIELD_MAIL_LAYOUT_LOGO, 0);
        $logoUrl = $logoId > 0 ? (string) wp_get_attachment_image_url($logoId, 'medium') : '';
        echo '<div class="rhbp-field" data-rhshop-logo>';
        echo '<label class="rhbp-field__label">' . esc_html__('Logo', 'rh-shop') . '</label>';
        printf('<input type="hidden" name="mail_layout_logo" value="%d" data-rhshop-logo-id>', $logoId);
        printf(
            '<img src="%s" alt="" style="max-height:48px;display:%s;margin-bottom:8px" data-rhshop-logo-preview>',
            esc_url($logoUrl),
            $logoUrl !== '' ? 'block' : 'none'
        );
        echo '<p><button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhshop-logo-pick>' . esc_html__('Logo wählen', 'rh-shop') . '</button> ';
        printf(
            '<button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhshop-logo-clear style="display:%s">%s</button></p>',
            $logoId > 0 ? 'inline-block' : 'none',
            esc_html__('Entfernen', 'rh-shop')
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Erscheint im Mail-Kopf. Leer = automatisch das Website-Logo, falls eins gesetzt ist.', 'rh-shop') . '</p>';
        echo '</div>';

        // Akzentfarbe
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-mail-accent">' . esc_html__('Akzentfarbe', 'rh-shop') . '</label>';
        printf(
            '<input type="color" id="rhshop-mail-accent" name="mail_layout_accent" value="%s">',
            esc_attr($this->config->mailLayoutAccent())
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Hintergrund des Mail-Kopfs.', 'rh-shop') . '</p>';
        echo '</div>';

        // Fusstext
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-mail-footer">' . esc_html__('Fusstext', 'rh-shop') . '</label>';
        printf(
            '<textarea id="rhshop-mail-footer" name="mail_layout_footer" rows="3" class="regular-text" style="max-width:420px" placeholder="%s">%s</textarea>',
            esc_attr__('leer = deine Anbieter-Anschrift', 'rh-shop'),
            esc_textarea(trim((string) rhbp_setting(Config::GROUP, Config::FIELD_MAIL_LAYOUT_FOOTER, '')))
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Steht unten in jeder Mail. Leer = die Anbieter-Anschrift aus dem Rechtliches-Tab.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->sectionClose();

        // Die einzelnen Mails stehen nicht mehr hier: sie kommen aus dem Core
        // und damit aus derselben Oberfläche wie die Mails aller anderen
        // Module. Der Block sitzt ausserhalb dieses Formulars (siehe
        // renderMailPanel), weil er sein eigenes mitbringt und Formulare sich
        // nicht verschachteln lassen.
        $this->mailMediaScript();
    }


    /**
     * Medien-Picker fürs Mail-Logo (wp.media). Einmal ausgegeben, steuert das Hidden-Feld
     * + Vorschau in der Logo-Zeile.
     */
    private function mailMediaScript(): void
    {
        ?>
        <style>
        .rhshop-mailrow { border: 1px solid #dcdcde; border-radius: 8px; padding: 12px 16px; margin: 0 0 10px; max-width: 640px; }
        .rhshop-mailrow__head { display: flex; align-items: center; gap: 12px; }
        .rhshop-mailrow__title { flex: 1; display: flex; flex-direction: column; }
        .rhshop-mailrow__desc { color: #646970; font-size: 12px; margin-top: 2px; }
        .rhshop-mailrow__edit { margin-top: 10px; }
        .rhshop-mailrow__edit > summary { cursor: pointer; color: #3858e9; font-size: 13px; }
        .rhshop-mailrow__edit[open] > summary { margin-bottom: 10px; }
        </style>
        <script>
        ( function () {
            var wrap = document.querySelector( '[data-rhshop-logo]' );
            if ( ! wrap || ! window.wp || ! window.wp.media ) { return; }
            var idField = wrap.querySelector( '[data-rhshop-logo-id]' );
            var preview = wrap.querySelector( '[data-rhshop-logo-preview]' );
            var clearBtn = wrap.querySelector( '[data-rhshop-logo-clear]' );
            var frame;
            wrap.querySelector( '[data-rhshop-logo-pick]' ).addEventListener( 'click', function () {
                if ( ! frame ) {
                    frame = window.wp.media( { title: '<?php echo esc_js( __( 'Logo wählen', 'rh-shop' ) ); ?>', multiple: false, library: { type: 'image' } } );
                    frame.on( 'select', function () {
                        var att = frame.state().get( 'selection' ).first().toJSON();
                        idField.value = att.id;
                        var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                        preview.src = url; preview.style.display = 'block';
                        clearBtn.style.display = 'inline-block';
                    } );
                }
                frame.open();
            } );
            clearBtn.addEventListener( 'click', function () {
                idField.value = '0'; preview.src = ''; preview.style.display = 'none'; clearBtn.style.display = 'none';
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Abschnitt 4: Rechtliches (Pflichtangaben, Widerruf, Beleg).
     */
    private function renderLegalSection(): void
    {
        $this->sectionOpen(
            __('Rechtliches', 'rh-shop'),
            __('Pflichtangaben für einen B2C-Shop. Im Zweifel eingeschaltet lassen.', 'rh-shop')
        );

        // Direkter Weg zu den Rechtstext-Seiten (anlegen, falls noch nicht vorhanden).
        $this->xlinks([
            $this->legalPageLink('impressum', __('Impressum', 'rh-shop')),
            $this->legalPageLink('datenschutz', __('Datenschutz', 'rh-shop')),
            $this->legalPageLink('widerrufsbelehrung', __('Widerrufsbelehrung', 'rh-shop')),
            $this->legalPageLink('agb', __('AGB', 'rh-shop')),
        ]);

        // AGB-Zustimmung im Checkout (optional, AGB sind nicht Pflicht)
        echo '<div class="rhbp-field">';
        echo '<label><input type="checkbox" name="agb_enabled" value="1" ' . checked($this->config->agbEnabled(), true, false) . ' /> '
            . esc_html__('AGB-Zustimmung im Checkout verlangen', 'rh-shop') . '</label>';
        echo '<p class="rhbp-field__desc">' . esc_html__('Nur einschalten, wenn du eine AGB-Seite hast. AGB sind rechtlich nicht Pflicht. Ist der Schalter aus, verlangt die Kasse nur Widerrufsbelehrung und Datenschutz.', 'rh-shop') . '</p>';
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

        // Anbieter-Anschrift (für das Muster-Widerrufsformular)
        echo '<div class="rhbp-field">';
        echo '<label class="rhbp-field__label" for="rhshop-anbieter">' . esc_html__('Anbieter-Anschrift', 'rh-shop') . '</label>';
        printf(
            '<textarea id="rhshop-anbieter" name="anbieter_adresse" rows="3" class="regular-text" style="max-width:420px">%s</textarea>',
            esc_textarea((string) rhbp_setting(Config::GROUP, \RhShop\Legal\Anbieter::SETTING_ADDRESS, ''))
        );
        echo '<p class="rhbp-field__desc">' . esc_html__('Vollständige Anschrift (Straße, PLZ, Ort). Wird ins Muster-Widerrufsformular eingesetzt. Name und E-Mail kommen aus den WordPress-Stammdaten. Pflegst du Stammdaten schon in rh-seo, kann eine Suite-Integration das automatisch liefern.', 'rh-shop') . '</p>';
        echo '</div>';

        $this->sectionClose();
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

    public function handleSave(): void
    {
        Guard::form(self::NONCE, self::CAPABILITY);

        $values = [
            Config::FIELD_PUBLISHABLE => isset($_POST['publishable_key']) ? sanitize_text_field(wp_unslash($_POST['publishable_key'])) : '',
            Config::FIELD_CURRENCY => $this->sanitizeCurrency($_POST['currency'] ?? 'eur'),
            Config::FIELD_TAX_MODE => $this->sanitizeTaxMode($_POST['tax_mode'] ?? ''),
            Config::FIELD_TAX_RATE => max(0, min(100, (int) ($_POST['tax_rate'] ?? Config::VAT_RATE_PERCENT))),
            Config::FIELD_SHIPPING => Money::toCents(isset($_POST['shipping_cents']) ? sanitize_text_field(wp_unslash($_POST['shipping_cents'])) : ''),
            Config::FIELD_FREE_SHIPPING => Money::toCents(isset($_POST['free_shipping_cents']) ? sanitize_text_field(wp_unslash($_POST['free_shipping_cents'])) : ''),
            ShippingMethods::FIELD => $this->collectShippingMethods(),
            Config::FIELD_LOW_STOCK => max(0, min(999, (int) ($_POST['low_stock_threshold'] ?? 5))),
            Config::FIELD_HOLD_MINUTES => max(1, min(1440, (int) ($_POST['reservation_hold_minutes'] ?? 30))),
            Config::FIELD_WIDERRUF_BUTTON => isset($_POST['widerruf_button']),
            Config::FIELD_INVOICE => isset($_POST['invoice_enabled']),
            Config::FIELD_AGB_ENABLED => isset($_POST['agb_enabled']),
            Config::FIELD_MAIL_FROM_NAME => isset($_POST['mail_from_name']) ? sanitize_text_field(wp_unslash($_POST['mail_from_name'])) : '',
            Config::FIELD_MAIL_FROM_ADDRESS => isset($_POST['mail_from_address']) ? sanitize_email(wp_unslash($_POST['mail_from_address'])) : '',
            Config::FIELD_MAIL_NOTIFY => isset($_POST['mail_notify']) ? sanitize_email(wp_unslash($_POST['mail_notify'])) : '',
            Config::FIELD_MAIL_NOTE => isset($_POST['mail_note']) ? sanitize_textarea_field(wp_unslash($_POST['mail_note'])) : '',
            \RhShop\Legal\Anbieter::SETTING_ADDRESS => isset($_POST['anbieter_adresse'])
                ? sanitize_textarea_field(wp_unslash($_POST['anbieter_adresse']))
                : '',
        ];

        $this->collectSecret($values, 'secret_key', Config::FIELD_SECRET_ENC);
        $this->collectSecret($values, 'webhook_secret', Config::FIELD_WEBHOOK_ENC);

        // Mail-Layout
        $values[Config::FIELD_MAIL_LAYOUT_LOGO] = isset($_POST['mail_layout_logo']) ? absint(wp_unslash($_POST['mail_layout_logo'])) : 0;
        $values[Config::FIELD_MAIL_LAYOUT_ACCENT] = sanitize_hex_color((string) wp_unslash($_POST['mail_layout_accent'] ?? '')) ?? '';
        $values[Config::FIELD_MAIL_LAYOUT_FOOTER] = isset($_POST['mail_layout_footer']) ? sanitize_textarea_field(wp_unslash($_POST['mail_layout_footer'])) : '';

        // An/Aus, Betreff und Zusatztext je Mail speichert der Core. Die alten
        // Werte bleiben unangetastet in der Option stehen: sie sind die
        // Rückfalltür, falls an der Übernahme etwas nicht gestimmt hat. Hier
        // dürfen sie nicht mit leeren Feldern überschrieben werden, denn die
        // Eingaben gibt es in diesem Formular nicht mehr.

        rhbp_update_settings(Config::GROUP, $values);

        $this->redirect();
    }

    /**
     * Die Versandmethoden-Zeilen aus dem POST zu einer JSON-Liste bauen. Leere Zeilen
     * (ohne Bezeichnung) werden übersprungen, neue Zeilen bekommen eine stabile Id.
     * Alle Werte werden sanitisiert, Preis/Gratis-ab über Money in Cent.
     */
    private function collectShippingMethods(): string
    {
        if (! isset($_POST['shipping_method']) || ! is_array($_POST['shipping_method'])) {
            return '';
        }

        $rows = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in handleSave geprüft.
        foreach (wp_unslash($_POST['shipping_method']) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $label = sanitize_text_field((string) ($raw['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = sanitize_text_field((string) ($raw['id'] ?? ''));
            if ($id === '') {
                $id = 'm_' . wp_generate_password(10, false, false);
            }

            $free = trim((string) ($raw['free'] ?? ''));

            $method = new ShippingMethod(
                id: $id,
                label: $label,
                carrier: Carrier::sanitize((string) ($raw['carrier'] ?? Carrier::NONE)),
                priceCents: Money::toCents((string) ($raw['price'] ?? '')),
                freeFromCents: $free === '' ? null : Money::toCents($free),
                deliveryTime: sanitize_text_field((string) ($raw['time'] ?? '')),
                enabled: isset($raw['enabled']),
            );
            $rows[] = $method->toArray();
        }

        return (string) wp_json_encode($rows);
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
        Guard::form('rhshop_webhook_install', self::CAPABILITY);

        $installer = new WebhookInstaller($this->config, new StripeClient($this->config));
        $result = $installer->install();

        if ($result instanceof WP_Error) {
            $this->redirectWith('webhook_error', $result->get_error_message());
        }

        $this->redirectWith('webhook_installed');
    }

    public function handleWebhookRemove(): void
    {
        Guard::form('rhshop_webhook_remove', self::CAPABILITY);

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
