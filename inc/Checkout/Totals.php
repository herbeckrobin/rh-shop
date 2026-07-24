<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Cart\Cart;
use RhShop\Orders\Order;
use RhShop\Stripe\Config;
use RhShop\Support\Money;

/**
 * Die Preisaufschlüsselung eines Warenkorbs. EINE Quelle, die sowohl die
 * §312j-Bestellübersicht als auch der Stripe-Session-Service konsumieren, damit
 * angezeigter und berechneter Betrag nie auseinanderlaufen.
 *
 * Deutsche B2C-Logik: die Produktpreise sind BRUTTO (Endpreise, PAngV). Bei
 * Regelbesteuerung wird die enthaltene USt herausgerechnet (nicht aufgeschlagen),
 * bei Kleinunternehmer (§19) fällt keine USt an.
 */
final class Totals
{
    private function __construct(
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly int $taxCents,
        public readonly int $totalCents,
        public readonly string $taxMode,
    ) {
    }

    public static function forCart(Cart $cart, Config $config): self
    {
        $subtotal = $cart->totalCents();
        $shipping = $subtotal > 0 ? $config->shippingCents() : 0;
        $total = $subtotal + $shipping;
        $mode = $config->taxMode();

        // Enthaltene USt aus dem Bruttobetrag herausrechnen (nur zur Anzeige/Rechnung),
        // NICHT aufschlagen. tax = brutto - netto, netto = brutto / (1 + satz).
        $tax = 0;
        if ($mode === Order::TAX_VAT && $total > 0) {
            $divisor = 1 + (Config::VAT_RATE_PERCENT / 100);
            $net = (int) round($total / $divisor);
            $tax = $total - $net;
        }

        return new self($subtotal, $shipping, $tax, $total, $mode);
    }

    public function isKleinunternehmer(): bool
    {
        return $this->taxMode === Order::TAX_KLEINUNTERNEHMER;
    }

    public function netCents(): int
    {
        return $this->totalCents - $this->taxCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toState(string $symbol): array
    {
        return [
            'subtotal' => Money::format($this->subtotalCents, $symbol),
            'shipping' => Money::format($this->shippingCents, $symbol),
            'shipping_cents' => $this->shippingCents,
            'net' => Money::format($this->netCents(), $symbol),
            'tax' => Money::format($this->taxCents, $symbol),
            'tax_cents' => $this->taxCents,
            'total' => Money::format($this->totalCents, $symbol),
            'total_cents' => $this->totalCents,
            'tax_mode' => $this->taxMode,
            'kleinunternehmer' => $this->isKleinunternehmer(),
            'vat_rate' => Config::VAT_RATE_PERCENT,
        ];
    }
}
