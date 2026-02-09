<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side store for the Brain (per blog_id + user_id + tab scope).
 *
 * Keeps state small and deterministic. Uses wp_options for simplicity.
 *
 * State shape (minimal):
 * - summary: string
 * - focus_entity: {type,id,label} | null
 * - last_results: array<{id,label}> | null
 * - pending_action: {created_at,expires_at,action:{...}} | null
 * - pending_target_selection: {...} | null (optional)
 */
class APAI_Brain_Store {

    public static function key( $user_id, $tab_id = 'default', $tab_instance = '1' ) {
        $blog_id = function_exists( 'get_current_blog_id' ) ? intval( get_current_blog_id() ) : 1;
        $user_id = intval( $user_id );

        $tab_id = is_string( $tab_id ) && $tab_id !== '' ? $tab_id : 'default';
        $tab_instance = is_string( $tab_instance ) && $tab_instance !== '' ? $tab_instance : '1';

        // Sanitize to keep option keys safe.
        $tab_id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tab_id );
        $tab_instance = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tab_instance );

        return 'apai_brain_state_' . $blog_id . '_' . $user_id . '_' . $tab_id . '_' . $tab_instance;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get( $user_id, $tab_id = 'default', $tab_instance = '1' ) {
        $state = get_option( self::key( $user_id, $tab_id, $tab_instance ), null );
        if ( ! is_array( $state ) ) {
            $state = self::default_state();
        }

        // Auto-expire pending action (best-effort).
        if ( isset( $state['pending_action'] ) && is_array( $state['pending_action'] ) ) {
            $exp = isset( $state['pending_action']['expires_at'] ) ? intval( $state['pending_action']['expires_at'] ) : 0;
            if ( $exp > 0 && time() > $exp ) {
                $state['pending_action'] = null;
            }
        }

        return $state;
    }

    /**
     * Patch state.
     *
     * @param int $user_id
     * @param string $tab_id
     * @param string $tab_instance
     * @param array<string,mixed> $patch
     */
    public static function patch( $user_id, $tab_id, $tab_instance, $patch ) {
        if ( ! is_array( $patch ) ) {
            return;
        }
        $state = self::get( $user_id, $tab_id, $tab_instance );
        foreach ( $patch as $k => $v ) {
            $state[ $k ] = $v;
        }
        $state['last_updated_at_ms'] = (int) round( microtime( true ) * 1000 );
        update_option( self::key( $user_id, $tab_id, $tab_instance ), $state, false );
    }

    public static function clear( $user_id, $tab_id, $tab_instance ) {
        delete_option( self::key( $user_id, $tab_id, $tab_instance ) );
    }

    /**
     * @return array<string,mixed>
     */
    public static function default_state() {
        return array(
            'summary'                 => '',
            'focus_entity'            => null,
            'last_results'            => null,
            'pending_action'          => null,
            'pending_target_selection'=> null,
            'pending_question'        => null,
            'last_updated_at_ms'      => 0,
        );
    }
    /**
     * Back-compat helper: older REST code calls update_state().
     * This is an alias of patch().
     */
    public static function update_state( $user_id, $tab_id, $tab_instance, $patch ) {
        return self::patch( $user_id, $tab_id, $tab_instance, $patch );
    }

    /**
     * Keep debug output safe and small.
     */
    public static function sanitize_for_debug( $state ) {
        if ( ! is_array( $state ) ) {
            return array();
        }

        $allowed = array(
            'summary',
            'focus_entity',
            'last_results',
            'pending_action',
            'pending_target_selection',
            'pending_question',
            'last_updated_at_ms',
        );

        $out = array();
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $state ) ) {
                $out[ $k ] = $state[ $k ];
            }
        }

        // Prevent huge dumps.
        if ( isset( $out['last_results'] ) && is_array( $out['last_results'] ) && count( $out['last_results'] ) > 50 ) {
            $out['last_results'] = array_slice( $out['last_results'], 0, 50 );
        }

        if ( isset( $out['pending_target_selection']['candidates'] ) && is_array( $out['pending_target_selection']['candidates'] ) && count( $out['pending_target_selection']['candidates'] ) > 50 ) {
            $out['pending_target_selection']['candidates'] = array_slice( $out['pending_target_selection']['candidates'], 0, 50 );
        }

        return $out;
    }
}
