<?php

declare(strict_types=1);

namespace RhShop\Stripe;

defined( 'ABSPATH' ) || exit;

use Stripe\StripeClient as SdkClient;

/**
 * Dünne Fabrik für den offiziellen Stripe-SDK-Client.
 *
 * Lazy: der Client wird erst beim ersten Zugriff aus dem Secret Key gebaut, ein
 * unkonfigurierter Shop stößt also keinen Stripe-Aufruf an. Gibt null zurück,
 * wenn kein Key hinterlegt ist, damit die Aufrufer sauber degradieren statt zu
 * fatalen.
 */
final class StripeClient
{
    private ?SdkClient $client = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function client(): ?SdkClient
    {
        if ($this->client instanceof SdkClient) {
            return $this->client;
        }

        $secret = $this->config->secretKey();
        if ($secret === '' || ! class_exists(SdkClient::class)) {
            return null;
        }

        $this->client = new SdkClient(['api_key' => $secret]);

        return $this->client;
    }
}
