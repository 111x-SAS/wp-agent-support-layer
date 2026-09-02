## Context

Ver proposal.md (Why). Restricciones que condicionan el diseño:

- Publicación en WordPress.org: licencia GPL-2.0-or-later, prefijo único (`wpasl_`, namespace `WPASL`), sin llamadas externas sin consentimiento, sin escritura fuera de uploads, escapado y sanitización estrictos, dependencias de terceros con namespace prefijado, `readme.txt` con `Stable tag`.
- Compatibilidad: WordPress 7.0+ y PHP 7.4 a 8.3 (matriz CI aprobada). El código se limita a sintaxis PHP 7.4: sin `match`, `enum`, `readonly`, tipos de unión ni promoción de constructores.
- Decisión del usuario: la generación de Markdown ocurre por programación (WP-Cron) y nunca en `save_post`.
- Decisión del usuario: un solo menú bajo Herramientas con opciones simples; por defecto solo `post` y `page`.
- Hechos verificados: Cloudflare respeta una cabecera `content-signal` enviada por el origen; el ejemplo oficial coloca `Content-Signal:` dentro del grupo `User-agent: *`; el borrador IETF `draft-ietf-aipref-attach` define la cabecera `Content-Usage` y una regla homónima en robots.txt con el vocabulario `train-ai` y `search`; llms.txt exige un H1, un blockquote y secciones H2 con listas de enlaces.

## Goals / Non-Goals

**Goals:**
- Un plugin autocontenido que implemente las acciones 1, 2, 3, 5 y 6 del documento y dé soporte de solo lectura a la 4 y la 8.
- Cero coste en la ruta de escritura de contenido: guardar una entrada no dispara conversiones.
- Respuestas idempotentes y cacheables por proxies y CDN.
- Sin JavaScript compilado ni build de front-end: todo el admin se resuelve con la Settings API y HTML del núcleo.
- Testeable con la suite oficial de WordPress en todas las versiones de la matriz.

**Non-Goals:**
- Bloqueo activo de crawlers (403 por user-agent), integración con la API de Cloudflare, scoring de agent-readiness, edición del robots.txt físico.
- Soporte de contenido privado, protegido con contraseña o no publicado.
- Integración con el editor de bloques mediante paneles React (se usa un meta box clásico, que Gutenberg también renderiza).

## Decisions

**D1. Arquitectura: PSR-4 bajo `WPASL\`, un solo punto de entrada.**
`wp-agent-support-layer.php` define constantes, comprueba requisitos y arranca `WPASL\Plugin`. Módulos por capacidad (`Markdown`, `Generation`, `Signals`, `Robots`, `LlmsTxt`, `Manifest`, `Diagnostics`, `Admin`) registrados por un pequeño contenedor de servicios. Autoload propio en producción (sin depender del autoloader de Composer) para reducir superficie; Composer se usa en desarrollo y para empaquetar. Alternativa descartada: arquitectura procedural con funciones globales, más difícil de testear.

**D2. Conversión HTML a Markdown con `league/html-to-markdown` prefijada.**
Se empaqueta en `vendor-prefixed/` con Strauss bajo `WPASL\Vendor\League\HTMLToMarkdown` en el paso de build (`composer run build`). Se envuelve tras una interfaz `MarkdownConverterInterface` para poder sustituirla. Alternativas: conversor propio (menor calidad, coste de mantenimiento) o no prefijar (riesgo de conflicto de clases con otros plugins, motivo habitual de rechazo en WordPress.org).

**D3. Almacenamiento de artefactos generados en uploads con acceso directo bloqueado.**
Directorio `wp-content/uploads/wp-agent-support-layer/<hash-de-sitio>/` con `index.php` vacío y `.htaccess` `Deny from all`, más aviso en documentación para nginx. Estructura: `md/<post_type>/<ID>.md`, `llms.txt`, `llms-full.txt`, `agent-skills.json`, `openapi.json`, `api-catalog.json` y `state.json` (cursor del lote y estadísticas). Los archivos nunca se sirven directamente: siempre pasa por PHP, que re-verifica la elegibilidad del contenido en cada petición. Alternativa descartada: tabla propia en la base de datos (más carga en MySQL para documentos largos y peor alineación con la petición "archivos como los sitemaps").

**D4. Elegibilidad evaluada en tiempo de petición, no solo en generación.**
Servir un Markdown requiere que el post exista, esté `publish`, sea de un post type habilitado y público, no tenga contraseña y no esté marcado como excluido. Esto garantiza que un contenido despublicado tras la última generación no se filtre aunque su archivo siga en disco. La generación programada elimina los archivos huérfanos; además, el cambio de estado a no público elimina el archivo de inmediato. Esta última es la única reacción a eventos de edición y no realiza conversiones.

**D5. Doble mecanismo de acceso: `Accept: text/markdown` y sufijo `.md`.**
La negociación por cabecera es lo que envían Claude Code, Cloudflare y otros agentes; el sufijo `.md` es cacheable de forma independiente y descubrible por humanos. Resolución del `.md`: en `parse_request` se detecta el sufijo, se resuelve la URL sin sufijo con `url_to_postid()` y se sirve el documento, evitando reglas de reescritura por post type. La negociación por cabecera se atiende en `template_redirect` sobre `is_singular()`. Ambas rutas envían `Vary: Accept`. Con enlaces permanentes "simples" (`?p=ID`) solo se ofrece la negociación por cabecera y el parámetro `?wpasl=md`.

**D6. Formato del documento Markdown.**
Front matter YAML (`title`, `url`, `type`, `date`, `modified`, `author`, `lang`, `description`, `categories`, `tags`), luego `# Título` y el cuerpo convertido a partir de `apply_filters('the_content', ...)` con bloques y shortcodes ya renderizados. Se eliminan `script`, `style`, `form`, `iframe`, `noscript` y comentarios HTML; los enlaces e imágenes se convierten a URLs absolutas. Cabeceras de respuesta: `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens` (estimación `ceil(bytes/4)`), `Link: <canonical>; rel="canonical"`, `Cache-Control: public, max-age=<intervalo de regeneración>`.

**D7. Generación por lotes con WP-Cron y ejecución acotada.**
Un único evento recurrente (`wpasl_generate`) con intervalo configurable (`hourly`, `twicedaily`, `daily` por defecto, `weekly`). Cada ejecución procesa hasta N ítems (por defecto 50) y se detiene al agotar N o un presupuesto de tiempo (20 s), guardando el cursor en `state.json`; la siguiente ejecución continúa. Al completar el ciclo se regeneran llms.txt, llms-full.txt y manifiestos. "Regenerar ahora" programa un evento único inmediato y dispara `spawn_cron()`; nunca ejecuta la generación dentro de la petición del admin. Si un documento no existe cuando se pide, se genera bajo demanda una única vez y se almacena (relleno perezoso), que es el mismo comportamiento de los plugins de sitemaps. Alternativa descartada: Action Scheduler (dependencia pesada para un solo job).

**D8. robots.txt: solo el virtual de WordPress.**
Se usa el filtro `robots_txt`. La línea `Content-Signal:` se inserta dentro del grupo `User-agent: *` que genera el núcleo (siguiendo el ejemplo oficial de Cloudflare) y las reglas por crawler se añaden como grupos propios al final, seguidos de un comentario que apunta a `/llms.txt`. Si existe un `robots.txt` físico, WordPress no sirve el virtual: el plugin lo detecta, muestra un aviso en el admin y ofrece el bloque generado para copiar. Alternativa descartada: escribir el archivo físico (fuera de uploads, prohibido en WordPress.org).

**D9. Lista de crawlers de IA mantenida en el plugin y filtrable.**
Un catálogo PHP (`WPASL\Robots\Catalog`) con user-agent, proveedor, propósito (`training`, `search`, `agent`) y URL de documentación. Grupos iniciales: entrenamiento (GPTBot, CCBot, ClaudeBot, anthropic-ai, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, Amazonbot, cohere-ai, Diffbot, omgili, PetalBot), búsqueda (OAI-SearchBot, PerplexityBot, Claude-SearchBot, DuckAssistBot, YouBot), agentes bajo demanda (ChatGPT-User, Claude-User, Perplexity-User, MistralAI-User, Meta-ExternalFetcher). Copilot y Gemini se documentan como no separables de Bingbot y Google-Extended respectivamente. El filtro `wpasl_crawler_catalog` permite ampliar la lista. La decisión por defecto de cada grupo deriva de los Content Signals: si `ai-train=no`, el grupo de entrenamiento se bloquea; los demás se permiten.

**D10. Señales en cabeceras: `Content-Signal` estable, `Content-Usage` marcada como experimental.**
`Content-Signal` se emite siempre en respuestas de front-end porque Cloudflare la reconoce. `Content-Usage` (IETF AIPREF) se emite con `train-ai` y `search` (el vocabulario del borrador no define `ai-input`) y se puede desactivar desde ajustes por si el borrador cambia. `X-Robots-Tag: noai, noimageai` y la meta robots equivalente solo cuando `ai-train=no`. Las cabeceras se envían con `send_headers` y en cada respuesta generada por el plugin; nunca en el admin.

**D11. Manifiestos sin capacidades ficticias.**
`agent-skills.json` declara solo lo que el sitio realmente expone sin autenticación: búsqueda (`/wp-json/wp/v2/search`), listado y lectura por post type habilitado, lectura en Markdown (patrón `.md`), índice (`/llms.txt`) y la propia documentación OpenAPI. El JSON-LD usa `@context` con schema.org y un vocabulario propio bajo la URL del plugin; los desarrolladores pueden añadir capacidades con el filtro `wpasl_agent_capabilities`, sin UI en v1. El documento OpenAPI 3.1 se deriva de `rest_get_server()->get_routes()` limitado a métodos GET públicos de los post types habilitados, búsqueda y rutas `wpasl/v1`. `/.well-known/api-catalog` (RFC 9727) enlaza al OpenAPI con `application/linkset+json`.

**D12. Diagnóstico mediante peticiones loopback con la API HTTP de WordPress.**
El panel lanza, bajo nonce y `manage_options`, peticiones a la home, a una entrada elegible de muestra, a `/robots.txt`, `/llms.txt`, `/agent-skills.json` y a una URL `.md`, con los user-agents del catálogo y con `Accept: text/markdown` y `text/html`. Reporta código, `Content-Type`, presencia de `Content-Signal`, `X-Robots-Tag`, `Link rel=alternate`, veredicto robots para ese user-agent y detección de CDN (`cf-ray`, `server`, `x-cache`). Resultado en un transient de 1 hora. Si detecta Cloudflare y respuestas ya convertidas en el borde, advierte del posible solapamiento con "Markdown for Agents". La lista de verificación de WAF es contenido estático con enlaces. También muestra comandos `curl` equivalentes para verificación externa.

**D13. Toolchain y CI.**
`wp scaffold plugin-tests --ci=github` como base; PHPUnit 9.6 con `yoast/phpunit-polyfills` ^4; `wp-coding-standards/wpcs` ^3; `phpcompatibility/phpcompatibility-wp` con `testVersion 7.4-`; `brianhenryie/strauss` para prefijar. Workflow `ci.yml`: job `test` con matriz PHP 7.4/8.0/8.2/8.3 sobre WordPress `latest`, servicio MySQL 8.0 (con `--default-authentication-plugin=mysql_native_password` para PHP 7.4), `bin/install-wp-tests.sh`, `vendor/bin/phpunit`; job `lint` con PHPCS y PHPCompatibility; job `plugin-check` con la acción oficial de Plugin Check. Workflow `release.yml` (al publicar un tag) que construye el zip con `vendor-prefixed/` y, cuando WordPress.org apruebe el slug, despliega a SVN con `10up/action-wordpress-plugin-deploy`.

**D14. Idioma.**
Cadenas fuente en inglés (requisito práctico de WordPress.org y GlotPress) con text domain `wp-agent-support-layer`, `.pot` generado y traducción `es_ES` incluida en `languages/`. Documentación de usuario en el `readme.txt` en inglés; README de GitHub en inglés con sección en español.

## Risks / Trade-offs

- [Cachés de página o CDN que ignoran `Vary: Accept` sirven HTML a agentes o Markdown a humanos] → la URL `.md` es el mecanismo principal recomendado; el diagnóstico compara ambas rutas y advierte; documentación de exclusiones para los cachés más comunes.
- [Cloudflare "Markdown for Agents" activo a la vez que el plugin] → el borde puede convertir HTML que el origen ya devolvería en Markdown; el diagnóstico lo detecta y la documentación recomienda dejar uno de los dos.
- [Contenido despublicado permanece en disco hasta la siguiente ejecución] → verificación de elegibilidad en cada petición y borrado inmediato al cambiar a estado no público; los archivos no son accesibles directamente.
- [Servidores nginx no honran `.htaccess`] → el diagnóstico prueba el acceso directo al directorio y avisa; se documenta la regla `location` recomendada.
- [WP-Cron desactivado o sin tráfico] → el panel muestra "última ejecución" y "siguiente ejecución"; se documenta el cron del sistema y existe el comando WP-CLI.
- [Conversión de bloques complejos (galerías, embeds, tablas anidadas) con pérdida de fidelidad] → reglas de limpieza previas y tests de instantánea sobre contenido de muestra; el filtro `wpasl_markdown_document` permite ajustar la salida.
- [El borrador IETF cambia la sintaxis de `Content-Usage`] → cabecera desactivable y marcada como experimental.
- [Lista de crawlers desactualizada] → catálogo filtrable y actualizado en cada release menor.
- [Coste de generación en sitios grandes] → lotes acotados por cantidad y tiempo; `llms-full.txt` desactivado por defecto y con límite de tamaño.
- [Espacio en disco con `llms-full.txt`] → límite configurable (por defecto 5 MB) y truncado con aviso en el propio archivo.

## Migration Plan

Instalación nueva; no hay migración de datos. Activación: comprobar requisitos, crear el directorio de almacenamiento con sus protecciones, registrar opciones por defecto, programar el evento cron, vaciar reglas de reescritura. Desactivación: desprogramar el cron. Desinstalación: borrar opciones, el post meta de exclusión, los transients y el directorio de almacenamiento. Rollback: desactivar el plugin devuelve el sitio al comportamiento previo porque todo es declarativo y se sirve desde PHP.

## Open Questions

- Lista definitiva de user-agents en el catálogo al momento del primer release (se actualizará con las fuentes oficiales de cada proveedor durante la implementación).
- Si el slug `wp-agent-support-layer` queda disponible en WordPress.org; de no estarlo, el nombre visible se mantiene y solo cambia el slug.
