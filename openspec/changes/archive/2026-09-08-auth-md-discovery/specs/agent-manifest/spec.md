## ADDED Requirements

### Requirement: Publicación de /auth.md
Cuando los manifiestos estén habilitados y la publicación de `auth.md` esté activa (por defecto lo está), el sistema SHALL responder en `/auth.md` con código 200, `Content-Type: text/markdown; charset=utf-8`, `X-Markdown-Tokens` con una estimación entera del número de tokens, `Cache-Control: public` con un `max-age` igual al intervalo de regeneración configurado, `X-Content-Type-Options: nosniff` y las cabeceras de señales de contenido que llevan los demás archivos de descubrimiento, sirviendo el documento almacenado o generándolo bajo demanda si no existe. El documento SHALL responder solo en su ruta exacta; las variantes con barra final o barras dobles (`/auth.md/`, `//auth.md`) MUST responder como cualquier ruta inexistente. Cuando exista un archivo `auth.md` físico en la raíz del sitio, el archivo físico SHALL prevalecer y la pestaña Manifiestos SHALL mostrar un aviso. Cuando la publicación de `auth.md` esté desactivada, o los manifiestos estén deshabilitados, `/auth.md` MUST responder 404. La ruta `/auth.md` SHALL estar reservada para este documento: MUST NOT resolver nunca a la versión Markdown de un contenido con slug `auth`, esté o no activa la publicación. La respuesta MUST NOT incluir la cabecera `X-Robots-Tag` con directivas `noai`.

#### Scenario: Petición a auth.md
- **WHEN** los manifiestos y la publicación de `auth.md` están habilitados y un cliente solicita `/auth.md`
- **THEN** la respuesta es 200 con `Content-Type: text/markdown; charset=utf-8`, `X-Markdown-Tokens`, `Cache-Control: public, max-age=<intervalo>`, `X-Content-Type-Options: nosniff` y `Content-Signal`, sin `X-Robots-Tag`

#### Scenario: Documento ausente
- **WHEN** no existe documento almacenado y un cliente solicita `/auth.md`
- **THEN** el sistema genera el documento, lo almacena y lo sirve; la siguiente petición sirve el documento almacenado sin regenerarlo

#### Scenario: Publicación desactivada
- **WHEN** la publicación de `auth.md` está desactivada y un cliente solicita `/auth.md`
- **THEN** la respuesta es 404

#### Scenario: Manifiestos deshabilitados
- **WHEN** los manifiestos están deshabilitados, la publicación de `auth.md` sigue marcada y un cliente solicita `/auth.md`
- **THEN** la respuesta es 404

#### Scenario: Archivo físico presente
- **WHEN** existe un `auth.md` físico en la raíz del sitio y un cliente solicita `/auth.md`
- **THEN** el sistema no sirve ningún documento (el servidor web entrega el archivo físico) y la pestaña Manifiestos muestra el aviso de archivo físico

#### Scenario: Ruta no canónica
- **WHEN** un cliente solicita `/auth.md/` o `//auth.md`
- **THEN** la respuesta es 404

#### Scenario: Contenido con slug auth y archivo raíz
- **WHEN** existe una página elegible con enlace permanente `/auth/` y un cliente solicita `/auth.md`
- **THEN** la respuesta es el documento `auth.md` del sitio, cuyo H1 contiene `auth.md`, y no el documento Markdown de la página

#### Scenario: Contenido con slug auth con la publicación desactivada
- **WHEN** existe una página elegible con enlace permanente `/auth/`, la publicación de `auth.md` está desactivada y un cliente solicita `/auth.md`
- **THEN** la respuesta es 404, mientras que `/auth/` con `Accept: text/markdown` y `/auth/?wpasl=md` responden 200 con el documento Markdown de la página

### Requirement: Contenido de auth.md
El documento `auth.md` SHALL estar redactado en inglés, SHALL ser autocontenido y MUST describir únicamente lo que el sitio ofrece realmente. SHALL comenzar con un encabezado de nivel 1 formado por el nombre del sitio seguido de `auth.md`, y SHALL contener, en secciones de nivel 2: la audiencia (agentes de IA, asistentes basados en modelos de lenguaje y crawlers); una declaración explícita de que el sitio no ofrece registro de agentes ni aprovisionamiento de credenciales, de que no existe servidor de autorización y de que no debe intentarse ningún registro; los métodos de acceso soportados, que SHALL ser únicamente peticiones HTTP `GET` anónimas sin credencial, con la lista de endpoints y recursos públicos declarados en el registro de capacidades (nombre, método, URL absoluta o plantilla de URL y descripción) y las URLs reales de `llms.txt`, del catálogo de API, de `agent-skills.json`, del documento OpenAPI y la forma de obtener cualquier contenido en Markdown (sufijo `.md` o `?wpasl=md` según la estructura de enlaces permanentes, y `Accept: text/markdown`); el uso de credenciales, indicando que no se necesita ni se acepta ninguna para los recursos anteriores y que las peticiones autenticadas o de escritura no se ofrecen a agentes y quedan sujetas a las reglas normales de WordPress; la política de uso con los valores vigentes de las señales de contenido y la URL de `robots.txt`; y el contacto técnico con el correo configurado o, en su defecto, el correo del administrador del sitio. Cuando el administrador haya escrito notas, SHALL aparecer una sección de nivel 2 con ese Markdown tal cual; cuando no, esa sección MUST NOT aparecer. Todas las URLs SHALL ser absolutas y SHALL coincidir con las que el sitio sirve. El documento MUST NOT contener metadatos de autorización de terceros, ningún bloque `agent_auth`, ninguna referencia a contraseñas de aplicación de WordPress ni la palabra `OAuth`. Un desarrollador SHALL poder modificar el Markdown resultante mediante un filtro.

#### Scenario: Estructura por defecto
- **WHEN** el sitio se llama "Cognos Online", tiene enlaces permanentes bonitos, `post` y `page` habilitados y expuestos en REST, y un cliente obtiene `/auth.md`
- **THEN** el documento empieza por `# Cognos Online auth.md`, declara que no hay registro ni credenciales para agentes, lista la búsqueda, el listado y la lectura de `post` y `page` con sus URLs REST absolutas, la lectura en Markdown con la plantilla `{+path}.md`, `llms.txt`, el catálogo de API, `agent-skills.json` y el documento OpenAPI con sus URLs reales, indica los valores de las señales de contenido y termina con el contacto técnico

#### Scenario: Correo de contacto
- **WHEN** el administrador no ha configurado un correo de contacto
- **THEN** la sección de contacto muestra el correo del administrador del sitio; cuando lo ha configurado, muestra ese correo

#### Scenario: Notas del administrador
- **WHEN** el administrador ha escrito "Rate limit: 60 requests per minute." en las notas y un cliente obtiene `/auth.md`
- **THEN** el documento contiene una sección de nivel 2 con ese texto; con las notas vacías, esa sección no existe

#### Scenario: Sin credenciales ni autorización de terceros
- **WHEN** un cliente obtiene `/auth.md`
- **THEN** el documento no contiene la palabra `OAuth`, ni `agent_auth`, ni ninguna mención a contraseñas de aplicación, y declara que no se acepta credencial alguna

#### Scenario: Enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente obtiene `/auth.md`
- **THEN** la lectura en Markdown se describe con `?wpasl=md` y las URLs REST usan la forma `?rest_route=` que el sitio sirve

#### Scenario: Filtro sobre el documento
- **WHEN** un desarrollador añade una sección mediante el filtro del documento
- **THEN** `/auth.md` sirve el documento con esa sección

## MODIFIED Requirements

### Requirement: Capacidades declaradas solo si existen y son públicas
El manifiesto SHALL incluir únicamente capacidades respaldadas por endpoints públicos sin autenticación: búsqueda de contenido, listado y lectura por cada post type habilitado expuesto en la API REST, lectura en Markdown, índice `llms.txt`, la documentación OpenAPI y, cuando esté publicado, el documento `auth.md` como recurso de tipo `text/markdown`. Un desarrollador SHALL poder añadir capacidades mediante un filtro. El sistema MUST NOT declarar endpoints que requieran autenticación ni post types no habilitados.

#### Scenario: Post type habilitado y expuesto en REST
- **WHEN** `post` está habilitado y expuesto en la API REST
- **THEN** el manifiesto incluye capacidades de listado y lectura para `post`

#### Scenario: Post type habilitado pero sin REST
- **WHEN** un post type habilitado no está expuesto en la API REST
- **THEN** el manifiesto no incluye capacidades REST para ese post type, aunque sí la lectura en Markdown

#### Scenario: Capacidad añadida por filtro
- **WHEN** un desarrollador añade una capacidad mediante el filtro
- **THEN** la capacidad aparece en el manifiesto con la estructura requerida

#### Scenario: auth.md declarado como capacidad
- **WHEN** la publicación de `auth.md` está activa y un cliente obtiene `/agent-skills.json`
- **THEN** `capabilities` contiene una entrada con método `GET`, `authentication: none`, `responseType: text/markdown` y la URL absoluta de `/auth.md`

#### Scenario: auth.md desactivado no se declara
- **WHEN** la publicación de `auth.md` está desactivada y un cliente obtiene `/agent-skills.json`
- **THEN** `capabilities` no contiene ninguna entrada que apunte a `/auth.md`

### Requirement: Catálogo de API en /.well-known/api-catalog
El sistema SHALL responder en `/.well-known/api-catalog` con `Content-Type: application/linkset+json`, exactamente ese valor y sin parámetros, y un linkset conforme a RFC 9727 que enlace al documento OpenAPI con la relación `service-desc` y a la documentación del sitio con `service-doc`. Cuando la publicación de `auth.md` esté activa, `service-doc` SHALL incluir la URL absoluta de `/auth.md` con `type: text/markdown`; cuando esté desactivada, MUST NOT incluirla. El catálogo SHALL responder solo en su ruta exacta.

#### Scenario: Catálogo disponible
- **WHEN** un cliente solicita `/.well-known/api-catalog`
- **THEN** la respuesta es 200 con `Content-Type: application/linkset+json` y contiene un enlace `service-desc` al documento OpenAPI

#### Scenario: Ruta no canónica del catálogo
- **WHEN** un cliente solicita `/.well-known/api-catalog/`
- **THEN** la respuesta es 404

#### Scenario: auth.md en el catálogo
- **WHEN** la publicación de `auth.md` está activa y un cliente solicita `/.well-known/api-catalog`
- **THEN** `service-doc` contiene `llms.txt`, `auth.md` con `type: text/markdown` y `agent-skills.json`

#### Scenario: auth.md desactivado fuera del catálogo
- **WHEN** la publicación de `auth.md` está desactivada y un cliente solicita `/.well-known/api-catalog`
- **THEN** ningún enlace de `service-doc` apunta a `/auth.md`

### Requirement: Coherencia con la generación programada
Los tres documentos JSON y `auth.md` SHALL servirse desde el almacenamiento generado por programación y regenerarse al final de cada ciclo; si no existen, SHALL generarse bajo demanda. Un cambio en ajustes que afecte a su contenido, incluidas la casilla de publicación y las notas de `auth.md`, SHALL invalidarlos para que se regeneren en la siguiente petición. Cuando la publicación de `auth.md` esté desactivada, la regeneración programada SHALL eliminar el documento almacenado.

#### Scenario: Cambio de correo de contacto
- **WHEN** un administrador cambia el correo de contacto
- **THEN** la siguiente petición a `/agent-skills.json` refleja el nuevo correo

#### Scenario: Cambio de notas de auth.md
- **WHEN** un administrador guarda notas nuevas en la pestaña Manifiestos
- **THEN** la siguiente petición a `/auth.md` contiene las notas nuevas sin esperar al ciclo programado

#### Scenario: Regeneración al final del ciclo
- **WHEN** termina un ciclo de generación con la publicación de `auth.md` activa
- **THEN** el almacenamiento contiene `auth.md` regenerado; con la publicación desactivada, el archivo no existe en el almacenamiento
