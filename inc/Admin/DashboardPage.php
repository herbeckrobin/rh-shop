<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use RhShop\Legal\Anbieter;
use RhShop\Orders\Order;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\Config;
use RhShop\Support\Money;
use RhShop\Withdrawal\WithdrawalStore;

/**
 * Shop-Übersicht: der Landing-Screen für den Betreiber. Beantwortet auf einen Blick
 * "was ist gerade los" (KPI-Kacheln) und "was muss ich tun" (Zu-erledigen-Liste), plus
 * Schnellaktionen. Read-only, alle Zahlen kommen aus den Stores.
 */
final class DashboardPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'rhshop-overview';
    private const RANGE_DAYS = 30;

    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register']);
    }

    public function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . ProductType::POST_TYPE,
            __('Shop-Übersicht', 'rh-shop'),
            __('Übersicht', 'rh-shop'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            0
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $orders = new OrderStore();
        $symbol = $this->config->currencySymbol();

        $openOrders = $orders->countByStatus(Order::STATUS_PAID);
        $revenue = $orders->revenueLastDays(self::RANGE_DAYS);
        $paidCount = $orders->paidCountLastDays(self::RANGE_DAYS);
        $productCount = $this->publishedProducts();
        $withdrawals = (new WithdrawalStore())->count();

        echo '<div class="wrap rhshop-dash">';
        echo '<h1>' . esc_html__('Shop-Übersicht', 'rh-shop') . '</h1>';

        $this->styles();
        $this->testModeBanner();

        echo '<div class="rhshop-dash__tiles">';
        $this->tile((string) $openOrders, __('warten auf Versand', 'rh-shop'), $this->ordersUrl(), $openOrders > 0);
        $this->tile(Money::format($revenue, $symbol), sprintf(/* translators: %d: Anzahl Tage */ __('Umsatz (%d Tage)', 'rh-shop'), self::RANGE_DAYS), '', false);
        $this->tile((string) $paidCount, sprintf(/* translators: %d: Anzahl Tage */ __('Bestellungen (%d Tage)', 'rh-shop'), self::RANGE_DAYS), $this->ordersUrl(), false);
        $this->tile((string) $productCount, __('Produkte', 'rh-shop'), admin_url('edit.php?post_type=' . ProductType::POST_TYPE), false);
        echo '</div>';

        echo '<div class="rhshop-dash__cols">';
        $this->todoCard($openOrders, $withdrawals);
        $this->actionsCard();
        echo '</div>';

        $this->shopPagesCard();

        echo '</div>';
    }

    /**
     * "Deine Shop-Seiten": findet die zentralen Seiten (über den enthaltenen Block bzw.
     * den Slug) und verlinkt Ansehen/Bearbeiten. So findet der Betreiber die Seiten,
     * ohne sie in der Seitenliste suchen zu müssen.
     */
    private function shopPagesCard(): void
    {
        $pages = [
            [__('Shop', 'rh-shop'), $this->findByBlock('rh-shop/product-grid')],
            [__('Warenkorb', 'rh-shop'), $this->findByBlock('rh-shop/cart-items')],
            [__('Kasse', 'rh-shop'), $this->findByBlock('rh-shop/checkout-form')],
            [__('Vielen Dank', 'rh-shop'), $this->findBySlug('danke')],
            [__('Vertrag widerrufen', 'rh-shop'), $this->findByBlock('rh-shop/widerruf')],
        ];

        echo '<div class="rhshop-dash__card rhshop-dash__card--wide">';
        echo '<h2>' . esc_html__('Deine Shop-Seiten', 'rh-shop') . '</h2>';
        echo '<ul class="rhshop-dash__pages">';

        foreach ($pages as [$label, $page]) {
            echo '<li><span class="rhshop-dash__page-name">' . esc_html($label) . '</span>';
            if ($page instanceof \WP_Post) {
                echo '<span class="rhshop-dash__page-links">'
                    . '<a href="' . esc_url((string) get_permalink($page)) . '" target="_blank" rel="noopener">' . esc_html__('ansehen', 'rh-shop') . '</a>'
                    . '<a href="' . esc_url((string) get_edit_post_link($page->ID, 'url')) . '">' . esc_html__('bearbeiten', 'rh-shop') . '</a>'
                    . '</span>';
            } else {
                echo '<span class="rhshop-dash__page-missing">' . esc_html__('nicht angelegt', 'rh-shop') . '</span>';
            }
            echo '</li>';
        }

        echo '</ul></div>';
    }

    private function findByBlock(string $block): ?\WP_Post
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
            '%' . $wpdb->esc_like('wp:' . $block) . '%'
        ));

        return $id > 0 ? get_post($id) : null;
    }

    private function findBySlug(string $slug): ?\WP_Post
    {
        $page = get_page_by_path($slug);

        return $page instanceof \WP_Post ? $page : null;
    }

    private function tile(string $number, string $label, string $url, bool $attn): void
    {
        $class = 'rhshop-dash__tile' . ($attn ? ' rhshop-dash__tile--attn' : '');
        $inner = '<span class="num">' . esc_html($number) . '</span><span class="lbl">' . esc_html($label) . '</span>';

        if ($url !== '') {
            printf('<a class="%s" href="%s">%s</a>', esc_attr($class), esc_url($url), $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner ist escapt.
        } else {
            printf('<div class="%s">%s</div>', esc_attr($class), $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner ist escapt.
        }
    }

    /**
     * "Zu erledigen": offene Aufgaben und Einrichtungs-Lücken. Zeigt nur, was wirklich
     * ansteht, sonst die Alles-erledigt-Meldung.
     */
    private function todoCard(int $openOrders, int $withdrawals): void
    {
        $items = [];

        if ($openOrders > 0) {
            $items[] = [
                sprintf(/* translators: %d: Anzahl */ _n('%d Bestellung wartet auf Versand', '%d Bestellungen warten auf Versand', $openOrders, 'rh-shop'), $openOrders),
                $this->ordersUrl(),
            ];
        }

        if ($withdrawals > 0) {
            $items[] = [
                sprintf(/* translators: %d: Anzahl */ _n('%d Widerruf eingegangen', '%d Widerrufe eingegangen', $withdrawals, 'rh-shop'), $withdrawals),
                admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=rhshop-widerrufe'),
            ];
        }

        foreach ($this->setupGaps() as $gap) {
            $items[] = $gap;
        }

        echo '<div class="rhshop-dash__card">';
        echo '<h2>' . esc_html__('Zu erledigen', 'rh-shop') . '</h2>';

        if ($items === []) {
            echo '<p class="rhshop-dash__done">' . esc_html__('Alles erledigt, gerade nichts offen.', 'rh-shop') . '</p>';
            echo '</div>';
            return;
        }

        echo '<ul class="rhshop-dash__todo">';
        foreach ($items as [$label, $url]) {
            printf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($label));
        }
        echo '</ul></div>';
    }

    /**
     * Einrichtungs-Lücken (dieselbe Logik wie die Erste-Schritte-Checkliste), damit der
     * Betreiber vom Überblick aus sieht, ob der Shop startklar ist.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function setupGaps(): array
    {
        $gaps = [];
        $settingsUrl = admin_url('admin.php?page=rh-blueprint&tab=shop');

        if (! $this->config->isConfigured()) {
            $gaps[] = [__('Stripe verbinden, damit Zahlungen laufen', 'rh-shop'), $settingsUrl];
        } elseif ($this->config->webhookEndpointId() === '' && ! $this->config->hasStoredWebhookSecret()) {
            $gaps[] = [__('Zahlungsbestätigung (Webhook) einrichten', 'rh-shop'), $settingsUrl];
        }

        if (trim((string) rhbp_setting(Config::GROUP, Anbieter::SETTING_ADDRESS, '')) === '') {
            $gaps[] = [__('Anbieter-Anschrift für das Widerrufsformular hinterlegen', 'rh-shop'), $settingsUrl];
        }

        return $gaps;
    }

    private function actionsCard(): void
    {
        $actions = [
            [__('Neues Produkt', 'rh-shop'), admin_url('post-new.php?post_type=' . ProductType::POST_TYPE), true],
            [__('Bestellungen', 'rh-shop'), $this->ordersUrl(), false],
            [__('Einstellungen', 'rh-shop'), admin_url('admin.php?page=rh-blueprint&tab=shop'), false],
            [__('Shop-Seite ansehen', 'rh-shop'), $this->shopUrl(), false],
        ];

        echo '<div class="rhshop-dash__card">';
        echo '<h2>' . esc_html__('Schnellaktionen', 'rh-shop') . '</h2>';
        echo '<div class="rhshop-dash__actions">';
        foreach ($actions as [$label, $url, $primary]) {
            printf(
                '<a class="button %s" href="%s">%s</a>',
                $primary ? 'button-primary' : '',
                esc_url($url),
                esc_html($label)
            );
        }
        echo '</div></div>';
    }

    private function testModeBanner(): void
    {
        if (! $this->config->isConfigured() || ! $this->config->isTestMode()) {
            return;
        }

        echo '<div class="notice notice-warning inline rhshop-dash__banner"><p>'
            . esc_html__('Test-Modus aktiv: es werden keine echten Zahlungen abgewickelt. Für den Live-Betrieb die Live-Schlüssel eintragen.', 'rh-shop')
            . '</p></div>';
    }

    private function publishedProducts(): int
    {
        $counts = wp_count_posts(ProductType::POST_TYPE);

        return (int) ($counts->publish ?? 0);
    }

    private function ordersUrl(): string
    {
        return admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=rhshop-orders');
    }

    private function shopUrl(): string
    {
        return (string) apply_filters('rh-blueprint/shop/shop_url', home_url('/shop'));
    }

    private function styles(): void
    {
        echo '<style>
.rhshop-dash__banner{margin:16px 0}
.rhshop-dash__tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0}
.rhshop-dash__tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;text-decoration:none;color:inherit;display:block}
.rhshop-dash__tile .num{display:block;font-size:30px;font-weight:700;line-height:1.15;color:#1d2327}
.rhshop-dash__tile .lbl{display:block;color:#646970;margin-top:4px}
a.rhshop-dash__tile:hover{border-color:#3858e9}
.rhshop-dash__tile--attn{border-color:#d63638;box-shadow:inset 3px 0 0 #d63638}
.rhshop-dash__cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:900px}
@media(max-width:782px){.rhshop-dash__cols{grid-template-columns:1fr}}
.rhshop-dash__card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px}
.rhshop-dash__card h2{margin:0 0 12px;font-size:15px}
.rhshop-dash__todo{list-style:none;margin:0;padding:0}
.rhshop-dash__todo li{padding:9px 0;border-bottom:1px solid #f0f0f1}
.rhshop-dash__todo li:last-child{border-bottom:0}
.rhshop-dash__done{color:#646970;margin:0}
.rhshop-dash__actions{display:flex;flex-wrap:wrap;gap:8px}
.rhshop-dash__card--wide{margin-top:16px;max-width:900px}
.rhshop-dash__pages{list-style:none;margin:0;padding:0}
.rhshop-dash__pages li{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:9px 0;border-bottom:1px solid #f0f0f1;flex-wrap:wrap}
.rhshop-dash__pages li:last-child{border-bottom:0}
.rhshop-dash__page-name{font-weight:600}
.rhshop-dash__page-links a{margin-left:12px}
.rhshop-dash__page-missing{color:#646970;font-size:12px}
</style>';
    }
}
