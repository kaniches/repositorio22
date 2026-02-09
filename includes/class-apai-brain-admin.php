<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Brain_Admin {

    /** @var string|null */
    private static $page_hook = null;

    public static function register_menu() {
        self::$page_hook = add_menu_page(
            'AutoProduct AI Brain',
            'AutoProduct AI Brain',
            'manage_woocommerce',
            'apai-brain',
            array( __CLASS__, 'render_page' ),
            'dashicons-format-chat',
            56
        );

        add_submenu_page(
            'apai-brain',
            'Ajustes',
            'Ajustes',
            'manage_woocommerce',
            'apai-brain-settings',
            array( __CLASS__, 'render_settings' )
        );
    }

    public static function enqueue_assets( $hook_suffix ) {
        if ( empty( self::$page_hook ) || $hook_suffix !== self::$page_hook ) {
            return;
        }

        $base_ver = defined( 'APAI_BRAIN_VERSION' ) ? (string) APAI_BRAIN_VERSION : 'dev';

        wp_enqueue_style( 'dashicons' );

        // CSS
        foreach ( array( 'base', 'shell', 'cards', 'selector', 'menu' ) as $f ) {
            $path = APAI_BRAIN_PATH . 'assets/css/admin-agent/' . $f . '.css';
            $url  = APAI_BRAIN_URL . 'assets/css/admin-agent/' . $f . '.css';
            if ( file_exists( $path ) ) {
                wp_enqueue_style(
                    'apai-brain-admin-agent-' . $f,
                    $url,
                    array(),
                    $base_ver . '-' . filemtime( $path )
                );
            }
        }

        // JS (order matters)
        $scripts = array(
            array( 'apai-brain-chat-utils', 'utils.js', array() ),
            array( 'apai-brain-chat-layout', 'layout.js', array( 'apai-brain-chat-utils' ) ),
            array( 'apai-brain-chat-shell', 'shell.js', array( 'apai-brain-chat-layout' ) ),
            array( 'apai-brain-chat-typing', 'typing.js', array( 'apai-brain-chat-utils' ) ),
            array( 'apai-brain-chat-trace', 'trace.js', array( 'apai-brain-chat-utils' ) ),
            array( 'apai-brain-chat-core-ui', 'core-ui.js', array( 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace' ) ),
            array( 'apai-brain-chat-core-cards', 'core-cards.js', array( 'apai-brain-chat-core-ui', 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace' ) ),
            array( 'apai-brain-chat-core', 'core.js', array( 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace', 'apai-brain-chat-core-ui', 'apai-brain-chat-core-cards' ) ),
            array( 'apai-brain-chat-menu', 'menu.js', array( 'apai-brain-chat-utils' ) ),
            array( 'apai-brain-chat-copy', 'copy.js', array( 'apai-brain-chat-utils', 'apai-brain-chat-core', 'apai-brain-chat-trace' ) ),
        );

        foreach ( $scripts as $s ) {
            list( $handle, $file, $deps ) = $s;
            $path = APAI_BRAIN_PATH . 'assets/js/admin-agent/' . $file;
            $url  = APAI_BRAIN_URL . 'assets/js/admin-agent/' . $file;
            if ( file_exists( $path ) ) {
                wp_enqueue_script( $handle, $url, $deps, $base_ver . '-' . filemtime( $path ), true );
            }
        }

        $rest_nonce = wp_create_nonce( 'wp_rest' );

        wp_localize_script(
            'apai-brain-chat-core',
            'APAI_AGENT_DATA',
            array(
                'rest_url'             => esc_url_raw( rest_url( 'apai-brain/v1/chat' ) ),
                'product_search_url'   => esc_url_raw( rest_url( 'apai-brain/v1/products/search' ) ),
                'product_summary_url'  => esc_url_raw( rest_url( 'apai-brain/v1/products/summary' ) ),
                'product_variations_url'=> esc_url_raw( rest_url( 'apai-brain/v1/products/variations' ) ),
                'variations_apply_url'  => esc_url_raw( rest_url( 'apai-brain/v1/variations/apply' ) ),
                'trace_excerpt_url'    => esc_url_raw( rest_url( 'apai-brain/v1/trace/excerpt' ) ),
                // GOLDEN RULE: Confirm only via button -> goes to Brain /confirm.
                // Brain validates pending + executes deterministically via Agent server-side.
                'execute_url'          => esc_url_raw( rest_url( 'apai-brain/v1/confirm' ) ),
                'debug_url'            => esc_url_raw( rest_url( 'apai-brain/v1/debug' ) ),
                'brain_debug_url'      => esc_url_raw( rest_url( 'apai-brain/v1/debug' ) ),
                'agent_debug_url'      => esc_url_raw( rest_url( 'apai-agent/v1/debug' ) ),
                'debug_url_lite'       => esc_url_raw( add_query_arg( array( 'level' => 'lite', '_wpnonce' => $rest_nonce ), rest_url( 'apai-brain/v1/debug' ) ) ),
                'debug_url_full'       => esc_url_raw( add_query_arg( array( 'level' => 'full', '_wpnonce' => $rest_nonce ), rest_url( 'apai-brain/v1/debug' ) ) ),
                'qa_url'               => esc_url_raw( add_query_arg( array( 'verbose' => 1, '_wpnonce' => $rest_nonce ), rest_url( 'apai-brain/v1/qa/run' ) ) ),
                'qa_url_quick'         => esc_url_raw( add_query_arg( array( 'quick' => 1, '_wpnonce' => $rest_nonce ), rest_url( 'apai-brain/v1/qa/run' ) ) ),
                'clear_pending_url'    => esc_url_raw( rest_url( 'apai-brain/v1/pending/clear' ) ),
                'nonce'                => $rest_nonce,
                'has_cat_agent'        => (bool) ( class_exists( 'APAI_Agent_REST' ) || defined( 'APAI_AGENT_VERSION' ) ),
                'core_ok'              => (bool) class_exists( 'APAI_Core' ),
            )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $has_cat_agent = class_exists( 'APAI_Agent_REST' ) || defined( 'APAI_AGENT_VERSION' );
        $url_products = admin_url( 'edit.php?post_type=product' );
        $url_settings = admin_url( 'admin.php?page=apai-brain-settings' );
        ?>
        <div class="wrap apai-agent-wrap">
            <div id="apai_shell" class="apai-shell apai-shell--collapsed" aria-label="AutoProduct AI">
                <aside class="apai-side" aria-label="Navegación">
                    <div class="apai-side-top">
                        <button type="button" id="apai_side_toggle" class="apai-side-toggle" aria-label="Expandir/colapsar menú" title="Menú">
                            <span class="dashicons dashicons-menu"></span>
                        </button>
                        <div class="apai-brand" aria-label="AutoProduct AI">
                            <span class="apai-brand-title">AutoProduct AI</span>
                            <span class="apai-brand-sub">Brain (Chat)</span>
                        </div>
                    </div>

                    <nav class="apai-nav" aria-label="Accesos">
                        <a class="apai-nav-item is-active" href="<?php echo esc_url( admin_url( 'admin.php?page=apai-brain' ) ); ?>" aria-label="Chat">
                            <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                            <span class="apai-nav-text">Chat</span>
                        </a>

                        <a class="apai-nav-item" href="<?php echo esc_url( $url_products ); ?>" aria-label="Productos">
                            <span class="dashicons dashicons-products" aria-hidden="true"></span>
                            <span class="apai-nav-text">Productos</span>
                        </a>

                        <a class="apai-nav-item" href="<?php echo esc_url( $url_settings ); ?>" aria-label="Ajustes">
                            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                            <span class="apai-nav-text">Ajustes</span>
                        </a>
                    </nav>

                    <div class="apai-side-bottom" aria-label="Usuario">
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                        <span class="apai-nav-text">Admin</span>
                    </div>
                </aside>

                <main class="apai-main" aria-label="Chat">

                    <?php if ( ! $has_cat_agent ) : ?>
                        <div class="notice notice-warning"><p><strong>Agente no activo.</strong> Podés chatear, pero no se podrán ejecutar acciones hasta activar el plugin Agent.</p></div>
                    <?php endif; ?>

                    <div class="apai-main-wordmark" aria-hidden="true">AutoProduct AI</div>

                    <div id="apai_agent_chat" class="apai-agent-chat">
                        <div id="apai_agent_messages" class="apai-agent-messages"></div>

                        <div id="apai_agent_debug_wrap" class="apai-agent-debug" style="display:none;">
                            <pre id="apai_agent_debug_pre"></pre>
                        </div>

                        <div class="apai-agent-bottom">
                            <div class="apai-composer-card">
                                <div class="apai-agent-inputbar">
                                    <button type="button" class="apai-btn-plus" id="apai_agent_plus" aria-label="Más opciones" title="Más opciones">+</button>
                                    <textarea id="apai_agent_input" rows="1" placeholder="Escribí como si hablaras con tu gestor de tienda…"></textarea>
                                    <button id="apai_agent_send" class="apai-btn-send" type="button">Enviar</button>
                                </div>

                                <div class="apai-agent-footerbar">
                                    <div class="apai-footer-left">
                                        <span class="apai-footer-label">Agente ejecutor:</span>
                                        <select id="apai_agent_selector">
                                            <option value="catalog">Agente de Catálogo</option>
                                        </select>
                                        <?php if ( ! $has_cat_agent ) : ?>
                                            <span class="apai-footer-warn">(No activo: no se podrán ejecutar acciones)</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="apai-footer-right">
                                        <button type="button" class="apai-btn-copy" id="apai_agent_copy_all" title="Copiar chat + debug full">
                                            <span class="dashicons dashicons-clipboard"></span>
                                            <span class="apai-btn-copy-label">Copiar</span>
                                        </button>
                                        <button type="button" class="apai-btn-debug apai-btn-qa" id="apai_agent_qa_quick" title="QA rápido (quick)">QA</button>
                                        <button type="button" class="apai-btn-debug apai-btn-qa" id="apai_agent_qa_verbose" title="QA completo (verbose)">QA+</button>
                                        <button type="button" class="apai-btn-debug" id="apai_agent_debug_toggle">Mostrar Debug</button>
                                        <select id="apai_agent_debug_level">
                                            <option value="lite" selected>Lite</option>
                                            <option value="full">Full</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </main>
            </div>
        </div>
        <?php
    }

    public static function render_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }

        $core_ok = class_exists( 'APAI_Core' ) || class_exists( 'APAI_OpenAI_Client' );
        $core_link = admin_url( 'admin.php?page=autoproduct-ai' );

        echo '<div class="wrap">';
        echo '<h1>Conexión - AutoProduct AI Brain</h1>';

        if ( ! $core_ok ) {
            echo '<div class="notice notice-error"><p><strong>Falta AutoProduct AI Core.</strong> Instalalo y activalo para conectar este Brain al SaaS / OpenAI.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Este Brain usa <strong>AutoProduct AI Core</strong> para conectarse al SaaS (y allí se usa OpenAI).</p></div>';
        }

        echo '<p>La configuración se hace en <a href="' . esc_url( $core_link ) . '"><strong>AutoProduct AI (Core)</strong></a>: URL del SaaS + API Key (apa_...).</p>';
        echo '<hr/>';
        echo '<p class="description">Seguridad: el Brain nunca ejecuta cambios sin botón <strong>Confirmar</strong>. "Cancelar" sí puede hacerse por texto.</p>';
        echo '</div>';
    }
}
