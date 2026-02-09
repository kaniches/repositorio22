<?php
/*
Plugin Name: AutoProduct AI - Brain (Chat Manager)
Description: Orquestador conversacional LLM-first para gestionar WooCommerce desde un chat. Reutiliza la UI app-style (admin-agent/*) y usa el plugin "AutoProduct AI - Agente IA" como ejecutor determinista.
Version: 2.1.7-cancel-button-only
Author: Daniel + IA
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'APAI_BRAIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'APAI_BRAIN_URL', plugin_dir_url( __FILE__ ) );
define( 'APAI_BRAIN_VERSION', '2.1.6' );

require_once APAI_BRAIN_PATH . 'includes/config/class-apai-patterns.php';
require_once APAI_BRAIN_PATH . 'includes/storage/class-apai-brain-store.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-trace.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-normalizer.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-persona.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-product-search.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-llm.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-response-builder.php';
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-rest.php';
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-admin.php';

add_action( 'init', function () {
    // Soft dependency: WooCommerce recommended.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p><strong>AutoProduct AI - Brain</strong>: WooCommerce no parece estar activo. El chat funcionará, pero las acciones no podrán ejecutarse.</p></div>';
        } );
    }

    // Soft dependency: Agent.
    if ( ! class_exists( 'APAI_Agent_REST' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>AutoProduct AI - Brain</strong> requiere que el plugin <strong>AutoProduct AI - Agente IA (Catálogo)</strong> esté activo para ejecutar acciones.</p></div>';
        } );
    }
} );

add_action( 'rest_api_init', function () {
    APAI_Brain_REST::register_routes();
} );

add_action( 'admin_menu', function () {
    APAI_Brain_Admin::register_menu();
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    APAI_Brain_Admin::enqueue_assets( $hook );
} );
