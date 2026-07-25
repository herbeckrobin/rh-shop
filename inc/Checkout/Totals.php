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
        public readonly int $taxRatePercent,
        public readonly int $freeShippingThresholdCents,
    ) {
    }

    /**
     * Der komplette Zusammenbau aus reinen Werten: Versand aus Warenwert + Pauschale +
     * Gratis-Schwelle, Gesamt = Warenwert + Versand, enthaltene USt aus dem Gesamt.
     * Ohne WordPress, damit die ganze Montage testbar ist und nur eine Stelle rechnet.
     */
    public static function fromValues(
        int $subtotalCents,
        int $flatShippingCents,
        int $freeThresholdCents,
        string $taxMode,
        int $taxRatePercent,
    ): self {
        $shipping = self::shippingFor($subtotalCents, $flatShippingCents, $freeThresholdCents);
        $total = $subtotalCents + $shipping;
        $tax = self::includedTax($total, $taxMode, $taxRatePercent);

        return new self($subtotalCents, $shipping, $tax, $total, $taxMode, $taxRatePercent, $freeThresholdCents);
    }

    /**
     * WordPress-Adapter: zieht die Werte aus Warenkorb und Konfiguration und übergibt sie
     * an fromValues. Die eigentliche Rechnung bleibt WP-frei.
     */
    public static function forCart(Cart $cart, Config $config): self
    {
        return self::fromValues(
            $cart->totalCents(),
            $config->shippingCents(),
            $config->freeShippingThresholdCents(),
            $config->taxMode(),
            $config->taxRatePercent(),
        );
    }

    /**
     * Versandkosten für einen Warenkorb-Wert. Leerer Warenkorb kostet nichts. Ist eine
     * Gratis-Schwelle gesetzt (> 0) und der Warenwert erreicht sie, entfällt der Versand,
     * sonst gilt die Pauschale. Reine Rechnung ohne WordPress, damit testbar und als eine
     * Quelle nutzbar. Schwelle wird gegen den Warenwert (Zwischensumme) geprüft, nicht
     * gegen den Gesamtpreis.
     */
    public static function shippingFor(int $subtotalCents, int $flatCents, int $freeThresholdCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        if ($freeThresholdCents > 0 && $subtotalCents >= $freeThresholdCents) {
            return 0;
        }

        return $flatCents;
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
     * Wie viel Warenwert noch bis zum Gratisversand fehlt (0 = Schwelle aus oder
     * bereits erreicht). Für den "noch X bis Gratisversand"-Hinweis.
     */
    public function freeShippingRemainingCents(): int
    {
        if ($this->freeShippingThresholdCents <= 0) {
            return 0;
        }

        return max(0, $this->freeShippingThresholdCents - $this->subtotalCents);
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
            'vat_rate' => $this->taxRatePercent,
            'free_shipping_remaining_cents' => $this->freeShippingRemainingCents(),
        ];
    }
}
