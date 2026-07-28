<?php

declare(strict_types=1);

namespace RhShop\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Seiten-Lookup per Slug mit Request-Cache.
 *
 * Die Shop-Views fragen dieselben Seiten mehrfach pro Request (Versandkosten-Link
 * in jeder Produktkarte, Zum-Shop-Button in mehreren Warenkorb-Ansichten). Ein
 * statischer Cache pro Slug spart die wiederholten Lookups, inklusive der
 * Negativ-Treffer (Seite existiert nicht).
 */
final class Pages
{
    /** @var array<string, string> */
    private static array $urls = [];

    /**
     * Permalink der Seite mit diesem Slug, leer wenn es sie nicht gibt.
     */
    public static function url(string $slug): string
    {
        if (isset(self::$urls[$slug])) {
            return self::$urls[$slug];
        }

        $page = get_page_by_path($slug);

        return self::$urls[$slug] = $page instanceof \WP_Post ? (string) get_permalink($page) : '';
    }

    public static function flushCache(): void
    {
        self::$urls = [];
    }
}
