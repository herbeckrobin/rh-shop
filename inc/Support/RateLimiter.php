<?php

declare(strict_types=1);

namespace RhShop\Support;

/**
 * Einfaches IP-basiertes Rate-Limit über Transients. Schützt die öffentlichen,
 * zustandsändernden bzw. kostenverursachenden Endpoints (Bestellung auslösen =
 * Reservierung + Stripe-Call, Widerruf = Mailversand) gegen Automatisierung.
 *
 * Bewusst grob (fixed window pro IP): kein Ersatz für eine WAF, aber es stoppt den
 * trivialen Bot-Loop, der sonst Bestand blockiert oder Mails flutet. Bei persistentem
 * Object-Cache schnell, sonst über die Options-Tabelle.
 */
final class RateLimiter
{
    /**
     * Zählt einen Treffer im Bucket und gibt true zurück, wenn das Limit für die IP im
     * Zeitfenster überschritten ist (dann sollte der Aufrufer mit 429 abbrechen).
     */
    public static function tooMany(string $bucket, int $max, int $windowSeconds): bool
    {
        $key = 'rhshop_rl_' . $bucket . '_' . md5(self::clientIp());
        $count = (int) get_transient($key);

        if ($count >= $max) {
            return true;
        }

        // Erster Treffer setzt das Fenster; Folge-Treffer erhöhen nur, ohne die TTL zu
        // verlängern (fixed window), damit das Limit nicht durch Dauerbeschuss ewig hält.
        set_transient($key, $count + 1, $count === 0 ? $windowSeconds : (int) max(1, self::remaining($key, $windowSeconds)));

        return false;
    }

    private static function remaining(string $key, int $fallback): int
    {
        // WP speichert die Ablaufzeit als Option; reicht der Zugriff nicht, Fallback.
        $timeout = (int) get_option('_transient_timeout_' . $key, 0);
        $left = $timeout - time();

        return $left > 0 ? $left : $fallback;
    }

    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        $valid = filter_var($ip, FILTER_VALIDATE_IP);

        return $valid !== false ? $valid : 'unknown';
    }
}
