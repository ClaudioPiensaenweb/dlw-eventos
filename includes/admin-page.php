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
    if (!current_user_can('manage_options')) return;

    // Acción manual de sync
    if (isset($_POST['dlw_sync_now']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'dlw_sync_manual')) {
        $count = dlw_sync_all_productos(true, 'manual');
        if ($count === false) {
            echo '<div class="notice notice-warning"><p>Ya hay una sincronización en curso. Vuelve a intentarlo en unos minutos.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Sincronización completada: <strong>' . (int) $count . '</strong> productos recibidos.</p></div>';
        }
    }

    // Guardar hora de la sincronización nocturna
    if (isset($_POST['dlw_guardar_hora']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'dlw_guardar_hora')) {
        update_option('dlw_sync_hora', min(23, max(0, (int) ($_POST['dlw_sync_hora'] ?? 4))), false);
        dlw_programar_cron(true);
        echo '<div class="notice notice-success"><p>Hora de sincronización guardada.</p></div>';
    }

    $last_sync = get_option('dlw_last_sync', []);
    $total = dlw_count_productos();
    $next_cron = wp_next_scheduled(DLW_CRON_HOOK);
    $eventos_activos = dlw_eventos_activos();
    $hora = dlw_hora_sync();
    $wp_cron_desactivado = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
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
                                <?php if (!empty($last_sync['modo'])): ?>
                                    · <em><?php echo esc_html($last_sync['modo']); ?></em>
                                <?php endif; ?>
                                · <?php echo (int) ($last_sync['llamadas'] ?? 0); ?> llamadas a dlongwood.com
                                <?php if (!empty($last_sync['detalle'])): ?>
                                    <p class="description"><?php echo esc_html($last_sync['detalle']); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>Nunca</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Eventos activos</th>
                        <td>
                            <strong><?php echo count($eventos_activos); ?></strong>
                            <p class="description">En curso o empiezan en los próximos <?php echo (int) DLW_VENTANA_DIAS; ?> días. Si no hay ninguno, la sincronización nocturna no llama a dlongwood.com.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Próxima sincronización automática</th>
                        <td>
                            <?php if ($next_cron): ?>
                                <?php echo esc_html(wp_date('Y-m-d H:i', $next_cron)); ?>
                            <?php else: ?>
                                <em style="color:#c00;">No programada</em>
                            <?php endif; ?>
                            <?php if (!$wp_cron_desactivado): ?>
                                <p class="description">WP-Cron depende de las visitas: se ejecuta con la primera visita después de esa hora. Para que sea exacta, define <code>DISABLE_WP_CRON</code> y programa <code>wp-cron.php</code> desde Plesk.</p>
                            <?php endif; ?>
                            <?php if (get_option('dlw_catalogo_pendiente')): ?>
                                <p class="description">Esta noche se descargará el catálogo completo (se han editado eventos y el buscador tenía más de una semana).</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook</th>
                        <td>
                            <code style="background:#f0f0f0;padding:4px 8px;border-radius:4px;">
                                <?php echo esc_url(rest_url('dlw-eventos/v1/sync')); ?>
                            </code>
                            <p class="description">Secreto: <code><?php echo esc_html(dlw_webhook_secret()); ?></code></p>
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
                    <p class="description">Descarga todos los productos del catálogo de dlongwood.com y actualiza la caché local. Mejor fuera de horas punta.</p>
                </form>
                <hr>
                <h3>Hora de la sincronización nocturna</h3>
                <form method="post">
                    <?php wp_nonce_field('dlw_guardar_hora'); ?>
                    <select name="dlw_sync_hora">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <option value="<?php echo $h; ?>" <?php selected($hora, $h); ?>><?php echo sprintf('%02d:10', $h); ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" name="dlw_guardar_hora" class="button">Guardar</button>
                    <p class="description">Hora de <?php echo esc_html(wp_timezone_string()); ?>.</p>
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
