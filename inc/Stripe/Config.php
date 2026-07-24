<?php

declare(strict_types=1);

namespace RhShop\Stripe;

use RhShop\Orders\Order;
use RhShop\Support\Secret;

/**
 * Zentraler Lesezugriff auf die Stripe-Konfiguration (Keys, Währung).
 *
 * Secrets kommen bevorzugt aus einer Konstante (wp-config.php), sonst aus der
 * verschlüsselten Option. Der Publishable Key ist öffentlich (steht ohnehin im
 * Frontend), er liegt im Klartext in der Option. Single Source, damit Settings-UI,
 * Checkout und Webhook dieselben Werte lesen.
 */
final class Config
{
    public const GROUP = 'shop';

    public const FIELD_PUBLISHABLE = 'publishable_key';
    public const FIELD_SECRET_ENC = 'secret_key_enc';
    public const FIELD_WEBHOOK_ENC = 'webhook_secret_enc';
    public const FIELD_CURRENCY = 'currency';
    public const FIELD_TAX_MODE = 'tax_mode';
    public const FIELD_SHIPPING = 'shipping_cents';
    public const FIELD_WEBHOOK_ENDPOINT = 'webhook_endpoint_id';
    public const FIELD_WIDERRUF_BUTTON = 'widerruf_button';

    /** Enthaltene USt bei Regelbesteuerung (Deutschland, Standardsatz). */
    public const VAT_RATE_PERCENT = 19;

    public const CONST_SECRET = 'RH_STRIPE_SECRET';
    public const CONST_WEBHOOK = 'RH_STRIPE_WEBHOOK_SECRET';

    /** @var array<string, string> Währung (lowercase, ISO-4217) => Symbol. */
    private const CURRENCIES = [
        'eur' => '€',
        'chf' => 'CHF',
        'usd' => '$',
    ];

    public function publishableKey(): string
    {
        return (string) rhbp_setting(self::GROUP, self::FIELD_PUBLISHABLE, '');
    }

    public function secretKey(): string
    {
        if (defined(self::CONST_SECRET) && constant(self::CONST_SECRET) !== '') {
            return (string) constant(self::CONST_SECRET);
        }

        return Secret::decrypt((string) rhbp_setting(self::GROUP, self::FIELD_SECRET_ENC, ''));
    }

    public function webhookSecret(): string
    {
        if (defined(self::CONST_WEBHOOK) && constant(self::CONST_WEBHOOK) !== '') {
            return (string) constant(self::CONST_WEBHOOK);
        }

        return Secret::decrypt((string) rhbp_setting(self::GROUP, self::FIELD_WEBHOOK_ENC, ''));
    }

    public function hasStoredSecret(): bool
    {
        return (defined(self::CONST_SECRET) && constant(self::CONST_SECRET) !== '')
            || (string) rhbp_setting(self::GROUP, self::FIELD_SECRET_ENC, '') !== '';
    }

    public function hasStoredWebhookSecret(): bool
    {
        return (defined(self::CONST_WEBHOOK) && constant(self::CONST_WEBHOOK) !== '')
            || (string) rhbp_setting(self::GROUP, self::FIELD_WEBHOOK_ENC, '') !== '';
    }

    public function webhookEndpointId(): string
    {
        return (string) rhbp_setting(self::GROUP, self::FIELD_WEBHOOK_ENDPOINT, '');
    }

    public function currency(): string
    {
        $currency = strtolower((string) rhbp_setting(self::GROUP, self::FIELD_CURRENCY, 'eur'));

        return isset(self::CURRENCIES[$currency]) ? $currency : 'eur';
    }

    public function currencySymbol(): string
    {
        return self::CURRENCIES[$this->currency()] ?? '€';
    }

    /**
     * @return array<string, string>
     */
    public static function currencies(): array
    {
        return self::CURRENCIES;
    }

    /**
     * Steuer-Modus: Regelbesteuerung (USt ausweisen) oder Kleinunternehmer (§19,
     * keine USt). Prägt Preisaufschlüsselung und Rechnungshinweis.
     */
    public function taxMode(): string
    {
        $mode = (string) rhbp_setting(self::GROUP, self::FIELD_TAX_MODE, Order::TAX_KLEINUNTERNEHMER);

        return in_array($mode, [Order::TAX_VAT, Order::TAX_KLEINUNTERNEHMER], true) ? $mode : Order::TAX_KLEINUNTERNEHMER;
    }

    /**
     * Pauschale Versandkosten in Cent (0 = kostenlos). Gewichts-/Zonen-Logik kommt
     * später; für den kleinen Shop reicht zunächst ein Pauschalbetrag.
     */
    public function shippingCents(): int
    {
        return max(0, (int) rhbp_setting(self::GROUP, self::FIELD_SHIPPING, 0));
    }

    /**
     * Zeigt das Plugin den sitewide "Vertrag widerrufen"-Button (§356a). Default an,
     * weil es für B2C-Shops mit Widerrufsrecht Pflicht ist.
     */
    public function widerrufButtonEnabled(): bool
    {
        return (bool) rhbp_setting(self::GROUP, self::FIELD_WIDERRUF_BUTTON, true);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== '' && $this->publishableKey() !== '';
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->secretKey(), 'sk_test_');
    }
}
