<?php

declare(strict_types=1);

namespace RhShop\Admin;

use RhShop\Catalog\ProductType;
use RhShop\Catalog\Variant;
use RhShop\Catalog\VariantRepository;
use RhShop\Support\Money;
use WP_Post;

/**
 * Meta-Box im Produkt-Editor für Preis, Bestand und Varianten.
 *
 * Zwei Ebenen in einer Box:
 * - Einfacher Preis + Bestand für Produkte ohne Varianten (Sticker, Tasse).
 * - Eine Varianten-Tabelle (Größe/Farbe/SKU/Preis/Bestand) für Textil. Sind hier
 *   Zeilen gepflegt, gewinnen sie über den einfachen Preis.
 *
 * Bewusst nativ (Meta-Box + kleines Inline-JS) statt einer bespoke React-/AJAX-UI:
 * für kleine Sortimente ist das die schlanke, editor-souveräne Pflege.
 */
final class VariantMetaBox
{
    private const NONCE_ACTION = 'rhshop_save_variants';
    private const NONCE_FIELD = 'rhshop_variants_nonce';

    private VariantRepository $variants;

    public function __construct()
    {
        $this->variants = new VariantRepository();
    }

    public function boot(): void
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_' . ProductType::POST_TYPE, [$this, 'save'], 10, 2);
    }

    public function register(): void
    {
        add_meta_box(
            'rhshop-variants',
            __('Preis & Varianten', 'rh-shop'),
            [$this, 'render'],
            ProductType::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $hasVariants = $this->variants->hasRealVariants($post->ID);
        $simplePrice = (int) get_post_meta($post->ID, VariantRepository::META_SIMPLE_PRICE, true);
        $simpleStock = get_post_meta($post->ID, VariantRepository::META_SIMPLE_STOCK, true);
        $rows = $hasVariants ? $this->variants->forProduct($post->ID) : [];

        echo '<div class="rhshop-metabox">';

        echo '<p class="rhshop-hint">' . esc_html__(
            'Einfacher Preis für Produkte ohne Varianten. Sobald du unten Varianten anlegst, gelten deren Preise.',
            'rh-shop'
        ) . '</p>';

        echo '<p><label><strong>' . esc_html__('Preis', 'rh-shop') . '</strong><br>';
        printf(
            '<input type="text" name="rhshop_simple_price" value="%s" placeholder="24,90" class="regular-text" style="max-width:140px"> €</label></p>',
            esc_attr($simplePrice > 0 ? number_format($simplePrice / 100, 2, ',', '') : '')
        );

        echo '<p><label><strong>' . esc_html__('Bestand', 'rh-shop') . '</strong><br>';
        printf(
            '<input type="number" name="rhshop_simple_stock" value="%s" min="0" step="1" style="max-width:140px"></label>',
            esc_attr($simpleStock === '' || $simpleStock === false ? '' : (string) (int) $simpleStock)
        );
        echo '<br><span class="description">' . esc_html__('Leer lassen = Bestand nicht verfolgen (immer verfügbar).', 'rh-shop') . '</span></p>';

        $this->renderGrundpreis($post->ID);

        echo '<hr><h4>' . esc_html__('Varianten', 'rh-shop') . '</h4>';
        echo '<table class="widefat rhshop-variants"><thead><tr>';
        foreach ([
            __('Größe', 'rh-shop'),
            __('Farbe', 'rh-shop'),
            __('SKU', 'rh-shop'),
            __('Preis (€)', 'rh-shop'),
            __('Bestand', 'rh-shop'),
            __('Nennmenge', 'rh-shop'),
            '',
        ] as $head) {
            printf('<th>%s</th>', esc_html($head));
        }
        echo '</tr></thead><tbody data-rhshop-variant-rows>';

        foreach ($rows as $variant) {
            $this->renderRow($variant);
        }

        echo '</tbody></table>';
        printf(
            '<p><button type="button" class="button" data-rhshop-add-variant>%s</button></p>',
            esc_html__('+ Variante hinzufügen', 'rh-shop')
        );

        echo '</div>';

        $this->renderTemplateAndScript();
    }

    /**
     * PAngV-Grundpreis (§4): die Einheit (Mess-Dimension der Ware) gilt produktweit,
     * die Nennmenge liegt pro Variante. Hier stehen die Einheit und die Nennmenge des
     * Produkts OHNE Varianten. Bei Varianten hat jede Zeile in der Tabelle unten ihre
     * eigene Nennmenge-Spalte. Einheit "keine" = Stückware, kein Grundpreis.
     */
    private function renderGrundpreis(int $postId): void
    {
        $amount = get_post_meta($postId, VariantRepository::META_GP_AMOUNT, true);
        $unit = (string) get_post_meta($postId, VariantRepository::META_GP_UNIT, true);

        echo '<hr><h4>' . esc_html__('Grundpreis (PAngV)', 'rh-shop') . '</h4>';
        echo '<p class="rhshop-hint">' . esc_html__(
            'Nur bei Ware nach Gewicht/Volumen/Länge/Fläche. Wähle die Einheit, dann die Nennmenge des Inhalts (z.B. Einheit "g", Nennmenge 500). Einheit "keine" = Stückware ohne Grundpreis. Bei Varianten trägst du die Nennmenge je Zeile in der Tabelle ein.',
            'rh-shop'
        ) . '</p>';

        echo '<p><label><strong>' . esc_html__('Einheit', 'rh-shop') . '</strong><br>';
        echo '<select name="rhshop_gp_unit">';
        foreach ($this->unitOptions() as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($unit, $value, false),
                esc_html($label)
            );
        }
        echo '</select></label></p>';

        echo '<p><label><strong>' . esc_html__('Nennmenge (Produkt ohne Varianten)', 'rh-shop') . '</strong><br>';
        printf(
            '<input type="text" name="rhshop_gp_amount" value="%s" placeholder="500" inputmode="decimal" style="max-width:120px"></label><br>',
            esc_attr($amount === '' || $amount === false ? '' : (string) $amount)
        );
        echo '<span class="description">' . esc_html__('Nur relevant, wenn das Produkt keine Varianten hat.', 'rh-shop') . '</span></p>';
    }

    /**
     * Auswahl der Grundpreis-Einheiten. "" = Stückware.
     *
     * @return array<string, string>
     */
    private function unitOptions(): array
    {
        return [
            '' => __('keine (Stückware)', 'rh-shop'),
            'g' => 'g', 'kg' => 'kg',
            'ml' => 'ml', 'l' => 'l',
            'cm' => 'cm', 'm' => 'm', 'm2' => 'm²',
        ];
    }

    private function renderRow(Variant $variant): void
    {
        $priceDisplay = $variant->priceCents > 0 ? number_format($variant->priceCents / 100, 2, ',', '') : '';
        $stockDisplay = $variant->stock === null ? '' : (string) $variant->stock;
        $gpDisplay = $variant->gpAmount === null ? '' : rtrim(rtrim(number_format($variant->gpAmount, 3, ',', ''), '0'), ',');

        echo '<tr>';
        printf('<td><input type="hidden" name="rhshop_variant_id[]" value="%s"><input type="text" name="rhshop_variant_option1[]" value="%s"></td>', esc_attr($variant->id), esc_attr($variant->option1));
        printf('<td><input type="text" name="rhshop_variant_option2[]" value="%s"></td>', esc_attr($variant->option2));
        printf('<td><input type="text" name="rhshop_variant_sku[]" value="%s"></td>', esc_attr($variant->sku));
        printf('<td><input type="text" name="rhshop_variant_price[]" value="%s" placeholder="24,90" style="max-width:90px"></td>', esc_attr($priceDisplay));
        printf('<td><input type="number" name="rhshop_variant_stock[]" value="%s" min="0" step="1" style="max-width:80px"></td>', esc_attr($stockDisplay));
        printf('<td><input type="text" name="rhshop_variant_gp[]" value="%s" placeholder="500" inputmode="decimal" style="max-width:80px"></td>', esc_attr($gpDisplay));
        printf('<td><button type="button" class="button-link-delete" data-rhshop-remove-variant>%s</button></td>', esc_html__('Entfernen', 'rh-shop'));
        echo '</tr>';
    }

    /**
     * Leere Vorlagen-Zeile (per JS geklont) + die kleine Add/Remove-Mechanik.
     * Buildless, Vanilla-JS, Event-Delegation, damit auch neu eingefügte Zeilen
     * greifen.
     */
    private function renderTemplateAndScript(): void
    {
        ?>
        <template data-rhshop-variant-template>
            <tr>
                <td><input type="hidden" name="rhshop_variant_id[]" value=""><input type="text" name="rhshop_variant_option1[]" value=""></td>
                <td><input type="text" name="rhshop_variant_option2[]" value=""></td>
                <td><input type="text" name="rhshop_variant_sku[]" value=""></td>
                <td><input type="text" name="rhshop_variant_price[]" value="" placeholder="24,90" style="max-width:90px"></td>
                <td><input type="number" name="rhshop_variant_stock[]" value="" min="0" step="1" style="max-width:80px"></td>
                <td><input type="text" name="rhshop_variant_gp[]" value="" placeholder="500" inputmode="decimal" style="max-width:80px"></td>
                <td><button type="button" class="button-link-delete" data-rhshop-remove-variant><?php echo esc_html__('Entfernen', 'rh-shop'); ?></button></td>
            </tr>
        </template>
        <script>
        ( function () {
            var box = document.querySelector( '.rhshop-metabox' );
            if ( ! box ) { return; }
            var rows = box.querySelector( '[data-rhshop-variant-rows]' );
            var tpl = box.querySelector( '[data-rhshop-variant-template]' );
            box.addEventListener( 'click', function ( e ) {
                var add = e.target.closest( '[data-rhshop-add-variant]' );
                if ( add ) {
                    rows.appendChild( tpl.content.cloneNode( true ) );
                    return;
                }
                var remove = e.target.closest( '[data-rhshop-remove-variant]' );
                if ( remove ) {
                    var tr = remove.closest( 'tr' );
                    if ( tr ) { tr.remove(); }
                }
            } );
        } )();
        </script>
        <style>
        .rhshop-metabox .rhshop-variants input[type="text"] { width: 100%; }
        .rhshop-metabox .rhshop-hint { color: #646970; }
        </style>
        <?php
    }

    public function save(int $postId, WP_Post $post): void
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

        $this->variants->saveSimple(
            $postId,
            Money::toCents(isset($_POST['rhshop_simple_price']) ? sanitize_text_field(wp_unslash($_POST['rhshop_simple_price'])) : ''),
            $this->parseStock($_POST['rhshop_simple_stock'] ?? '')
        );

        $this->saveGrundpreis($postId);

        $this->variants->save($postId, $this->collectVariants());
    }

    /**
     * Grundpreis-Nennmenge + Einheit speichern. Nennmenge als Zahl (Komma erlaubt),
     * Einheit gegen die Whitelist geprüft. Leere/ungültige Angabe = kein Grundpreis.
     */
    private function saveGrundpreis(int $postId): void
    {
        $unitRaw = isset($_POST['rhshop_gp_unit']) ? sanitize_text_field(wp_unslash($_POST['rhshop_gp_unit'])) : '';
        $unit = in_array($unitRaw, ['g', 'kg', 'ml', 'l', 'cm', 'm', 'm2'], true) ? $unitRaw : '';

        // Ohne Einheit gibt es keinen Grundpreis: Einheit + einfache Nennmenge löschen.
        // Die Varianten-Nennmengen bleiben stehen (schaden ohne Einheit nicht, greifen
        // wieder, sobald eine Einheit gesetzt wird).
        if ($unit === '') {
            delete_post_meta($postId, VariantRepository::META_GP_UNIT);
            delete_post_meta($postId, VariantRepository::META_GP_AMOUNT);
            return;
        }

        update_post_meta($postId, VariantRepository::META_GP_UNIT, $unit);

        $amount = $this->parseAmount(isset($_POST['rhshop_gp_amount']) ? wp_unslash($_POST['rhshop_gp_amount']) : '');
        if ($amount === null) {
            delete_post_meta($postId, VariantRepository::META_GP_AMOUNT);
            return;
        }

        update_post_meta($postId, VariantRepository::META_GP_AMOUNT, $amount);
    }

    /**
     * @return array<int, Variant>
     */
    private function collectVariants(): array
    {
        $ids = $this->postArray('rhshop_variant_id');
        $option1 = $this->postArray('rhshop_variant_option1');
        $option2 = $this->postArray('rhshop_variant_option2');
        $skus = $this->postArray('rhshop_variant_sku');
        $prices = $this->postArray('rhshop_variant_price');
        $stocks = $this->postArray('rhshop_variant_stock');
        $gps = $this->postArray('rhshop_variant_gp');

        $variants = [];
        $count = count($option1);

        for ($i = 0; $i < $count; $i++) {
            $o1 = sanitize_text_field($option1[$i] ?? '');
            $o2 = sanitize_text_field($option2[$i] ?? '');
            $sku = sanitize_text_field($skus[$i] ?? '');
            $priceCents = Money::toCents($prices[$i] ?? '');

            // Vollständig leere Zeilen (aus dem Template, nie befüllt) überspringen.
            if ($o1 === '' && $o2 === '' && $sku === '' && $priceCents === 0) {
                continue;
            }

            $variants[] = new Variant(
                id: (string) ($ids[$i] ?? ''),
                option1: $o1,
                option2: $o2,
                sku: $sku,
                priceCents: $priceCents,
                stock: $this->parseStock($stocks[$i] ?? ''),
                gpAmount: $this->parseAmount($gps[$i] ?? ''),
            );
        }

        return $variants;
    }

    /**
     * @return array<int, string>
     */
    private function postArray(string $key): array
    {
        if (! isset($_POST[$key]) || ! is_array($_POST[$key])) {
            return [];
        }

        return array_map(static fn ($v): string => (string) wp_unslash($v), $_POST[$key]);
    }

    private function parseStock(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : max(0, (int) $value);
    }

    /**
     * Nennmenge parsen (Komma oder Punkt als Dezimaltrenner). Leer/<= 0 = null.
     */
    private function parseAmount(mixed $value): ?float
    {
        $value = trim(str_replace(',', '.', (string) $value));
        if ($value === '') {
            return null;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : null;
    }
}
