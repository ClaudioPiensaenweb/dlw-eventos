<?php
/**
 * Metabox nativo para selector de productos en CPT 'eventos'.
 * Reemplaza el campo HTML de JetEngine.
 * Lee de la tabla local (sin llamadas API).
 */
if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () {
    add_meta_box(
        'dlw_productos_selector',
        'Productos del catálogo',
        'dlw_render_metabox',
        'eventos',
        'normal',
        'high'
    );
});

function dlw_render_metabox($post) {
    wp_nonce_field('dlw_metabox_save', 'dlw_metabox_nonce');
    $selected_ids = get_post_meta($post->ID, 'event_product_ids', true);
    ?>
    <input type="hidden" id="event_product_ids" name="event_product_ids" value="<?php echo esc_attr($selected_ids); ?>">

    <!-- Buscador Select2 -->
    <div id="dlw-buscar-container" style="margin-bottom:1em;">
        <select id="dlw-buscar-select" style="width:100%;"></select>
    </div>

    <!-- Grid de cards -->
    <div id="dlw-productos-grid" style="margin-bottom:1.5em;"></div>

    <!-- Importación masiva -->
    <div id="dlw-importar-bloque" style="margin-top:1.5em;margin-bottom:1em;background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:15px 20px 10px 15px;">
        <div style="margin-bottom:14px;">
            <strong>Añadir productos por:</strong>
            <button type="button" class="dlw-btn-importar active" data-tipo="marcas">Marca</button>
            <button type="button" class="dlw-btn-importar" data-tipo="tecnologias">Tecnología</button>
            <button type="button" class="dlw-btn-importar" data-tipo="familias">Área</button>
            <button type="button" id="dlw-borrar-todos" style="margin-left:20px;background:#c00;color:#fff;border:none;border-radius:3px;padding:6px 12px;cursor:pointer;">Borrar todo</button>
        </div>
        <div style="display:flex;align-items:center;flex-wrap:wrap;margin-bottom:12px;gap:12px;">
            <select id="dlw-select-filtro" style="min-width:240px;max-width:350px;"></select>
            <button type="button" id="dlw-btn-previsualizar">Previsualizar</button>
            <span id="dlw-status" style="margin-left:15px;color:#0073aa;font-size:0.97em;"></span>
        </div>
        <div id="dlw-preview-wrap"></div>
    </div>
    <?php
}

// Guardar meta al guardar el post
add_action('save_post_eventos', function ($post_id) {
    if (!isset($_POST['dlw_metabox_nonce']) || !wp_verify_nonce($_POST['dlw_metabox_nonce'], 'dlw_metabox_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['event_product_ids'])) {
        $ids = sanitize_text_field($_POST['event_product_ids']);
        // Sanitizar: solo dígitos y comas
        $ids = implode(',', array_filter(array_map('absint', explode(',', $ids))));
        update_post_meta($post_id, 'event_product_ids', $ids);
    }
});
