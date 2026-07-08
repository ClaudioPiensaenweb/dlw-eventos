# HANDOFF — dlw-eventos

> Última actualización: 2026-07-08 por alexPiensaenweb
> Estado: **en producción**, versión **v1.2.0** publicada

Documento de traspaso. Todo lo necesario para retomar el plugin sin preguntar a nadie.

---

## Qué es

Plugin de WordPress **DLW Eventos — Catálogo de productos**. Muestra productos remotos de `dlongwood.com` en las páginas de eventos/congresos de **eventos.dlongwood.com**. Es un reemplazo *drop-in* de 3 code snippets antiguos + un campo HTML de JetEngine: mismos shortcodes, mismas clases CSS, compatible con Bricks Builder existente. La diferencia es que lee de una **caché local** (tabla MySQL) en vez de llamar a la API remota en cada carga.

- **Producción:** https://eventos.dlongwood.com
- **Repo:** https://github.com/ClaudioPiensaenweb/dlw-eventos (público)
- **API de origen (remota):** `https://www.dlongwood.com/wp-json/catalogo/v1/productos`

---

## Estructura del código

```
dlw-eventos.php            Bootstrap: constantes, activación, cron, enqueue de assets, auto-updater
includes/
  sync.php                 Sincroniza productos desde la API remota → tabla local (cron cada 6h)
  cache.php                Lectura de la tabla local (dlw_get_producto(s), filtros, taxonomías)
  metabox.php              Metabox nativo en la edición de "eventos" (selección de productos)
  shortcodes.php           Shortcodes drop-in (grid, filtros, ficha individual, botones)
  ajax.php                 Handlers AJAX admin (Select2, importación) y frontend (filtrado)
  webhook.php              Endpoint para invalidar/refrescar caché desde el sitio remoto
  admin-page.php           Página de ajustes + importación masiva
assets/
  frontend.js / .css       Grid de productos, filtros, y autorelleno del formulario (ver abajo)
  producto.css             Estilos de la ficha individual de producto
  admin.js / .css          UI del metabox y la página de ajustes
lib/plugin-update-checker/ Dependencia vendorizada (YahnisElsts v5.6). NO tocar, NO excluir del repo.
```

---

## Cómo funciona el auto-update

El plugin se actualiza solo desde las **releases de GitHub** vía `plugin-update-checker` (configurado en `dlw-eventos.php`). Detección por **tags + release**; usa el zipball automático de GitHub (no se adjunta ZIP a la release).

### Publicar una versión nueva (flujo completo)

1. Editar el código.
2. **Bumpar la versión en `dlw-eventos.php` en DOS sitios** (deben coincidir):
   - Header del plugin: `* Version: X.Y.Z`
   - Constante: `define('DLW_EVENTOS_VERSION', 'X.Y.Z');`
3. `git add -A && git commit -m "vX.Y.Z - descripción"`
4. `git push origin main`
5. `git tag -a vX.Y.Z -m "descripción"` && `git push origin vX.Y.Z`
6. `gh release create vX.Y.Z --title "vX.Y.Z" --notes "changelog"`
7. WordPress mostrará la actualización en el admin automáticamente.

> El tag **debe** coincidir con la versión del header del plugin.
> Si el repo pasa a privado: añadir `$dlwUpdater->setAuthentication('ghp_TOKEN');` en `dlw-eventos.php`.

---

## Datos del dominio (para no perderse)

- **CPT:** `eventos` (expuesto en REST, `rest_base` = `eventos`).
- **Ficha de producto:** URL con query string → `?producto=<ID>&evento=<ID>`. Los IDs se leen con `$_GET` en shortcodes y PHP.
- **Relación evento→productos:** post meta `event_product_ids` en cada evento (CSV de IDs de producto).
- **Caché:** tabla `{prefix}dlw_productos_cache`. Sync por cron cada 6 h (`dlw_sync_productos_cron`, reprogramado en cada `init` por seguridad).
- **Entidades HTML:** el contenido remoto trae entidades (™, …). Se decodifican en render (`frontend.js`) y en el nombre del evento (PHP). Ojo con esto al tocar textos.

---

## Historial de versiones

| Versión | Commit | Qué incluyó |
|---------|--------|-------------|
| **v1.1.0** | `58ea8af` | Release inicial en GitHub. Caché local, metabox nativo, auto-updater, fix entidades HTML, fix reprogramación de cron, shortcodes drop-in, nonces/sanitización en AJAX. |
| **v1.2.0** | `5d7566b` | Autorelleno del campo `nombre_evento` del formulario JetFormBuilder de la ficha de producto (ver abajo). |

### Detalle de la v1.2.0 (último cambio)

La ficha de producto lleva un formulario JetFormBuilder ("Solicitar más información"). Se añadió el autorelleno de un campo hidden `nombre_evento` con el **título del evento**:

- **`dlw-eventos.php`** (enqueue frontend): resuelve el `post_title` en el servidor a partir de `?evento=ID` con `get_the_title()` (0 llamadas HTTP extra) y lo pasa al JS vía `wp_localize_script('dlwFront', ['nombreEvento' => ...])`.
- **`assets/frontend.js`**: rellena `input[name="nombre_evento"]` con ese valor y **dispara los eventos `input`/`change`** — imprescindible, porque JetFormBuilder tiene estado reactivo y si no se notifica el cambio el campo viaja vacío. Incluye reintentos por si JFB monta el form tarde.

Patrón reutilizable: para rellenar cualquier otro campo del formulario con datos del evento/producto, resolver en PHP → `wp_localize_script` → rellenar en JS disparando `input`/`change`.

---

## Estado actual y siguiente paso

- Código en `main`, working tree limpio. Tags `v1.1.0` y `v1.2.0` publicados con sus releases.
- **Verificación pendiente en producción** (no bloqueante): abrir una ficha con `?producto=…&evento=…`, enviar el formulario y confirmar que `nombre_evento` llega con el título del evento (probado contra el evento 2010 → "ISCO 2026 (Innovations in Single Cell Omics)"). Si llegara vacío en algún caso, revisar que esa URL lleve `?evento=ID`.
- Para que producción reciba la v1.2.0 por el auto-updater, **debe tener la v1.1.0 instalada primero**. Si no, subir el plugin a mano una vez (vale directamente la 1.2.0) y a partir de ahí ya es automático.

---

## Notas operativas

- El token de **`gh` CLI caduca** en el equipo de trabajo (da `401` aunque `git push` funcione, porque git usa Git Credential Manager). Si pasa: reautenticar con `gh auth login -h github.com`, o crear la release desde la web (`.../releases/new?tag=vX.Y.Z`). A 2026-07-08 `gh` está reautenticado (cuenta `ClaudioPiensaenweb`).
- **`.planning/`** está en `.gitignore` — es directorio de trabajo de las skills de piensa, no forma parte del plugin.
- Este proyecto **no usa el flujo estructurado de piensa** (no hay ROADMAP/BRIEFING/fases). Se gestiona por git + releases. Este HANDOFF.md sustituye al `estado.md` de piensa.
