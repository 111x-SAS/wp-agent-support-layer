## MODIFIED Requirements

### Requirement: Detección de CDN y advertencias de infraestructura
El informe SHALL indicar si las respuestas provienen de un CDN o proxy conocido a partir de cabeceras como `cf-ray`, `server` o `x-cache`, y SHALL advertir cuando detecte que Cloudflare ya convierte a Markdown en el borde. El informe SHALL indicar además si una caché de página sirvió alguna de las respuestas sondeadas, a partir de la cabecera de respuesta `X-Cache-Handler` (el valor `cache-enabler-engine` SHALL identificarse como Cache Enabler; cualquier otro valor SHALL mostrarse tal cual), y SHALL registrar si Cache Enabler estaba activo en el sitio en el momento de la prueba aunque ninguna respuesta llevara esa cabecera (modo en que el servidor web sirve los ficheros de caché sin PHP). Cuando la caché de página sea Cache Enabler, las comprobaciones por crawler que detecten la ausencia de `Content-Signal` o de `X-Robots-Tag` en la respuesta HTML, o HTML devuelto para `Accept: text/markdown`, SHALL nombrar Cache Enabler como causa probable en lugar del texto genérico "una caché o proxy". El informe SHALL comprobar si los archivos del almacenamiento son accesibles por acceso directo y marcarlo como error si lo son. El almacenamiento SHALL contener siempre un archivo sonda para esta comprobación, de modo que se realice aunque no exista ningún documento generado. Un informe almacenado por una versión anterior sin el hallazgo de caché de página SHALL renderizarse sin avisos de PHP, con ese hallazgo vacío.

#### Scenario: Cloudflare detectado
- **WHEN** las respuestas incluyen la cabecera `cf-ray`
- **THEN** el informe indica "Cloudflare detectado" y muestra la lista de verificación de WAF y rate-limiting

#### Scenario: Cache Enabler detectado por la cabecera X-Cache-Handler
- **WHEN** la portada y la entrada de muestra en HTML responden 200 con `X-Cache-Handler: cache-enabler-engine` y sin `Content-Signal` ni `X-Robots-Tag`, y la entrada de muestra con `Accept: text/markdown` responde con ese mismo HTML cacheado
- **THEN** el informe indica que Cache Enabler sirvió respuestas desde su caché de página, las comprobaciones de `Content-Signal` y `X-Robots-Tag` de cada crawler son advertencias que nombran Cache Enabler, la comprobación de negociación es un error que nombra Cache Enabler, y las comprobaciones de la URL `.md`, `llms.txt`, `robots.txt` y los manifiestos siguen siendo correctas

#### Scenario: Cache Enabler activo sin cabecera X-Cache-Handler
- **WHEN** Cache Enabler está activo en el sitio y las respuestas HTML sondeadas llegan sin `X-Cache-Handler` y sin `Content-Signal`
- **THEN** el informe indica que Cache Enabler está activo en el sitio y las advertencias de cabeceras ausentes lo nombran como causa probable

#### Scenario: Otra caché de página
- **WHEN** las respuestas incluyen `X-Cache-Handler` con un valor distinto de `cache-enabler-engine`
- **THEN** el informe muestra ese valor como caché de página detectada y las comprobaciones por crawler conservan el texto genérico

#### Scenario: Sin caché de página
- **WHEN** ninguna respuesta incluye `X-Cache-Handler` y Cache Enabler no está activo
- **THEN** el informe no menciona ninguna caché de página y las comprobaciones por crawler conservan el texto genérico

#### Scenario: Almacenamiento expuesto
- **WHEN** la petición directa a un documento almacenado devuelve 200
- **THEN** el informe marca "almacenamiento accesible públicamente" como error con la instrucción de corrección para el servidor web

#### Scenario: Almacenamiento sin documentos generados
- **WHEN** todavía no se ha generado ningún documento y un administrador lanza la prueba
- **THEN** el informe incluye igualmente la comprobación de acceso directo al almacenamiento usando el archivo sonda

#### Scenario: Informe anterior sin hallazgo de caché de página
- **WHEN** el transient del informe contiene un informe generado por una versión anterior, sin la clave de caché de página
- **THEN** la pestaña Diagnóstico se renderiza sin avisos de PHP y sin mencionar ninguna caché de página

## ADDED Requirements

### Requirement: Aviso de caché de página con fragmentos de configuración
Cuando Cache Enabler esté activo en el sitio, la pestaña Diagnóstico SHALL mostrar, sin necesidad de ejecutar la simulación, un aviso que indique: que el HTML servido desde la caché de página pierde las cabeceras `Content-Signal`, `Content-Usage`, `X-Robots-Tag`, `Link rel="api-catalog"` y `Link rel="alternate" type="text/markdown"`; que una petición con `Accept: text/markdown` que también acepte `text/html` recibe el HTML cacheado en lugar de Markdown; que las URLs `.md`, `llms.txt`, `robots.txt` y los manifiestos no se ven afectados; que solo la petición que regenera la caché lleva las cabeceras; y que la solución está en el servidor web o el CDN, no en el plugin. La detección local SHALL basarse en la presencia del plugin Cache Enabler y SHALL poder sustituirse mediante un filtro, de modo que pueda simularse en tests y adaptarse por terceros. El plugin MUST NOT escribir en `.htaccess` ni en la configuración del servidor.

El aviso SHALL incluir fragmentos listos para copiar generados con los valores reales del sitio: un bloque para `.htaccess` (Apache 2.4 y LiteSpeed Enterprise, dentro de `<IfModule mod_headers.c>`), un bloque para nginx (`add_header … always`) y un bloque para OpenLiteSpeed (`extraHeaders` dentro de un `context /` del host virtual). Cada bloque SHALL fijar `Content-Signal` con el valor configurado; `Content-Usage` con su valor solo cuando esa cabecera esté activada; y `Link` con la relación `api-catalog` hacia `/.well-known/api-catalog` del sitio solo cuando el manifiesto esté habilitado. Los bloques para `.htaccess` y nginx SHALL fijar además `X-Robots-Tag: noai, noimageai` solo cuando `ai-train` sea `no` y SHALL limitar las cabeceras a las respuestas HTML, de modo que `X-Robots-Tag` no se añada a Markdown ni a `robots.txt`; el bloque para `.htaccess` SHALL evitar que `Content-Signal` y `Content-Usage` aparezcan duplicadas en las respuestas que sí pasan por PHP. El bloque para OpenLiteSpeed, cuyo servidor no puede limitar cabeceras por tipo de contenido, MUST NOT incluir `X-Robots-Tag` sea cual sea el valor de `ai-train`, y el texto que lo acompaña SHALL explicar esa omisión, SHALL indicar dónde se pega en CyberPanel (vHost Conf del sitio) y en el WebAdmin de OpenLiteSpeed (Header Operations del contexto `/` del host virtual) y SHALL indicar que requiere un reinicio graceful del servidor. Los valores de las cabeceras SHALL ser los mismos que el plugin envía en sus respuestas; en el bloque para OpenLiteSpeed la relación del `Link` SHALL escribirse sin comillas internas (`rel=api-catalog`), forma equivalente según RFC 8288. Cuando el último informe detectó Cloudflare, el aviso SHALL recordar la alternativa de una Transform Rule de cabeceras de respuesta en Cloudflare con esas mismas cabeceras. Cuando Cache Enabler no esté activo, el aviso y los fragmentos MUST NOT mostrarse.

La pestaña Señales SHALL mostrar, cuando Cache Enabler esté activo, un aviso breve indicando que el HTML cacheado no lleva las cabeceras de señales y remitiendo a la pestaña Diagnóstico; la pestaña Manifiestos MUST NOT cambiar.

#### Scenario: Aviso con Cache Enabler activo
- **WHEN** Cache Enabler está activo y un administrador abre la pestaña Diagnóstico sin haber ejecutado la simulación
- **THEN** ve el aviso que nombra Cache Enabler, enumera las cabeceras perdidas, explica que `Accept: text/markdown` devuelve el HTML cacheado, indica que `.md`, `llms.txt`, `robots.txt` y los manifiestos no se ven afectados y remite al servidor web o al CDN, junto con los fragmentos para `.htaccess`, nginx y OpenLiteSpeed

#### Scenario: Fragmentos con la configuración por defecto
- **WHEN** Cache Enabler está activo, las señales son las por defecto (`ai-train=no`, `Content-Usage` activada) y el manifiesto está habilitado
- **THEN** el bloque para `.htaccess` y el bloque para nginx fijan `Content-Signal: search=yes, ai-input=yes, ai-train=no`, `Content-Usage: train-ai=n, search=y`, `X-Robots-Tag: noai, noimageai` y `Link: <URL del sitio/.well-known/api-catalog>; rel="api-catalog"`, condicionados a respuestas HTML, con la URL real del sitio y sin ninguna cadena de ejemplo

#### Scenario: Fragmento para OpenLiteSpeed sin X-Robots-Tag
- **WHEN** Cache Enabler está activo, las señales son las por defecto (`ai-train=no`, `Content-Usage` activada) y el manifiesto está habilitado
- **THEN** el bloque para OpenLiteSpeed contiene un `context /` con `extraHeaders` que fija `Content-Signal: search=yes, ai-input=yes, ai-train=no`, `Content-Usage: train-ai=n, search=y` y `Link: <URL del sitio/.well-known/api-catalog>; rel=api-catalog`, no contiene `X-Robots-Tag` aunque `ai-train` sea `no`, y su texto explica esa omisión, indica el vHost Conf de CyberPanel y las Header Operations del WebAdmin y pide el reinicio graceful

#### Scenario: Fragmentos con entrenamiento permitido y sin manifiesto
- **WHEN** Cache Enabler está activo, `ai-train` es `yes`, `Content-Usage` está desactivada y el manifiesto está deshabilitado
- **THEN** los tres fragmentos contienen únicamente `Content-Signal` con `ai-train=yes`, sin `Content-Usage`, sin `X-Robots-Tag` y sin `Link`

#### Scenario: Recordatorio de Cloudflare
- **WHEN** Cache Enabler está activo y el último informe almacenado detectó Cloudflare
- **THEN** el aviso incluye el recordatorio de la Transform Rule de Cloudflare; sin informe o sin Cloudflare detectado, no lo incluye

#### Scenario: Sin Cache Enabler
- **WHEN** Cache Enabler no está activo y un administrador abre la pestaña Diagnóstico
- **THEN** la pestaña no muestra el aviso ni los fragmentos

#### Scenario: Detección local sustituida por filtro
- **WHEN** un filtro devuelve que Cache Enabler está activo en un sitio donde no lo está
- **THEN** la pestaña Diagnóstico muestra el aviso y los fragmentos como si el plugin estuviera activo, y el informe de una simulación ejecutada en ese estado registra Cache Enabler como caché de página

#### Scenario: Aviso en la pestaña Señales
- **WHEN** Cache Enabler está activo y un administrador abre la pestaña Señales
- **THEN** ve un aviso breve que indica que el HTML cacheado no lleva `Content-Signal`, `Content-Usage` ni `X-Robots-Tag` y enlaza a la pestaña Diagnóstico; sin Cache Enabler, el aviso no aparece
