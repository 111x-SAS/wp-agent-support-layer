## Why

La revisión de código de la versión 1.0.2 (`docs/reviews/2026-09-02-review-1.0.2.md`, verificada contra `main` en `225224f`) encontró 35 hallazgos: dos desviaciones de spec visibles para administradores y agentes (el formulario de "Regenerar ahora" queda anidado dentro del formulario de la Settings API, de modo que ni la acción manual ni la pestaña General funcionan en un WordPress limpio; la "Página de entradas" anuncia una URL `.md` que responde 404), diez riesgos operativos o de rendimiento (sin confirmación al guardar, borrado de ajustes con `_tab` desconocido, subsitios nuevos sin configurar, `--post-type` que procesa otros tipos, fallos de escritura reintentados en silencio, reescritura íntegra de `wpasl_state` por ítem, vista previa de robots.txt distinta del núcleo, `/llms.txt` que reconstruye `llms-full.txt` en línea, diagnóstico sin resultado por cada URL × user-agent, documento OpenAPI sin `Content-Signal`) y 23 mejoras de calidad, interoperabilidad, i18n y deriva documental. El origen fue un reporte real de un administrador: los botones de la pestaña General terminan en la pantalla "All Settings". Se corrigen los 35 juntos para publicar 1.0.3.

## What Changes

**Alta**

- El bloque de estado y las acciones "Regenerar ahora" / "Regenerar todo" se renderizan fuera del formulario de la Settings API, como formulario de primer nivel; la pestaña General vuelve a guardar sus ajustes y "Guardar cambios" queda dentro del formulario correcto. Se añade un punto de extensión para renderizar contenido después del formulario de una pestaña; `wpasl_general_tab_after` se mantiene pero deja de usarse para formularios.
- La página configurada como "Página de entradas" (`page_for_posts`) se sirve en Markdown por sufijo `.md`, por negociación `Accept` y por `?wpasl=md`, y su HTML anuncia la URL alternativa, igual que la portada estática.

**Media**

- Tras guardar cualquier pestaña, la página muestra la confirmación "Settings saved." de la Settings API.
- Guardar con `_tab` ausente o desconocido no vacía los ajustes: solo se sanitizan las claves presentes en la entrada, o se conserva el valor actual.
- Los sitios creados después de una activación en red se configuran al crearse (almacenamiento, opciones, evento recurrente) y el evento recurrente se autorrepara si falta.
- `wp wpasl generate --post-type=<tipo>` se limita a ese tipo también con `--batch` y cuando el tipo no tiene ítems elegibles; `--all --post-type=<tipo>` solo invalida ese tipo.
- Los fallos de escritura de documentos se registran en el estado (contador por ítem), se exponen en el panel y en `wp wpasl status`, se despriorizan tras varios intentos y disparan una acción y un `error_log()`.
- La opción `wpasl_state` deja de reescribirse en despublicaciones de post types no habilitados y en rellenos perezosos ejecutados dentro de una ejecución programada.
- La vista previa de robots.txt y el bloque para copiar reproducen exactamente lo que emite el núcleo (sin la rama `Disallow: /` para sitios no públicos; paths derivados de `admin_url()`).
- `/llms.txt` solo construye `llms.txt`. `llms-full.txt` se construye en la ejecución programada o por WP-CLI; cuando falta, `/llms-full.txt` responde 503 con `Retry-After` y programa un evento único que lo construya.
- El diagnóstico sondea la URL `.md` de muestra y `/robots.txt` con el user-agent de cada crawler, no solo la portada y la entrada de muestra.
- El documento OpenAPI servido por REST incluye `Content-Signal` y, cuando está habilitada, `Content-Usage`.

**Baja**

- Ciclo de vida: sin `flush_rewrite_rules()` en la desactivación; `get_sites()` paginado; la desinstalación borra también el evento único de "Regenerar ahora".
- `Http::send_header()` no registra cabeceras que no envió.
- `/.well-known/api-catalog` responde `Content-Type: application/linkset+json` sin `charset`; las respuestas HTML y Markdown anuncian `Link: <…/.well-known/api-catalog>; rel="api-catalog"` cuando el manifiesto está habilitado.
- `X-Robots-Tag: noai, noimageai` solo en respuestas HTML, no en robots.txt, feeds ni sitemaps.
- Los documentos raíz (`/llms.txt`, `/llms-full.txt`, `/agent-skills.json`, `/.well-known/api-catalog`) solo responden en su path exacto; variantes con barra final o doble responden 404 del núcleo.
- Comandos `curl` del diagnóstico escapados para la shell; informe almacenado normalizado contra una forma por defecto antes de renderizarlo.
- `llms.txt` omite `## Optional` cuando no hay enlaces; solo `page` usa el orden por menú (los CPT jerárquicos se ordenan por fecha, como el resto), y la spec lo dice así.
- Plantilla `read-markdown` con `{+path}`; `servers[0].url` de OpenAPI sin query string con enlaces permanentes simples.
- Sección "Filters" en `readme.txt` documentando todos los filtros de extensión.
- `relative_path()` respeta el límite de segmento del path base; `absolutize()` conserva la barra de la raíz con referencias `?…`.
- `set_base_url()` forma parte de `ConverterInterface`.
- `prune()` elimina ficheros `.tmp` huérfanos; el almacenamiento añade `web.config` e `index.php` en cada subdirectorio.
- Los globals de post (`$pages`, `$numpages`, `$multipage`, `$authordata`, `$id`) se restauran tras construir un documento.
- La spec de negociación por `Accept` describe el tratamiento de comodines (`text/*`, `*/*`) como HTML.
- `is_excluded()` y `excluded_ids()` usan el mismo predicado (cualquier valor distinto de `''` y `'0'`).
- Tests nuevos para cada hallazgo, smoke ampliado con el POST autenticado a `admin-post.php`, filas de `docs/spec-coverage.md` actualizadas y release 1.0.3 (versión, changelog, upgrade notice).

Sin cambios **BREAKING** en URLs públicas, opciones ni filtros existentes. `wpasl_state` incorpora una clave nueva (`failed`) compatible con el estado anterior. Un `Tab` de terceros que implemente `ConverterInterface` propio debe añadir `set_base_url()` (cambio de interfaz interna, documentado en el changelog).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `admin-settings`: la acción manual de regeneración se ofrece en un formulario de primer nivel, nunca dentro del formulario de la Settings API; "Guardar cambios" guarda la pestaña General; confirmación visible tras guardar cualquier pestaña; sanitización parcial con `_tab` ausente o desconocido; sitios creados tras la activación en red quedan configurados; desactivación sin vaciar reglas de reescritura; desinstalación que elimina también el evento único.
- `markdown-delivery`: la "Página de entradas" es servible y anunciada como cualquier contenido elegible; negociación por `Accept` con comodines tratados como HTML; sufijo `.md` respetando el límite de segmento del path base; restauración de globals tras generar.
- `scheduled-generation`: `--post-type` acotado en todas las combinaciones de WP-CLI; fallos de escritura visibles y despriorizados; estado de generación sin reescrituras innecesarias; almacenamiento protegido en Nginx/IIS y en subdirectorios; limpieza de temporales; evento único eliminado al desinstalar.
- `llms-txt`: `/llms.txt` no construye `llms-full.txt`; `llms-full.txt` ausente responde 503 con `Retry-After` y programa su construcción; `## Optional` solo con enlaces; orden por menú exclusivo de `page`; paths exactos para los documentos raíz.
- `ai-crawler-robots`: el bloque generado y la vista previa reproducen la salida del núcleo para `blog_public` 0 y 1.
- `agent-diagnostics`: resultado por crawler para la URL `.md` de muestra y `/robots.txt`; comandos `curl` escapados; informe almacenado tolerante a versiones anteriores.
- `content-signals`: `Content-Signal`/`Content-Usage` también en el documento OpenAPI; `X-Robots-Tag` con `noai` solo en HTML; cabecera `Link rel="api-catalog"` cuando el manifiesto está habilitado.
- `agent-manifest`: `Content-Type` exacto del catálogo; plantilla `read-markdown` con expansión reservada; `servers` válido con enlaces permanentes simples; paths exactos.

## Impact

- **Código:** `src/Admin/{Page,Tab,GenerationStatus}.php`, `src/Admin/Tabs/{GeneralTab,CrawlersTab,DiagnosticsTab}.php`, `src/Settings.php`, `src/Plugin.php`, `src/Lifecycle.php`, `src/Uninstaller.php`, `src/Http.php`, `src/Storage.php`, `src/CLI/Commands.php`, `src/Generation/{Runner,State,Scheduler}.php`, `src/Markdown/{Delivery,DocumentBuilder,LeagueConverter,ConverterInterface}.php`, `src/Content/Eligibility.php`, `src/Llms/{LlmsTxtRouter,LlmsTxtBuilder}.php`, `src/Manifest/{ManifestRouter,ManifestBuilder,CapabilityRegistry}.php`, `src/Robots/RobotsTxt.php`, `src/Signals/ContentSignals.php`, `src/Diagnostics/{CrawlerProbe,Report}.php`.
- **Tests:** casos nuevos en `tests/test-settings.php`, `test-generation-status.php`, `test-delivery.php`, `test-llms-txt.php`, `test-runner.php`, `test-cli.php`, `test-multisite.php`, `test-crawler-robots.php`, `test-diagnostics.php`, `test-agent-manifest.php`, `test-content-signals.php`, `test-storage.php`, `test-converter.php`, `test-document-builder.php`, `test-eligibility.php`, `test-uninstall.php`; `bin/smoke-docker.sh` con el POST autenticado a `admin-post.php`.
- **Docs:** `docs/spec-coverage.md`, `readme.txt` (changelog 1.0.3, upgrade notice, sección "Filters"), `README.md`, `languages/*.pot`, evidencia nueva en `docs/evidence/`.
- **Datos:** sin migración. `wpasl_state` gana la clave `failed` (vacía por defecto); los estados existentes siguen siendo válidos. El transient del informe de diagnóstico se normaliza al leerlo.
- **Dependencias:** ninguna nueva. Sin JavaScript nuevo en el admin.
