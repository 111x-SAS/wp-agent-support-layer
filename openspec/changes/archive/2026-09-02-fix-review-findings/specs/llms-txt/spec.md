## MODIFIED Requirements

### Requirement: Estructura conforme a la especificación
El documento SHALL comenzar con un encabezado de nivel 1 con el nombre del sitio, seguido de un blockquote con la descripción configurada (por defecto la descripción corta del sitio), un bloque opcional de Markdown libre configurado por el administrador, y una sección de nivel 2 por cada post type habilitado. Cada sección SHALL listar sus ítems como `- [Título](URL): descripción`, donde la URL es la versión Markdown del ítem y la descripción es el extracto. El documento SHALL terminar con una sección `## Optional` que enlace al sitemap, a `agent-skills.json` y al documento OpenAPI. La URL del sitemap SHALL ser la que WordPress publica para el índice de sitemaps según la estructura de enlaces permanentes del sitio. Las etiquetas y descripciones fijas de la sección `## Optional` SHALL ser traducibles.

#### Scenario: Documento con post y page
- **WHEN** están habilitados `page` y `post` y existen entradas publicadas de ambos
- **THEN** el documento contiene un H1, un blockquote, una sección `## Pages`, una sección `## Posts` con enlaces a las URLs `.md` y una sección `## Optional`

#### Scenario: Descripción personalizada
- **WHEN** el administrador configura una descripción propia
- **THEN** el blockquote contiene esa descripción en lugar de la descripción corta del sitio

#### Scenario: Sitemap con enlaces permanentes simples
- **WHEN** el sitio usa enlaces permanentes simples y los sitemaps del núcleo están habilitados
- **THEN** la sección `## Optional` enlaza a `/?sitemap=index` y no a `/wp-sitemap.xml`
