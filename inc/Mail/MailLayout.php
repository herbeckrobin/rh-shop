<?php

declare(strict_types=1);

namespace RhShop\Mail;

defined( 'ABSPATH' ) || exit;

use RhShop\Stripe\Config;

/**
 * Gemeinsamer Rahmen für alle Shop-Mails: Kopf (Logo oder Shop-Name auf Akzentfarbe),
 * Inhalt, Fuss (Anschrift). Table-basiertes Markup mit Inline-CSS, weil E-Mail-Clients
 * (Outlook &amp; Co.) weder externe Stylesheets noch modernes Layout zuverlässig rendern.
 *
 * Der übergebene Body ist bereits fertiges, escaptes HTML aus den Mailer-Methoden, hier
 * wird nur gerahmt.
 */
final class MailLayout
{
    public static function wrap(string $bodyHtml, Config $config): string
    {
        $accent = $config->mailLayoutAccent();
        $logoUrl = $config->mailLayoutLogoUrl();
        $shopName = (string) get_bloginfo('name');
        $footer = $config->mailLayoutFooter();

        $header = $logoUrl !== ''
            ? '<img src="' . esc_url($logoUrl) . '" alt="' . esc_attr($shopName) . '" style="max-height:44px;max-width:220px;display:inline-block">'
            : '<span style="font-size:18px;font-weight:700;color:#ffffff">' . esc_html($shopName) . '</span>';

        $footerHtml = $footer !== ''
            ? '<div>' . nl2br(esc_html($footer)) . '</div>'
            : '';

        return '<div style="margin:0;padding:24px 0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;border-collapse:collapse;background:#ffffff;border-radius:10px;overflow:hidden">'
            . '<tr><td style="background:' . esc_attr($accent) . ';padding:22px 28px;text-align:center">' . $header . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $header intern escapt.
            . '<tr><td style="padding:28px;color:#1c2c2c;font-size:15px;line-height:1.6">' . $bodyHtml . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body aus Mailer, escapt.
            . '<tr><td style="padding:18px 28px;background:#fafafa;color:#8a8a8a;font-size:12px;line-height:1.5;border-top:1px solid #eeeeee">' . $footerHtml . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $footerHtml intern escapt.
            . '</table></td></tr></table></div>';
    }
}
