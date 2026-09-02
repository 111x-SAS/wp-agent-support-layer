## Purpose

Declara formalmente y de forma verificable qué puede hacer un agente sobre el sitio sin autenticación, mediante un manifiesto JSON-LD, un documento OpenAPI 3.1 y el catálogo de API estándar.

## ADDED Requirements

### Requirement: Publicación de /agent-skills.json
El sistema SHALL responder en `/agent-skills.json` con `Content-Type: application/ld+json; charset=utf-8` y un documento JSON-LD válido que incluya `@context`, el nombre, la URL y la descripción del sitio, el editor con el correo de contacto configurado, la versión del manifiesto y una lista `capabilities`. Cada capacidad SHALL declarar identificador, nombre, descripción, método HTTP, URL absoluta o plantilla de URL, parámetros con su tipo y si son obligatorios, y `authentication: none`. El manifiesto SHALL poder deshabilitarse desde ajustes, en cuyo caso `/agent-skills.json` MUST responder 404.

#### Scenario: Manifiesto por defecto
- **WHEN** un cliente solicita `/agent-skills.json` con el manifiesto habilitado
- **THEN** la respuesta es 200, JSON válido, con `@context`, `capabilities` no vacío y correo de contacto

#### Scenario: Manifiesto deshabilitado
- **WHEN** el manifiesto está deshabilitado
- **THEN** `/agent-skills.json` responde 404

### Requirement: Capacidades declaradas solo si existen y son públicas
El manifiesto SHALL incluir únicamente capacidades respaldadas por endpoints públicos sin autenticación: búsqueda de contenido, listado y lectura por cada post type habilitado expuesto en la API REST, lectura en Markdown, índice `llms.txt` y la documentación OpenAPI. Un desarrollador SHALL poder añadir capacidades mediante un filtro. El sistema MUST NOT declarar endpoints que requieran autenticación ni post types no habilitados.

#### Scenario: Post type habilitado y expuesto en REST
- **WHEN** `post` está habilitado y expuesto en la API REST
- **THEN** el manifiesto incluye capacidades de listado y lectura para `post`

#### Scenario: Post type habilitado pero sin REST
- **WHEN** un post type habilitado no está expuesto en la API REST
- **THEN** el manifiesto no incluye capacidades REST para ese post type, aunque sí la lectura en Markdown

#### Scenario: Capacidad añadida por filtro
- **WHEN** un desarrollador añade una capacidad mediante el filtro
- **THEN** la capacidad aparece en el manifiesto con la estructura requerida

### Requirement: Documento OpenAPI 3.1
El sistema SHALL responder en `/wp-json/wpasl/v1/openapi` con un documento OpenAPI 3.1 válido en JSON que describa, solo para métodos GET públicos, las rutas REST de los post types habilitados, la búsqueda y las rutas propias del plugin, con `info.contact` derivado del correo de contacto configurado y `servers` apuntando a la URL base de la API REST del sitio.

#### Scenario: Documento válido
- **WHEN** un cliente solicita el documento OpenAPI
- **THEN** la respuesta es JSON con `openapi` igual a `3.1.0`, `info`, `servers` y `paths` no vacío

#### Scenario: Solo métodos GET
- **WHEN** el documento describe la ruta de entradas
- **THEN** la ruta solo declara la operación `get`

### Requirement: Catálogo de API en /.well-known/api-catalog
El sistema SHALL responder en `/.well-known/api-catalog` con `Content-Type: application/linkset+json` y un linkset conforme a RFC 9727 que enlace al documento OpenAPI con la relación `service-desc` y a la documentación del sitio con `service-doc`.

#### Scenario: Catálogo disponible
- **WHEN** un cliente solicita `/.well-known/api-catalog`
- **THEN** la respuesta es 200 con `Content-Type: application/linkset+json` y contiene un enlace `service-desc` al documento OpenAPI

### Requirement: Coherencia con la generación programada
Los tres documentos SHALL servirse desde el almacenamiento generado por programación y regenerarse al final de cada ciclo; si no existen, SHALL generarse bajo demanda. Un cambio en ajustes que afecte a su contenido SHALL invalidarlos para que se regeneren en la siguiente petición.

#### Scenario: Cambio de correo de contacto
- **WHEN** un administrador cambia el correo de contacto
- **THEN** la siguiente petición a `/agent-skills.json` refleja el nuevo correo
