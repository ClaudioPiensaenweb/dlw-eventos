<?php
/**
 * AJAX handlers — admin y frontend.
 * Todos leen de la tabla local (0 llamadas API).
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════
// ADMIN: Buscar productos (Select2)
// ════════════════════════════════════════════════════════════════
add_action('wp_ajax_get_catalogo_productos', function () {
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'dlw_eventos_nonce')) {
        wp_send_json_error('Nonce inválido', 403);
    }
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Sin permisos', 403);
    }

    $search = sanitize_text_field($_GET['q'] ?? '');
    $ids_raw = $_GET['ids'] ?? '';

    if (!empty($ids_raw)) {
        $ids = array_filter(array_map('absint', explode(',', $ids_raw)));
        $productos = dlw_get_productos($ids);
        $result = array_map(function ($p) {
            return [
                'id'     => $p->id,
                'titulo' => $p->titulo,
                'imagen' => $p->imagen_destacada,
            ];
        }, $productos);
        wp_send_json_success(array_values($result));
    } elseif (!empty($search)) {
        $productos = dlw_buscar_productos($search);
        $result = array_map(function ($p) {
            return [
                'id'     => $p->id,
                'titulo' => $p->titulo,
                'imagen' => $p->imagen_destacada,
            ];
        }, $productos);
        wp_send_json_success($result);
    } else {
        wp_send_json_success([]);
    }
});

// ════════════════════════════════════════════════════════════════
// ADMIN: Obtener valores de taxonomía para importación masiva
// ════════════════════════════════════════════════════════════════
add_action('wp_ajax_dlw_get_taxonomia_valores', function () {
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'dlw_eventos_nonce')) {
        wp_send_json_error('Nonce inválido', 403);
    }

    $tipo = sanitize_key($_GET['tipo'] ?? '');
    if (!$tipo) wp_send_json_error('Tipo requerido');

    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    // Extraer todos los valores únicos de la taxonomía desde la tabla local
    $rows = $wpdb->get_col("SELECT taxonomias FROM {$table}");
    $valores = [];

    foreach ($rows as $json) {
        $tax = json_decode($json, true);
        if (isset($tax[$tipo]) && is_array($tax[$tipo])) {
            $valores = array_merge($valores, $tax[$tipo]);
        }
    }

    $valores = array_unique($valores);
    sort($valores);

    wp_send_json_success($valores);
});

// ════════════════════════════════════════════════════════════════
// ADMIN: Obtener productos por taxonomía (importación masiva)
// ════════════════════════════════════════════════════════════════
add_action('wp_ajax_dlw_get_productos_por_taxonomia', function () {
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'dlw_eventos_nonce')) {
        wp_send_json_error('Nonce inválido', 403);
    }

    $tipo = sanitize_key($_GET['tipo'] ?? '');
    $valor = sanitize_text_field($_GET['valor'] ?? '');
    if (!$tipo || !$valor) wp_send_json_error('Parámetros requeridos');

    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $rows = $wpdb->get_results("SELECT producto_id, titulo, imagen_destacada, taxonomias FROM {$table}");
    $resultado = [];

    foreach ($rows as $row) {
        $tax = json_decode($row->taxonomias, true);
        if (isset($tax[$tipo]) && is_array($tax[$tipo]) && in_array($valor, $tax[$tipo], true)) {
            $resultado[] = [
                'id'     => (int) $row->producto_id,
                'titulo' => $row->titulo,
                'imagen' => $row->imagen_destacada,
            ];
        }
    }

    wp_send_json_success($resultado);
});

// ════════════════════════════════════════════════════════════════
// ADMIN: Guardar post tras importación
// ════════════════════════════════════════════════════════════════
add_action('wp_ajax_importar_evento_guardar', function () {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dlw_eventos_nonce')) {
        wp_send_json_error('Nonce inválido', 403);
    }
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Sin permisos');
    }

    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'eventos') {
        wp_send_json_error('Post no válido');
    }

    wp_update_post(['ID' => $post_id]);
    wp_send_json_success('Guardado');
});

// ════════════════════════════════════════════════════════════════
// FRONTEND: Filtrar productos del evento
// ════════════════════════════════════════════════════════════════
add_action('wp_ajax_nopriv_filtrar_productos_evento', 'dlw_filtrar_productos_ajax');
add_action('wp_ajax_filtrar_productos_evento', 'dlw_filtrar_productos_ajax');

function dlw_filtrar_productos_ajax() {
    if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'dlw_frontend_nonce')) {
        wp_send_json_error('Nonce inválido', 403);
    }

    $ids = isset($_POST['ids'])
        ? array_filter(array_map('absint', explode(',', sanitize_text_field($_POST['ids']))))
        : [];
    $filtros = isset($_POST['filtros'])
        ? json_decode(stripslashes($_POST['filtros']), true)
        : [];

    if (!$ids) {
        wp_send_json_success(['productos' => []]);
    }

    $productos = dlw_get_productos_filtrados($ids, $filtros ?: []);

    // Convertir a arrays para JSON (misma estructura que la API)
    $result = array_map(function ($p) {
        return [
            'id'               => $p->id,
            'titulo'           => $p->titulo,
            'extracto'         => $p->extracto,
            'descripcion'      => $p->descripcion,
            'imagen_destacada' => $p->imagen_destacada,
            'meta_fields'      => $p->meta_fields,
            'taxonomias'       => $p->taxonomias,
            'enlace'           => $p->enlace,
        ];
    }, $productos);

    wp_send_json_success(['productos' => $result]);
}
