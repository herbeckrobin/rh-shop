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
 * Bewusst nativ (wp.media-Frame + kleines Inline-JS), wie die Varianten-Box.
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
        if ($screen instanceof \WP_Screen && $screen->post_type === ProductType::POST_TYPE) {
            wp_enqueue_media();
        }
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
     * Inline-JS (wp.media-Frame, Entfernen, Drag-Sortierung) + Box-Styles.
     * Bewusst inline wie bei der Varianten-Box: eine Datei, kein Build.
     */
    private function renderAssets(): void
    {
        ?>
        <script>
        ( function () {
            var box = document.querySelector( '[data-rhshop-gallery-box]' );
            if ( ! box || ! window.wp || ! wp.media ) {
                return;
            }
            var list = box.querySelector( '[data-rhshop-gallery-list]' );
            var input = box.querySelector( '[data-rhshop-gallery-ids]' );
            var frame = null;

            function syncInput() {
                var ids = [];
                list.querySelectorAll( '[data-rhshop-gallery-item]' ).forEach( function ( li ) {
                    ids.push( li.getAttribute( 'data-rhshop-gallery-item' ) );
                } );
                input.value = ids.join( ',' );
            }

            function addItem( id, thumbUrl ) {
                if ( list.querySelector( '[data-rhshop-gallery-item="' + id + '"]' ) ) {
                    return;
                }
                var li = document.createElement( 'li' );
                li.setAttribute( 'data-rhshop-gallery-item', id );
                li.setAttribute( 'draggable', 'true' );
                li.innerHTML = '<img src="' + thumbUrl + '" alt="">'
                    + '<button type="button" data-rhshop-gallery-remove aria-label="<?php echo esc_js( __( 'Bild entfernen', 'rh-shop' ) ); ?>">×</button>';
                list.appendChild( li );
            }

            box.querySelector( '[data-rhshop-gallery-add]' ).addEventListener( 'click', function () {
                if ( ! frame ) {
                    frame = wp.media( {
                        title: '<?php echo esc_js( __( 'Galerie-Bilder wählen', 'rh-shop' ) ); ?>',
                        button: { text: '<?php echo esc_js( __( 'Übernehmen', 'rh-shop' ) ); ?>' },
                        library: { type: 'image' },
                        multiple: 'add'
                    } );
                    frame.on( 'select', function () {
                        frame.state().get( 'selection' ).forEach( function ( att ) {
                            var data = att.toJSON();
                            var thumb = ( data.sizes && data.sizes.thumbnail ) ? data.sizes.thumbnail.url : data.url;
                            addItem( String( data.id ), thumb );
                        } );
                        syncInput();
                    } );
                }
                frame.open();
            } );

            list.addEventListener( 'click', function ( e ) {
                var btn = e.target.closest( '[data-rhshop-gallery-remove]' );
                if ( btn ) {
                    btn.closest( '[data-rhshop-gallery-item]' ).remove();
                    syncInput();
                }
            } );

            // Sortierung per Drag and Drop (natives HTML5, reicht für kleine Galerien).
            var dragged = null;
            list.addEventListener( 'dragstart', function ( e ) {
                dragged = e.target.closest( '[data-rhshop-gallery-item]' );
            } );
            list.addEventListener( 'dragover', function ( e ) {
                e.preventDefault();
                var over = e.target.closest( '[data-rhshop-gallery-item]' );
                if ( ! dragged || ! over || over === dragged ) {
                    return;
                }
                var rect = over.getBoundingClientRect();
                var before = ( e.clientX - rect.left ) < rect.width / 2;
                list.insertBefore( dragged, before ? over : over.nextSibling );
            } );
            list.addEventListener( 'drop', function ( e ) {
                e.preventDefault();
                syncInput();
            } );
            list.addEventListener( 'dragend', syncInput );
        } )();
        </script>
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
