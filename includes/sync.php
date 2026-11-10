<?php
/**
 * Sincronización de productos desde dlongwood.com → tabla local.
 *
 * Hay dos modos:
 *
 * 1. SINCRONIZACIÓN NOCTURNA LIGERA (dlw_sync_nocturna) — la del cron diario.
 *    Pensada para hacer el mínimo de llamadas posible:
 *      - Sin eventos activos o próximos → 0 llamadas HTTP, 0 escrituras.
 *      - Con eventos activos → 1 llamada pequeña a /catalogo/v1/cambios y
 *        solo se descargan, uno a uno, los productos que han cambiado.
 *      - Si dlongwood.com no tiene el endpoint de cambios → solo se
 *        descargan los productos de los eventos activos.
 *
 * 2. SINCRONIZACIÓN COMPLETA (dlw_sync_all_productos) — botón "Sincronizar
 *    ahora" o, de noche, cuando alguien ha estado editando eventos y el
 *    catálogo del buscador tiene más de una semana.
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════
// Utilidades
// ════════════════════════════════════════════════════════════════

/**
 * Bloqueo para que no se solapen dos sincronizaciones (cron + botón manual).
 */
function dlw_sync_bloquear() {
    if (get_transient('dlw_sync_lock')) return false;
    set_transient('dlw_sync_lock', time(), 15 * MINUTE_IN_SECONDS);
    return true;
}

function dlw_sync_desbloquear() {
    delete_transient('dlw_sync_lock');
}

/**
 * GET a la API remota. Devuelve el JSON decodificado o WP_Error.
 */
function dlw_api_get($url, $timeout = 30) {
    $response = wp_remote_get($url, [
        'timeout'    => $timeout,
        'user-agent' => 'dlw-eventos/' . DLW_EVENTOS_VERSION,
    ]);

    if (is_wp_error($response)) return $response;

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('dlw_http_' . $code, 'HTTP ' . $code, ['status' => $code]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return new WP_Error('dlw_json', 'Respuesta no válida');
    }
    return $body;
}

/**
 * Genera el extracto si la API no lo trae.
 */
function dlw_generar_extracto($p) {
    if (!empty($p['extracto'])) return $p['extracto'];

    $content = $p['descripcion'] ?? '';
    if (preg_match('/<div\s+class=["\']dlw-lead-box["\'][^>]*>(.*?)<\/div>/si', $content, $m)) {
        $texto = $m[1];
    } else {
        $texto = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $content);
    }
    $texto = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($texto)));
    return mb_strlen($texto) > 200 ? mb_substr($texto, 0, 200) . '…' : $texto;
}

/**
 * Guarda un producto en la tabla local. Si no ha cambiado (mismo hash),
 * no escribe nada.
 *
 * @return string|false 'guardado', 'igual' o false si el dato no es válido.
 */
function dlw_guardar_producto($p) {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    if (!is_array($p) || empty($p['id'])) return false;
    $id = absint($p['id']);

    $fila = [
        'producto_id'      => $id,
        'titulo'           => sanitize_text_field($p['titulo'] ?? ''),
        'extracto'         => dlw_generar_extracto($p),
        'descripcion'      => $p['descripcion'] ?? '',
        'imagen_destacada' => esc_url_raw($p['imagen_destacada'] ?? ''),
        'meta_fields'      => wp_json_encode($p['meta_fields'] ?? []),
        'taxonomias'       => wp_json_encode($p['taxonomias'] ?? []),
        'enlace'           => esc_url_raw($p['enlace'] ?? ''),
    ];
    $fila['hash'] = md5(wp_json_encode($fila));

    $hash_actual = $wpdb->get_var($wpdb->prepare(
        "SELECT hash FROM {$table} WHERE producto_id = %d", $id
    ));
    if ($hash_actual === $fila['hash']) return 'igual';

    $fila['synced_at'] = current_time('mysql');
    $wpdb->replace($table, $fila, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    return 'guardado';
}

/**
 * Registra el resultado de una sincronización (lo muestra la página de ajustes).
 */
function dlw_registrar_sync($datos) {
    update_option('dlw_last_sync', array_merge([
        'time'        => current_time('mysql'),
        'count'       => 0,
        'modo'        => '',
        'llamadas'    => 0,
        'actualizados'=> 0,
        'detalle'     => '',
    ], $datos), false);
}

// ════════════════════════════════════════════════════════════════
// Eventos activos
// ════════════════════════════════════════════════════════════════

/**
 * Convierte el valor de un campo fecha de JetEngine a timestamp.
 * JetEngine puede guardar 'Y-m-d' o un timestamp, según la opción
 * "Save as timestamp" del campo.
 */
function dlw_parse_fecha($valor) {
    if ($valor === '' || $valor === null || $valor === false) return 0;
    if (is_numeric($valor)) return (int) $valor;
    $ts = strtotime((string) $valor);
    return $ts ? $ts : 0;
}

/**
 * IDs de eventos publicados que están en curso o empiezan en los próximos
 * DLW_VENTANA_DIAS días (campos JetEngine fecha_de_inicio / fecha_de_fin).
 * Solo lee la base de datos local.
 */
function dlw_eventos_activos() {
    $ventana = (int) apply_filters('dlw_ventana_dias', DLW_VENTANA_DIAS);
    $hoy     = strtotime(current_time('Y-m-d')); // medianoche local
    $limite  = $hoy + $ventana * DAY_IN_SECONDS;

    $eventos = get_posts([
        'post_type'              => 'eventos',
        'post_status'            => 'publish',
        'fields'                 => 'ids',
        'posts_per_page'         => -1,
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => [[
            'key'     => 'event_product_ids',
            'value'   => '',
            'compare' => '!=',
        ]],
    ]);

    $activos = [];
    foreach ($eventos as $evento_id) {
        $inicio = dlw_parse_fecha(get_post_meta($evento_id, 'fecha_de_inicio', true));
        $fin    = dlw_parse_fecha(get_post_meta($evento_id, 'fecha_de_fin', true)) ?: $inicio;

        if (!$inicio && !$fin) {
            // Sin fechas: se considera activo solo si se ha editado hace poco.
            $modificado = get_post_modified_time('U', true, $evento_id);
            if ($modificado && $modificado >= time() - $ventana * DAY_IN_SECONDS) {
                $activos[] = $evento_id;
            }
            continue;
        }

        // En curso o por empezar dentro de la ventana (y no terminado ayer o antes).
        if ($fin >= $hoy - DAY_IN_SECONDS && $inicio <= $limite) {
            $activos[] = $evento_id;
        }
    }

    return $activos;
}

/**
 * IDs de producto usados por una lista de eventos.
 */
function dlw_productos_de_eventos($eventos) {
    $ids = [];
    foreach ((array) $eventos as $evento_id) {
        $csv = (string) get_post_meta($evento_id, 'event_product_ids', true);
        $ids = array_merge($ids, array_map('absint', explode(',', $csv)));
    }
    return array_values(array_unique(array_filter($ids)));
}

// ════════════════════════════════════════════════════════════════
// Sincronización nocturna ligera (cron diario)
// ════════════════════════════════════════════════════════════════

function dlw_sync_nocturna() {
    if (!dlw_sync_bloquear()) return;

    try {
        // 1) Alguien ha preparado eventos y el catálogo del buscador es viejo →
        //    sincronización completa (3 llamadas), aprovechando la noche.
        if (get_option('dlw_catalogo_pendiente') || dlw_count_productos() === 0) {
            delete_option('dlw_catalogo_pendiente');
            dlw_sync_all_productos(false, 'nocturna-completa');
            return;
        }

        // 2) Sin eventos activos → nada que hacer. 0 llamadas.
        $eventos_activos = dlw_eventos_activos();
        $ids_activos = dlw_productos_de_eventos($eventos_activos);
        if (!$ids_activos) {
            dlw_registrar_sync([
                'count'   => dlw_count_productos(),
                'modo'    => 'nocturna',
                'detalle' => 'Sin eventos activos: no se ha llamado a dlongwood.com.',
            ]);
            return;
        }

        $llamadas = 0;
        $guardados = 0;
        $ids_en_cache = dlw_ids_en_cache();

        // 3) Preguntar a dlongwood.com qué ha cambiado (1 llamada pequeña).
        $ultimo = (int) get_option('dlw_ultimo_check_cambios', 0);
        $desde  = $ultimo ? $ultimo - 10 * MINUTE_IN_SECONDS : time() - WEEK_IN_SECONDS;
        $cambios = dlw_api_get(add_query_arg('desde', $desde, DLW_API_CAMBIOS), 15);
        $llamadas++;

        if (!is_wp_error($cambios) && isset($cambios['publicados'], $cambios['modificados'])) {
            $publicados  = array_map('absint', (array) $cambios['publicados']);
            $modificados = array_map('absint', (array) $cambios['modificados']);

            // Bajas: solo si la lista parece completa (protección ante respuestas raras).
            $borrados = 0;
            if ($publicados && count($publicados) >= 0.5 * count($ids_en_cache)) {
                $borrados = dlw_borrar_productos_excepto($publicados);
            }

            // Lo que hay que descargar: modificados que nos interesan + activos que faltan.
            $a_descargar = array_unique(array_merge(
                array_intersect($modificados, array_merge($ids_en_cache, $ids_activos)),
                array_diff($ids_activos, $ids_en_cache)
            ));
            $a_descargar = array_values(array_intersect($a_descargar, $publicados));

            // Muchos cambios de golpe → sale más barato el catálogo completo (3 llamadas).
            if (count($a_descargar) > 30) {
                update_option('dlw_ultimo_check_cambios', (int) $cambios['ahora'], false);
                dlw_sync_all_productos(false, 'nocturna-completa');
                return;
            }

            foreach ($a_descargar as $id) {
                $llamadas++;
                if (dlw_sync_producto_individual($id) === 'guardado') $guardados++;
            }

            update_option('dlw_ultimo_check_cambios', (int) ($cambios['ahora'] ?? time()), false);

            dlw_registrar_sync([
                'count'        => dlw_count_productos(),
                'modo'         => 'nocturna',
                'llamadas'     => $llamadas,
                'actualizados' => $guardados,
                'detalle'      => sprintf(
                    '%d eventos activos · %d productos cambiados en origen · %d descargados · %d dados de baja.',
                    count($eventos_activos), count($modificados), count($a_descargar), $borrados
                ),
            ]);
            return;
        }

        // 4) Plan B: dlongwood.com aún no tiene /cambios → refrescar solo los
        //    productos de los eventos activos, uno a uno.
        foreach ($ids_activos as $id) {
            $llamadas++;
            if (dlw_sync_producto_individual($id) === 'guardado') $guardados++;
        }

        dlw_registrar_sync([
            'count'        => dlw_count_productos(),
            'modo'         => 'nocturna-sin-endpoint',
            'llamadas'     => $llamadas,
            'actualizados' => $guardados,
            'detalle'      => 'Endpoint /cambios no disponible (' .
                (is_wp_error($cambios) ? $cambios->get_error_message() : 'respuesta no válida') .
                '): se han refrescado solo los productos de eventos activos.',
        ]);
    } finally {
        dlw_sync_desbloquear();
    }
}

// ════════════════════════════════════════════════════════════════
// Sincronización completa
// ════════════════════════════════════════════════════════════════

/**
 * Descarga el catálogo completo (3 páginas de 100). Solo borra productos
 * locales si TODAS las páginas se han descargado bien.
 *
 * @param bool   $bloquear Usar el bloqueo (false si ya lo tiene quien llama).
 * @param string $modo     Etiqueta para el registro.
 * @return int|false Productos recibidos, o false si otra sync está en curso.
 */
function dlw_sync_all_productos($bloquear = true, $modo = 'completa') {
    if ($bloquear && !dlw_sync_bloquear()) return false;

    try {
        $page = 1;
        $max_pages = 1;
        $recibidos = 0;
        $guardados = 0;
        $remote_ids = [];
        $completa = true;
        $error = '';

        do {
            $body = dlw_api_get(add_query_arg([
                'lang'     => 'es',
                'fields'   => 'detallado',
                'per_page' => 100,
                'page'     => $page,
            ], DLW_API_BASE), 30);

            if (is_wp_error($body)) {
                $completa = false;
                $error = 'Error en página ' . $page . ': ' . $body->get_error_message();
                error_log('[DLW Sync] ' . $error);
                break;
            }

            $productos = $body['posts'] ?? $body;
            if (isset($productos['id'])) $productos = [$productos];
            if (!is_array($productos) || !$productos) break;

            foreach ($productos as $p) {
                if (!isset($p['id'])) continue;
                $remote_ids[] = absint($p['id']);
                $recibidos++;
                if (dlw_guardar_producto($p) === 'guardado') $guardados++;
            }

            $max_pages = (int) ($body['max_num_pages'] ?? 1);
            $page++;
        } while ($page <= $max_pages);

        // Borrar productos que ya no existen en remoto: SOLO con el catálogo completo.
        $borrados = ($completa && $remote_ids) ? dlw_borrar_productos_excepto($remote_ids) : 0;

        update_option('dlw_ultimo_catalogo_completo', time(), false);

        dlw_registrar_sync([
            'count'        => dlw_count_productos(),
            'modo'         => $modo,
            'llamadas'     => $page - 1 + ($completa ? 0 : 1),
            'actualizados' => $guardados,
            'detalle'      => $completa
                ? sprintf('%d recibidos · %d con cambios · %d dados de baja.', $recibidos, $guardados, $borrados)
                : $error . ' — no se ha borrado nada.',
        ]);

        return $recibidos;
    } finally {
        if ($bloquear) dlw_sync_desbloquear();
    }
}

/**
 * Sincroniza un producto individual por ID (webhook y sync nocturna).
 *
 * @return string|false 'guardado', 'igual' o false.
 */
function dlw_sync_producto_individual($id) {
    $id = absint($id);
    if (!$id) return false;

    $p = dlw_api_get(add_query_arg([
        'lang'   => 'es',
        'fields' => 'detallado',
    ], DLW_API_BASE . '/' . $id), 15);

    if (is_wp_error($p)) return false;
    if (isset($p['posts'][0])) $p = $p['posts'][0];
    if (!isset($p['id'])) return false;

    return dlw_guardar_producto($p);
}

// ════════════════════════════════════════════════════════════════
// Catálogo del buscador del admin: se marca para refrescar de noche
// ════════════════════════════════════════════════════════════════

/**
 * Si alguien abre la edición de un evento y el catálogo completo tiene más
 * de una semana, se pide una sincronización completa para esta noche.
 */
add_action('load-post.php', 'dlw_marcar_catalogo_si_viejo');
add_action('load-post-new.php', 'dlw_marcar_catalogo_si_viejo');
function dlw_marcar_catalogo_si_viejo() {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'eventos') return;

    $ultimo = (int) get_option('dlw_ultimo_catalogo_completo', 0);
    if ($ultimo < time() - WEEK_IN_SECONDS && !get_option('dlw_catalogo_pendiente')) {
        update_option('dlw_catalogo_pendiente', 1, false);
    }
}
