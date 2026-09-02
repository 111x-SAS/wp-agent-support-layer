## MODIFIED Requirements

### Requirement: Contenido elegible para entrega en Markdown
El sistema SHALL considerar elegible únicamente el contenido que cumpla todas estas condiciones: pertenece a un post type habilitado en los ajustes (por defecto `post` y `page`), el post type es público, el estado es `publish`, no está protegido con contraseña y no está marcado como excluido de la capa de agentes. Un ítem SHALL considerarse excluido cuando su marca de exclusión tenga cualquier valor distinto de vacío y de `0`, y ese mismo criterio MUST aplicarse tanto al evaluar una petición individual como al listar ítems elegibles, la cola de generación y `llms.txt`. La elegibilidad MUST evaluarse en cada petición, no solo al generar el documento.

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

#### Scenario: Marca de exclusión con valor no canónico
- **WHEN** un tercero guarda la marca de exclusión con el valor `yes`
- **THEN** la entrada no se sirve en Markdown, no aparece en la lista de elegibles ni en `llms.txt` y su documento se elimina en el siguiente ciclo

### Requirement: Negociación de contenido por cabecera Accept
El sistema SHALL servir el documento Markdown en la URL canónica de un contenido elegible cuando la cabecera `Accept` de la petición incluya `text/markdown` con una preferencia (`q`) mayor o igual que la de `text/html` o cuando `text/html` esté ausente. A efectos de esta comparación, los comodines `text/*` y `*/*` SHALL contar como aceptación de `text/html` con su `q`, de modo que un cliente que acepte cualquier tipo reciba HTML salvo que prefiera explícitamente `text/markdown`. En cualquier otro caso MUST servir la respuesta HTML habitual.

#### Scenario: Agente que prefiere Markdown
- **WHEN** un cliente envía `Accept: text/markdown` a la URL canónica de una entrada elegible
- **THEN** el sistema responde 200 con `Content-Type: text/markdown; charset=utf-8` y el cuerpo en Markdown

#### Scenario: Navegador que prefiere HTML
- **WHEN** un cliente envía `Accept: text/html,application/xhtml+xml,*/*;q=0.8` a la URL canónica de una entrada elegible
- **THEN** el sistema responde con la página HTML habitual

#### Scenario: Cabecera con ambos tipos y preferencia por HTML
- **WHEN** un cliente envía `Accept: text/html, text/markdown;q=0.5`
- **THEN** el sistema responde con la página HTML habitual

#### Scenario: Comodín con Markdown poco preferido
- **WHEN** un cliente envía `Accept: text/markdown;q=0.3, */*`
- **THEN** el sistema responde con la página HTML habitual

#### Scenario: Comodín con Markdown preferido
- **WHEN** un cliente envía `Accept: text/markdown, */*;q=0.1`
- **THEN** el sistema responde 200 con el documento Markdown

### Requirement: URL alternativa con sufijo .md
Cuando el sitio use enlaces permanentes bonitos, el sistema SHALL servir el documento Markdown de un contenido elegible en la URL resultante de añadir `.md` a la ruta del enlace permanente, sin importar la cabecera `Accept`. Con enlaces permanentes simples, el sistema SHALL aceptar el parámetro de consulta `wpasl=md` sobre la URL canónica. Cuando el enlace permanente de un contenido elegible sea la raíz del sitio (página configurada como portada estática) o contenga una cadena de consulta (post type público sin reglas de reescritura), la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/.md` SHALL resolver a la portada estática cuando exista. La página configurada como "Página de entradas" (`page_for_posts`) SHALL servirse en Markdown por sufijo `.md`, por negociación `Accept` y por `wpasl=md` cuando sea elegible, exactamente igual que cualquier otra página. Cuando el sitio esté instalado en un subdirectorio, la ruta `.md` SHALL reconocer el path base solo como segmento completo. Una URL `.md` que no corresponda a contenido elegible MUST responder 404. La URL alternativa anunciada en el HTML, en la cabecera `Link`, en `llms.txt` y en el diagnóstico MUST ser siempre una URL que el sistema sirva.

#### Scenario: Sufijo .md sobre enlace permanente bonito
- **WHEN** un cliente solicita `/blog/mi-entrada/.md` o `/blog/mi-entrada.md` para una entrada elegible con enlace permanente `/blog/mi-entrada/`
- **THEN** el sistema responde 200 con el documento Markdown

#### Scenario: Sufijo .md sobre contenido inexistente
- **WHEN** un cliente solicita `/no-existe.md`
- **THEN** el sistema responde 404

#### Scenario: Parámetro de consulta con enlaces simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita `/?p=12&wpasl=md` para una entrada elegible
- **THEN** el sistema responde 200 con el documento Markdown

#### Scenario: Portada estática
- **WHEN** una página elegible está configurada como portada estática y el sitio usa enlaces permanentes bonitos
- **THEN** la URL alternativa anunciada es `https://example.com/?wpasl=md`, esa URL responde 200 con el documento Markdown y `/.md` responde 200 con el mismo documento

#### Scenario: Página de entradas
- **WHEN** el sitio muestra una portada estática, otra página elegible con enlace permanente `/blog/` está configurada como "Página de entradas" y un cliente solicita `/blog.md`, `/blog/` con `Accept: text/markdown` o `/blog/?wpasl=md`
- **THEN** el sistema responde 200 con el documento Markdown de esa página, cuyo título encabeza el documento, y `llms.txt` enlaza a `https://example.com/blog.md`

#### Scenario: Post type sin reglas de reescritura
- **WHEN** un ítem elegible pertenece a un post type público cuyo enlace permanente es `/?post_type=doc&p=7`
- **THEN** la URL alternativa anunciada es `/?post_type=doc&p=7&wpasl=md` y responde 200 con el documento Markdown

#### Scenario: Raíz sin portada estática
- **WHEN** el sitio muestra las últimas entradas en la portada y un cliente solicita `/.md`
- **THEN** el sistema responde 404

#### Scenario: Instalación en subdirectorio
- **WHEN** el sitio está instalado en `https://example.com/blog/`, existe una entrada elegible con enlace permanente `/blog/x/` y un cliente solicita `/blogx.md`
- **THEN** el sistema responde 404 y `/blog/x.md` responde 200

### Requirement: Descubrimiento desde el HTML
La respuesta HTML de todo contenido elegible SHALL incluir en `<head>` un elemento `<link rel="alternate" type="text/markdown" href="...">` apuntando a la URL alternativa Markdown, y una cabecera HTTP `Link` equivalente con `rel="alternate"` y `type="text/markdown"`. La "Página de entradas" elegible SHALL incluirlos igual que cualquier página.

#### Scenario: Enlace alternativo en una entrada elegible
- **WHEN** un cliente obtiene la página HTML de una entrada elegible
- **THEN** el `<head>` contiene el `link rel="alternate"` con `type="text/markdown"` y la cabecera `Link` está presente

#### Scenario: Sin enlace en contenido no elegible
- **WHEN** un cliente obtiene la página HTML de un post type no habilitado
- **THEN** no existe `link rel="alternate" type="text/markdown"` ni cabecera `Link` equivalente

#### Scenario: Enlace alternativo en la página de entradas
- **WHEN** un cliente obtiene la página HTML de la "Página de entradas" elegible
- **THEN** el `<head>` contiene el `link rel="alternate"` con `type="text/markdown"` hacia `/blog.md` y la cabecera `Link` está presente

### Requirement: Estructura del documento Markdown
Cada documento Markdown SHALL comenzar con un bloque de front matter YAML que incluya como mínimo `title`, `url`, `type`, `date`, `modified`, `author` y `lang`, e incluya `description`, `categories` y `tags` cuando existan. Tras el front matter SHALL aparecer el título como encabezado de nivel 1 y después el cuerpo convertido a partir del contenido renderizado (bloques y shortcodes ya procesados). El cuerpo SHALL contener el contenido completo de la entrada aunque incluya las marcas `<!--more-->` o `<!--nextpage-->`, sin enlaces "leer más" ni paginación, y MUST ser el mismo sea cual sea la ruta que generó el documento (ejecución programada, WP-CLI, URL alternativa o generación bajo demanda). El cuerpo MUST excluir scripts, estilos, formularios, iframes y comentarios HTML, y MUST expresar enlaces e imágenes con URLs absolutas: las rutas relativas con `./` o `../` SHALL resolverse contra la URL del contenido, las URLs sin esquema (`//host/ruta`) SHALL adoptar el esquema de la URL del sitio, y una referencia que solo tenga cadena de consulta (`?p=1`) SHALL resolverse contra la ruta del contenido conservando la barra de la raíz. Todo conversor que el sistema utilice, incluido uno sustituido por un desarrollador mediante el filtro de servicios, MUST recibir la URL base del contenido para esta resolución.

#### Scenario: Front matter completo
- **WHEN** se genera el documento de una entrada con categorías y etiquetas
- **THEN** el front matter contiene `title`, `url`, `type`, `date`, `modified`, `author`, `lang`, `description`, `categories` y `tags`

#### Scenario: Limpieza de elementos no textuales
- **WHEN** el contenido renderizado incluye un `<script>`, un `<form>` y un `<iframe>`
- **THEN** ninguno de ellos aparece en el cuerpo Markdown

#### Scenario: Enlaces relativos
- **WHEN** el contenido incluye un enlace con `href="/contacto/"`
- **THEN** el Markdown contiene el enlace con la URL absoluta del sitio

#### Scenario: Contenido con more y nextpage
- **WHEN** una entrada contiene `<!--more-->` y `<!--nextpage-->` y el documento se genera fuera de una vista singular (ejecución programada o URL alternativa)
- **THEN** el Markdown contiene el texto anterior y posterior a ambas marcas, sin enlace "(more…)" ni marcador de página

#### Scenario: Ruta relativa con segmentos padre
- **WHEN** el contenido de `https://example.com/blog/entrada/` incluye una imagen con `src="../img/foto.png"` y un enlace con `href="//cdn.example.com/doc.pdf"`
- **THEN** el Markdown contiene `https://example.com/blog/img/foto.png` y `https://cdn.example.com/doc.pdf`

#### Scenario: Referencia solo con cadena de consulta en la raíz
- **WHEN** el contenido de `https://example.org/` incluye un enlace con `href="?p=1"`
- **THEN** el Markdown contiene `https://example.org/?p=1`

### Requirement: Origen del documento servido
El sistema SHALL servir el documento Markdown desde el almacenamiento generado por programación. Si el documento de un contenido elegible no existe en el almacenamiento, el sistema SHALL generarlo en esa petición, almacenarlo y servirlo. El sistema MUST NOT regenerar un documento como reacción a la edición o guardado de una entrada. Generar un documento MUST NOT dejar alterado el estado global de la petición en curso (entrada actual, autor, paginación) una vez terminada la generación.

#### Scenario: Documento previamente generado
- **WHEN** existe un documento almacenado para una entrada elegible y un cliente lo solicita
- **THEN** el sistema sirve el documento almacenado sin volver a convertir el contenido

#### Scenario: Documento ausente
- **WHEN** no existe documento almacenado para una entrada elegible y un cliente lo solicita
- **THEN** el sistema genera el documento, lo almacena y responde 200 con él

#### Scenario: Edición de una entrada
- **WHEN** un editor actualiza el contenido de una entrada que ya tiene documento almacenado
- **THEN** el documento almacenado no cambia hasta la siguiente ejecución programada

#### Scenario: Generación sin efectos sobre la petición
- **WHEN** se generan varios documentos consecutivos fuera de una vista singular (ejecución programada o WP-CLI)
- **THEN** al terminar, la entrada actual, el autor actual y los contadores de paginación de la petición son los mismos que antes de generar
