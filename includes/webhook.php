<?php
/**
 * Webhook para invalidar caché cuando se edita un producto en dlongwood.com.
 * 
 * CONFIGURACIÓN EN DLONGWOOD.COM:
 * Añadir este snippet en dlongwood.com para que llame al webhook:
 * 
 * add_action('save_post_productos', function($post_id) {
 *     if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
 *     wp_remote_post('https://eventos.dlongwood.com/wp-json/dlw-eventos/v1/sync', [
 *         'body'    => json_encode(['id' => $post_id, 'secret' => 'TU_SECRET_KEY']),
 *         'headers' => ['Content-Type' => 'application/json'],
 *         'timeout' => 5,
 *         'blocking' => false,
 *     ]);
 * });
 */
if (!defined('ABSPATH')) exit;

// Clave secreta para validar webhooks (cambiar en producción)
if (!defined('DLW_WEBHOOK_SECRET')) {
    define('DLW_WEBHOOK_SECRET', 'dlw_eventos_sync_2026');
}

add_action('rest_api_init', function () {
    // Sync individual
    register_rest_route('dlw-eventos/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => 'dlw_webhook_sync',
        'permission_callback' => '__return_true',
    ]);

    // Sync completa (manual desde admin)
    register_rest_route('dlw-eventos/v1', '/sync-all', [
        'methods'             => 'POST',
        'callback'            => 'dlw_webhook_sync_all',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

function dlw_webhook_sync(WP_REST_Request $request) {
    $body = $request->get_json_params();

    // Validar secret
    if (empty($body['secret']) || $body['secret'] !== DLW_WEBHOOK_SECRET) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $id = absint($body['id'] ?? 0);
    if (!$id) {
        return new WP_REST_Response(['error' => 'ID requerido'], 400);
    }

    // Si el producto fue eliminado
    if (!empty($body['action']) && $body['action'] === 'delete') {
        global $wpdb;
        $table = $wpdb->prefix . DLW_CACHE_TABLE;
        $wpdb->delete($table, ['producto_id' => $id], ['%d']);
        return new WP_REST_Response(['ok' => true, 'action' => 'deleted', 'id' => $id]);
    }

    // Sync individual
    $result = dlw_sync_producto_individual($id);

    return new WP_REST_Response([
        'ok'     => $result,
        'id'     => $id,
        'action' => 'synced',
    ]);
}

function dlw_webhook_sync_all() {
    $count = dlw_sync_all_productos();
    return new WP_REST_Response([
        'ok'    => true,
        'count' => $count,
    ]);
}
