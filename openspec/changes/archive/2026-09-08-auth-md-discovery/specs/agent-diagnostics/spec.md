## MODIFIED Requirements

### Requirement: Ejecución de la prueba de rastreo
La página de diagnóstico SHALL ofrecer una acción que realice peticiones HTTP desde el servidor a URLs del propio sitio (portada, una entrada elegible de muestra, su URL `.md`, `/robots.txt`, `/llms.txt`, `/auth.md`, `/agent-skills.json` y `/.well-known/api-catalog`) usando los user-agents del catálogo de crawlers y las cabeceras `Accept: text/markdown` y `Accept: text/html`. Las comprobaciones de sitio (`/llms.txt`, `/auth.md`, `/agent-skills.json`, `/.well-known/api-catalog`, la sonda de almacenamiento) SHALL realizarse una vez con el user-agent del plugin; por cada crawler del catálogo SHALL solicitarse, con su user-agent, la portada, la entrada de muestra con `Accept: text/html`, la entrada de muestra con `Accept: text/markdown`, la URL `.md` de muestra y `/robots.txt`. La comprobación de `/auth.md` SHALL considerarse correcta con HTTP 200 y `Content-Type` `text/markdown`, advertencia con otro `Content-Type` o una redirección, y error con cualquier otro código. Todas las peticiones de la prueba MUST ser `GET`; la prueba MUST NOT sondear ningún endpoint de registro, aprovisionamiento ni autenticación. La acción MUST requerir `manage_options` y nonce válido, MUST limitar cada petición a 5 segundos, MUST NOT seguir redirecciones, MUST limitar el tamaño de cada respuesta leída y MUST NOT contactar ningún host distinto del propio sitio. La prueba SHALL ejecutarse en lotes repartidos en varias peticiones del navegador encadenadas automáticamente: cada petición del administrador MUST terminar dentro de un presupuesto de tiempo configurable por filtro (por defecto 30 segundos) y, si quedan crawlers por sondear, MUST guardar el progreso y continuar en la siguiente petición sin intervención del administrador ni JavaScript. Ninguna petición del administrador MUST superar los 100 segundos. El informe SHALL publicarse solo cuando el último lote termine; un progreso interrumpido MUST descartarse al cabo de una hora.

#### Scenario: Ejecución completa
- **WHEN** un administrador lanza la prueba
- **THEN** se obtiene un resultado por cada comprobación de sitio y, por cada crawler, un resultado para la portada, la entrada de muestra en HTML y en Markdown negociado, la URL `.md` de muestra y `/robots.txt`, con código HTTP, `Content-Type` y cabeceras relevantes

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

#### Scenario: Sin peticiones de registro
- **WHEN** un administrador lanza la prueba completa
- **THEN** todas las peticiones realizadas son `GET` a la portada, la entrada de muestra, `/robots.txt`, `/llms.txt`, `/auth.md`, `/agent-skills.json`, `/.well-known/api-catalog` y la sonda de almacenamiento, y ninguna apunta a un endpoint de registro o autenticación

### Requirement: Lista de verificación de infraestructura y comandos externos
La página SHALL mostrar una lista de verificación estática para la auditoría de WAF, rate-limiting y filtrado de IP orientada a crawlers verificados, que SHALL mencionar `auth.md` entre los archivos de descubrimiento que no deben cachearse ni transformarse, y SHALL mostrar comandos `curl` equivalentes a las pruebas realizadas, incluido uno para `/auth.md`, para que puedan repetirse desde fuera del servidor. Los comandos SHALL ser seguros de pegar en una shell POSIX: la URL y el user-agent SHALL ir citados de forma que ninguna comilla, espacio o carácter especial presente en ellos rompa el comando.

#### Scenario: Comandos disponibles
- **WHEN** un administrador abre la página de diagnóstico
- **THEN** ve la lista de verificación y al menos un comando `curl` por crawler con su user-agent y la cabecera `Accept` correspondiente

#### Scenario: Comando con comilla en el user-agent
- **WHEN** el catálogo contiene, por filtro, un user-agent con una comilla simple en su nombre
- **THEN** el comando `curl` mostrado sigue siendo un comando válido que envía ese user-agent literal

#### Scenario: Comando para auth.md
- **WHEN** un administrador abre la página de diagnóstico
- **THEN** la sección "Repeat the checks from outside" contiene `curl -s '<URL absoluta de /auth.md>'` junto a los comandos de `robots.txt`, `llms.txt`, `agent-skills.json` y el catálogo
