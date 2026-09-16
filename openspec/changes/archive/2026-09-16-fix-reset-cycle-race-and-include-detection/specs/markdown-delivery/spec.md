## MODIFIED Requirements

### Requirement: Origen del contenido del documento
Antes de construir el documento de una entrada, el sistema SHALL resolver su origen de contenido como `editor` o `rendered`, junto con una razón identificable, evaluando en este orden y deteniéndose en la primera condición que aplique: (1) la anulación por entrada guardada en la meta protegida de la entrada, si vale `editor` o `rendered`; (2) el ajuste del origen para el post type de la entrada, si vale `editor` o `rendered`; (3) con el ajuste en `auto`: `rendered` si la entrada tiene una plantilla asignada distinta de la predeterminada; `rendered` si la entrada lleva la meta de un constructor visual conocido con el valor que ese constructor usa para marcar contenido construido (lista por defecto: Elementor, Divi, Beaver Builder, Bricks, Oxygen y Breakdance; ampliable por filtro); `rendered` si el tema activo contiene un archivo de plantilla específico del tipo o de la entrada (la jerarquía que WordPress consulta para una vista singular: plantilla asignada, `single-{tipo}-{slug}.php`, `single-{tipo}.php`, `page-{slug}.php`, `page-{id}.php`; en temas de bloques, las plantillas equivalentes del tema) cuyo código no imprime el contenido del editor ni incluye de forma dinámica partes de plantilla que puedan imprimirlo; y, únicamente cuando un desarrollador fija mediante filtro un umbral de caracteres mayor que cero (por defecto la regla está desactivada), `rendered` si el contenido del editor convertido a Markdown queda por debajo de ese umbral; (4) en cualquier otro caso `editor`, incluido el contenido del editor vacío. Un archivo de plantilla que imprima el contenido del editor, o que incluya partes de plantilla con argumentos dinámicos, MUST NOT contar como condición a favor de `rendered`. Al buscar includes dinámicos en el código de la plantilla, el sistema SHALL reconocer `include`, `require`, `include_once` y `require_once` únicamente como construcciones del lenguaje: un identificador más largo que meramente empiece por una de esas palabras (una variable, una propiedad o un método) MUST NOT contar como include dinámico, y una clave de array entrecomillada como `'include'` tampoco. La lectura del archivo de plantilla SHALL limitarse a un tamaño acotado y SHALL reutilizar su resultado dentro de la misma ejecución mientras el archivo no cambie. Un desarrollador SHALL poder sustituir el resultado de la resolución mediante un filtro que reciba el origen, la razón y la entrada.

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

#### Scenario: Identificadores que empiezan por include o require en un archivo de plantilla de contenido fijo
- **WHEN** el tema activo contiene `single-post.php` con contenido fijo cuyo código asigna `$include_path = get_stylesheet_directory();` o llama a un método como `$this->require_once_algo( $file );`, sin ningún `include`/`require` real con argumento dinámico, y una entrada de tipo `post` con contenido normal tiene origen `auto`
- **THEN** el origen resuelto es `rendered` con razón `template_file:single-post.php`; y en la misma plantilla un `include $file;`, un `require_once $file;` o un `include'./parte.php';` real siguen impidiendo esa condición, con lo que el origen resuelto es `editor`

#### Scenario: Contenido del editor vacío sin umbral
- **WHEN** una entrada con origen `auto`, sin plantilla, sin constructor y sin archivo de plantilla específico tiene `post_content` vacío y ningún filtro fija un umbral
- **THEN** el origen resuelto es `editor` con razón `default`, sin convertir el contenido ni obtener la página renderizada

#### Scenario: Umbral del editor activado por filtro
- **WHEN** un filtro fija el umbral en 100 caracteres y una entrada con origen `auto`, sin plantilla, sin constructor y sin archivo de plantilla específico tiene `post_content` vacío o su contenido convertido a Markdown tiene menos de 100 caracteres
- **THEN** el origen resuelto es `rendered` con razón `empty_editor`; con el umbral en 2 y un contenido de cuatro caracteres el origen resuelto es `editor`

#### Scenario: Contenido normal
- **WHEN** una entrada con origen `auto` no cumple ninguna condición
- **THEN** el origen resuelto es `editor` con razón `default`

#### Scenario: Resolución sustituida por filtro
- **WHEN** un desarrollador devuelve `rendered` desde el filtro de resolución para una entrada que resolvía `editor`
- **THEN** el documento se genera a partir de la página renderizada
