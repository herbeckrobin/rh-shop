<?php

/**
 * Dev-Seed: legt Testmerch (fiktive Marke "Nordlicht") an. Idempotent über den
 * Slug. Nur zum lokalen Testen, nicht Teil des Plugins. Nach dem Lauf löschbar.
 *
 * Aufruf: ddev wp eval-file rh-shop/bin/seed-testmerch.php
 */

use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;

if (! defined('ABSPATH')) {
    exit;
}

/** Kategorie sicherstellen, gibt term_id zurück. */
$ensureCat = static function (string $name) : int {
    $existing = term_exists($name, ProductType::TAXONOMY);
    if (is_array($existing)) {
        return (int) $existing['term_id'];
    }
    $created = wp_insert_term($name, ProductType::TAXONOMY);
    return is_array($created) ? (int) $created['term_id'] : 0;
};

/** Variantenzeile bauen. */
$v = static function (string $size, string $color, string $sku, int $priceCents, ?int $stock) : array {
    return [
        'id' => bin2hex(random_bytes(4)),
        'option1' => $size,
        'option2' => $color,
        'sku' => $sku,
        'price_cents' => $priceCents,
        'stock' => $stock,
    ];
};

$repo = new VariantRepository();

$catBekleidung = $ensureCat('Bekleidung');
$catAccessoires = $ensureCat('Accessoires');

// --- T-Shirt mit 8 Varianten (Größe x Farbe), eine ausverkauft ---
$tshirtVariants = [];
foreach (['S', 'M', 'L', 'XL'] as $size) {
    foreach (['Schwarz', 'Natur'] as $color) {
        $price = $size === 'XL' ? 2690 : 2490;
        $stock = ($size === 'L' && $color === 'Natur') ? 0 : 12; // eine ausverkauft
        $sku = 'NL-TS-' . $size . '-' . strtoupper(substr($color, 0, 2));
        $tshirtVariants[] = $v($size, $color, $sku, $price, $stock);
    }
}

// --- Hoodie, 4 Größen, eine Farbe ---
$hoodieVariants = [];
foreach (['S', 'M', 'L', 'XL'] as $size) {
    $hoodieVariants[] = $v($size, 'Anthrazit', 'NL-HD-' . $size, 5490, 5);
}

// --- Cap, nur Farb-Achse (keine Größe) ---
$capVariants = [
    $v('', 'Schwarz', 'NL-CAP-SW', 2200, 20),
    $v('', 'Oliv', 'NL-CAP-OL', 2200, 8),
];

$products = [
    [
        'slug' => 'nordlicht-classic-tshirt',
        'title' => 'Nordlicht Classic T-Shirt',
        'excerpt' => 'Schweres Bio-Baumwoll-Shirt mit dezentem Nordlicht-Print. Fällt klassisch, nicht tailliert.',
        'content' => 'Unser Klassiker aus 220g Bio-Baumwolle. Angenehm schwer, hält Form und Farbe auch nach vielen Wäschen. Der Print sitzt klein auf der Brust, ohne aufdringlich zu sein.',
        'cat' => $catBekleidung,
        'variants' => $tshirtVariants,
    ],
    [
        'slug' => 'nordlicht-heavy-hoodie',
        'title' => 'Nordlicht Heavy Hoodie',
        'excerpt' => 'Dicker Hoodie mit angerauter Innenseite, für kalte Tage am Wasser.',
        'content' => 'Schwerer Hoodie (350g) mit weicher, angerauter Innenseite und doppellagiger Kapuze. Kängurutasche, verstärkte Nähte, ein Teil fürs Leben.',
        'cat' => $catBekleidung,
        'variants' => $hoodieVariants,
    ],
    [
        'slug' => 'nordlicht-6-panel-cap',
        'title' => 'Nordlicht 6-Panel Cap',
        'excerpt' => 'Unstrukturierte 6-Panel-Cap mit gesticktem Logo, Einheitsgröße.',
        'content' => 'Klassische unstrukturierte Cap, verstellbarer Metallverschluss, gesticktes Nordlicht-Logo. Passt jedem.',
        'cat' => $catBekleidung,
        'variants' => $capVariants,
    ],
    [
        'slug' => 'nordlicht-sticker-set',
        'title' => 'Nordlicht Sticker-Set',
        'excerpt' => 'Fünf wetterfeste Vinyl-Sticker im Set.',
        'content' => 'Fünf verschiedene Motive, wetterfest und UV-beständig. Halten auf Laptop, Flasche und Rahmen.',
        'cat' => $catAccessoires,
        'simple' => ['price' => 450, 'stock' => 200],
    ],
    [
        'slug' => 'nordlicht-emaille-tasse',
        'title' => 'Nordlicht Emaille-Tasse',
        'excerpt' => 'Robuste Emaille-Tasse, 300ml, lagerfeuertauglich.',
        'content' => 'Klassische Emaille-Tasse mit schwarzem Rand, 300ml. Verträgt Lagerfeuer und Spülmaschine.',
        'cat' => $catAccessoires,
        'simple' => ['price' => 1490, 'stock' => 30],
    ],
];

$created = 0;
$skipped = 0;

foreach ($products as $p) {
    $existing = get_page_by_path($p['slug'], OBJECT, ProductType::POST_TYPE);
    if ($existing instanceof WP_Post) {
        $skipped++;
        continue;
    }

    $postId = wp_insert_post([
        'post_type' => ProductType::POST_TYPE,
        'post_status' => 'publish',
        'post_name' => $p['slug'],
        'post_title' => $p['title'],
        'post_excerpt' => $p['excerpt'],
        'post_content' => '<!-- wp:paragraph --><p>' . esc_html($p['content']) . '</p><!-- /wp:paragraph -->',
    ], true);

    if (is_wp_error($postId)) {
        echo 'Fehler bei ' . $p['slug'] . ': ' . $postId->get_error_message() . "\n";
        continue;
    }

    wp_set_object_terms($postId, [$p['cat']], ProductType::TAXONOMY);

    if (isset($p['variants'])) {
        update_post_meta($postId, VariantRepository::META_VARIANTS, $p['variants']);
    } elseif (isset($p['simple'])) {
        $repo->saveSimple($postId, $p['simple']['price'], $p['simple']['stock']);
    }

    $created++;
    echo 'angelegt: ' . $p['title'] . ' (ID ' . $postId . ")\n";
}

echo "---\nangelegt: {$created}, übersprungen (schon da): {$skipped}\n";
