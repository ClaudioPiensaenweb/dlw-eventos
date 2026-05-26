<?php
/**
 * Funciones de lectura de la caché local.
 * Reemplazan a get_producto_remoto() y las llamadas directas a la API.
 */
if (!defined('ABSPATH')) exit;

/**
 * Obtiene un producto de la caché local por ID.
 * Reemplazo directo de get_producto_remoto().
 * Devuelve objeto con la misma estructura que la API.
 */
function dlw_get_producto($id) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    $id = absint($id);
    if (!$id) return null;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE producto_id = %d", $id
    ));

    if (!$row) return null;

    return dlw_row_to_object($row);
}

/**
 * Obtiene múltiples productos por IDs.
 * Reemplazo de get_productos_remotos_batch().
 */
function dlw_get_productos($ids) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $ids = array_filter(array_map('absint', (array) $ids));
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE producto_id IN ({$placeholders})
         ORDER BY FIELD(producto_id, {$placeholders})",
        array_merge($ids, $ids)
    ));

    $productos = [];
    foreach ($rows as $row) {
        $productos[$row->producto_id] = dlw_row_to_object($row);
    }
    return $productos;
}

/**
 * Obtiene productos filtrados por taxonomía.
 */
function dlw_get_productos_filtrados($ids, $filtros = []) {
    $productos = dlw_get_productos($ids);

    if (empty($filtros)) {
        return array_values($productos);
    }

    $resultado = [];
    foreach ($productos as $p) {
        $coincide = true;
        foreach ($filtros as $tax => $valores) {
            if (empty($valores)) continue;
            $tax_valores = (array) ($p->taxonomias->{$tax} ?? []);
            if (empty(array_intersect($valores, $tax_valores))) {
                $coincide = false;
                break;
            }
        }
        if ($coincide) $resultado[] = $p;
    }

    return $resultado;
}

/**
 * Busca productos por texto en título.
 */
function dlw_buscar_productos($search, $limit = 50) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT producto_id, titulo, imagen_destacada 
         FROM {$table} 
         WHERE titulo LIKE %s 
         ORDER BY titulo ASC 
         LIMIT %d",
        '%' . $wpdb->esc_like($search) . '%',
        $limit
    ));

    return array_map(function ($row) {
        return (object) [
            'id'               => (int) $row->producto_id,
            'titulo'           => $row->titulo,
            'imagen_destacada' => $row->imagen_destacada,
        ];
    }, $rows);
}

/**
 * Obtiene todos los valores únicos de una taxonomía para un set de IDs.
 */
function dlw_get_taxonomia_valores($ids, $taxonomia) {
    $productos = dlw_get_productos($ids);
    $valores = [];

    foreach ($productos as $p) {
        $tax_vals = (array) ($p->taxonomias->{$taxonomia} ?? []);
        $valores = array_merge($valores, $tax_vals);
    }

    $valores = array_unique($valores);
    sort($valores);
    return $valores;
}

/**
 * Cuenta total de productos en caché.
 */
function dlw_count_productos() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

/**
 * Convierte una fila de la DB al formato objeto que esperan los shortcodes.
 * Mantiene compatibilidad exacta con la estructura de la API.
 */
function dlw_row_to_object($row) {
    return (object) [
        'id'               => (int) $row->producto_id,
        'titulo'           => $row->titulo,
        'extracto'         => $row->extracto,
        'descripcion'      => $row->descripcion,
        'imagen_destacada' => $row->imagen_destacada,
        'meta_fields'      => json_decode($row->meta_fields),
        'taxonomias'       => json_decode($row->taxonomias),
        'enlace'           => $row->enlace,
    ];
}
