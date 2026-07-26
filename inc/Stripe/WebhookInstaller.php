<?php

declare(strict_types=1);

namespace RhShop\Stripe;

defined( 'ABSPATH' ) || exit;

use RhShop\Cart\CartRestController;
use RhShop\Support\Secret;
use Stripe\Exception\ApiErrorException;
use WP_Error;

/**
 * Richtet den Stripe-Webhook automatisch ein, damit ein Nicht-ITler nichts im
 * Stripe-Dashboard suchen oder ein Signing-Secret kopieren muss.
 *
 * Ein Klick: das Plugin legt über die Stripe-API einen Webhook-Endpoint auf die
 * eigene REST-URL an und speichert das dabei zurückgegebene Signing-Secret
 * verschlüsselt. Vorhandene Endpoints auf dieselbe URL werden vorher entfernt
 * (deren Secret kennen wir nicht, Stripe gibt es nur beim Anlegen heraus), so
 * bleibt genau ein Endpoint mit bekanntem Secret.
 *
 * Voraussetzung: die Seite muss öffentlich erreichbar sein. Lokal (DDEV) kommt
 * Stripe nicht dran, dort führt weiter die Stripe-CLI den Webhook.
 */
final class WebhookInstaller
{
    private const EVENTS = ['payment_intent.succeeded'];

    public function __construct(
        private readonly Config $config,
        private readonly StripeClient $stripe,
    ) {
    }

    public function webhookUrl(): string
    {
        return rest_url(CartRestController::NAMESPACE . '/webhook');
    }

    /**
     * Erkennt lokale/nicht öffentlich erreichbare URLs (dort kann Stripe nicht
     * zustellen). Nur zur Warnung, das Anlegen wird nicht verhindert.
     */
    public function isLocalUrl(?string $url = null): bool
    {
        $host = (string) wp_parse_url($url ?? $this->webhookUrl(), PHP_URL_HOST);

        if ($host === 'localhost' || str_starts_with($host, '127.') || str_starts_with($host, '192.168.')) {
            return true;
        }

        foreach (['.ddev.site', '.test', '.local', '.localhost'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:string, url:string, local:bool}|WP_Error
     */
    public function install(): array|WP_Error
    {
        $client = $this->stripe->client();
        if ($client === null) {
            return new WP_Error('rhshop_not_configured', __('Bitte zuerst die Stripe-Keys eintragen.', 'rh-shop'), ['status' => 400]);
        }

        $url = $this->webhookUrl();

        try {
            $existing = $client->webhookEndpoints->all(['limit' => 100]);
            foreach ($existing->data as $endpoint) {
                if (($endpoint->url ?? '') === $url) {
                    $client->webhookEndpoints->delete($endpoint->id);
                }
            }

            $endpoint = $client->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => self::EVENTS,
                'description' => 'rh-shop (WordPress)',
            ]);
        } catch (ApiErrorException $e) {
            return new WP_Error('rhshop_stripe_error', $e->getMessage(), ['status' => 502]);
        }

        rhbp_update_settings(Config::GROUP, [
            Config::FIELD_WEBHOOK_ENDPOINT => (string) $endpoint->id,
            Config::FIELD_WEBHOOK_ENC => Secret::encrypt((string) $endpoint->secret),
        ]);

        return ['id' => (string) $endpoint->id, 'url' => $url, 'local' => $this->isLocalUrl($url)];
    }

    /**
     * Entfernt den vom Plugin angelegten Endpoint wieder (bei Stripe und lokal).
     */
    public function remove(): void
    {
        $client = $this->stripe->client();
        $id = $this->config->webhookEndpointId();

        if ($client !== null && $id !== '') {
            try {
                $client->webhookEndpoints->delete($id);
            } catch (ApiErrorException $e) {
                // Endpoint evtl. schon weg, Optionen trotzdem leeren.
            }
        }

        rhbp_update_settings(Config::GROUP, [
            Config::FIELD_WEBHOOK_ENDPOINT => '',
            Config::FIELD_WEBHOOK_ENC => '',
        ]);
    }
}
