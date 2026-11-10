<?php
/**
 * Funciones de lectura de la caché local.
 * Reemplazan a get_producto_remoto() y las llamadas directas a la API.
 *
 * Rendimiento:
 *  - Memoria por petición: la ficha llama a [producto_api] muchas veces para
 *    el mismo producto, pero solo se consulta la base de datos una vez.
 *  - El grid y los filtros usan dlw_get_productos_listado(), que NO lee las
 *    columnas pesadas (descripcion).
 */
if (!defined('ABSPATH')) exit;

/** Columnas ligeras para listados (sin la descripción completa). */
define('DLW_COLUMNAS_LISTADO', 'producto_id, titulo, extracto, imagen_destacada, meta_fields, taxonomias, enlace');

/**
 * Obtiene un producto de la caché local por ID (con todas sus columnas).
 * Reemplazo directo de get_producto_remoto().
 */
function dlw_get_producto($id) {
    static $memo = [];
    global $wpdb;

    $id = absint($id);
    if (!$id) return null;
    if (array_key_exists($id, $memo)) return $memo[$id];

    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT producto_id, titulo, extracto, descripcion, imagen_destacada, meta_fields, taxonomias, enlace
         FROM {$table} WHERE producto_id = %d", $id
    ));

    return $memo[$id] = $row ? dlw_row_to_object($row) : null;
}

/**
 * Obtiene múltiples productos por IDs, en el mismo orden. Incluye la descripción.
 * Reemplazo de get_productos_remotos_batch().
 */
function dlw_get_productos($ids) {
    return dlw_consultar_productos($ids, 'producto_id, titulo, extracto, descripcion, imagen_destacada, meta_fields, taxonomias, enlace');
}

/**
 * Igual que dlw_get_productos() pero sin la descripción: para grids, filtros
 * y buscadores. Memorizado por petición (los 3 filtros + el grid de una
 * página de evento hacen una sola consulta).
 */
function dlw_get_productos_listado($ids) {
    static $memo = [];
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    $clave = implode(',', $ids);
    if (!isset($memo[$clave])) {
        $memo[$clave] = dlw_consultar_productos($ids, DLW_COLUMNAS_LISTADO);
    }
    return $memo[$clave];
}

function dlw_consultar_productos($ids, $columnas) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$columnas} FROM {$table} WHERE producto_id IN ({$placeholders})
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
 * Obtiene productos filtrados por taxonomía (sin descripción).
 */
function dlw_get_productos_filtrados($ids, $filtros = []) {
    $productos = dlw_get_productos_listado($ids);

    if (empty($filtros) || !is_array($filtros)) {
        return array_values($productos);
    }

    $resultado = [];
    foreach ($productos as $p) {
        if (dlw_producto_cumple_filtros($p, $filtros)) $resultado[] = $p;
    }
    return $resultado;
}

/**
 * AND entre grupos (marcas, tecnologías…), OR dentro de cada grupo.
 */
function dlw_producto_cumple_filtros($p, $filtros) {
    foreach ($filtros as $tax => $valores) {
        if (empty($valores)) continue;
        $tax_valores = (array) ($p->taxonomias->{$tax} ?? []);
        if (empty(array_intersect((array) $valores, $tax_valores))) return false;
    }
    return true;
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
    $valores = [];
    foreach (dlw_get_productos_listado($ids) as $p) {
        $valores = array_merge($valores, (array) ($p->taxonomias->{$taxonomia} ?? []));
    }
    $valores = array_values(array_unique($valores));
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
 * Todos los IDs que hay en caché.
 */
function dlw_ids_en_cache() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    return array_map('intval', $wpdb->get_col("SELECT producto_id FROM {$table}"));
}

/**
 * Borra de la caché los productos que no estén en $ids_validos.
 * @return int Filas borradas.
 */
function dlw_borrar_productos_excepto($ids_validos) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    $ids_validos = array_values(array_unique(array_filter(array_map('absint', (array) $ids_validos))));
    if (!$ids_validos) return 0;

    $placeholders = implode(',', array_fill(0, count($ids_validos), '%d'));
    return (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE producto_id NOT IN ({$placeholders})",
        $ids_validos
    ));
}

/**
 * Convierte una fila de la DB al formato objeto que esperan los shortcodes.
 * Mantiene compatibilidad exacta con la estructura de la API.
 */
function dlw_row_to_object($row) {
    return (object) [
        'id'               => (int) $row->producto_id,
        'titulo'           => $row->titulo,
        'extracto'         => $row->extracto ?? '',
        'descripcion'      => $row->descripcion ?? '',
        'imagen_destacada' => $row->imagen_destacada ?? '',
        'meta_fields'      => json_decode($row->meta_fields ?? '{}'),
        'taxonomias'       => json_decode($row->taxonomias ?? '{}'),
        'enlace'           => $row->enlace ?? '',
    ];
}
