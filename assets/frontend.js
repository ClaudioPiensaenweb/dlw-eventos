jQuery(function($){
  // ════════════════════════════════════════════════════════════════
  // Grid de productos + filtros.
  // Las tarjetas llegan ya pintadas desde el servidor (shortcode
  // [productos_remotos_grid]) con sus taxonomías en data-tax. Filtrar es
  // solo ocultar/mostrar: 0 peticiones al servidor.
  // AND entre grupos (marcas, tecnologías, áreas), OR dentro de cada grupo.
  // ════════════════════════════════════════════════════════════════
  var filtrosActivos = {};
  var GRUPOS = ['marcas', 'tecnologias', 'familias'];
  var $grid = $('#brxe-d807f8');

  function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str == null ? '' : String(str)));
    return div.innerHTML;
  }

  function taxDe(card) {
    if (!card._dlwTax) {
      try { card._dlwTax = JSON.parse(card.getAttribute('data-tax') || '{}'); }
      catch (e) { card._dlwTax = {}; }
    }
    return card._dlwTax;
  }

  function cumple(card) {
    var tax = taxDe(card);
    return Object.keys(filtrosActivos).every(function(grupo) {
      var valores = filtrosActivos[grupo] || [];
      if (!valores.length) return true;
      var propios = tax[grupo] || [];
      return valores.some(function(v) { return propios.indexOf(v) !== -1; });
    });
  }

  function aplicarFiltros() {
    var $cards = $grid.find('[data-producto]');
    if (!$cards.length) return;

    var disponibles = {};
    GRUPOS.forEach(function(g) { disponibles[g] = {}; });
    var visibles = 0;

    $cards.each(function() {
      var ok = cumple(this);
      this.style.display = ok ? '' : 'none';
      if (!ok) return;
      visibles++;
      var tax = taxDe(this);
      GRUPOS.forEach(function(g) {
        (tax[g] || []).forEach(function(v) { disponibles[g][v] = true; });
      });
    });

    var $vacio = $grid.children('.dlw-sin-productos');
    if (!visibles && !$vacio.length) {
      $grid.append('<div class="dlw-sin-productos" style="padding:2em;">No hay productos con este filtro.</div>');
    } else if (visibles) {
      $vacio.remove();
    }

    // Desactivar opciones que no darían resultados (igual que antes).
    $('.evento-filtro-btn').each(function() {
      var grupo = this.getAttribute('data-filtro');
      if (!disponibles[grupo]) return;
      var available = !!disponibles[grupo][this.getAttribute('data-valor')];
      $(this).prop('disabled', !available).toggleClass('disabled', !available);
    });
  }

  function renderFiltrosActivos() {
    var labels = { marcas: 'Marcas', tecnologias: 'Tecnologías', familias: 'Áreas' };
    var $list = $('#jet-active-filters-list');
    $list.empty();
    var hasFilters = false;

    Object.keys(filtrosActivos).forEach(function(grupo) {
      (filtrosActivos[grupo] || []).forEach(function(val) {
        if (!val) return;
        hasFilters = true;
        $list.append(
          '<div class="jet-active-filter" data-filtro="' + escHtml(grupo) + '" data-valor="' + escHtml(val) + '">' +
            '<div class="jet-active-filter__label">' + escHtml(labels[grupo] || grupo) +
              '<span class="jet-active-filter__label-separator">:</span></div>' +
            '<div class="jet-active-filter__val">' + escHtml(val) + '</div>' +
            '<div class="jet-active-filter__remove" tabindex="0" role="button" aria-label="Quitar filtro">×</div>' +
          '</div>'
        );
      });
    });

    $list.closest('.jet-smart-filters-active').toggle(hasFilters);
  }

  function leerFiltrosDeBotones(grupo, evento) {
    filtrosActivos[grupo] = $('.evento-filtro-btn.active').filter(function() {
      return this.getAttribute('data-filtro') === grupo && this.getAttribute('data-evento') === evento;
    }).map(function() { return this.getAttribute('data-valor'); }).get();
    if (!filtrosActivos[grupo].length) delete filtrosActivos[grupo];
  }

  $(document).on('click', '.evento-filtro-btn', function(e) {
    e.preventDefault();
    var $btn = $(this);
    $btn.toggleClass('active');
    leerFiltrosDeBotones(this.getAttribute('data-filtro'), this.getAttribute('data-evento'));
    renderFiltrosActivos();
    aplicarFiltros();
  });

  $(document).on('click keydown', '.jet-active-filter__remove', function(e) {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    var $parent = $(this).closest('.jet-active-filter'),
        grupo = $parent.attr('data-filtro'),
        valor = $parent.attr('data-valor');
    $('.evento-filtro-btn.active').filter(function() {
      return this.getAttribute('data-filtro') === grupo && this.getAttribute('data-valor') === valor;
    }).removeClass('active');
    if (filtrosActivos[grupo]) {
      filtrosActivos[grupo] = filtrosActivos[grupo].filter(function(v) { return v !== valor; });
      if (!filtrosActivos[grupo].length) delete filtrosActivos[grupo];
    }
    renderFiltrosActivos();
    aplicarFiltros();
  });

  // Init: marcar como no disponibles las opciones sin productos en el grid.
  if ($grid.length) {
    aplicarFiltros();
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
