<?php

declare(strict_types=1);

namespace RhShop\Admin;

defined( 'ABSPATH' ) || exit;

use RhShop\Catalog\ProductType;
use WP_Post;

/**
 * Meta-Box im Produkt-Editor für die Bildergalerie der Detailseite.
 *
 * Speichert eine sortierte Liste von Attachment-IDs im Meta `_rhshop_gallery`.
 * Das Beitragsbild bleibt das Hauptbild (Raster, Warenkorb, Fallback); die Galerie
 * ergänzt weitere Ansichten auf der Produktseite (Block rh-shop/product-gallery).
 * Bewusst nativ (wp.media-Frame + eigenes kleines Script), kein Build.
 */
final class GalleryMetaBox
{
    public const META_GALLERY = '_rhshop_gallery';

    private const NONCE_ACTION = 'rhshop_save_gallery';
    private const NONCE_FIELD = 'rhshop_gallery_nonce';

    public function boot(): void
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_' . ProductType::POST_TYPE, [$this, 'save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueMedia']);
    }

    public function register(): void
    {
        add_meta_box(
            'rhshop-gallery',
            __('Bildergalerie', 'rh-shop'),
            [$this, 'render'],
            ProductType::POST_TYPE,
            // Zu den anderen Produktdaten in den Meta-Boxen-Bereich, direkt unter
            // Preis & Varianten. Volle Breite, dadurch grosse Vorschaubilder.
            'normal',
            'default'
        );
    }

    /**
     * wp.media nur auf dem Produkt-Editor laden.
     */
    public function enqueueMedia(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen instanceof \WP_Screen || $screen->post_type !== ProductType::POST_TYPE) {
            return;
        }

        wp_enqueue_media();

        // Eigenes Script statt Inline im Meta-Box-Markup: das lief im Block-Editor
        // los, bevor wp.media bereit war, brach still ab und der Button blieb tot.
        // Als registriertes Script im Footer ist die Reihenfolge garantiert.
        $rel = 'assets/js/gallery-metabox.js';
        $abs = RHSHOP_PLUGIN_DIR . $rel;
        wp_enqueue_script(
            'rh-shop-gallery-metabox',
            RHSHOP_PLUGIN_URL . $rel,
            ['media-views'],
            file_exists($abs) ? (string) filemtime($abs) : RHSHOP_VERSION,
            true
        );
        wp_localize_script('rh-shop-gallery-metabox', 'rhShopGallery', [
            'title' => __('Bilder wählen', 'rh-shop'),
            'button' => __('Übernehmen', 'rh-shop'),
            'remove' => __('Bild entfernen', 'rh-shop'),
            'feature' => __('Als Hauptbild festlegen', 'rh-shop'),
            'replace' => __('Bild ersetzen', 'rh-shop'),
            'featuredBadge' => __('Hauptbild', 'rh-shop'),
            'iconStar' => self::icon('star'),
            'iconReplace' => self::icon('replace'),
            'mediaMissing' => __('Die Medienauswahl konnte nicht geladen werden. Bitte lade die Seite neu.', 'rh-shop'),
        ]);
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $featured = (int) get_post_thumbnail_id($post->ID);
        // Das Hauptbild steht genau einmal in der Liste, an erster Stelle. Aus den
        // weiteren Bildern fliegt es raus, falls es dort auch gepflegt wurde.
        $gallery = array_values(array_filter(
            self::imageIds($post->ID),
            static fn (int $id): bool => $id !== $featured
        ));

        echo '<div class="rhshop-gallery-box" data-rhshop-gallery-box>';
        echo '<p class="rhshop-hint">' . esc_html__(
            'Alle Bilder dieses Produkts. Das erste ist das Hauptbild und erscheint im Raster und im Warenkorb, die weiteren stehen auf der Produktseite darunter. Kacheln verschieben sortiert sie um.',
            'rh-shop'
        ) . '</p>';

        echo '<ul class="rhshop-gallery-box__list" data-rhshop-gallery-list>';
        if ($featured > 0) {
            $this->renderItem($featured, true);
        }
        foreach ($gallery as $id) {
            $this->renderItem($id, false);
        }
        echo '</ul>';

        printf(
            '<p class="rhshop-gallery-box__empty" data-rhshop-gallery-empty%s>%s</p>',
            ($featured > 0 || $gallery !== []) ? ' hidden' : '',
            esc_html__('Noch keine Bilder. Leg mit "Bilder hinzufügen" los, das erste wird das Hauptbild.', 'rh-shop')
        );

        printf(
            '<input type="hidden" name="rhshop_gallery_ids" value="%s" data-rhshop-gallery-ids>',
            esc_attr(implode(',', $gallery))
        );
        // Nur gefüllt, wenn der Redakteur hier ein anderes Hauptbild wählt. Leer heisst:
        // Beitragsbild nicht anfassen (sonst würde eine Änderung aus der Seitenleiste
        // beim Speichern wieder überschrieben).
        printf(
            '<input type="hidden" name="rhshop_featured_id" value="" data-rhshop-gallery-featured data-initial="%d">',
            $featured
        );

        printf(
            '<button type="button" class="button button-primary" data-rhshop-gallery-add>%s</button>',
            esc_html__('Bilder hinzufügen', 'rh-shop')
        );
        echo '</div>';

        $this->renderAssets();
    }

    private function renderItem(int $id, bool $isFeatured): void
    {
        $thumb = wp_get_attachment_image_url($id, 'medium');
        if (! is_string($thumb)) {
            return;
        }

        printf(
            '<li data-rhshop-gallery-item="%1$d"%2$s draggable="true" title="%3$s">'
            . '<img src="%4$s" alt="">'
            . '<span class="rhshop-gallery-box__badge">%5$s</span>'
            . '<span class="rhshop-gallery-box__tools">'
            . '<button type="button" data-rhshop-gallery-feature aria-label="%6$s" title="%6$s">%9$s</button>'
            . '<button type="button" data-rhshop-gallery-replace aria-label="%7$s" title="%7$s">%10$s</button>'
            . '<button type="button" data-rhshop-gallery-remove aria-label="%8$s" title="%8$s">×</button>'
            . '</span>'
            . '</li>',
            (int) $id,
            $isFeatured ? ' class="is-featured"' : '',
            esc_attr(get_the_title($id)),
            esc_url($thumb),
            esc_html__('Hauptbild', 'rh-shop'),
            esc_attr__('Als Hauptbild festlegen', 'rh-shop'),
            esc_attr__('Bild ersetzen', 'rh-shop'),
            esc_attr__('Bild entfernen', 'rh-shop'),
            self::icon('star'),
            self::icon('replace')
        );
    }

    /**
     * Kleine Inline-Icons für die Kachel-Werkzeuge (kein Dashicon-Zwang, damit die
     * Buttons auf dem Bild gleich gross und gut treffbar bleiben).
     */
    private static function icon(string $name): string
    {
        $paths = [
            'star' => '<path d="M12 3.6l2.5 5.1 5.6.8-4 4 1 5.6-5.1-2.7-5 2.7 1-5.6-4.1-4 5.6-.8z"/>',
            'replace' => '<path d="M4 12a8 8 0 0 1 13.7-5.6M20 12a8 8 0 0 1-13.7 5.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M17 3v4h-4M7 21v-4h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        ];

        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    /**
     * Box-Styles. Die Interaktion (wp.media, Entfernen, Sortieren) liegt in
     * assets/js/gallery-metabox.js, siehe enqueueMedia().
     */
    private function renderAssets(): void
    {
        ?>
        <style>
        .rhshop-gallery-box .rhshop-hint { color: #646970; margin: 0 0 12px; max-width: 75ch; }
        .rhshop-gallery-box__list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 12px;
            margin: 0 0 12px;
            padding: 0;
            list-style: none;
        }
        .rhshop-gallery-box__list li {
            position: relative;
            aspect-ratio: 1;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #dcdcde;
            background: #f6f7f7;
            cursor: grab;
        }
        .rhshop-gallery-box__list li.is-featured { border: 2px solid #2271b1; }
        .rhshop-gallery-box__list li:active { cursor: grabbing; }
        .rhshop-gallery-box__list img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* Badge nur am Hauptbild, Werkzeuge auf Hover/Fokus. */
        .rhshop-gallery-box__badge {
            position: absolute; left: 6px; bottom: 6px;
            padding: 2px 8px; border-radius: 999px;
            background: #2271b1; color: #fff;
            font-size: 11px; font-weight: 600;
            display: none;
        }
        .rhshop-gallery-box__list li.is-featured .rhshop-gallery-box__badge { display: block; }

        .rhshop-gallery-box__tools {
            position: absolute; top: 6px; right: 6px;
            display: flex; gap: 4px;
            opacity: 0; transition: opacity .15s ease;
        }
        .rhshop-gallery-box__list li:hover .rhshop-gallery-box__tools,
        .rhshop-gallery-box__tools:focus-within { opacity: 1; }
        .rhshop-gallery-box__tools button {
            width: 26px; height: 26px; padding: 0;
            display: flex; align-items: center; justify-content: center;
            border: 0; border-radius: 50%;
            background: rgba(29, 35, 39, 0.85); color: #fff;
            font-size: 15px; line-height: 1; cursor: pointer;
        }
        .rhshop-gallery-box__tools button:hover { background: #2271b1; }
        /* Das Hauptbild braucht den Hauptbild-Knopf nicht. */
        .rhshop-gallery-box__list li.is-featured [data-rhshop-gallery-feature] { display: none; }
        .rhshop-gallery-box__empty { color: #646970; font-style: italic; margin: 0 0 12px; }
        .rhshop-gallery-box__empty[hidden] { display: none; }
        </style>
        <?php
    }

    public function save(int $postId): void
    {
        if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        // Hauptbild nur anfassen, wenn es in dieser Box aktiv gewechselt wurde.
        // Sonst würde eine Änderung aus der Seitenleiste hier überschrieben.
        $featuredRaw = isset($_POST['rhshop_featured_id']) ? absint($_POST['rhshop_featured_id']) : 0;
        if ($featuredRaw > 0 && wp_attachment_is_image($featuredRaw)) {
            set_post_thumbnail($postId, $featuredRaw);
        }

        $featured = (int) get_post_thumbnail_id($postId);

        $raw = isset($_POST['rhshop_gallery_ids']) ? sanitize_text_field(wp_unslash($_POST['rhshop_gallery_ids'])) : '';
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = absint($part);
            // Das Hauptbild gehört nicht in die Liste der weiteren Bilder, sonst
            // stünde es auf der Produktseite zweimal.
            if ($id > 0 && $id !== $featured && wp_attachment_is_image($id)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            delete_post_meta($postId, self::META_GALLERY);

            return;
        }

        update_post_meta($postId, self::META_GALLERY, $ids);
    }

    /**
     * Galerie-IDs eines Produkts, bereinigt (nur existierende Bilder).
     *
     * @return array<int, int>
     */
    public static function imageIds(int $productId): array
    {
        $raw = get_post_meta($productId, self::META_GALLERY, true);
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = absint($id);
            if ($id > 0 && wp_attachment_is_image($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
