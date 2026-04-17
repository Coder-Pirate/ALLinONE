<?php
defined( 'ABSPATH' ) || exit;

/**
 * Facebook Product Catalog feed.
 *
 * Serves a Google Shopping / Meta Catalog-compatible XML feed at:
 *   https://yoursite.com/fb-catalog.xml
 *
 * Register the feed URL in Meta Commerce Manager → Data Sources → Add Product Feed.
 *
 * Supported product types:
 *   - Simple products
 *   - Variable products (each variation = one item, linked via item_group_id)
 *
 * Feed includes:
 *   id, title, description, link, image_link, additional_image_link,
 *   availability, price, sale_price, condition, brand, product_type,
 *   item_group_id (variations), color, size, age_group, gender
 */
class COC_FB_Catalog {

    const QUERY_VAR  = 'coc_fb_catalog';
    const FEED_SLUG  = 'fb-catalog.xml';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action( 'init',              [ __CLASS__, 'add_rewrite'       ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_query_var'     ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_output_feed' ] );
    }

    public static function add_rewrite() {
        add_rewrite_rule(
            '^' . self::FEED_SLUG . '$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public static function add_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function maybe_output_feed() {
        if ( ! get_query_var( self::QUERY_VAR ) ) {
            return;
        }

        if ( ! get_option( 'coc_fb_catalog_enabled', '' ) ) {
            wp_die( esc_html__( 'Facebook Catalog feed is disabled.', 'courier-order-check' ), '', [ 'response' => 403 ] );
        }

        self::output_feed();
        exit;
    }

    /* ------------------------------------------------------------------
     * Feed URL helper (for admin display)
     * ------------------------------------------------------------------ */

    public static function feed_url() {
        return home_url( '/' . self::FEED_SLUG );
    }

    /* ------------------------------------------------------------------
     * XML feed output
     * ------------------------------------------------------------------ */

    private static function output_feed() {
        $condition = get_option( 'coc_fb_catalog_condition', 'new' );
        $inc_oos   = (bool) get_option( 'coc_fb_catalog_include_oos', '' );
        $currency  = get_woocommerce_currency();
        $store     = get_bloginfo( 'name' );
        $store_url = home_url();

        // Query all products (simple + variable parents).
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $ids = get_posts( $args );

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'Cache-Control: no-store, no-cache' );
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>'       . self::cdata( $store )     . '</title>' . "\n";
        echo '    <link>'        . esc_url( $store_url )     . '</link>'  . "\n";
        echo '    <description>' . self::cdata( $store . ' — Product Feed' ) . '</description>' . "\n";

        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product || ! $product->is_visible() ) {
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                self::render_variable( $product, $condition, $inc_oos, $currency );
            } else {
                self::render_simple( $product, null, null, $condition, $inc_oos, $currency );
            }
        }

        echo '  </channel>' . "\n";
        echo '</rss>' . "\n";
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Variable product: iterate each published, purchasable variation
     * ------------------------------------------------------------------ */

    private static function render_variable( WC_Product_Variable $parent, $condition, $inc_oos, $currency ) {
        $variation_ids = $parent->get_children();
        foreach ( $variation_ids as $vid ) {
            $variation = wc_get_product( $vid );
            if ( ! $variation || ! $variation->is_purchasable() ) {
                continue;
            }
            self::render_simple( $variation, $parent, $parent->get_id(), $condition, $inc_oos, $currency );
        }
    }

    /* ------------------------------------------------------------------
     * Single product / variation item
     * ------------------------------------------------------------------ */

    private static function render_simple( $product, $parent, $group_id, $condition, $inc_oos, $currency ) {
        // Availability.
        $in_stock = $product->is_in_stock();
        if ( ! $in_stock && ! $inc_oos ) {
            return;
        }
        $availability = $in_stock ? 'in stock' : 'out of stock';
        if ( $product->managing_stock() && $product->get_stock_quantity() <= 0 && $product->get_backorders_allowed() ) {
            $availability = 'preorder';
        }

        // ID: prefer SKU, fall back to post ID.
        $sku        = $product->get_sku();
        $item_id    = $sku ?: (string) $product->get_id();
        $group_sku  = '';
        if ( $group_id ) {
            $par_product = $parent instanceof WC_Product ? $parent : wc_get_product( $group_id );
            $group_sku   = $par_product ? ( $par_product->get_sku() ?: (string) $group_id ) : (string) $group_id;
        }

        // Prices.
        $regular_price = (float) wc_get_price_excluding_tax( $product, [ 'price' => $product->get_regular_price() ] );
        $sale_price    = $product->is_on_sale() ? (float) wc_get_price_excluding_tax( $product, [ 'price' => $product->get_sale_price() ] ) : null;
        $display_price = $product->is_on_sale() ? $sale_price : $regular_price;

        // URLs.
        $link = get_permalink( $product->get_parent_id() ?: $product->get_id() );
        if ( $group_id ) {
            // Deep-link to variation.
            $attrs = $product->get_variation_attributes();
            if ( $attrs ) {
                $link = add_query_arg( $attrs, $link );
            }
        }

        // Images.
        $image_id        = $product->get_image_id();
        $parent_image_id = ( ! $image_id && $parent instanceof WC_Product ) ? $parent->get_image_id() : null;
        $image_link      = $image_id
            ? wp_get_attachment_url( $image_id )
            : ( $parent_image_id ? wp_get_attachment_url( $parent_image_id ) : wc_placeholder_img_src() );

        // Gallery images (additional_image_link — Facebook supports multiple).
        $gallery_ids = $product->get_gallery_image_ids();
        if ( empty( $gallery_ids ) && $parent instanceof WC_Product ) {
            $gallery_ids = $parent->get_gallery_image_ids();
        }

        // Categories → product_type.
        $parent_pid  = $product->get_parent_id() ?: $product->get_id();
        $terms       = get_the_terms( $parent_pid, 'product_cat' );
        $product_type = '';
        if ( $terms && ! is_wp_error( $terms ) ) {
            $crumbs = [];
            foreach ( $terms as $term ) {
                $ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat' ) );
                $path      = '';
                foreach ( $ancestors as $anc_id ) {
                    $anc   = get_term( $anc_id, 'product_cat' );
                    $path .= ( $anc && ! is_wp_error( $anc ) ) ? $anc->name . ' > ' : '';
                }
                $path    .= $term->name;
                $crumbs[] = $path;
            }
            $product_type = implode( ', ', $crumbs );
        }

        // Description: variation description or parent short description or full description.
        $desc = $product->get_description();
        if ( empty( $desc ) && $parent instanceof WC_Product ) {
            $desc = $parent->get_short_description() ?: $parent->get_description();
        }
        $desc = wp_strip_all_tags( $desc );

        // Brand: check popular meta keys.
        $brand_meta_keys = [ '_brand', '_product_brand', 'pa_brand' ];
        $brand           = '';
        foreach ( $brand_meta_keys as $key ) {
            $val = get_post_meta( $parent_pid, $key, true );
            if ( $val ) { $brand = $val; break; }
        }
        // Also check taxonomy 'product_brand' (Perfect WooCommerce Brands, etc.).
        if ( ! $brand ) {
            $brand_terms = get_the_terms( $parent_pid, 'product_brand' );
            if ( $brand_terms && ! is_wp_error( $brand_terms ) ) {
                $brand = $brand_terms[0]->name;
            }
        }

        // Variation attributes → color/size.
        $color = '';
        $size  = '';
        if ( $product->is_type( 'variation' ) ) {
            $attrs = $product->get_variation_attributes();
            foreach ( $attrs as $attr => $val ) {
                $attr_lower = strtolower( $attr );
                if ( strpos( $attr_lower, 'color' ) !== false || strpos( $attr_lower, 'colour' ) !== false ) {
                    $color = $val;
                } elseif ( strpos( $attr_lower, 'size' ) !== false ) {
                    $size = $val;
                }
            }
        }

        // ---- output ----
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '    <item>' . "\n";
        echo '      <g:id>'           . self::x( $item_id )    . '</g:id>'           . "\n";
        echo '      <g:title>'        . self::cdata( $product->get_name() ) . '</g:title>' . "\n";
        echo '      <g:description>'  . self::cdata( $desc ?: $product->get_name() ) . '</g:description>' . "\n";
        echo '      <g:link>'         . esc_url( $link )        . '</g:link>'         . "\n";
        echo '      <g:image_link>'   . esc_url( $image_link )  . '</g:image_link>'   . "\n";

        foreach ( array_slice( $gallery_ids, 0, 10 ) as $gid ) {
            $gurl = wp_get_attachment_url( $gid );
            if ( $gurl ) {
                echo '      <g:additional_image_link>' . esc_url( $gurl ) . '</g:additional_image_link>' . "\n";
            }
        }

        echo '      <g:availability>'  . self::x( $availability )  . '</g:availability>'  . "\n";
        echo '      <g:price>'         . self::x( number_format( $regular_price, 2, '.', '' ) . ' ' . $currency ) . '</g:price>' . "\n";

        if ( $sale_price !== null ) {
            echo '      <g:sale_price>' . self::x( number_format( $sale_price, 2, '.', '' ) . ' ' . $currency ) . '</g:sale_price>' . "\n";
        }

        echo '      <g:condition>'     . self::x( $condition )      . '</g:condition>'     . "\n";

        if ( $product_type ) {
            echo '      <g:product_type>' . self::cdata( $product_type ) . '</g:product_type>' . "\n";
        }

        if ( $brand ) {
            echo '      <g:brand>'  . self::cdata( $brand ) . '</g:brand>'  . "\n";
        }

        if ( $group_sku ) {
            echo '      <g:item_group_id>' . self::x( $group_sku ) . '</g:item_group_id>' . "\n";
        }

        if ( $color ) {
            echo '      <g:color>' . self::cdata( $color ) . '</g:color>' . "\n";
        }

        if ( $size ) {
            echo '      <g:size>' . self::cdata( $size ) . '</g:size>' . "\n";
        }

        // GTIN / MPN if stored.
        $gtin = get_post_meta( $product->get_id(), '_gtin', true ) ?: get_post_meta( $parent_pid, '_gtin', true );
        if ( $gtin ) {
            echo '      <g:gtin>' . self::x( $gtin ) . '</g:gtin>' . "\n";
        }

        $mpn = get_post_meta( $product->get_id(), '_mpn', true ) ?: get_post_meta( $parent_pid, '_mpn', true );
        if ( $mpn ) {
            echo '      <g:mpn>' . self::x( $mpn ) . '</g:mpn>' . "\n";
        }

        echo '    </item>' . "\n";
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * XML helpers
     * ------------------------------------------------------------------ */

    /** Wrap text in CDATA section. */
    private static function cdata( $text ) {
        return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $text ) . ']]>';
    }

    /** XML-escape plain text. */
    private static function x( $text ) {
        return htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' );
    }
}
