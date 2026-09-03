## MODIFIED Requirements

### Requirement: Publicación de /agent-skills.json
El sistema SHALL responder en `/agent-skills.json` con `Content-Type: application/ld+json; charset=utf-8` y un documento JSON-LD válido que incluya `@context`, el nombre, la URL y la descripción del sitio, el editor con el correo de contacto configurado, la versión del manifiesto y una lista `capabilities`. Cada capacidad SHALL declarar identificador, nombre, descripción, método HTTP, URL absoluta o plantilla de URL, parámetros con su tipo y si son obligatorios, y `authentication: none`. Las plantillas de URL SHALL seguir RFC 6570 y, cuando una variable represente una ruta con varios segmentos, SHALL usar la expansión reservada (`{+path}`) para que la expansión no codifique las barras. El documento SHALL responder solo en su ruta exacta; las variantes con barra final o barras dobles MUST responder como cualquier ruta inexistente. El manifiesto SHALL poder deshabilitarse desde ajustes, en cuyo caso `/agent-skills.json` MUST responder 404.

#### Scenario: Manifiesto por defecto
- **WHEN** un cliente solicita `/agent-skills.json` con el manifiesto habilitado
- **THEN** la respuesta es 200, JSON válido, con `@context`, `capabilities` no vacío y correo de contacto

#### Scenario: Manifiesto deshabilitado
- **WHEN** el manifiesto está deshabilitado
- **THEN** `/agent-skills.json` responde 404

#### Scenario: Plantilla de lectura en Markdown
- **WHEN** se expande la plantilla de la capacidad de lectura en Markdown con `path = parent/child`
- **THEN** la URL resultante es `https://example.com/parent/child.md`

#### Scenario: Ruta no canónica del manifiesto
- **WHEN** un cliente solicita `/agent-skills.json/`
- **THEN** la respuesta es 404

### Requirement: Documento OpenAPI 3.1
El sistema SHALL responder en `/wp-json/wpasl/v1/openapi` con un documento OpenAPI 3.1 válido en JSON que describa, solo para métodos GET públicos, las rutas REST de los post types habilitados, la búsqueda y las rutas propias del plugin, con `info.contact` derivado del correo de contacto configurado y `servers` apuntando a la URL base de la API REST del sitio. La URL de `servers` MUST NOT contener cadena de consulta: con enlaces permanentes simples, `servers[0].url` SHALL ser la URL del sitio y las claves de `paths` SHALL incluir el prefijo `/?rest_route=` necesario para alcanzar cada ruta. La respuesta SHALL incluir las cabeceras de señales de contenido definidas en la capacidad correspondiente.

#### Scenario: Documento válido
- **WHEN** un cliente solicita el documento OpenAPI
- **THEN** la respuesta es JSON con `openapi` igual a `3.1.0`, `info`, `servers` y `paths` no vacío

#### Scenario: Solo métodos GET
- **WHEN** el documento describe la ruta de entradas
- **THEN** la ruta solo declara la operación `get`

#### Scenario: Enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita el documento OpenAPI
- **THEN** `servers[0].url` no contiene `?` y la ruta de entradas es alcanzable concatenando `servers[0].url` con su clave en `paths`

### Requirement: Catálogo de API en /.well-known/api-catalog
El sistema SHALL responder en `/.well-known/api-catalog` con `Content-Type: application/linkset+json`, exactamente ese valor y sin parámetros, y un linkset conforme a RFC 9727 que enlace al documento OpenAPI con la relación `service-desc` y a la documentación del sitio con `service-doc`. El catálogo SHALL responder solo en su ruta exacta.

#### Scenario: Catálogo disponible
- **WHEN** un cliente solicita `/.well-known/api-catalog`
- **THEN** la respuesta es 200 con `Content-Type: application/linkset+json` y contiene un enlace `service-desc` al documento OpenAPI

#### Scenario: Ruta no canónica del catálogo
- **WHEN** un cliente solicita `/.well-known/api-catalog/`
- **THEN** la respuesta es 404
