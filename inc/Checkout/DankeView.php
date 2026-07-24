<?php

declare(strict_types=1);

namespace RhShop\Checkout;

use RhShop\Orders\Order;
use RhShop\Orders\OrderStore;
use RhShop\Stripe\Config;
use RhShop\Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Die Bestätigungsseite nach der Zahlung (`/danke?session_id=…`), status-bewusst.
 *
 * Der alte statische Text ("du bekommst gleich eine Mail") log: er versprach eine
 * Bestätigung, ohne die Zahlung zu prüfen. Hier wird der echte Stand gezeigt:
 *
 * - Bestellung lokal schon bezahlt (Webhook war da) -> Bestätigung.
 * - Noch pending -> einmal bei Stripe nachfragen (der Rücksprung ist schneller als
 *   der Webhook, das ist das Race): payment_status paid -> Bestätigung, sonst der
 *   ehrliche "wird noch verarbeitet"-Hinweis ohne Mail-Versprechen.
 * - Kein/kein passender session_id (Direktaufruf) -> neutraler Dank.
 */
final class DankeView
{
    public function __construct(
        private readonly Config $config,
        private readonly OrderStore $orders,
        private readonly StripeClient $stripe,
    ) {
    }

    public static function make(): self
    {
        $config = new Config();

        return new self($config, new OrderStore(), new StripeClient($config));
    }

    public function render(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Landing von Stripe, kein Formular; nur Anzeige.
        $sessionId = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';

        $order = ($sessionId !== '' && str_starts_with($sessionId, 'cs_'))
            ? $this->orders->findBySessionId($sessionId)
            : null;

        if ($order === null) {
            return $this->wrap(
                esc_html__('Vielen Dank', 'rh-shop'),
                '<p>' . esc_html__('Vielen Dank für deinen Einkauf.', 'rh-shop') . '</p>'
            );
        }

        $paid = $order->status === Order::STATUS_PAID
            || $order->status === Order::STATUS_SHIPPED
            || $this->isPaidAtStripe($sessionId);

        return $paid ? $this->confirmed($order) : $this->processing($order);
    }

    private function confirmed(Order $order): string
    {
        $body = '<p>' . sprintf(
            /* translators: %s: Bestellnummer */
            esc_html__('deine Zahlung ist bestätigt. Deine Bestellung %s ist bei uns eingegangen.', 'rh-shop'),
            '<strong>' . esc_html($order->orderNumber) . '</strong>'
        ) . '</p>';

        $body .= '<p>' . esc_html__('Die Bestellbestätigung schicken wir dir per E-Mail. Wir melden uns, sobald deine Bestellung unterwegs ist.', 'rh-shop') . '</p>';

        return $this->wrap(esc_html__('Vielen Dank für deine Bestellung', 'rh-shop'), $body);
    }

    private function processing(Order $order): string
    {
        $body = '<p>' . sprintf(
            /* translators: %s: Bestellnummer */
            esc_html__('deine Bestellung %s ist bei uns eingegangen. Deine Zahlung wird gerade verarbeitet.', 'rh-shop'),
            '<strong>' . esc_html($order->orderNumber) . '</strong>'
        ) . '</p>';

        $body .= '<p>' . esc_html__('Sobald die Zahlung bestätigt ist, bekommst du die Bestellbestätigung per E-Mail.', 'rh-shop') . '</p>';

        return $this->wrap(esc_html__('Danke für deine Bestellung', 'rh-shop'), $body);
    }

    /**
     * Bei noch nicht lokal bezahlter Bestellung einmal die Wahrheit bei Stripe holen
     * (Webhook-Verzögerung überbrücken). Fehlschlag/keine Konfiguration -> false, dann
     * greift der ehrliche "wird verarbeitet"-Text.
     */
    private function isPaidAtStripe(string $sessionId): bool
    {
        $client = $this->stripe->client();
        if ($client === null) {
            return false;
        }

        try {
            $session = $client->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException) {
            return false;
        }

        return ($session->payment_status ?? '') === 'paid';
    }

    private function wrap(string $title, string $body): string
    {
        return '<div class="rhshop-danke">'
            . '<h2 class="rhshop-danke__title">' . $title . '</h2>'
            . '<p>' . esc_html__('Hallo,', 'rh-shop') . '</p>'
            // $body ist bereits escaptes Markup.
            . $body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</div>';
    }
}
