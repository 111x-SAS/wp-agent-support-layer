## MODIFIED Requirements

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

## ADDED Requirements

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
