<?php
/**
 * ════════════════════════════════════════════════════════════════
 * SNIPPET PARA DLONGWOOD.COM (NO es parte del plugin dlw-eventos)
 * ════════════════════════════════════════════════════════════════
 *
 * Instalar en www.dlongwood.com como Code Snippet ("Solo ejecutar en
 * el sitio") o en el functions.php del tema hijo.
 *
 * Añade un endpoint MUY ligero que el plugin dlw-eventos consulta una vez
 * por noche (y solo si hay eventos activos) para saber qué productos han
 * cambiado, en vez de descargar el catálogo completo (~6 MB):
 *
 *   GET /wp-json/catalogo/v1/cambios?desde=<timestamp unix>
 *
 * Respuesta (unos pocos KB):
 *   {
 *     "ahora":       1762790400,           // hora del servidor (unix)
 *     "modificados": [123, 456],           // IDs publicados modificados desde "desde"
 *     "publicados":  [1, 2, 3, ...]        // todos los IDs publicados (para detectar bajas)
 *   }
 *
 * Solo hace 2 consultas de IDs (fields => ids): no carga contenido, meta
 * ni imágenes. Coste despreciable para el servidor.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('catalogo/v1', '/cambios', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => [
            'desde' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
        ],
        'callback'            => function (WP_REST_Request $request) {
            $desde = (int) $request->get_param('desde');

            // Mismo idioma que usa el catálogo (?lang=es). Compatible con WPML y Polylang.
            do_action('wpml_switch_language', 'es');

            $base = [
                'post_type'              => 'productos',
                'post_status'            => 'publish',
                'fields'                 => 'ids',
                'posts_per_page'         => -1,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'suppress_filters'       => false,
                'lang'                   => 'es',
            ];

            $publicados = get_posts($base);

            $modificados = [];
            if ($desde > 0) {
                $modificados = get_posts($base + [
                    'date_query' => [[
                        'column'    => 'post_modified_gmt',
                        'after'     => gmdate('Y-m-d H:i:s', $desde),
                        'inclusive' => true,
                    ]],
                ]);
            }

            $response = new WP_REST_Response([
                'ahora'       => time(),
                'modificados' => array_map('intval', $modificados),
                'publicados'  => array_map('intval', $publicados),
            ]);
            $response->header('Cache-Control', 'no-store');
            return $response;
        },
    ]);
});
