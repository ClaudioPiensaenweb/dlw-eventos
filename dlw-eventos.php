<?php
/**
 * Plugin Name: DLW Eventos — Catálogo de productos
 * Description: Gestión de productos remotos de dlongwood.com para eventos/congresos. Reemplazo drop-in de los code snippets actuales.
 * Version: 1.2.0
 * Author: Piensaenweb
 * Text Domain: dlw-eventos
 *
 * INSTRUCCIONES DE MIGRACIÓN:
 * 1. Subir este plugin a wp-content/plugins/dlw-eventos/
 * 2. Activar el plugin
 * 3. Ir a DLW Eventos > Ajustes y lanzar la primera sincronización
 * 4. Desactivar los 3 code snippets actuales:
 *    - "Editar evento - Cargar Productos"
 *    - "Productos - Archive Evento"
 *    - "Producto - FICHA INDIVIDUAL"
 * 5. Desactivar el campo HTML de JetEngine "productos_catalogo_ids"
 *    (el plugin registra su propio metabox nativo)
 * 6. NO tocar nada en Bricks Builder — mismos shortcodes, mismas clases
 */

if (!defined('ABSPATH')) exit;

define('DLW_EVENTOS_VERSION', '1.2.0');
define('DLW_EVENTOS_PATH', plugin_dir_path(__FILE__));
define('DLW_EVENTOS_URL', plugin_dir_url(__FILE__));
define('DLW_API_BASE', 'https://www.dlongwood.com/wp-json/catalogo/v1/productos');
define('DLW_WP_API_BASE', 'https://www.dlongwood.com/wp-json/wp/v2/');
define('DLW_SYNC_INTERVAL', 6 * HOUR_IN_SECONDS); // Sync cada 6 horas
define('DLW_CACHE_TABLE', 'dlw_productos_cache');

// ════════════════════════════════════════════════════════════════
// Activación / Desactivación
// ════════════════════════════════════════════════════════════════
register_activation_hook(__FILE__, 'dlw_eventos_activate');
register_deactivation_hook(__FILE__, 'dlw_eventos_deactivate');

function dlw_eventos_activate() {
    dlw_create_cache_table();
    if (!wp_next_scheduled('dlw_sync_productos_cron')) {
        wp_schedule_event(time(), 'dlw_six_hours', 'dlw_sync_productos_cron');
    }
    // Primera sync al activar
    dlw_sync_all_productos();
}

function dlw_eventos_deactivate() {
    wp_clear_scheduled_hook('dlw_sync_productos_cron');
}

// Intervalo custom de 6 horas
add_filter('cron_schedules', function ($schedules) {
    $schedules['dlw_six_hours'] = [
        'interval' => DLW_SYNC_INTERVAL,
        'display'  => 'Cada 6 horas (DLW Sync)',
    ];
    return $schedules;
});

// Cron hook
add_action('dlw_sync_productos_cron', 'dlw_sync_all_productos');

// ════════════════════════════════════════════════════════════════
// Crear tabla de caché
// ════════════════════════════════════════════════════════════════
function dlw_create_cache_table() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        producto_id BIGINT UNSIGNED NOT NULL,
        titulo VARCHAR(500) NOT NULL DEFAULT '',
        extracto TEXT NOT NULL,
        descripcion LONGTEXT NOT NULL,
        imagen_destacada VARCHAR(500) NOT NULL DEFAULT '',
        meta_fields LONGTEXT NOT NULL,
        taxonomias LONGTEXT NOT NULL,
        enlace VARCHAR(500) NOT NULL DEFAULT '',
        datos_json LONGTEXT NOT NULL,
        synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (producto_id),
        KEY idx_synced (synced_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ════════════════════════════════════════════════════════════════
// Includes
// ════════════════════════════════════════════════════════════════
require_once DLW_EVENTOS_PATH . 'includes/sync.php';
require_once DLW_EVENTOS_PATH . 'includes/cache.php';
require_once DLW_EVENTOS_PATH . 'includes/metabox.php';
require_once DLW_EVENTOS_PATH . 'includes/shortcodes.php';
require_once DLW_EVENTOS_PATH . 'includes/ajax.php';
require_once DLW_EVENTOS_PATH . 'includes/webhook.php';
require_once DLW_EVENTOS_PATH . 'includes/admin-page.php';

// ════════════════════════════════════════════════════════════════
// Auto-updater desde GitHub (YahnisElsts/plugin-update-checker)
// ════════════════════════════════════════════════════════════════
require_once DLW_EVENTOS_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$dlwUpdater = PucFactory::buildUpdateChecker(
    'https://github.com/ClaudioPiensaenweb/dlw-eventos/',  // URL del repo GitHub
    __FILE__,                                         // Archivo principal del plugin
    'dlw-eventos'                                     // Slug del plugin
);

// Usar releases de GitHub (tags)
$dlwUpdater->getVcsApi()->enableReleaseAssets();

// ════════════════════════════════════════════════════════════════
// Assets
// ════════════════════════════════════════════════════════════════

// Admin
add_action('admin_enqueue_scripts', function ($hook) {
    // Metabox en edición de eventos
    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'eventos') {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
            wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
            wp_enqueue_style('dlw-admin-css', DLW_EVENTOS_URL . 'assets/admin.css', [], DLW_EVENTOS_VERSION);
            wp_enqueue_script('dlw-admin-js', DLW_EVENTOS_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'select2-js'], DLW_EVENTOS_VERSION, true);

            global $post;
            wp_localize_script('dlw-admin-js', 'DLWEventos', [
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('dlw_eventos_nonce'),
                'selected' => get_post_meta($post->ID, 'event_product_ids', true),
            ]);
        }
    }
});

// Asegurar que el cron siempre esté programado
add_action('init', function () {
    if (!wp_next_scheduled('dlw_sync_productos_cron')) {
        wp_schedule_event(time(), 'dlw_six_hours', 'dlw_sync_productos_cron');
    }
});

// Frontend
add_action('wp_enqueue_scripts', function () {
    if (!is_admin()) {
        wp_enqueue_script('jquery');
        wp_enqueue_style('dlw-frontend-css', DLW_EVENTOS_URL . 'assets/frontend.css', [], DLW_EVENTOS_VERSION);
        wp_enqueue_style('dlw-producto-css', DLW_EVENTOS_URL . 'assets/producto.css', [], DLW_EVENTOS_VERSION);
        wp_enqueue_script('dlw-frontend-js', DLW_EVENTOS_URL . 'assets/frontend.js', ['jquery'], DLW_EVENTOS_VERSION, true);

        // Nombre del evento (post_title) para el formulario JetFormBuilder de la ficha de producto.
        // El ID llega por query string (?evento=2010); lo resolvemos en servidor → 0 llamadas HTTP extra.
        $evento_id = absint($_GET['evento'] ?? 0);
        $nombre_evento = ($evento_id && get_post_type($evento_id) === 'eventos')
            ? html_entity_decode(get_the_title($evento_id), ENT_QUOTES, 'UTF-8')
            : '';

        wp_localize_script('dlw-frontend-js', 'dlwFront', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('dlw_frontend_nonce'),
            'nombreEvento' => $nombre_evento,
        ]);
    }
});
