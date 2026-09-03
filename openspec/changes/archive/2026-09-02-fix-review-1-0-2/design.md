## Context

Ver `proposal.md` para la motivación y los deltas en `specs/` para el comportamiento exigido. Los 35 hallazgos del review se verificaron de nuevo contra el código de este worktree (`225224f`) antes de diseñar; todos se confirmaron. Estado actual relevante:

- `Page::render()` (`src/Admin/Page.php:182-188`) envuelve `$tab->render()` en `<form action="options.php">` con `settings_fields('wpasl')`, campo oculto `wpasl_settings[_tab]` y `submit_button()`. `GeneralTab::render()` dispara `wpasl_general_tab_after` dentro de ese formulario y `GenerationStatus::render()` imprime ahí su propio `<form action="admin-post.php">` (`GenerationStatus.php:131-137`) con los avisos `wpasl_notice`, la advertencia de `DISABLE_WP_CRON` y la tabla de estado. La interfaz `Tab` (`src/Admin/Tab.php`) tiene cuatro métodos y su docblock admite slugs fuera de `Settings::TAB_KEYS`.
- `Page::render()` llama a `settings_errors('wpasl')`; el núcleo registra "Settings saved." bajo el slug `general` y nadie en `src/` llama a `add_settings_error()`.
- `Settings::sanitize()` (`src/Settings.php:155-178`) recorre `TAB_KEYS[$tab]` o, si `_tab` no se reconoce, todas las claves de `defaults()`, y `sanitize_key_value()` interpreta `null` como "casilla desmarcada". `test_sanitize_is_idempotent_for_every_field` llama a `sanitize()` sin `_tab` con `llms_full_max_bytes_mb` en la entrada, así que la sanitización parcial debe seguir convirtiendo ese campo.
- `Lifecycle::activate(true)` recorre `get_sites(['number' => 0])` una vez; no hay hook en `wp_initialize_site` y `Plugin::maybe_upgrade()` solo ajusta autoload y versión.
- `Runner::run($limit, $budget)` reconstruye la cola sin post types (`Runner.php:150-152`); `$cycle_post_types` solo lo lee `never_generated_ids()`; `run_cycle()` no sale con cola vacía; `Commands::generate()` con `--batch` llama a `run()` sin tipos y `reset_cycle()` vacía `generated` entero.
- `write_document()` devuelve `false` sin registro; `Storage::write()` tampoco registra. `Runner::status()` no tiene clave de fallos.
- `State::save()` hace `wp_cache_delete` + `get_option` + `update_option` por llamada; `Runner::remove_document()` llama a `State::forget()` para cualquier post type; `LlmsTxtBuilder::build_full()` llama a `Delivery::document()` por ítem (`:223`), que rellena perezosamente con `Runner::generate_item()` y `State::mark_generated()`.
- `LlmsTxtRouter::document()` regenera todos los artefactos llms en un fallo de caché; `invalidate()` borra `llms.txt` y `llms-full.txt` en cada guardado.
- `CrawlerProbe::probe_crawler()` devuelve `home`, `post_html`, `post_markdown`; `test_probe_uses_crawler_user_agents_and_accept_headers` fija las claves de `site` y el conteo de 3 por crawler.
- `ManifestRouter::rest_openapi()` solo añade `Cache-Control`; `wpasl_before_serve` no se dispara en REST. `ContentSignals::headers($html)` devuelve el mapa de cabeceras.
- `Http::send_header()` registra siempre en `self::$log`; en PHPUnit `headers_sent()` es `true`, así que ese log es lo único que los tests de cabeceras pueden inspeccionar.
- Sin `vendor/` ni `wordpress-tests-lib` en el worktree: `composer install`, `composer run build`, `bin/install-wp-tests.sh` (contenedor `wpasl-mysql` en 3306, sexto argumento `true` si no hay cliente `mysql`).

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, sin JavaScript nuevo en el admin, WPCS limpio, sin migración de datos en un parche, compatibilidad con la opción `wpasl_state` existente y con las URLs públicas.

Decisiones tomadas por defecto donde el review ofrecía alternativas (el usuario no las revisó de forma explícita; se marcan para su aprobación en la revisión de estos artefactos): alcance de los 35 hallazgos; estado de generación mantenido en la opción con mitigación mínima (coherente con la decisión de 1.0.2 de no migrar a post meta); `llms-full.txt` en frío responde 503 y programa un evento único; el diagnóstico sondea `.md` y `robots.txt` por crawler (código, no spec); los hallazgos 23 y 34 se resuelven ajustando la spec; `--post-type` se acota en todas las combinaciones en lugar de rechazarlas; la página de entradas se sirve en lugar de excluirse.

## Goals / Non-Goals

**Goals:**

- Corregir los 35 hallazgos con cambios locales, cada uno cubierto por un test automatizado nuevo o ampliado, y publicar como 1.0.3.
- Que un WordPress limpio pueda guardar la pestaña General y programar la regeneración manual desde los botones, verificado además por el smoke con un POST autenticado.
- No romper puntos de extensión existentes: `wpasl_general_tab_after` sigue disparándose, la interfaz `Tab` no cambia, la forma de `wpasl_state` solo gana una clave.

**Non-Goals:**

- Migrar el estado de generación a post meta.
- Construir `llms-full.txt` de forma incremental o por streaming.
- Cambiar el catálogo de crawlers, las políticas por defecto o el diseño por lotes del diagnóstico.
- Redirecciones 301 para las rutas no canónicas de los documentos raíz (basta con dejar el 404 al núcleo).

## Decisions

### D1. Acción manual fuera del formulario: hook `wpasl_page_after_form`

`Page::render()` cierra `</form>` y a continuación ejecuta `do_action( 'wpasl_page_after_form', $current, $this )` tanto para pestañas con formulario como sin él. `GenerationStatus::register()` se engancha a ese hook y solo renderiza cuando `$current === 'general'`. El bloque completo (encabezado, avisos `wpasl_notice`, advertencia de cron, tabla y formulario `admin-post.php`) se mueve tal cual, con lo que `handle()`, nonce, capacidad y redirección no cambian. `GeneralTab::render()` sigue disparando `wpasl_general_tab_after` (vacío ahora) y su docblock advierte que se ejecuta dentro del formulario de ajustes y no debe imprimir formularios.

- Alternativa descartada: `Tab::render_after_form()`. Cambia una interfaz pública que terceros pueden implementar; el hook no rompe nada.
- Alternativa descartada: eliminar `wpasl_general_tab_after`. Se conserva por compatibilidad y se documenta su nuevo uso.
- Test: `Page::render()` completo con `GenerationStatus` registrado; el HTML contiene exactamente dos `<form`, cada `<form` va precedido de un `</form>` o es el primero, `name="submit"` y `wpasl_settings[_tab]` están dentro del formulario `options.php`, y `wpasl_regenerate_nonce` dentro del de `admin-post.php`.

### D2. Confirmación de guardado: `settings_errors()` sin argumento

`Page::render()` llama a `settings_errors()` sin slug, como hace `options-head.php`, de modo que imprime el aviso `general/settings_updated` del núcleo y cualquier error registrado por la sanitización. No se cambia el slug del núcleo ni se registra un aviso propio: evita el duplicado que produciría el doble `sanitize` en `add_option`.

- Test: `$_GET['settings-updated'] = 'true'`, transient `settings_errors` con la entrada del núcleo → `Page::render()` contiene `Settings saved.` una sola vez.

### D3. Sanitización parcial con `_tab` ausente o desconocido

En `Settings::sanitize()`: si `_tab` es una clave de `TAB_KEYS`, comportamiento actual. Si `_tab` no está vacío y no se reconoce, devolver `$current` sin cambios. Si `_tab` está vacío, el conjunto de claves es `array_intersect( array_keys( defaults() ), array_keys( $input ) )` más `llms_full_max_bytes` cuando la entrada traiga `llms_full_max_bytes_mb` (la conversión MB→bytes de `:167-172` se conserva para esa clave). Con ese conjunto, `null` nunca llega a `sanitize_key_value()` en modo parcial, así que las casillas no marcadas solo se interpretan como "no" cuando la pestaña es conocida y el formulario las contenía.

- Riesgo: un `update_option('wpasl_settings', $parcial)` desde código deja de "completar" el resto con valores por defecto. Es el comportamiento deseado (conservar lo almacenado); `defaults()` sigue aplicándose en `all()` para claves ausentes.
- Test: opción con valores no por defecto, `sanitize( array( '_tab' => 'otro' ) )` conserva todo; `sanitize( array( 'batch_size' => 10 ) )` cambia solo `batch_size`; un `Tab` falso registrado en `wpasl_register_tabs` con slug propio se renderiza y guarda sin tocar `post_types`.

### D4. Página de entradas como caso de la portada estática

`Delivery` incorpora `posts_page_id()` (`show_on_front === 'page'` y `page_for_posts > 0`). En `handle_md_suffix()`, antes de `url_to_postid()`, si `$relative` coincide con la ruta relativa del permalink de la página de entradas, se resuelve directamente a ese id (sujeto a elegibilidad). Un helper `is_markdown_context()` devuelve `is_singular()` o bien `is_home()` con `get_queried_object()` instancia de `WP_Post` cuyo ID es la página de entradas; `maybe_serve()`, `send_html_headers()` y `print_alternate_link()` lo usan en lugar de `is_singular()`. `markdown_url()` no cambia: `/blog/` produce `/blog.md`, que ahora se sirve.

- Alternativa descartada: excluir la página de entradas en `Eligibility`. Contradice "todo contenido elegible" y la página tiene contenido propio.
- Test: `show_on_front=page`, `page_on_front=A`, `page_for_posts=B` → `markdown_url(B)` responde 200 con `# <título de B>`; `go_to('/blog/')` con `Accept: text/markdown` sirve Markdown; el HTML de `/blog/` lleva `<link rel="alternate">`; en `Test_Llms_Txt`, toda URL emitida responde 200.

### D5. Sitios nuevos en red: `wp_initialize_site` más autorreparación

`Plugin::boot()` añade `add_action( 'wp_initialize_site', ..., 100, 1 )` que, cuando el plugin figura en `active_sitewide_plugins` (leído con `get_site_option`, sin cargar `plugin.php`), hace `switch_to_blog( $site->blog_id )`, `Lifecycle::activate_site()` (sin `flush_rewrite_rules`, ver D13) y `restore_current_blog()`. Además `Plugin::maybe_upgrade()` reprograma con `Scheduler::schedule()` cuando `current_interval()` es `null` y asegura el almacenamiento con `Storage::ensure()`, de modo que un sitio que quedó sin configurar por cualquier motivo se repara en su primera visita al admin.

- Test (multisitio): `Lifecycle::activate(true)`, `wpmu_create_blog()`/`wp_insert_site()`, `switch_to_blog()` → `wp_next_scheduled( Scheduler::HOOK )` no es `false` y `get_option( Settings::OPTION )` existe. Sin activación en red, crear un sitio no programa nada.

### D6. WP-CLI acotado por post type

`Runner::run()` gana un tercer parámetro `$post_types = null` que se guarda en `$this->cycle_post_types` durante la ejecución y se pasa a `build_queue()` en la rama de reconstrucción (`:150-152`), simetría con la rama de merge (`:155`). `run_cycle()` termina devolviendo 0 si la cola construida está vacía. `Commands::generate()` pasa `$types` a `run()` en la rama `--batch`. `reset_cycle( $post_types = null )` con tipos elimina de `generated` solo los ids de esos tipos (`Eligibility::query()` por tipo, `array_diff_key`) y vacía la cola; sin tipos, comportamiento actual.

- Alternativa descartada: rechazar `--batch` o `--all` combinados con `--post-type` con `WP_CLI::error()`. Son combinaciones útiles (regenerar un CPT recién habilitado por lotes) y el coste de acotarlas es pequeño.
- Test: `post` y `page` pendientes, `generate --post-type=page --batch` → solo el documento de la página; 3 posts y 0 páginas, `generate --post-type=page` → ningún documento y `Processed 0`; `generate --all --post-type=page` con ambos tipos generados → los posts siguen en `generated`.

### D7. Fallos de generación contados en el estado

`wpasl_state` gana `failed => array( post_id => intentos )`. `write_document()` sigue devolviendo `bool` pero deja el motivo en `$this->last_error` (`no_generator`, `empty_document`, `storage_write`); `Storage::write()` registra con `error_log()` el motivo (`mkdir`, `file_put_contents`, `rename`) y la ruta. En `run()`, tras un fallo: `++$state['failed'][$id]`, `do_action( 'wpasl_generation_failed', $post, $reason, $attempts )` y `error_log()`. Tras un éxito: `unset( $state['failed'][$id] )`. `never_generated_ids()` excluye del frente los ids con `failed >= apply_filters( 'wpasl_max_failures', 3 )`; `build_queue()` los coloca al final. `status()` expone `failed` (conteo); `GenerationStatus::render()` añade la fila "Failed items" y `Commands::status()` la línea correspondiente. `State::save()` en modo merge fusiona `failed` con `array_replace` igual que `generated`.

- Alternativa descartada: abandonar el ítem definitivamente. Un fallo de permisos suele ser transitorio; despriorizar mantiene el reintento sin bloquear el lote.
- Test: generador stub que devuelve `false` → tras dos `run()`, `status()['failed'] === 1`, el estado guarda `failed[$id] === 2`, la acción se disparó con el motivo; con tres fallos, el siguiente `run()` procesa primero un ítem sano.

### D8. Escrituras del estado acotadas

`Runner::remove_document()` vuelve inmediatamente cuando el post type del ítem no está habilitado (nada que borrar, nada que olvidar). `Runner` mantiene `$this->run_state` (referencia al `$state` en curso) mientras `run()` está activo; `generate_item()` registra la marca en `$this->run_state['generated']` en lugar de `State::mark_generated()` cuando `run_state` no es `null`. Como los artefactos (`LlmsTxtBuilder::generate()`, `build_full()`) se construyen dentro de `run()`, sus rellenos perezosos se guardan una sola vez al final.

- Alternativa descartada: mover la marca a post meta. Rechazado por el usuario en 1.0.2; sigue siendo un cambio mayor para un parche.
- Test: 100 ítems no generados y `llms_full_enabled` → `run()` completa el ciclo con `get_option( State::OPTION )` escrita una vez (contador con el filtro `pre_update_option_wpasl_state`); enviar a papelera un ítem de tipo no habilitado no cambia la opción.

### D9. Vista previa de robots.txt igual al núcleo

`RobotsTxt::generated_output()` deja de ramificar por `blog_public`: emite `User-agent: *`, `Disallow: <path de admin_url()>` y `Allow: <path de admin_url('admin-ajax.php')>` y pasa `$public` al filtro `robots_txt` con las mismas prioridades que en el front. El test compara con `ob_start(); do_robots(); ob_get_clean()` para `blog_public` 1 y 0.

### D10. `llms.txt` por fichero; `llms-full.txt` en frío con 503 y evento único

`LlmsTxtBuilder::generate_file( Storage $storage, string $file )` construye solo el fichero pedido; `generate()` pasa a llamarla para ambos. `LlmsTxtRouter::document( FILE )` usa `generate_file( FILE )`. Para `FULL_FILE` ausente con la función habilitada, `serve()` responde 503 con `Retry-After: 120`, `Cache-Control: no-store` y un cuerpo breve, y llama a `Scheduler::schedule_llms_full()`, que programa `wp_schedule_single_event( time(), 'wpasl_build_llms_full' )` si no hay uno pendiente. El handler de ese hook (en `Scheduler`, delegando en el builder) escribe `llms-full.txt`. La regeneración al final del ciclo (`Runner`) y `wp wpasl generate` siguen construyéndolo.

- Alternativa descartada: construir `llms-full.txt` en la petición que lo pide. Mantiene el riesgo de `max_execution_time` y artefacto a medias para cualquier visitante.
- Alternativa descartada: 404 hasta el siguiente cron. Un 404 puede cachearse y hace creer al agente que el fichero no existe.
- Test: con `llms_full_enabled`, guardar ajustes y pedir `/llms.txt` → 200, `llms-full.txt` ausente, ningún documento de ítem escrito; pedir `/llms-full.txt` → 503 con `Retry-After`, evento `wpasl_build_llms_full` programado; ejecutar el evento → 200.

### D11. Diagnóstico: `.md` y `robots.txt` por crawler

`CrawlerProbe::probe_crawler()` añade `post_md` (petición a `markdown_url` con el user-agent del crawler, sin `Accept`) y `robots` (`/robots.txt` con ese user-agent). `site_targets()` conserva `markdown_url` y `robots` (el veredicto por crawler sigue usando el cuerpo de `site.robots`). `Report::crawler_checks()` añade las comprobaciones `markdown_url` (error si no es 200 o no es Markdown) y `robots_fetch` (error si no es 200). `DiagnosticsTab` muestra ambas. El coste extra es de dos peticiones por crawler, absorbido por los lotes de 30 s.

- Test: `$report['crawlers']['GPTBot']['checks']` contiene `markdown_url` y `robots_fetch`; un 403 en `.md` solo para `ClaudeBot` (filtro `pre_http_request` por user-agent) aparece como error solo en ClaudeBot. Se actualizan las aserciones de `test_probe_uses_crawler_user_agents_and_accept_headers` (5 peticiones por crawler).

### D12. Señales en el documento OpenAPI por REST

`ManifestRouter` recibe `ContentSignals` por constructor (parámetro opcional al final para no romper el mapa de servicios de `Plugin::boot()`, que se actualiza para pasarlo) y `rest_openapi()` añade `$response->header( $name, $value )` por cada entrada de `headers( false )`.

- Test: `test_openapi_rest_route` afirma `Content-Signal` y `Content-Usage` en `$response->get_headers()`; con el interruptor desactivado, solo `Content-Signal`.

### D13. Ciclo de vida y desinstalación

`Lifecycle::deactivate()` deja de llamar a `flush_rewrite_rules()` (el plugin no registra reglas; todo va por `parse_request`). `for_each_site()` y `Uninstaller` paginan `get_sites()` de 100 en 100 con `offset`. `Uninstaller` usa `Scheduler::unschedule()` (que limpia `HOOK` y `HOOK` + `MANUAL_ARGS`) y además `wp_clear_scheduled_hook( 'wpasl_build_llms_full' )`.

- Test: `run_soon()` antes de desinstalar → `_get_cron_array()` sin entradas `wpasl_generate`; desactivar no cambia la opción `rewrite_rules`.

### D14. `Http` con marca de envío

`Http::send_header()` y `remove_header()` registran siempre la operación pero con una clave `sent` (`true` solo si se llamó a `header()`). `effective_headers( $only_sent = false )` conserva el comportamiento actual por defecto (los tests siguen inspeccionando el log en CLI) y permite pedir solo lo enviado. Se documenta en el docblock que el log es una réplica en memoria.

- Alternativa descartada: no registrar cuando `headers_sent()`. Deja a los tests de PHPUnit sin ninguna forma de comprobar cabeceras.

### D15. Cabeceras y rutas de manifiestos y señales

- Catálogo: `Content-Type: application/linkset+json` sin `charset` (RFC 9264/9727); `agent-skills.json` conserva `charset`.
- `Link: <…/.well-known/api-catalog>; rel="api-catalog"`: `ContentSignals::send()` lo añade con `Http::send_header( 'Link', ..., false )` en contextos HTML y Markdown (`wpasl_before_serve` con contexto `markdown`) cuando `manifest_enabled` es verdadero; no en llms.txt, manifiestos ni robots.
- `X-Robots-Tag` solo en HTML: cuando el contexto es el objeto `WP`, es HTML solo si `query_vars` no contiene `feed`, `robots`, `sitemap` ni `sitemap-stylesheet`.
- Rutas exactas: `LlmsTxtRouter` y `ManifestRouter` comparan `'/' . ltrim( $path, '/' )` solo si `$path` no contiene `//` ni termina en `/`; en cualquier otro caso no interceptan y el núcleo responde 404.
- `read-markdown`: plantilla `home_url( '/{+path}.md' )`.
- OpenAPI con enlaces simples: `servers[0].url = home_url()` y cada clave de `paths` con prefijo `/?rest_route=`; con enlaces bonitos no cambia nada.

### D16. Correcciones locales restantes

- Comandos `curl`: helper `shell_quote( $s )` = `'` + `str_replace( "'", "'\\''", $s )` + `'`, independiente de la configuración regional (a diferencia de `escapeshellarg()`).
- `Report::load()` normaliza con una fusión recursiva contra `Report::defaults()` y descarta informes cuya clave `version` no coincida con `WPASL_VERSION` mayor.minor (se muestran como "no disponible").
- `## Optional` se omite cuando `optional_links()` está vacío; el orden por `menu_order`/`title` se aplica solo cuando `'page' === $type`.
- `relative_path()` exige `/` o fin de cadena tras el path base. `absolutize()` usa `'/' === $path ? '/' : untrailingslashit( $path )` y se corrige la aserción de `tests/test-converter.php:48`.
- `ConverterInterface` añade `set_base_url( string $url ): void`; `DocumentBuilder` deja de usar `method_exists`. Cambio de interfaz documentado en el changelog (afecta solo a conversores sustituidos por `wpasl_services`).
- `Runner::prune()` elimina `*.tmp` con `filemtime` anterior a una hora; `Storage::write()` crea `index.php` en cada directorio nuevo y `ensure()` añade `web.config` con `<authorization><deny users="*"/></authorization>` junto a `.htaccess`.
- `DocumentBuilder` guarda y restaura `$pages`, `$numpages`, `$multipage`, `$authordata`, `$id` además de `$post`, `$more`, `$page`.
- `Eligibility::excluded_ids()` usa `meta_value NOT IN ('', '0')` e `is_excluded()` el mismo predicado sobre el valor del meta.
- `readme.txt` gana la sección "Filters" con los 15 filtros y acciones (`wpasl_agent_capabilities`, `wpasl_crawler_catalog`, `wpasl_llms_sections`, `wpasl_llms_txt`, `wpasl_llms_optional_links`, `wpasl_agent_skills`, `wpasl_openapi`, `wpasl_api_catalog`, `wpasl_diagnostics_crawlers`, `wpasl_diagnostics_time_budget`, `wpasl_run_time_budget`, `wpasl_is_eligible`, `wpasl_cron_disabled`, `wpasl_services`, `wpasl_register_tabs`, `wpasl_page_after_form`, `wpasl_generation_failed`, `wpasl_max_failures`).

## Risks / Trade-offs

- [Terceros que imprimían formularios en `wpasl_general_tab_after`] → el hook sigue existiendo; el changelog y el docblock indican que ahora está dentro del formulario de ajustes y que `wpasl_page_after_form` es el lugar para formularios propios.
- [`settings_errors()` sin slug imprime avisos de otros grupos si otro plugin los registra en la misma petición] → solo ocurre en la página del plugin y tras un guardado propio; es el mismo comportamiento que las pantallas `options-*.php`.
- [Un 503 en `/llms-full.txt` cacheado por un CDN] → `Cache-Control: no-store` y `Retry-After`; los CDN respetan `no-store` para 503.
- [`wp_initialize_site` se ejecuta con el plugin activo solo en algunos sitios] → se comprueba `active_sitewide_plugins`; sin activación en red no se hace nada, como hoy.
- [Dos peticiones más por crawler en el diagnóstico (21 crawlers × 5 = 105 peticiones)] → el diseño por lotes ya reparte la carga; con 5 s por petición el peor caso sigue dentro del presupuesto por lote.
- [Despriorizar ítems fallidos puede retrasar contenido válido si el fallo era puntual] → el contador se reinicia con el primer éxito y el ítem sigue en la cola, solo al final.
- [Cambio de `ConverterInterface`] → solo afecta a conversores inyectados por `wpasl_services`; se anuncia en el changelog y en la sección "Filters".
- [Rutas exactas para documentos raíz] → clientes que pedían `/llms.txt/` reciben 404; no hay evidencia de uso y el comportamiento anterior duplicaba contenido.

## Migration Plan

Sin migración de datos. `wpasl_state` se lee con `failed` ausente hasta la primera escritura. El transient del informe de diagnóstico se normaliza al leerlo. Despliegue como actualización normal del plugin (1.0.3); reversión a 1.0.2 sin pasos adicionales, ya que ninguna opción cambia de forma.
