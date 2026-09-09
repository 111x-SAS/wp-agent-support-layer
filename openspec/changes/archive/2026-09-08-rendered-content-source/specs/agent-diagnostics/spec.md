## MODIFIED Requirements

### Requirement: Ejecución de la prueba de rastreo
La página de diagnóstico SHALL ofrecer una acción que realice peticiones HTTP desde el servidor a URLs del propio sitio (portada, una entrada elegible de muestra, su URL `.md`, su URL de renderizado, `/robots.txt`, `/llms.txt`, cada `/llms-<post_type>.txt` de los post types habilitados, `/auth.md`, `/agent-skills.json` y `/.well-known/api-catalog`) usando los user-agents del catálogo de crawlers y las cabeceras `Accept: text/markdown` y `Accept: text/html`. Las comprobaciones de sitio (`/llms.txt`, los archivos por tipo, `/auth.md`, `/agent-skills.json`, `/.well-known/api-catalog`, la sonda de almacenamiento, la URL de renderizado de la entrada de muestra) SHALL realizarse una vez con el user-agent del plugin; por cada crawler del catálogo SHALL solicitarse, con su user-agent, la portada, la entrada de muestra con `Accept: text/html`, la entrada de muestra con `Accept: text/markdown`, la URL `.md` de muestra y `/robots.txt`. La comprobación de `/auth.md` y la de cada archivo por tipo SHALL considerarse correctas con HTTP 200 y `Content-Type` `text/markdown`, advertencia con otro `Content-Type` o una redirección, y error con cualquier otro código. La comprobación de `/llms.txt` SHALL registrar además el tamaño del cuerpo recibido y SHALL marcarse como advertencia cuando supere 30.000 caracteres, indicando el tamaño y recomendando reducir el límite de vista previa. La comprobación del catálogo SHALL aceptar el `Content-Type` `application/linkset+json` con o sin parámetro `profile`. La comprobación de la URL de renderizado SHALL solicitar la URL canónica de la entrada de muestra con la cabecera y el parámetro de renderizado y `Accept: text/html`, SHALL considerarse correcta con HTTP 200, `Content-Type` `text/html` y una región de contenido con texto detectada con la misma extracción que usa la generación (indicando el selector que coincidió), advertencia con HTTP 200 y HTML sin región reconocible (el cuerpo entero se usaría como contenido) o con una redirección, y error con cualquier otro código, con `Content-Type` `text/markdown` (la marca de renderizado no se respeta) o con error de conexión, indicando en ese caso que el sitio no admite peticiones a sí mismo y que los ítems con origen `rendered` se servirán con el contenido del editor. Todas las peticiones de la prueba MUST ser `GET`; la prueba MUST NOT sondear ningún endpoint de registro, aprovisionamiento ni autenticación. La acción MUST requerir `manage_options` y nonce válido, MUST limitar cada petición a 5 segundos, MUST NOT seguir redirecciones, MUST limitar el tamaño de cada respuesta leída y MUST NOT contactar ningún host distinto del propio sitio. La prueba SHALL ejecutarse en lotes repartidos en varias peticiones del navegador encadenadas automáticamente: cada petición del administrador MUST terminar dentro de un presupuesto de tiempo configurable por filtro (por defecto 30 segundos) y, si quedan crawlers por sondear, MUST guardar el progreso y continuar en la siguiente petición sin intervención del administrador ni JavaScript. Ninguna petición del administrador MUST superar los 100 segundos. El informe SHALL publicarse solo cuando el último lote termine; un progreso interrumpido MUST descartarse al cabo de una hora.

#### Scenario: Ejecución completa
- **WHEN** un administrador lanza la prueba
- **THEN** se obtiene un resultado por cada comprobación de sitio, incluida una por cada archivo por tipo de los post types habilitados y una para la URL de renderizado de la entrada de muestra, y, por cada crawler, un resultado para la portada, la entrada de muestra en HTML y en Markdown negociado, la URL `.md` de muestra y `/robots.txt`, con código HTTP, `Content-Type` y cabeceras relevantes

#### Scenario: Sin permisos
- **WHEN** un usuario sin `manage_options` intenta lanzar la prueba
- **THEN** el sistema rechaza la acción

#### Scenario: Prueba dividida en lotes
- **WHEN** el presupuesto de tiempo se agota con crawlers pendientes
- **THEN** la petición actual guarda los resultados parciales y redirige a la siguiente petición, que continúa por el primer crawler pendiente hasta completar el informe

#### Scenario: Peticiones lentas
- **WHEN** cada URL sondeada tarda 5 segundos en responder
- **THEN** ninguna petición del administrador dura más del presupuesto configurado más la duración de un crawler, y el informe final contiene todos los crawlers

#### Scenario: Redirección en una URL sondeada
- **WHEN** la portada responde 301 hacia otra URL
- **THEN** el resultado registra el código 301 sin seguirlo y el informe marca la comprobación como advertencia

#### Scenario: Bloqueo por user-agent en la URL .md
- **WHEN** la infraestructura responde 403 a la URL `.md` de muestra solo cuando el user-agent es `ClaudeBot`
- **THEN** el informe marca la comprobación de la URL `.md` como error únicamente en ClaudeBot y como correcta en el resto de crawlers

#### Scenario: auth.md servido
- **WHEN** `/auth.md` responde 200 con `Content-Type: text/markdown; charset=utf-8`
- **THEN** el informe muestra la comprobación de sitio `auth.md` como correcta

#### Scenario: auth.md ausente
- **WHEN** `/auth.md` responde 404
- **THEN** el informe muestra la comprobación de sitio `auth.md` como error con el código HTTP recibido

#### Scenario: auth.md con tipo inesperado
- **WHEN** `/auth.md` responde 200 con `Content-Type: text/html`
- **THEN** el informe muestra la comprobación de sitio `auth.md` como advertencia indicando el tipo recibido y el esperado

#### Scenario: Archivos por tipo comprobados
- **WHEN** `post` y `page` están habilitados, `/llms-page.txt` responde 200 con `text/markdown` y `/llms-post.txt` responde 404
- **THEN** el informe muestra la comprobación de `llms-page.txt` como correcta y la de `llms-post.txt` como error con el código recibido, entre las comprobaciones de `llms.txt` y `auth.md`

#### Scenario: llms.txt demasiado grande
- **WHEN** `/llms.txt` responde 200 con `text/markdown` y un cuerpo de 82.000 caracteres
- **THEN** el informe muestra la comprobación de `llms.txt` como advertencia indicando el tamaño y los 30.000 caracteres recomendados; con un cuerpo de 20.000 caracteres la muestra como correcta

#### Scenario: Catálogo con profile
- **WHEN** `/.well-known/api-catalog` responde 200 con `Content-Type: application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"`
- **THEN** el informe muestra la comprobación del catálogo como correcta

#### Scenario: URL de renderizado correcta
- **WHEN** la URL de renderizado de la entrada de muestra responde 200 con `text/html` y un `main` con texto
- **THEN** la petición lleva la cabecera y el parámetro de renderizado y `Accept: text/html`, y el informe muestra la comprobación "Rendered page" como correcta indicando el selector `main`

#### Scenario: URL de renderizado sin región
- **WHEN** la URL de renderizado responde 200 con `text/html` sin ningún selector de la lista
- **THEN** el informe muestra la comprobación como advertencia indicando que se usará el cuerpo entero y recomendando configurar el selector CSS

#### Scenario: URL de renderizado bloqueada
- **WHEN** la URL de renderizado responde con error de conexión, 403, o 200 con `text/markdown`
- **THEN** el informe muestra la comprobación como error indicando que los ítems con origen `rendered` se servirán con el contenido del editor

#### Scenario: Sin peticiones de registro
- **WHEN** un administrador lanza la prueba completa
- **THEN** todas las peticiones realizadas son `GET` a la portada, la entrada de muestra, su URL de renderizado, `/robots.txt`, `/llms.txt`, los archivos por tipo, `/auth.md`, `/agent-skills.json`, `/.well-known/api-catalog` y la sonda de almacenamiento, y ninguna apunta a un endpoint de registro o autenticación

### Requirement: Lista de verificación de infraestructura y comandos externos
La página SHALL mostrar una lista de verificación estática para la auditoría de WAF, rate-limiting y filtrado de IP orientada a crawlers verificados, que SHALL mencionar `auth.md` y los archivos por tipo de `llms.txt` entre los archivos de descubrimiento que no deben cachearse ni transformarse, SHALL mencionar que el servidor debe admitir peticiones HTTP del propio sitio a sí mismo (loopback) para obtener las páginas renderizadas, y SHALL mostrar comandos `curl` equivalentes a las pruebas realizadas, incluido uno para `/auth.md`, uno por cada `/llms-<post_type>.txt` de los post types habilitados y uno para la URL de renderizado de la entrada de muestra con su cabecera, para que puedan repetirse desde fuera del servidor. Los comandos SHALL ser seguros de pegar en una shell POSIX: la URL y el user-agent SHALL ir citados de forma que ninguna comilla, espacio o carácter especial presente en ellos rompa el comando.

#### Scenario: Comandos disponibles
- **WHEN** un administrador abre la página de diagnóstico
- **THEN** ve la lista de verificación y al menos un comando `curl` por crawler con su user-agent y la cabecera `Accept` correspondiente

#### Scenario: Comando con comilla en el user-agent
- **WHEN** el catálogo contiene, por filtro, un user-agent con una comilla simple en su nombre
- **THEN** el comando `curl` mostrado sigue siendo un comando válido que envía ese user-agent literal

#### Scenario: Comando para auth.md
- **WHEN** un administrador abre la página de diagnóstico
- **THEN** la sección "Repeat the checks from outside" contiene `curl -s '<URL absoluta de /auth.md>'` junto a los comandos de `robots.txt`, `llms.txt`, `agent-skills.json` y el catálogo

#### Scenario: Comandos para los archivos por tipo
- **WHEN** `post` y `page` están habilitados y un administrador abre la página de diagnóstico
- **THEN** la sección "Repeat the checks from outside" contiene `curl -s '<URL absoluta de /llms-page.txt>'` y `curl -s '<URL absoluta de /llms-post.txt>'`

#### Scenario: Comando para la URL de renderizado
- **WHEN** existe una entrada elegible de muestra y un administrador abre la página de diagnóstico
- **THEN** la sección "Repeat the checks from outside" contiene un comando `curl -s -H 'X-WPASL-Render: 1' '<URL canónica de la muestra con el parámetro de renderizado>'`

## ADDED Requirements

### Requirement: Aviso de fallos de renderizado en la pestaña Diagnóstico
Cuando el estado de generación registre al menos un fallo de loopback de renderizado, la pestaña Diagnóstico SHALL mostrar, sin necesidad de ejecutar la simulación, un aviso que indique cuántos ítems tienen el fallo registrado, el motivo y la fecha del último fallo, las causas habituales (el servidor no admite peticiones a sí mismo, un WAF o un límite de peticiones bloquea el user-agent del plugin, una redirección de host o de esquema en la URL canónica, tiempo agotado) y que esos ítems se sirven con el contenido del editor hasta que el loopback funcione. Sin fallos registrados, el aviso MUST NOT mostrarse.

#### Scenario: Aviso con fallos registrados
- **WHEN** el estado registra dos ítems con fallo de renderizado, el último con motivo `redirect_external_host`, y un administrador abre la pestaña Diagnóstico
- **THEN** ve el aviso con "2", el motivo `redirect_external_host`, la fecha y las causas habituales

#### Scenario: Sin fallos registrados
- **WHEN** el estado no registra fallos de renderizado
- **THEN** la pestaña Diagnóstico no muestra el aviso
