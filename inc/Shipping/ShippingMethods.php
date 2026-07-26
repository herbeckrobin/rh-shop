<?php

declare(strict_types=1);

namespace RhShop\Shipping;

defined( 'ABSPATH' ) || exit;

use RhShop\Stripe\Config;

/**
 * Zugriff auf die konfigurierten Versandmethoden. Eine Quelle: der Checkout zeigt
 * die aktiven zur Auswahl, der Server bepreist die gewählte immer selbst hieraus
 * (nie den Client-Preis vertrauen).
 *
 * Rückwärtskompatibel: ist keine Methode gepflegt, liefert die Klasse eine einzelne
 * Default-Methode aus der alten Versandpauschale. Bestehende Shops verhalten sich
 * dann exakt wie bisher, ohne dass etwas eingerichtet werden muss.
 */
final class ShippingMethods
{
    /** Feld in der Shop-Settings-Option, JSON-kodierte Liste. */
    public const FIELD = 'shipping_methods';

    /** Stabile Id der Fallback-Methode (keine Methoden gepflegt). */
    public const FALLBACK_ID = 'standard';

    public function __construct(private readonly Config $config)
    {
    }

    public static function make(): self
    {
        return new self(new Config());
    }

    /**
     * Alle gepflegten Methoden (auch deaktivierte), in Speicherreihenfolge. Leer,
     * wenn nichts gepflegt ist.
     *
     * @return array<int, ShippingMethod>
     */
    public function all(): array
    {
        $raw = (string) rhbp_setting(Config::GROUP, self::FIELD, '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $methods = [];
        foreach ($decoded as $row) {
            if (is_array($row) && ($row['id'] ?? '') !== '') {
                $methods[] = ShippingMethod::fromArray($row);
            }
        }

        return $methods;
    }

    /**
     * Nur aktive Methoden.
     *
     * @return array<int, ShippingMethod>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (ShippingMethod $m): bool => $m->enabled));
    }

    /** Gibt es überhaupt gepflegte, aktive Methoden. */
    public function isConfigured(): bool
    {
        return $this->active() !== [];
    }

    /**
     * Die im Checkout wählbaren Methoden: die aktiven, oder als Fallback eine einzelne
     * Default-Methode aus der alten Pauschale.
     *
     * @return array<int, ShippingMethod>
     */
    public function availableForCheckout(): array
    {
        $active = $this->active();

        return $active !== [] ? $active : [$this->fallbackMethod()];
    }

    public function find(string $id): ?ShippingMethod
    {
        foreach ($this->all() as $method) {
            if ($method->id === $id) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Die für die Bestellung gültige Methode zu einer (client-gelieferten) Id. Nur
     * aktive/verfügbare Methoden zählen; ist die Id unbekannt oder leer, greift die
     * erste verfügbare. So kann der Client die Auswahl treffen, aber weder Preis noch
     * Methode fälschen: der Server nimmt immer das hinterlegte Objekt.
     */
    public function resolveForCheckout(string $id): ShippingMethod
    {
        $available = $this->availableForCheckout();

        foreach ($available as $method) {
            if ($method->id === $id) {
                return $method;
            }
        }

        return $available[0];
    }

    /**
     * Liste speichern (JSON in der Shop-Option). Erwartet ShippingMethod-Objekte.
     *
     * @param array<int, ShippingMethod> $methods
     */
    public function save(array $methods): void
    {
        $rows = array_map(static fn (ShippingMethod $m): array => $m->toArray(), array_values($methods));
        rhbp_update_setting(Config::GROUP, self::FIELD, (string) wp_json_encode($rows));
    }

    /**
     * Default-Methode aus der bisherigen Pauschale + Gratis-Schwelle. Trägt den
     * Rückwärtskompat-Fall: ohne gepflegte Methoden verhält sich der Versand wie vor
     * dem Ausbau. Kein Carrier (Abholung/unbestimmt), bis der Betreiber Methoden anlegt.
     */
    private function fallbackMethod(): ShippingMethod
    {
        $free = $this->config->freeShippingThresholdCents();

        return new ShippingMethod(
            id: self::FALLBACK_ID,
            label: __('Versand', 'rh-shop'),
            carrier: Carrier::NONE,
            priceCents: $this->config->shippingCents(),
            freeFromCents: $free > 0 ? $free : null,
            deliveryTime: '',
            enabled: true,
        );
    }
}
