## MODIFIED Requirements

### Requirement: Aviso de caché de página con fragmentos de configuración
Cuando Cache Enabler esté activo en el sitio, la pestaña Diagnóstico SHALL mostrar, sin necesidad de ejecutar la simulación, un aviso que indique: que el HTML servido desde la caché de página pierde las cabeceras `Content-Signal`, `Content-Usage`, `X-Robots-Tag`, `Link rel="api-catalog"` y `Link rel="alternate" type="text/markdown"`; que una petición con `Accept: text/markdown` que también acepte `text/html` recibe el HTML cacheado en lugar de Markdown; que las URLs `.md`, `llms.txt`, `robots.txt` y los manifiestos no se ven afectados; que solo la petición que regenera la caché lleva las cabeceras; y que la solución está en el servidor web o el CDN, que el plugin solo puede aplicar por sí mismo en el caso de `.htaccess` descrito en el requisito «Aplicación automática del bloque `.htaccess`». La detección local SHALL basarse en la presencia del plugin Cache Enabler y SHALL poder sustituirse mediante un filtro, de modo que pueda simularse en tests y adaptarse por terceros. El plugin MUST NOT escribir en la configuración del servidor web (nginx, OpenLiteSpeed, host virtual) ni del CDN, y MUST NOT escribir en `.htaccess` salvo en el único caso del requisito «Aplicación automática del bloque `.htaccess`»: el bloque de cabeceras de Apache, por acción explícita del administrador, con copia de seguridad, verificación y restauración automática. Ninguna otra ruta del plugin (activación, actualización, guardado de ajustes, generación programada, WP-CLI, simulación de rastreo, renderizado de la pestaña) MUST escribir en `.htaccess`.

El aviso SHALL incluir fragmentos listos para copiar generados con los valores reales del sitio: un bloque para `.htaccess` (Apache 2.4 y LiteSpeed Enterprise, dentro de `<IfModule mod_headers.c>`), un bloque para nginx (`add_header … always`) y un bloque para OpenLiteSpeed (`extraHeaders` dentro de un `context /` del host virtual). Cada bloque SHALL fijar `Content-Signal` con el valor configurado; `Content-Usage` con su valor solo cuando esa cabecera esté activada; y `Link` con la relación `api-catalog` hacia `/.well-known/api-catalog` del sitio solo cuando el manifiesto esté habilitado. Los bloques para `.htaccess` y nginx SHALL fijar además `X-Robots-Tag: noai, noimageai` solo cuando `ai-train` sea `no` y SHALL limitar las cabeceras a las respuestas HTML, de modo que `X-Robots-Tag` no se añada a Markdown ni a `robots.txt`; el bloque para `.htaccess` SHALL evitar que `Content-Signal` y `Content-Usage` aparezcan duplicadas en las respuestas que sí pasan por PHP. El bloque para `.htaccess` SHALL fijar además, siempre y limitada a respuestas HTML, la cabecera marcador `X-WPASL-Headers: htaccess`, que el plugin MUST NOT enviar nunca desde PHP, de modo que su presencia en una respuesta demuestre que el bloque está activo en el servidor; el bloque copiable y el que escribe la aplicación automática SHALL ser el mismo texto. Los bloques para nginx y OpenLiteSpeed MUST NOT cambiar por este marcador. El bloque para OpenLiteSpeed, cuyo servidor no puede limitar cabeceras por tipo de contenido, MUST NOT incluir `X-Robots-Tag` sea cual sea el valor de `ai-train`, y el texto que lo acompaña SHALL explicar esa omisión, SHALL indicar dónde se pega en CyberPanel (vHost Conf del sitio) y en el WebAdmin de OpenLiteSpeed (Header Operations del contexto `/` del host virtual) y SHALL indicar que requiere un reinicio graceful del servidor. Los valores de las cabeceras de señales, `X-Robots-Tag` y `Link` SHALL ser los mismos que el plugin envía en sus respuestas; en el bloque para OpenLiteSpeed la relación del `Link` SHALL escribirse sin comillas internas (`rel=api-catalog`), forma equivalente según RFC 8288. Cuando el último informe detectó Cloudflare, el aviso SHALL recordar la alternativa de una Transform Rule de cabeceras de respuesta en Cloudflare con esas mismas cabeceras. Cuando Cache Enabler no esté activo, el aviso y los fragmentos MUST NOT mostrarse. Cuando el entorno no se detecte como compatible con la aplicación automática, `.htaccess` no sea escribible o el sitio sea multisitio, el aviso SHALL mostrar únicamente los fragmentos, sin comprobación de capacidad ni botón, exactamente como antes de este cambio.

La pestaña Señales SHALL mostrar, cuando Cache Enabler esté activo, un aviso breve indicando que el HTML cacheado no lleva las cabeceras de señales y remitiendo a la pestaña Diagnóstico; la pestaña Manifiestos MUST NOT cambiar.

#### Scenario: Aviso con Cache Enabler activo
- **WHEN** Cache Enabler está activo y un administrador abre la pestaña Diagnóstico sin haber ejecutado la simulación
- **THEN** ve el aviso que nombra Cache Enabler, enumera las cabeceras perdidas, explica que `Accept: text/markdown` devuelve el HTML cacheado, indica que `.md`, `llms.txt`, `robots.txt` y los manifiestos no se ven afectados y remite al servidor web o al CDN, junto con los fragmentos para `.htaccess`, nginx y OpenLiteSpeed, y el texto no afirma que el plugin nunca escribe `.htaccess`

#### Scenario: Fragmentos con la configuración por defecto
- **WHEN** Cache Enabler está activo, las señales son las por defecto (`ai-train=no`, `Content-Usage` activada) y el manifiesto está habilitado
- **THEN** el bloque para `.htaccess` y el bloque para nginx fijan `Content-Signal: search=yes, ai-input=yes, ai-train=no`, `Content-Usage: train-ai=n, search=y`, `X-Robots-Tag: noai, noimageai` y `Link: <URL del sitio/.well-known/api-catalog>; rel="api-catalog"`, condicionados a respuestas HTML, con la URL real del sitio y sin ninguna cadena de ejemplo; el bloque para `.htaccess` fija además `X-WPASL-Headers: htaccess` condicionada a respuestas HTML y el bloque para nginx no la contiene

#### Scenario: Fragmento para OpenLiteSpeed sin X-Robots-Tag
- **WHEN** Cache Enabler está activo, las señales son las por defecto (`ai-train=no`, `Content-Usage` activada) y el manifiesto está habilitado
- **THEN** el bloque para OpenLiteSpeed contiene un `context /` con `extraHeaders` que fija `Content-Signal: search=yes, ai-input=yes, ai-train=no`, `Content-Usage: train-ai=n, search=y` y `Link: <URL del sitio/.well-known/api-catalog>; rel=api-catalog`, no contiene `X-Robots-Tag` aunque `ai-train` sea `no`, no contiene `X-WPASL-Headers`, y su texto explica esa omisión, indica el vHost Conf de CyberPanel y las Header Operations del WebAdmin y pide el reinicio graceful

#### Scenario: Fragmentos con entrenamiento permitido y sin manifiesto
- **WHEN** Cache Enabler está activo, `ai-train` es `yes`, `Content-Usage` está desactivada y el manifiesto está deshabilitado
- **THEN** los tres fragmentos contienen únicamente `Content-Signal` con `ai-train=yes`, sin `Content-Usage`, sin `X-Robots-Tag` y sin `Link`, y el bloque para `.htaccess` conserva además `X-WPASL-Headers: htaccess`

#### Scenario: Marcador nunca enviado desde PHP
- **WHEN** se solicita al sitio una página HTML, una URL `.md`, `robots.txt`, `llms.txt` o un manifiesto sin ningún bloque aplicado en el servidor
- **THEN** ninguna respuesta lleva la cabecera `X-WPASL-Headers`

#### Scenario: Recordatorio de Cloudflare
- **WHEN** Cache Enabler está activo y el último informe almacenado detectó Cloudflare
- **THEN** el aviso incluye el recordatorio de la Transform Rule de Cloudflare; sin informe o sin Cloudflare detectado, no lo incluye

#### Scenario: Sin Cache Enabler
- **WHEN** Cache Enabler no está activo y un administrador abre la pestaña Diagnóstico
- **THEN** la pestaña no muestra el aviso ni los fragmentos

#### Scenario: Detección local sustituida por filtro
- **WHEN** un filtro devuelve que Cache Enabler está activo en un sitio donde no lo está
- **THEN** la pestaña Diagnóstico muestra el aviso y los fragmentos como si el plugin estuviera activo, y el informe de una simulación ejecutada en ese estado registra Cache Enabler como caché de página

#### Scenario: Solo fragmentos cuando la aplicación automática no está disponible
- **WHEN** Cache Enabler está activo y el entorno no se detecta como compatible, o `.htaccess` no es escribible, o el sitio es multisitio
- **THEN** el aviso muestra los tres fragmentos sin ninguna comprobación de capacidad, sin estado del bloque y sin el botón «Aplicar automáticamente»

#### Scenario: Aviso en la pestaña Señales
- **WHEN** Cache Enabler está activo y un administrador abre la pestaña Señales
- **THEN** ve un aviso breve que indica que el HTML cacheado no lleva `Content-Signal`, `Content-Usage` ni `X-Robots-Tag` y enlaza a la pestaña Diagnóstico; sin Cache Enabler, el aviso no aparece

## ADDED Requirements

### Requirement: Aplicación automática del bloque `.htaccess`
Cuando Cache Enabler esté activo, el sitio no sea multisitio, el entorno se detecte como compatible y el archivo `.htaccess` sea escribible, la pestaña Diagnóstico SHALL mostrar junto al fragmento de `.htaccess`: el resultado positivo de la comprobación (servidor detectado, presencia de `mod_headers` cuando pueda comprobarse, ruta del archivo), el estado del bloque (no aplicado; aplicado y al día; o aplicado con un contenido distinto del que el plugin escribiría ahora) y un botón «Aplicar automáticamente» (o «Actualizar el bloque» cuando ya está aplicado) en un formulario de primer nivel hacia `admin-post.php` con su nonce, nunca anidado dentro de otro formulario. La comprobación de entorno SHALL considerar compatible: Apache cuando `apache_get_modules()` esté disponible y liste `mod_headers` y la versión, si es reconocible, no sea inferior a 2.4.7; Apache identificado por `SERVER_SOFTWARE` cuando `apache_get_modules()` no exista (PHP-FPM o CGI), con versión no inferior a 2.4.7 o sin versión reconocible; y LiteSpeed identificado por `SERVER_SOFTWARE` salvo que la edición anunciada por el servidor identifique OpenLiteSpeed. Cualquier otro servidor, OpenLiteSpeed y la ausencia de `mod_headers` en la lista de módulos SHALL considerarse no compatibles. El archivo SHALL ser el `.htaccess` de la raíz de instalación de WordPress y SHALL considerarse escribible cuando exista y sea escribible, o cuando no exista y su directorio sea escribible. La ruta del archivo y el resultado de la comprobación de entorno SHALL poder sustituirse mediante filtros, para tests y terceros.

La acción MUST requerir `manage_options` y nonce válido y SHALL ejecutar, en este orden y en la misma petición: (1) repetir la comprobación de entorno y de escribibilidad, y abortar sin escribir si alguna falla; (2) guardar una copia de seguridad del contenido actual del archivo, o registrar que no existía, en el almacenamiento del plugin, conservando solo la copia más reciente, y abortar sin escribir si la copia no puede guardarse; (3) escribir el bloque de `.htaccess` entre los marcadores `# BEGIN WP Agent Support Layer` y `# END WP Agent Support Layer` con el mecanismo de marcadores de WordPress, reemplazándolo en su sitio si ya existe y añadiéndolo al final del archivo si no, sin modificar, reordenar ni eliminar ninguna otra línea; (4) verificar con una única petición HTTP `GET` desde el servidor a la URL canónica de la entrada publicada de muestra (o la portada si no hay ninguna) en el mismo host, con un parámetro que evite cachés intermedias, con `Accept: text/html`, sin seguir redirecciones y con tiempo límite, considerando la verificación correcta solo con HTTP 200 y las cabeceras `X-WPASL-Headers: htaccess` y `Content-Signal` presentes; (5) si la verificación falla por cualquier motivo (error de conexión, tiempo agotado, código distinto de 200, redirección, cabecera ausente), restaurar el archivo desde la copia de seguridad (contenido íntegro original, o eliminación del archivo si no existía) y mostrar un aviso de error que nombre la fase y el motivo e indique que no se conservó ningún cambio; si la restauración fallara, el aviso MUST decirlo e indicar dónde está la copia de seguridad. Con verificación correcta la pestaña SHALL mostrar un aviso de éxito y el estado «aplicado y al día». La escritura MUST NOT ocurrir en ningún otro momento ni por ninguna otra ruta del plugin, MUST NOT tocar ningún otro archivo ni la configuración del servidor, y MUST NOT repetirse automáticamente cuando cambien los ajustes: un bloque desactualizado solo se actualiza pulsando de nuevo el botón.

#### Scenario: Entorno compatible y archivo escribible
- **WHEN** Cache Enabler está activo, la comprobación de entorno devuelve Apache con `mod_headers`, `.htaccess` es escribible y un administrador abre la pestaña Diagnóstico
- **THEN** junto al fragmento de `.htaccess` ve el resultado de la comprobación con la ruta del archivo, el estado «no aplicado» y el botón «Aplicar automáticamente» dentro de un formulario de primer nivel con nonce, y el HTML no contiene ningún formulario abierto dentro de otro

#### Scenario: Sin permisos o sin nonce
- **WHEN** un usuario sin `manage_options`, o un administrador sin nonce válido, envía la acción de aplicación
- **THEN** la acción se rechaza, no se realiza ninguna petición HTTP y el archivo `.htaccess` no cambia

#### Scenario: Aplicación correcta
- **WHEN** un administrador pulsa «Aplicar automáticamente» en un entorno compatible y la petición de verificación responde 200 con `X-WPASL-Headers: htaccess` y `Content-Signal`
- **THEN** existe una copia de seguridad con el contenido previo del archivo, el archivo contiene el bloque entre `# BEGIN WP Agent Support Layer` y `# END WP Agent Support Layer` con las mismas líneas que el fragmento copiable, el contenido previo permanece intacto fuera de los marcadores, se realizó exactamente una petición de verificación al propio host con `Accept: text/html` y sin seguir redirecciones, la página vuelve a la pestaña Diagnóstico con el aviso de éxito y el estado es «aplicado y al día»

#### Scenario: Bloque ya presente con valores anteriores
- **WHEN** `.htaccess` contiene ya un bloque entre los marcadores del plugin con un `Content-Signal` distinto del configurado y líneas ajenas antes y después, y un administrador pulsa «Actualizar el bloque»
- **THEN** antes de la acción el estado mostrado es «aplicado con valores distintos», tras la acción el bloque queda reemplazado en la misma posición con los valores actuales, las líneas ajenas antes y después son idénticas byte a byte y solo existe un bloque con los marcadores

#### Scenario: Verificación sin la cabecera marcador
- **WHEN** un administrador pulsa «Aplicar automáticamente» y la petición de verificación responde 200 con `Content-Signal` pero sin `X-WPASL-Headers`
- **THEN** el archivo queda con el contenido original byte a byte, la pestaña muestra un aviso de error que indica la fase de verificación, la cabecera ausente y que no se conservó ningún cambio, y el estado vuelve a «no aplicado»

#### Scenario: Verificación con error, tiempo agotado o código distinto de 200
- **WHEN** la petición de verificación devuelve un error de conexión, agota el tiempo límite, responde 500 o responde con una redirección
- **THEN** el archivo queda con el contenido original byte a byte y la pestaña muestra un aviso de error con el motivo recibido y la indicación de que no se conservó ningún cambio

#### Scenario: Archivo inexistente restaurado
- **WHEN** `.htaccess` no existe, su directorio es escribible y la verificación falla tras escribir el bloque
- **THEN** el archivo creado se elimina y la pestaña muestra el aviso de error

#### Scenario: Copia de seguridad imposible
- **WHEN** la copia de seguridad no puede guardarse en el almacenamiento del plugin
- **THEN** la acción aborta sin escribir en `.htaccess`, sin realizar la petición de verificación y con un aviso de error que nombra la fase de copia de seguridad

#### Scenario: Solo se conserva la copia más reciente
- **WHEN** un administrador aplica el bloque dos veces
- **THEN** existe una única copia de seguridad, correspondiente al contenido del archivo justo antes de la segunda aplicación

#### Scenario: Ninguna escritura fuera de la acción
- **WHEN** en un entorno compatible se activa el plugin, se guardan los ajustes de cualquier pestaña, se ejecuta una generación, se ejecuta la simulación de rastreo y se renderiza la pestaña Diagnóstico, sin pulsar el botón
- **THEN** el archivo `.htaccess` no se modifica y no existe ninguna copia de seguridad nueva

#### Scenario: Ajustes cambiados tras aplicar
- **WHEN** el bloque está aplicado y al día y un administrador cambia `ai-train` a `yes` en la pestaña Señales
- **THEN** el archivo `.htaccess` no cambia y la pestaña Diagnóstico muestra el estado «aplicado con valores distintos» con el botón «Actualizar el bloque»

#### Scenario: Multisitio
- **WHEN** el sitio es multisitio, Cache Enabler está activo y la comprobación de entorno devolvería compatible
- **THEN** la pestaña Diagnóstico no muestra la comprobación de capacidad ni el botón, y la acción de aplicación, si se envía, aborta sin escribir

#### Scenario: Detección de entorno y ruta sustituidas por filtro
- **WHEN** un filtro devuelve un entorno compatible y otro filtro apunta el archivo a una ruta temporal
- **THEN** la comprobación, el estado, la acción, la copia de seguridad y la restauración operan sobre esa ruta y nunca sobre el `.htaccess` real de la instalación
