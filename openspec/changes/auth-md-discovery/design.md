## Context

Ver `proposal.md` para la motivación y los deltas de `specs/` para el comportamiento exigido. Estado actual verificado en este worktree (`8568aeb`):

- Orden de los manejadores de `parse_request`. `Plugin::boot()` (`src/Plugin.php`) registra los servicios recorriendo el mapa en orden de inserción: `delivery` va antes que `llms_router` y `manifest_router`. Los tres cuelgan de `parse_request` con prioridad 1 (`Delivery::register()` en `src/Markdown/Delivery.php:82`, `LlmsTxtRouter::register()` en `src/Llms/LlmsTxtRouter.php:96`, `ManifestRouter::register()` en `src/Manifest/ManifestRouter.php:93`), así que a igual prioridad `Delivery::handle_md_suffix()` corre primero. Para `/auth.md` su expresión `#^(.*?)/?\.md$#` captura `auth`, `serve_md_suffix()` llama a `url_to_postid()`, obtiene 0 y ejecuta `force_404()` (`query_vars = ['error' => '404']`) además de añadir `redirect_canonical => __return_false`. Si existiera una página con slug `auth`, la serviría y terminaría la petición antes de que ningún router posterior viera la URL. Por eso no basta con añadir un router: el manejador del sufijo debe exceptuar la ruta.
- `LlmsTxtRouter` es el patrón más cercano a lo que hace falta: ruta raíz exacta (`ManifestRouter::exact_relative_path()` tras quitar el path base), precedencia de un archivo físico mediante `physical_path()` filtrable con `wpasl_physical_llms_path`, lectura del almacenamiento con generación bajo demanda (`document()`), invalidación en `update_option_`/`add_option_wpasl_settings`, cabeceras `text/markdown; charset=utf-8`, `X-Markdown-Tokens` (`ceil(strlen/4)`), `Cache-Control: public, max-age=` de `Delivery::max_age()` y `nosniff`, y disparo de `wpasl_before_serve` con un contexto de cadena antes de las cabeceras propias. `LlmsTab` muestra el aviso de archivo físico.
- `ContentSignals::send()` (`src/Signals/ContentSignals.php`) trata cualquier contexto de cadena como respuesta no HTML: envía `Content-Signal` y `Content-Usage`, retira `X-Robots-Tag` y solo añade `Link rel="api-catalog"` cuando el contexto es `markdown`. El docblock enumera los contextos `markdown`, `llms-txt` y `manifest`.
- `CapabilityRegistry::all()` (`src/Manifest/CapabilityRegistry.php`) ya declara documentos como capacidades (`site-index` para `llms.txt` con `responseType: text/markdown`, `openapi`), con `authentication: none` y URL absoluta; `ManifestBuilder::openapi()` solo incluye las capacidades cuya URL empieza por `rest_url()`, de modo que una capacidad con `home_url('/auth.md')` entra en `agent-skills.json` y no en OpenAPI, como `llms.txt`. `ManifestBuilder::api_catalog()` construye el linkset con `service-doc` para `llms.txt` y `agent-skills.json` y pasa por el filtro `wpasl_api_catalog`.
- `Settings` (`src/Settings.php`): `TAB_KEYS['manifests'] = ['manifest_enabled', 'contact_email']`, `defaults()` y `sanitize_key_value()` por clave; `llms_intro` se sanea con `sanitize_textarea_field()` sin límite. `Settings::contact_email()` cae al `admin_email`.
- `RobotsTxt::rules_block()` (`src/Robots/RobotsTxt.php`) termina con `# llms.txt: <url>` y es el mismo bloque que la pestaña Crawlers ofrece para un robots.txt físico. Tiene `Settings`.
- `CrawlerProbe::site_targets()` (`src/Diagnostics/CrawlerProbe.php:107`) enumera `robots`, `llms`, `skills`, `catalog`, `markdown_url` y `storage`; `probe_site()` las pide una vez con el user-agent del plugin y `Accept: text/markdown, application/json;q=0.9, text/html;q=0.8, */*;q=0.5`. `Report::site_checks()` (`src/Diagnostics/Report.php:544`) evalúa cada clave contra un `Content-Type` esperado: redirección → advertencia, código distinto de 200 → error, tipo inesperado → advertencia, si no → correcto. Con los manifiestos deshabilitados, `skills` y `catalog` aparecen hoy como error (404). `DiagnosticsTab::curl_commands()` lista `/robots.txt`, `/llms.txt`, `/agent-skills.json` y `/.well-known/api-catalog`; `render_checklist()` incluye la frase "Do not cache or transform robots.txt, llms.txt, agent-skills.json and /.well-known/api-catalog".
- `Runner::add_artifact_generator()` acepta cualquier `ArtifactGeneratorInterface` (`id()` y `generate( Storage )`); `regenerate_artifacts()` los recorre al final de cada ciclo. Los tests de `Runner` cuentan llamadas de un generador de prueba, no el número de generadores reales; `Test_CLI` puede listar los ids de artefactos en `wpasl status`.
- Skill `auth-md` de isitagentready.com (leído): exige `/auth.md` desde la raíz como Markdown con un H1 que contenga `auth.md`; sin metadatos de autorización, el documento debe identificar la audiencia, documentar endpoints de registro o aprovisionamiento, listar métodos soportados y explicar el uso de credenciales; advierte de no sondear `POST /agent/auth` en escaneos pasivos. El sitio no tiene servidor de autorización y no se fabrican `/.well-known/oauth-protected-resource` ni `oauth-authorization-server`.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, WPCS limpio, sin bump de versión, sin tocar `readme.txt`, `README.md` ni `languages/`; cadenas de interfaz en inglés con el text domain `wp-agent-support-layer`; ninguna petición saliente nueva.

## Goals / Non-Goals

**Goals:**

- Que `https://cognosonline.com/auth.md` responda 200 en Markdown con un H1 que contenga `auth.md` y un contenido veraz, de modo que el check `authMd` del escáner pase sin declarar nada que el sitio no ofrezca.
- Encajar la ruta nueva en el orden real de `parse_request` sin depender del orden de registro de servicios, y resolver la colisión con un contenido de slug `auth` de forma explícita: el archivo raíz gana.
- Reutilizar el patrón de `llms.txt` (archivo físico, almacenamiento, invalidación, cabeceras) para que el documento se comporte como el resto de archivos de descubrimiento y quede cubierto por los mismos tests.
- Que el documento sea descubrible desde el catálogo RFC 9727, `agent-skills.json` y `robots.txt`, y verificable desde la pestaña Diagnóstico.

**Non-Goals:**

- Publicar metadatos de autorización (`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`) o un bloque `agent_auth`: el sitio no tiene servidor de autorización.
- Documentar o habilitar contraseñas de aplicación, registro de agentes o cualquier endpoint autenticado.
- Añadir `auth.md` a la sección `## Optional` de `llms.txt`: el catálogo y `agent-skills.json` ya lo enlazan y `llms.txt` es un índice de contenido; quien lo quiera puede añadirlo con el filtro `wpasl_llms_optional_links` existente.
- Enviar `Link rel="api-catalog"` en la respuesta de `auth.md`: `llms.txt` tampoco lo hace y el propio documento enlaza el catálogo.
- Cambiar el comportamiento del diagnóstico cuando el administrador ha desactivado el documento (hoy los manifiestos deshabilitados también se reportan como error 404); se mantiene la coherencia con lo existente.
- Actualizar `readme.txt` y las traducciones (release).

## Decisions

### D1. Dos clases nuevas en `src/Manifest/`: `AuthMdBuilder` y `AuthMdRouter`, calcadas de `LlmsTxtBuilder`/`LlmsTxtRouter`

- `WPASL\Manifest\AuthMdBuilder` implementa `ArtifactGeneratorInterface` (`id()` = `auth-md`, `FILE = 'auth.md'`), recibe `Settings`, `CapabilityRegistry` y `ContentSignals`, expone `enabled()` (`manifest_enabled && auth_md_enabled`), `document()` (el Markdown) y `generate( Storage )` (escribe `auth.md` o lo borra cuando está desactivado). Va en `src/Manifest/` porque el interruptor vive en el grupo `manifests`, el contenido sale del registro de capacidades y el documento se anuncia en el catálogo y en `agent-skills.json`.
- `WPASL\Manifest\AuthMdRouter` recibe `Settings`, `Storage`, `AuthMdBuilder` y `Delivery` (para `max_age()`), cuelga `handle_request()` de `parse_request` con prioridad 1 y `invalidate()` de `update_option_`/`add_option_wpasl_settings`; `requested( $request_uri )` estático devuelve `true` solo para la ruta exacta `auth.md` tras quitar el path base con la misma lógica que `LlmsTxtRouter::requested_file()`; `physical_path()` estático filtrable con `wpasl_physical_auth_md_path` (`ABSPATH . 'auth.md'`) y `physical_file_exists()`; `document()` lee el almacenamiento y genera bajo demanda; `headers()` devuelve `Content-Type: text/markdown; charset=utf-8`, `X-Markdown-Tokens`, `Cache-Control: public, max-age=<Delivery::max_age()>` y `X-Content-Type-Options: nosniff`; `serve()` dispara `wpasl_before_serve` con contexto `auth-md`, envía `status_header( 200 )`, las cabeceras, el cuerpo y respeta `wpasl_terminate_after_serve`.
- `handle_request()`: si la ruta no es `auth.md`, no hace nada; si existe archivo físico, se aparta (el servidor web lo sirve); si `enabled()` es falso, fuerza `query_vars = ['error' => '404']`; si no, sirve.
- `Plugin::boot()` crea `auth_md` (builder) y `auth_md_router`, los añade al mapa de servicios después de `manifest_router` y registra el builder con `$runner->add_artifact_generator()`. `ContentSignals::send()` no necesita cambios de lógica (contexto de cadena = no HTML, sin `api-catalog`); solo se documenta el contexto `auth-md` en su docblock.

Alternativas descartadas: (a) ampliar `ManifestRouter`/`ManifestBuilder` con un cuarto archivo: obligaría a ramificar `headers()` y `serve()` por tipo de medio, a meter la precedencia de archivo físico y un segundo interruptor en un router pensado para JSON, y a que `ManifestBuilder::generate()` conociera un documento de texto; (b) servirlo desde `Delivery` como caso especial del sufijo `.md`: mezcla la entrega de contenido con un documento de sitio y deja el documento fuera de `Runner`, del almacenamiento y de la invalidación; (c) un namespace nuevo `src/AuthMd/`: no aporta nada frente a `src/Manifest/` y separa el interruptor de la clase que lo consume.

### D2. Ruta reservada en `Delivery`: el archivo raíz gana, y la URL alternativa de un contenido que colisione usa `?wpasl=md`

- `Delivery::handle_md_suffix()` devuelve sin hacer nada cuando `AuthMdRouter::requested( $request_uri )` es verdadero, antes de aplicar la expresión del sufijo. Así `/auth.md` nunca resuelve a un contenido, exista o no la publicación: con `auth.md` publicado la sirve `AuthMdRouter` (después, a la misma prioridad); desactivado, `AuthMdRouter` fuerza el 404. La exención es por ruta exacta: `/auth/.md` sigue resolviendo al contenido con slug `auth` porque no es la ruta reservada.
- Por qué gana el archivo raíz: `auth.md` es una convención de raíz de dominio como `robots.txt` o `llms.txt` (para los que el plugin ya cede ante un archivo físico y reserva la ruta virtual); un escáner o agente que pida `/auth.md` espera ese documento y no el Markdown de una página cualquiera; y el contenido con slug `auth` no pierde su Markdown, solo la forma con sufijo.
- `Delivery::needs_query_arg()` gana una condición: si la ruta relativa del enlace permanente, sin barras, es `auth` (es decir, `untrailingslashit( permalink ) . '.md'` daría la ruta reservada), devuelve `true`, y `markdown_url()` anuncia `<permalink>?wpasl=md`. Es el mismo mecanismo que ya usa la portada estática y los post types sin reescritura, y mantiene la invariante de `markdown-delivery` ("toda URL alternativa anunciada se sirve"), que `Test_Llms_Txt::test_every_emitted_markdown_url_is_servable` comprueba. La comparación se hace con `Delivery::relative_path()` para respetar instalaciones en subdirectorio.
- Con la exención en `Delivery`, el orden entre `Delivery` y `AuthMdRouter` deja de importar; no se cambia la prioridad de ningún hook.

Alternativas descartadas: (a) registrar `AuthMdRouter` con prioridad 0 y no tocar `Delivery`: funciona mientras nadie cambie el orden, pero deja a `Delivery` interpretando la ruta cuando el documento está desactivado (serviría la página de slug `auth` en `/auth.md` según el estado de una casilla, un comportamiento sorprendente); (b) dejar que el contenido con slug `auth` gane: rompe la convención de raíz y el check del escáner en cualquier sitio con una página `auth`; (c) rechazar el slug `auth` al guardar contenido: intrusivo y fuera del alcance del plugin.

### D3. Generación bajo demanda con caché en el almacenamiento, invalidada al guardar ajustes, regenerada al final del ciclo

El documento se guarda como `auth.md` en la raíz del almacenamiento (junto a `llms.txt` y los JSON). `AuthMdRouter::document()` lo lee y, si falta, lo genera en la petición; `AuthMdRouter::invalidate()` lo borra al guardar `wpasl_settings` (casilla, notas, correo de contacto, post types, señales); `Runner` lo regenera al final de cada ciclo mediante `AuthMdBuilder::generate()`, que además lo borra cuando está desactivado.

Por qué no generarlo en cada petición: el coste es pequeño, pero el resto de archivos de descubrimiento usan el almacenamiento, `X-Markdown-Tokens` y el cuerpo deben ser estables entre peticiones para las cachés, y el patrón mantiene el documento visible en `wpasl status` como artefacto. Trade-off asumido, idéntico al de los manifiestos: un cambio en el título del sitio o en la estructura de enlaces permanentes se refleja en el siguiente ciclo (o al pulsar "Regenerar ahora"), no en la siguiente petición.

### D4. Contenido: plantilla fija en inglés, valores reales, sin traducción de la plantilla

`AuthMdBuilder::document()` compone el Markdown con este esqueleto (las cadenas de la plantilla se escriben en inglés sin pasar por `__()`, porque el destinatario es un agente y el escáner, no el administrador; los nombres y descripciones de las capacidades vienen del registro y siguen siendo traducibles, ver Risks):

```
# {Site name} auth.md

> How automated agents may access {home_url}. Generated by WP Agent Support Layer from the site's current configuration.

## Audience

AI agents, LLM-based assistants and AI crawlers that read this site's public content.

## Registration and credential provisioning

This site does not offer agent registration or credential provisioning. There is no sign-up endpoint, no API key issuance and no authorization server. Do not attempt to register, and do not send credentials: every resource listed below is public.

## Supported access methods

Anonymous HTTP GET requests, without any credential. Send a descriptive User-Agent and honour robots.txt.

### Public endpoints

- **{name}** — GET {url | urlTemplate}: {description}   (una línea por capacidad del registro, excluida la propia `auth-md`)

### Discovery documents

- llms.txt: {home_url/llms.txt}
- API catalog (RFC 9727): {home_url/.well-known/api-catalog}
- Agent skills (JSON-LD): {home_url/agent-skills.json}
- OpenAPI 3.1: {rest_url wpasl/v1/openapi}
- Markdown version of any public item: append `.md` to its URL (o `?wpasl=md` con enlaces simples), or request it with `Accept: text/markdown`.

## Credential use

No credential is required or accepted for the resources above. Authenticated and write operations of the WordPress REST API are not offered to agents; they follow WordPress's standard authentication and permission rules and are outside the scope of this document.

## Usage policy

Content signals: search={yes|no}, ai-input={yes|no}, ai-train={yes|no}. See {home_url/robots.txt}.

## Contact

Technical contact: {contact_email}

## Notes                      (solo si hay notas)

{auth_md_notes tal cual}
```

- El H1 usa `DocumentBuilder::plain_text( get_bloginfo( 'name' ) )` como los manifiestos, de modo que contiene `auth.md` literalmente (requisito del skill).
- Las capacidades se toman de `CapabilityRegistry::all()` filtrando el id `auth-md` para no listarse a sí mismo; las URLs REST y las plantillas salen ya absolutas y correctas para enlaces bonitos o simples (`{+path}.md`, `?p={id}&wpasl=md`, `?rest_route=`).
- No aparecen `OAuth`, `agent_auth` ni "application password" en ninguna cadena; el test lo verifica con `stripos`.
- El resultado pasa por el filtro `wpasl_auth_md` (cadena Markdown) antes de guardarse, en paralelo a `wpasl_agent_skills` y `wpasl_api_catalog`.

Alternativa descartada: hacer la plantilla traducible con `__()`. El resto de documentos del plugin son JSON con textos traducibles, pero aquí la prosa completa se dirige a agentes y al escáner; una plantilla traducible produciría un `auth.md` en castellano en cognosonline.com y perdería palabras clave como "no registration" que un lector automático puede buscar. Cambiar de idioma con `switch_to_locale( 'en_US' )` durante la generación evitaría la mezcla con las descripciones traducidas de las capacidades, pero recarga textdomains en una petición de front-end; se descarta por ahora (ver Risks).

### D5. Ajustes: `auth_md_enabled` (bool, `true`) y `auth_md_notes` (texto, `''`, 4000 caracteres) en el grupo `manifests`

- `Settings::TAB_KEYS['manifests']` pasa a `['manifest_enabled', 'auth_md_enabled', 'contact_email', 'auth_md_notes']`; `defaults()` añade ambas claves; `sanitize_key_value()`: `auth_md_enabled` como los demás booleanos (`! empty()`), `auth_md_notes` con `sanitize_textarea_field()` seguido de `mb_substr( …, 0, 4000 )`. El límite (4000 caracteres, unos 1000 tokens) mantiene `auth.md` corto para agentes y acota el almacenamiento; truncar un valor ya truncado es idempotente, lo que cubre la doble sanitización de la Settings API.
- La casilla activa por defecto hace que las instalaciones existentes publiquen `auth.md` al actualizar sin tocar ajustes (`wp_parse_args` sobre `defaults()`), que es el resultado deseado para cognosonline.com. La dependencia de `manifest_enabled` se aplica en `AuthMdBuilder::enabled()`, no en la sanitización: la casilla conserva su valor si el administrador desactiva y reactiva los manifiestos.
- `ManifestsTab::render()`: la lista de enlaces gana `home_url( '/auth.md' )` con la descripción "Markdown statement of how agents may access this site: no registration, no credentials, public read-only endpoints."; el párrafo introductorio menciona `auth.md`; nueva fila con la casilla "Publish auth.md" (descripción "Requires the manifests above. When disabled, /auth.md responds 404."); nueva fila con el `textarea` "auth.md notes (Markdown)" (`rows="6"`, `class="large-text code"`, descripción "Optional. Appended to auth.md as a Notes section, e.g. rate limits or preferred endpoints. Do not paste credentials."); aviso `notice notice-warning inline` cuando `AuthMdRouter::physical_file_exists()`, con el mismo texto que `LlmsTab` adaptado a `auth.md`.

### D6. Descubrimiento: capacidad en el registro, `service-doc` en el catálogo y comentario en `robots.txt`

- `CapabilityRegistry::all()` añade, cuando `manifest_enabled && auth_md_enabled`, la capacidad `auth-md` ("Agent access documentation (auth.md)", "Markdown statement of how agents may access this site: no registration, no credentials, public read-only endpoints.", `home_url( '/auth.md' )`, sin parámetros, `text/markdown`) justo después de `site-index`. Justificación: el registro ya modela documentos como capacidades (`site-index`, `openapi`); así `agent-skills.json` lo anuncia sin tocar `ManifestBuilder::agent_skills()`, y OpenAPI lo ignora automáticamente porque la URL no empieza por `rest_url()`. El registro usa su `Settings` para la condición; no necesita `AuthMdBuilder`.
- `ManifestBuilder::api_catalog()` inserta `{ href: home_url('/auth.md'), type: 'text/markdown' }` en `service-doc` entre `llms.txt` y `agent-skills.json` bajo la misma condición. Justificación: RFC 9727 define `service-doc` como documentación del servicio para humanos y agentes; `auth.md` es exactamente eso, y el escáner y los agentes que ya conocen el catálogo llegan al documento sin otro salto.
- `RobotsTxt::rules_block()` añade `# auth.md: <home_url/auth.md>` tras `# llms.txt:` bajo la misma condición. Justificación: es el mismo mecanismo de comentario ya usado para `llms.txt`; no es directiva estándar y no afecta a ningún parser, pero es el primer archivo que lee cualquier crawler. `RobotsTxt` ya recibe `Settings`, así que la condición se evalúa allí (`manifest_enabled && auth_md_enabled`) sin nuevas dependencias; el bloque de la pestaña Crawlers cambia igual porque sale del mismo método.
- Descartado: `llms.txt` `## Optional` (ver Non-Goals) y `Link rel="alternate"` o `rel="describedby"` hacia `auth.md` en las respuestas HTML (más cabeceras por petición sin un consumidor conocido).

### D7. Diagnóstico: comprobación de sitio `auth` y comando `curl`, solo GET

- `CrawlerProbe::site_targets()` añade `'auth' => home_url( '/auth.md' )` tras `llms`; se sondea con el mismo user-agent del plugin y el mismo `Accept` que las demás comprobaciones de sitio (una petición `GET`, sin cuerpo). No se conserva el cuerpo (`KEEP_BODY` sin cambios).
- `Report::site_checks()` añade `'auth' => array( 'text/markdown', __( 'auth.md', … ) )` entre `llms` y `skills`; hereda la lógica existente (redirección → advertencia, código ≠ 200 → error, tipo distinto → advertencia). `Report::defaults()`/`normalize()` no necesitan cambios porque las comprobaciones de sitio se recorren por clave presente; un informe antiguo sin `auth` simplemente no la muestra.
- `DiagnosticsTab::curl_commands()` añade `/auth.md` a la lista de archivos de descubrimiento (después de `/llms.txt`); `render_checklist()` cambia la frase a "Do not cache or transform robots.txt, llms.txt, auth.md, agent-skills.json and /.well-known/api-catalog beyond their Cache-Control lifetime."
- `Test_Privacy::test_only_the_crawler_probe_calls_the_http_api` y `Test_Diagnostics::test_probe_only_contacts_its_own_host` siguen garantizando que no hay hosts externos; el test nuevo de "sin peticiones de registro" recorre las URLs pedidas por `probe->run()` y comprueba que todas son `GET` sobre el conjunto esperado.

### D8. Tests y cobertura

Nuevo `tests/test-auth-md.php` (`Test_Auth_Md`, misma estructura que `Test_Llms_Txt`: `Plugin::instance()->get( 'auth_md_router' )`, `wpasl_terminate_after_serve` a `false`, `go_to()` con `ob_start()`, cabeceras vía el registro de `Http`):

- `test_route_serves_auth_md_with_lazy_generation` (200, cabeceras, `Content-Signal`, sin `X-Robots-Tag`, segundo `go_to` no regenera).
- `test_route_is_404_when_disabled` y `test_route_is_404_when_manifests_disabled`.
- `test_physical_file_takes_precedence` (filtro `wpasl_physical_auth_md_path`, salida vacía, aviso en la pestaña).
- `test_non_canonical_root_paths_are_not_served` (`/auth.md/`, `//auth.md`).
- `test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg` (documento del sitio en `/auth.md`; con la casilla desactivada 404; `Accept` y `?wpasl=md` sirven la página; `markdown_url()` devuelve `?wpasl=md`; `/auth/.md` sirve la página) más `test_reserved_path_respects_base_path` (subdirectorio, con `home_url` filtrada como en `Test_Delivery::test_md_suffix_respects_base_path_segment_boundary`).
- `test_document_structure` (H1 `# <nombre> auth.md`, secciones, URLs reales de `rest_url( 'wp/v2/search' )`, `home_url( '/{+path}.md' )`, `llms.txt`, catálogo, `agent-skills.json`, OpenAPI, señales, contacto), `test_contact_email_falls_back_to_admin_email`, `test_admin_notes_section`, `test_document_never_mentions_oauth_or_application_passwords`, `test_plain_permalinks_describe_query_arg_and_rest_route`, `test_filter_changes_the_document`.
- `test_settings_change_invalidates_stored_file`, `test_builder_is_registered_as_artifact_generator_and_respects_the_toggle` (genera con la casilla activa, borra con ella desactivada).
- `test_capability_catalog_and_robots_announce_auth_md` y `test_nothing_announces_auth_md_when_disabled` (registro, `api_catalog()`, `rules_block()` y `robots_txt` real).
- `test_manifests_tab_renders_auth_md_fields` (URL, casilla, textarea).

En tests existentes: `Test_Settings::test_defaults` (claves nuevas), `test_sanitize_is_idempotent_for_every_field` (notas truncadas), un test de límite `test_auth_md_notes_are_truncated_to_4000_chars` y otro de conservación al guardar otra pestaña; `Test_Diagnostics`: `test_report_checks_auth_md` (200/404/tipo inesperado con el transporte simulado), `test_probe_only_sends_get_to_known_targets`, y ampliación de `test_tab_renders_checklist_and_curl_commands` (`curl -s '<home_url/auth.md>'`, "auth.md" en la lista). `Test_Agent_Manifest::test_api_catalog_route` y `test_default_capabilities_cover_rest_markdown_index_and_openapi` se amplían con `auth.md`. `docs/spec-coverage.md` gana una fila por escenario de los cinco deltas.

## Risks / Trade-offs

- [Capacidades traducidas dentro de un documento en inglés] → Los nombres y descripciones de las capacidades siguen la configuración de idioma del sitio (en cognosonline.com, castellano) mientras la plantilla es inglesa. Las URLs, el H1 y las declaraciones clave son independientes del idioma; se acepta la mezcla y se deja abierta la opción de `switch_to_locale( 'en_US' )` si el usuario la prefiere (ver Open Questions).
- [Sitio existente con una página de slug `auth`] → Su Markdown deja de estar en `/auth.md` al actualizar, porque la casilla está activa por defecto. Sigue disponible por `Accept` y `?wpasl=md`, y el enlace alternativo anunciado cambia a esa forma en el HTML, `Link` y `llms.txt`; el cambio se documenta en el `readme.txt` de la release.
- [Archivo físico presente pero la petición llega a WordPress] → Igual que con `llms.txt`: el router se aparta, `Delivery` ya no interpreta la ruta y el núcleo responde 404. El aviso de la pestaña explica que el archivo físico manda.
- [Diagnóstico en error cuando el administrador desactiva el documento a propósito] → Mismo comportamiento que hoy con los manifiestos deshabilitados; se mantiene por coherencia y se anota como mejora futura (ocultar comprobaciones de documentos desactivados).
- [Caché de página o CDN sirviendo `/auth.md` obsoleto] → `Cache-Control` con el intervalo de regeneración, como el resto de archivos; el checklist y los comandos `curl` incluyen `auth.md` para comprobarlo desde fuera.
- [Notas del administrador con contenido inapropiado (credenciales, HTML)] → `sanitize_textarea_field()` elimina etiquetas; la descripción del campo pide no pegar credenciales; el documento es público por definición.
- [Contador de artefactos] → `wpasl status` y cualquier test que enumere ids de artefactos verán `auth-md`; se revisa `Test_CLI` en la implementación.

## Migration Plan

Sin migración de datos: las claves nuevas se resuelven por `wp_parse_args()` sobre `defaults()`. Al actualizar, la primera petición a `/auth.md` genera el documento; el siguiente ciclo o "Regenerar ahora" lo deja en el almacenamiento. Desactivar la casilla y guardar devuelve `/auth.md` a 404 y borra el archivo en la siguiente regeneración; desactivar el plugin restaura el comportamiento anterior (404 por `Delivery`). Cache Enabler no cachea la ruta (no es HTML), así que no requiere purga; una caché de CDN puede necesitar purgar `/auth.md`, `/robots.txt`, `/agent-skills.json` y `/.well-known/api-catalog`.

## Open Questions

- ¿Debe la generación forzar `en_US` (`switch_to_locale`) para que las descripciones de las capacidades salgan en inglés en sitios en otro idioma? No cambia las specs ni las tareas (sería una línea en `AuthMdBuilder::document()`); se deja fuera salvo indicación del usuario.
- ¿Conviene añadir `auth.md` a la sección `## Optional` de `llms.txt` en una iteración posterior? Fuera de alcance aquí; el filtro `wpasl_llms_optional_links` lo permite sin cambios de código.
