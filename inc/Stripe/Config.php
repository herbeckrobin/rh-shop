<?php

declare(strict_types=1);

namespace RhShop\Stripe;

defined( 'ABSPATH' ) || exit;

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
    public const FIELD_TAX_RATE = 'tax_rate';
    public const FIELD_SHIPPING = 'shipping_cents';
    public const FIELD_FREE_SHIPPING = 'free_shipping_cents';
    public const FIELD_WEBHOOK_ENDPOINT = 'webhook_endpoint_id';
    public const FIELD_WIDERRUF_BUTTON = 'widerruf_button';
    public const FIELD_INVOICE = 'invoice_enabled';
    public const FIELD_AGB_ENABLED = 'agb_enabled';
    public const FIELD_MAIL_FROM_NAME = 'mail_from_name';
    public const FIELD_MAIL_FROM_ADDRESS = 'mail_from_address';
    public const FIELD_MAIL_NOTIFY = 'mail_notify';
    public const FIELD_MAIL_NOTE = 'mail_note';
    public const FIELD_LOW_STOCK = 'low_stock_threshold';
    public const FIELD_HOLD_MINUTES = 'reservation_hold_minutes';

    /** Default-USt-Satz (Deutschland, Standardsatz). Der echte Satz ist konfigurierbar. */
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
     * USt-Satz in Prozent bei Regelbesteuerung. Konfigurierbar (Deutschland 19 oder
     * ermäßigt 7, andere Märkte anders), auf 0 bis 100 begrenzt.
     */
    public function taxRatePercent(): int
    {
        $rate = (int) rhbp_setting(self::GROUP, self::FIELD_TAX_RATE, self::VAT_RATE_PERCENT);

        return max(0, min(100, $rate));
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
     * Warenwert-Schwelle in Cent, ab der der Versand gratis ist (0 = kein Gratisversand).
     * Geprüft gegen die Zwischensumme, siehe Totals::shippingFor.
     */
    public function freeShippingThresholdCents(): int
    {
        return max(0, (int) rhbp_setting(self::GROUP, self::FIELD_FREE_SHIPPING, 0));
    }

    /**
     * Ab welchem Restbestand der Hinweis "Nur noch X verfügbar" erscheint (0 = aus,
     * dann wird nur Ausverkauft angezeigt). Greift nur bei Varianten mit verfolgtem
     * Bestand; unbegrenzte Varianten zeigen nie einen Bestandshinweis.
     */
    public function lowStockThreshold(): int
    {
        return max(0, min(999, (int) rhbp_setting(self::GROUP, self::FIELD_LOW_STOCK, 5)));
    }

    /**
     * Wie lange (Minuten) der Bestand beim Auslösen der Bestellung reserviert wird,
     * bevor er ohne Zahlung wieder frei wird. Deckt das Zeitfenster bis zur
     * bestätigten Stripe-Zahlung ab.
     */
    public function reservationHoldMinutes(): int
    {
        return max(1, min(1440, (int) rhbp_setting(self::GROUP, self::FIELD_HOLD_MINUTES, 30)));
    }

    /**
     * Absender-Name der Shop-Mails (From). Leer = WordPress-Default bzw. rh-smtp.
     */
    public function mailFromName(): string
    {
        return trim((string) rhbp_setting(self::GROUP, self::FIELD_MAIL_FROM_NAME, ''));
    }

    /**
     * Absender-Adresse der Shop-Mails (From). Nur gültige E-Mail, sonst leer
     * (dann greift der WordPress-/rh-smtp-Default).
     */
    public function mailFromAddress(): string
    {
        $value = trim((string) rhbp_setting(self::GROUP, self::FIELD_MAIL_FROM_ADDRESS, ''));

        return is_email($value) ? $value : '';
    }

    /**
     * Adresse, an die die Benachrichtigung über neue Bestellungen geht. Leer =
     * Fallback auf die WordPress-Administrator-Adresse.
     */
    public function notifyAddress(): string
    {
        $value = trim((string) rhbp_setting(self::GROUP, self::FIELD_MAIL_NOTIFY, ''));

        return is_email($value) ? $value : (string) get_option('admin_email');
    }

    /**
     * Optionaler eigener Text in der Kundenbestätigung (z.B. Kontakt bei Fragen).
     */
    public function mailNote(): string
    {
        return trim((string) rhbp_setting(self::GROUP, self::FIELD_MAIL_NOTE, ''));
    }

    /**
     * Zeigt das Plugin den sitewide "Vertrag widerrufen"-Button (§356a). Default an,
     * weil es für B2C-Shops mit Widerrufsrecht Pflicht ist.
     */
    public function widerrufButtonEnabled(): bool
    {
        return (bool) rhbp_setting(self::GROUP, self::FIELD_WIDERRUF_BUTTON, true);
    }

    /**
     * Erstellt das Plugin nach der Zahlung eine Stripe-Rechnung (Stripe Invoicing,
     * kostenpflichtiges Add-on). Default an.
     */
    public function invoiceEnabled(): bool
    {
        return (bool) rhbp_setting(self::GROUP, self::FIELD_INVOICE, true);
    }

    /**
     * Verlangt der Checkout eine AGB-Zustimmung. AGB sind für einen Shop rechtlich
     * NICHT Pflicht (ohne AGB gilt das Gesetz), darum Default aus: die AGB-Checkbox
     * plus Link erscheinen nur, wenn der Betreiber sie einschaltet (und dann eigene
     * AGB hat). Widerruf + Datenschutz bleiben davon unberührt Pflicht.
     */
    public function agbEnabled(): bool
    {
        return (bool) rhbp_setting(self::GROUP, self::FIELD_AGB_ENABLED, false);
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
