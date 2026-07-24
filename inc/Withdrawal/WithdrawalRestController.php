<?php

declare(strict_types=1);

namespace RhShop\Withdrawal;

use RhShop\Cart\CartRestController;
use RhShop\Orders\OrderStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST-Endpoint hinter dem Bestätigungs-Button "Widerruf bestätigen" (§356a Abs. 3).
 *
 * Öffentlich (der Widerruf muss OHNE Login möglich sein, §356a Abs. 1), gegen CSRF
 * per REST-Nonce gesichert. Nimmt genau die drei Pflichtangaben entgegen (Name,
 * Bestellnummer, E-Mail; Grund optional), dokumentiert den Widerruf mit
 * Eingangszeitpunkt und schickt die Eingangsbestätigung. Der Widerruf wird auch dann
 * entgegengenommen, wenn die Bestellnummer bei uns nicht gefunden wird (die Erklärung
 * ist trotzdem wirksam abzugeben), die Prüfung passiert danach.
 */
final class WithdrawalRestController
{
    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(CartRestController::NAMESPACE, '/widerruf', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit'],
            'permission_callback' => [$this, 'checkNonce'],
        ]);
    }

    public function checkNonce(WP_REST_Request $request): bool
    {
        return (bool) wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest');
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        $orderNumber = sanitize_text_field((string) $request->get_param('order_number'));
        $email = sanitize_email((string) $request->get_param('email'));
        $reason = sanitize_textarea_field((string) $request->get_param('reason'));

        if ($name === '' || $orderNumber === '') {
            return new WP_Error('rhshop_widerruf_incomplete', __('Bitte Name und Bestellnummer angeben.', 'rh-shop'), ['status' => 400]);
        }
        if (! is_email($email)) {
            return new WP_Error('rhshop_email_invalid', __('Bitte eine gültige E-Mail-Adresse angeben.', 'rh-shop'), ['status' => 400]);
        }

        // Beste-Mühe-Zuordnung zur Bestellung (Nummer + passende E-Mail), keine Pflicht.
        $orderId = 0;
        $order = (new OrderStore())->findByNumber($orderNumber);
        if ($order !== null && strcasecmp($order->email, $email) === 0) {
            $orderId = $order->id;
        }

        $store = new WithdrawalStore();
        $id = $store->create([
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'customer_name' => $name,
            'email' => $email,
            'reason' => $reason,
            'ip' => $this->clientIp(),
        ]);

        if ($id === 0) {
            return new WP_Error('rhshop_widerruf_failed', __('Der Widerruf konnte nicht gespeichert werden. Bitte per E-Mail widerrufen.', 'rh-shop'), ['status' => 500]);
        }

        $withdrawal = $store->find($id);
        if ($withdrawal !== null) {
            (new WithdrawalMailer())->send($withdrawal);

            /**
             * Ein Widerruf ist eingegangen (dokumentiert, Eingangsbestätigung raus).
             *
             * @param Withdrawal $withdrawal
             */
            do_action('rh-blueprint/shop/withdrawal_received', $withdrawal);
        }

        return new WP_REST_Response([
            'confirmed' => true,
            'received_at' => $withdrawal?->receivedAt ?? '',
        ]);
    }

    /**
     * Client-IP für den Nachweis. Über Filter abschaltbar (leerer String = nicht
     * speichern), für datensparsame Setups.
     */
    private function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return (string) apply_filters('rh-blueprint/shop/withdrawal_ip', $ip);
    }
}
