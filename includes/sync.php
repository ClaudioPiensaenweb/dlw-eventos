<?php
/**
 * Sincronización de productos desde dlongwood.com → tabla local
 */
if (!defined('ABSPATH')) exit;

/**
 * Sincroniza todos los productos del catálogo remoto a la tabla local.
 * Se ejecuta cada 6h vía WP-Cron y bajo demanda desde admin.
 */
function dlw_sync_all_productos() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $page = 1;
    $per_page = 100;
    $total_synced = 0;
    $remote_ids = [];

    do {
        $url = add_query_arg([
            'lang'     => 'es',
            'fields'   => 'detallado',
            'per_page' => $per_page,
            'page'     => $page,
        ], DLW_API_BASE);

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log('[DLW Sync] Error en página ' . $page . ': ' . 
                (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)));
            break;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $productos = $body['posts'] ?? $body;

        if (!is_array($productos) || empty($productos)) break;

        // Si la API devuelve un solo producto como objeto
        if (isset($productos['id'])) {
            $productos = [$productos];
        }

        foreach ($productos as $p) {
            if (!isset($p['id'])) continue;

            $id = absint($p['id']);
            $remote_ids[] = $id;

            // Generar extracto
            $extracto = '';
            if (!empty($p['extracto'])) {
                $extracto = $p['extracto'];
            } else {
                $content = $p['descripcion'] ?? '';
                if (preg_match('/<div\s+class=["\']dlw-lead-box["\'][^>]*>(.*?)<\/div>/si', $content, $m)) {
                    $extracto = wp_strip_all_tags($m[1]);
                    $extracto = trim(preg_replace('/\s+/', ' ', $extracto));
                    if (mb_strlen($extracto) > 200) $extracto = mb_substr($extracto, 0, 200) . '…';
                } else {
                    $limpio = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $content);
                    $limpio = wp_strip_all_tags($limpio);
                    $limpio = trim(preg_replace('/\s+/', ' ', $limpio));
                    $extracto = mb_strlen($limpio) > 200 ? mb_substr($limpio, 0, 200) . '…' : $limpio;
                }
            }

            $wpdb->replace($table, [
                'producto_id'      => $id,
                'titulo'           => sanitize_text_field($p['titulo'] ?? ''),
                'extracto'         => $extracto,
                'descripcion'      => $p['descripcion'] ?? '',
                'imagen_destacada' => esc_url_raw($p['imagen_destacada'] ?? ''),
                'meta_fields'      => wp_json_encode($p['meta_fields'] ?? []),
                'taxonomias'       => wp_json_encode($p['taxonomias'] ?? []),
                'enlace'           => esc_url_raw($p['enlace'] ?? ''),
                'datos_json'       => wp_json_encode($p),
                'synced_at'        => current_time('mysql'),
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

            $total_synced++;
        }

        $max_pages = $body['max_num_pages'] ?? 1;
        $page++;

    } while ($page <= $max_pages);

    // Eliminar productos que ya no existen en remoto
    if (!empty($remote_ids)) {
        $placeholders = implode(',', array_fill(0, count($remote_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE producto_id NOT IN ({$placeholders})",
            $remote_ids
        ));
    }

    update_option('dlw_last_sync', [
        'time'  => current_time('mysql'),
        'count' => $total_synced,
    ]);

    return $total_synced;
}

/**
 * Sincroniza un producto individual por ID.
 * Usado por el webhook cuando se edita/publica en dlongwood.com.
 */
function dlw_sync_producto_individual($id) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    $id = absint($id);

    $url = DLW_API_BASE . '/' . $id . '?lang=es';
    $response = wp_remote_get($url, ['timeout' => 15]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $p = json_decode(wp_remote_retrieve_body($response), true);
    if (!$p || !isset($p['id'])) return false;

    // Extracto
    $extracto = $p['extracto'] ?? '';
    if (empty($extracto)) {
        $content = $p['descripcion'] ?? '';
        if (preg_match('/<div\s+class=["\']dlw-lead-box["\'][^>]*>(.*?)<\/div>/si', $content, $m)) {
            $extracto = wp_strip_all_tags($m[1]);
            $extracto = trim(preg_replace('/\s+/', ' ', $extracto));
            if (mb_strlen($extracto) > 200) $extracto = mb_substr($extracto, 0, 200) . '…';
        }
    }

    $wpdb->replace($table, [
        'producto_id'      => $id,
        'titulo'           => sanitize_text_field($p['titulo'] ?? ''),
        'extracto'         => $extracto,
        'descripcion'      => $p['descripcion'] ?? '',
        'imagen_destacada' => esc_url_raw($p['imagen_destacada'] ?? ''),
        'meta_fields'      => wp_json_encode($p['meta_fields'] ?? []),
        'taxonomias'       => wp_json_encode($p['taxonomias'] ?? []),
        'enlace'           => esc_url_raw($p['enlace'] ?? ''),
        'datos_json'       => wp_json_encode($p),
        'synced_at'        => current_time('mysql'),
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    return true;
}
