<?php

declare(strict_types=1);

namespace RhShop\Orders;

use RhShop\Catalog\ReservationRepository;
use RhShop\Stripe\Config;

/**
 * Aufräum-Cron für die Bestand-Reservierung. Abgelaufene Reservierungen zählen zwar
 * ohnehin nicht mehr mit (lazy, der Bestand ist sofort wieder frei), aber ihre Zeilen
 * und die zugehörigen unbezahlten Bestellungen sollen nicht ewig liegen bleiben:
 *
 * - abgelaufene Reservierungs-Zeilen löschen,
 * - unbezahlte Bestellungen, deren Halte-Fenster abgelaufen ist, auf storniert setzen
 *   (sie kommen so aus der "Zu erledigen"-Liste, ihre Reservierung ist längst frei).
 */
final class ReservationCron
{
    public const HOOK = 'rhshop_stock_maintenance';

    public function __construct(
        private readonly Config $config,
        private readonly ReservationRepository $reservations = new ReservationRepository(),
        private readonly OrderStore $orders = new OrderStore(),
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', [$this, 'schedule']);
    }

    public function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        $this->reservations->pruneExpired();

        // Unbezahlte Bestellungen älter als das Halte-Fenster stornieren. Grosszügiger
        // Puffer (Halte-Fenster selbst), damit keine gerade zahlende Bestellung erwischt
        // wird; die Zahlung dauert Sekunden, das Fenster Minuten.
        $this->orders->cancelAbandonedPending($this->config->reservationHoldMinutes());
    }
}
