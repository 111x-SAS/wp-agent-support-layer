## MODIFIED Requirements

### Requirement: Estructura conforme a la especificación
El documento SHALL comenzar con un encabezado de nivel 1 con el nombre del sitio, seguido de un blockquote con la descripción configurada (por defecto la descripción corta del sitio), un bloque opcional de Markdown libre configurado por el administrador, una sección `## When to use this site` con la guía configurada por el administrador cuando no esté vacía (y MUST omitirse cuando lo esté, sin texto por defecto), y una sección de nivel 2 por cada post type habilitado. Cada sección SHALL listar como vista previa sus primeros ítems como `- [Título](URL): descripción`, donde la URL es la versión Markdown del ítem y la descripción es el extracto, y, cuando el tipo tenga más ítems elegibles que los mostrados, SHALL terminar con una línea `- [Full list of <etiqueta del tipo> (<n> items)](<URL absoluta de /llms-<post_type>.txt>)` donde `<n>` es el número de ítems que contiene el archivo del tipo. El documento SHALL terminar con una sección `## Optional` que enlace al sitemap, a `agent-skills.json` y al documento OpenAPI, y MUST omitir esa sección cuando no exista ningún enlace que incluir. La URL del sitemap SHALL ser la que el sitio realmente sirve: la del índice de sitemaps de WordPress según la estructura de enlaces permanentes cuando los sitemaps del núcleo están habilitados, o la del plugin SEO que los sustituya cuando el sistema pueda determinarla localmente; cuando no pueda determinarla, MUST omitir el enlace. Las etiquetas y descripciones fijas de la sección `## Optional` SHALL ser traducibles.

#### Scenario: Documento con post y page
- **WHEN** están habilitados `page` y `post` y existen entradas publicadas de ambos
- **THEN** el documento contiene un H1, un blockquote, una sección `## Pages`, una sección `## Posts` con enlaces a las URLs `.md` y una sección `## Optional`

#### Scenario: Descripción personalizada
- **WHEN** el administrador configura una descripción propia
- **THEN** el blockquote contiene esa descripción en lugar de la descripción corta del sitio

#### Scenario: Guía "cuándo usar este sitio"
- **WHEN** el administrador ha escrito "Use this site for official course descriptions." en la guía y una introducción propia
- **THEN** el documento contiene `## When to use this site` seguido de ese texto, después de la introducción y antes de la primera sección de post type; con la guía vacía, la sección no existe

#### Scenario: Vista previa con enlace a la lista completa
- **WHEN** el límite de vista previa es 10 y hay 25 entradas publicadas
- **THEN** la sección `## Posts` contiene 10 líneas de ítem y termina con `- [Full list of Posts (25 items)](<URL absoluta de /llms-post.txt>)`

#### Scenario: Tipo con menos ítems que la vista previa
- **WHEN** el límite de vista previa es 10 y hay 3 páginas publicadas
- **THEN** la sección `## Pages` contiene 3 líneas de ítem y ninguna línea "Full list"

#### Scenario: Sitemap con enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y los sitemaps del núcleo están habilitados
- **THEN** la sección `## Optional` enlaza a `/?sitemap=index` y no a `/wp-sitemap.xml`

#### Scenario: Sitemap de un plugin SEO
- **WHEN** los sitemaps del núcleo están deshabilitados y el sistema determina localmente que un plugin SEO sirve el índice en `/sitemap_index.xml`
- **THEN** la sección `## Optional` enlaza a la URL absoluta de `/sitemap_index.xml`

#### Scenario: Sitemap indeterminable
- **WHEN** los sitemaps del núcleo están deshabilitados y ningún plugin SEO conocido ni filtro aporta la URL
- **THEN** la sección `## Optional` no contiene enlace al sitemap

#### Scenario: Sin enlaces opcionales
- **WHEN** los sitemaps del núcleo están deshabilitados, ningún sitemap alternativo es determinable y el manifiesto está deshabilitado
- **THEN** el documento no contiene la sección `## Optional`

### Requirement: Límite y orden de ítems por sección
Cada sección de `llms.txt` SHALL incluir como vista previa como máximo el número de ítems configurado como límite de vista previa (por defecto 10, entre 1 y 100). Cada archivo por tipo `/llms-<post_type>.txt` SHALL incluir como máximo el número de ítems configurado como límite por tipo (por defecto 1000, entre 1 y 10000). Las páginas (`page`) SHALL ordenarse por orden de menú y título; el resto de post types, incluidos los jerárquicos personalizados, por fecha de publicación descendente; la vista previa SHALL ser el prefijo de la lista completa con el mismo orden. Solo se incluyen ítems elegibles según la capacidad de entrega en Markdown.

#### Scenario: Más ítems que el límite
- **WHEN** hay 150 entradas publicadas, el límite de vista previa es 10 y el límite por tipo es 1000
- **THEN** la sección `## Posts` de `llms.txt` contiene las 10 más recientes y `/llms-post.txt` contiene las 150

#### Scenario: Más ítems que el límite por tipo
- **WHEN** hay 30 entradas publicadas y el límite por tipo es 20
- **THEN** `/llms-post.txt` contiene las 20 más recientes y termina con una nota indicando que la lista está truncada a 20 de 30 ítems, y la línea "Full list" de `llms.txt` dice `(20 items)`

#### Scenario: Entrada excluida
- **WHEN** una entrada está marcada como excluida de la capa de agentes
- **THEN** no aparece en `llms.txt` ni en `/llms-post.txt`

#### Scenario: Post type jerárquico personalizado
- **WHEN** un post type jerárquico `doc` está habilitado con ítems de distintas fechas y órdenes de menú
- **THEN** la sección `## Docs` y `/llms-doc.txt` los listan por fecha de publicación descendente

### Requirement: llms-full.txt opcional
Cuando el administrador lo habilite (por defecto deshabilitado), el sistema SHALL responder en `/llms-full.txt` con la concatenación de los documentos Markdown de los ítems incluidos en los archivos por tipo (las listas completas, no solo la vista previa de `llms.txt`), separados por una línea horizontal, sin superar el tamaño máximo configurado (por defecto 5 MB). Si se alcanza el límite, el documento SHALL terminar con una nota indicando que fue truncado. El documento SHALL construirse en la ejecución programada o por WP-CLI; cuando esté habilitado y no exista en el almacenamiento, `/llms-full.txt` SHALL responder 503 con la cabecera `Retry-After` y SHALL programar un evento único inmediato que lo construya en segundo plano, sin construirlo en la petición. El documento SHALL responder solo en su ruta exacta. Cuando esté deshabilitado, `/llms-full.txt` MUST responder 404.

#### Scenario: Habilitado
- **WHEN** `llms-full.txt` está habilitado, existe en el almacenamiento y un cliente lo solicita
- **THEN** la respuesta es 200 en Markdown con los documentos concatenados

#### Scenario: Concatena las listas completas
- **WHEN** `llms-full.txt` está habilitado, el límite de vista previa es 2 y hay 5 entradas publicadas
- **THEN** el documento construido contiene los 5 documentos Markdown

#### Scenario: Deshabilitado
- **WHEN** `llms-full.txt` está deshabilitado y un cliente lo solicita
- **THEN** la respuesta es 404

#### Scenario: Límite de tamaño
- **WHEN** la concatenación supera el tamaño máximo
- **THEN** el documento se corta en el último ítem completo que cabe y termina con la nota de truncado

#### Scenario: Habilitado pero ausente
- **WHEN** `llms-full.txt` está habilitado, no existe en el almacenamiento y un cliente lo solicita
- **THEN** la respuesta es 503 con `Retry-After`, queda programado un evento único de generación y la siguiente ejecución de ese evento escribe `llms-full.txt`

## ADDED Requirements

### Requirement: Archivos por tipo de contenido en /llms-<post_type>.txt
Por cada post type habilitado cuyo nombre no sea `full`, el sistema SHALL responder en `/llms-<post_type>.txt` con código 200 y las mismas cabeceras que `/llms.txt` (`Content-Type: text/markdown; charset=utf-8`, `X-Markdown-Tokens`, `Cache-Control: public` con el intervalo de regeneración, `X-Content-Type-Options: nosniff` y las señales de contenido), sirviendo el documento generado por programación o generándolo bajo demanda si no existe; la generación bajo demanda MUST construir únicamente ese archivo. El documento SHALL estar en inglés, SHALL comenzar con un encabezado de nivel 1 con el nombre del sitio y la etiqueta del tipo, seguido de un blockquote que indique el número de ítems y enlace a `/llms.txt`, y SHALL listar todos los ítems elegibles del tipo hasta el límite por tipo con el mismo formato de línea que `llms.txt`; cuando el número de ítems elegibles supere el límite, SHALL terminar con una nota indicando cuántos se listan del total. Un post type llamado `full` MUST NOT tener archivo por tipo, para no colisionar con `/llms-full.txt`, y su sección en `llms.txt` MUST NOT emitir la línea "Full list". El documento SHALL responder solo en su ruta exacta; una ruta `/llms-<nombre>.txt` cuyo nombre no sea un post type habilitado, o cualquier variante no canónica, MUST responder como cualquier ruta inexistente. Si existe un archivo físico `llms-<post_type>.txt` en la raíz del sitio, el archivo físico SHALL prevalecer. Los archivos por tipo SHALL regenerarse junto con `llms.txt` al final de cada ciclo, SHALL invalidarse al guardar ajustes igual que `llms.txt`, y los archivos de tipos que dejen de estar habilitados SHALL eliminarse del almacenamiento en la siguiente regeneración. Toda URL Markdown emitida en un archivo por tipo MUST ser una URL que el sistema sirva, igual que en `llms.txt`.

#### Scenario: Petición a un archivo por tipo
- **WHEN** `post` está habilitado y un cliente solicita `/llms-post.txt`
- **THEN** la respuesta es 200 con `Content-Type: text/markdown; charset=utf-8`, `X-Markdown-Tokens`, `Cache-Control: public, max-age=<intervalo>`, `X-Content-Type-Options: nosniff` y `Content-Signal`, y el cuerpo empieza por un H1 con el nombre del sitio y `Posts` y contiene una línea por entrada publicada elegible

#### Scenario: Documento ausente
- **WHEN** no existe `llms-post.txt` en el almacenamiento y un cliente lo solicita
- **THEN** el sistema genera solo ese archivo, lo almacena y lo sirve, sin escribir `llms.txt`, `llms-full.txt` ni documentos de ítems en esa petición

#### Scenario: Tipo no habilitado o inexistente
- **WHEN** `post` está habilitado y un cliente solicita `/llms-attachment.txt` o `/llms-nada.txt`
- **THEN** la respuesta es 404

#### Scenario: Ruta no canónica
- **WHEN** un cliente solicita `/llms-post.txt/` o `//llms-post.txt`
- **THEN** la respuesta es 404

#### Scenario: Archivo físico presente
- **WHEN** existe un `llms-post.txt` físico en la raíz del sitio y un cliente solicita `/llms-post.txt`
- **THEN** el sistema no sirve ningún documento (el servidor web entrega el archivo físico)

#### Scenario: Post type llamado full
- **WHEN** un post type público `full` está habilitado con ítems publicados
- **THEN** `/llms-full.txt` sigue siendo el documento concatenado (o 404 cuando está deshabilitado), la sección `## Full` de `llms.txt` lista su vista previa sin línea "Full list" y ningún archivo por tipo se genera para él

#### Scenario: Regeneración e invalidación
- **WHEN** termina un ciclo de generación con `post` y `page` habilitados, y después el administrador guarda ajustes
- **THEN** el almacenamiento contiene `llms-post.txt` y `llms-page.txt` tras el ciclo, y ambos desaparecen del almacenamiento al guardar ajustes para regenerarse en la siguiente petición

#### Scenario: Tipo deshabilitado
- **WHEN** `page` deja de estar habilitado y termina el siguiente ciclo de generación
- **THEN** `llms-page.txt` no existe en el almacenamiento y `/llms-page.txt` responde 404

#### Scenario: Toda URL emitida se sirve
- **WHEN** el sitio tiene portada estática, página de entradas y entradas normales y se generan `llms.txt` y los archivos por tipo
- **THEN** cada URL Markdown que aparece en `llms.txt` y en cada `/llms-<post_type>.txt` responde 200 con exactamente un documento Markdown, y cada URL `/llms-<post_type>.txt` enlazada desde `llms.txt` responde 200
