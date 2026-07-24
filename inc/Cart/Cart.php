<?php

declare(strict_types=1);

namespace RhShop\Cart;

use RhShop\Catalog\ProductType;
use RhShop\Catalog\VariantRepository;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Der Warenkorb, gehalten in einem Cookie (kein Login nötig).
 *
 * Das Cookie speichert NUR Referenzen (Produkt-ID, Varianten-ID, Menge), nie
 * Preise. Bei jedem Zugriff werden die Zeilen frisch aus dem Katalog aufgelöst und
 * der Gesamtbetrag serverseitig gerechnet. So kann ein manipuliertes Cookie weder
 * einen Preis fälschen noch eine ausverkaufte/gelöschte Variante durchdrücken:
 * ungültige Referenzen fallen beim Auflösen einfach raus.
 */
final class Cart
{
    public const COOKIE = 'rhshop_cart';
    private const MAX_QTY = 99;
    private const TTL_DAYS = 30;

    /** @var array<int, array{p:int,v:string,q:int}> */
    private array $items;

    public function __construct(private readonly VariantRepository $variants)
    {
        $this->items = $this->read();
    }

    public function add(int $productId, string $variantId, int $qty = 1): void
    {
        $qty = max(1, $qty);

        if (! $this->isPurchasable($productId, $variantId)) {
            return;
        }

        foreach ($this->items as $i => $item) {
            if ($item['p'] === $productId && $item['v'] === $variantId) {
                $this->items[$i]['q'] = $this->clampQty($productId, $variantId, $item['q'] + $qty);
                return;
            }
        }

        $this->items[] = ['p' => $productId, 'v' => $variantId, 'q' => $this->clampQty($productId, $variantId, $qty)];
    }

    public function setQty(int $productId, string $variantId, int $qty): void
    {
        if ($qty <= 0) {
            $this->remove($productId, $variantId);
            return;
        }

        foreach ($this->items as $i => $item) {
            if ($item['p'] === $productId && $item['v'] === $variantId) {
                $this->items[$i]['q'] = $this->clampQty($productId, $variantId, $qty);
                return;
            }
        }
    }

    public function remove(int $productId, string $variantId): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn (array $item): bool => ! ($item['p'] === $productId && $item['v'] === $variantId)
        ));
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * @return array<int, CartLine>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->items as $item) {
            $product = get_post($item['p']);
            if (! $product || $product->post_type !== ProductType::POST_TYPE || $product->post_status !== 'publish') {
                continue;
            }

            $variant = $this->variants->find($item['p'], $item['v']);
            if ($variant === null) {
                continue;
            }

            $thumb = get_the_post_thumbnail_url($item['p'], 'medium');

            $lines[] = new CartLine(
                productId: $item['p'],
                variantId: $item['v'],
                productTitle: get_the_title($item['p']),
                optionsLabel: $variant->optionsLabel(),
                sku: $variant->sku,
                unitPriceCents: $variant->priceCents,
                qty: $item['q'],
                permalink: (string) get_permalink($item['p']),
                thumbnailUrl: is_string($thumb) ? $thumb : '',
            );
        }

        return $lines;
    }

    public function totalCents(): int
    {
        return array_sum(array_map(static fn (CartLine $l): int => $l->lineTotalCents(), $this->lines()));
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (array $i): int => $i['q'], $this->items));
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    /**
     * Serialisierbarer Zustand fürs Frontend (REST-Antwort, Mini-Cart).
     *
     * @return array<string, mixed>
     */
    public function toState(Config $config): array
    {
        $symbol = $config->currencySymbol();
        $lines = array_map(static fn (CartLine $l): array => [
            'product_id' => $l->productId,
            'variant_id' => $l->variantId,
            'title' => $l->productTitle,
            'options' => $l->optionsLabel,
            'qty' => $l->qty,
            'unit_price' => Money::format($l->unitPriceCents, $symbol),
            'line_total' => Money::format($l->lineTotalCents(), $symbol),
            'permalink' => $l->permalink,
            'thumbnail' => $l->thumbnailUrl,
        ], $this->lines());

        return [
            'count' => $this->count(),
            'total' => Money::format($this->totalCents(), $symbol),
            'total_cents' => $this->totalCents(),
            'empty' => $lines === [],
            'lines' => $lines,
        ];
    }

    /**
     * Cookie schreiben. Muss in einem Request laufen, BEVOR Ausgabe beginnt
     * (REST-Handler erfüllen das). HttpOnly, weil nur der Server das Cookie liest.
     */
    public function persist(): void
    {
        $value = wp_json_encode(array_values($this->items));

        setcookie(self::COOKIE, (string) $value, [
            'expires' => time() + self::TTL_DAYS * DAY_IN_SECONDS,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Damit ein Folge-Zugriff im selben Request den neuen Stand sieht.
        $_COOKIE[self::COOKIE] = (string) $value;
    }

    /**
     * @return array<int, array{p:int,v:string,q:int}>
     */
    private function read(): array
    {
        if (! isset($_COOKIE[self::COOKIE])) {
            return [];
        }

        $decoded = json_decode(wp_unslash($_COOKIE[self::COOKIE]), true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $raw) {
            if (! is_array($raw) || ! isset($raw['p'], $raw['v'], $raw['q'])) {
                continue;
            }
            $p = (int) $raw['p'];
            $v = sanitize_text_field((string) $raw['v']);
            $q = max(1, min(self::MAX_QTY, (int) $raw['q']));
            if ($p > 0 && $v !== '') {
                $items[] = ['p' => $p, 'v' => $v, 'q' => $q];
            }
        }

        return $items;
    }

    private function isPurchasable(int $productId, string $variantId): bool
    {
        $product = get_post($productId);
        if (! $product || $product->post_type !== ProductType::POST_TYPE || $product->post_status !== 'publish') {
            return false;
        }

        $variant = $this->variants->find($productId, $variantId);

        return $variant !== null && $variant->isAvailable();
    }

    /**
     * Menge auf den verfügbaren Bestand deckeln (falls verfolgt) und auf MAX_QTY.
     */
    private function clampQty(int $productId, string $variantId, int $qty): int
    {
        $qty = max(1, min(self::MAX_QTY, $qty));
        $variant = $this->variants->find($productId, $variantId);

        if ($variant !== null && $variant->stock !== null) {
            $qty = min($qty, max(0, $variant->stock));
        }

        return max(1, $qty);
    }
}
