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
        $tax = self::includedTax($total, $mode, Config::VAT_RATE_PERCENT);

        return new self($subtotal, $shipping, $tax, $total, $mode);
    }

    /**
     * Enthaltene Umsatzsteuer aus einem Brutto-Betrag herausrechnen (deutsche B2C-Logik,
     * PAngV: Preise sind Endpreise). NICHT aufschlagen: tax = brutto minus netto,
     * netto = brutto / (1 + satz). Bei Kleinunternehmer (§19) oder Satz 0 fällt keine
     * USt an. Reine Rechnung ohne WordPress, damit testbar und als eine Quelle nutzbar.
     */
    public static function includedTax(int $grossCents, string $taxMode, int $ratePercent): int
    {
        if ($taxMode !== Order::TAX_VAT || $grossCents <= 0 || $ratePercent <= 0) {
            return 0;
        }

        $net = (int) round($grossCents / (1 + ($ratePercent / 100)));

        return $grossCents - $net;
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
