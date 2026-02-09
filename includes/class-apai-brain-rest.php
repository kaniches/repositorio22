<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Brain_REST {

    /**
     * Normalize prices for stable comparisons (no currency symbols, stable decimal).
     */
    private static function normalize_price_for_compare( $v ) {
        if ( $v === null ) { return ''; }
        $s = trim( (string) $v );
        $s = preg_replace( '/[^0-9.,-]/', '', $s );

        // If both comma and dot exist, assume comma is thousands separator.
        if ( strpos( $s, ',' ) !== false && strpos( $s, '.' ) !== false ) {
            $s = str_replace( ',', '', $s );
        } else {
            // Otherwise treat comma as decimal separator.
            $s = str_replace( ',', '.', $s );
        }

        // Remove repeated dots (best-effort).
        $s = preg_replace( '/\.(?=.*\.)/', '', $s );
        return $s;
    }


    /**
     * For variable products, show a stable "current price" label for UX.
     * If all variations share the same regular price, return that.
     * Otherwise return a min–max range.
     *
     * @param WC_Product $p
     * @return string
     */
    private static function variable_price_display( $p ) {
        if ( ! $p || ! method_exists( $p, 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
            return '';
        }
        $children = $p->get_children();
        if ( ! is_array( $children ) || empty( $children ) ) {
            return '';
        }
        $prices = array();
        foreach ( $children as $cid ) {
            $cid = absint( $cid );
            if ( $cid <= 0 ) { continue; }
            $v = wc_get_product( $cid );
            if ( ! $v ) { continue; }
            $pr = method_exists( $v, 'get_regular_price' ) ? (string) $v->get_regular_price() : '';
            $pr = self::normalize_price_for_compare( $pr );
            if ( $pr === '' ) { continue; }
            $prices[] = $pr;
        }
        if ( empty( $prices ) ) {
            return '';
        }
        $uniq = array_values( array_unique( $prices ) );
        if ( count( $uniq ) === 1 ) {
            return '$' . $uniq[0];
        }
        // numeric compare if possible
        $nums = array();
        foreach ( $prices as $pr ) {
            $n = floatval( str_replace( ',', '.', $pr ) );
            $nums[] = $n;
        }
        sort( $nums );
        $min = $nums[0];
        $max = $nums[count($nums)-1];
        // format without trailing .0
        $fmt = function($x){
            $s = (string) $x;
            if ( preg_match('/\.0+$/', $s) ) { $s = preg_replace('/\.0+$/', '', $s); }
            return $s;
        };
        return '$' . $fmt($min) . '–$' . $fmt($max);
    }

    /**
     * Returns true if the requested changes are a no-op for the target product.
     * For variable products, checks all variations.
     */
    private static function is_noop_update_product( $product, $changes ) {
        if ( ! $product || ! is_array( $changes ) ) { return false; }

        $ptype = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : '';
        $fields = array();
        foreach ( array( 'regular_price', 'sale_price' ) as $k ) {
            if ( array_key_exists( $k, $changes ) ) {
                $fields[] = $k;
            }
        }
        if ( empty( $fields ) ) {
            return false;
        }

        $wanted = array();
        foreach ( $fields as $k ) {
            $wanted[ $k ] = self::normalize_price_for_compare( $changes[ $k ] );
        }

        // Variable products: compare against all variations.
        if ( $ptype === 'variable' && method_exists( $product, 'get_children' ) && function_exists( 'wc_get_product' ) ) {
            $children = $product->get_children();
            if ( ! is_array( $children ) ) { $children = array(); }
            if ( empty( $children ) ) {
                return false;
            }
            foreach ( $children as $child_id ) {
                $child_id = absint( $child_id );
                if ( $child_id <= 0 ) { continue; }
                $child = wc_get_product( $child_id );
                if ( ! $child ) { continue; }
                foreach ( $fields as $k ) {
                    $current = '';
                    if ( $k === 'regular_price' && method_exists( $child, 'get_regular_price' ) ) {
                        $current = self::normalize_price_for_compare( $child->get_regular_price() );
                    } else if ( $k === 'sale_price' && method_exists( $child, 'get_sale_price' ) ) {
                        $current = self::normalize_price_for_compare( $child->get_sale_price() );
                    }
                    if ( $current !== $wanted[ $k ] ) {
                        return false;
                    }
                }
            }
            return true;
        }

        // Simple products.
        foreach ( $fields as $k ) {
            $current = '';
            if ( $k === 'regular_price' && method_exists( $product, 'get_regular_price' ) ) {
                $current = self::normalize_price_for_compare( $product->get_regular_price() );
            } else if ( $k === 'sale_price' && method_exists( $product, 'get_sale_price' ) ) {
                $current = self::normalize_price_for_compare( $product->get_sale_price() );
            }
            if ( $current !== $wanted[ $k ] ) {
                return false;
            }
        }
        return true;
    }

    public static function register_routes() {
        $namespaces = array( 'apai-brain/v1' );

        foreach ( $namespaces as $ns ) {
            register_rest_route(
                $ns,
                '/chat',
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'handle_chat' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/pending/clear',
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'handle_pending_clear' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/confirm',
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'handle_confirm' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/debug',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_debug' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/products/search',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_product_search' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/products/summary',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_product_summary' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/products/variations',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_product_variations' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/variations/apply',
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'handle_variations_apply' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/trace/excerpt',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_trace_excerpt' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );

            register_rest_route(
                $ns,
                '/trace/log',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_trace_log' ),
                    'permission_callback' => array( __CLASS__, 'permission_check_admin' ),
                )
            );

            register_rest_route(
                $ns,
                '/qa/run',
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'handle_qa_run' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                )
            );
        }
    }

    public static function permission_check() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    public static function permission_check_admin() {
        return current_user_can( 'manage_options' );
    }

    private static function get_scope_from_request( WP_REST_Request $request ) {
        $json = $request->get_json_params();
        $tab_id = '';
        $tab_instance = '';

        if ( is_array( $json ) ) {
            if ( ! empty( $json['tab_id'] ) ) { $tab_id = (string) $json['tab_id']; }
            if ( ! empty( $json['tab_instance'] ) ) { $tab_instance = (string) $json['tab_instance']; }
            // Back-compat: some builds send meta.*
            if ( isset( $json['meta'] ) && is_array( $json['meta'] ) ) {
                if ( $tab_id === '' && ! empty( $json['meta']['tab_id'] ) ) { $tab_id = (string) $json['meta']['tab_id']; }
                if ( $tab_instance === '' && ! empty( $json['meta']['tab_instance'] ) ) { $tab_instance = (string) $json['meta']['tab_instance']; }
            }
        }

        if ( $tab_id === '' ) {
            $q = $request->get_param( 'tab_id' );
            if ( ! empty( $q ) ) { $tab_id = (string) $q; }
        }
        if ( $tab_instance === '' ) {
            $q = $request->get_param( 'tab_instance' );
            if ( ! empty( $q ) ) { $tab_instance = (string) $q; }
        }

        if ( $tab_id === '' ) { $tab_id = 'default'; }
        if ( $tab_instance === '' ) { $tab_instance = '1'; }

        return array( $tab_id, $tab_instance );
    }

    private static function new_trace_id() {
        $tid = class_exists( 'APAI_Brain_Trace' ) ? APAI_Brain_Trace::new_trace_id() : ( 'apai_' . time() );
        if ( class_exists( 'APAI_Brain_Trace' ) ) {
            APAI_Brain_Trace::set_current_trace_id( $tid );
        }
        return $tid;
    }

    public static function handle_chat( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        list( $tab_id, $tab_instance ) = self::get_scope_from_request( $request );

        $tid = self::new_trace_id();

        $json = $request->get_json_params();
        $message = ( is_array( $json ) && isset( $json['message'] ) ) ? (string) $json['message'] : '';
        $history = ( is_array( $json ) && isset( $json['history'] ) && is_array( $json['history'] ) ) ? $json['history'] : array();

        $message_raw = $message;
        $message_norm = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::normalize_text( $message_raw ) : strtolower( trim( $message_raw ) );

        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );

        // Slot-filling helper (NO keywords / NO comandos):
        // If we previously asked a question (missing slots), we try to recover the missing
        // data from the user's next message (even if they answer in one sentence).
        if ( isset( $state['pending_question'] ) && is_array( $state['pending_question'] ) ) {
            $pq = $state['pending_question'];
            $missing = isset( $pq['missing'] ) && is_array( $pq['missing'] ) ? $pq['missing'] : array();
            $missing_lc = array_map( 'strtolower', $missing );

            // Extract numbers defensively (e.g. "producto 386 y precio 5000").
            $nums = array();
            if ( preg_match_all( '/\d+/', $message_raw, $mnums ) ) {
                $nums = array_map( 'intval', $mnums[0] );
            }

            $pid = null;
            $price = null;

            // Product id: prefer explicit "producto #123" then fallback to "#123".
            if ( preg_match( '/\bproducto\s*#?\s*(\d+)\b/i', $message_raw, $mm ) ) {
                $pid = intval( $mm[1] );
            } elseif ( preg_match( '/#\s*(\d+)\b/', $message_raw, $mm ) ) {
                $pid = intval( $mm[1] );
            }

            // Price: prefer explicit "precio 5000" or "$5000".
            if ( preg_match( '/\bprecio\b\s*[:=]?\s*\$?\s*([0-9.,]+)/i', $message_raw, $mm ) ) {
                $price = APAI_Brain_Normalizer::parse_price_number( $mm[1] );
            } elseif ( preg_match( '/\$\s*([0-9.,]+)/', $message_raw, $mm ) ) {
                $price = APAI_Brain_Normalizer::parse_price_number( $mm[1] );
            }

            // Heuristics when both product and price are in the same sentence without keywords.
            if ( $pid !== null && $price === null && count( $nums ) >= 2 ) {
                $price = intval( end( $nums ) );
            }
            if ( $pid === null && count( $nums ) >= 2 ) {
                // e.g. "386 5000" => first is product id, second is price.
                $pid = intval( $nums[0] );
                if ( $price === null ) {
                    $price = intval( $nums[1] );
                }
            }
            if ( $price === null && count( $nums ) === 1 && ( in_array( 'nuevo_precio', $missing_lc, true ) || in_array( 'precio', $missing_lc, true ) || in_array( 'price', $missing_lc, true ) || in_array( 'regular_price', $missing_lc, true ) ) ) {
                $price = intval( $nums[0] );
            }

            $need_price = in_array( 'price', $missing_lc, true ) || in_array( 'regular_price', $missing_lc, true ) || in_array( 'nuevo_precio', $missing_lc, true ) || in_array( 'precio', $missing_lc, true ) || in_array( 'new_price', $missing_lc, true );
            $need_product = in_array( 'product_id', $missing_lc, true ) || in_array( 'producto', $missing_lc, true ) || in_array( 'product', $missing_lc, true );

            // If we got everything we need, rewrite the message into a natural utterance and clear pending_question.
            if ( ( ! $need_product || $pid !== null ) && ( ! $need_price || $price !== null ) ) {
                if ( $pid !== null && $price !== null ) {
                    $message_raw = 'subí el precio del producto ' . $pid . ' a ' . $price;
                } elseif ( $pid !== null ) {
                    $message_raw = 'producto ' . $pid;
                } elseif ( $price !== null ) {
                    $message_raw = 'precio ' . $price;
                }
                $state['pending_question'] = null;
                APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, $state );
            } else {
                // Update pending_question with what is still missing (so we don't re-ask for already provided data).
                $new_missing = array();
                foreach ( $missing as $slot ) {
                    $slot_lc = strtolower( $slot );
                    if ( ( $slot_lc === 'producto' || $slot_lc === 'product' || $slot_lc === 'product_id' ) && $pid !== null ) {
                        continue;
                    }
                    if ( ( $slot_lc === 'precio' || $slot_lc === 'nuevo_precio' || $slot_lc === 'price' || $slot_lc === 'regular_price' || $slot_lc === 'new_price' ) && $price !== null ) {
                        continue;
                    }
                    $new_missing[] = $slot;
                }
                $state['pending_question']['missing'] = $new_missing;
                APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, $state );
            }
        }


        // GOLDEN RULE: cancel MUST be via button only (not by text).
        // We intentionally do NOT parse 'cancelar' text to avoid accidental cancellations.
        // If there is a pending action, text must NOT confirm it.
        if ( ! empty( $state['pending_action'] ) && is_array( $state['pending_action'] ) ) {
            if ( class_exists( 'APAI_Patterns' ) && APAI_Patterns::looks_like_text_confirm( $message_norm ) ) {
                $payload = APAI_Brain_Response_Builder::ok(
                    'Para ejecutar la acción pendiente, tocá **Confirmar** en la tarjeta.',
                    $state,
                    array( 'trace_id' => $tid )
                );
                return self::respond( $payload, $tid );
            }

			// If the user tries to cancel via text, DO NOT cancel.
			if ( class_exists( 'APAI_Patterns' ) && method_exists( 'APAI_Patterns', 'looks_like_text_cancel' ) && APAI_Patterns::looks_like_text_cancel( $message_norm ) ) {
				$payload = APAI_Brain_Response_Builder::ok(
					'Para cancelar la acción pendiente, tocá **Cancelar** en la tarjeta.',
					$state,
					array( 'trace_id' => $tid )
				);
				return self::respond( $payload, $tid );
			}
        }

        // Build Context Lite
        $context_lite = array(
            'summary'      => isset( $state['summary'] ) ? (string) $state['summary'] : '',
            'focus_entity' => isset( $state['focus_entity'] ) ? $state['focus_entity'] : null,
            'has_pending'  => ! empty( $state['pending_action'] ),
            'last_results' => isset( $state['last_results'] ) ? $state['last_results'] : null,
        );

        APAI_Brain_Trace::emit_current( 'chat_in', array( 'tab_id' => $tab_id, 'tab_instance' => $tab_instance ) );

        $plan = APAI_Brain_LLM::plan( $message_raw, $context_lite, $history );
        APAI_Brain_Trace::emit_current( 'llm_plan', array( 'plan' => $plan ) );

        $reply = isset( $plan['reply'] ) ? (string) $plan['reply'] : '';

        // Update summary (tiny, best-effort)
        if ( isset( $plan['kind'] ) && $plan['kind'] === 'answer' ) {
            // Keep previous summary.
        }

        
        // If the model asked a follow-up question, remember missing slots so we can accept short answers (e.g. "500").
        if ( isset( $plan['kind'] ) && $plan['kind'] === 'question' ) {
            $missing = isset( $plan['missing'] ) && is_array( $plan['missing'] ) ? $plan['missing'] : array();
            $state['pending_question'] = array(
                'missing' => array_values( $missing ),
                'ts'      => time(),
            );
            APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, $state );
            return self::respond( array(
                'ok' => true,
                'trace_id' => $tid,
                'reply' => $reply,
                'cards' => array(),
                'meta' => array(
                    'store_state' => APAI_Brain_Store::sanitize_for_debug( $state ),
                    'level' => 'lite',
                ),
            ) , $tid );
        }

// Draft action => create pending_action
        if ( isset( $plan['kind'] ) && $plan['kind'] === 'draft_action' && isset( $plan['action'] ) && is_array( $plan['action'] ) ) {
            $action = $plan['action'];
            $reply_append = '';

            // Normalize action schema for Agent compatibility (payload -> changes).
            if ( is_array( $action ) && isset( $action['payload'] ) && ! isset( $action['changes'] ) && is_array( $action['payload'] ) ) {
                $action['changes'] = $action['payload'];
                unset( $action['payload'] );
            }

            // Normalize common field aliases for Agent allowlist.
            // IMPORTANT: Agent typically allows "regular_price" but not "price".
            if ( isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
                if ( array_key_exists( 'price', $action['changes'] ) && ! array_key_exists( 'regular_price', $action['changes'] ) ) {
                    $action['changes']['regular_price'] = $action['changes']['price'];
                }
                if ( array_key_exists( 'price', $action['changes'] ) ) {
                    unset( $action['changes']['price'] );
                }
                if ( array_key_exists( 'regular_price', $action['changes'] ) ) {
                    $action['changes']['regular_price'] = (string) $action['changes']['regular_price'];
                }
                if ( array_key_exists( 'sale_price', $action['changes'] ) ) {
                    $action['changes']['sale_price'] = (string) $action['changes']['sale_price'];
                }
            }

            // Defensive validation BEFORE creating pending:
            // If the user referenced an invalid product id, we ask instead of creating a broken card.
            $atype = isset( $action['type'] ) ? (string) $action['type'] : '';

            // Normalize a common variable-variation intent into a Brain-supported action.
            // Some models output update_product with changes.variations={variation_id: price}.
            // The Agent does not allow "variations" as a field, so we translate to update_variations,
            // which is executed deterministically at confirm-time as multiple update_product calls.
            if ( $atype === 'update_product' && isset( $action['changes'] ) && is_array( $action['changes'] ) && isset( $action['changes']['variations'] ) && is_array( $action['changes']['variations'] ) ) {
                $action = array(
                    'type' => 'update_variations',
                    'human_summary' => isset( $action['human_summary'] ) ? $action['human_summary'] : 'Actualizar precios de variaciones.',
                    'product_id' => isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0,
                    'variation_prices' => $action['changes']['variations'],
                    'risk' => isset( $action['risk'] ) ? $action['risk'] : 'medium',
                );
                $atype = 'update_variations';
            }
            // If the model tries a bulk update of variations, normalize to a Brain-supported action.
            // The Agent may not support bulk_update directly, so we execute it deterministically in confirm by
            // translating to multiple update_product calls for each variation.
            if ( $atype === 'bulk_update' && isset( $action['changes'] ) && is_array( $action['changes'] ) && isset( $action['changes']['variaciones'] ) && is_array( $action['changes']['variaciones'] ) ) {
                $action = array(
                    'type' => 'update_variations',
                    'human_summary' => isset( $action['human_summary'] ) ? $action['human_summary'] : 'Actualizar precios de variaciones.',
                    'product_id' => isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0,
                    'variation_prices' => $action['changes']['variaciones'],
                    'risk' => isset( $action['risk'] ) ? $action['risk'] : 'medium',
                );
                $atype = 'update_variations';
            }


            if ( $atype === 'update_product' ) {
                $pid = isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0;
                if ( $pid <= 0 ) {
                    $payload = APAI_Brain_Response_Builder::ok(
                        '¿Qué producto querés modificar? Decime el **ID** o el nombre, y si querés puedo buscarlo por título.',
                        $state,
                        array( 'trace_id' => $tid )
                    );
                    return self::respond( $payload, $tid );
                }
                if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $pid ) ) {
                    $payload = APAI_Brain_Response_Builder::ok(
                        'No encuentro el producto #' . $pid . '. ¿Me pasás un ID válido o el nombre del producto?',
                        $state,
                        array( 'trace_id' => $tid )
                    );
                    return self::respond( $payload, $tid );
                }

                // NO-OP detection and variable UX helpers.
                $p = wc_get_product( $pid );
                if ( $p && isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
                    if ( self::is_noop_update_product( $p, $action['changes'] ) ) {
                        $ptype = method_exists( $p, 'get_type' ) ? (string) $p->get_type() : '';
                        $current_r = method_exists( $p, 'get_regular_price' ) ? (string) $p->get_regular_price() : '';
                        $current_s = method_exists( $p, 'get_sale_price' ) ? (string) $p->get_sale_price() : '';

                        $msg = 'Listo: el producto #' . $pid . ' **ya tiene** ese precio.';
                        if ( $ptype === 'variable' ) {
                            $msg = 'Listo: este producto es **variable** y sus variaciones **ya tienen** ese precio.';
                        }
                        // Add a helpful next step.
                        $extras = array();
                        if ( isset( $action['changes']['regular_price'] ) ) {
                            $disp = ( $ptype === 'variable' ) ? self::variable_price_display( $p ) : ( '$' . self::normalize_price_for_compare( $current_r ) );
                            if ( $disp === '$' || $disp === '' ) { $disp = '$' . self::normalize_price_for_compare( $current_r ); }
                            $extras[] = 'Precio actual: **' . $disp . '**';
                        }
                        if ( isset( $action['changes']['sale_price'] ) ) {
                            $extras[] = 'Oferta actual: **$' . self::normalize_price_for_compare( $current_s ) . '**';
                        }
                        if ( ! empty( $extras ) ) {
                            $msg .= ' (' . implode( ' · ', $extras ) . ')';
                        }
                        $msg .= "\n\nSi querés, puedo ayudarte a cambiarlo a otro valor, ajustar **oferta**, **stock** o **variaciones**.";

                        $payload = APAI_Brain_Response_Builder::ok(
                            $msg,
                            $state,
                            array( 'trace_id' => $tid, 'should_clear_pending' => true )
                        );
                        return self::respond( $payload, $tid );
                    }
                }

                // Variation selector UX (GOLDEN RULE): selection is via UI, confirm stays a button.
                // POLICY: for variable products, ALWAYS show the selector for price/sale changes
                // (unless it is a true NO-OP already handled above).
                // We intentionally re-fetch the product to avoid any scope/edge issues.
                $p_var = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
                if ( $p_var && method_exists( $p_var, 'get_type' ) && (string) $p_var->get_type() === 'variable' ) {
                    $has_price_change = false;
                    if ( isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
                        // Accept both canonical and common aliases, but keep the action unchanged here.
                        $has_price_change = array_key_exists( 'regular_price', $action['changes'] )
                            || array_key_exists( 'sale_price', $action['changes'] )
                            || array_key_exists( 'price', $action['changes'] )
                            || array_key_exists( 'offer', $action['changes'] );
                    }
                    if ( $has_price_change ) {
                        $vars = class_exists( 'APAI_Brain_Product_Search' ) ? APAI_Brain_Product_Search::variations( $pid, 80, 0 ) : array( 'items' => array(), 'total' => 0 );
                        $items = ( is_array( $vars ) && isset( $vars['items'] ) && is_array( $vars['items'] ) ) ? $vars['items'] : array();
                        $total = ( is_array( $vars ) && isset( $vars['total'] ) ) ? (int) $vars['total'] : count( $items );

                        $changes = isset( $action['changes'] ) && is_array( $action['changes'] ) ? $action['changes'] : array();
                        // Normalize minimal aliases for display + downstream apply.
                        if ( array_key_exists( 'price', $changes ) && ! array_key_exists( 'regular_price', $changes ) ) {
                            $changes['regular_price'] = (string) $changes['price'];
                        }
                        if ( array_key_exists( 'offer', $changes ) && ! array_key_exists( 'sale_price', $changes ) ) {
                            $changes['sale_price'] = (string) $changes['offer'];
                        }
                        unset( $changes['price'] );
                        unset( $changes['offer'] );

                        $sel = array(
                            'kind'       => 'variation_selector',
                            'product_id'  => $pid,
                            'changes'    => $changes,
                            'asked_at'   => time(),
                            'total'      => $total,
                            'candidates' => $items,
                        );

                        APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, array(
                            'pending_action' => null,
                            'pending_target_selection' => $sel,
                        ) );
                        $state2 = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );

                        $rp = isset( $changes['regular_price'] ) ? (string) $changes['regular_price'] : '';
                        $sp = isset( $changes['sale_price'] ) ? (string) $changes['sale_price'] : '';
                        $msg = "Este producto es **variable**. Elegí a qué variaciones querés aplicar el cambio.";
                        if ( $rp !== '' && $sp !== '' ) {
                            $msg .= "\n\nCambio propuesto: **Precio $" . self::normalize_price_for_compare( $rp ) . "** y **Oferta $" . self::normalize_price_for_compare( $sp ) . "**.";
                        } else if ( $rp !== '' ) {
                            $msg .= "\n\nCambio propuesto: **Precio $" . self::normalize_price_for_compare( $rp ) . "**.";
                        } else if ( $sp !== '' ) {
                            $msg .= "\n\nCambio propuesto: **Oferta $" . self::normalize_price_for_compare( $sp ) . "**.";
                        }
                        $msg .= "\n\nUsá la tarjeta para **seleccionar variaciones** y tocá *Aplicar a seleccionadas* (o *Aplicar a todas*). Después te voy a pedir confirmación con el botón.";

                        $payload = APAI_Brain_Response_Builder::ok( $msg, $state2, array( 'trace_id' => $tid ) );
                        return self::respond( $payload, $tid );
                    }
                }
            }

            $created = time();
            $ttl = 10 * 60;
            $pending = array(
                'id'         => 'pa_' . substr( md5( uniqid( '', true ) ), 0, 10 ),
                'created_at' => $created,
                'expires_at' => $created + $ttl,
                'action'     => $action,
            );
            APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, array(
                'pending_action' => $pending,
            ) );
            $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );

            // If the model forgot to include the safety sentence, we add it.
            if ( $reply === '' ) {
                $reply = 'Ok. Te dejo la acción preparada. Revisala y tocá Confirmar para ejecutarla.';
            }

            if ( $reply_append !== '' ) {
                $reply .= $reply_append;
            }

            $payload = APAI_Brain_Response_Builder::ok( $reply, $state, array( 'trace_id' => $tid ) );
            return self::respond( $payload, $tid );
        }

        // Default: just answer
        if ( $reply === '' ) {
            $reply = 'Ok. ¿Qué querés hacer con tu tienda?';
        }

        $payload = APAI_Brain_Response_Builder::ok( $reply, $state, array( 'trace_id' => $tid ) );
        return self::respond( $payload, $tid );
    }

    public static function handle_pending_clear( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        list( $tab_id, $tab_instance ) = self::get_scope_from_request( $request );
        $tid = self::new_trace_id();

        APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, array(
            'pending_action' => null,
            'pending_target_selection' => null,
        ) );
        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );
        $payload = APAI_Brain_Response_Builder::ok( 'OK', $state, array( 'trace_id' => $tid, 'should_clear_pending' => true ) );
        return self::respond( $payload, $tid );
    }

    /**
     * Confirm pending_action (ONLY via button) and execute deterministically via Agent.
     *
     * GOLDEN RULE: This endpoint is the only way to execute. Text can never confirm.
     */
    public static function handle_confirm( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        list( $tab_id, $tab_instance ) = self::get_scope_from_request( $request );
        $tid = self::new_trace_id();

        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );
        $pending = ( isset( $state['pending_action'] ) && is_array( $state['pending_action'] ) ) ? $state['pending_action'] : null;

        if ( ! $pending || empty( $pending['action'] ) || ! is_array( $pending['action'] ) ) {
            return self::respond( array( 'ok' => false, 'code' => 'no_pending', 'message' => 'No hay ninguna acción pendiente para confirmar.', 'trace_id' => $tid ), $tid, 400 );
        }

        $json = $request->get_json_params();
        $requested_id = ( is_array( $json ) && ! empty( $json['pending_action_id'] ) ) ? (string) $json['pending_action_id'] : '';
        $pending_id = isset( $pending['id'] ) ? (string) $pending['id'] : '';

        if ( $requested_id !== '' && $pending_id !== '' && $requested_id !== $pending_id ) {
            return self::respond( array( 'ok' => false, 'code' => 'pending_mismatch', 'message' => 'La acción pendiente cambió. Volvé a intentar.', 'trace_id' => $tid ), $tid, 409 );
        }

        // Defensive: validate target exists before executing.
        $action = $pending['action'];
        // Back-compat: normalize action schema for Agent validator/executor.
        if ( is_array( $action ) && isset( $action['payload'] ) && ! isset( $action['changes'] ) && is_array( $action['payload'] ) ) {
            $action['changes'] = $action['payload'];
            unset( $action['payload'] );
        }

        // Back-compat / usability: many models output "price" instead of "regular_price".
        // The Agent allowlist typically accepts regular_price, not price.
        if ( is_array( $action ) && isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
            if ( array_key_exists( 'price', $action['changes'] ) && ! array_key_exists( 'regular_price', $action['changes'] ) ) {
                $action['changes']['regular_price'] = $action['changes']['price'];
            }
            // Always remove unsupported aliases.
            if ( array_key_exists( 'price', $action['changes'] ) ) {
                unset( $action['changes']['price'] );
            }
            // Normalize to strings for Woo/Agent compatibility.
            if ( array_key_exists( 'regular_price', $action['changes'] ) ) {
                $action['changes']['regular_price'] = (string) $action['changes']['regular_price'];
            }
            if ( array_key_exists( 'sale_price', $action['changes'] ) ) {
                $action['changes']['sale_price'] = (string) $action['changes']['sale_price'];
            }
        }

        // Normalize "update_product" actions that include a variations map.
        // Some plans use changes.variations={variation_id: price}, but the Agent does not allow
        // a "variations" field. We translate to the Brain-supported update_variations action,
        // executed deterministically below as multiple update_product calls.
                // Normalize "bulk_update" actions that include a variations map (LLM sometimes uses bulk_update).
        if ( isset( $action['type'] ) && (string) $action['type'] === 'bulk_update' && isset( $action['changes'] ) && is_array( $action['changes'] ) && isset( $action['changes']['variations'] ) && is_array( $action['changes']['variations'] ) ) {
            $action = array(
                'type' => 'update_variations',
                'human_summary' => isset( $action['human_summary'] ) ? $action['human_summary'] : 'Actualizar precios de variaciones.',
                'product_id' => isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0,
                'variation_prices' => $action['changes']['variations'],
                'risk' => isset( $action['risk'] ) ? $action['risk'] : 'medium',
            );
        }

if ( isset( $action['type'] ) && (string) $action['type'] === 'update_product' && isset( $action['changes'] ) && is_array( $action['changes'] ) && isset( $action['changes']['variations'] ) && is_array( $action['changes']['variations'] ) ) {
            $action = array(
                'type' => 'update_variations',
                'human_summary' => isset( $action['human_summary'] ) ? $action['human_summary'] : 'Actualizar precios de variaciones.',
                'product_id' => isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0,
                'variation_prices' => $action['changes']['variations'],
                'risk' => isset( $action['risk'] ) ? $action['risk'] : 'medium',
            );
            $type = 'update_variations';
        }
        $type = isset( $action['type'] ) ? (string) $action['type'] : '';
        // Brain-supported action: update specific variations with specific prices.
        // The Agent may not have a dedicated bulk action, so we deterministically translate it to multiple
        // update_product actions on variation IDs.
        if ( $type === 'update_variations' ) {
            $pid = isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0;
            if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
                return self::respond( array( 'ok' => false, 'code' => 'not_found', 'message' => 'No encuentro el producto #' . $pid . '.', 'trace_id' => $tid ), $tid, 404 );
            }
            $p = wc_get_product( $pid );
            if ( ! $p || ! method_exists( $p, 'get_type' ) || (string) $p->get_type() !== 'variable' ) {
                return self::respond( array( 'ok' => false, 'code' => 'not_variable', 'message' => 'Este producto no es variable, así que no puedo actualizar variaciones.', 'trace_id' => $tid ), $tid, 400 );
            }
            if ( empty( $action['variation_prices'] ) || ! is_array( $action['variation_prices'] ) ) {
                return self::respond( array( 'ok' => false, 'code' => 'missing_variations', 'message' => 'No hay variaciones para actualizar.', 'trace_id' => $tid ), $tid, 400 );
            }
        }



        if ( $type === 'update_product' ) {
            $pid = isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0;
            if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $pid ) ) {
                return self::respond( array( 'ok' => false, 'code' => 'not_found', 'message' => 'No encuentro el producto #' . $pid . '. Cancelá la acción o elegí otro producto.', 'trace_id' => $tid ), $tid, 404 );
            }
        }

        if ( ! class_exists( 'APAI_Agent_Validator' ) || ! class_exists( 'APAI_Agent_Executor' ) ) {
            return self::respond( array( 'ok' => false, 'code' => 'agent_missing', 'message' => 'No puedo ejecutar porque el Agente de Catálogo no está activo.', 'trace_id' => $tid ), $tid, 503 );
        }

        // Build session_state for Agent. Keep deterministic.
        $session_state = array();
        if ( is_array( $json ) && isset( $json['session_state'] ) && is_array( $json['session_state'] ) ) {
            $session_state = $json['session_state'];
        }
        // Provide store_state (Brain) in case Agent wants it.
        $session_state['brain_store_state'] = $state;

        // Validate + execute.
        // Special case: variable products should apply price to variations (through Agent, deterministically).
        $result = null;
        if ( is_array( $action ) && isset( $action['type'] ) && $action['type'] === 'update_product' ) {
            $pid = isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0;
            $p = ( $pid > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $pid ) : null;
            $ptype = ( $p && method_exists( $p, 'get_type' ) ) ? (string) $p->get_type() : '';

            if ( $p && $ptype === 'variable' && method_exists( $p, 'get_children' ) ) {
                $children = $p->get_children();
                if ( ! is_array( $children ) ) {
                    $children = array();
                }

                $ok_count = 0;
                foreach ( $children as $child_id ) {
                    $child_id = absint( $child_id );
                    if ( $child_id <= 0 ) { continue; }

                    $child_action = $action;
                    $child_action['product_id'] = $child_id;

                    $validated = APAI_Agent_Validator::validate_confirmed_action( $child_action );
                    if ( is_wp_error( $validated ) ) {
                        return self::respond( array( 'ok' => false, 'code' => $validated->get_error_code(), 'message' => $validated->get_error_message(), 'trace_id' => $tid ), $tid, 400 );
                    }
                    $child_result = APAI_Agent_Executor::execute( $validated, $session_state );
                    if ( is_wp_error( $child_result ) ) {
                        return self::respond( array( 'ok' => false, 'code' => $child_result->get_error_code(), 'message' => $child_result->get_error_message(), 'trace_id' => $tid ), $tid, 500 );
                    }
                    if ( is_array( $child_result ) && ! empty( $child_result['ok'] ) ) {
                        $ok_count++;
                    }
                }

                $result = array(
                    'ok' => true,
                    'mode' => 'variable_apply_to_variations',
                    'variations_updated' => $ok_count,
                );
            }
        }


        // Execute update_variations by translating to multiple update_product calls on variation IDs.
        if ( $result === null && $type === 'update_variations' ) {
            $ok_count = 0;
            $errors = array();
            foreach ( $action['variation_prices'] as $k => $v ) {
                // Accept keys like "#387" or "387"
                $vid = 0;
                if ( is_string( $k ) ) {
                    $vid = absint( preg_replace( '/[^0-9]/', '', $k ) );
                } else {
                    $vid = absint( $k );
                }
                if ( $vid <= 0 ) { continue; }

                $price = (string) $v;
                $child_action = array(
                    'type' => 'update_product',
                    'product_id' => $vid,
                    'changes' => array(
                        'regular_price' => $price,
                    ),
                    'risk' => 'medium',
                );

                $validated = APAI_Agent_Validator::validate_confirmed_action( $child_action );
                if ( is_wp_error( $validated ) ) {
                    $errors[] = $validated->get_error_message();
                    continue;
                }
                $child_result = APAI_Agent_Executor::execute( $validated, $session_state );
                if ( is_wp_error( $child_result ) ) {
                    $errors[] = $child_result->get_error_message();
                    continue;
                }
                if ( is_array( $child_result ) && ! empty( $child_result['ok'] ) ) {
                    $ok_count++;
                }
            }

            if ( ! empty( $errors ) && $ok_count === 0 ) {
                return self::respond( array( 'ok' => false, 'code' => 'variations_failed', 'message' => $errors[0], 'trace_id' => $tid ), $tid, 500 );
            }

            $result = array(
                'ok' => true,
                'mode' => 'update_variations',
                'variations_updated' => $ok_count,
            );
        }

        if ( $result === null ) {
            $validated = APAI_Agent_Validator::validate_confirmed_action( $action );
            if ( is_wp_error( $validated ) ) {
                return self::respond( array( 'ok' => false, 'code' => $validated->get_error_code(), 'message' => $validated->get_error_message(), 'trace_id' => $tid ), $tid, 400 );
            }

            $result = APAI_Agent_Executor::execute( $validated, $session_state );
            if ( is_wp_error( $result ) ) {
                return self::respond( array( 'ok' => false, 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'trace_id' => $tid ), $tid, 500 );
            }
        }

        // Post-check: verify that the expected change actually took effect in WooCommerce.
        // This catches common cases like variable products (prices on variations) or silent no-ops.
        if ( is_array( $action ) && isset( $action['type'] ) && $action['type'] === 'update_product' ) {
            $pid = isset( $action['product_id'] ) ? absint( $action['product_id'] ) : 0;
            $wanted = null;
            if ( isset( $action['changes'] ) && is_array( $action['changes'] ) && array_key_exists( 'regular_price', $action['changes'] ) ) {
                $wanted = (string) $action['changes']['regular_price'];
            }
            if ( $pid > 0 && $wanted !== null && function_exists( 'wc_get_product' ) ) {
                $p = wc_get_product( $pid );
                if ( $p ) {
                    $after = (string) $p->get_regular_price();
                    // Normalize decimals so comparisons are stable.
                    $after_norm = preg_replace( '/[^0-9.,-]/', '', $after );
                    $wanted_norm = preg_replace( '/[^0-9.,-]/', '', $wanted );
                    if ( $after_norm !== $wanted_norm ) {
                        $ptype = method_exists( $p, 'get_type' ) ? (string) $p->get_type() : '';
                        if ( ! is_array( $result ) ) { $result = array(); }
                        $result['warning'] = array(
                            'code' => 'postcheck_mismatch',
                            'message' => 'La acción se ejecutó, pero el precio visible no cambió. Esto suele pasar si el producto es variable (el precio está en las variaciones) o si WooCommerce rechazó el valor.',
                            'product_type' => $ptype,
                            'wanted_regular_price' => $wanted,
                            'observed_regular_price' => $after,
                        );
                    }
                }
            }
        }

        // Clear pending on success.
        if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
            APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, array(
                'pending_action' => null,
                'pending_target_selection' => null,
            ) );
            $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );
        }

        // Normalize response shape for the UI.
        if ( is_array( $result ) ) {
            $result['trace_id'] = $tid;
            // Keep Brain store_state for UI re-render.
            $result['store_state'] = $state;

            // Ensure a stable reply field for the admin-agent/* UI.
            if ( empty( $result['reply'] ) ) {
                if ( ! empty( $result['ok'] ) ) {
                    $result['reply'] = '✅ Acción ejecutada correctamente.';
                } else if ( ! empty( $result['message'] ) ) {
                    $result['reply'] = (string) $result['message'];
                }
            }

            // If post-check detected a mismatch, make it visible in the reply.
            if ( ! empty( $result['warning'] ) && is_array( $result['warning'] ) && ! empty( $result['warning']['message'] ) ) {
                $result['reply'] = '⚠️ ' . (string) $result['warning']['message'];
            }
        }

        return self::respond( $result, $tid );
    }

    public static function handle_debug( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        list( $tab_id, $tab_instance ) = self::get_scope_from_request( $request );
        $tid = self::new_trace_id();

        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );
        $level = (string) $request->get_param( 'level' );
        if ( $level === '' ) { $level = 'lite'; }

        $out = array(
            'ok' => true,
            'trace_id' => $tid,
            'store_state' => $state,
            'level' => $level,
        );

        if ( $level === 'full' ) {
            $out['trace_events'] = class_exists( 'APAI_Brain_Trace' ) ? APAI_Brain_Trace::buffer_get( $tid ) : array();
        }

        return self::respond( $out, $tid );
    }

    public static function handle_product_search( WP_REST_Request $request ) {
        $tid = self::new_trace_id();
        $q = (string) $request->get_param( 'q' );
        $page = max( 1, intval( $request->get_param( 'page' ) ) );
        $per_page = max( 1, min( 50, intval( $request->get_param( 'per_page' ) ) ) );

        $result = class_exists( 'APAI_Brain_Product_Search' ) ? APAI_Brain_Product_Search::search( $q, $page, $per_page ) : array();
        if ( ! is_array( $result ) ) {
            $result = array();
        }
        $result['trace_id'] = $tid;
        $result['ok'] = true;

        return self::respond( $result, $tid );
    }

    public static function handle_product_summary( WP_REST_Request $request ) {
        $tid = self::new_trace_id();
        $id = intval( $request->get_param( 'id' ) );
        if ( $id <= 0 ) {
            return self::respond( array( 'ok' => false, 'message' => 'Missing id', 'trace_id' => $tid ), $tid, 400 );
        }
        $summary = class_exists( 'APAI_Brain_Product_Search' ) ? APAI_Brain_Product_Search::summary( $id ) : null;
        if ( ! $summary ) {
            return self::respond( array( 'ok' => false, 'message' => 'Not found', 'trace_id' => $tid ), $tid, 404 );
        }
        return self::respond( array( 'ok' => true, 'trace_id' => $tid, 'product' => $summary ), $tid );
    }

    /**
     * Read-only: list variations for a variable product.
     * UI uses this to render the variation selector card.
     */
    public static function handle_product_variations( WP_REST_Request $request ) {
        $tid = self::new_trace_id();
        $product_id = absint( $request->get_param( 'product_id' ) );
        $limit = max( 1, min( 200, intval( $request->get_param( 'limit' ) ) ) );
        $offset = max( 0, intval( $request->get_param( 'offset' ) ) );

        if ( $product_id <= 0 ) {
            return self::respond( array( 'ok' => false, 'message' => 'Missing product_id', 'trace_id' => $tid ), $tid, 400 );
        }

        $out = class_exists( 'APAI_Brain_Product_Search' ) ? APAI_Brain_Product_Search::variations( $product_id, $limit, $offset ) : array();
        if ( ! is_array( $out ) ) { $out = array(); }
        $out['ok'] = true;
        $out['trace_id'] = $tid;
        return self::respond( $out, $tid );
    }

    /**
     * Apply a variation selection (UI button).
     * Converts pending_target_selection(kind=variation_scope) into a server-side pending_action.
     * GOLDEN RULE: confirmation still happens ONLY via Confirm button.
     */
    public static function handle_variations_apply( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        list( $tab_id, $tab_instance ) = self::get_scope_from_request( $request );
        $tid = self::new_trace_id();

        $json = $request->get_json_params();
        $selected_ids = array();
        $apply_all = false;
        if ( is_array( $json ) ) {
            if ( ! empty( $json['apply_all'] ) ) { $apply_all = (bool) $json['apply_all']; }
            if ( isset( $json['selected_ids'] ) && is_array( $json['selected_ids'] ) ) {
                $selected_ids = $json['selected_ids'];
            }
        }

        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );
        $sel = isset( $state['pending_target_selection'] ) ? $state['pending_target_selection'] : null;
        if ( ! is_array( $sel ) || ( isset( $sel['kind'] ) ? (string) $sel['kind'] : '' ) !== 'variation_selector' ) {
            $payload = APAI_Brain_Response_Builder::error( 'No hay un selector de variaciones activo.', 'no_variation_selector', $state, array( 'trace_id' => $tid ) );
            return self::respond( $payload, $tid, 400 );
        }

        $product_id = isset( $sel['product_id'] ) ? absint( $sel['product_id'] ) : 0;
        $changes = isset( $sel['changes'] ) && is_array( $sel['changes'] ) ? $sel['changes'] : array();

        if ( $product_id <= 0 ) {
            $payload = APAI_Brain_Response_Builder::error( 'Selector inválido (product_id).', 'bad_selector', $state, array( 'trace_id' => $tid ) );
            return self::respond( $payload, $tid, 400 );
        }

        // Determine the final list of variation ids.
        $variation_ids = array();
        if ( $apply_all ) {
            $v = class_exists( 'APAI_Brain_Product_Search' ) ? APAI_Brain_Product_Search::variations( $product_id, 200, 0 ) : array();
            if ( is_array( $v ) && ! empty( $v['items'] ) && is_array( $v['items'] ) ) {
                foreach ( $v['items'] as $it ) {
                    if ( isset( $it['id'] ) ) {
                        $variation_ids[] = absint( $it['id'] );
                    }
                }
            }
        } else {
            foreach ( $selected_ids as $sid ) {
                $variation_ids[] = absint( $sid );
            }
        }
        $variation_ids = array_values( array_filter( array_unique( $variation_ids ) ) );

        if ( empty( $variation_ids ) ) {
            // Keep selector open; user can pick.
            $payload = APAI_Brain_Response_Builder::ok(
                'No seleccionaste variaciones. Marcá al menos una o tocá **Aplicar a todas**.',
                $state,
                array( 'trace_id' => $tid )
            );
            return self::respond( $payload, $tid );
        }

        // Build a deterministic bulk_update action for the Agent (4.4.3+).
        $bulk_changes = array();
        foreach ( $variation_ids as $vid ) {
            if ( $vid <= 0 ) { continue; }
            $bulk_changes[ (string) $vid ] = array();
            if ( array_key_exists( 'regular_price', $changes ) ) {
                $bulk_changes[ (string) $vid ]['regular_price'] = (string) $changes['regular_price'];
            }
            if ( array_key_exists( 'sale_price', $changes ) ) {
                $bulk_changes[ (string) $vid ]['sale_price'] = (string) $changes['sale_price'];
            }
        }

        $summary_bits = array();
        if ( isset( $changes['regular_price'] ) ) { $summary_bits[] = 'precio a $' . self::normalize_price_for_compare( $changes['regular_price'] ); }
        if ( isset( $changes['sale_price'] ) ) { $summary_bits[] = 'oferta a $' . self::normalize_price_for_compare( $changes['sale_price'] ); }
        $hs = 'Aplicar ' . implode( ' y ', $summary_bits ) . ' en ' . count( $variation_ids ) . ' ' . ( count( $variation_ids ) === 1 ? 'variación' : 'variaciones' ) . '.';
        if ( empty( $summary_bits ) ) {
            $hs = 'Actualizar ' . count( $variation_ids ) . ' ' . ( count( $variation_ids ) === 1 ? 'variación' : 'variaciones' ) . '.';
        }

        $created = time();
        $ttl = 10 * 60;
        $pending = array(
            'id'         => 'pa_' . substr( md5( uniqid( '', true ) ), 0, 10 ),
            'created_at' => $created,
            'expires_at' => $created + $ttl,
            'action'     => array(
                'type' => 'bulk_update',
                'human_summary' => $hs,
                'product_id' => $product_id,
                'changes' => $bulk_changes,
                'risk' => 'low',
            ),
        );

        APAI_Brain_Store::patch( $user_id, $tab_id, $tab_instance, array(
            'pending_action' => $pending,
            'pending_target_selection' => null,
        ) );
        $state = APAI_Brain_Store::get( $user_id, $tab_id, $tab_instance );

        $payload = APAI_Brain_Response_Builder::ok(
            'Perfecto. Preparé la acción para **' . count( $variation_ids ) . '** ' . ( count( $variation_ids ) === 1 ? 'variación' : 'variaciones' ) . '. Tocá **Confirmar** para ejecutarla (o escribí “cancelar”).',
            $state,
            array( 'trace_id' => $tid )
        );
        return self::respond( $payload, $tid );
    }

    public static function handle_trace_excerpt( WP_REST_Request $request ) {
        $tid = self::new_trace_id();
        $trace_id = (string) $request->get_param( 'trace_id' );
        if ( $trace_id === '' ) {
            $trace_id = $tid;
        }

        // Prefer persisted trace excerpts (tail of the trace log) so that the UI can copy a useful
        // "TRACER" section like the legacy brain. Fallback to the in-memory buffer when needed.
        $mode = (string) $request->get_param( 'mode' );
        if ( $mode === '' ) {
            $mode = 'tail';
        }
        $lines = intval( $request->get_param( 'lines' ) );
        if ( $lines <= 0 ) {
            $lines = 200;
        }

        $payload = null;
        if ( class_exists( 'APAI_Brain_Trace' ) && method_exists( 'APAI_Brain_Trace', 'excerpt_by_trace_ids' ) ) {
            // Max bytes aligns with legacy behavior (512 KiB tail).
            $max_bytes = 524288;
            $ex = APAI_Brain_Trace::excerpt_by_trace_ids( array( $trace_id ), $lines, $max_bytes );
            // Keep "events" key for backwards compatibility with existing JS.
            $payload = array(
                'ok' => true,
                // Return the requested trace id (the excerpt is keyed by it).
                'trace_id' => $trace_id,
                'mode' => $mode,
                'meta' => isset( $ex['meta'] ) ? $ex['meta'] : array(),
                'events' => isset( $ex['lines'] ) ? $ex['lines'] : array(),
            );
        }

        if ( $payload === null ) {
            $events = class_exists( 'APAI_Brain_Trace' ) ? APAI_Brain_Trace::buffer_get( $trace_id ) : array();
            $payload = array( 'ok' => true, 'trace_id' => $trace_id, 'mode' => 'buffer', 'meta' => array(), 'events' => $events );
        }

        return self::respond( $payload, $tid );
    }


    public static function handle_trace_log( WP_REST_Request $request ) {
        $tid = self::new_trace_id();

        if ( ! class_exists( 'APAI_Brain_Trace' ) || ! method_exists( 'APAI_Brain_Trace', 'full_trace_log_lines' ) ) {
            return self::respond( array( 'ok' => false, 'trace_id' => $tid, 'message' => 'Trace log no disponible.' ), $tid, 503 );
        }

        $max_lines = absint( $request->get_param( 'max_lines' ) );
        if ( $max_lines <= 0 ) {
            $max_lines = 2000;
        }
        if ( $max_lines > 5000 ) {
            $max_lines = 5000;
        }

        $out = APAI_Brain_Trace::full_trace_log_lines( $max_lines );
        if ( ! is_array( $out ) ) {
            $out = array( 'ok' => true, 'file' => '', 'lines' => array(), 'meta' => array( 'warning' => 'bad_response' ) );
        }

        $payload = array(
            'ok' => true,
            'trace_id' => $tid,
            'trace_file' => isset( $out['file'] ) ? (string) $out['file'] : '',
            'meta' => isset( $out['meta'] ) && is_array( $out['meta'] ) ? $out['meta'] : array(),
            'lines' => isset( $out['lines'] ) && is_array( $out['lines'] ) ? $out['lines'] : array(),
        );

        if ( isset( $out['error'] ) ) {
            $payload['message'] = 'No se pudo leer trace.log en este momento.';
            $payload['meta']['error'] = (string) $out['error'];
        }

        return self::respond( $payload, $tid );
    }

    public static function handle_qa_run( WP_REST_Request $request ) {
        $tid = self::new_trace_id();

        $checks = array();
        $checks[] = array( 'name' => 'woocommerce_active', 'ok' => class_exists( 'WooCommerce' ) );
        $checks[] = array( 'name' => 'agent_active', 'ok' => ( class_exists( 'APAI_Agent_REST' ) || defined( 'APAI_AGENT_VERSION' ) ) );
        $checks[] = array( 'name' => 'rest_chat_registered', 'ok' => true );

        $ok = true;
        foreach ( $checks as $c ) {
            if ( empty( $c['ok'] ) ) { $ok = false; }
        }

        return self::respond( array( 'ok' => true, 'trace_id' => $tid, 'qa' => array( 'overall' => $ok, 'checks' => $checks ) ), $tid );
    }

    private static function respond( $payload, $trace_id = "", $status = 200 ) {
        $res = new WP_REST_Response( $payload, $status );
        $res->header( 'X-APAI-Trace-Id', (string) $trace_id );
        return $res;
    }
}