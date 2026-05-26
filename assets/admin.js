jQuery(function($){
  var $input      = $('#event_product_ids');
  var $grid       = $('#dlw-productos-grid');
  var $select     = $('#dlw-buscar-select');
  var $status     = $('#dlw-status');
  var $filtro     = $('#dlw-select-filtro');
  var $preview    = $('#dlw-preview-wrap');
  var tipoActivo  = 'marcas';
  var ajaxUrl     = DLWEventos.ajaxUrl;
  var nonce       = DLWEventos.nonce;

  // ── Helpers ──────────────────────────────────────────────
  function dispatchChange() {
    var el = document.getElementById('event_product_ids');
    if (el) {
      el.dispatchEvent(new Event('input', {bubbles:true}));
      el.dispatchEvent(new Event('change', {bubbles:true}));
    }
  }

  function getIds() {
    return ($input.val() || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
  }

  function setIds(ids) {
    ids = Array.from(new Set(ids.filter(Boolean)));
    $input.val(ids.join(',')).trigger('input').trigger('change');
    dispatchChange();
  }

  // ── Grid ─────────────────────────────────────────────────
  function renderGrid() {
    var ids = getIds();
    // Deduplica
    var unique = Array.from(new Set(ids));
    if (unique.join(',') !== ids.join(',')) setIds(unique);
    ids = unique;

    $grid.empty();
    if (!ids.length) return;

    $.get(ajaxUrl, {action:'get_catalogo_productos', ids:ids.join(','), nonce:nonce}, function(res){
      if (!res.success) return;
      var map = {};
      res.data.forEach(function(p){ map[p.id] = p; });
      ids.forEach(function(id){
        var p = map[id];
        if (!p) return;
        $grid.append(
          '<div class="producto-card" data-id="'+p.id+'">' +
            '<div class="card-thumb" style="background-image:url(\''+( p.imagen||'')+'\')"></div>' +
            '<div class="card-title">'+p.titulo+'</div>' +
            '<span class="remove-card" title="Eliminar">&times;</span>' +
          '</div>'
        );
      });
    });
  }

  function addProduct(p) {
    var id = String(p.id);
    if ($grid.find('[data-id="'+id+'"]').length) return;
    $grid.append(
      '<div class="producto-card" data-id="'+id+'">' +
        '<div class="card-thumb" style="background-image:url(\''+(p.imagen||'')+'\')"></div>' +
        '<div class="card-title">'+p.titulo+'</div>' +
        '<span class="remove-card" title="Eliminar">&times;</span>' +
      '</div>'
    );
    var ids = getIds();
    if (ids.indexOf(id) < 0) { ids.push(id); setIds(ids); }
  }

  function updateIdsFromGrid() {
    var ids = [];
    $grid.find('.producto-card').each(function(){
      ids.push(String($(this).data('id')));
    });
    setIds(ids);
  }

  $grid.on('click','.remove-card', function(){
    $(this).closest('.producto-card').remove();
    updateIdsFromGrid();
  });

  if ($.fn.sortable) {
    $grid.sortable({ items:'.producto-card', update: updateIdsFromGrid });
  }

  // ── Select2 buscador ────────────────────────────────────
  $select.select2({
    ajax: {
      url: ajaxUrl,
      dataType:'json',
      delay:300,
      data: function(params){
        return {action:'get_catalogo_productos', q:params.term, nonce:nonce};
      },
      processResults: function(data){
        if (!data.success) return {results:[]};
        return {results: data.data.map(function(p){
          return {id:p.id, text:p.titulo, image:p.imagen};
        })};
      },
      cache:true
    },
    placeholder:'Buscar producto por nombre',
    minimumInputLength:2,
    templateResult: function(item){
      if (!item.id) return item.text;
      var img = item.image ? '<img src="'+item.image+'" style="width:30px;height:30px;object-fit:cover;border-radius:3px;margin-right:8px;vertical-align:middle;">' : '';
      return '<span>'+img+item.text+'</span>';
    },
    templateSelection: function(item){ return item.text || 'Buscar producto por nombre'; },
    escapeMarkup: function(m){ return m; }
  });

  $select.on('select2:select', function(e){
    var d = e.params.data;
    addProduct({id:d.id, titulo:d.text, imagen:d.image});
    $select.val(null).trigger('change');
  });

  // ── Importación masiva ──────────────────────────────────
  function loadTaxValues(tipo) {
    $filtro.empty();
    $status.text('Cargando...');
    $preview.empty();

    // Lee de la tabla local (instantáneo)
    $.get(ajaxUrl, {action:'dlw_get_taxonomia_valores', tipo:tipo, nonce:nonce}, function(res){
      if (!res.success) { $status.text('Error'); return; }
      $filtro.append('<option value="">-- Selecciona --</option>');
      res.data.forEach(function(v){
        $filtro.append('<option value="'+v+'">'+v+'</option>');
      });
      if ($.fn.select2) {
        $filtro.select2({width:'100%', dropdownAutoWidth:true, placeholder:'Escribe para buscar...'});
      }
      $status.text('');
    });
  }

  $('.dlw-btn-importar').on('click', function(){
    tipoActivo = $(this).data('tipo');
    loadTaxValues(tipoActivo);
    $('.dlw-btn-importar').removeClass('active');
    $(this).addClass('active');
  });

  $('#dlw-borrar-todos').on('click', function(){
    $grid.empty();
    setIds([]);
    $status.text('Se borraron todos los productos.');
  });

  $('#dlw-btn-previsualizar').on('click', function(){
    var valor = $filtro.val();
    $preview.empty();
    if (!valor) { $status.text('Elige una opción.'); return; }
    $status.text('Buscando productos...');

    $.get(ajaxUrl, {action:'dlw_get_productos_por_taxonomia', tipo:tipoActivo, valor:valor, nonce:nonce}, function(res){
      if (!res.success || !res.data.length) {
        $status.text('No hay productos para este filtro');
        return;
      }
      var productos = res.data;
      var html = '<div style="margin:12px 0 0 0;">' +
        '<strong style="color:#2271b1;">'+productos.length+' productos encontrados:</strong>' +
        '<ul class="preview-listado">';
      productos.forEach(function(p){
        html += '<li><label><input type="checkbox" class="importar-checkbox" value="'+p.id+'" checked> '+p.titulo+'</label></li>';
      });
      html += '</ul><button type="button" id="dlw-confirmar-importacion" style="margin-top:7px;">Importar seleccionados</button></div>';
      $preview.html(html);
      $status.text('');

      $('#dlw-confirmar-importacion').on('click', function(){
        var sel = $('.importar-checkbox:checked').map(function(){return this.value;}).get();
        if (!sel.length) { $status.text('No has seleccionado ninguno.'); return; }
        var actuales = getIds();
        var nuevos = sel.filter(function(id){ return actuales.indexOf(String(id)) < 0; });
        setIds(actuales.concat(nuevos));
        renderGrid();

        var post_id = $('#post_ID').val() || $('[name="post_ID"]').val();
        if (post_id) {
          $.post(ajaxUrl, {action:'importar_evento_guardar', post_id:post_id, nonce:nonce}, function(resp){
            $status.text(resp.success
              ? '¡Importados '+nuevos.length+' productos y guardado!'
              : '¡Importados '+nuevos.length+' productos, pero error al guardar!');
            $preview.empty();
          });
        } else {
          $status.text('¡Importados '+nuevos.length+' productos!');
          $preview.empty();
        }
      });
    });
  });

  // ── Init ─────────────────────────────────────────────────
  renderGrid();
  loadTaxValues(tipoActivo);
});
