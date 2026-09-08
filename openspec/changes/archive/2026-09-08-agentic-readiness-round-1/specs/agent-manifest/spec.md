## MODIFIED Requirements

### Requirement: Catálogo de API en /.well-known/api-catalog
El sistema SHALL responder en `/.well-known/api-catalog` con `Content-Type: application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"` y un linkset conforme a RFC 9727 con dos entradas: la primera anclada en la URL absoluta del propio catálogo (`/.well-known/api-catalog`) con la relación `item` cuyo único destino es la URL base de la API REST del sitio; la segunda anclada en esa URL base de la API REST con la relación `service-desc` hacia el documento OpenAPI (`type: application/openapi+json`) y la relación `service-doc` hacia la documentación del sitio. Cuando la publicación de `auth.md` esté activa, `service-doc` SHALL incluir la URL absoluta de `/auth.md` con `type: text/markdown`; cuando esté desactivada, MUST NOT incluirla. Las URLs de `anchor` e `item` SHALL ser absolutas y coincidir con las que el sitio sirve, también con enlaces permanentes simples. El catálogo SHALL responder solo en su ruta exacta.

#### Scenario: Catálogo disponible
- **WHEN** un cliente solicita `/.well-known/api-catalog`
- **THEN** la respuesta es 200 con `Content-Type: application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"`, `linkset[0].anchor` es la URL absoluta de `/.well-known/api-catalog`, `linkset[0].item[0].href` es la URL base de la API REST, `linkset[1].anchor` es esa misma URL base y `linkset[1].service-desc[0].href` es la URL del documento OpenAPI con `type: application/openapi+json`

#### Scenario: Ruta no canónica del catálogo
- **WHEN** un cliente solicita `/.well-known/api-catalog/`
- **THEN** la respuesta es 404

#### Scenario: auth.md en el catálogo
- **WHEN** la publicación de `auth.md` está activa y un cliente solicita `/.well-known/api-catalog`
- **THEN** `linkset[1].service-doc` contiene `llms.txt`, `auth.md` con `type: text/markdown` y `agent-skills.json`, y `linkset[0]` no contiene `service-desc` ni `service-doc`

#### Scenario: auth.md desactivado fuera del catálogo
- **WHEN** la publicación de `auth.md` está desactivada y un cliente solicita `/.well-known/api-catalog`
- **THEN** ningún enlace de `service-doc` apunta a `/auth.md`

#### Scenario: Enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita `/.well-known/api-catalog`
- **THEN** `linkset[0].item[0].href` y `linkset[1].anchor` son la URL base de la API REST en la forma `?rest_route=` que el sitio sirve

### Requirement: Documento OpenAPI 3.1
El sistema SHALL responder en `/wp-json/wpasl/v1/openapi` con un documento OpenAPI 3.1 válido en JSON que describa, solo para métodos GET públicos, las rutas REST de los post types habilitados, la búsqueda y las rutas propias del plugin, con `info.contact` derivado del correo de contacto configurado y `servers` apuntando a la URL base de la API REST del sitio. La URL de `servers` MUST NOT contener cadena de consulta: con enlaces permanentes simples, `servers[0].url` SHALL ser la URL del sitio y las claves de `paths` SHALL incluir el prefijo `/?rest_route=` necesario para alcanzar cada ruta. La respuesta SHALL incluir las cabeceras de señales de contenido definidas en la capacidad correspondiente.

El documento SHALL declarar `components.schemas.Error` con la forma real de los errores de la API REST de WordPress: un objeto con `code` (cadena) y `message` (cadena) obligatorios y `data` (objeto con `status` entero y propiedades adicionales permitidas). Toda operación SHALL declarar, además de `200`, las respuestas `400`, `404` y `default` con contenido `application/json` cuyo esquema referencie `#/components/schemas/Error`. El documento MUST NOT declarar el formato RFC 9457 (`application/problem+json`), que el sitio no emite.

`info.description` SHALL contener, en inglés, la política de versionado y deprecación real de la API: la API pública es la API REST de WordPress organizada en espacios de nombres versionados, enumerando los espacios de nombres que realmente aparecen en `paths` (por ejemplo `wp/v2` y `wpasl/v1`); los cambios siguen el ciclo de versiones de WordPress y del plugin; el sitio no emite cabeceras `Deprecation` ni `Sunset`; los cambios incompatibles en las rutas del plugin se anuncian en su changelog y los de las rutas del núcleo en las notas de versión de WordPress. El texto MUST NOT declarar compromisos de soporte, plazos de retirada ni cabeceras que el sitio no cumpla o no emita.

#### Scenario: Documento válido
- **WHEN** un cliente solicita el documento OpenAPI
- **THEN** la respuesta es JSON con `openapi` igual a `3.1.0`, `info`, `servers`, `paths` no vacío y `components.schemas.Error`

#### Scenario: Solo métodos GET
- **WHEN** el documento describe la ruta de entradas
- **THEN** la ruta solo declara la operación `get`

#### Scenario: Enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita el documento OpenAPI
- **THEN** `servers[0].url` no contiene `?` y la ruta de entradas es alcanzable concatenando `servers[0].url` con su clave en `paths`

#### Scenario: Modelo de error tipado
- **WHEN** un cliente obtiene el documento OpenAPI
- **THEN** `components.schemas.Error` es un objeto con `code` y `message` de tipo `string` obligatorios y `data` de tipo `object` con `status` de tipo `integer`, y la operación `get` de la ruta de entradas declara las respuestas `400`, `404` y `default` con `content.application/json.schema.$ref` igual a `#/components/schemas/Error`

#### Scenario: Sin RFC 9457
- **WHEN** un cliente obtiene el documento OpenAPI
- **THEN** el documento no contiene `application/problem+json`

#### Scenario: Política de versionado en la descripción
- **WHEN** `post` está habilitado y expuesto en REST y un cliente obtiene el documento OpenAPI
- **THEN** `info.description` menciona `wp/v2` y `wpasl/v1`, indica que no se emiten cabeceras `Deprecation` ni `Sunset` y remite al changelog del plugin para los cambios incompatibles

### Requirement: Contenido de auth.md
El documento `auth.md` SHALL estar redactado en inglés, SHALL ser autocontenido y MUST describir únicamente lo que el sitio ofrece realmente. SHALL comenzar con un encabezado de nivel 1 formado por el nombre del sitio seguido de `auth.md`, y SHALL contener, en secciones de nivel 2: la audiencia (agentes de IA, asistentes basados en modelos de lenguaje y crawlers); cuando el administrador haya escrito la guía "cuándo usar este sitio" en la pestaña llms.txt, una sección `When to use this site` con ese Markdown tal cual, inmediatamente después de la audiencia, y cuando no, esa sección MUST NOT aparecer; una declaración explícita de que el sitio no ofrece registro de agentes ni aprovisionamiento de credenciales, de que no existe servidor de autorización y de que no debe intentarse ningún registro; los métodos de acceso soportados, que SHALL ser únicamente peticiones HTTP `GET` anónimas sin credencial, con la lista de endpoints y recursos públicos declarados en el registro de capacidades (nombre, método, URL absoluta o plantilla de URL y descripción) y las URLs reales de `llms.txt`, del catálogo de API, de `agent-skills.json`, del documento OpenAPI y la forma de obtener cualquier contenido en Markdown (sufijo `.md` o `?wpasl=md` según la estructura de enlaces permanentes, y `Accept: text/markdown`); el uso de credenciales, indicando que no se necesita ni se acepta ninguna para los recursos anteriores y que las peticiones autenticadas o de escritura no se ofrecen a agentes y quedan sujetas a las reglas normales de WordPress; una sección `API versioning and deprecation` con la misma política que `info.description` del documento OpenAPI (espacios de nombres versionados reales, ciclo de versiones de WordPress y del plugin, sin cabeceras `Deprecation` ni `Sunset`, cambios incompatibles anunciados en el changelog del plugin); la política de uso con los valores vigentes de las señales de contenido y la URL de `robots.txt`; y el contacto técnico con el correo configurado o, en su defecto, el correo del administrador del sitio. Cuando el administrador haya escrito notas, SHALL aparecer una sección de nivel 2 con ese Markdown tal cual; cuando no, esa sección MUST NOT aparecer. Todas las URLs SHALL ser absolutas y SHALL coincidir con las que el sitio sirve. El documento MUST NOT contener metadatos de autorización de terceros, ningún bloque `agent_auth`, ninguna referencia a contraseñas de aplicación de WordPress ni la palabra `OAuth`. Un desarrollador SHALL poder modificar el Markdown resultante mediante un filtro.

#### Scenario: Estructura por defecto
- **WHEN** el sitio se llama "Cognos Online", tiene enlaces permanentes bonitos, `post` y `page` habilitados y expuestos en REST, y un cliente obtiene `/auth.md`
- **THEN** el documento empieza por `# Cognos Online auth.md`, declara que no hay registro ni credenciales para agentes, lista la búsqueda, el listado y la lectura de `post` y `page` con sus URLs REST absolutas, la lectura en Markdown con la plantilla `{+path}.md`, `llms.txt`, el catálogo de API, `agent-skills.json` y el documento OpenAPI con sus URLs reales, contiene la sección `## API versioning and deprecation` mencionando `wp/v2` y `wpasl/v1`, indica los valores de las señales de contenido y termina con el contacto técnico

#### Scenario: Correo de contacto
- **WHEN** el administrador no ha configurado un correo de contacto
- **THEN** la sección de contacto muestra el correo del administrador del sitio; cuando lo ha configurado, muestra ese correo

#### Scenario: Notas del administrador
- **WHEN** el administrador ha escrito "Rate limit: 60 requests per minute." en las notas y un cliente obtiene `/auth.md`
- **THEN** el documento contiene una sección de nivel 2 con ese texto; con las notas vacías, esa sección no existe

#### Scenario: Guía "cuándo usar este sitio" en auth.md
- **WHEN** el administrador ha escrito "Use this site for official course descriptions." en la guía "cuándo usar este sitio" y un cliente obtiene `/auth.md`
- **THEN** el documento contiene `## When to use this site` seguido de ese texto, situado después de `## Audience` y antes de `## Registration and credential provisioning`; con la guía vacía, esa sección no existe

#### Scenario: Política de versionado y deprecación
- **WHEN** un cliente obtiene `/auth.md`
- **THEN** el documento contiene `## API versioning and deprecation`, la sección menciona los espacios de nombres versionados reales, dice que no se emiten cabeceras `Deprecation` ni `Sunset` y remite al changelog del plugin, y no promete plazos de soporte ni de retirada

#### Scenario: Sin credenciales ni autorización de terceros
- **WHEN** un cliente obtiene `/auth.md`
- **THEN** el documento no contiene la palabra `OAuth`, ni `agent_auth`, ni ninguna mención a contraseñas de aplicación, y declara que no se acepta credencial alguna

#### Scenario: Enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente obtiene `/auth.md`
- **THEN** la lectura en Markdown se describe con `?wpasl=md` y las URLs REST usan la forma `?rest_route=` que el sitio sirve

#### Scenario: Filtro sobre el documento
- **WHEN** un desarrollador añade una sección mediante el filtro del documento
- **THEN** `/auth.md` sirve el documento con esa sección

## ADDED Requirements

### Requirement: Enlaces de descubrimiento en el HTML y shortcode
Cuando los manifiestos estén habilitados, la respuesta HTML de toda página pública del sitio SHALL incluir en `<head>` los elementos `<link rel="service-desc" type="application/openapi+json" href="<URL del documento OpenAPI>">`, `<link rel="api-catalog" type="application/linkset+json" href="<URL absoluta de /.well-known/api-catalog>">` y `<link rel="describedby" type="text/markdown" href="<URL absoluta de /llms.txt>">`, y, cuando la publicación de `auth.md` esté activa, `<link rel="service-doc" type="text/markdown" href="<URL absoluta de /auth.md>">`. Todas las relaciones SHALL ser relaciones registradas en el registro de relaciones de enlace de IANA. Cuando los manifiestos estén deshabilitados, MUST NOT emitirse ninguno de estos elementos. Los elementos MUST NOT emitirse en la administración. El sistema SHALL registrar el shortcode `[wpasl_agent_links]`, que SHALL imprimir una lista HTML (`ul` con clase `wpasl-agent-links`) de enlaces con texto en inglés traducible a `auth.md` (cuando esté publicado), `llms.txt`, el catálogo de API y el documento OpenAPI, y que SHALL devolver una cadena vacía cuando los manifiestos estén deshabilitados. Un desarrollador SHALL poder modificar la lista de enlaces mediante un filtro compartido por el `<head>` y el shortcode.

#### Scenario: Enlaces en la portada
- **WHEN** los manifiestos están habilitados, `auth.md` está publicado y un cliente obtiene la portada en HTML
- **THEN** el `<head>` contiene los cuatro elementos `link` con las relaciones `service-desc`, `api-catalog`, `describedby` y `service-doc` y sus URLs absolutas reales

#### Scenario: Enlaces en una página interior
- **WHEN** los manifiestos están habilitados y un cliente obtiene la página HTML de una entrada
- **THEN** el `<head>` contiene los elementos `link` de descubrimiento además del `link rel="alternate" type="text/markdown"` de la entrada

#### Scenario: auth.md desactivado
- **WHEN** la publicación de `auth.md` está desactivada y un cliente obtiene la portada
- **THEN** el `<head>` no contiene ningún `link` hacia `/auth.md` y sí los otros tres

#### Scenario: Manifiestos deshabilitados
- **WHEN** los manifiestos están deshabilitados y un cliente obtiene la portada
- **THEN** el `<head>` no contiene ningún `link` con relación `service-desc`, `api-catalog`, `describedby` ni `service-doc`, y `[wpasl_agent_links]` devuelve una cadena vacía

#### Scenario: Shortcode
- **WHEN** los manifiestos están habilitados, `auth.md` está publicado y una página contiene `[wpasl_agent_links]`
- **THEN** el HTML resultante contiene un `ul` con clase `wpasl-agent-links` y cuatro enlaces cuyos `href` son las URLs absolutas de `/auth.md`, `/llms.txt`, `/.well-known/api-catalog` y el documento OpenAPI, con las URLs escapadas
