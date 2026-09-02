## Purpose

Permite que agentes y crawlers de IA obtengan una versión Markdown fiel y cacheable del contenido público del sitio, descubrible desde el HTML y accesible por negociación de contenido o por URL alternativa.

## ADDED Requirements

### Requirement: Contenido elegible para entrega en Markdown
El sistema SHALL considerar elegible únicamente el contenido que cumpla todas estas condiciones: pertenece a un post type habilitado en los ajustes (por defecto `post` y `page`), el post type es público, el estado es `publish`, no está protegido con contraseña y no está marcado como excluido de la capa de agentes. La elegibilidad MUST evaluarse en cada petición, no solo al generar el documento.

#### Scenario: Entrada publicada de un post type habilitado
- **WHEN** un cliente solicita la versión Markdown de una entrada publicada del post type `post` con `post` habilitado
- **THEN** el sistema responde con el documento Markdown y código 200

#### Scenario: Entrada despublicada después de la última generación
- **WHEN** una entrada que tenía documento generado pasa a estado `draft` y un cliente solicita su versión Markdown
- **THEN** el sistema no sirve el documento y responde igual que para una entrada inexistente

#### Scenario: Entrada protegida con contraseña
- **WHEN** un cliente solicita la versión Markdown de una entrada protegida con contraseña
- **THEN** el sistema no sirve Markdown y responde con la página HTML habitual

#### Scenario: Post type no habilitado
- **WHEN** un cliente solicita la versión Markdown de un ítem de un post type no habilitado en los ajustes
- **THEN** el sistema no sirve Markdown y responde con la página HTML habitual

#### Scenario: Entrada excluida manualmente
- **WHEN** una entrada tiene activa la marca de exclusión de la capa de agentes y un cliente solicita su versión Markdown
- **THEN** el sistema no sirve Markdown y responde con la página HTML habitual

### Requirement: Negociación de contenido por cabecera Accept
El sistema SHALL servir el documento Markdown en la URL canónica de un contenido elegible cuando la cabecera `Accept` de la petición incluya `text/markdown` con una preferencia (`q`) mayor o igual que la de `text/html` o cuando `text/html` esté ausente. En cualquier otro caso MUST servir la respuesta HTML habitual.

#### Scenario: Agente que prefiere Markdown
- **WHEN** un cliente envía `Accept: text/markdown` a la URL canónica de una entrada elegible
- **THEN** el sistema responde 200 con `Content-Type: text/markdown; charset=utf-8` y el cuerpo en Markdown

#### Scenario: Navegador que prefiere HTML
- **WHEN** un cliente envía `Accept: text/html,application/xhtml+xml,*/*;q=0.8` a la URL canónica de una entrada elegible
- **THEN** el sistema responde con la página HTML habitual

#### Scenario: Cabecera con ambos tipos y preferencia por HTML
- **WHEN** un cliente envía `Accept: text/html, text/markdown;q=0.5`
- **THEN** el sistema responde con la página HTML habitual

### Requirement: URL alternativa con sufijo .md
Cuando el sitio use enlaces permanentes bonitos, el sistema SHALL servir el documento Markdown de un contenido elegible en la URL resultante de añadir `.md` a la ruta del enlace permanente, sin importar la cabecera `Accept`. Con enlaces permanentes simples, el sistema SHALL aceptar el parámetro de consulta `wpasl=md` sobre la URL canónica. Una URL `.md` que no corresponda a contenido elegible MUST responder 404.

#### Scenario: Sufijo .md sobre enlace permanente bonito
- **WHEN** un cliente solicita `/blog/mi-entrada/.md` o `/blog/mi-entrada.md` para una entrada elegible con enlace permanente `/blog/mi-entrada/`
- **THEN** el sistema responde 200 con el documento Markdown

#### Scenario: Sufijo .md sobre contenido inexistente
- **WHEN** un cliente solicita `/no-existe.md`
- **THEN** el sistema responde 404

#### Scenario: Parámetro de consulta con enlaces simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita `/?p=12&wpasl=md` para una entrada elegible
- **THEN** el sistema responde 200 con el documento Markdown

### Requirement: Cabeceras de la respuesta Markdown
Toda respuesta Markdown SHALL incluir `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens` con una estimación entera del número de tokens, `Link` con la relación `canonical` hacia la URL HTML y `Cache-Control: public` con un `max-age` igual al intervalo de regeneración configurado. Las respuestas HTML de contenido elegible SHALL incluir también `Vary: Accept`.

#### Scenario: Cabeceras presentes
- **WHEN** un cliente obtiene un documento Markdown
- **THEN** la respuesta contiene las cabeceras `Content-Type`, `Vary`, `X-Markdown-Tokens`, `Link` y `Cache-Control` con los valores especificados

#### Scenario: Estimación de tokens coherente
- **WHEN** el documento Markdown tiene 4000 bytes
- **THEN** `X-Markdown-Tokens` vale 1000

### Requirement: Descubrimiento desde el HTML
La respuesta HTML de todo contenido elegible SHALL incluir en `<head>` un elemento `<link rel="alternate" type="text/markdown" href="...">` apuntando a la URL alternativa Markdown, y una cabecera HTTP `Link` equivalente con `rel="alternate"` y `type="text/markdown"`.

#### Scenario: Enlace alternativo en una entrada elegible
- **WHEN** un cliente obtiene la página HTML de una entrada elegible
- **THEN** el `<head>` contiene el `link rel="alternate"` con `type="text/markdown"` y la cabecera `Link` está presente

#### Scenario: Sin enlace en contenido no elegible
- **WHEN** un cliente obtiene la página HTML de un post type no habilitado
- **THEN** no existe `link rel="alternate" type="text/markdown"` ni cabecera `Link` equivalente

### Requirement: Estructura del documento Markdown
Cada documento Markdown SHALL comenzar con un bloque de front matter YAML que incluya como mínimo `title`, `url`, `type`, `date`, `modified`, `author` y `lang`, e incluya `description`, `categories` y `tags` cuando existan. Tras el front matter SHALL aparecer el título como encabezado de nivel 1 y después el cuerpo convertido a partir del contenido renderizado (bloques y shortcodes ya procesados). El cuerpo MUST excluir scripts, estilos, formularios, iframes y comentarios HTML, y MUST expresar enlaces e imágenes con URLs absolutas.

#### Scenario: Front matter completo
- **WHEN** se genera el documento de una entrada con categorías y etiquetas
- **THEN** el front matter contiene `title`, `url`, `type`, `date`, `modified`, `author`, `lang`, `description`, `categories` y `tags`

#### Scenario: Limpieza de elementos no textuales
- **WHEN** el contenido renderizado incluye un `<script>`, un `<form>` y un `<iframe>`
- **THEN** ninguno de ellos aparece en el cuerpo Markdown

#### Scenario: Enlaces relativos
- **WHEN** el contenido incluye un enlace con `href="/contacto/"`
- **THEN** el Markdown contiene el enlace con la URL absoluta del sitio

### Requirement: Origen del documento servido
El sistema SHALL servir el documento Markdown desde el almacenamiento generado por programación. Si el documento de un contenido elegible no existe en el almacenamiento, el sistema SHALL generarlo en esa petición, almacenarlo y servirlo. El sistema MUST NOT regenerar un documento como reacción a la edición o guardado de una entrada.

#### Scenario: Documento previamente generado
- **WHEN** existe un documento almacenado para una entrada elegible y un cliente lo solicita
- **THEN** el sistema sirve el documento almacenado sin volver a convertir el contenido

#### Scenario: Documento ausente
- **WHEN** no existe documento almacenado para una entrada elegible y un cliente lo solicita
- **THEN** el sistema genera el documento, lo almacena y responde 200 con él

#### Scenario: Edición de una entrada
- **WHEN** un editor actualiza el contenido de una entrada que ya tiene documento almacenado
- **THEN** el documento almacenado no cambia hasta la siguiente ejecución programada
