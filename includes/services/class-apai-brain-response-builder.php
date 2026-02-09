<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Brain_Response_Builder {

    /**
     * Standard REST payload used by admin-agent/* UI.
     *
     * @param string $reply
     * @param array<string,mixed> $store_state
     * @param array<string,mixed> $meta
     * @param array<int,mixed> $cards
     * @return array<string,mixed>
     */
    public static function ok( $reply, $store_state, $meta = array(), $cards = array() ) {
        return array(
            'ok'         => true,
            'reply'      => is_string( $reply ) ? $reply : '',
            'cards'      => is_array( $cards ) ? $cards : array(),
            'store_state'=> is_array( $store_state ) ? $store_state : array(),
            'meta'       => is_array( $meta ) ? $meta : array(),
        );
    }

    public static function error( $message, $code = 'brain_error', $store_state = null, $meta = array() ) {
        return array(
            'ok'         => false,
            'code'       => is_string( $code ) ? $code : 'brain_error',
            'message'    => is_string( $message ) ? $message : 'Error',
            'store_state'=> is_array( $store_state ) ? $store_state : array(),
            'meta'       => is_array( $meta ) ? $meta : array(),
        );
    }
}
