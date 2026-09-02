## Why

La revisión de código de la versión 1.0.1 (`docs/reviews/2026-09-02-review.md`, en el worktree `review-v1.0.1`) encontró 20 hallazgos: tres desviaciones de spec visibles para agentes y administradores (documentos Markdown truncados por `<!--more-->`, URL Markdown rota para la portada estática, ~2 consultas SQL por ítem al listar elegibles), seis riesgos operativos (exposición del meta de exclusión por REST, diagnóstico síncrono que supera el límite de 100 s de Cloudflare, veredicto de robots.txt no contrastado, cola que ignora altas a mitad de ciclo, pérdida de actualizaciones en el estado de generación, doble sanitización del límite de `llms-full.txt`) y once mejoras de calidad, i18n y deriva documental. Todos se verificaron contra el código de `main` en `03f1a5c`. Se corrigen juntos para publicar 1.0.2 antes del envío a WordPress.org.

## What Changes

**Alta**

- El documento Markdown incluye el contenido completo aunque el post use `<!--more-->` o `<!--nextpage-->`, en cualquier ruta de generación (cron, WP-CLI, ruta `.md`, lazy fill).
- `markdown_url()` devuelve una URL válida para la portada estática (`page_on_front`) y para post types cuyo permalink lleva query string; `/.md` resuelve a la portada estática cuando existe.
- `Eligibility::query()` acota las consultas: precarga de cachés por lotes, re-filtrado en PHP solo cuando hay callbacks en `wpasl_is_eligible`, y `status()` con consulta de conteo.

**Media**

- El meta `_wpasl_exclude` deja de aparecer en la respuesta REST de lectura para usuarios sin permiso de edición.
- El diagnóstico se ejecuta por lotes en varias peticiones encadenadas (patrón redirect, sin JavaScript), cada una acotada por un presupuesto de tiempo que garantiza respuestas muy por debajo de los 100 s de Cloudflare. Las peticiones loopback no siguen redirecciones (3xx se reporta como advertencia) y limitan el tamaño de respuesta.
- El veredicto de robots.txt del informe se contrasta con el cuerpo de `/robots.txt` realmente servido.
- La cola de generación incorpora al inicio de cada ejecución los ítems elegibles nunca generados; los archivos de descubrimiento se regeneran también cuando el último ciclo completo es más antiguo que el intervalo; cambiar `post_types` reinicia la cola.
- El estado de generación se recarga y fusiona antes de guardarse, de modo que los lazy fills concurrentes no pierden su marca.
- `Settings::sanitize()` es idempotente: el límite de `llms-full.txt` se recibe en un campo en MB y se almacena en bytes sin doble conversión.

**Baja**

- Respuestas Markdown sin `X-Robots-Tag` y con `X-Content-Type-Options: nosniff` (también JSON); cabecera `Link` HTML enviada sin reemplazar otras `Link`.
- `absolutize()` usa el esquema de la URL base para `//host` y normaliza `./` y `../`.
- `wpasl_settings` y `wpasl_storage_token` pasan a autoload.
- Cadenas de interfaz del informe, `llms.txt` y WP-CLI traducibles.
- El almacenamiento siempre tiene un archivo sonda (`probe.txt`) para comprobar la exposición.
- URL del sitemap obtenida con `get_sitemap_url()`.
- El catálogo de crawlers rechaza tokens con `.`, `[` o `]`.
- `wp wpasl generate --post-type` falla con error si el tipo no está habilitado o falta la librería de conversión.
- Limpieza WPCS (alcance de `phpcs:disable`, arrays multilínea, sangría del textarea, guion largo, `esc_url_raw`).
- "Regenerar ahora" programa un evento único en lugar de mover el evento recurrente.
- Tests nuevos para cada hallazgo, filas de `docs/spec-coverage.md` actualizadas y release 1.0.2 (versión, changelog, release notes).

Sin cambios **BREAKING**: la forma de la opción `wpasl_state`, las URLs públicas y los filtros existentes se mantienen. El nombre del campo del formulario del límite de `llms-full.txt` cambia (solo afecta al formulario del plugin).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `markdown-delivery`: estructura del documento (contenido completo con `more`/`nextpage`, URLs con `../` y `//host`), URL alternativa para portada estática y permalinks con query string, cabeceras de la respuesta Markdown (sin `X-Robots-Tag`, con `nosniff`).
- `admin-settings`: exclusión por entrada no legible por REST sin permiso de edición; sanitización idempotente de ajustes; ausencia de llamadas externas incluye no seguir redirecciones.
- `agent-diagnostics`: ejecución por lotes acotada en tiempo; veredicto de robots.txt contrastado con el cuerpo servido; sonda de almacenamiento siempre disponible.
- `scheduled-generation`: altas a mitad de ciclo y cambio de `post_types` alimentan o reinician la cola; regeneración de archivos por antigüedad; estado de generación resistente a escrituras concurrentes; cota de consultas al listar elegibles; regeneración manual con evento único; errores explícitos en WP-CLI.
- `llms-txt`: enlace al sitemap coherente con la estructura de permalinks.
- `ai-crawler-robots`: tokens de crawler válidos en el catálogo.

## Impact

- **Código:** `src/Markdown/{DocumentBuilder,Delivery,LeagueConverter}.php`, `src/Content/Eligibility.php`, `src/Admin/ExcludeMetaBox.php`, `src/Admin/Tabs/{CrawlersTab,DiagnosticsTab,LlmsTab,ManifestsTab}.php`, `src/Diagnostics/{CrawlerProbe,DiagnosticsController,Report}.php`, `src/Generation/{Runner,State,Scheduler}.php`, `src/Settings.php`, `src/Lifecycle.php`, `src/Storage.php`, `src/Signals/ContentSignals.php`, `src/Llms/LlmsTxtBuilder.php`, `src/Manifest/ManifestRouter.php`, `src/CLI/Commands.php`, `src/Robots/Catalog.php`.
- **Tests:** nuevos casos en `tests/test-document-builder.php`, `test-delivery.php`, `test-eligibility.php`, `test-exclude-meta-box.php`, `test-diagnostics.php`, `test-runner.php`, `test-settings.php`, `test-crawler-robots.php`, `test-llms-txt.php`, `test-multisite.php`; nuevo `tests/test-state.php`.
- **Docs:** `docs/spec-coverage.md`, `readme.txt` (changelog 1.0.2), `README.md`, `languages/*.pot` (cadenas nuevas), `docs/reviews/2026-09-02-review.md` incorporado al repositorio.
- **Datos:** sin migración. La opción `wpasl_state` conserva su forma; `wpasl_settings` y `wpasl_storage_token` cambian a autoload en la siguiente escritura o en activación.
- **Dependencias:** ninguna nueva. Requiere WordPress 5.5+ para `get_sitemap_url()`; el plugin ya declara 7.0.
