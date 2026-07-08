jQuery(function($){
  var filtrosActivos = {};
  var nonce = (window.dlwFront && window.dlwFront.nonce) || '';
  var ajaxurl = (window.dlwFront && window.dlwFront.ajaxUrl) || window.ajaxurl || '';

  function getGridData() {
    var $grid = $('#brxe-d807f8');
    return ($grid.data('ids') || '').toString().split(',').filter(Boolean);
  }

  // Decodifica entidades HTML (&#x2122; → ™, &hellip; → …) y luego
  // escapa solo los caracteres peligrosos (<, >, &, ") para prevenir XSS
  function decodeHtml(str) {
    if (!str) return '';
    var txt = document.createElement('textarea');
    txt.innerHTML = str;
    return txt.value;
  }

  function escHtml(str) {
    // Primero decodificar entidades que vienen de la DB
    var decoded = decodeHtml(str);
    // Luego escapar para inserción segura en HTML
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(decoded));
    return div.innerHTML;
  }

  function renderGrid(productos) {
    var $grid = $('#brxe-d807f8');
    $grid.empty();
    var evento_id = $grid.data('evento-id') || '';
    var site_url = window.location.origin || 'https://eventos.dlongwood.com';

    var sellos_img = {
      'IVD': 'https://www.dlongwood.com/wp-content/uploads/2024/09/ivd-logo.png',
      'CE' : 'https://www.dlongwood.com/wp-content/uploads/2024/09/ce-logo.png',
      'RUO': 'https://www.dlongwood.com/wp-content/uploads/2024/09/ruo-logo.png',
      'PSO': 'https://www.dlongwood.com/wp-content/uploads/2024/09/pso-logo.png'
    };

    if (!Array.isArray(productos)) productos = [];
    if (!productos.length) {
      $grid.html('<div style="padding:2em;">No hay productos con este filtro.</div>');
      return;
    }

    var fragment = document.createDocumentFragment();

    productos.forEach(function(p) {
      var sx = '';
      if (p.meta_fields && p.meta_fields.sellos) {
        var sellos = Array.isArray(p.meta_fields.sellos) ? p.meta_fields.sellos : [p.meta_fields.sellos];
        sellos.forEach(function(sello) {
          var clave = (sello + '').toUpperCase().trim();
          if (sellos_img[clave]) {
            sx += '<img width="82" height="60" src="' + sellos_img[clave] + '" class="brxe-image css-filter sello-' + clave.toLowerCase() + '" alt="" loading="lazy">';
          }
        });
      }
      if (sx) sx = '<div class="brxe-iuxwmo brxe-block">' + sx + '</div>';

      var extracto = '';
      if (p.extracto) {
        extracto = escHtml(p.extracto);
      } else {
        var descLimpia = (p.descripcion || '')
          .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
          .replace(/<[^>]+>/g, '')
          .replace(/\s+/g, ' ')
          .trim();
        extracto = escHtml(descLimpia.slice(0, 180));
        if (descLimpia.length > 180) extracto += '…';
      }

      var url = site_url + '/?producto=' + p.id + (evento_id ? '&evento=' + evento_id : '');
      var tituloEsc = escHtml(p.titulo || '');

      var el = document.createElement('a');
      el.href = url;
      el.className = 'brxe-myhzzt brxe-block jsfb-filterable jsfb-query--productos';
      el.setAttribute('data-producto', p.id);
      el.innerHTML =
        '<div class="brxe-enlgrr brxe-block">' + sx +
          '<img width="350" height="350" src="' + (p.imagen_destacada || '') + '" class="brxe-frfswr brxe-image producto-imagen css-filter size-medium_large" alt="' + tituloEsc + '" loading="lazy">' +
        '</div>' +
        '<h3 class="brxe-vmggvb brxe-heading texto-equilibrado producto-titulo">' + tituloEsc + '</h3>' +
        '<div class="brxe-esybzj brxe-text-basic extracto-listing">' + extracto + '</div>';

      fragment.appendChild(el);
    });

    $grid[0].appendChild(fragment);
  }

  function showSkeleton() {
    var $grid = $('#brxe-d807f8');
    var skeleton = '<div class="skeleton-loader">';
    for (var i = 0; i < 3; i++) {
      skeleton += '<div class="skeleton-card">' +
        '<div class="skeleton-img"></div>' +
        '<div class="skeleton-title"></div>' +
        '<div class="skeleton-text"></div>' +
        '<div class="skeleton-text" style="width:60%;margin-top:6px;"></div>' +
      '</div>';
    }
    skeleton += '</div>';
    $grid.html(skeleton);
  }

  var reloadTimer = null;
  function reloadGrid() {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(function() {
      var ids = getGridData();
      if (!ids.length) return;
      showSkeleton();
      $.post(ajaxurl, {
        action: 'filtrar_productos_evento',
        ids: ids.join(','),
        filtros: JSON.stringify(filtrosActivos),
        _nonce: nonce
      }, function(res) {
        var productos = (res.success && res.data && Array.isArray(res.data.productos))
          ? res.data.productos : [];
        renderGrid(productos);
        actualizarOpcionesFiltros(productos);
      }).fail(function() {
        $('#brxe-d807f8').html('<div style="padding:2em;color:#c00;">Error al cargar productos. Recarga la página.</div>');
      });
    }, 200);
  }

  function renderFiltrosActivos() {
    var labels = { marcas: 'Marcas', tecnologias: 'Tecnologías', familias: 'Áreas' };
    var $list = $('#jet-active-filters-list');
    $list.empty();
    var hasFilters = false;

    Object.entries(filtrosActivos).forEach(function(entry) {
      var grupo = entry[0], valores = entry[1];
      (valores || []).forEach(function(val) {
        if (!val) return;
        hasFilters = true;
        $list.append(
          '<div class="jet-active-filter" data-filtro="' + grupo + '" data-valor="' + escHtml(val) + '">' +
            '<div class="jet-active-filter__label">' + (labels[grupo] || grupo) +
              '<span class="jet-active-filter__label-separator">:</span></div>' +
            '<div class="jet-active-filter__val">' + escHtml(val) + '</div>' +
            '<div class="jet-active-filter__remove" tabindex="0" role="button" aria-label="Quitar filtro">×</div>' +
          '</div>'
        );
      });
    });

    $list.closest('.jet-smart-filters-active').toggle(hasFilters);
  }

  $(document).on('click', '.evento-filtro-btn', function(e) {
    e.preventDefault();
    var $btn = $(this), grupo = $btn.data('filtro'), evento = $btn.data('evento');
    $btn.toggleClass('active');
    filtrosActivos[grupo] = $('.evento-filtro-btn.active[data-filtro="' + grupo + '"][data-evento="' + evento + '"]')
      .map(function() { return $(this).data('valor') + ''; }).get();
    if (!filtrosActivos[grupo].length) delete filtrosActivos[grupo];
    renderFiltrosActivos();
    reloadGrid();
  });

  $(document).on('click', '.jet-active-filter__remove', function(e) {
    e.preventDefault();
    var $parent = $(this).closest('.jet-active-filter'),
        grupo = $parent.data('filtro'),
        valor = $parent.data('valor');
    $('.evento-filtro-btn.active[data-filtro="' + grupo + '"][data-valor="' + valor + '"]').removeClass('active');
    if (filtrosActivos[grupo]) {
      filtrosActivos[grupo] = filtrosActivos[grupo].filter(function(v) { return v != valor; });
      if (!filtrosActivos[grupo].length) delete filtrosActivos[grupo];
    }
    renderFiltrosActivos();
    reloadGrid();
  });

  function actualizarOpcionesFiltros(productos) {
    var disponibles = { marcas: new Set(), tecnologias: new Set(), familias: new Set() };
    productos.forEach(function(p) {
      if (!p.taxonomias) return;
      Object.keys(disponibles).forEach(function(tax) {
        (p.taxonomias[tax] || []).forEach(function(x) { disponibles[tax].add(x); });
      });
    });
    Object.keys(disponibles).forEach(function(tax) {
      $('.evento-filtro-btn[data-filtro="' + tax + '"]').each(function() {
        var val = $(this).data('valor');
        var available = disponibles[tax].has(val);
        $(this).prop('disabled', !available).toggleClass('disabled', !available);
      });
    });
  }

  // Init
  if ($('#brxe-d807f8').length) {
    reloadGrid();
  }

  // ════════════════════════════════════════════════════════════════
  // Formulario JetFormBuilder (ficha de producto): rellenar "nombre_evento"
  // con el post_title del evento. El nombre llega resuelto desde PHP
  // (dlwFront.nombreEvento); el ID venía por query string (?evento=2010).
  // ════════════════════════════════════════════════════════════════
  function rellenarNombreEvento() {
    var nombre = (window.dlwFront && window.dlwFront.nombreEvento) || '';
    if (!nombre) return;
    // El input hidden puede aparecer más de una vez si hay varios formularios.
    $('input[name="nombre_evento"]').each(function() {
      if (this.value === nombre) return;      // ya está puesto → no re-disparar eventos
      this.value = nombre;
      // JetFormBuilder mantiene un estado reactivo: hay que notificarle el cambio
      // para que envíe el valor y no un campo vacío.
      this.dispatchEvent(new Event('input',  { bubbles: true }));
      this.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  if (window.dlwFront && window.dlwFront.nombreEvento) {
    rellenarNombreEvento();
    // Red de seguridad: JetFormBuilder puede montar o resetear el campo tras el
    // ready. Reintentamos unas cuantas veces; solo re-aplica si el valor cambió.
    var reintentosEvento = 0;
    var timerEvento = setInterval(function() {
      rellenarNombreEvento();
      if (++reintentosEvento >= 10) clearInterval(timerEvento);
    }, 300);
  }
});
