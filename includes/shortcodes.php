<?php
/**
 * Shortcodes — reemplazo drop-in de los snippets 2 y 3.
 * Mismos nombres, mismos atributos, mismas clases CSS.
 * La única diferencia: leen de la tabla local, no de la API remota.
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════
// SNIPPET 2: Archive Evento
// ════════════════════════════════════════════════════════════════

// Grid de productos
add_shortcode('productos_remotos_grid', function ($atts) {
    $atts = shortcode_atts([
        'evento_id'       => 0,
        'producto_actual' => 0,
        'filtros'         => '{}',
    ], $atts);

    $evento_id = absint($atts['evento_id'] ?: get_the_ID());
    $producto_actual = absint($atts['producto_actual']);
    $ids_raw = get_post_meta($evento_id, 'event_product_ids', true);

    if (!$ids_raw) return '<div id="brxe-d807f8"></div>';

    $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $ids_raw)))));
    if ($producto_actual) {
        $ids = array_values(array_diff($ids, [$producto_actual]));
    }
    if (!$ids) return '<div id="brxe-d807f8"></div>';

    return '<div id="brxe-d807f8" class="brxe-block brx-grid grid-4" data-ids="'
         . esc_attr(implode(',', $ids)) . '" data-evento-id="' . esc_attr($evento_id) . '"></div>';
});

// Filtros — función DRY
function dlw_render_filtro_shortcode($atts, $taxonomia) {
    $atts = shortcode_atts(['evento_id' => 0], $atts);
    $evento_id = absint($atts['evento_id'] ?: get_the_ID());
    $ids_raw = get_post_meta($evento_id, 'event_product_ids', true);
    if (!$ids_raw) return '';

    $ids = array_map('absint', explode(',', $ids_raw));
    $valores = dlw_get_taxonomia_valores($ids, $taxonomia);

    if (empty($valores)) return '';

    $html = '<div class="evento-filtro-grupo"><div class="evento-filtro-opciones">';
    foreach ($valores as $val) {
        $html .= '<button type="button" class="evento-filtro-btn" data-evento="'
               . esc_attr($evento_id) . '" data-filtro="' . esc_attr($taxonomia)
               . '" data-valor="' . esc_attr($val) . '">' . esc_html($val) . '</button>';
    }
    $html .= '</div></div>';
    return $html;
}

add_shortcode('filtro_evento_familias', function ($atts) {
    return dlw_render_filtro_shortcode($atts, 'familias');
});
add_shortcode('filtro_evento_marcas', function ($atts) {
    return dlw_render_filtro_shortcode($atts, 'marcas');
});
add_shortcode('filtro_evento_tecnologias', function ($atts) {
    return dlw_render_filtro_shortcode($atts, 'tecnologias');
});

add_shortcode('aplicar_filtros_btn', function () {
    return '<button id="btn-aplicar-filtros" class="btn-aplicar-filtros">Aplicar filtros</button>';
});

add_shortcode('filtros_evento_activos', function () {
    ob_start(); ?>
    <div class="jet-smart-filters-active jet-active-filters" style="display:none;">
      <div class="jet-active-filters__list" id="jet-active-filters-list"></div>
    </div>
    <?php return ob_get_clean();
});


// ════════════════════════════════════════════════════════════════
// SNIPPET 3: Ficha Individual
// ════════════════════════════════════════════════════════════════

add_shortcode('producto_api', function ($atts) {
    $atts = shortcode_atts([
        'campo' => '',
        'id'    => absint($_GET['producto'] ?? 0),
    ], $atts);

    $id = absint($atts['id']);
    $campo = sanitize_key($atts['campo']);
    if (!$id || !$campo) return '';

    $p = dlw_get_producto($id);
    if (!$p) return '';

    switch ($campo) {
        case 'titulo':
            return esc_html($p->titulo ?? '');

        case 'descripcion':
            remove_filter('the_content', 'wpautop');
            $output = apply_filters('the_content', $p->descripcion ?? '');
            add_filter('the_content', 'wpautop');
            return $output;

        case 'extracto':
            return esc_html($p->extracto ?? '');

        case 'imagen_destacada':
            return esc_url($p->imagen_destacada ?? '');

        case 'img_tag':
            $sellos_img = [
                'IVD' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ivd-logo.png',
                'CE'  => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ce-logo.png',
                'RUO' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ruo-logo.png',
                'PSO' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/pso-logo.png',
            ];

            $sx = '';
            $sellos = (array) ($p->meta_fields->sellos ?? []);
            foreach ($sellos as $s) {
                $c = strtoupper(trim((string) $s));
                if (isset($sellos_img[$c])) {
                    $sx .= '<img width="104" height="60" src="' . esc_url($sellos_img[$c])
                         . '" class="brxe-image css-filter size-full sello-' . esc_attr(strtolower($c))
                         . '" alt="" loading="lazy">';
                }
            }
            if ($sx) $sx = '<div id="brxe-kshxob" class="brxe-block">' . $sx . '</div>';

            $img_url = esc_url($p->imagen_destacada ?? '');
            $img_alt = esc_attr($p->titulo ?? '');
            if (!$img_url) return $sx;

            ob_start(); ?>
            <div id="brxe-qazdce" class="brxe-block">
                <?php echo $sx; ?>
                <img width="350" height="350"
                     src="<?php echo $img_url; ?>"
                     class="brxe-image css-filter size-medium"
                     alt="<?php echo $img_alt; ?>"
                     id="brxe-vawnha"
                     loading="lazy">
            </div>
            <?php return ob_get_clean();

        case 'imagen_superior':
            return esc_url($p->meta_fields->imagen_superior ?? '');

        case 'especificaciones_titulo':
            return esc_html($p->meta_fields->especificaciones_titulo ?? '');

        case 'especificaciones_contenido':
            $contenido = $p->meta_fields->especificaciones_contenido ?? '';
            return $contenido ? apply_filters('the_content', $contenido) : '';

        case 'enlace':
            return esc_url($p->enlace ?? '');

        case 'documento_enlace':
            return esc_url($p->meta_fields->documentos->{'item-0'}->{'docs-enlace'} ?? '');

        case 'documento_titulo':
            return esc_html($p->meta_fields->documentos->{'item-0'}->{'docs-titulo'} ?? '');

        case 'producto_relacionado_id':
            return absint($p->meta_fields->productos_relacionados[0] ?? 0) ?: '';

        case 'marcas':
            return esc_html(implode(', ', (array) ($p->taxonomias->marcas ?? [])));

        case 'tecnologias':
            return esc_html(implode(', ', (array) ($p->taxonomias->tecnologias ?? [])));

        case 'familias':
            return esc_html(implode(', ', (array) ($p->taxonomias->familias ?? [])));

        default:
            return '';
    }
});

add_shortcode('filtro_evento_boton', function ($atts) {
    $atts = shortcode_atts([
        'tipo'     => '',
        'producto' => absint($_GET['producto'] ?? 0),
        'evento'   => absint($_GET['evento'] ?? 0),
    ], $atts);

    $producto_id = absint($atts['producto']);
    $tipo = sanitize_key($atts['tipo']);
    if (!$producto_id || !$tipo) return '';

    $p = dlw_get_producto($producto_id);
    if (!$p || !isset($p->taxonomias->{$tipo}) || empty($p->taxonomias->{$tipo}[0])) return '';

    $v = $p->taxonomias->{$tipo}[0];
    $url = esc_url(add_query_arg([
        'evento' => absint($atts['evento']),
        'filtro' => $tipo,
        'valor'  => rawurlencode($v),
    ], home_url()));

    return '<a href="' . $url . '" class="brxe-button filtro-evento">Ver más '
         . esc_html(ucfirst($tipo)) . ': ' . esc_html($v) . '</a>';
});

add_shortcode('evento_volver_btn', function ($atts) {
    $atts = shortcode_atts([
        'evento_id' => 0,
        'label'     => 'Ver todos los productos',
        'icon'      => 'ti-arrow-left',
        'class'     => 'brxe-block volver-btn',
    ], $atts);

    $evento_id = absint($atts['evento_id']);
    if (!$evento_id) $evento_id = absint($_GET['evento'] ?? 0);
    if (!$evento_id) return '';

    $evento = get_post($evento_id);
    if (!$evento || $evento->post_type !== 'eventos') return '';

    $url = get_permalink($evento_id);
    if (!$url) return '';

    ob_start(); ?>
    <a href="<?php echo esc_url($url); ?>"
       class="<?php echo esc_attr($atts['class']); ?>"
       style="display:inline-flex;align-items:center;gap:10px;padding:12px 24px;border-radius:9px;background:#e6f0fa;color:#15496d;font-weight:600;font-size:1.15em;text-decoration:none;transition:.15s;border:none;">
        <i class="<?php echo esc_attr($atts['icon']); ?>" style="font-size:1.4em;vertical-align:middle;"></i>
        <span><?php echo esc_html($atts['label']); ?></span>
    </a>
    <?php return ob_get_clean();
});

add_shortcode('evento_catalogo', function ($atts) {
    $atts = shortcode_atts([
        'evento' => absint($_GET['evento'] ?? 0),
    ], $atts);
    $evento_id = absint($atts['evento']);
    if (!$evento_id) return '';
    $catalogo = get_post_meta($evento_id, 'enlace_folleto', true);
    return $catalogo ? esc_url($catalogo) : '';
});
