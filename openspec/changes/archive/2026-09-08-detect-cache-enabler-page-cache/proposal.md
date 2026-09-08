## Why

En cognosonline.com el plugin de caché de página Cache Enabler (KeyCDN) entrega el HTML cacheado desde su drop-in `advanced-cache.php`, que WordPress carga antes que cualquier plugin. Esa entrega solo emite `X-Cache-Handler: cache-enabler-engine` (más `Content-Encoding` y 304), lee el fichero y termina: no conserva ni reproduce las cabeceras del origen y no ofrece ningún hook para añadirlas. Toda respuesta HTML cacheada pierde `Content-Signal`, `Content-Usage`, `X-Robots-Tag: noai`, `Link rel="api-catalog"` y `Link rel="alternate" type="text/markdown"`, y como la clave de caché ignora `Accept`, un agente que pide `text/markdown` (aceptando también `text/html`) sobre una URL cacheada recibe el HTML cacheado. Solo la petición que regenera la caché pasa por PHP, así que el administrador ve "la cabecera aparece una vez y luego desaparece". Veinte peticiones seguidas a la portada devolvieron `x-cache-handler: cache-enabler-engine` y ninguna cabecera del plugin. Hoy la pestaña Diagnóstico solo dice "a cache or proxy may strip it", no reconoce Cache Enabler y no indica dónde está el remedio, que es externo al plugin (servidor web o CDN). El sitio de referencia corre OpenLiteSpeed bajo CyberPanel, donde ni `.htaccess` ni `add_header` sirven, pero la funcionalidad debe valer para varios servidores.

## What Changes

- La pestaña Diagnóstico detecta Cache Enabler activo en el sitio sin ejecutar la simulación y muestra un aviso que explica qué cabeceras se pierden en el HTML cacheado, que la negociación por `Accept` devuelve el HTML cacheado, que las URLs `.md`, `llms.txt`, `robots.txt` y los manifiestos no se ven afectados, y que la solución está en el servidor web o el CDN.
- La simulación conserva la cabecera de respuesta `X-Cache-Handler`; el informe gana un hallazgo de infraestructura "caché de página" (Cache Enabler cuando el valor es `cache-enabler-engine`, o el valor bruto para otros manejadores) y las comprobaciones por crawler que hoy dicen "a cache or proxy may strip it" o "a page cache or CDN that ignores Vary: Accept" nombran Cache Enabler cuando la respuesta vino de su caché o el plugin está activo en el sitio.
- El aviso incluye fragmentos listos para copiar generados con los valores reales del sitio (URL del sitio, `Content-Signal`, `Content-Usage` si está activada, `X-Robots-Tag` si el entrenamiento no está permitido, `Link rel="api-catalog"` si el manifiesto está habilitado): un bloque para `.htaccess` (Apache / LiteSpeed Enterprise, `mod_headers`), un bloque para nginx (`add_header … always`), un bloque para OpenLiteSpeed (`extraHeaders` en el `context /` del host virtual, con indicación de dónde se pega en CyberPanel y en el WebAdmin y del reinicio graceful; sin `X-Robots-Tag`, porque OpenLiteSpeed no puede limitar cabeceras a las respuestas HTML) y, cuando el último informe detectó Cloudflare, un recordatorio de la opción Transform Rule. Los valores salen de la lógica existente de señales de contenido; no se duplican cadenas.
- La pestaña Señales muestra un aviso breve cuando Cache Enabler está activo, remitiendo a Diagnóstico; la pestaña Manifiestos no cambia (los manifiestos no se ven afectados).
- Un informe almacenado por una versión anterior se sigue renderizando sin avisos; el hallazgo nuevo aparece vacío.
- Nuevo filtro `wpasl_diagnostics_page_cache` para sustituir la detección local (tests y terceros), ya que una constante o clase de otro plugin no puede simularse de otra forma en PHPUnit.
- Tests PHPUnit para la detección remota, la detección local, los avisos y el contenido de los fragmentos; `docs/spec-coverage.md` actualizado.

Sin cambios **BREAKING**. Fuera de alcance: escribir en `.htaccess`, en el vHost Conf o en la configuración del servidor, opciones nuevas en el administrador, bump de versión, `readme.txt`, `README.md` y regeneración de traducciones (se hacen en la release; la documentación del filtro nuevo en la sección "Filters" de `readme.txt` queda para entonces).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `agent-diagnostics`: el requisito de detección de CDN y advertencias de infraestructura incorpora la detección de la caché de página (remota por `X-Cache-Handler` y local por el plugin activo) y exige que las comprobaciones por crawler nombren Cache Enabler; un requisito nuevo cubre el aviso local, su texto, los fragmentos de configuración generados con los valores del sitio y el aviso breve en la pestaña Señales.

## Impact

- **Código:** `src/Diagnostics/CrawlerProbe.php` (`HEADERS`, `begin()` registra la detección local), `src/Diagnostics/Report.php` (hallazgo `page_cache`, mensajes por crawler, `defaults()`), nueva clase `src/Diagnostics/PageCache.php` (detección local filtrable y fragmentos), `src/Admin/Tabs/DiagnosticsTab.php` (aviso y fragmentos), `src/Admin/Tabs/SignalsTab.php` (aviso breve), `src/Signals/ContentSignals.php` (exponer el valor de la cabecera `Link` del catálogo sin cambiar lo que se envía), `src/Plugin.php` (cableado del servicio nuevo).
- **Tests:** `tests/test-diagnostics.php` (detección remota y local, aviso, fragmentos, informe antiguo), `tests/test-content-signals.php` (aviso en la pestaña Señales).
- **Docs:** `docs/spec-coverage.md`. `readme.txt`, `README.md` y `languages/` no cambian.
- **Datos, dependencias, admin:** sin migración, sin dependencias nuevas, sin opciones nuevas; el transient del informe gana claves con valores por defecto.
