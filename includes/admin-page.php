<?php
/**
 * Página de ajustes del plugin en el admin.
 * Muestra estado de sincronización, botón de sync manual, estadísticas.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'DLW Eventos',
        'DLW Eventos',
        'manage_options',
        'dlw-eventos',
        'dlw_admin_page_render',
        'dashicons-grid-view',
        30
    );
});

function dlw_admin_page_render() {
    // Acción manual de sync
    if (isset($_POST['dlw_sync_now']) && wp_verify_nonce($_POST['_wpnonce'], 'dlw_sync_manual')) {
        $count = dlw_sync_all_productos();
        echo '<div class="notice notice-success"><p>Sincronización completada: <strong>' . $count . '</strong> productos actualizados.</p></div>';
    }

    $last_sync = get_option('dlw_last_sync', []);
    $total = dlw_count_productos();
    $next_cron = wp_next_scheduled('dlw_sync_productos_cron');
    ?>
    <div class="wrap">
        <h1>DLW Eventos — Estado del catálogo</h1>

        <div style="display:flex;gap:20px;margin-top:20px;">
            <!-- Card: Estado -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;flex:1;">
                <h2 style="margin-top:0;">Estado de sincronización</h2>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th>Productos en caché</th>
                        <td><strong style="font-size:1.5em;color:#0073aa;"><?php echo $total; ?></strong></td>
                    </tr>
                    <tr>
                        <th>Última sincronización</th>
                        <td>
                            <?php if (!empty($last_sync['time'])): ?>
                                <?php echo esc_html($last_sync['time']); ?>
                                (<?php echo esc_html($last_sync['count']); ?> productos)
                            <?php else: ?>
                                <em>Nunca</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Próxima sincronización automática</th>
                        <td>
                            <?php if ($next_cron): ?>
                                <?php echo esc_html(date_i18n('Y-m-d H:i:s', $next_cron)); ?>
                            <?php else: ?>
                                <em style="color:#c00;">No programada</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook URL</th>
                        <td>
                            <code style="background:#f0f0f0;padding:4px 8px;border-radius:4px;">
                                <?php echo esc_url(rest_url('dlw-eventos/v1/sync')); ?>
                            </code>
                            <p class="description">Configurar en dlongwood.com para sincronización instantánea.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Card: Acciones -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;width:300px;">
                <h2 style="margin-top:0;">Acciones</h2>
                <form method="post">
                    <?php wp_nonce_field('dlw_sync_manual'); ?>
                    <p>
                        <button type="submit" name="dlw_sync_now" class="button button-primary button-hero" style="width:100%;">
                            Sincronizar ahora
                        </button>
                    </p>
                    <p class="description">Descarga todos los productos del catálogo de dlongwood.com y actualiza la caché local.</p>
                </form>
                <hr>
                <h3>Instrucciones de migración</h3>
                <ol style="font-size:13px;line-height:1.6;">
                    <li>Lanzar primera sincronización (botón de arriba)</li>
                    <li>Desactivar los 3 code snippets</li>
                    <li>Desactivar campo HTML de JetEngine</li>
                    <li>Verificar que todo funciona igual</li>
                </ol>
            </div>
        </div>
    </div>
    <?php
}
