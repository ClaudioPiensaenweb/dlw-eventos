<?php
/**
 * Webhook para refrescar un producto cuando se edita en dlongwood.com.
 *
 * El secreto es propio de cada instalación: se genera al actualizar a la
 * v1.3.0 y se ve en DLW Eventos > Ajustes. También se puede fijar en
 * wp-config.php con: define('DLW_WEBHOOK_SECRET', '...');
 *
 * CONFIGURACIÓN EN DLONGWOOD.COM (snippet):
 *
 * add_action('save_post_productos', function($post_id) {
 *     if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
 *     wp_remote_post('https://eventos.dlongwood.com/wp-json/dlw-eventos/v1/sync', [
 *         'body'     => json_encode(['id' => $post_id, 'secret' => 'SECRETO_DE_AJUSTES']),
 *         'headers'  => ['Content-Type' => 'application/json'],
 *         'timeout'  => 5,
 *         'blocking' => false,
 *     ]);
 * });
 */
if (!defined('ABSPATH')) exit;

function dlw_webhook_secret() {
    if (defined('DLW_WEBHOOK_SECRET') && DLW_WEBHOOK_SECRET) return (string) DLW_WEBHOOK_SECRET;
    return (string) get_option('dlw_webhook_secret', '');
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
    $secret = dlw_webhook_secret();

    if (!$secret || empty($body['secret']) || !hash_equals($secret, (string) $body['secret'])) {
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

    $result = dlw_sync_producto_individual($id);

    return new WP_REST_Response([
        'ok'     => $result !== false,
        'id'     => $id,
        'action' => $result === 'igual' ? 'unchanged' : 'synced',
    ]);
}

function dlw_webhook_sync_all() {
    $count = dlw_sync_all_productos();
    return new WP_REST_Response([
        'ok'    => $count !== false,
        'count' => (int) $count,
    ]);
}
