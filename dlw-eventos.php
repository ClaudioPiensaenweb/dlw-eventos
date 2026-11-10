<?php
/**
 * Plugin Name: DLW Eventos — Catálogo de productos
 * Description: Gestión de productos remotos de dlongwood.com para eventos/congresos. Reemplazo drop-in de los code snippets actuales.
 * Version: 1.3.0
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

define('DLW_EVENTOS_VERSION', '1.3.0');
define('DLW_DB_VERSION', '2');
define('DLW_EVENTOS_PATH', plugin_dir_path(__FILE__));
define('DLW_EVENTOS_URL', plugin_dir_url(__FILE__));
define('DLW_API_BASE', 'https://www.dlongwood.com/wp-json/catalogo/v1/productos');
define('DLW_API_CAMBIOS', 'https://www.dlongwood.com/wp-json/catalogo/v1/cambios');
define('DLW_WP_API_BASE', 'https://www.dlongwood.com/wp-json/wp/v2/');
define('DLW_CACHE_TABLE', 'dlw_productos_cache');
define('DLW_CRON_HOOK', 'dlw_sync_productos_cron');
// Un evento cuenta como "activo" si está en curso o empieza en los próximos N días.
if (!defined('DLW_VENTANA_DIAS')) define('DLW_VENTANA_DIAS', 30);

// ════════════════════════════════════════════════════════════════
// Activación / Desactivación / Actualización
// ════════════════════════════════════════════════════════════════
register_activation_hook(__FILE__, 'dlw_eventos_activate');
register_deactivation_hook(__FILE__, 'dlw_eventos_deactivate');

function dlw_eventos_activate() {
    dlw_upgrade();
    // Primera carga del catálogo solo si la tabla está vacía.
    if (dlw_count_productos() === 0) {
        dlw_sync_all_productos();
    }
}

function dlw_eventos_deactivate() {
    wp_clear_scheduled_hook(DLW_CRON_HOOK);
}

// Migraciones al actualizar el plugin (el auto-updater no lanza la activación).
add_action('plugins_loaded', function () {
    if (get_option('dlw_db_version') !== DLW_DB_VERSION) {
        dlw_upgrade();
    }
});

function dlw_upgrade() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;

    dlw_create_cache_table();

    // v2: datos_json duplicaba todo el contenido y no se leía nunca → fuera.
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'datos_json'")) {
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN datos_json");
    }

    // Secreto del webhook propio de esta instalación (el de antes era público en GitHub).
    if (!defined('DLW_WEBHOOK_SECRET') && !get_option('dlw_webhook_secret')) {
        update_option('dlw_webhook_secret', wp_generate_password(40, false), false);
    }

    // Pasar del cron de 6 h al diario de madrugada.
    dlw_programar_cron(true);

    update_option('dlw_db_version', DLW_DB_VERSION, true);
}

// ════════════════════════════════════════════════════════════════
// Cron: una vez al día, de madrugada (hora configurable en Ajustes)
// ════════════════════════════════════════════════════════════════
add_action(DLW_CRON_HOOK, 'dlw_sync_nocturna');

function dlw_hora_sync() {
    return min(23, max(0, (int) get_option('dlw_sync_hora', 4)));
}

/** Timestamp de la próxima HH:10 en la zona horaria del sitio. */
function dlw_proxima_ejecucion() {
    $tz = wp_timezone();
    $ahora = new DateTime('now', $tz);
    $cuando = (clone $ahora)->setTime(dlw_hora_sync(), 10, 0);
    if ($cuando <= $ahora) $cuando->modify('+1 day');
    return $cuando->getTimestamp();
}

function dlw_programar_cron($reprogramar = false) {
    if ($reprogramar) wp_clear_scheduled_hook(DLW_CRON_HOOK);
    if (!wp_next_scheduled(DLW_CRON_HOOK)) {
        wp_schedule_event(dlw_proxima_ejecucion(), 'daily', DLW_CRON_HOOK);
    }
}

// Asegurar que el cron siempre esté programado (lectura de una opción autocargada: coste nulo).
add_action('init', function () {
    dlw_programar_cron();
});

// ════════════════════════════════════════════════════════════════
// Crear tabla de caché
// ════════════════════════════════════════════════════════════════
function dlw_create_cache_table() {
    global $wpdb;
    $table = $wpdb->prefix . DLW_CACHE_TABLE;
    $charset = $wpdb->get_charset_collate();

    // Formato estricto de dbDelta: sin "IF NOT EXISTS" y dos espacios tras PRIMARY KEY.
    $sql = "CREATE TABLE {$table} (
  producto_id bigint(20) unsigned NOT NULL,
  titulo varchar(500) NOT NULL DEFAULT '',
  extracto text NOT NULL,
  descripcion longtext NOT NULL,
  imagen_destacada varchar(500) NOT NULL DEFAULT '',
  meta_fields longtext NOT NULL,
  taxonomias longtext NOT NULL,
  enlace varchar(500) NOT NULL DEFAULT '',
  hash char(32) NOT NULL DEFAULT '',
  synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (producto_id),
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

// Frontend: los assets solo se cargan donde hacen falta (evento, ficha de producto
// o página con alguno de los shortcodes), no en todo el sitio.
add_action('wp_enqueue_scripts', function () {
    wp_register_style('dlw-frontend-css', DLW_EVENTOS_URL . 'assets/frontend.css', [], DLW_EVENTOS_VERSION);
    wp_register_style('dlw-producto-css', DLW_EVENTOS_URL . 'assets/producto.css', [], DLW_EVENTOS_VERSION);
    wp_register_script('dlw-frontend-js', DLW_EVENTOS_URL . 'assets/frontend.js', ['jquery'], DLW_EVENTOS_VERSION, true);

    if (is_singular('eventos') || !empty($_GET['producto']) || !empty($_GET['evento'])) {
        dlw_enqueue_frontend();
    }
});

/**
 * Encola CSS + JS del frontend. Los shortcodes también lo llaman, por si se
 * usan en una plantilla que no sea la del evento.
 */
function dlw_enqueue_frontend() {
    static $hecho = false;
    if ($hecho || is_admin()) return;
    if (!wp_script_is('dlw-frontend-js', 'registered')) return; // antes de wp_enqueue_scripts
    $hecho = true;

    wp_enqueue_style('dlw-frontend-css');
    wp_enqueue_style('dlw-producto-css');
    wp_enqueue_script('dlw-frontend-js');

    // Nombre del evento (post_title) para el formulario JetFormBuilder de la ficha de producto.
    // El ID llega por query string (?evento=2010); lo resolvemos en servidor → 0 llamadas HTTP extra.
    // Sin nonce: el grid ya no usa AJAX, así que la página se puede cachear sin problemas.
    $evento_id = absint($_GET['evento'] ?? 0);
    $nombre_evento = ($evento_id && get_post_type($evento_id) === 'eventos')
        ? html_entity_decode(get_the_title($evento_id), ENT_QUOTES, 'UTF-8')
        : '';

    wp_localize_script('dlw-frontend-js', 'dlwFront', [
        'nombreEvento' => $nombre_evento,
    ]);
}
