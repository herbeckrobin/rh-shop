<?php

declare(strict_types=1);

namespace RhShop\Admin;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;
use RhShop\Catalog\StockRepository;
use RhShop\Catalog\VariantRepository;
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
        add_action('wp_ajax_rhshop_dash_chart', [$this, 'ajaxChart']);
    }

    /**
     * Liefert die Zeitreihe für die Bestellungen-Card (Woche/Monat/Jahr, mit
     * Kalender-Versatz). Nur für Betreiber, per Nonce gegen CSRF.
     */
    public function ajaxChart(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('rhshop_dash', 'nonce');

        $range = sanitize_key((string) ($_GET['range'] ?? 'month'));
        if (! in_array($range, ['week', 'month', 'year'], true)) {
            $range = 'month';
        }
        $offset = (int) ($_GET['offset'] ?? 0);
        $offset = max(-120, min(0, $offset)); // nur Vergangenheit, gedeckelt

        wp_send_json_success($this->chartData($range, $offset));
    }

    /**
     * Zeitfenster + Balken je nach Bereich und Kalender-Versatz. Rechnet in der
     * WordPress-Zeitzone (gleiche Basis wie created_at), damit die Tages-/Monatsgrenzen
     * stimmen. Leere Eimer werden mit 0 aufgefüllt.
     *
     * @return array{title:string, bars:array<int, array{label:string, value:int, full:string}>}
     */
    private function chartData(string $range, int $offset): array
    {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $sep = ' bis ';

        if ($range === 'week') {
            $start = $now->modify('monday this week')->modify(($offset * 7) . ' days')->setTime(0, 0);
            $end = $start->modify('+7 days');
            $granularity = 'day';
            $step = '+1 day';
            $title = $start->format('d.m.') . $sep . $start->modify('+6 days')->format('d.m.Y');
        } elseif ($range === 'year') {
            $start = $now->modify('first day of January this year')->modify($offset . ' years')->setTime(0, 0);
            $end = $start->modify('+1 year');
            $granularity = 'month';
            $step = '+1 month';
            $title = $start->format('Y');
        } else {
            $start = $now->modify('first day of this month')->modify($offset . ' months')->setTime(0, 0);
            $end = $start->modify('+1 month');
            $granularity = 'day';
            $step = '+1 day';
            $title = $this->monthName((int) $start->format('n')) . ' ' . $start->format('Y');
        }

        $counts = (new OrderStore())->paidCountSeries(
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $granularity
        );

        $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $bars = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify($step)) {
            if ($granularity === 'month') {
                $key = $cursor->format('Y-m');
                $label = $this->monthName((int) $cursor->format('n'), true);
                $full = $this->monthName((int) $cursor->format('n')) . ' ' . $cursor->format('Y');
            } else {
                $key = $cursor->format('Y-m-d');
                $label = $range === 'week' ? $days[(int) $cursor->format('N') - 1] : $cursor->format('j');
                $full = $cursor->format('d.m.Y');
            }
            $value = $counts[$key] ?? 0;
            $bars[] = [
                'label' => $label,
                'value' => $value,
                /* translators: 1: Anzahl Bestellungen, 2: Datum */
                'full' => sprintf(_n('%1$d Bestellung, %2$s', '%1$d Bestellungen, %2$s', $value, 'rh-shop'), $value, $full),
            ];
        }

        return ['title' => $title, 'bars' => $bars];
    }

    private function monthName(int $month, bool $short = false): string
    {
        $long = [1 => __('Januar', 'rh-shop'), __('Februar', 'rh-shop'), __('März', 'rh-shop'), __('April', 'rh-shop'), __('Mai', 'rh-shop'), __('Juni', 'rh-shop'), __('Juli', 'rh-shop'), __('August', 'rh-shop'), __('September', 'rh-shop'), __('Oktober', 'rh-shop'), __('November', 'rh-shop'), __('Dezember', 'rh-shop')];
        $name = $long[$month] ?? '';

        return $short ? mb_substr($name, 0, 3) : $name;
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

        // Die vier Kern-Cards: jede zeigt die Kennzahl UND das, was dir konkret hilft
        // (was zu verschicken ist, was den Umsatz trägt, wie sich Bestellungen
        // entwickeln, was nachzubestellen ist).
        echo '<div class="rhshop-dash__cards">';
        $this->queueCard($orders, $openOrders);
        $this->revenueCard($orders, $revenue, $symbol);
        $this->ordersChartCard($orders, $paidCount);
        $this->stockCard($productCount);
        echo '</div>';

        echo '<div class="rhshop-dash__cols">';
        $this->todoCard($openOrders, $withdrawals);
        $this->actionsCard();
        echo '</div>';

        $this->shopPagesCard();
        $this->chartScript();

        echo '</div>';
    }

    /**
     * Vanilla-JS für die Bestellungen-Card: lädt die Zeitreihe per AJAX, rendert die
     * Balken (Werte nur im Hover-Tooltip), schaltet Woche/Monat/Jahr um und navigiert
     * durch den Kalender. Inline, weil es nur auf dieser einen Admin-Seite läuft.
     */
    private function chartScript(): void
    {
        ?>
<script>
( function () {
	var root = document.querySelector( '[data-rhshop-chart]' );
	if ( ! root ) { return; }
	var nonce = root.getAttribute( 'data-nonce' );
	var plot = root.querySelector( '[data-chart-plot]' );
	var titleEl = root.querySelector( '[data-chart-title]' );
	var nextBtn = root.querySelector( '[data-nav="next"]' );
	var state = { range: 'month', offset: 0 };

	function esc( s ) { var d = document.createElement( 'div' ); d.textContent = s == null ? '' : String( s ); return d.innerHTML; }

	function render( data ) {
		titleEl.textContent = data.title;
		var max = 0;
		data.bars.forEach( function ( b ) { if ( b.value > max ) { max = b.value; } } );
		if ( max === 0 ) {
			plot.innerHTML = '<p class="rhshop-chart__empty"><?php echo esc_js( __( 'Keine Bestellungen in diesem Zeitraum.', 'rh-shop' ) ); ?></p>';
			return;
		}
		var bars = data.bars.map( function ( b ) {
			var h = max > 0 ? Math.round( b.value / max * 100 ) : 0;
			return '<div class="rhshop-chart__bar" style="height:' + Math.max( h, 2 ) + '%"' + ( b.value === 0 ? ' data-empty="1"' : '' ) + '><span class="v">' + esc( b.full ) + '</span></div>';
		} ).join( '' );
		var n = data.bars.length, every = Math.ceil( n / 6 );
		var axis = data.bars.map( function ( b, i ) {
			var show = n <= 12 || i === 0 || i === n - 1 || i % every === 0;
			return '<span>' + ( show ? esc( b.label ) : '' ) + '</span>';
		} ).join( '' );
		plot.innerHTML = '<div class="rhshop-chart__bars">' + bars + '</div><div class="rhshop-chart__axis">' + axis + '</div>';
	}

	function load() {
		nextBtn.disabled = state.offset >= 0;
		plot.style.opacity = '0.5';
		var url = ajaxurl + '?action=rhshop_dash_chart&range=' + encodeURIComponent( state.range ) + '&offset=' + state.offset + '&nonce=' + encodeURIComponent( nonce );
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) { plot.style.opacity = '1'; if ( res && res.success ) { render( res.data ); } } )
			.catch( function () { plot.style.opacity = '1'; } );
	}

	root.querySelectorAll( '[data-range]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			root.querySelectorAll( '[data-range]' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
			btn.classList.add( 'is-active' );
			state.range = btn.getAttribute( 'data-range' );
			state.offset = 0;
			load();
		} );
	} );
	root.querySelector( '[data-nav="prev"]' ).addEventListener( 'click', function () { state.offset -= 1; load(); } );
	nextBtn.addEventListener( 'click', function () { if ( state.offset < 0 ) { state.offset += 1; load(); } } );

	load();
} )();
</script>
		<?php
    }

    /**
     * Card 1: offene Bestellungen (warten auf Versand) + die am längsten wartenden, damit
     * klar ist, was als Nächstes rausgeht.
     */
    private function queueCard(OrderStore $orders, int $openOrders): void
    {
        $waiting = $orders->oldestAwaitingShipment(3);

        echo '<div class="rhshop-dash__card rhshop-metric">';
        printf(
            '<a class="rhshop-metric__head%s" href="%s"><span class="num">%d</span><span class="lbl">%s</span></a>',
            $openOrders > 0 ? ' is-attn' : '',
            esc_url($this->ordersUrl()),
            $openOrders,
            esc_html__('warten auf Versand', 'rh-shop')
        );

        if ($waiting !== []) {
            echo '<ul class="rhshop-metric__list">';
            foreach ($waiting as $w) {
                printf(
                    '<li><a href="%s"><span class="t">%s</span><span class="m">%s</span></a></li>',
                    esc_url($this->orderDetailUrl($w['id'])),
                    esc_html($w['order_number']),
                    esc_html($this->waitLabel($w['paid_at']))
                );
            }
            echo '</ul>';
        } else {
            echo '<p class="rhshop-metric__empty">' . esc_html__('Nichts wartet auf Versand.', 'rh-shop') . '</p>';
        }
        echo '</div>';
    }

    /**
     * Card 2: Umsatz + horizontales Balkendiagramm "was macht den Umsatz aus" (Top-
     * Produkte nach Umsatzanteil). Der genaue Betrag erscheint beim Drüberfahren.
     */
    private function revenueCard(OrderStore $orders, int $revenue, string $symbol): void
    {
        $top = $orders->revenueByProduct(self::RANGE_DAYS, 5);
        $max = $top !== [] ? max($top) : 0;

        echo '<div class="rhshop-dash__card rhshop-metric">';
        printf(
            '<div class="rhshop-metric__head"><span class="num">%s</span><span class="lbl">%s</span></div>',
            esc_html(Money::format($revenue, $symbol)),
            esc_html(sprintf(/* translators: %d: Anzahl Tage */ __('Umsatz (%d Tage)', 'rh-shop'), self::RANGE_DAYS))
        );

        if ($top !== []) {
            echo '<ul class="rhshop-bars">';
            foreach ($top as $title => $cents) {
                $pct = $max > 0 ? (int) round($cents / $max * 100) : 0;
                printf(
                    '<li title="%s"><span class="bl">%s</span><span class="bt"><span class="bf" style="width:%d%%"></span></span></li>',
                    esc_attr($title . ': ' . Money::format($cents, $symbol)),
                    esc_html($title),
                    $pct
                );
            }
            echo '</ul>';
        } else {
            echo '<p class="rhshop-metric__empty">' . esc_html__('Noch keine Verkäufe im Zeitraum.', 'rh-shop') . '</p>';
        }
        echo '</div>';
    }

    /**
     * Card 3: bezahlte Bestellungen als minimalistische Zeitreihe, umschaltbar Woche/
     * Monat/Jahr, mit Pfeil-Navigation durch den Kalender. Ohne Hover nur die Balken,
     * die Zahlen erscheinen beim Drüberfahren. Die Navigation lädt per AJAX nach.
     */
    private function ordersChartCard(OrderStore $orders, int $paidCount): void
    {
        echo '<div class="rhshop-dash__card rhshop-metric rhshop-chart" data-rhshop-chart data-nonce="' . esc_attr(wp_create_nonce('rhshop_dash')) . '">';
        printf(
            '<div class="rhshop-metric__head"><span class="num">%d</span><span class="lbl">%s</span></div>',
            $paidCount,
            esc_html(sprintf(/* translators: %d: Anzahl Tage */ __('Bestellungen (%d Tage)', 'rh-shop'), self::RANGE_DAYS))
        );

        // Umschalter + Navigation (Vanilla-JS füllt den Chart-Bereich).
        echo '<div class="rhshop-chart__controls">';
        echo '<div class="rhshop-chart__ranges" role="tablist">';
        foreach (['week' => __('Woche', 'rh-shop'), 'month' => __('Monat', 'rh-shop'), 'year' => __('Jahr', 'rh-shop')] as $key => $label) {
            printf(
                '<button type="button" class="rhshop-chart__range%s" data-range="%s">%s</button>',
                $key === 'month' ? ' is-active' : '',
                esc_attr($key),
                esc_html($label)
            );
        }
        echo '</div>';
        echo '<div class="rhshop-chart__nav">';
        printf('<button type="button" data-nav="prev" aria-label="%s">&#8249;</button>', esc_attr__('Zurück', 'rh-shop'));
        echo '<span class="rhshop-chart__title" data-chart-title></span>';
        printf('<button type="button" data-nav="next" aria-label="%s">&#8250;</button>', esc_attr__('Weiter', 'rh-shop'));
        echo '</div>';
        echo '</div>';

        echo '<div class="rhshop-chart__plot" data-chart-plot></div>';
        echo '</div>';
    }

    /**
     * Card 4: Produktanzahl + Lager-Status (knappe und ausverkaufte Varianten), damit du
     * siehst, was nachzubestellen ist. Die knappsten stehen direkt drunter.
     */
    private function stockCard(int $productCount): void
    {
        $stock = new StockRepository();
        $threshold = $this->config->lowStockThreshold();
        $counts = $stock->lowStockCounts($threshold);
        $lowest = $stock->lowestStock($threshold, 3);

        echo '<div class="rhshop-dash__card rhshop-metric">';
        printf(
            '<a class="rhshop-metric__head%s" href="%s"><span class="num">%d</span><span class="lbl">%s</span></a>',
            ($counts['out'] > 0 || $counts['low'] > 0) ? ' is-attn' : '',
            esc_url(admin_url('edit.php?post_type=' . ProductType::POST_TYPE)),
            $productCount,
            esc_html__('Produkte', 'rh-shop')
        );

        if ($lowest !== []) {
            $repo = new VariantRepository();
            echo '<ul class="rhshop-metric__list">';
            foreach ($lowest as $v) {
                $variant = $repo->find($v['product_id'], $v['variant_id']);
                $name = get_the_title($v['product_id']);
                if ($variant !== null && $variant->optionsLabel() !== '') {
                    $name .= ' (' . $variant->optionsLabel() . ')';
                }
                $label = $v['stock'] === 0
                    ? __('ausverkauft', 'rh-shop')
                    : sprintf(/* translators: %d: Restbestand */ __('nur noch %d', 'rh-shop'), $v['stock']);
                printf(
                    '<li><a href="%s"><span class="t">%s</span><span class="m%s">%s</span></a></li>',
                    esc_url(get_edit_post_link($v['product_id']) ?? ''),
                    esc_html($name),
                    $v['stock'] === 0 ? ' is-out' : ' is-low',
                    esc_html($label)
                );
            }
            echo '</ul>';
        } else {
            echo '<p class="rhshop-metric__empty">' . esc_html__('Alle Bestände sind in Ordnung.', 'rh-shop') . '</p>';
        }
        echo '</div>';
    }

    private function orderDetailUrl(int $id): string
    {
        return admin_url('edit.php?post_type=' . ProductType::POST_TYPE . '&page=rhshop-orders&order=' . $id);
    }

    /**
     * "seit X Tagen" / "seit heute" aus dem paid_at-Zeitpunkt (WP-lokal).
     */
    private function waitLabel(string $paidAt): string
    {
        $days = $this->daysSince($paidAt);
        if ($days <= 0) {
            return __('seit heute', 'rh-shop');
        }

        /* translators: %d: Anzahl Tage */
        return sprintf(_n('seit %d Tag', 'seit %d Tagen', $days, 'rh-shop'), $days);
    }

    private function daysSince(string $mysqlDate): int
    {
        $then = strtotime($mysqlDate);
        if ($then === false) {
            return 0;
        }

        return (int) max(0, floor(((int) current_time('timestamp') - $then) / DAY_IN_SECONDS));
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
.rhshop-dash__cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:20px 0}
@media(max-width:1200px){.rhshop-dash__cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:782px){.rhshop-dash__cards{grid-template-columns:1fr}}
.rhshop-chart__empty{color:#8c8f94;font-size:12px;text-align:center;padding:38px 0}
.rhshop-metric{display:flex;flex-direction:column}
.rhshop-metric__head{display:block;text-decoration:none;color:inherit;padding-bottom:12px;border-bottom:1px solid #f0f0f1}
.rhshop-metric__head .num{display:block;font-size:30px;font-weight:700;line-height:1.15;color:#1d2327}
.rhshop-metric__head .lbl{display:block;color:#646970;margin-top:4px}
a.rhshop-metric__head:hover .num{color:#3858e9}
.rhshop-metric__head.is-attn{position:relative}
.rhshop-metric__head.is-attn .num{color:#d63638}
.rhshop-metric__list{list-style:none;margin:12px 0 0;padding:0}
.rhshop-metric__list li{border-bottom:1px solid #f6f7f7}
.rhshop-metric__list li:last-child{border-bottom:0}
.rhshop-metric__list a{display:flex;justify-content:space-between;gap:10px;padding:7px 0;text-decoration:none;color:#1d2327}
.rhshop-metric__list a:hover .t{color:#3858e9}
.rhshop-metric__list .t{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rhshop-metric__list .m{color:#646970;white-space:nowrap;font-size:12px}
.rhshop-metric__list .m.is-out{color:#d63638;font-weight:600}
.rhshop-metric__list .m.is-low{color:#9a6700;font-weight:600}
.rhshop-metric__empty{color:#646970;margin:12px 0 0}
.rhshop-bars{list-style:none;margin:14px 0 0;padding:0;display:flex;flex-direction:column;gap:9px}
.rhshop-bars li{display:grid;grid-template-columns:1fr;gap:3px}
.rhshop-bars .bl{font-size:12px;color:#3c434a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rhshop-bars .bt{display:block;height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden}
.rhshop-bars .bf{display:block;height:100%;background:#3858e9;border-radius:4px;min-width:2px}
.rhshop-chart__controls{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap}
.rhshop-chart__ranges{display:inline-flex;border:1px solid #dcdcde;border-radius:6px;overflow:hidden}
.rhshop-chart__range{background:#fff;border:0;border-right:1px solid #dcdcde;padding:4px 10px;font-size:12px;cursor:pointer;color:#3c434a}
.rhshop-chart__range:last-child{border-right:0}
.rhshop-chart__range.is-active{background:#3858e9;color:#fff}
.rhshop-chart__nav{display:inline-flex;align-items:center;gap:6px}
.rhshop-chart__nav button{background:#fff;border:1px solid #dcdcde;border-radius:6px;width:26px;height:26px;font-size:15px;line-height:1;cursor:pointer;color:#3c434a}
.rhshop-chart__nav button:hover{border-color:#3858e9;color:#3858e9}
.rhshop-chart__title{font-size:12px;color:#646970;min-width:96px;text-align:center}
.rhshop-chart__plot{margin-top:12px;min-height:110px}
.rhshop-chart__bars{display:flex;align-items:flex-end;gap:3px;height:110px}
.rhshop-chart__bar{flex:1;background:#e6ebfb;border-radius:3px 3px 0 0;min-height:2px;position:relative;transition:background .12s}
.rhshop-chart__bar:hover{background:#3858e9}
.rhshop-chart__bar[data-empty="1"]{background:#f0f0f1}
.rhshop-chart__bar .v{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:4px;background:#1d2327;color:#fff;font-size:11px;padding:2px 6px;border-radius:4px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .1s}
.rhshop-chart__bar:hover .v{opacity:1}
.rhshop-chart__axis{display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:#8c8f94}
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
