<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use WP_Screen;

/**
 * Native WordPress-Hilfe (das "Hilfe"-Register oben rechts) auf den Shop-Screens.
 * Kurze, aufgabenbezogene Erklärungen für den Betreiber, ohne den Bildschirm zuzustellen.
 */
final class HelpTabs
{
    public function boot(): void
    {
        add_action('current_screen', [$this, 'addTabs']);
    }

    public function addTabs(WP_Screen $screen): void
    {
        foreach ($this->tabsFor($screen) as $tab) {
            $screen->add_help_tab($tab);
        }
    }

    /**
     * @return array<int, array{id: string, title: string, content: string}>
     */
    private function tabsFor(WP_Screen $screen): array
    {
        $id = $screen->id;

        if (str_contains($id, 'rhshop-overview')) {
            return [$this->tab('overview', __('Shop-Übersicht', 'rh-shop'), [
                __('Hier siehst du auf einen Blick, was los ist (offene Bestellungen, Umsatz, Produkte) und was noch zu tun ist.', 'rh-shop'),
                __('Die Kacheln und die Liste "Zu erledigen" führen direkt zu den passenden Stellen. Unter "Deine Shop-Seiten" findest du Shop, Warenkorb und Kasse zum Ansehen oder Bearbeiten.', 'rh-shop'),
            ])];
        }

        if (str_contains($id, 'rhshop-orders')) {
            return [$this->tab('orders', __('Bestellungen', 'rh-shop'), [
                __('Alle eingegangenen Bestellungen. Über die Auswahl setzt du den Status, z.B. auf "versendet".', 'rh-shop'),
                __('Beim Wechsel auf "versendet" bekommt der Kunde automatisch eine Versandbestätigung. Eine Sendungsnummer oder einen Link kannst du optional mitgeben.', 'rh-shop'),
            ])];
        }

        if ($id === 'toplevel_page_rh-blueprint') {
            return [$this->tab('shop-settings', __('Shop-Einstellungen', 'rh-shop'), [
                __('Die Shop-Einstellungen sind in Tabs geordnet: Status, Zahlung, Preise & Steuer, Versand, E-Mail und Rechtliches.', 'rh-shop'),
                __('Der Status-Tab zeigt, ob dein Shop startklar ist (Stripe verbunden, Webhook, Rechtstexte). Arbeite dich von dort durch die Tabs.', 'rh-shop'),
            ])];
        }

        if ($screen->post_type === ProductType::POST_TYPE && $screen->base === 'post') {
            return [$this->tab('product', __('Produkt pflegen', 'rh-shop'), [
                __('Titel, Bild und Beschreibung pflegst du wie bei einer normalen Seite.', 'rh-shop'),
                __('Preis, Varianten (z.B. Größe/Farbe) und Bestand trägst du in der Box "Varianten & Preis" ein. Ohne Varianten reicht ein einfacher Preis.', 'rh-shop'),
            ])];
        }

        return [];
    }

    /**
     * @param array<int, string> $paragraphs
     * @return array{id: string, title: string, content: string}
     */
    private function tab(string $id, string $title, array $paragraphs): array
    {
        $content = '';
        foreach ($paragraphs as $p) {
            $content .= '<p>' . esc_html($p) . '</p>';
        }

        return [
            'id' => 'rhshop-help-' . $id,
            'title' => $title,
            'content' => $content,
        ];
    }
}
