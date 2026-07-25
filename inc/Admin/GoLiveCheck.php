<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Legal\Anbieter;
use RhShop\Stripe\Config;

/**
 * Go-Live-Checkliste im Shop-Tab. Hilft dem Betreiber (der kein IT-Profi ist),
 * vor dem Scharfschalten die rechtlichen und technischen Lücken zu sehen: fehlende
 * Rechtsseiten (Impressum, Datenschutz, Widerruf, ggf. AGB), Stripe/Webhook,
 * Anbieter-Anschrift fürs Widerrufsformular.
 *
 * Der Plugin legt bewusst KEINE Rechtsseiten an (Entscheidung: ein leerer Platzhalter
 * darf nicht versehentlich live gehen). Stattdessen zeigt die Liste, was fehlt, und
 * verlinkt direkt zum Anlegen. Rechtstexte selbst kommen vom Generator/Anwalt.
 */
final class GoLiveCheck
{
    private const TAB_ID = 'shop';

    public function __construct(private readonly Config $config)
    {
    }

    public function render(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $checks = $this->checks();
        $open = count(array_filter($checks, static fn (array $c): bool => $c['status'] !== 'ok'));

        echo '<div class="rhbp-card" style="max-width:640px;margin-top:1rem">';
        echo '<h3 style="margin-top:0">' . esc_html__('Go-Live-Check', 'rh-shop') . '</h3>';

        if ($open === 0) {
            echo '<div class="rhbp-callout rhbp-callout--success">' . esc_html__('Alles erledigt. Der Shop ist startklar.', 'rh-shop') . '</div>';
        } else {
            echo '<p class="rhbp-field__desc">' . esc_html(sprintf(
                /* translators: %d: Anzahl offener Punkte */
                _n('%d Punkt noch offen, bevor du live gehst.', '%d Punkte noch offen, bevor du live gehst.', $open, 'rh-shop'),
                $open
            )) . '</p>';
        }

        echo '<ul class="rhshop-golive">';
        foreach ($checks as $check) {
            $this->renderItem($check);
        }
        echo '</ul>';

        echo '<style>'
            . '.rhshop-golive{list-style:none;margin:0;padding:0}'
            . '.rhshop-golive__item{display:flex;gap:.6rem;align-items:flex-start;padding:.55rem 0;border-top:1px solid rgba(0,0,0,.08)}'
            . '.rhshop-golive__item:first-child{border-top:0}'
            . '.rhshop-golive__icon{flex:none;width:1.4rem;height:1.4rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:#fff}'
            . '.rhshop-golive__item--ok .rhshop-golive__icon{background:#1a7f37}'
            . '.rhshop-golive__item--warn .rhshop-golive__icon{background:#bc4c00}'
            . '.rhshop-golive__item--info .rhshop-golive__icon{background:#0969da}'
            . '.rhshop-golive__body{display:flex;flex-direction:column;gap:.1rem}'
            . '.rhshop-golive__hint{font-size:.85rem;color:#646970}'
            . '</style>';

        echo '</div>';
    }

    /**
     * @param array{label: string, status: string, hint: string, url?: string, action?: string} $check
     */
    private function renderItem(array $check): void
    {
        $icons = ['ok' => '✓', 'warn' => '!', 'info' => 'i'];
        $icon = $icons[$check['status']] ?? 'i';

        $action = '';
        if (($check['url'] ?? '') !== '' && ($check['action'] ?? '') !== '') {
            $action = ' <a href="' . esc_url($check['url']) . '">' . esc_html($check['action']) . '</a>';
        }

        printf(
            '<li class="rhshop-golive__item rhshop-golive__item--%s"><span class="rhshop-golive__icon" aria-hidden="true">%s</span>'
            . '<span class="rhshop-golive__body"><strong>%s</strong><span class="rhshop-golive__hint">%s%s</span></span></li>',
            esc_attr($check['status']),
            esc_html($icon),
            esc_html($check['label']),
            esc_html($check['hint']),
            // $action ist bereits escaped (esc_url + esc_html).
            $action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    /**
     * @return array<int, array{label: string, status: string, hint: string, url?: string, action?: string}>
     */
    private function checks(): array
    {
        $checks = [];

        // Stripe verbunden
        if (! $this->config->isConfigured()) {
            $checks[] = ['label' => __('Stripe verbunden', 'rh-shop'), 'status' => 'warn', 'hint' => __('Publishable und Secret Key oben eintragen.', 'rh-shop')];
        } elseif ($this->config->isTestMode()) {
            $checks[] = ['label' => __('Stripe verbunden', 'rh-shop'), 'status' => 'info', 'hint' => __('Aktuell im Test-Modus. Für echte Zahlungen die Live-Keys eintragen.', 'rh-shop')];
        } else {
            $checks[] = ['label' => __('Stripe verbunden', 'rh-shop'), 'status' => 'ok', 'hint' => __('Live-Modus, echte Zahlungen aktiv.', 'rh-shop')];
        }

        // Webhook
        $webhookOk = $this->config->webhookEndpointId() !== '' || $this->config->hasStoredWebhookSecret();
        $checks[] = [
            'label' => __('Webhook eingerichtet', 'rh-shop'),
            'status' => $webhookOk ? 'ok' : 'warn',
            'hint' => $webhookOk ? __('Zahlungen werden serverseitig bestätigt.', 'rh-shop') : __('Ohne Webhook werden Bestellungen nicht auf bezahlt gesetzt. Karte "Webhook" oben.', 'rh-shop'),
        ];

        // Rechtsseiten
        $checks[] = $this->pageCheck('impressum', __('Impressum', 'rh-shop'), __('Pflichtangabe (§ 5 DDG).', 'rh-shop'));
        $checks[] = $this->privacyCheck();
        $checks[] = $this->pageCheck('widerrufsbelehrung', __('Widerrufsbelehrung', 'rh-shop'), __('Pflicht bei Widerrufsrecht. Text vom Generator/Anwalt, Muster-Formular per [rhshop_widerrufsformular] einbinden.', 'rh-shop'));

        if ($this->config->agbEnabled()) {
            $checks[] = $this->pageCheck('agb', __('AGB', 'rh-shop'), __('AGB-Zustimmung ist eingeschaltet, also muss die Seite existieren.', 'rh-shop'));
        }

        // Anbieter-Anschrift (fürs Widerrufsformular)
        $hasAddress = trim((string) rhbp_setting(Config::GROUP, Anbieter::SETTING_ADDRESS, '')) !== ''
            || has_filter('rh-blueprint/shop/anbieter');
        $checks[] = [
            'label' => __('Anbieter-Anschrift', 'rh-shop'),
            'status' => $hasAddress ? 'ok' : 'warn',
            'hint' => $hasAddress ? __('Wird ins Widerrufsformular eingesetzt.', 'rh-shop') : __('Für das Muster-Widerrufsformular. Oben unter "Anbieter-Anschrift" eintragen.', 'rh-shop'),
        ];

        return $checks;
    }

    /**
     * @return array{label: string, status: string, hint: string, url?: string, action?: string}
     */
    private function pageCheck(string $slug, string $label, string $hint): array
    {
        $page = get_page_by_path($slug);
        $published = $page instanceof \WP_Post && $page->post_status === 'publish';

        if ($published) {
            return ['label' => $label, 'status' => 'ok', 'hint' => __('Seite ist veröffentlicht.', 'rh-shop')];
        }

        if ($page instanceof \WP_Post) {
            return [
                'label' => $label,
                'status' => 'warn',
                'hint' => __('Seite existiert, ist aber noch nicht veröffentlicht.', 'rh-shop'),
                'url' => (string) get_edit_post_link($page->ID, 'raw'),
                'action' => __('bearbeiten', 'rh-shop'),
            ];
        }

        return [
            'label' => $label,
            'status' => 'warn',
            'hint' => $hint,
            'url' => admin_url('post-new.php?post_type=page'),
            'action' => __('Seite anlegen', 'rh-shop'),
        ];
    }

    /**
     * @return array{label: string, status: string, hint: string, url?: string, action?: string}
     */
    private function privacyCheck(): array
    {
        $url = get_privacy_policy_url();
        if ($url !== '') {
            return ['label' => __('Datenschutzerklärung', 'rh-shop'), 'status' => 'ok', 'hint' => __('WordPress-Datenschutzseite ist gesetzt.', 'rh-shop')];
        }

        return [
            'label' => __('Datenschutzerklärung', 'rh-shop'),
            'status' => 'warn',
            'hint' => __('Keine Datenschutzseite gesetzt. Unter Einstellungen > Datenschutz festlegen.', 'rh-shop'),
            'url' => admin_url('options-privacy.php'),
            'action' => __('festlegen', 'rh-shop'),
        ];
    }
}
