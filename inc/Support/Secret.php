<?php

declare(strict_types=1);

namespace RhShop\Support;

/**
 * Verschlüsselt Geheimnisse (Stripe Secret Key, Webhook-Signing-Secret) at-rest
 * mit libsodium.
 *
 * Der Schlüssel wird aus den WordPress-Salts (`wp_salt('auth')`) abgeleitet. Die
 * Salts liegen bei einer Standard-Installation in der wp-config.php, NICHT in der
 * Datenbank. Ein reiner DB-Leak gibt den Stripe-Key damit nicht preis (man
 * bräuchte zusätzlich die wp-config.php). libsodium ist in PHP 8.1+ eingebaut.
 *
 * Am sichersten bleibt die Konstante (RH_STRIPE_SECRET) in der wp-config.php,
 * dann landet der Key gar nicht erst in der DB. Diese Verschlüsselung ist der
 * Schutz für den Fall, dass er doch über das Feld gespeichert wird.
 */
final class Secret
{
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || ! function_exists('sodium_crypto_secretbox')) {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '' || ! function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return sodium_crypto_generichash(wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
