<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product search (admin-only)
 *
 * WHY: The target selector UI needs to browse large catalogs without sending
 * huge payloads in a single chat response.
 *
 * @INVARIANT: Read-only. Never mutates products or memory.
 */
class APAI_Brain_Product_Search {

    /**
     * Public wrapper used by REST: 1-indexed page.
     *
     * @return array{total:int, items:array<int, array{id:int,title:string,sku:string,price:string,thumb_url:string,categories:array<int,string>}>}
     */
    public static function search( $q, $page = 1, $per_page = 20 ) {
        $page = max( 1, (int) $page );
        $per_page = (int) $per_page;
        if ( $per_page <= 0 ) { $per_page = 20; }
        if ( $per_page > 100 ) { $per_page = 100; }
        $offset = ( $page - 1 ) * $per_page;
        return self::search_by_title_like( (string) $q, $per_page, $offset );
    }

    /**
     * Lightweight single-product summary used by the Action Card preview.
     *
     * @param int $product_id
     * @return array<string,mixed>|null
     */
    public static function summary( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) { return null; }
        if ( ! function_exists( 'wc_get_product' ) ) { return null; }
        $p = wc_get_product( $product_id );
        if ( ! $p ) { return null; }

        $title = (string) $p->get_name();
        $sku   = (string) $p->get_sku();
        $price = (string) $p->get_price();

        return array(
            'id'         => $product_id,
            'title'      => $title !== '' ? $title : ( 'Producto #' . $product_id ),
            'sku'        => $sku,
            'price'      => $price,
            'thumb_url'  => self::get_product_thumb_url( $p ),
            'categories' => self::get_product_category_names( $product_id ),
            'status'     => method_exists( $p, 'get_status' ) ? (string) $p->get_status() : '',
            'type'       => method_exists( $p, 'get_type' ) ? (string) $p->get_type() : '',
        );
    }

    /**
     * Variations list for a variable product (read-only). Used by the Variation Selector UI.
     *
     * @param int $product_id
     * @param int $limit
     * @param int $offset
     * @return array{product_id:int,total:int,items:array<int,array{id:int,label:string,attributes:array<string,string>,regular_price:string,sale_price:string,stock_status:string}>}
     */
    public static function variations( $product_id, $limit = 50, $offset = 0 ) {
        $product_id = (int) $product_id;
        $limit = (int) $limit;
        $offset = (int) $offset;
        if ( $product_id <= 0 ) {
            return array( 'product_id' => 0, 'total' => 0, 'items' => array() );
        }
        if ( $limit <= 0 ) { $limit = 50; }
        if ( $limit > 200 ) { $limit = 200; }
        if ( $offset < 0 ) { $offset = 0; }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return array( 'product_id' => $product_id, 'total' => 0, 'items' => array() );
        }
        $p = wc_get_product( $product_id );
        if ( ! $p || ! method_exists( $p, 'get_type' ) || (string) $p->get_type() !== 'variable' ) {
            return array( 'product_id' => $product_id, 'total' => 0, 'items' => array() );
        }
        if ( ! method_exists( $p, 'get_children' ) ) {
            return array( 'product_id' => $product_id, 'total' => 0, 'items' => array() );
        }
        $children = $p->get_children();
        if ( ! is_array( $children ) ) { $children = array(); }
        $total = count( $children );

        $slice = array_slice( $children, $offset, $limit );
        $items = array();
        foreach ( $slice as $cid ) {
            $cid = absint( $cid );
            if ( $cid <= 0 ) { continue; }
            $v = wc_get_product( $cid );
            if ( ! $v ) { continue; }

            $attrs = array();
            if ( method_exists( $v, 'get_attributes' ) ) {
                $a = $v->get_attributes();
                if ( is_array( $a ) ) {
                    foreach ( $a as $k => $val ) {
                        $k = (string) $k;
                        $val = is_array( $val ) ? implode( ',', $val ) : (string) $val;
                        $k = preg_replace( '/^attribute_/', '', $k );
                        if ( $k !== '' && $val !== '' ) {
                            $attrs[ $k ] = $val;
                        }
                    }
                }
            }

            $label = '#' . $cid;
            if ( ! empty( $attrs ) ) {
                $pairs = array();
                foreach ( $attrs as $k => $val ) {
                    $pairs[] = $k . ': ' . $val;
                }
                $label .= ' (' . implode( ', ', $pairs ) . ')';
            }

            $items[] = array(
                'id' => $cid,
                'label' => $label,
                'attributes' => $attrs,
                'regular_price' => method_exists( $v, 'get_regular_price' ) ? (string) $v->get_regular_price() : '',
                'sale_price'    => method_exists( $v, 'get_sale_price' ) ? (string) $v->get_sale_price() : '',
                'stock_status'  => method_exists( $v, 'get_stock_status' ) ? (string) $v->get_stock_status() : '',
            );
        }

        return array(
            'product_id' => $product_id,
            'total' => $total,
            'items' => $items,
        );
    }

    /**
     * Search published products by title (LIKE), with pagination.
     *
     * @param string $q
     * @param int $limit
     * @param int $offset
     * @return array{total:int, items:array<int, array{id:int,title:string,sku:string,price:string,thumb_url:string,categories:array<int,string>}>}
     */
    public static function search_by_title_like( $q, $limit = 20, $offset = 0 ) {
        global $wpdb;

        $q = sanitize_text_field( (string) $q );
        $limit = (int) $limit;
        $offset = (int) $offset;

        if ( $limit <= 0 ) { $limit = 20; }
        if ( $limit > 100 ) { $limit = 100; }
        if ( $offset < 0 ) { $offset = 0; }

        // Empty query: return latest products (still paginated).
        $where = "post_type='product' AND post_status='publish'";
        $params = array();
        if ( $q !== '' ) {
            $where .= ' AND post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $q ) . '%';
        }

        // Total
        $sql_total = 'SELECT COUNT(ID) FROM ' . $wpdb->posts . ' WHERE ' . $where;
        if ( ! empty( $params ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $params ) );
        } else {
            $total = (int) $wpdb->get_var( $sql_total );
        }

        // Items
        $sql_items = 'SELECT ID, post_title FROM ' . $wpdb->posts . ' WHERE ' . $where . ' ORDER BY ID DESC LIMIT %d OFFSET %d';
        $params_items = $params;
        $params_items[] = $limit;
        $params_items[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( $sql_items, $params_items ), ARRAY_A );

        $items = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $id = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
                if ( $id <= 0 ) { continue; }
                $title = isset( $r['post_title'] ) ? (string) $r['post_title'] : '';
                $title = trim( wp_strip_all_tags( $title ) );

                $sku = '';
                $price = '';
                $thumb_url = '';
                $cats = array();
                if ( function_exists( 'wc_get_product' ) ) {
                    $p = wc_get_product( $id );
                    if ( $p ) {
                        $sku = (string) $p->get_sku();
                        $price = (string) $p->get_price();
                        $thumb_url = self::get_product_thumb_url( $p );
                        $cats = self::get_product_category_names( $id );
                    }
                }

                $items[] = array(
                    'id'    => $id,
                    'title' => ( $title !== '' ? $title : ( 'Producto #' . $id ) ),
                    'sku'   => $sku,
                    'price' => $price,
                    'thumb_url'  => $thumb_url,
                    'categories' => $cats,
                );
            }
        }

        return array(
            'total' => $total,
            'items' => $items,
        );
    }

    /** UI-only: thumbnail URL for a product (safe, read-only). */
    private static function get_product_thumb_url( $product ) {
        try {
            if ( ! $product ) { return ''; }
            $image_id = (int) $product->get_image_id();
            if ( $image_id > 0 ) {
                $url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
                return is_string( $url ) ? $url : '';
            }
            if ( function_exists( 'wc_placeholder_img_src' ) ) {
                return (string) wc_placeholder_img_src( 'thumbnail' );
            }
        } catch ( \Throwable $e ) {
            return '';
        }
        return '';
    }

    /** UI-only: category names for a product. */
    private static function get_product_category_names( $product_id ) {
        try {
            $names = array();
            $terms = get_the_terms( (int) $product_id, 'product_cat' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) {
                    if ( isset( $t->name ) && $t->name !== '' ) {
                        $names[] = (string) $t->name;
                    }
                }
            }
            return $names;
        } catch ( \Throwable $e ) {
            return array();
        }
    }
}
