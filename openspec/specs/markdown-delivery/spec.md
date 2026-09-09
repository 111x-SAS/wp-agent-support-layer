# markdown-delivery Specification

## Purpose
Permite que agentes y crawlers de IA obtengan una versión Markdown fiel y cacheable del contenido público del sitio, descubrible desde el HTML y accesible por negociación de contenido o por URL alternativa.

## Requirements

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
Cuando el sitio use enlaces permanentes bonitos, el sistema SHALL servir el documento Markdown de un contenido elegible en la URL resultante de añadir `.md` a la ruta del enlace permanente, sin importar la cabecera `Accept`. Con enlaces permanentes simples, el sistema SHALL aceptar el parámetro de consulta `wpasl=md` sobre la URL canónica. Cuando el enlace permanente de un contenido elegible sea la raíz del sitio (página configurada como portada estática) o contenga una cadena de consulta (post type público sin reglas de reescritura), la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/.md` SHALL resolver a la portada estática cuando exista. La página configurada como "Página de entradas" (`page_for_posts`) SHALL servirse en Markdown por sufijo `.md`, por negociación `Accept` y por `wpasl=md` cuando sea elegible, exactamente igual que cualquier otra página. Cuando el sitio esté instalado en un subdirectorio, la ruta `.md` SHALL reconocer el path base solo como segmento completo. La ruta exacta `/auth.md` (relativa a la raíz del sitio) SHALL estar reservada para el documento `auth.md` de la capa de agentes: el sufijo `.md` MUST NOT resolverla a ningún contenido, exista o no un contenido con slug `auth` y esté o no publicada `auth.md`; el archivo raíz gana. Para un contenido elegible cuya URL con sufijo `.md` coincida con esa ruta reservada, la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/auth/.md` SHALL seguir resolviendo a ese contenido. Una URL `.md` que no corresponda a contenido elegible MUST responder 404. La URL alternativa anunciada en el HTML, en la cabecera `Link`, en `llms.txt` y en el diagnóstico MUST ser siempre una URL que el sistema sirva.

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

#### Scenario: Ruta reservada para auth.md
- **WHEN** existe una página elegible con enlace permanente `/auth/` y un cliente solicita `/auth.md`
- **THEN** el sistema no sirve el documento Markdown de la página: con `auth.md` publicado responde el documento `auth.md` del sitio y con `auth.md` desactivado responde 404

#### Scenario: URL alternativa de un contenido que colisiona con auth.md
- **WHEN** existe una página elegible con enlace permanente `https://example.com/auth/` y el sitio usa enlaces permanentes bonitos
- **THEN** la URL alternativa anunciada en el HTML, en la cabecera `Link` y en `llms.txt` es `https://example.com/auth/?wpasl=md`, esa URL responde 200 con el documento Markdown de la página y `/auth/.md` responde 200 con el mismo documento

#### Scenario: Ruta reservada en subdirectorio
- **WHEN** el sitio está instalado en `https://example.com/blog/`, `auth.md` está publicado y un cliente solicita `/blog/auth.md`
- **THEN** la respuesta es el documento `auth.md` del sitio, y `/auth.md` fuera del path base no lo es

### Requirement: Cabeceras de la respuesta Markdown
Toda respuesta Markdown SHALL incluir `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens` con una estimación entera del número de tokens, `Link` con la relación `canonical` hacia la URL HTML, `Cache-Control: public` con un `max-age` igual al intervalo de regeneración configurado y `X-Content-Type-Options: nosniff`. La respuesta Markdown MUST NOT incluir la cabecera `X-Robots-Tag` con directivas `noai`, tanto si se obtuvo por negociación de contenido como por la URL alternativa. La cabecera `Link` de la respuesta Markdown MUST NOT reemplazar las cabeceras `Link` que otros componentes del plugin añadan a esa misma respuesta, como la relación `api-catalog`, y las cabeceras `Link` emitidas para la representación HTML antes de que la negociación de contenido eligiera Markdown MUST NOT repetirse en la respuesta Markdown: cada relación anunciada SHALL aparecer una sola vez. Las respuestas HTML de contenido elegible SHALL incluir también `Vary: Accept`, y la cabecera `Link` que el sistema añada a la respuesta HTML MUST NOT reemplazar otras cabeceras `Link` ya enviadas.

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

#### Scenario: Cabecera Link del catálogo conservada en Markdown
- **WHEN** el manifiesto está habilitado y un cliente obtiene el documento Markdown de una entrada elegible mediante `Accept: text/markdown` en la URL canónica, mediante la URL con sufijo `.md` o mediante `?wpasl=md`
- **THEN** la respuesta contiene exactamente dos cabeceras `Link`: una con `rel="canonical"` hacia la URL HTML y otra con `rel="api-catalog"` hacia `/.well-known/api-catalog`, sin repeticiones

#### Scenario: Sin cabecera Link del catálogo con el manifiesto deshabilitado
- **WHEN** el manifiesto está deshabilitado y un cliente obtiene el documento Markdown de una entrada elegible
- **THEN** la única cabecera `Link` de la respuesta es la de `rel="canonical"`

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
Cada documento Markdown SHALL comenzar con un bloque de front matter YAML que incluya como mínimo `title`, `url`, `type`, `date`, `modified`, `author`, `lang` y `source`, e incluya `description`, `categories` y `tags` cuando existan. La clave `source` SHALL valer `editor` cuando el cuerpo proviene del contenido del editor (bloques y shortcodes ya procesados) y `rendered` cuando proviene de la región de contenido de la página renderizada; un documento generado con el contenido del editor porque la página renderizada no pudo obtenerse SHALL llevar `source: editor`. Tras el front matter SHALL aparecer el título como encabezado de nivel 1 y después el cuerpo convertido a partir del contenido del origen resuelto. El cuerpo SHALL contener el contenido completo de la entrada aunque incluya las marcas `<!--more-->` o `<!--nextpage-->`, sin enlaces "leer más" ni paginación, y MUST ser el mismo sea cual sea la ruta que generó el documento (ejecución programada, WP-CLI, URL alternativa o generación bajo demanda). El cuerpo MUST excluir scripts, estilos, formularios, iframes y comentarios HTML, y MUST expresar enlaces e imágenes con URLs absolutas: las rutas relativas con `./` o `../` SHALL resolverse contra la URL del contenido, las URLs sin esquema (`//host/ruta`) SHALL adoptar el esquema de la URL del sitio, y una referencia que solo tenga cadena de consulta (`?p=1`) SHALL resolverse contra la ruta del contenido conservando la barra de la raíz. Todo conversor que el sistema utilice, incluido uno sustituido por un desarrollador mediante el filtro de servicios, MUST recibir la URL base del contenido para esta resolución. El HTML del origen resuelto, sea el del editor o el fragmento extraído de la página renderizada, SHALL pasar por el mismo filtro de HTML previo a la conversión, y el documento final por el mismo filtro de documento.

#### Scenario: Front matter completo
- **WHEN** se genera el documento de una entrada con categorías y etiquetas
- **THEN** el front matter contiene `title`, `url`, `type`, `date`, `modified`, `author`, `lang`, `source`, `description`, `categories` y `tags`

#### Scenario: Clave source según el origen
- **WHEN** se genera el documento de una entrada cuyo origen resuelto es `editor` y el de otra cuyo origen resuelto es `rendered` y cuya página renderizada se obtuvo
- **THEN** el primer front matter contiene `source: "editor"`, el segundo `source: "rendered"`, y el resto de las claves y el orden del documento no cambian respecto a la versión anterior

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

#### Scenario: Filtros compartidos por ambos orígenes
- **WHEN** un desarrollador registra un filtro sobre el HTML previo a la conversión que añade un párrafo y se generan un documento de origen `editor` y otro de origen `rendered`
- **THEN** ambos cuerpos contienen el párrafo añadido

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

### Requirement: Respuesta 404 en Markdown para agentes
Cuando una petición con sufijo `.md` o con el parámetro `wpasl=md` no resuelva a contenido elegible, o cuando una petición cuya cabecera `Accept` prefiera `text/markdown` (con el mismo criterio de preferencia que la negociación de contenido) termine en una respuesta 404 de WordPress, el sistema SHALL responder con código 404, `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, las cabeceras de señales de contenido de las respuestas no HTML (sin `X-Robots-Tag`) y, con los manifiestos habilitados, una única cabecera `Link` con `rel="api-catalog"`. El cuerpo SHALL ser un documento Markdown corto en inglés, generado con valores reales del sitio, con un encabezado de nivel 1 que indique que el recurso no se encontró, una frase que lo explique y una lista de enlaces a `/llms.txt`, a `/auth.md` cuando esté publicado, al sitemap del sitio cuando el sistema pueda determinar su URL, y al catálogo de API cuando los manifiestos estén habilitados. El cuerpo MUST NOT reflejar la URL solicitada ni ningún otro dato de la petición. Un desarrollador SHALL poder modificar el cuerpo mediante un filtro. Las respuestas 404 a peticiones que no cumplan ninguna de las condiciones anteriores (por ejemplo, un navegador con `Accept: text/html`) MUST seguir siendo la página 404 HTML habitual del tema. El sistema MUST NOT interferir con las redirecciones canónicas que WordPress aplica a una URL inexistente antes de responder 404.

#### Scenario: Sufijo .md sobre contenido inexistente
- **WHEN** un cliente solicita `/no-existe.md`
- **THEN** la respuesta es 404 con `Content-Type: text/markdown; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, y el cuerpo contiene un H1 con "not found" y enlaces a `/llms.txt` y al catálogo de API

#### Scenario: Sufijo .md sobre contenido no elegible
- **WHEN** un cliente solicita la URL `.md` de una entrada en borrador
- **THEN** la respuesta es 404 en Markdown con el mismo cuerpo

#### Scenario: Accept que prefiere Markdown en una URL inexistente
- **WHEN** un cliente envía `Accept: text/markdown` a `/no-existe/`
- **THEN** la respuesta es 404 en Markdown con `Vary: Accept`

#### Scenario: Navegador en una URL inexistente
- **WHEN** un cliente envía `Accept: text/html,application/xhtml+xml,*/*;q=0.8` a `/no-existe/` o a `/no-existe.md`
- **THEN** con `/no-existe/` la respuesta es la página 404 HTML del tema, y con `/no-existe.md` la respuesta es 404 en Markdown (el sufijo manda)

#### Scenario: Enlaces según la configuración
- **WHEN** `auth.md` está publicado, los sitemaps del núcleo están habilitados y un cliente solicita `/no-existe.md`
- **THEN** el cuerpo enlaza `/auth.md` y la URL del índice de sitemaps; con `auth.md` desactivado no enlaza `/auth.md`; con los manifiestos deshabilitados no enlaza el catálogo y la respuesta no lleva `Link rel="api-catalog"`

#### Scenario: Sin reflejo de la petición
- **WHEN** un cliente solicita `/<script>alert(1)</script>.md`
- **THEN** el cuerpo del 404 en Markdown no contiene la cadena solicitada

#### Scenario: Cabeceras de señales
- **WHEN** el entrenamiento no está permitido y un cliente solicita `/no-existe.md`
- **THEN** la respuesta contiene `Content-Signal` y no contiene `X-Robots-Tag`, y con los manifiestos habilitados contiene exactamente una cabecera `Link` con `rel="api-catalog"`

#### Scenario: Filtro sobre el cuerpo
- **WHEN** un desarrollador añade una línea mediante el filtro del cuerpo
- **THEN** la respuesta 404 en Markdown contiene esa línea

### Requirement: Origen del contenido del documento
Antes de construir el documento de una entrada, el sistema SHALL resolver su origen de contenido como `editor` o `rendered`, junto con una razón identificable, evaluando en este orden y deteniéndose en la primera condición que aplique: (1) la anulación por entrada guardada en la meta protegida de la entrada, si vale `editor` o `rendered`; (2) el ajuste del origen para el post type de la entrada, si vale `editor` o `rendered`; (3) con el ajuste en `auto`: `rendered` si la entrada tiene una plantilla asignada distinta de la predeterminada; `rendered` si la entrada lleva la meta de un constructor visual conocido con el valor que ese constructor usa para marcar contenido construido (lista por defecto: Elementor, Divi, Beaver Builder, Bricks, Oxygen y Breakdance; ampliable por filtro); `rendered` si el tema activo contiene un archivo de plantilla específico del tipo o de la entrada (la jerarquía que WordPress consulta para una vista singular: plantilla asignada, `single-{tipo}-{slug}.php`, `single-{tipo}.php`, `page-{slug}.php`, `page-{id}.php`; en temas de bloques, las plantillas equivalentes del tema) cuyo código no imprime el contenido del editor ni incluye de forma dinámica partes de plantilla que puedan imprimirlo; y, como red final, `rendered` si el contenido del editor convertido a Markdown queda vacío o por debajo de un umbral pequeño de caracteres filtrable; (4) en cualquier otro caso `editor`. Un archivo de plantilla que imprima el contenido del editor, o que incluya partes de plantilla con argumentos dinámicos, MUST NOT contar como condición a favor de `rendered`. La lectura del archivo de plantilla SHALL limitarse a un tamaño acotado y SHALL reutilizar su resultado dentro de la misma ejecución mientras el archivo no cambie. Un desarrollador SHALL poder sustituir el resultado de la resolución mediante un filtro que reciba el origen, la razón y la entrada.

#### Scenario: Anulación por entrada
- **WHEN** una entrada de un post type con origen `auto` sin plantilla, sin constructor y con contenido normal tiene la anulación por entrada en `rendered`
- **THEN** el origen resuelto es `rendered` con razón `post_override`, y con la anulación en `editor` sobre una entrada construida con Elementor el origen resuelto es `editor`

#### Scenario: Ajuste por tipo de contenido
- **WHEN** el post type `solucion` tiene el origen `rendered` en los ajustes y una entrada de ese tipo tiene contenido normal en el editor
- **THEN** el origen resuelto es `rendered` con razón `post_type_setting`; con el origen `editor` para `page`, una página construida con Elementor resuelve `editor`

#### Scenario: Plantilla asignada
- **WHEN** una página con origen `auto` tiene asignada la plantilla `templates/landing.php` y su contenido en el editor es normal
- **THEN** el origen resuelto es `rendered` con razón `page_template`; con la plantilla predeterminada esa condición no aplica

#### Scenario: Meta de constructor
- **WHEN** una entrada con origen `auto` tiene la meta `_elementor_edit_mode` con valor `builder` y una meta `_elementor_data` no vacía
- **THEN** el origen resuelto es `rendered` con razón `builder:elementor`; con `_elementor_edit_mode` presente pero `_elementor_data` vacía esa condición no aplica; una clave añadida por filtro se evalúa igual que las conocidas

#### Scenario: Archivo de plantilla con contenido fijo
- **WHEN** el tema activo contiene `single-solucion.php` cuyo código no contiene `the_content`, `get_the_content` ni el bloque `wp:post-content` y una entrada del tipo `solucion` tiene origen `auto`
- **THEN** el origen resuelto es `rendered` con razón `template_file:single-solucion.php`

#### Scenario: Archivo de plantilla que imprime el editor
- **WHEN** el tema activo contiene `single-solucion.php` que llama a `the_content()` y una entrada del tipo `solucion` con contenido normal tiene origen `auto`
- **THEN** esa condición no aplica y el origen resuelto es `editor`; lo mismo cuando el archivo incluye `get_template_part( 'template-parts/content', get_post_type() )`

#### Scenario: Contenido del editor vacío
- **WHEN** una entrada con origen `auto`, sin plantilla, sin constructor y sin archivo de plantilla específico tiene `post_content` vacío o su contenido convertido a Markdown tiene menos caracteres que el umbral
- **THEN** el origen resuelto es `rendered` con razón `empty_editor`

#### Scenario: Contenido normal
- **WHEN** una entrada con origen `auto` no cumple ninguna condición
- **THEN** el origen resuelto es `editor` con razón `default`

#### Scenario: Resolución sustituida por filtro
- **WHEN** un desarrollador devuelve `rendered` desde el filtro de resolución para una entrada que resolvía `editor`
- **THEN** el documento se genera a partir de la página renderizada

### Requirement: Obtención de la página renderizada por loopback
Cuando el origen resuelto sea `rendered`, el sistema SHALL obtener el HTML de la entrada con una petición HTTP `GET` desde el servidor a la URL canónica de la entrada, exclusivamente sobre el host del sitio, con `Accept: text/html`, un user-agent propio del plugin, una cabecera propia y un parámetro de consulta propio que identifiquen la petición como petición de renderizado, un tiempo máximo de espera (por defecto 10 segundos), un tamaño máximo de respuesta (por defecto 2 MB), validación de URL segura, y sin seguir redirecciones automáticamente; una redirección al mismo host SHALL seguirse manualmente como máximo dos veces y una redirección a otro host MUST tratarse como fallo. Los argumentos de la petición SHALL poder ajustarse mediante un filtro. Una petición que lleve la cabecera o el parámetro de renderizado MUST recibir la respuesta HTML habitual y MUST NOT recibir Markdown ni provocar la generación de ningún documento, aunque su cabecera `Accept` prefiera Markdown; una petición de renderizado sobre la portada estática SHALL renderizar la portada estática. Cuando el loopback falle (error de conexión, tiempo agotado, código distinto de 200, redirección no seguible, respuesta que no es HTML, o HTML sin región de contenido con texto), el sistema SHALL generar el documento con el contenido del editor y `source: editor`, SHALL registrar el fallo con su motivo y MUST NOT tratarlo como fallo de conversión. Cuando el documento de una entrada elegible falte y se genere bajo demanda en una petición, el sistema SHALL realizar el loopback en esa misma petición con los mismos límites y, si falla, SHALL servir el documento generado con el contenido del editor.

#### Scenario: Petición de renderizado
- **WHEN** se genera el documento de una entrada con origen `rendered` cuya URL canónica es `https://example.com/soluciones/blackboard/`
- **THEN** el sistema realiza exactamente una petición `GET` a esa URL del host `example.com` con `Accept: text/html`, la cabecera y el parámetro de renderizado, tiempo máximo de 10 segundos, tamaño máximo de 2 MB y sin redirecciones automáticas, y el documento contiene el texto de la región de contenido con `source: rendered`

#### Scenario: Petición de renderizado nunca recibe Markdown
- **WHEN** un cliente envía a la URL canónica de una entrada elegible una petición con la cabecera de renderizado y `Accept: text/markdown`, o con el parámetro de renderizado y `Accept: text/markdown`, sin documento almacenado
- **THEN** la respuesta es la página HTML habitual, no se genera ni almacena ningún documento, y la portada estática con el parámetro de renderizado responde la portada estática

#### Scenario: Argumentos filtrables
- **WHEN** un desarrollador fija el tiempo máximo en 3 segundos mediante el filtro de argumentos
- **THEN** la petición de renderizado se realiza con ese tiempo máximo

#### Scenario: Redirección al mismo host
- **WHEN** la URL canónica responde 301 hacia otra URL del mismo host que responde 200 con HTML
- **THEN** el sistema sigue la redirección y genera el documento con `source: rendered`; con tres redirecciones encadenadas el loopback falla

#### Scenario: Redirección a otro host
- **WHEN** la URL canónica responde 302 hacia `https://www.example.com/...` cuando el host del sitio es `example.com`
- **THEN** el sistema no contacta el otro host, el loopback falla con motivo de redirección y el documento se genera con el contenido del editor y `source: editor`

#### Scenario: Loopback bloqueado o fuera de tiempo
- **WHEN** la petición de renderizado devuelve un error de conexión, agota el tiempo, o responde 403, 500 o 503
- **THEN** el documento se genera con el contenido del editor y `source: editor`, el fallo queda registrado con su motivo y el ítem no figura como fallo de conversión

#### Scenario: HTML sin región de contenido
- **WHEN** la petición de renderizado responde 200 con un HTML cuyo cuerpo no contiene texto tras la limpieza
- **THEN** el loopback falla con motivo `no_content` y el documento se genera con el contenido del editor

#### Scenario: Generación bajo demanda con loopback
- **WHEN** no existe documento para una entrada elegible con origen `rendered` y un cliente solicita su versión Markdown
- **THEN** el sistema realiza el loopback en esa petición, almacena el documento con `source: rendered` y responde 200 con él; si el loopback falla, responde 200 con el documento generado con el contenido del editor

### Requirement: Extracción de la región de contenido
Del HTML de la página renderizada, el sistema SHALL extraer la región de contenido principal: cuando el selector CSS configurado no esté vacío, SHALL usar ese selector; en caso contrario SHALL probar en orden una lista de selectores filtrable (por defecto `main`, `[role="main"]`, `article`, `#content`, `#primary`, `.site-content`, `.elementor[data-elementor-type]`) y tomar, del primer selector con coincidencias que contengan texto, el elemento con más texto; si ninguno coincide, SHALL usar `body`. Dentro de la región, el sistema SHALL eliminar, antes de la conversión, los elementos `script`, `style`, `noscript`, `template`, `nav`, `header`, `footer`, `aside`, `form`, `iframe`, `svg`, `button`, `input`, `select`, `textarea`, los que lleven `hidden` o `aria-hidden="true"`, los de clase `screen-reader-text` y los contenedores de cabecera y pie de constructores visuales (lista filtrable, por defecto los de Elementor y Divi), y SHALL quitar el primer encabezado de nivel 1 cuyo texto normalizado coincida con el título de la entrada. El selector CSS configurado SHALL admitir etiquetas, `#id`, `.clase`, `[attr]`, `[attr="valor"]`, sus combinaciones sobre un mismo elemento, el combinador descendiente y el combinador hijo, y listas separadas por comas; un selector fuera de ese subconjunto MUST rechazarse al guardar los ajustes conservando el valor anterior y registrando un aviso de error. Un desarrollador SHALL poder sustituir el selector mediante un filtro.

#### Scenario: Página de Elementor
- **WHEN** la página renderizada es un documento de Elementor con `header` y `footer` del tema, un contenedor `.elementor-location-header`, un `main` con `div.elementor[data-elementor-type="wp-page"]` con varios widgets de texto y encabezados, y un `<h1>` con el título de la entrada
- **THEN** el Markdown contiene el texto y los encabezados de los widgets, no contiene el texto de la cabecera, del pie ni del menú, y el título aparece una sola vez como H1

#### Scenario: Tema clásico
- **WHEN** la página renderizada es un tema clásico con `main#primary > article.post` y `aside#secondary`
- **THEN** el Markdown contiene el contenido del `article` y no el de `aside`

#### Scenario: Sin región reconocible
- **WHEN** la página renderizada no contiene `main`, `article` ni ningún selector de la lista, pero `body` contiene párrafos de texto y un `nav`
- **THEN** el Markdown contiene los párrafos y no el menú

#### Scenario: Elementos eliminados
- **WHEN** la región de contenido contiene un `script`, un `style`, un `form`, un `button`, un `div hidden`, un `span aria-hidden="true"` y un `span.screen-reader-text`
- **THEN** ninguno de ellos aporta texto al Markdown

#### Scenario: Selector configurado
- **WHEN** el selector configurado es `div.entry-content > .inner` y la página contiene ese elemento junto a un `main` con más texto
- **THEN** el Markdown contiene solo el texto de `.inner`; con el selector configurado sin coincidencias en la página, la extracción sigue con la detección automática

#### Scenario: Selector inválido
- **WHEN** un administrador guarda el selector `div:has(p)` o `a::before`
- **THEN** el ajuste conserva el valor anterior, la página muestra un aviso de error de saneado y `div.entry-content, main article` se acepta

#### Scenario: Lista de selectores filtrable
- **WHEN** un desarrollador antepone `.mi-contenido` a la lista de selectores mediante el filtro
- **THEN** una página con `.mi-contenido` y `main` produce el Markdown de `.mi-contenido`
