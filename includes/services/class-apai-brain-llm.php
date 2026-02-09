<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LLM Planner (LLM-first).
 *
 * Produces one of:
 * - question: ask for missing info
 * - draft_action: propose deterministic action payload for Agent executor
 * - answer: normal assistant answer (no pending)
 *
 * IMPORTANT:
 * - Never executes actions.
 * - Never confirms by text.
 */
class APAI_Brain_LLM {

    /**
     * @param string $message
     * @param array<string,mixed> $context_lite
     * @param array<int,array<string,string>> $history
     * @return array<string,mixed>
     */
    public static function plan( $message, $context_lite, $history = array() ) {
        $message = is_string( $message ) ? trim( $message ) : '';
        $context_lite = is_array( $context_lite ) ? $context_lite : array();
        $history = is_array( $history ) ? $history : array();

        // Allow local dev overriding without hitting LLM.
        $filtered = apply_filters( 'apai_brain_llm_plan', null, $message, $context_lite, $history );
        if ( is_array( $filtered ) ) {
            return $filtered;
        }

        // If Core provides an LLM client, use it. Otherwise, return a helpful message.
        $client = null;
        if ( class_exists( 'APAI_Core' ) && method_exists( 'APAI_Core', 'get_openai_client' ) ) {
            $client = APAI_Core::get_openai_client();
        } elseif ( class_exists( 'APAI_OpenAI_Client' ) ) {
            // Legacy direct client (if exposed)
            $client = new APAI_OpenAI_Client();
        }

        if ( ! $client ) {
            return array(
                'kind'  => 'answer',
                'reply' => 'Para usar la IA necesito que esté activo AutoProduct AI Core (conexión al SaaS / API key). Andá a AutoProduct AI → Core y conectá la tienda, y lo seguimos 😊',
            );
        }

        $sys = self::system_prompt();
        $user = self::user_prompt( $message, $context_lite, $history );

        $raw = self::call_llm( $client, $sys, $user );
        $plan = self::parse_plan_json( $raw );

        // One retry if parse fails.
        if ( empty( $plan ) || ! is_array( $plan ) || empty( $plan['kind'] ) ) {
            $raw2 = self::call_llm( $client, $sys, $user . "\n\nIMPORTANTE: Devolvé SOLO JSON válido, sin texto extra." );
            $plan = self::parse_plan_json( $raw2 );
        }

        if ( ! is_array( $plan ) || empty( $plan['kind'] ) ) {
            return array(
                'kind'  => 'answer',
                'reply' => 'Me trabé interpretando eso. ¿Podés reformularlo en una frase corta? (por ejemplo: “subí el precio 10% de remeras”)',
                'debug' => array( 'raw' => $raw ),
            );
        }

        return $plan;
    }

    private static function system_prompt() {
        $persona = class_exists( 'APAI_Brain_Persona' ) ? APAI_Brain_Persona::system_prompt() : '';
        $rules = "\n\nREGLAS DE ORO:\n";
        $rules .= "- Sos un gerente de tienda experto en WooCommerce.\n";
        $rules .= "- NUNCA confirmes con palabras. Si hay una acción pendiente, pedí que toquen el botón Confirmar.\n";
        $rules .= "- 'Cancelar' sí puede hacerse por texto.\n";
        $rules .= "- Si falta info, hacé 1 pregunta concreta por vez (máximo 2).\n";
        $rules .= "- Evitá repetir. Si el usuario no responde, ofrecé opciones.\n";
        $rules .= "- Tus salidas deben ser JSON válido, SIN texto adicional.\n";

        $schema = "\n\nFORMATO JSON (elegí 1 kind):\n";
        $schema .= "1) question:\n";
        $schema .= '{"kind":"question","reply":"...","missing":["campo"],"suggested_tools":[...]}\n';
        $schema .= "2) draft_action:\n";
        $schema .= '{"kind":"draft_action","reply":"...","action":{"type":"update_product|create_product|delete_product|update_category|order_update|bulk_update","human_summary":"...","product_id":123,"changes":{...},"risk":"low|medium|high"}}\n';
        $schema .= "3) answer:\n";
        $schema .= '{"kind":"answer","reply":"..."}\n';

        return trim( $persona . $rules . $schema );
    }

    private static function user_prompt( $message, $context_lite, $history ) {
        $ctx = array(
            'store_state' => $context_lite,
            'history'     => array_slice( $history, -10 ),
            'message'     => $message,
        );
        $json = wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE );
        return "Contexto y mensaje del usuario (JSON):\n" . $json;
    }

    private static function call_llm( $client, $system, $user ) {
        // We support multiple client shapes from Core.
        try {
            if ( is_object( $client ) && method_exists( $client, 'chat' ) ) {
                $res = $client->chat( array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user', 'content' => $user ),
                ), array( 'temperature' => 0.2 ) );
                if ( is_array( $res ) && isset( $res['content'] ) ) {
                    return (string) $res['content'];
                }
                if ( is_string( $res ) ) {
                    return $res;
                }
            }

            // Fallback: some clients expose ->complete or ->request.
            if ( is_object( $client ) && method_exists( $client, 'complete' ) ) {
                $res = $client->complete( $system . "\n\n" . $user );
                if ( is_string( $res ) ) {
                    return $res;
                }
            }
        } catch ( \Throwable $e ) {
            // swallow
        }

        return '';
    }

    /**
     * Extract and parse first JSON object from model output.
     *
     * @return array<string,mixed>
     */
    private static function parse_plan_json( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( $raw === '' ) {
            return array();
        }

        // Find first {...} block (best-effort).
        $start = strpos( $raw, '{' );
        $end = strrpos( $raw, '}' );
        if ( $start === false || $end === false || $end <= $start ) {
            return array();
        }
        $json = substr( $raw, $start, $end - $start + 1 );

        $decoded = json_decode( $json, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
        return array();
    }
}
