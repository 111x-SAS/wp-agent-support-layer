## Context

Ver `proposal.md` para la motivación. Estado actual relevante para el diseño (código en `main` `03f1a5c`):

- `DocumentBuilder::render_html()` usa `get_the_content()`; `WP_Query::generate_postdata()` deja `$more = 0` fuera de una vista singular, así que el teaser depende de la ruta que generó el documento.
- `Delivery::markdown_url()` concatena `.md` al permalink sin trailing slash; `handle_md_suffix()` devuelve 404 para `/.md` y `test_md_suffix_for_home_is_404` lo fija.
- `Eligibility::query()` hace `fields => ids` sin cachés y luego `array_filter(..., is_eligible)`, que llama a `get_post()` y `get_post_meta()` por id. `Runner::status()`, `build_queue()`, `prune()` y `LlmsTxtBuilder::sections()` dependen de `eligible_ids()`.
- `ExcludeMetaBox` registra el meta con `show_in_rest => true`; `auth_callback` solo protege la escritura.
- `DiagnosticsController::handle()` ejecuta `CrawlerProbe::run()` completo (7 objetivos de sitio + 3 peticiones × cada crawler del catálogo, `timeout 10`, `redirection 2`) dentro de una sola petición `admin-post.php`. Cloudflare corta cualquier petición de origen a los 100 s con error 524.
- `Report::crawler_checks()` deriva el veredicto de robots.txt de `Policy::for_agent()`, no del cuerpo obtenido en `raw['site']['robots']`.
- `Runner::run()` construye la cola solo cuando está vacía y regenera artefactos solo al vaciarla; `State::save()` sobrescribe la opción `wpasl_state` completa; `State::mark_generated()` (lazy fill) también.
- `Settings::sanitize()` convierte `llms_full_max_bytes` de MB a bytes; `update_option()` sobre una opción inexistente sanitiza dos veces.
- `Scheduler::run_soon()` reprograma el evento recurrente en `time() - 1`.
- Sin `vendor/` ni `wordpress-tests-lib` en el worktree; la verificación local requiere `composer install` y `bin/install-wp-tests.sh` (o `bin/smoke-docker.sh`). Contenedor `wpasl-mysql` en 3306.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, sin JavaScript nuevo en el admin, WPCS limpio, compatibilidad con la opción `wpasl_state` existente (no hay migración en un parche).

## Goals / Non-Goals

**Goals:**

- Corregir los 20 hallazgos con cambios locales, cada uno cubierto por un test automatizado nuevo.
- Que ninguna petición del navegador al diagnóstico supere los 100 s en ningún hosting, sin depender de JavaScript.
- Mantener la forma del estado de generación y las URLs públicas; publicar como 1.0.2.

**Non-Goals:**

- Migrar el estado de generación a post meta (descartado por el usuario para este parche; ver D8).
- Barra de progreso o ejecución AJAX del diagnóstico.
- Cambiar el catálogo de crawlers o las políticas por defecto.
- Reescribir el `design.md` archivado de `agent-support-layer-v1`; las decisiones que lo contradicen se registran aquí (D10).

## Decisions

### D1. Contenido completo: `$more = 1` y eliminación de marcas antes de `the_content`

En `render_html()`: guardar y restaurar `$GLOBALS['more']` y `$GLOBALS['page']`, fijar `$more = 1`, y trabajar sobre una copia de `post_content` a la que se eliminan `<!--more(.*?)-->`, `<!--nextpage-->` y los bloques `<!-- wp:more -->…<!-- /wp:more -->` / `<!-- wp:nextpage -->…<!-- /wp:nextpage -->` (que ya solo envuelven la marca). Se pasa el contenido limpio directamente al filtro `the_content` en lugar de `get_the_content()`.

- Alternativa descartada: solo `$more = 1`. `get_the_content()` sigue partiendo por `<!--nextpage-->` (`$pages`), y el `wp:more` con `noTeaser` cambia el comportamiento. Eliminar las marcas es determinista.
- Alternativa descartada: `apply_filters('the_content', $post->post_content)` sin `setup_postdata`. Se pierde el contexto que necesitan bloques y shortcodes.

### D2. `markdown_url()` con parámetro de consulta como fallback universal

`markdown_url()` devuelve `add_query_arg('wpasl', 'md', $permalink)` cuando: la estructura de permalinks está vacía (ya existía), el permalink contiene `?`, o `untrailingslashit($permalink) === untrailingslashit(home_url())`. En `handle_md_suffix()`, cuando `$relative === ''` y `show_on_front === 'page'` con `page_on_front > 0`, se resuelve a esa página (sujeta a elegibilidad); en caso contrario 404. `test_md_suffix_for_home_is_404` se conserva para `show_on_front = posts` y se añade el caso de portada estática. El query var `wpasl=md` ya se procesa en `parse_request` para cualquier permalink, así que la URL con parámetro sirve en ambas estructuras de permalinks.

- Alternativa descartada: emitir `/.md` para la portada. Es ambiguo con `/index.md` en algunos servidores y no cubre CPT sin rewrite.

### D3. Elegibles: precarga por lotes y salto del re-filtrado

En `Eligibility::query()`: tras obtener los ids, si `has_filter('wpasl_is_eligible')` es falso, devolver los ids directamente (las condiciones de tipo, estado, contraseña y exclusión ya están en el SQL: `post_status`, `has_password`, `post__not_in`). Si hay callbacks, precargar con `_prime_post_caches($chunk, false, true)` en trozos de 500 antes de `array_filter`. Nuevo método `count($post_types)` que usa `WP_Query` con `posts_per_page => 1`, `no_found_rows => false` y devuelve `found_posts` cuando no hay filtro; con filtro, `count(eligible_ids())`. `Runner::status()` usa `count()` para `eligible`, y `generated`/`pending` se calculan con `array_intersect_key` contra el mapa de ids (sin `get_post`). `LlmsTxtBuilder::sections()` ya usa `query()` con límite; `prune()` y `build_queue()` se benefician del atajo.

- Riesgo: el atajo asume que `excluded_ids()` está actualizado (caché de 60 s en `wp_cache`). Se mantiene `flush_excluded_cache()` en `updated_post_meta`/`deleted_post_meta` del meta de exclusión (ya existe en `ExcludeMetaBox`; verificar en apply).
- Test: `$wpdb->num_queries` antes/después de `eligible_ids()` con 60 posts → diferencia < 10.

### D4. Ocultar `_wpasl_exclude` en lectura REST

`ExcludeMetaBox::register()` añade, por cada post type habilitado, un filtro `rest_prepare_{$post_type}` con prioridad 10 que elimina `data['meta'][self::META]` cuando `! current_user_can('edit_post', $post->ID)`. `show_in_rest` se mantiene para que el editor de bloques lea/escriba el valor.

- Alternativa descartada: `show_in_rest => false` y guardar solo desde el meta box clásico. Rompería la casilla en el editor de bloques.

### D5. Diagnóstico por lotes encadenados por redirección

Estructura:

- `CrawlerProbe` gana `probe_site()` (objetivos de sitio) y `probe_crawler($agent, $crawler, $post)` (las 3 peticiones de un crawler). `run()` se conserva para WP-CLI y tests (llama a ambos en serie). `fetch()` pasa a `timeout => 5`, `redirection => 0`, `limit_response_size => 1 MB`, y registra `status` 3xx con la cabecera `location` guardada en `headers` (se añade `location` a `HEADERS`).
- `DiagnosticsController::handle()` gestiona una ejecución persistida en el transient `wpasl_diagnostics_run_{user_id}` (TTL 1 h) con `{started, sample_post, site, crawlers, pending: [agent…]}`. Flujo: (1) sin transient (POST del formulario): crear la ejecución con `pending = array_keys($crawlers)`, sondear el sitio; (2) bucle: mientras `pending` no esté vacío y `microtime(true) - $t0 < $budget`, sacar el primer agente, sondear, guardar en `crawlers`; (3) si quedan pendientes: guardar transient y `wp_safe_redirect(add_query_arg(['action' => ACTION, '_wpnonce' => wp_create_nonce(ACTION)], admin_url('admin-post.php')), 303)`; (4) si no: construir `Report`, `Report::save()`, borrar transient, redirigir a la pestaña con el aviso actual. `check_admin_referer()` acepta el nonce por GET, y `admin_post_{action}` se dispara también para GET, así que no hace falta JavaScript. Guardar el transient antes de redirigir y volver a comprobar capacidad y nonce en cada paso.
- Presupuesto: `apply_filters('wpasl_diagnostics_time_budget', 30)` segundos. Cota por petición del navegador: el primer paso hace como máximo 7 peticiones de sitio × 5 s = 35 s y detiene antes del primer crawler si excedió el presupuesto; los pasos siguientes hacen como máximo `budget + 3 × 5 s` = 45 s. Ambos quedan por debajo de los 100 s de Cloudflare con margen para la latencia del propio hosting. Con 25 crawlers en un sitio sano (≈0,3 s por petición) todo cabe en un solo paso, como hoy.
- Sin JavaScript: el navegador sigue la cadena de 303 (cada paso responde en segundos, el límite de redirecciones de los navegadores es ≥ 20 y con el peor caso de 5 s por petición se necesitan ≈ 15 pasos para 25 crawlers; si un catálogo ampliado superara ese límite, el administrador solo tendría que pulsar de nuevo: la ejecución persistida continúa donde quedó).
- `Report::site_checks()` marca 3xx como advertencia con la `location` (“la URL redirige; el diagnóstico no sigue redirecciones”). Un 3xx en la portada por `www.` es hallazgo útil, no error.
- `max_execution_time`: en Linux solo cuenta CPU, no espera de red; en hostings que cuenten tiempo real, el presupuesto de 30 s (filtrable) mantiene cada paso por debajo del valor por defecto de PHP.
- Alternativas descartadas: AJAX con progreso (JS nuevo, endpoint y estado por pieza); reducir a un user-agent por grupo (pierde la detección de WAF por UA, que es la razón de ser del informe).

### D6. Veredicto de robots.txt desde el cuerpo servido

`Report` gana `robots_verdict($body, $agent)`: recorre líneas, agrupa por `User-agent:` (comparación case-insensitive, un grupo puede tener varios `User-agent`), y devuelve `block` si el grupo del agente tiene `Disallow: /` (exacto) sin `Allow: /`, `allow` si tiene `Allow: /` o `Disallow:` vacío, `null` si no hay grupo. Comparación con `Policy::for_agent()`: igual → OK con el texto actual; distinto → WARNING “robots.txt served shows X, configured Y (a physical robots.txt or a cache may be serving stale rules)”; sin grupo → WARNING “no rule for this user-agent in the served robots.txt”; cuerpo no disponible (status ≠ 200) → WARNING. El grupo `*` no se considera veredicto del crawler (la política del plugin es por token). `raw['site']['robots']` pasa a guardar `body` (limitado a 64 KB) en `fetch()` para `robots` y para las comprobaciones existentes de `llms.txt`.

### D7. Cola viva, artefactos por antigüedad y reinicio por `post_types`

- Al inicio de `run()`, tras cargar el estado y aunque la cola no esté vacía: `$new = array_diff(eligible_ids(), array_keys(generated), queue)`; si hay nuevos, `queue = array_merge($new, queue)`. Con D3 esto cuesta 1 consulta.
- Tras el bucle, si `! $cycle_completed` y (`last_cycle_completed === 0` o `time() - last_cycle_completed > interval_seconds`), y además `last_artifacts_regenerated` (clave nueva en el estado, retrocompatible: ausente = 0) también es más antiguo que el intervalo, regenerar artefactos y guardar `last_artifacts_regenerated`. Intervalo en segundos: `wp_get_schedules()[schedule]['interval']`.
- `Scheduler::on_settings_updated()` compara también `post_types`; si cambian, `State::clear_queue()` (vacía `queue` y `cycle_started`, conserva `generated`). Los documentos de tipos deshabilitados los elimina `prune()` como hoy.

### D8. Estado: recargar y fusionar antes de guardar

`State::save($state)` pasa a: `$current = $this->load()`; `generated = $current.generated ∪ $state.generated` tomando el mayor timestamp por id, salvo los ids que `$state` eliminó explícitamente vía `forget()`/`prune()`; para ello `Runner::prune()` y `State::forget()` registran los ids eliminados en `$state['_removed']` (clave transitoria que `save()` consume y no persiste). `queue`: la versión en memoria manda (el runner es el único que consume la cola; los lazy fills no la modifican salvo `forget()`, que elimina ids: se aplica como `array_diff` sobre la cola en memoria). Campos escalares: los de `$state`. `mark_generated()` sigue siendo load + set + save, ahora fusionado. `wp_cache_delete(OPTION, 'options')` antes de `load()` en `save()` para no leer una copia obsoleta del object cache.

- Alternativa descartada (post meta): elegida por el usuario para un cambio futuro; más invasiva (uninstall, multisitio, status).
- Test: cargar estado en memoria, llamar a `mark_generated(B)` desde “otra petición”, guardar el estado en memoria con la marca de A → la opción contiene A y B.

### D9. Sanitización idempotente del límite de `llms-full.txt`

El formulario envía `llms_full_max_mb`; `Settings::sanitize()` calcula `llms_full_max_bytes = clamp(1..100, absint(mb)) * MB_IN_BYTES` cuando el campo `llms_full_max_mb` está presente, y cuando solo llega `llms_full_max_bytes` (valor ya sanitizado, tests, `update_option` programático) lo acota a `[MB_IN_BYTES, 100 * MB_IN_BYTES]` sin multiplicar. `llms_full_max_mb` nunca se persiste. Tests existentes en `test-llms-txt.php` se actualizan al nuevo nombre de campo. Se añade `test_sanitize_is_idempotent` que recorre todos los campos con `sanitize(sanitize(x)) === sanitize(x)`.

### D10. Regenerar ahora como evento único (deriva del diseño archivado)

`Scheduler::run_soon()` programa `wp_schedule_single_event(time(), self::HOOK, array('manual'))` si no existe ya uno pendiente con esos args, y llama a `spawn_cron()`; no toca el evento recurrente. El callback ignora los argumentos. El argumento distinto evita la deduplicación de 10 minutos de `wp_schedule_single_event` contra el evento recurrente. `unschedule()` limpia también los eventos únicos con `wp_clear_scheduled_hook(HOOK, array('manual'))`. Se documenta aquí que el estado vive en la opción `wpasl_state` (no en `state.json`), que es lo que el `design.md` archivado D3 describía de otra forma; el spec principal no menciona el medio de almacenamiento, así que no hay delta.

### D11. Hallazgos de baja severidad

- **10/11:** `ContentSignals::send('markdown')` ejecuta `header_remove('X-Robots-Tag')` antes de emitir cabeceras; `Delivery::markdown_headers()` y `ManifestRouter`/`LlmsTxtRouter` añaden `X-Content-Type-Options: nosniff`; `Delivery::send_html_headers()` envía `Link` con `replace = false`.
- **12:** `LeagueConverter::absolutize()` usa `$base['scheme']` para `//host`, y normaliza el path resultante con una función `normalize_path()` que resuelve `.` y `..` sin salir de `/`. `#fragmento` se devuelve tal cual (es relativo al propio documento).
- **13:** `Lifecycle::activate_site()` y `Settings::save()` usan autoload `yes` para `wpasl_settings`; `Storage::token()` guarda con autoload `yes`; en `Plugin::boot` (solo admin) una comprobación única por versión con la opción nueva `wpasl_version` (comparada con `WPASL_VERSION`, añadida a `Uninstaller::OPTIONS`) llama a `wp_set_option_autoload_values()` (WP 6.4+, con fallback a `update_option` con autoload) para instalaciones existentes. El transient de diagnóstico por usuario se elimina en la desinstalación con una consulta por prefijo `_transient_wpasl_diagnostics_run_`.
- **14:** cadenas de `Report` (`HTTP 200.`, `Content-Signal:`, `X-Robots-Tag:`, mensajes con `sprintf`), `LlmsTxtBuilder::optional_links()` y `Commands` (`success`/`error`) envueltas en `__()` con comentarios `translators:`; regenerar `languages/wp-agent-support-layer.pot`.
- **15:** `Storage::ensure()` escribe `probe.txt` (contenido fijo) junto al `.htaccess`; `storage_direct_url()` lo usa siempre; `Storage::clear()` no lo borra; `uninstall` sí (borra el directorio).
- **16:** `get_sitemap_url('index')` con fallback a `home_url('/wp-sitemap.xml')` si la función no existe.
- **17:** `Catalog::all()` amplía la expresión a `/[\s#:.\[\]]/`.
- **18:** `Commands::generate()` con `--post-type` valida contra `enabled_post_types()` y `status()['has_item_generator']`; `WP_CLI::error()` en ambos casos.
- **19:** `phpcs:disable` de `Storage.php` limitado a las funciones de sistema de archivos y `NoSilencedErrors` solo en las líneas con `@`; arrays de `Catalog` en multilínea; `DiagnosticsTab` imprime el `<textarea>` con `trim()`; `ManifestsTab` guion largo dentro de `__()`; `Delivery::handle_md_suffix()` aplica `esc_url_raw()` antes de `wp_parse_url()`.

## Risks / Trade-offs

- [D3: un plugin que filtre `wpasl_is_eligible` pierde el atajo] → precarga por lotes mantiene ~2 consultas por 500 ítems en lugar de 2 por ítem.
- [D5: cadena de redirecciones larga en catálogos ampliados o sitios muy lentos] → cada paso guarda progreso; el administrador puede pulsar de nuevo y continúa. Aviso en la pestaña cuando existe una ejecución en curso.
- [D5: transient compartido entre administradores] → clave por usuario (`wpasl_diagnostics_run_{user_id}`); dos administradores simultáneos no se pisan.
- [D7: regenerar artefactos a mitad de ciclo publica `llms-full.txt` incompleto] → aceptable: mejor un archivo actualizado con los documentos disponibles que uno de hace 100 días; el lazy fill del builder completa los que falten dentro del límite de tamaño.
- [D8: `_removed` en memoria] → clave transitoria, nunca persistida; tests cubren `prune()` + lazy fill concurrente.
- [D9: cambio de nombre del campo] → solo afecta al formulario del plugin; la opción almacenada no cambia.
- [D11.13: autoload en instalaciones existentes] → `wp_set_option_autoload_values()` es idempotente; en WP < 6.4 el fallback reescribe la opción una vez.
- [Sin entorno local de tests en el worktree] → apply empieza por `composer install` + `bin/install-wp-tests.sh` (o `bin/smoke-docker.sh`) y termina con la lista de verificación del informe de revisión.

## Migration Plan

Sin migración de datos. Orden de despliegue: release 1.0.2 en GitHub (workflow existente). Rollback: volver a 1.0.1; la opción `wpasl_state` sigue siendo legible (solo se añade `last_artifacts_regenerated`, ignorada por 1.0.1) y el transient de diagnóstico caduca solo.
