## MODIFIED Requirements

### Requirement: URL alternativa con sufijo .md
Cuando el sitio use enlaces permanentes bonitos, el sistema SHALL servir el documento Markdown de un contenido elegible en la URL resultante de añadir `.md` a la ruta del enlace permanente, sin importar la cabecera `Accept`. Con enlaces permanentes simples, el sistema SHALL aceptar el parámetro de consulta `wpasl=md` sobre la URL canónica. Cuando el enlace permanente de un contenido elegible sea la raíz del sitio (página configurada como portada estática) o contenga una cadena de consulta (post type público sin reglas de reescritura), la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/.md` SHALL resolver a la portada estática cuando exista. Una URL `.md` que no corresponda a contenido elegible MUST responder 404. La URL alternativa anunciada en el HTML, en la cabecera `Link`, en `llms.txt` y en el diagnóstico MUST ser siempre una URL que el sistema sirva.

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

#### Scenario: Post type sin reglas de reescritura
- **WHEN** un ítem elegible pertenece a un post type público cuyo enlace permanente es `/?post_type=doc&p=7`
- **THEN** la URL alternativa anunciada es `/?post_type=doc&p=7&wpasl=md` y responde 200 con el documento Markdown

#### Scenario: Raíz sin portada estática
- **WHEN** el sitio muestra las últimas entradas en la portada y un cliente solicita `/.md`
- **THEN** el sistema responde 404

### Requirement: Cabeceras de la respuesta Markdown
Toda respuesta Markdown SHALL incluir `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens` con una estimación entera del número de tokens, `Link` con la relación `canonical` hacia la URL HTML, `Cache-Control: public` con un `max-age` igual al intervalo de regeneración configurado y `X-Content-Type-Options: nosniff`. La respuesta Markdown MUST NOT incluir la cabecera `X-Robots-Tag` con directivas `noai`, tanto si se obtuvo por negociación de contenido como por la URL alternativa. Las respuestas HTML de contenido elegible SHALL incluir también `Vary: Accept`, y la cabecera `Link` que el sistema añada a la respuesta HTML MUST NOT reemplazar otras cabeceras `Link` ya enviadas.

#### Scenario: Cabeceras presentes
- **WHEN** un cliente obtiene un documento Markdown
- **THEN** la respuesta contiene las cabeceras `Content-Type`, `Vary`, `X-Markdown-Tokens`, `Link`, `Cache-Control` y `X-Content-Type-Options: nosniff` con los valores especificados

#### Scenario: Estimación de tokens coherente
- **WHEN** el documento Markdown tiene 4000 bytes
- **THEN** `X-Markdown-Tokens` vale 1000

#### Scenario: Sin X-Robots-Tag en Markdown negociado
- **WHEN** el entrenamiento no está permitido y un cliente obtiene el documento Markdown mediante `Accept: text/markdown` en la URL canónica
- **THEN** la respuesta no contiene la cabecera `X-Robots-Tag`

#### Scenario: Cabecera Link adicional preservada
- **WHEN** otro componente ya envió una cabecera `Link` en la respuesta HTML de una entrada elegible
- **THEN** la respuesta contiene ambas cabeceras `Link`

### Requirement: Estructura del documento Markdown
Cada documento Markdown SHALL comenzar con un bloque de front matter YAML que incluya como mínimo `title`, `url`, `type`, `date`, `modified`, `author` y `lang`, e incluya `description`, `categories` y `tags` cuando existan. Tras el front matter SHALL aparecer el título como encabezado de nivel 1 y después el cuerpo convertido a partir del contenido renderizado (bloques y shortcodes ya procesados). El cuerpo SHALL contener el contenido completo de la entrada aunque incluya las marcas `<!--more-->` o `<!--nextpage-->`, sin enlaces "leer más" ni paginación, y MUST ser el mismo sea cual sea la ruta que generó el documento (ejecución programada, WP-CLI, URL alternativa o generación bajo demanda). El cuerpo MUST excluir scripts, estilos, formularios, iframes y comentarios HTML, y MUST expresar enlaces e imágenes con URLs absolutas: las rutas relativas con `./` o `../` SHALL resolverse contra la URL del contenido, y las URLs sin esquema (`//host/ruta`) SHALL adoptar el esquema de la URL del sitio.

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
