# llms-txt Specification

## Purpose
Publica en la raíz del dominio un mapa curado del sitio en Markdown, conforme a la especificación llms.txt, para que los modelos de lenguaje localicen el contenido relevante con el mínimo contexto.

## Requirements

### Requirement: Publicación de /llms.txt
El sistema SHALL responder en `/llms.txt` con `Content-Type: text/markdown; charset=utf-8` y código 200, sirviendo el documento generado por programación o generándolo bajo demanda si no existe. La generación bajo demanda de `llms.txt` MUST construir únicamente `llms.txt`: MUST NOT construir `llms-full.txt` ni convertir documentos de ítems en esa petición. El documento SHALL responder solo en su ruta exacta; las variantes con barra final o barras dobles (`/llms.txt/`, `//llms.txt`) MUST responder como cualquier ruta inexistente. Si existe un archivo `llms.txt` físico en la raíz, el archivo físico SHALL prevalecer y la página de ajustes SHALL mostrar un aviso.

#### Scenario: Petición a llms.txt
- **WHEN** un cliente solicita `/llms.txt`
- **THEN** la respuesta es 200 con `Content-Type: text/markdown; charset=utf-8`

#### Scenario: Documento ausente
- **WHEN** no existe documento generado y un cliente solicita `/llms.txt`
- **THEN** el sistema genera el documento, lo almacena y lo sirve

#### Scenario: Petición a llms.txt sin arrastrar llms-full.txt
- **WHEN** `llms-full.txt` está habilitado, un administrador acaba de guardar ajustes y un cliente anónimo solicita `/llms.txt`
- **THEN** la respuesta es 200, `llms-full.txt` no existe todavía en el almacenamiento y no se escribió ningún documento de ítem en esa petición

#### Scenario: Ruta no canónica
- **WHEN** un cliente solicita `/llms.txt/` o `//llms.txt`
- **THEN** la respuesta es 404

### Requirement: Estructura conforme a la especificación
El documento SHALL comenzar con un encabezado de nivel 1 con el nombre del sitio, seguido de un blockquote con la descripción configurada (por defecto la descripción corta del sitio), un bloque opcional de Markdown libre configurado por el administrador, y una sección de nivel 2 por cada post type habilitado. Cada sección SHALL listar sus ítems como `- [Título](URL): descripción`, donde la URL es la versión Markdown del ítem y la descripción es el extracto. El documento SHALL terminar con una sección `## Optional` que enlace al sitemap, a `agent-skills.json` y al documento OpenAPI, y MUST omitir esa sección cuando no exista ningún enlace que incluir. La URL del sitemap SHALL ser la que WordPress publica para el índice de sitemaps según la estructura de enlaces permanentes del sitio. Las etiquetas y descripciones fijas de la sección `## Optional` SHALL ser traducibles.

#### Scenario: Documento con post y page
- **WHEN** están habilitados `page` y `post` y existen entradas publicadas de ambos
- **THEN** el documento contiene un H1, un blockquote, una sección `## Pages`, una sección `## Posts` con enlaces a las URLs `.md` y una sección `## Optional`

#### Scenario: Descripción personalizada
- **WHEN** el administrador configura una descripción propia
- **THEN** el blockquote contiene esa descripción en lugar de la descripción corta del sitio

#### Scenario: Sitemap con enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y los sitemaps del núcleo están habilitados
- **THEN** la sección `## Optional` enlaza a `/?sitemap=index` y no a `/wp-sitemap.xml`

#### Scenario: Sin enlaces opcionales
- **WHEN** los sitemaps del núcleo están deshabilitados y el manifiesto está deshabilitado
- **THEN** el documento no contiene la sección `## Optional`

### Requirement: Límite y orden de ítems por sección
Cada sección SHALL incluir como máximo el número de ítems configurado (por defecto 100). Las páginas (`page`) SHALL ordenarse por orden de menú y título; el resto de post types, incluidos los jerárquicos personalizados, por fecha de publicación descendente. Solo se incluyen ítems elegibles según la capacidad de entrega en Markdown.

#### Scenario: Más ítems que el límite
- **WHEN** hay 150 entradas publicadas y el límite es 100
- **THEN** la sección `## Posts` contiene las 100 más recientes

#### Scenario: Entrada excluida
- **WHEN** una entrada está marcada como excluida de la capa de agentes
- **THEN** no aparece en `llms.txt`

#### Scenario: Post type jerárquico personalizado
- **WHEN** un post type jerárquico `doc` está habilitado con ítems de distintas fechas y órdenes de menú
- **THEN** la sección `## Docs` los lista por fecha de publicación descendente

### Requirement: llms-full.txt opcional
Cuando el administrador lo habilite (por defecto deshabilitado), el sistema SHALL responder en `/llms-full.txt` con la concatenación de los documentos Markdown de los ítems incluidos en `llms.txt`, separados por una línea horizontal, sin superar el tamaño máximo configurado (por defecto 5 MB). Si se alcanza el límite, el documento SHALL terminar con una nota indicando que fue truncado. El documento SHALL construirse en la ejecución programada o por WP-CLI; cuando esté habilitado y no exista en el almacenamiento, `/llms-full.txt` SHALL responder 503 con la cabecera `Retry-After` y SHALL programar un evento único inmediato que lo construya en segundo plano, sin construirlo en la petición. El documento SHALL responder solo en su ruta exacta. Cuando esté deshabilitado, `/llms-full.txt` MUST responder 404.

#### Scenario: Habilitado
- **WHEN** `llms-full.txt` está habilitado, existe en el almacenamiento y un cliente lo solicita
- **THEN** la respuesta es 200 en Markdown con los documentos concatenados

#### Scenario: Deshabilitado
- **WHEN** `llms-full.txt` está deshabilitado y un cliente lo solicita
- **THEN** la respuesta es 404

#### Scenario: Límite de tamaño
- **WHEN** la concatenación supera el tamaño máximo
- **THEN** el documento se corta en el último ítem completo que cabe y termina con la nota de truncado

#### Scenario: Habilitado pero ausente
- **WHEN** `llms-full.txt` está habilitado, no existe en el almacenamiento y un cliente lo solicita
- **THEN** la respuesta es 503 con `Retry-After`, queda programado un evento único de generación y la siguiente ejecución de ese evento escribe `llms-full.txt`
