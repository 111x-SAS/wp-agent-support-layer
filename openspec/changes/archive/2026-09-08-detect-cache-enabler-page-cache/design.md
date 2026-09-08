## Context

Ver `proposal.md` para la motivación y `specs/agent-diagnostics/spec.md` para el comportamiento exigido. Estado actual verificado en este worktree (`5feb6a3`):

- `CrawlerProbe::HEADERS` (`src/Diagnostics/CrawlerProbe.php:42`) enumera las cabeceras que `fetch()` conserva de cada respuesta; `x-cache-handler` no está, así que hoy el informe no puede ver la firma de Cache Enabler. `probe_crawler()` sondea `post_html` antes que `post_markdown` con `Accept: text/markdown, text/html;q=0.9, */*;q=0.8`: en un sitio con Cache Enabler la primera petición rellena la caché de la URL y la segunda recibe ese HTML, de modo que la simulación reproduce el fallo de forma determinista.
- `Report::infrastructure()` (`src/Diagnostics/Report.php:186-232`) produce `cdn`, `server`, `edge_markdown` y `storage_exposed`; `defaults()` y `normalize()` rellenan las claves que un informe antiguo no trae. `crawler_checks()` emite los textos genéricos "Content-Signal header missing on the HTML response (a cache or proxy may strip it)", "X-Robots-Tag noai missing on the HTML response" y "HTML returned for Accept: text/markdown. A page cache or CDN that ignores "Vary: Accept" is probably interfering". La comprobación `alternate_link` acepta el `<link>` del cuerpo (`has_md_link`), que sí sobrevive en el HTML cacheado, así que con Cache Enabler sigue en verde.
- `DiagnosticsTab` recibe `CrawlerProbe` y `Page`; la construye `DiagnosticsController::register_tab()` y `Plugin::boot()` cablea el controlador. `SignalsTab` recibe solo `Settings` y la construye `ContentSignals::register_tab()`.
- `ContentSignals::headers( $html )` devuelve `Content-Signal`, `Content-Usage` (si está activada) y `X-Robots-Tag: noai, noimageai` (si `ai-train=no`); la cabecera `Link` del catálogo se construye en línea dentro de `send()` (`src/Signals/ContentSignals.php:156-159`) cuando `manifest_enabled` está activo.
- `Test_Diagnostics` simula el transporte con `pre_http_request` (`fake_http`, respuestas por `URL|md|html`), limita a dos crawlers y obtiene `probe`, `diagnostics` y `page` de `Plugin::instance()`. En el entorno de tests Cache Enabler no está instalado; una constante o clase de otro plugin no se puede definir y luego retirar entre tests.
- Cache Enabler, verificado en el código fuente publicado de `inc/cache_enabler_engine.class.php` (rama principal; se asume idéntico a 1.8.16 en estos puntos): `deliver_cache()` envía `X-Cache-Handler: cache-enabler-engine`, 304 condicional y `Content-Encoding`, hace `readfile()` y `exit`; no dispara ninguna acción y los únicos filtros del motor son `cache_enabler_bypass_cache` y `cache_enabler_exclude_search`. `should_start()` no arranca en URIs con `.txt`, `.xml`, `.xsl` o `.ico`; `is_cacheable()` exige un `<!DOCTYPE html>`; `is_excluded()` considera excluida una petición cuyo `Accept` no contiene `text/html`. `advanced-cache.php` se carga en `wp-settings.php` antes que los mu-plugins, por lo que ningún hook de este plugin puede intervenir en la entrega.
- Apache `mod_headers` (documentación 2.4): `Header [onsuccess|always] set|setifempty|merge|unset … [expr=…]`; `mod_proxy_fcgi` (PHP-FPM) deja las cabeceras del backend en la tabla `always` y mod_php en `onsuccess`, de modo que un `Header set` en la tabla equivocada duplica la cabecera; el propio manual recomienda `Header onsuccess unset X` + `Header always set X "…"`. `%{CONTENT_TYPE}` en `expr=` es el tipo de la respuesta (el manual lo usa en `"expr=-z %{CONTENT_TYPE}"`). `setifempty` existe desde 2.4.7.

OpenLiteSpeed (el servidor del sitio de referencia, bajo CyberPanel), verificado en fuentes oficiales:

- Texto de ayuda de "Header Operations" (`extraHeaders`, repositorio `litespeedtech/gdoc`, `scripts/text/common/Virtual_Hosts_Context.hdoc`, espacio de nombres `LEO`, es decir, LSWS Enterprise y OpenLiteSpeed): "Specifies additional response/request headers to be added. Multiple header directives can be added with one directive per line. "NONE" can be used to disable parent header inheritance. If no directive is provided 'Header' is assumed." Sintaxis: `[Header]|RequestHeader [condition] set|append|merge|add|unset header [value] [early|env=[!]variable]`. Ejemplos: `set Cache-control no-cache`, `append Cache-control no-store`, `Header set My-header cust_header_val`. Nota: "Syntax and usage are similar to Apache's mod_headers directives for supported operations" y compatibilidad con la sintaxis antigua `header_name: value1,value2`.
- Parser (`src/http/httpheader.cpp`, `HttpHeaderOps::parseOp`): reconoce `add`, `set`, `merge`, `unset`, `append` y `replace` (alias de `set`); una línea sin operador se trata como `add`; acepta los prefijos `always`/`onsuccess` y `env=`; no existe `setifempty` ni `expr=`.
- Nivel: el ítem aparece en el nivel Context (`Virtual_Hosts_Context.hdoc`) y, solo en Enterprise, en el nivel Server (`Server_General.hdoc`, espacio `E`); no aparece en `Virtual_Hosts_General.hdoc`. En OpenLiteSpeed se configura por tanto en un contexto del host virtual: WebAdmin > Virtual Hosts > [sitio] > Context > contexto `/` > Header Operations (docs.openlitespeed.org, "Custom Headers" y "Security Headers"), con "graceful restart" después; editando el fichero, dentro de `context / { … }` y `systemctl restart lsws`.
- CyberPanel (base de conocimiento oficial, "How To Set HSTS Header … Using CyberPanel (OpenLiteSpeed)"): Websites > List Websites > Manage > vHost Conf, añadiendo al final un bloque `context / { allowBrowse 1  extraHeaders <nombre> "<valor>"  rewrite { }  addDefaultCharset off  phpIniOverride { } }`. La forma multilínea es `extraHeaders <<<END_extraHeaders … END_extraHeaders` (docs.openlitespeed.org, plantillas de DirectAdmin).
- Condiciones: la única condición de la sintaxis es `env=`; no hay condición por tipo de contenido. En el foro oficial (forum.openlitespeed.org, "Conditional headers") el equipo de LiteSpeed remite a Header Operations por contexto (directorio), y los usuarios confirman que `<FilesMatch>`/`<IfModule>` en `.htaccess` se ignoran en OpenLiteSpeed aunque funcionen en LSWS Enterprise. Un contexto `/` cubre todas las URLs, incluidas `.md`, `robots.txt` y `llms.txt`.
- Duplicados: la incidencia 85 del repositorio de OpenLiteSpeed muestra cabeceras de `extraHeaders` repetidas en respuestas PHP cacheadas por el módulo de caché; no se ha verificado si `set` reemplaza una cabecera que ya envió PHP.

Un punto del encargo no coincide con lo verificado y se ajusta aquí: la negociación por `Accept` no falla siempre. Cache Enabler solo entrega desde caché cuando `Accept` contiene `text/html`; `curl -H 'Accept: text/markdown'` a secas sortea la caché y recibe Markdown, mientras que un agente que anuncia `text/markdown, text/html;q=0.9` (como la simulación y como la mayoría de agentes) recibe el HTML cacheado. El texto del aviso lo dice así.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, WPCS limpio, sin opción nueva, sin escritura en `.htaccess`, sin bump de versión, sin tocar `readme.txt`, `README.md` ni `languages/`; cadenas nuevas en inglés con el text domain `wp-agent-support-layer`.

## Goals / Non-Goals

**Goals:**

- Que el administrador de un sitio con Cache Enabler entienda, desde la pestaña Diagnóstico y sin ejecutar nada, por qué las cabeceras "aparecen una vez y desaparecen", qué no se ve afectado y dónde está el remedio.
- Que el informe de la simulación nombre Cache Enabler en el hallazgo de infraestructura y en las comprobaciones por crawler afectadas, tanto cuando la respuesta trae `X-Cache-Handler` como cuando el servidor web sirve los ficheros sin PHP.
- Fragmentos correctos para el sitio real en Apache/LiteSpeed Enterprise, nginx y OpenLiteSpeed: mismos valores que envía el plugin, limitados a respuestas HTML donde el servidor lo permite, sin duplicar `Content-Signal`/`Content-Usage` en las respuestas que pasan por PHP (Apache), y sin cadenas de ejemplo.
- Detección local sustituible por filtro para que los tests y terceros puedan simularla.

**Non-Goals:**

- Escribir o modificar `.htaccess`, `nginx.conf` o reglas de CDN.
- Detectar otras cachés de página por su plugin activo (WP Super Cache, W3TC, LiteSpeed Cache…); solo se muestra el valor bruto de `X-Cache-Handler` cuando no es Cache Enabler.
- Generar configuración para Varnish, IIS u otros servidores distintos de Apache/LiteSpeed Enterprise, nginx y OpenLiteSpeed.
- Purgar la caché de Cache Enabler o cambiar sus ajustes.
- Documentar el filtro nuevo en `readme.txt` (release).

## Decisions

### D1. Clase `WPASL\Diagnostics\PageCache` con detección estática filtrable y fragmentos por instancia

Nueva clase `src/Diagnostics/PageCache.php`:

- `PageCache::detect()` (estática) devuelve `array{id:string, name:string, version:string}` o `null`. Detección: `class_exists( 'Cache_Enabler', false ) || defined( 'CACHE_ENABLER_VERSION' )` → `array( 'id' => 'cache-enabler', 'name' => 'Cache Enabler', 'version' => CACHE_ENABLER_VERSION o '' )`. El resultado pasa por el filtro `wpasl_diagnostics_page_cache` (documentado con docblock como el resto de filtros; `readme.txt` se actualiza en la release). Devolver `null` desde el filtro oculta el aviso; devolver el array lo fuerza. Es la única forma de simular el plugin en PHPUnit, donde una constante no puede indefinirse.
- `PageCache::label( $handler )` (estática) traduce el valor de `X-Cache-Handler`: `cache-enabler-engine` → "Cache Enabler"; cualquier otro valor se devuelve tal cual. La usa `Report`, que trabaja sobre resultados crudos y no necesita una instancia.
- Instancia con `Settings` y `ContentSignals`: `headers()` devuelve el conjunto a replicar (ver D3), `htaccess_snippet()`, `nginx_snippet()`, `openlitespeed_snippet()` y `cloudflare_note()` generan los textos.

Alternativas descartadas: (a) meter la detección en `Report` — `Report` es puro sobre los resultados crudos y así debe seguir (tests sin transporte); (b) inyectar `PageCache` en `SignalsTab` — obliga a que `ContentSignals` conozca `PageCache`, que a su vez depende de `ContentSignals`; con `detect()` estática la pestaña Señales no necesita cableado nuevo; (c) `is_plugin_active( 'cache-enabler/cache-enabler.php' )` — requiere cargar `wp-admin/includes/plugin.php` y falla si el directorio se renombró; la clase y la constante son la identidad real del plugin.

### D2. La ejecución registra la detección local y el informe combina ambas fuentes

`CrawlerProbe::begin()` añade `'page_cache' => PageCache::detect()` al estado de la ejecución (se guarda en el transient del progreso y llega a `Report::build()` como `$raw['page_cache']`). `CrawlerProbe::HEADERS` incorpora `x-cache-handler`.

`Report::infrastructure()` gana dos claves, presentes también en `defaults()` para que `normalize()` cubra informes antiguos:

- `page_cache` (string): etiqueta de la caché de página. "Cache Enabler" si `$raw['page_cache']['id']` es `cache-enabler` o alguna respuesta trae `x-cache-handler: cache-enabler-engine`; si no, `PageCache::label()` del primer `x-cache-handler` encontrado; `''` si nada.
- `page_cache_served` (bool): alguna respuesta sondeada trajo `x-cache-handler`.

`crawler_checks()` recibe `$infra` (ya lo hace) y, cuando `$infra['page_cache']` es "Cache Enabler", cambia los tres mensajes afectados por variantes que lo nombran: para `content_signal` y `x_robots_tag`, "… missing on the HTML response. Cache Enabler served it from its page cache, which drops the headers this plugin sends; see the page cache notice." cuando la respuesta concreta trae `x-cache-handler`, y "… Cache Enabler is active and its page cache drops the headers this plugin sends; …" cuando no la trae (modo servidor web); para `negotiation`, "HTML returned for Accept: text/markdown: Cache Enabler served the cached HTML because its cache key ignores Accept; agents can still use the .md URL." Con otra caché o sin caché se conservan los textos actuales. Las cadenas exactas se fijan en la implementación; lo normativo es que nombren Cache Enabler.

`DiagnosticsTab::render_report()` añade a la lista de infraestructura una línea "Page cache: %s" cuando `page_cache` no está vacío, con el sufijo "(served at least one probed response)" cuando `page_cache_served` es verdadero.

Alternativa descartada: derivar el hallazgo solo de `x-cache-handler`. En el modo en que el servidor web sirve los ficheros de caché la cabecera no existe y el informe seguiría hablando de "una caché o proxy" en un sitio donde el plugin sabe perfectamente qué caché hay.

### D3. Fragmentos con los valores del plugin: limitados a HTML en Apache y nginx, sin `X-Robots-Tag` en OpenLiteSpeed

`PageCache::headers()` devuelve `ContentSignals::headers( true )` (`Content-Signal`, `Content-Usage` si está activada, `X-Robots-Tag` si `ai-train=no`) más `Link` con el valor del catálogo cuando `manifest_enabled` está activo. Para no duplicar la cadena del catálogo, `ContentSignals` gana `api_catalog_link()` (público) que devuelve `'<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"'` y `send()` pasa a usarlo; lo que se envía no cambia (los tests de `Test_Content_Signals` y `Test_Delivery` lo garantizan). `Link rel="alternate" type="text/markdown"` no se replica: su valor depende de cada URL y no puede fijarse estáticamente; el aviso lo dice y recuerda que el `<link>` del `<head>` sí sobrevive en el HTML cacheado.

Bloque `.htaccess` (Apache 2.4.7+ y LiteSpeed Enterprise), una condición común `"expr=%{CONTENT_TYPE} =~ m#^text/html#"` en todas las líneas para que `X-Robots-Tag: noai` nunca llegue a Markdown ni a `robots.txt` (la spec de señales lo prohíbe en el plugin y el servidor no debe contradecirla):

```
# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (Apache 2.4.7+ / LiteSpeed Enterprise, mod_headers).
# HTML responses only. Content-Signal and Content-Usage replace the value PHP sends, so they never repeat.
<IfModule mod_headers.c>
	Header onsuccess unset Content-Signal "expr=%{CONTENT_TYPE} =~ m#^text/html#"
	Header always set Content-Signal "search=yes, ai-input=yes, ai-train=no" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
	Header onsuccess unset Content-Usage "expr=%{CONTENT_TYPE} =~ m#^text/html#"
	Header always set Content-Usage "train-ai=n, search=y" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
	Header always setifempty X-Robots-Tag "noai, noimageai" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
	Header always setifempty Link "<https://cognosonline.com/.well-known/api-catalog>; rel=\"api-catalog\"" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
</IfModule>
```

- `unset` + `always set` para las cabeceras de un solo valor: con PHP-FPM la cabecera de PHP vive en la tabla `always` y `set` la reemplaza; con mod_php vive en `onsuccess` y `unset` la retira antes. Resultado: exactamente una en la respuesta regenerada y una en la cacheada, en ambos SAPI.
- `setifempty` para `X-Robots-Tag` y `Link`: en la respuesta cacheada no hay ninguna y se añaden; en la regenerada PHP ya las envió (con PHP-FPM en la misma tabla, así que no se tocan; con mod_php pueden repetirse solo en esa respuesta, lo que es inocuo para directivas y relaciones). `set` está descartado porque pisaría un `X-Robots-Tag: noindex` de otro plugin; `merge` está descartado porque compara el valor completo, no cada directiva, y duplica igualmente.
- Las comillas dobles dentro de un valor se escapan como `\"` (`PageCache::apache_quote()`); el único valor con comillas es `Link`.

Bloque nginx (dos partes, porque `map` solo es válido en `http {}` y `add_header` en `server {}`/`location {}`); un valor vacío hace que nginx no añada la cabecera:

```
# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (nginx).
# 1) Inside http { }: an empty value means the header is not added, so only HTML responses get them.
map $sent_http_content_type $wpasl_content_signal {
	default "";
	"~^text/html" "search=yes, ai-input=yes, ai-train=no";
}
map $sent_http_content_type $wpasl_content_usage {
	default "";
	"~^text/html" "train-ai=n, search=y";
}
map $sent_http_content_type $wpasl_x_robots_tag {
	default "";
	"~^text/html" "noai, noimageai";
}
map $sent_http_content_type $wpasl_link {
	default "";
	"~^text/html" '<https://cognosonline.com/.well-known/api-catalog>; rel="api-catalog"';
}
# 2) Inside the server { } block of cognosonline.com. A location { } with its own add_header lines
#    stops inheriting these; repeat them there.
add_header Content-Signal $wpasl_content_signal always;
add_header Content-Usage $wpasl_content_usage always;
add_header X-Robots-Tag $wpasl_x_robots_tag always;
add_header Link $wpasl_link always;
```

- nginx no tiene "reemplazar": en la única respuesta que pasa por PHP (regeneración) `Content-Signal` y `Content-Usage` aparecerán dos veces con el mismo valor. Se acepta y se documenta en el comentario del bloque; la alternativa (`fastcgi_hide_header` + `map` combinado con `$upstream_http_content_signal`) es correcta pero demasiado dependiente de cómo está montado el `location` de PHP como para generarla a ciegas.
- Solo se emiten los `map` y `add_header` de las cabeceras presentes en `headers()`.

Bloque OpenLiteSpeed (`openlitespeed_snippet()`), siguiendo el bloque de la base de conocimiento de CyberPanel y la forma multilínea documentada, con la sintaxis de operador del texto de ayuda oficial:

```
# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (OpenLiteSpeed).
# CyberPanel: Websites > List Websites > Manage > vHost Conf, at the end of the file. If the file already has a
# "context / { }" block, add the extraHeaders lines inside it instead of adding a second block.
# OpenLiteSpeed WebAdmin: Virtual Hosts > your site > Context > "/" > Header Operations: paste only the lines
# between the END_extraHeaders markers.
# OpenLiteSpeed cannot limit headers to HTML responses, so X-Robots-Tag is left out on purpose: "noai" must not
# reach Markdown or robots.txt. The response that regenerates the cache passes through PHP and may carry
# Content-Signal and Content-Usage twice with the same value.
# Then restart OpenLiteSpeed gracefully (WebAdmin > Actions > Graceful Restart, or: systemctl restart lsws).
context / {
  allowBrowse             1
  extraHeaders            <<<END_extraHeaders
set Content-Signal "search=yes, ai-input=yes, ai-train=no"
set Content-Usage "train-ai=n, search=y"
merge Link "<https://cognosonline.com/.well-known/api-catalog>; rel=api-catalog"
  END_extraHeaders
  rewrite  {
  }
  addDefaultCharset       off
  phpIniOverride  {
  }
}
```

- `PageCache::headers( $for_openlitespeed = false )` (o un método `openlitespeed_headers()`) devuelve el mismo conjunto sin `X-Robots-Tag`: la sintaxis de OpenLiteSpeed no tiene condición por tipo de contenido (solo `env=`), un contexto `/` cubre también `.md`, `robots.txt` y `llms.txt`, y la spec de señales prohíbe `noai` en respuestas no HTML. El comentario del bloque lo explica. `Content-Signal` y `Content-Usage` van sin condición porque el plugin ya las envía en todas las respuestas públicas; un `Link` de descubrimiento extra en una respuesta no HTML es inocuo.
- `set` para las dos cabeceras de un solo valor (semántica de reemplazo si OpenLiteSpeed aplica la operación sobre la cabecera que envió PHP; si no, la respuesta que regenera la caché las llevará dos veces con el mismo valor, como en nginx, y el comentario lo documenta). `merge` para `Link`, operador presente en el parser, para no pisar las relaciones `alternate` y `api-catalog` que PHP ya envía en la respuesta regenerada.
- Valores entre comillas dobles, como en el bloque de la base de conocimiento de CyberPanel (`extraHeaders Strict-Transport-Security "max-age=15552000; includeSubDomains;preload"`), que es la única forma verificada con espacios y punto y coma. La relación del `Link` se escribe `rel=api-catalog` sin comillas internas, forma equivalente según RFC 8288 (una sola relación puede ir como token), porque no está verificado que el parser de OpenLiteSpeed admita `\"` dentro de un valor. Es la única diferencia textual con el valor que envía el plugin y el delta de spec la recoge.
- El bloque mantiene `allowBrowse`, `rewrite`, `addDefaultCharset` y `phpIniOverride` tal como los pone CyberPanel para que el contexto nuevo no altere el comportamiento del sitio; no lleva `location`, igual que en la base de conocimiento de CyberPanel.
- Solo se emiten las líneas de las cabeceras presentes.

Recordatorio de Cloudflare: cuando el informe almacenado tiene `cdn === 'Cloudflare'`, `cloudflare_note()` produce un párrafo "Cloudflare was detected in front of the site: instead of the web server you can add these headers with a Transform Rule (Rules > Transform Rules > Modify Response Header, Set static), one per header, limited to HTML responses if the rule expression allows it:" seguido de las parejas `Nombre: valor`. Sin informe o sin Cloudflare no aparece; la detección local no depende del informe pero el CDN sí.

Alternativas descartadas: (a) `Header always add` sin condición en Apache, como pedía el encargo inicial: añadiría `X-Robots-Tag: noai` a Markdown, `robots.txt`, feeds y manifiestos (contradice la spec de señales y confunde a los agentes que leen Markdown) y duplicaría `Content-Signal` en todas las respuestas que pasan por PHP, incluidas `.md`, `llms.txt` y `robots.txt`, con lo que el propio diagnóstico mostraría valores repetidos; (b) incluir `X-Robots-Tag` en el bloque de OpenLiteSpeed con un contexto por extensión (`.md`) que lo retire con `unset`: la URL negociada por `Accept` y `?wpasl=md` no tienen extensión, `robots.txt` y `llms.txt` necesitarían contextos propios y el resultado sería frágil; (c) usar en OpenLiteSpeed la sintaxis antigua `Nombre: valor` (equivale a `add`): duplicaría las cabeceras en la respuesta regenerada sin la posibilidad de reemplazo que da `set`.

### D4. Dónde se avisa

- **Diagnóstico:** aviso `notice notice-warning inline` entre la descripción y el formulario "Run crawler simulation", visible sin informe. Contiene: título con el nombre y la versión detectados; párrafo con las cabeceras perdidas, la explicación de `Accept`, lo que no se ve afectado y que la petición que regenera la caché sí las lleva; párrafo con el remedio (servidor web o CDN; el plugin no escribe configuración); tres `<textarea readonly class="large-text code">`, uno por fragmento (`.htaccess`, nginx, OpenLiteSpeed; cada uno con un encabezado `<h4>` que nombra el servidor y, en el de OpenLiteSpeed, el aviso de que `X-Robots-Tag` no va incluido; mismo patrón que los comandos `curl`, con `esc_textarea()` y sin espacio inicial) y el recordatorio de Cloudflare cuando proceda. Todo el contenido se genera en `PageCache` y la pestaña solo lo imprime.
- **Señales:** un `notice notice-warning inline` de una frase al inicio de `render()`: "Cache Enabler is active. HTML served from its page cache does not carry the Content-Signal, Content-Usage or X-Robots-Tag headers; see the Diagnostics tab for the web server configuration that restores them." con enlace a `add_query_arg( array( 'page' => Page::SLUG, 'tab' => 'diagnostics' ), admin_url( 'tools.php' ) )`. Justificación: es la pestaña donde el administrador cambia justo los valores que la caché descarta, y sin el aviso comprobaría el cambio con `curl` y lo vería "fallar".
- **Manifiestos:** sin aviso. Los ficheros de manifiesto no se ven afectados (no son HTML) y la cabecera `Link` del catálogo es un mecanismo secundario de descubrimiento (RFC 9727) que Diagnóstico ya cubre; un aviso allí sugeriría un problema con los manifiestos que no existe.
- **Lista de verificación estática:** sin cambios; el aviso es más específico que cualquier ítem genérico y ya existe el punto sobre `Vary: Accept`.

### D5. Cableado

`Plugin::boot()` crea `new PageCache( $settings, $this->services['signals'] )`, lo registra como servicio `page_cache` (sin `register()`, no engancha nada) y lo pasa a `DiagnosticsController`, que lo pasa a `DiagnosticsTab` (constructor `( CrawlerProbe $probe, Page $page, PageCache $page_cache )`). `CrawlerProbe` usa `PageCache::detect()` estática y no necesita la instancia. Los tests obtienen la instancia con `Plugin::instance()->get( 'page_cache' )`.

### D6. Tests

En `tests/test-diagnostics.php` (mismos `fake_http` y dos crawlers):

- `test_report_detects_cache_enabler_from_x_cache_handler`: respuestas de portada, entrada HTML y entrada con `Accept: text/markdown` con `content-type: text/html`, `x-cache-handler: cache-enabler-engine` y sin `content-signal` ni `x-robots-tag`; cuerpo con el `<link rel="alternate">`. Afirma que el resultado crudo de `probe->run()` conserva `x-cache-handler`; que `infrastructure['page_cache']` es "Cache Enabler" y `page_cache_served` verdadero; que `content_signal` y `x_robots_tag` son advertencias cuyo mensaje contiene "Cache Enabler"; que `negotiation` es error con "Cache Enabler"; que `alternate_link`, `markdown_url` y las comprobaciones de sitio (`llms`, `robots`, `skills`, `catalog`, `markdown_url`) siguen en `ok`. Renderiza la pestaña y comprueba "Page cache: Cache Enabler".
- `test_report_uses_local_detection_without_x_cache_handler`: filtro `wpasl_diagnostics_page_cache` devolviendo Cache Enabler; respuestas HTML sin `x-cache-handler` ni `content-signal`; afirma `page_cache` "Cache Enabler", `page_cache_served` falso y mensajes que nombran Cache Enabler con la variante "is active".
- `test_report_shows_other_page_cache_handler_verbatim`: `x-cache-handler: foo-cache` → `page_cache` "foo-cache" y mensajes genéricos ("a cache or proxy may strip it").
- `test_tab_shows_cache_enabler_notice_and_snippets`: filtro activo, sin informe; renderiza la pestaña y afirma el texto del aviso (nombre, `Content-Signal`, "Accept: text/markdown", ".md", "llms.txt", "robots.txt", "web server or CDN"), las líneas exactas de `.htaccess` con los valores por defecto (`Header always set Content-Signal "search=yes, ai-input=yes, ai-train=no"`, `Content-Usage "train-ai=n, search=y"`, `setifempty X-Robots-Tag "noai, noimageai"`, `setifempty Link "<` + `home_url( '/.well-known/api-catalog' )`, la condición `%{CONTENT_TYPE}`), las líneas de nginx (`map $sent_http_content_type $wpasl_content_signal`, `add_header Content-Signal $wpasl_content_signal always;`), la presencia del bloque de OpenLiteSpeed (`END_extraHeaders`) y que no aparece "Cloudflare". Luego guarda un informe con `cdn => 'Cloudflare'` y afirma que aparece el recordatorio.
- `test_openlitespeed_snippet_omits_x_robots_tag`: con la configuración por defecto, `openlitespeed_snippet()` contiene `context / {`, `extraHeaders            <<<END_extraHeaders`, `set Content-Signal "search=yes, ai-input=yes, ai-train=no"`, `set Content-Usage "train-ai=n, search=y"`, `merge Link "<` + `home_url( '/.well-known/api-catalog' )` + `>; rel=api-catalog"`, `END_extraHeaders`, "vHost Conf", "Header Operations" y "Graceful Restart", y no contiene `X-Robots-Tag` ni `noai` fuera del comentario explicativo (aserción sobre las líneas del bloque `extraHeaders`), mientras que `htaccess_snippet()` con la misma configuración sí contiene `X-Robots-Tag`.
- `test_snippets_follow_settings`: `ai-train=yes`, `content_usage_header=false`, `manifest_enabled=false` → `htaccess_snippet()`, `nginx_snippet()` y `openlitespeed_snippet()` contienen `ai-train=yes` y no contienen `Content-Usage`, `X-Robots-Tag` ni `Link`; `headers()` es exactamente `array( 'Content-Signal' => 'search=yes, ai-input=yes, ai-train=yes' )`.
- `test_tab_hides_cache_notice_without_page_cache`: filtro `__return_null`; la pestaña no contiene "Cache Enabler" ni `mod_headers`.
- `test_tab_renders_report_from_previous_version_without_notices` (existente) añade `assertSame( '', $report['infrastructure']['page_cache'] )` y que el HTML no contiene "Page cache:".

En `tests/test-content-signals.php`: `test_signals_tab_warns_about_cache_enabler` (filtro activo → aviso con enlace a `tab=diagnostics`; filtro nulo → sin aviso) y verificación de que `api_catalog_link()` coincide con el valor que `send()` emite.

### D7. Cobertura documentada

`docs/spec-coverage.md` gana en `agent-diagnostics` una fila por escenario nuevo del delta (cinco nuevos del requisito modificado y ocho del requisito añadido, incluida la fila "Fragmento para OpenLiteSpeed sin X-Robots-Tag" → `Test_Diagnostics::test_openlitespeed_snippet_omits_x_robots_tag`; agrupadas cuando un mismo test cubre varias) y el párrafo introductorio cita `detect-cache-enabler-page-cache`. `readme.txt` y `README.md` no cambian.

## Risks / Trade-offs

- [LiteSpeed Enterprise podría no soportar el argumento `expr=` de `Header`] → El aviso etiqueta el bloque como "Apache 2.4.7+ / LiteSpeed (mod_headers)"; si LiteSpeed ignora la condición, las cabeceras se aplicarían a todas las respuestas (mismo efecto que el `Header always add` del encargo, nunca peor). Queda como pregunta abierta.
- [Un informe se genera en un sitio donde Cache Enabler se activó o desactivó después] → `page_cache` refleja el momento de la prueba y el aviso local refleja el estado actual; ambos textos dicen de qué momento hablan ("at the time of the last run" en la línea de infraestructura).
- [`%{CONTENT_TYPE}` evaluado antes de que el manejador fije el tipo] → `mod_headers` procesa `Header` en su filtro de salida, cuando el tipo ya está fijado; el propio manual condiciona `Content-Type` con esa variable. Verificación real en cognosonline tras aplicar el fragmento (`curl -sI` a la portada, a una URL `.md` y a `robots.txt`), fuera de este cambio.
- [Duplicado de `Content-Signal` en nginx en la respuesta de regeneración] → Documentado en el comentario del bloque; valores idénticos, sin efecto para los agentes.
- [En OpenLiteSpeed `set` podría no reemplazar la cabecera que envió PHP, o el módulo de caché podría repetirla (incidencia 85)] → Solo afecta a la respuesta que regenera la caché; valores idénticos; documentado en el comentario del bloque. Verificación con `curl -sI` en cognosonline tras aplicarlo.
- [El bloque de OpenLiteSpeed no restaura `X-Robots-Tag: noai` en el HTML cacheado] → Decisión deliberada: sin condición por tipo de contenido, incluirlo contradiría la spec de señales en Markdown y `robots.txt`. La meta `robots` con `noai, noimageai` sí sobrevive en el cuerpo del HTML cacheado, y `Content-Signal` con `ai-train=no` llega por cabecera; el aviso lo explica.
- [El valor citado entre comillas o `rel=api-catalog` sin comillas podría no ser aceptado por el parser de OpenLiteSpeed] → La forma con comillas es la de la base de conocimiento de CyberPanel; `rel=api-catalog` es válido por RFC 8288 y evita comillas anidadas. Se comprueba en cognosonline (que corre OpenLiteSpeed) al desplegar.
- [El filtro `wpasl_diagnostics_page_cache` permite a un tercero forzar un aviso falso] → Solo afecta a texto informativo del administrador; no altera ninguna respuesta pública.
- [Las cadenas nuevas no están traducidas hasta la release] → Comportamiento habitual del proyecto; el POT y el `.po` se regeneran al preparar la versión.

## Migration Plan

Sin migración de datos. El transient del informe y el del progreso ganan claves con valor por defecto; `normalize()` cubre los almacenados. Rollback: revertir el commit; el único efecto es volver a los textos genéricos. Tras publicar, en cognosonline.com (OpenLiteSpeed bajo CyberPanel): abrir Diagnóstico, comprobar el aviso, pegar el bloque de OpenLiteSpeed en el vHost Conf del sitio, reiniciar OpenLiteSpeed en modo graceful y verificar con `curl -sI` que la portada cacheada lleva `Content-Signal`, `Content-Usage` y `Link rel=api-catalog` una sola vez, que una URL `.md` sigue sin `X-Robots-Tag` y que la respuesta regenerada (tras purgar la caché) no trae valores rotos.

## Open Questions

- ¿Soporta LiteSpeed Enterprise `expr=` en `Header` de `.htaccess`? El texto de ayuda compartido con OpenLiteSpeed solo documenta `env=`, pero Enterprise procesa `.htaccess` con más compatibilidad Apache que OpenLiteSpeed. Deferrable: no cambia la spec ni las tareas; solo afectaría a la etiqueta del bloque `.htaccess`.
- ¿Reemplaza `set` de OpenLiteSpeed una cabecera que ya envió PHP, o la duplica? Deferrable: el comentario del bloque ya avisa del posible duplicado en la respuesta regenerada y la verificación se hace en cognosonline al desplegar.
