## Context

Ver `proposal.md` para la motivación y los deltas de `specs/` para el comportamiento exigido. Estado actual verificado en este worktree (`985e08b`, rama `maorodriguez/plan-agentic-readiness-round-1`), con las correcciones respecto al encargo:

- **Catálogo RFC 9727.** `ManifestBuilder::api_catalog()` (`src/Manifest/ManifestBuilder.php`) construye un linkset con una sola entrada anclada en `untrailingslashit( rest_url() )` con `service-desc` y `service-doc`. `ManifestRouter::headers()` envía `Content-Type: application/linkset+json` **sin** `charset` (el encargo decía `; charset=utf-8`; no es así, y el comentario del código cita RFC 9264 para no añadir parámetros). El RFC 9727 (leído en https://www.rfc-editor.org/rfc/rfc9727.txt): el catálogo MUST ser `application/linkset+json`, el linkset SHOULD llevar `profile="https://www.rfc-editor.org/info/rfc9727"`, y en el apéndice A.2 la entrada anclada en la URL del catálogo usa `item` (RFC 6573) para cada API; el apéndice A.1 muestra la entrada por API con `service-desc`, `service-doc`, `status` y `service-meta` (RFC 8631). `Report::site_checks()` (`src/Diagnostics/Report.php:250`) compara el `Content-Type` con `stripos()` por subcadena, así que `application/linkset+json; profile=...` sigue pasando sin cambios. El test `Test_Agent_Manifest::test_api_catalog_route` afirma el tipo exacto sin parámetros y lee `linkset[0]`: hay que ajustarlo. La spec actual de `agent-manifest` exige "exactamente ese valor y sin parámetros": se modifica.
- **OpenAPI.** `ManifestBuilder::openapi()` declara por operación `responses` `200` (con esquema del servidor REST vía `schema_for()`) y `404` solo con descripción; no hay `components`. `info.description` es una frase fija traducible. Las capacidades REST vienen de `CapabilityRegistry::all()` y solo entran en `paths` las que empiezan por `rest_url()`; los espacios de nombres reales se pueden derivar de las claves de `paths` (`/wp/v2/...`, `/wpasl/v1/...`, o `/?rest_route=/wp/v2/...` con enlaces simples). Verificado en vivo que la API REST responde errores JSON `{code, message, data:{status}}`.
- **auth.md.** `AuthMdBuilder::document()` es una plantilla en inglés sin `__()` con secciones `## Audience`, `## Registration and credential provisioning`, `## Supported access methods`, `## Credential use`, `## Usage policy`, `## Contact` y `## Notes` opcional; pasa por `wpasl_auth_md`. Recibe `Settings`, `CapabilityRegistry` y `ContentSignals`.
- **Entrega Markdown.** `Delivery::handle_md_suffix()` (`parse_request`, prioridad 1) llama a `serve_md_suffix()`, que pone `md_request_post_id = -1` como marca de "petición `.md` que no resolvió", desactiva `redirect_canonical` y llama a `force_404()` (`query_vars = ['error' => '404']`) cuando la ruta no resuelve o el contenido no es elegible; `maybe_serve()` cuelga de `template_redirect` prioridad 1 y `send_html_headers()` de prioridad 2; `prefers_markdown()` es estática y pública. `Delivery::serve()` hace `Http::remove_header( 'Link' )` antes de `wpasl_before_serve` porque `send_headers` ya emitió el `Link rel="api-catalog"` para HTML. `ContentSignals::send()` trata cualquier contexto de cadena como no HTML (retira `X-Robots-Tag`) y solo añade el `Link` del catálogo cuando el contexto es `markdown`. En los tests, `go_to()` ejecuta `WP::main()` (que incluye `handle_404`) pero **no** dispara `template_redirect`; los tests existentes llaman a `maybe_serve()` a mano tras `go_to()`. En vivo, `https://cognosonline.com/nonexistent-page-xyz.md` responde 404 `text/html`.
- **Sitemap.** `LlmsTxtBuilder::optional_links()` solo enlaza el sitemap cuando `wp_sitemaps_get_server()->sitemaps_enabled()` es verdadero, usando `get_sitemap_url( 'index' )`. En cognosonline.com los sitemaps del núcleo están desactivados: `/wp-sitemap.xml` y `/sitemap.xml` responden 301 hacia `/sitemap_index.xml` (200, `text/xml`), la ruta de Yoast SEO y Rank Math, y `robots.txt` no lleva línea `Sitemap:`. Hoy el `llms.txt` del sitio no enlaza ningún sitemap.
- **llms.txt.** `LlmsTxtBuilder::sections()` devuelve `post type => ids` limitado por `llms_limit` (por defecto 100, saneado 1..1000) y filtrado por `wpasl_llms_sections`; `build()` emite `## <etiqueta>` con `item_line()` por ítem y `## Optional`; `build_full()` recorre las mismas secciones. `LlmsTxtRouter::requested_file()` solo reconoce `llms.txt` y `llms-full.txt` por ruta exacta (`ManifestRouter::exact_relative_path()`); `physical_path()` es solo para `llms.txt` (filtro `wpasl_physical_llms_path`); `invalidate()` borra los dos archivos; `document()` genera bajo demanda solo `llms.txt`; `serve()` dispara `wpasl_before_serve` con contexto `llms-txt`. `LlmsTab` recibe solo `Settings` (`LlmsTxtRouter::register_tab()`). `Test_Llms_Txt::test_every_emitted_markdown_url_is_servable` extrae con una expresión regular todas las URLs `.md`/`wpasl=md` de `build()` y las sirve una a una con `go_to()` + `maybe_serve()`.
- **Ajustes.** `Settings::TAB_KEYS['llms'] = ['llms_description','llms_intro','llms_limit','llms_full_enabled','llms_full_max_bytes']`; `sanitize_key_value()` por clave; `auth_md_notes` se sanea con `sanitize_textarea_field()` + `mb_substr( …, 0, AUTH_MD_NOTES_MAX )` (4000), patrón idempotente que reutilizamos. `Test_Settings` afirma `llms_limit === 100` en los defaults y lo usa en varios tests de idempotencia.
- **Diagnóstico.** `CrawlerProbe::site_targets()` devuelve claves fijas (`robots`, `llms`, `auth`, `skills`, `catalog`, `markdown_url`, `storage`); `KEEP_BODY = ['robots','llms']` y `fetch()` guarda solo `MAX_BODY_KEPT` bytes del cuerpo, con `limit_response_size = MAX_RESPONSE_BYTES`. `Report::site_checks()` recorre un mapa fijo clave => [tipo esperado, etiqueta]. `Test_Diagnostics::test_report_checks_auth_md` afirma la lista exacta de claves de `site`. `DiagnosticsTab::curl_commands()` y `render_checklist()` enumeran las rutas a mano.
- **Head y shortcode.** El único `wp_head` del plugin es `Delivery::print_alternate_link()` (prioridad 5). No hay shortcodes, bloques, `block.json`, directorio `assets/` ni pipeline de build de JS.
- **Relaciones IANA** (leídas en https://www.iana.org/assignments/link-relations/link-relations.xhtml): `api-catalog` (RFC 9727), `service-desc`, `service-doc`, `service-meta`, `status` (RFC 8631), `describedby` (POWDER), `help` y `alternate` (HTML), `item` (RFC 6573) están registradas.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, WPCS limpio, sin bump de versión, sin tocar `readme.txt`, `README.md` ni `languages/`; cadenas de interfaz en inglés con el text domain `wp-agent-support-layer`; documentos para agentes en inglés sin `__()`; ninguna petición saliente nueva.

## Goals / Non-Goals

**Goals:**

- Resolver los siete hallazgos del escáner que dependen del plugin con documentos veraces, sin declarar nada que el sitio no cumpla.
- Reducir `llms.txt` por debajo de 30.000 caracteres en sitios como cognosonline.com sin perder la lista completa de contenido, que pasa a archivos por tipo servidos con las mismas garantías (ruta exacta, archivo físico, almacenamiento, invalidación, cabeceras).
- Mantener y extender la invariante "toda URL Markdown emitida se sirve".
- Encajar cada mejora en el patrón existente (routers, builders, `Settings`, pestañas, `Report`) para que la implementación sea un commit por grupo A–G.

**Non-Goals:**

- Arreglar los hallazgos del escáner que son timeouts del propio escáner (alcanzabilidad, 404 HTML, negociación, errores JSON).
- RFC 9457 (`application/problem+json`), cabeceras `Deprecation`/`Sunset`, o cualquier compromiso de soporte: WordPress no los emite.
- Un bloque de Gutenberg para los enlaces: exige `block.json`, script de editor y build; el shortcode cubre el caso de uso (pie del tema) sin assets.
- Replicar la guía "cuándo usar este sitio" en `agent-skills.json`: `description` mapea a `schema.org/description` (la descripción corta del sitio) y no hay término en el `@context` para una guía en prosa; `llms.txt` y `auth.md` ya la llevan.
- Detección del sitemap por HTTP: el plugin no hace peticiones salientes fuera del diagnóstico; la detección es local.
- Cambiar la forma de llamar a `llms-full.txt` ni su política de construcción en segundo plano.

## Decisions

### A1. Catálogo con dos entradas y `profile` en el `Content-Type`

`ManifestBuilder::api_catalog()` devuelve:

```json
{ "linkset": [
  { "anchor": "<home_url('/.well-known/api-catalog')>",
    "item": [ { "href": "<untrailingslashit(rest_url())>" } ] },
  { "anchor": "<untrailingslashit(rest_url())>",
    "service-desc": [ { "href": "<openapi_url()>", "type": "application/openapi+json" } ],
    "service-doc":  [ llms.txt, auth.md (si publicado), agent-skills.json ] }
] }
```

- La entrada 0 sigue el apéndice A.2 del RFC (catálogo → `item` → APIs); la entrada 1 sigue el A.1 (API → `service-desc`/`service-doc`). Una sola API porque el sitio solo expone la API REST de WordPress; una capacidad añadida por filtro con otra base no crea otra entrada (quien la quiera usa `wpasl_api_catalog`).
- `ManifestRouter::headers()` pasa a `application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"` para el catálogo. El RFC lo pide con SHOULD y el escáner lo exige; el comentario del código sobre RFC 9264 se reescribe (RFC 9264 §5 admite `profile` como parámetro del tipo). `Report::site_checks()` no cambia (subcadena). El test del catálogo se reescribe para `linkset[0].item` y `linkset[1]`.
- Con enlaces permanentes simples `rest_url()` es `home_url('/?rest_route=/')`; `untrailingslashit()` deja `?rest_route=` que es lo que el sitio sirve; se cubre con escenario.

Alternativa descartada: una sola entrada anclada en el catálogo con `item`, `service-desc` y `service-doc` mezclados. El RFC anida la descripción por API bajo el ancla de la API; el escáner busca `item` en `linkset[0]` y `service-desc` puede seguir encontrándose recorriendo el linkset.

### B1. `components.schemas.Error` y respuestas `400`, `404` y `default` en todas las operaciones

- `openapi()` añade `components.schemas.Error`:

```json
{ "type": "object", "required": ["code","message"],
  "properties": {
    "code": { "type": "string", "description": "Machine-readable error code, e.g. rest_no_route or rest_post_invalid_id." },
    "message": { "type": "string", "description": "Human-readable message." },
    "data": { "type": "object", "properties": { "status": { "type": "integer", "description": "HTTP status code." } }, "additionalProperties": true } },
  "additionalProperties": true }
```

- Cada operación declara `400` ("Invalid parameter."), `404` ("Not found.") y `default` ("Error response of the WordPress REST API.") con `content: { "application/json": { "schema": { "$ref": "#/components/schemas/Error" } } }`. Se eligen `400` y `404` porque son los que las rutas GET públicas del núcleo devuelven realmente (`rest_invalid_param`, `rest_no_route`, `rest_post_invalid_id`, `rest_forbidden` para ítems no públicos es 401/403 y queda cubierto por `default`). Las descripciones siguen siendo traducibles como las de `200`.
- Se descarta RFC 9457 y no se añade `application/problem+json` en ningún sitio.

### C1. Política de versionado derivada de las rutas reales, compartida por OpenAPI y auth.md

- Nuevo método `ManifestBuilder::versioning_policy( array $namespaces )` (o función estática compartida) que devuelve el párrafo en inglés; los espacios de nombres se extraen de las claves de `paths` con una expresión regular sobre `/(?:\?rest_route=/)?([a-z0-9_-]+/v\d+)/` y se listan ordenados y únicos. Texto:

  > This API is the WordPress REST API. Routes are grouped in versioned namespaces (wp/v2, wpasl/v1). Changes follow the release cycles of WordPress core and of the WP Agent Support Layer plugin. This site does not send Deprecation or Sunset headers. Backwards-incompatible changes to the wpasl/v1 routes are announced in the plugin changelog; changes to core routes follow the WordPress release notes.

- `info.description` concatena la frase actual traducible y este párrafo en inglés (sin `__()`, porque va dirigido al agente y el escáner busca las palabras `deprecation`/`sunset`). `AuthMdBuilder::document()` añade `## API versioning and deprecation` después de `## Credential use`, llamando al mismo método con los espacios de nombres derivados de las URLs REST del registro (`CapabilityRegistry::all()`; `AuthMdBuilder` no tiene `ManifestBuilder`, por eso el método es estático y recibe los espacios de nombres). Con un solo origen del texto no se desincronizan.

### D1. 404 en Markdown desde `Delivery`, en `template_redirect` prioridad 11, con contexto `markdown-404`

- Nuevo método público `Delivery::maybe_serve_404()` colgado de `template_redirect` con prioridad 11: después de `redirect_canonical()` y `wp_old_slug_redirect()` (prioridad 10), para no interferir con las redirecciones que WordPress intenta sobre una URL inexistente antes de responder 404 (`redirect_guess_404_permalink`); para las peticiones `.md` la canónica ya está desactivada, así que llegan aquí siempre. Condiciones, en orden: `is_404()`; y una de (`md_request_post_id === -1`, `get_query_var( 'wpasl' ) === 'md'`, `prefers_markdown( $_SERVER['HTTP_ACCEPT'] )`). Con `Accept` de navegador y sin sufijo, no hace nada y el tema muestra su 404.
- Por qué no dentro de `force_404()`: en `parse_request` aún no se sabe si otro componente resolverá la petición, `is_404()` no existe y el marcador `-1` ya identifica el caso; `template_redirect` es el punto donde el 404 es definitivo.
- Respuesta: `Http::remove_header( 'Link' )` (como `serve()`), `do_action( 'wpasl_before_serve', 'markdown-404', null )`, `status_header( 404 )`, cabeceras `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` (sin `Link canonical`: no hay recurso), `echo`, y `wpasl_terminate_after_serve`. `ContentSignals::send()` añade el `Link rel="api-catalog"` también para el contexto `markdown-404` (`in_array( $context, ['markdown','markdown-404'] )`) y documenta el contexto. `no-store` porque el cuerpo depende de ajustes y de que la URL puede empezar a existir al publicar; Cache Enabler no cachea 404 ni respuestas no HTML.
- Cuerpo (`Delivery::not_found_document()`, inglés sin `__()`, filtrado por `wpasl_markdown_404`):

```
# Not found

The requested resource does not exist on <site name>, is not public, or has no Markdown version.

## Where to look instead

- [Site index (llms.txt)](<home_url/llms.txt>): curated Markdown index of the public content.
- [Agent access documentation (auth.md)](<home_url/auth.md>): how agents may access this site.   (solo si AuthMdBuilder::is_published())
- [Sitemap](<SitemapLocator::url()>): XML sitemap of the whole site.   (solo si hay URL)
- [API catalog](<home_url/.well-known/api-catalog>): RFC 9727 linkset with the OpenAPI description of the public REST API.   (solo con manifest_enabled)
```

  No se refleja la URL pedida ni ningún dato de la petición: evita cualquier inyección en el cuerpo y hace el documento cacheable por el propio filtro si alguien lo quisiera.
- `Delivery` necesita saber si `auth.md` está publicado (`AuthMdBuilder::is_published( $settings )`, estático, ya disponible) y la URL del sitemap (`SitemapLocator`, ver D2). No hace falta inyectar nada nuevo en el constructor.
- Tests: tras `go_to( '/no-existe.md' )` se llama a `maybe_serve_404()` a mano, como se hace con `maybe_serve()`; la salida se captura con `ob_start()` y las cabeceras con `Http::effective_headers()`.

### D2. `SitemapLocator`: URL del sitemap determinada localmente, compartida por `llms.txt` y el 404

Nueva clase `WPASL\Content\SitemapLocator` con `public static function url()` que devuelve `string|null`, en este orden:

1. Sitemaps del núcleo habilitados (`function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled()`) → `get_sitemap_url( 'index' )` (respeta enlaces simples: `/?sitemap=index`).
2. Líneas `Sitemap:` del `robots.txt` virtual (`apply_filters( 'robots_txt', '', (bool) get_option( 'blog_public' ) )`): Rank Math, AIOSEO y otros las añaden ahí; se toma la primera URL absoluta del mismo host.
3. Plugins SEO conocidos por clase, con la ruta que sirven: `WPSEO_Sitemaps_Router` (Yoast) → `/sitemap_index.xml`; `RankMath\Sitemap\Router` → `/sitemap_index.xml`; `AIOSEO\Plugin\AIOSEO` → `/sitemap.xml`; `SEOPress` (`function_exists( 'seopress_init' )`) → `/sitemaps.xml`. Los nombres de clase y rutas se verifican en la implementación contra el código de cada plugin (tarea explícita); una detección errónea solo produce un enlace 404 en un documento de ayuda, nunca una petición.
4. Filtro `wpasl_sitemap_url( $url|null )` sobre el resultado, para que un desarrollador la fije o la anule.
5. `null` → sin enlace.

`LlmsTxtBuilder::optional_links()` pasa a usarla (mejora colateral justificada: hoy cognosonline.com no enlaza sitemap alguno). El escenario existente "Sitemap con enlaces permanentes simples" se conserva. En los tests, los plugins SEO no existen: el caso 3 se cubre con el filtro y con el caso 2 (añadiendo una línea `Sitemap:` vía `robots_txt`), y se comprueba que con el núcleo deshabilitado (`wp_sitemaps_enabled` a `false`) y nada más, no hay enlace.

### E1. `llms_when_to_use`: textarea Markdown en la pestaña llms.txt, sección en `llms.txt` y `auth.md`

- `Settings`: clave `llms_when_to_use` (`''`) en `defaults()` y `TAB_KEYS['llms']`; saneado `mb_substr( sanitize_textarea_field(), 0, 4000 )` reutilizando el patrón de `auth_md_notes` (constante compartida renombrada a `TEXTAREA_MAX` o una nueva `LLMS_WHEN_TO_USE_MAX = 4000`; se elige la segunda para no tocar el nombre público de `AUTH_MD_NOTES_MAX`).
- `LlmsTxtBuilder::build()`: tras el intro, si `trim()` no está vacío, `## When to use this site\n\n<texto>\n\n`. El encabezado va en inglés sin `__()` (los `## <post type>` usan la etiqueta del tipo, que sí sigue el idioma del sitio; `## Optional` es fijo en inglés: precedente).
- `AuthMdBuilder::document()`: misma sección, entre `## Audience` y `## Registration and credential provisioning`, con el mismo texto. `AuthMdBuilder` ya tiene `Settings`.
- `LlmsTab`: fila "When to use this site (Markdown)" con `textarea` `rows="6"`, `maxlength`, descripción "Name your best-fit use cases and how an agent should call this site (which documents to read first, which endpoints to use). Shown in llms.txt and auth.md. Leave empty to omit the section."
- Al guardar la pestaña llms.txt se invalidan `llms.txt` (`LlmsTxtRouter::invalidate()`) y `auth.md` (`AuthMdRouter::invalidate()`), ambos ya colgados de `update_option_wpasl_settings`: sin cambios.

### F1. Nombres, límites y contenido de los archivos por tipo

- **Nombre**: `llms-<post_type>.txt` en la raíz, patrón de `llms-full.txt`. `post_type` es una clave saneada (`[a-z0-9_-]{1,20}`), así que el nombre es seguro como ruta y como archivo. Colisiones: (a) con `llms-full.txt` si existe un post type `full` → ese tipo no tiene archivo y su sección en `llms.txt` no emite "Full list" (constante `LlmsTxtBuilder::RESERVED_TYPES = ['full']`); (b) con slugs de contenido: una página con slug `llms-post.txt` es improbable y el router corre en `parse_request` prioridad 1 antes que el núcleo, igual que hoy con `llms.txt`; (c) con archivos físicos: precedencia del físico, como `llms.txt`. Se descarta `/llms/<tipo>.txt` (un directorio virtual `llms/` chocaría con una página de slug `llms` y con reglas de reescritura) y `/llms-<tipo>.md` (el sufijo `.md` es de la entrega de contenido).
- **Límites**: se retira `llms_limit` y se crean `llms_preview_limit` (10, 1..100) y `llms_type_limit` (1000, 1..10000). Por qué no reutilizar `llms_limit`: su valor almacenado (100 en cualquier sitio que haya guardado la pestaña) tendría que servir o como vista previa (y `llms.txt` no adelgazaría hasta que el administrador lo editara, que es justo lo que este cambio quiere evitar) o como límite por tipo (y la "lista completa" quedaría en 100 sin que nadie lo hubiera decidido). Con claves nuevas los dos valores por defecto son los deseados en todos los sitios al regenerar; un `llms_limit` residual en la opción es inerte (`sanitize()` solo recorre `TAB_KEYS`/`defaults()`, `all()` lo devuelve pero nadie lo lee). `Test_Settings` se actualiza. Un límite por tipo acotado (10000) evita cargar decenas de miles de posts en memoria en la generación bajo demanda; por encima, el archivo termina con `> Truncated: listing 10000 of 23456 items.` y `llms.txt` dice `(10000 items)`.
- **Vista previa**: `sections()` conserva su contrato (ids completos por tipo hasta `llms_type_limit`, filtro `wpasl_llms_sections`) y se añade `preview_sections( $sections )` que corta cada lista a `llms_preview_limit` y pasa por `wpasl_llms_preview_sections`. `build()` usa la vista previa; `build_full()` y `build_type( $type, $ids )` usan las listas completas. Así `llms-full.txt` concatena la lista completa (spec modificada) y la vista previa es un prefijo con el mismo orden. El número de ítems para la línea "Full list" es `count( $sections[ $type ] )` (lo que realmente contiene el archivo); el total elegible para la nota de truncado sale de `Eligibility::count( [ $type ] )` (una consulta `COUNT`).
- **Contenido de `llms-<tipo>.txt`** (inglés sin `__()`):

```
# <Site name> — <Type label>

> All public <Type label> of <Site name> (<n> items). Index: <home_url/llms.txt>

- [Title](<markdown url>): description
...
> Truncated: listing <limit> of <total> items.   (solo si total > limit)
```

- **Línea en `llms.txt`**: `- [Full list of <Type label> (<n> items)](<home_url/llms-<tipo>.txt>)` como última línea de la sección, solo cuando `count( $ids ) > llms_preview_limit`. Formato de enlace llms.txt estándar para que los parsers lo traten como un ítem más.
- `## Optional` no repite los archivos por tipo: ya están enlazados en su sección.

### F2. Router, generación, invalidación y diagnóstico de los archivos por tipo

- `LlmsTxtBuilder`: `type_file( $post_type )` devuelve `llms-<tipo>.txt` o `null` si el tipo está reservado; `type_files()` enumera los archivos de los tipos habilitados; `generate()` escribe `llms.txt`, cada archivo por tipo y `llms-full.txt`, y borra del almacenamiento cualquier `llms-*.txt` que no corresponda a un tipo habilitado (listado de la raíz del almacenamiento filtrado por `^llms-[a-z0-9_-]+\.txt$`, excluido `llms-full.txt`, que ya gestiona `generate_file`). `generate_file( $storage, $file, $sections )` acepta los nombres por tipo y construye solo ese archivo.
- `LlmsTxtRouter::requested_file()` reconoce `llms.txt`, `llms-full.txt` y `llms-<tipo>.txt` cuando `<tipo>` es un post type habilitado con archivo (`Settings::enabled_post_types()` y `type_file()`); otro nombre → `null` (404 del núcleo). `physical_path( $file = 'llms.txt' )` construye `ABSPATH . $file` y el filtro `wpasl_physical_llms_path` recibe `$file` como segundo argumento (compatible: los usos actuales ignoran el segundo parámetro). `handle_request()` aplica la precedencia del físico a todos los archivos. `document()` genera bajo demanda `llms.txt` y los archivos por tipo (nunca `llms-full.txt`). `invalidate()` borra `llms.txt`, `llms-full.txt` y todo `llms-*.txt` del almacenamiento (sin necesitar la lista de tipos, que puede haber cambiado en ese mismo guardado). `serve()` y `headers()` no cambian (contexto `llms-txt`).
- `LlmsTab` recibe `Storage` además de `Settings` (`LlmsTxtRouter::register_tab()` ya tiene ambos): muestra las URLs de los archivos por tipo, el tamaño (`mb_strlen()` del `llms.txt` almacenado) y el aviso `notice notice-warning inline` cuando supera `LlmsTxtBuilder::RECOMMENDED_MAX_CHARS = 30000`. Sin archivo almacenado: "Not generated yet; it will be built on the next request or run."
- **Diagnóstico**: `CrawlerProbe` recibe además `LlmsTxtBuilder` (o `Settings`) para enumerar los archivos por tipo; `site_targets()` añade `llms-<tipo>` (clave `llms-page`, `llms-post`, …) entre `llms` y `auth`, en el orden de `sections()` (páginas primero). `Report::site_checks()` construye el mapa de expectativas con las claves fijas más una entrada `text/markdown` por cada clave que empiece por `llms-` presente en el resultado (etiqueta `llms-<tipo>.txt`), así el informe funciona con la lista de tipos vigente al sondear y con informes antiguos. `fetch()` guarda `body_length` (longitud en caracteres del cuerpo recibido, medida antes del recorte a `MAX_BODY_KEPT`, que es 64 KiB) para los objetivos con cuerpo; `site_checks()` marca `llms` como advertencia cuando `body_length > 30000`: "llms.txt: HTTP 200, text/markdown, but 82,000 characters; agents expect at most 30,000. Lower 'Items per section in llms.txt'." Verificado: `MAX_RESPONSE_BYTES` es 1 MiB (`limit_response_size`), así que un `llms.txt` de hasta ese tamaño se mide entero; por encima, la advertencia sigue disparándose porque 1 MiB supera el umbral. `DiagnosticsTab::curl_commands()` y el checklist incluyen los archivos por tipo. `test_report_checks_auth_md` actualiza la lista exacta de claves.
- **Invariante**: `test_every_emitted_markdown_url_is_servable` se extiende: además de las URLs de `build()`, recorre las de cada `build_type()`; y comprueba que cada URL `/llms-<tipo>.txt` enlazada en `build()` responde 200 por el router. Con el límite de vista previa por defecto (10) y 4 ítems el test actual no cambia de recuento; se añade un caso con más ítems que la vista previa para que aparezca la línea "Full list".
- **CLI y estado**: los archivos por tipo pertenecen al generador `llms-txt`; `wpasl status` no cambia.

### G1. `DiscoveryLinks`: `<link>` en `wp_head` y shortcode, en `src/Manifest/`

- Nueva clase `WPASL\Manifest\DiscoveryLinks` con `Settings`; `register()` cuelga `print_links()` de `wp_head` prioridad 5 (junto al `alternate` de `Delivery`) y `add_shortcode( 'wpasl_agent_links', … )` en `init`. `links()` devuelve una lista `[ rel, type, href, label ]` filtrada por `wpasl_discovery_links`: `service-desc`/`application/openapi+json`/`ManifestBuilder::openapi_url()`/"OpenAPI description of the public REST API"; `api-catalog`/`application/linkset+json`/catálogo/"API catalog (RFC 9727)"; `describedby`/`text/markdown`/`llms.txt`/"Site index for agents (llms.txt)"; `service-doc`/`text/markdown`/`auth.md`/"Agent access documentation (auth.md)" solo si `AuthMdBuilder::is_published()`. Vacía cuando `manifest_enabled` es falso. Las etiquetas del shortcode son traducibles (interfaz pública del tema); los atributos `rel`/`type` no.
- En todas las páginas públicas y no solo en la portada: el `Link rel="api-catalog"` HTTP ya va en toda respuesta HTML (RFC 9727 §4), los agentes aterrizan en cualquier URL, el coste son cuatro líneas, y en sitios con caché de página (Cache Enabler) el `<head>` sobrevive donde las cabeceras se pierden (ver `PageCache`). `describedby` hacia `llms.txt` en una página interior es una aproximación (describe el sitio, no la página); se acepta y se documenta.
- Relaciones: `service-desc` (descripción para máquinas → OpenAPI), `api-catalog` (RFC 9727 §3 muestra el enlace en HTML), `describedby` (recurso que describe el contexto → `llms.txt`), `service-doc` (documentación del servicio → `auth.md`). Se descarta `help` para `auth.md` ("context-sensitive help" del HTML es para humanos) y `alternate` (ya lo usa la versión Markdown del contenido).
- Shortcode: `<ul class="wpasl-agent-links"><li><a href="…" rel="…" type="…">label</a></li>…</ul>`; devuelve `''` con los manifiestos deshabilitados. Sin atributos en esta versión.
- Efecto en el escáner incierto (el usuario lo asume); el diagnóstico no lo comprueba.

### H1. Tests y cobertura

- `Test_Agent_Manifest`: `test_api_catalog_route` reescrito (dos entradas, `item`, `profile`), `test_api_catalog_with_plain_permalinks`, `test_openapi_error_schema_and_responses`, `test_openapi_has_no_problem_json`, `test_openapi_description_states_versioning_policy`.
- `Test_Auth_Md`: `test_versioning_section`, `test_when_to_use_section_present_and_absent`.
- Nuevo `tests/test-markdown-404.php` (`Test_Markdown_404`): sufijo inexistente, sufijo no elegible, `Accept` en URL inexistente, navegador sin sufijo (sin salida, `is_404()`), enlaces según configuración, sin reflejo, cabeceras y `Link` único, filtro.
- `Test_Llms_Txt`: `test_when_to_use_section`, `test_preview_and_full_list_line`, `test_type_file_lists_all_items_and_truncates`, `test_type_file_route_headers_and_lazy_generation`, `test_type_file_unknown_or_disabled_type_is_404`, `test_type_file_non_canonical_paths`, `test_type_file_physical_precedence`, `test_reserved_type_full`, `test_invalidation_and_regeneration_remove_stale_type_files`, `test_llms_full_concatenates_full_lists`, `test_optional_sitemap_from_seo_plugin_or_filter`, `test_optional_omits_sitemap_when_unknown`, extensión de `test_every_emitted_markdown_url_is_servable`, `test_tab_shows_size_and_warning`.
- `Test_Settings`: defaults nuevos sin `llms_limit`, rangos, truncado, conservación al guardar otra pestaña, opción antigua con `llms_limit`.
- `Test_Diagnostics`: `test_report_checks_type_files`, `test_report_warns_on_large_llms_txt`, catálogo con `profile`, comandos `curl` de los archivos por tipo, actualización de la lista exacta de claves.
- Nuevo `tests/test-discovery-links.php` (`Test_Discovery_Links`): portada, página interior, `auth.md` desactivado, manifiestos deshabilitados, shortcode, filtro.
- `docs/spec-coverage.md`: fila por escenario de los cinco deltas, citando `agentic-readiness-round-1` en el párrafo introductorio.

## Risks / Trade-offs

- [Consumidores del catálogo que leen `linkset[0]['service-desc']`] → Rompe para ellos; se documenta como **BREAKING** en la release. Los agentes conformes con RFC 9727 recorren el linkset por ancla.
- [Cambio de forma de `llms.txt`: cada sección pasa de hasta 100 ítems a 10 + enlace] → Agentes que solo lean `llms.txt` ven menos ítems directamente; la línea "Full list" es un enlace llms.txt estándar y `llms-full.txt` sigue concatenando todo. Se documenta en la release. El administrador puede subir la vista previa hasta 100.
- [`llms_limit` almacenado se ignora] → Un sitio que lo hubiera bajado a propósito (p. ej. 20) obtiene 10 de vista previa y 1000 por tipo; la pestaña muestra los valores nuevos y el tamaño resultante. Riesgo bajo y documentado.
- [Generación bajo demanda de un archivo por tipo con miles de ítems] → Acotada por `llms_type_limit` (10000 máximo), consulta de ids con `fields => ids`, `_prime_post_caches()` por bloques como hace `Eligibility::query()`; el archivo se cachea en el almacenamiento y se regenera en el ciclo. Un sitio con decenas de miles de ítems por tipo se queda en el límite y con la nota de truncado.
- [Detección del sitemap por clase de plugin SEO con nombres desactualizados] → Solo afecta a un enlace de ayuda; el filtro `wpasl_sitemap_url` permite fijarlo; la tarea de implementación verifica los nombres en el código de cada plugin y deja el caso 2 (`robots.txt`) como red.
- [404 Markdown en rutas 404 forzadas por el propio plugin (`/llms-full.txt` deshabilitado, `/auth.md` desactivado, manifiestos deshabilitados)] → Con `Accept: text/markdown` el agente recibe el 404 Markdown con enlaces útiles; con `Accept` de navegador, el 404 HTML. Coherente y deseable.
- [Cachés y CDNs ante un 404 con `no-store`] → No se cachea; un ataque de URLs aleatorias `.md` cuesta lo mismo que un 404 HTML del tema (menos: no carga plantilla). Sin reflejo de la petición.
- [`describedby` hacia `llms.txt` en páginas interiores] → Semántica aproximada; el `alternate` de la página sigue siendo el enlace preciso al Markdown de esa página.
- [Mezcla de idiomas en `auth.md` y `llms.txt`] → Los encabezados nuevos van en inglés y la guía del administrador en el idioma que escriba; precedente de `## Optional` y de la plantilla de `auth.md`.
- [Diagnóstico con más objetivos] → Un objetivo por tipo habilitado (normalmente 2–4) con 5 s de tope cada uno; dentro del presupuesto de 30 s por lote porque las comprobaciones de sitio ya se hacen una sola vez al empezar.
- [Filtro `wpasl_physical_llms_path` con un argumento nuevo] → Compatible hacia atrás; un callback antiguo que devuelva una ruta fija la aplicaría a todos los archivos: se documenta en el docblock.

## Migration Plan

Sin migración de datos: las claves nuevas se resuelven por `wp_parse_args()` sobre `defaults()`; `llms_limit` queda inerte en la opción. Al actualizar, la primera petición a `/llms.txt`, a `/llms-<tipo>.txt`, a `/.well-known/api-catalog` o al OpenAPI genera el documento nuevo si el almacenamiento aún tiene el antiguo (los guardados de ajustes y el siguiente ciclo o "Regenerar ahora" los invalidan); para forzarlo tras desplegar, guardar la pestaña llms.txt o pulsar "Regenerar ahora". Cachés de CDN: purgar `/llms.txt`, `/.well-known/api-catalog`, `/auth.md`, `/wp-json/wpasl/v1/openapi` y el HTML de la portada. Rollback: desactivar el plugin o volver a la versión anterior; los archivos `llms-*.txt` del almacenamiento quedan huérfanos hasta la siguiente `Runner::clear()` o desinstalación (`Storage::delete_all()`).

## Open Questions

- Los nombres de clase de Yoast SEO, Rank Math, AIOSEO y SEOPress del caso 3 de `SitemapLocator` se verifican durante la implementación contra el código de cada plugin; si alguno no coincide, se ajusta o se elimina esa entrada sin cambiar specs ni tareas.
- Si el usuario prefiere que la vista previa por defecto sea 5 o 20 en lugar de 10, es un cambio de constante que no altera specs (que citan "por defecto 10") más que en ese número; se deja en 10 salvo indicación.
