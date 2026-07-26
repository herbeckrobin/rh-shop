<?php

declare(strict_types=1);

namespace RhShop\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Eine Versandmethode, wie der Betreiber sie anbietet und der Kunde sie im Checkout
 * wählt. Immutable Value-Object. Trägt die Amazon-Kombi (wohin/womit/Preis) flach in
 * sich: ein Label ("DHL nach Hause"), ein Carrier, ein Preis. Optional eine eigene
 * Gratis-ab-Schwelle und eine Lieferzeit-Angabe.
 *
 * Persistiert als JSON in der Shop-Option (eine kleine, überschaubare Liste, kein
 * Query-Bedarf, darum keine eigene Tabelle).
 */
final class ShippingMethod
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $carrier,
        public readonly int $priceCents,
        public readonly ?int $freeFromCents,
        public readonly string $deliveryTime,
        public readonly bool $enabled,
    ) {
    }

    /**
     * Versandkosten dieser Methode für einen Warenwert (eigene Gratis-ab-Schwelle
     * berücksichtigt). Delegiert an die eine Versand-Rechenquelle in Totals, damit die
     * Gratis-Logik nicht doppelt existiert.
     */
    public function shippingFor(int $subtotalCents): int
    {
        return \RhShop\Checkout\Totals::shippingFor($subtotalCents, max(0, $this->priceCents), $this->freeFromCents ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $free = $row['free_from_cents'] ?? null;

        return new self(
            id: (string) ($row['id'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            carrier: Carrier::sanitize((string) ($row['carrier'] ?? Carrier::NONE)),
            priceCents: max(0, (int) ($row['price_cents'] ?? 0)),
            freeFromCents: ($free === null || $free === '') ? null : max(0, (int) $free),
            deliveryTime: (string) ($row['delivery_time'] ?? ''),
            enabled: (bool) ($row['enabled'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'carrier' => $this->carrier,
            'price_cents' => $this->priceCents,
            'free_from_cents' => $this->freeFromCents,
            'delivery_time' => $this->deliveryTime,
            'enabled' => $this->enabled,
        ];
    }
}
