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

/** Logos de los sellos (grid y ficha). */
function dlw_sellos_img() {
    return [
        'IVD' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ivd-logo.png',
        'CE'  => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ce-logo.png',
        'RUO' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/ruo-logo.png',
        'PSO' => 'https://www.dlongwood.com/wp-content/uploads/2024/09/pso-logo.png',
    ];
}

/** Texto plano con entidades decodificadas (™, …) y escapado para HTML. */
function dlw_texto($str) {
    return esc_html(html_entity_decode((string) $str, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Tarjeta de producto del grid. Mismo marcado y clases que generaba antes
 * frontend.js, más data-* con las taxonomías para filtrar en el navegador.
 */
function dlw_render_tarjeta($p, $evento_id) {
    $sellos_img = dlw_sellos_img();
    $sx = '';
    $sellos = $p->meta_fields->sellos ?? [];
    foreach ((array) $sellos as $sello) {
        $clave = strtoupper(trim((string) $sello));
        if (isset($sellos_img[$clave])) {
            $sx .= '<img width="82" height="60" src="' . esc_url($sellos_img[$clave])
                 . '" class="brxe-image css-filter sello-' . esc_attr(strtolower($clave)) . '" alt="" loading="lazy">';
        }
    }
    if ($sx) $sx = '<div class="brxe-iuxwmo brxe-block">' . $sx . '</div>';

    $extracto = html_entity_decode((string) $p->extracto, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $url = add_query_arg(array_filter([
        'producto' => $p->id,
        'evento'   => $evento_id,
    ]), home_url('/'));

    $tax = [];
    foreach (['marcas', 'tecnologias', 'familias'] as $t) {
        $tax[$t] = array_values(array_map('strval', (array) ($p->taxonomias->{$t} ?? [])));
    }

    $titulo = dlw_texto($p->titulo);

    return '<a href="' . esc_url($url) . '" class="brxe-myhzzt brxe-block jsfb-filterable jsfb-query--productos"'
         . ' data-producto="' . (int) $p->id . '" data-tax="' . esc_attr(wp_json_encode($tax)) . '">'
         .   '<div class="brxe-enlgrr brxe-block">' . $sx
         .     '<img width="350" height="350" src="' . esc_url($p->imagen_destacada) . '" class="brxe-frfswr brxe-image producto-imagen css-filter size-medium_large" alt="' . $titulo . '" loading="lazy" decoding="async">'
         .   '</div>'
         .   '<h3 class="brxe-vmggvb brxe-heading texto-equilibrado producto-titulo">' . $titulo . '</h3>'
         .   '<div class="brxe-esybzj brxe-text-basic extracto-listing">' . esc_html($extracto) . '</div>'
         . '</a>';
}

/** IDs de producto de un evento (CSV en event_product_ids). */
function dlw_ids_evento($evento_id) {
    $ids_raw = (string) get_post_meta($evento_id, 'event_product_ids', true);
    return array_values(array_unique(array_filter(array_map('absint', explode(',', $ids_raw)))));
}

// Grid de productos: se pinta en el servidor (HTML cacheable, sin AJAX ni
// "parpadeo" del esqueleto). Los filtros solo ocultan/muestran tarjetas en JS.
add_shortcode('productos_remotos_grid', function ($atts) {
    $atts = shortcode_atts([
        'evento_id'       => 0,
        'producto_actual' => 0,
        'filtros'         => '{}',
    ], $atts);

    dlw_enqueue_frontend();

    $evento_id = absint($atts['evento_id'] ?: get_the_ID());
    $producto_actual = absint($atts['producto_actual']);

    $ids = dlw_ids_evento($evento_id);
    if ($producto_actual) {
        $ids = array_values(array_diff($ids, [$producto_actual]));
    }
    if (!$ids) return '<div id="brxe-d807f8"></div>';

    $html = '';
    foreach (dlw_get_productos_listado($ids) as $p) {
        $html .= dlw_render_tarjeta($p, $evento_id);
    }
    if (!$html) $html = '<div class="dlw-sin-productos" style="padding:2em;">No hay productos con este filtro.</div>';

    return '<div id="brxe-d807f8" class="brxe-block brx-grid grid-4" data-ids="'
         . esc_attr(implode(',', $ids)) . '" data-evento-id="' . esc_attr($evento_id) . '">'
         . $html . '</div>';
});

// Filtros — función DRY
function dlw_render_filtro_shortcode($atts, $taxonomia) {
    dlw_enqueue_frontend();
    $atts = shortcode_atts(['evento_id' => 0], $atts);
    $evento_id = absint($atts['evento_id'] ?: get_the_ID());
    $ids_raw = get_post_meta($evento_id, 'event_product_ids', true);
    if (!$ids_raw) return '';

    $valores = dlw_get_taxonomia_valores(dlw_ids_evento($evento_id), $taxonomia);

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
            $sellos_img = dlw_sellos_img();

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
