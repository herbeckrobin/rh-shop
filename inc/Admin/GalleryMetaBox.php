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
            // Bewusst 'side': im Block-Editor rendert das die Box in der rechten
            // Dokument-Spalte, wo sie direkt sichtbar ist. 'normal' landet dagegen im
            // unteren Meta-Boxen-Bereich, der standardmäßig zugeklappt ist (gemessen:
            // Box dann gar nicht erreichbar, ohne dass der Nutzer ihn erst aufklappt).
            'side'
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
            'title' => __('Galerie-Bilder wählen', 'rh-shop'),
            'button' => __('Übernehmen', 'rh-shop'),
            'remove' => __('Bild entfernen', 'rh-shop'),
            'mediaMissing' => __('Die Medienauswahl konnte nicht geladen werden. Bitte lade die Seite neu.', 'rh-shop'),
        ]);
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $ids = self::imageIds($post->ID);

        echo '<div class="rhshop-gallery-box" data-rhshop-gallery-box>';
        echo '<p class="rhshop-hint">' . esc_html__(
            'Weitere Bilder für die Produktseite. Das Beitragsbild ist immer das erste Bild.',
            'rh-shop'
        ) . '</p>';

        echo '<ul class="rhshop-gallery-box__list" data-rhshop-gallery-list>';
        foreach ($ids as $id) {
            $this->renderItem($id);
        }
        echo '</ul>';

        printf(
            '<input type="hidden" name="rhshop_gallery_ids" value="%s" data-rhshop-gallery-ids>',
            esc_attr(implode(',', $ids))
        );
        printf(
            '<button type="button" class="button" data-rhshop-gallery-add>%s</button>',
            esc_html__('Bilder hinzufügen', 'rh-shop')
        );
        echo '</div>';

        $this->renderAssets();
    }

    private function renderItem(int $id): void
    {
        $thumb = wp_get_attachment_image_url($id, 'thumbnail');
        if (! is_string($thumb)) {
            return;
        }

        printf(
            '<li data-rhshop-gallery-item="%1$d" draggable="true">'
            . '<img src="%2$s" alt="">'
            . '<button type="button" data-rhshop-gallery-remove aria-label="%3$s">×</button>'
            . '</li>',
            (int) $id,
            esc_url($thumb),
            esc_attr__('Bild entfernen', 'rh-shop')
        );
    }

    /**
     * Box-Styles. Die Interaktion (wp.media, Entfernen, Sortieren) liegt in
     * assets/js/gallery-metabox.js, siehe enqueueMedia().
     */
    private function renderAssets(): void
    {
        ?>
        <style>
        .rhshop-gallery-box .rhshop-hint { color: #646970; }
        .rhshop-gallery-box__list { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 10px; padding: 0; list-style: none; }
        .rhshop-gallery-box__list li { position: relative; width: 72px; height: 72px; cursor: grab; }
        .rhshop-gallery-box__list img { width: 100%; height: 100%; object-fit: cover; border-radius: 4px; display: block; }
        .rhshop-gallery-box__list button {
            position: absolute; top: -6px; right: -6px;
            width: 20px; height: 20px; padding: 0;
            border: 0; border-radius: 50%;
            background: #1d2327; color: #fff;
            font-size: 13px; line-height: 1; cursor: pointer;
        }
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

        $raw = isset($_POST['rhshop_gallery_ids']) ? sanitize_text_field(wp_unslash($_POST['rhshop_gallery_ids'])) : '';
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = absint($part);
            if ($id > 0 && wp_attachment_is_image($id)) {
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
