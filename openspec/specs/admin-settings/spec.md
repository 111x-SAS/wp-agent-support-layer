# admin-settings Specification

## Purpose
Concentra la configuración del plugin en una página simple bajo Herramientas, con valores por defecto seguros, y garantiza requisitos mínimos, exclusión por entrada y ciclo de vida limpio (activación, desactivación, desinstalación).

## Requirements

### Requirement: Página de ajustes en Herramientas
El sistema SHALL registrar una única página "Agent Support Layer" bajo el menú Herramientas, accesible solo a usuarios con `manage_options`, organizada en pestañas: General, Señales, Crawlers, llms.txt, Manifiestos y Diagnóstico. Todos los ajustes SHALL guardarse mediante la Settings API con sanitización y nonce. Tras guardar cualquier pestaña, la página SHALL mostrar la confirmación de guardado que genera la Settings API ("Settings saved.") y los avisos de error registrados durante la sanitización.

#### Scenario: Acceso con permisos
- **WHEN** un administrador abre Herramientas
- **THEN** ve el submenú "Agent Support Layer" y puede cargar la página

#### Scenario: Acceso sin permisos
- **WHEN** un usuario con rol Editor intenta cargar la página por URL
- **THEN** el sistema deniega el acceso

#### Scenario: Confirmación al guardar
- **WHEN** un administrador guarda la pestaña Señales, Crawlers, llms.txt o Manifiestos y vuelve a la página con `settings-updated=true`
- **THEN** la página muestra el aviso "Settings saved." una sola vez y los valores guardados

### Requirement: Ajustes de la pestaña General
La pestaña General SHALL permitir elegir los post types habilitados entre los post types públicos (por defecto `post` y `page`), el intervalo de regeneración, el tamaño de lote, el origen de contenido de cada post type habilitado ("Content source": `auto` por defecto, `editor` o `rendered`, con una descripción de cada valor) y un campo de texto "Content selector (CSS)" opcional (vacío por defecto, con la descripción de que vacío significa detección automática), y ofrecer la acción "Regenerar ahora" junto al estado de generación. El origen de contenido SHALL almacenarse por post type y un post type sin valor almacenado SHALL tratarse como `auto`; un valor fuera de `auto`, `editor` y `rendered` SHALL sanearse a `auto`. El selector SHALL sanearse como texto de una línea; un selector fuera del subconjunto admitido MUST rechazarse conservando el valor almacenado y registrando un aviso de error de la Settings API. Ambos ajustes SHALL pertenecer al grupo de claves de la pestaña General, de modo que guardar esa pestaña los actualice y guardar cualquier otra pestaña los conserve, y su saneado MUST ser idempotente. Cuando el estado de generación registre fallos de renderizado, la pestaña SHALL mostrar el aviso descrito en el estado visible de la generación. El formulario de la acción manual MUST ser un formulario de primer nivel, nunca anidado dentro del formulario de la Settings API, y el botón "Guardar cambios" SHALL pertenecer al formulario de la Settings API y guardar los ajustes de la pestaña. Pulsar Intro en un campo de la pestaña SHALL enviar el formulario de ajustes, no el de la acción manual.

#### Scenario: Post types por defecto
- **WHEN** el plugin se activa por primera vez
- **THEN** los post types habilitados son exactamente `post` y `page`

#### Scenario: Post type no público
- **WHEN** existe un post type registrado como no público
- **THEN** no aparece como opción seleccionable

#### Scenario: Guardar la pestaña General
- **WHEN** un administrador cambia "Items per run" y pulsa "Guardar cambios"
- **THEN** la página vuelve a la pestaña General con la confirmación de guardado y el valor nuevo persistido

#### Scenario: Regenerar desde la pestaña General
- **WHEN** un administrador pulsa "Regenerar ahora" o "Regenerar todo" en la pestaña General
- **THEN** la petición llega al manejador de la acción manual, la página vuelve a la pestaña General con el aviso de programación y ningún ajuste se modifica

#### Scenario: Marcado de la pestaña General
- **WHEN** se renderiza la pestaña General completa para un administrador
- **THEN** el HTML contiene exactamente un formulario de la Settings API que incluye el botón "Guardar cambios", un formulario de primer nivel para la acción manual con su nonce, y ningún formulario abierto dentro de otro

#### Scenario: Origen por tipo por defecto
- **WHEN** el plugin se activa por primera vez y un administrador abre la pestaña General
- **THEN** cada post type habilitado muestra el origen `auto` seleccionado y el selector CSS vacío

#### Scenario: Guardar origen y selector
- **WHEN** un administrador pone el origen de `page` en `rendered`, escribe `main article` en el selector y guarda la pestaña General
- **THEN** los valores quedan almacenados, volver a sanearlos los deja iguales, y un origen enviado con valor `foo` se almacena como `auto`

#### Scenario: Selector rechazado
- **WHEN** el selector almacenado es `main` y un administrador guarda `div:has(p)`
- **THEN** el valor almacenado sigue siendo `main` y la página muestra un aviso de error indicando el subconjunto admitido

#### Scenario: Guardar otra pestaña
- **WHEN** el origen de `page` es `rendered` y el selector no está vacío y un administrador guarda la pestaña Señales
- **THEN** el origen y el selector conservan su valor

### Requirement: Exclusión por entrada
Para los post types habilitados, la pantalla de edición SHALL mostrar una casilla "Excluir de la capa de agentes" que, al activarse, retire el ítem de la entrega en Markdown, de `llms.txt` y de `llms-full.txt`. El valor SHALL guardarse como post meta protegido. En la API REST, el valor SHALL poder leerse y escribirse únicamente por usuarios con permiso de edición sobre la entrada; en la respuesta de lectura para cualquier otro usuario la clave MUST NOT aparecer en `meta`.

#### Scenario: Marcar exclusión
- **WHEN** un editor marca la casilla y guarda la entrada
- **THEN** la entrada deja de ser elegible en la siguiente petición

#### Scenario: Post type no habilitado
- **WHEN** se edita un ítem de un post type no habilitado
- **THEN** la casilla no se muestra

#### Scenario: Lectura REST anónima
- **WHEN** un cliente sin autenticar solicita `GET /wp/v2/posts/{id}` de una entrada con la exclusión activa
- **THEN** la respuesta no contiene la clave de exclusión en `meta`

#### Scenario: Lectura REST con permiso de edición
- **WHEN** un editor autenticado solicita `GET /wp/v2/posts/{id}?context=edit`
- **THEN** la respuesta contiene la clave de exclusión en `meta` con su valor

### Requirement: Requisitos mínimos
El plugin SHALL declarar `Requires at least: 7.0` y `Requires PHP: 7.4`. Si se activa en un entorno que no los cumpla, SHALL mostrar un aviso administrativo explicando el requisito no cumplido y MUST NOT cargar el resto de su funcionalidad.

#### Scenario: PHP inferior al mínimo
- **WHEN** el plugin se activa con PHP 7.3
- **THEN** se muestra un aviso con el requisito y ninguna funcionalidad del plugin queda activa

### Requirement: Ciclo de vida
Al activarse, el plugin SHALL crear el almacenamiento protegido, registrar las opciones por defecto, programar la generación y vaciar las reglas de reescritura. En una activación en red, los sitios creados después de la activación SHALL quedar configurados del mismo modo en el momento de su creación, y un sitio cuyo evento recurrente falte SHALL reprogramarlo al cargar su administración. Al desactivarse, SHALL desprogramar la generación sin vaciar las reglas de reescritura. Al desinstalarse, SHALL eliminar sus opciones, transients, el post meta de exclusión, el directorio de almacenamiento y todos sus eventos programados, incluido el evento único de la regeneración manual, sin afectar a ningún contenido del sitio. Los recorridos de sitios en red SHALL hacerse por páginas de tamaño acotado.

#### Scenario: Desinstalación limpia
- **WHEN** el plugin se desinstala
- **THEN** no quedan opciones con prefijo `wpasl_`, ni post meta de exclusión, ni el directorio de almacenamiento

#### Scenario: Desinstalación con evento único pendiente
- **WHEN** un administrador pulsó "Regenerar ahora" y el plugin se desinstala antes de que el evento único se ejecute
- **THEN** no queda ningún evento de generación programado, ni recurrente ni único

#### Scenario: Sitio creado tras la activación en red
- **WHEN** el plugin está activado en red y se crea un sitio nuevo
- **THEN** el sitio nuevo tiene sus opciones por defecto, su almacenamiento y su evento recurrente de generación sin que nadie visite su administración

### Requirement: Privacidad y ausencia de llamadas externas
El plugin MUST NOT realizar peticiones a servidores distintos del propio sitio ni enviar telemetría. Las peticiones que el diagnóstico realiza al propio sitio MUST NOT seguir redirecciones, de modo que una redirección hacia otro host nunca se siga. Todo texto de la interfaz SHALL ser traducible con el text domain `wp-agent-support-layer`.

#### Scenario: Sin llamadas externas
- **WHEN** se ejecuta cualquier funcionalidad del plugin, incluido el diagnóstico
- **THEN** ninguna petición HTTP sale hacia un host distinto del propio sitio

#### Scenario: Redirección a otro host
- **WHEN** una URL sondeada por el diagnóstico responde 302 hacia `https://www.example.com/` u otro host
- **THEN** el diagnóstico no realiza ninguna petición al host de destino y registra la redirección como advertencia

### Requirement: Sanitización idempotente de ajustes
La sanitización de los ajustes SHALL ser idempotente: aplicarla sobre un valor ya sanitizado MUST devolver el mismo valor. El tamaño máximo de `llms-full.txt` SHALL introducirse en el formulario en megabytes y almacenarse en bytes; un valor almacenado en bytes que vuelva a pasar por la sanitización MUST conservarse. Cuando la entrada no identifique una pestaña conocida, la sanitización SHALL actualizar únicamente las claves presentes en la entrada y conservar el resto de los valores almacenados; cuando identifique una pestaña desconocida, SHALL conservar todos los valores almacenados. Una entrada parcial MUST NOT vaciar los post types habilitados ni restablecer señales, interruptores o textos que no vengan en ella.

#### Scenario: Doble sanitización
- **WHEN** el formulario envía un tamaño máximo de 5 MB y el valor resultante vuelve a sanitizarse (por ejemplo, al crearse la opción por primera vez)
- **THEN** el valor almacenado sigue siendo 5 MB en bytes

#### Scenario: Valor fuera de rango
- **WHEN** el formulario envía un tamaño máximo de 500 MB
- **THEN** el valor almacenado es 100 MB en bytes

#### Scenario: Entrada parcial sin pestaña
- **WHEN** los ajustes almacenados tienen valores distintos de los por defecto y se guarda una entrada con solo `batch_size` y sin identificador de pestaña
- **THEN** `batch_size` se actualiza y el resto de los valores, incluidos los post types y las señales, se conservan

#### Scenario: Pestaña desconocida
- **WHEN** una pestaña registrada por un tercero guarda el formulario con un identificador de pestaña que el plugin no reconoce
- **THEN** los valores almacenados del plugin no cambian

### Requirement: Ajustes de auth.md en la pestaña Manifiestos
La pestaña Manifiestos SHALL ofrecer una casilla "Publish auth.md", activa por defecto y descrita como dependiente de "Publish manifests", y un área de texto opcional "auth.md notes (Markdown)" cuyo contenido se incluye tal cual en el documento. Ambos ajustes SHALL pertenecer al grupo de claves de la pestaña Manifiestos, de modo que guardar esa pestaña los actualice y guardar cualquier otra pestaña los conserve. Las notas SHALL sanearse como texto multilínea sin etiquetas HTML y SHALL limitarse a 4000 caracteres; la sanitización MUST ser idempotente. La lista de enlaces de la pestaña SHALL incluir la URL de `/auth.md` con su descripción, y la pestaña SHALL mostrar un aviso cuando exista un `auth.md` físico en la raíz del sitio.

#### Scenario: Valores por defecto
- **WHEN** el plugin se activa por primera vez
- **THEN** la publicación de `auth.md` está activa y las notas están vacías

#### Scenario: Guardar la pestaña Manifiestos
- **WHEN** un administrador desmarca "Publish auth.md", escribe notas y guarda la pestaña Manifiestos
- **THEN** la publicación queda desactivada, las notas quedan almacenadas sin etiquetas HTML y `/auth.md` responde 404 en la siguiente petición

#### Scenario: Guardar otra pestaña
- **WHEN** las notas de `auth.md` tienen contenido y un administrador guarda la pestaña General
- **THEN** las notas y la casilla de publicación conservan su valor

#### Scenario: Notas por encima del límite
- **WHEN** el formulario envía notas de 5000 caracteres
- **THEN** el valor almacenado tiene 4000 caracteres y volver a sanearlo lo deja igual

#### Scenario: Enlaces de la pestaña
- **WHEN** un administrador abre la pestaña Manifiestos
- **THEN** ve la URL de `/auth.md` junto a las de `agent-skills.json`, el documento OpenAPI y el catálogo de API, la casilla "Publish auth.md" y el área de notas

### Requirement: Ajustes de la pestaña llms.txt
La pestaña llms.txt SHALL ofrecer, además de la descripción, la introducción y los ajustes de `llms-full.txt`: un área de texto opcional "When to use this site (Markdown)" cuyo contenido se incluye tal cual en `llms.txt` y en `auth.md`, saneado como texto multilínea sin etiquetas HTML y limitado a 4000 caracteres con saneado idempotente; un campo numérico "Items per section in llms.txt" (límite de vista previa, por defecto 10, entre 1 y 100); y un campo numérico "Items per content-type file" (límite por tipo, por defecto 1000, entre 1 y 10000). El ajuste anterior "Items per section" (`llms_limit`) SHALL desaparecer de los valores por defecto y del formulario; un valor almacenado con esa clave MUST ignorarse sin error. Los tres ajustes SHALL pertenecer al grupo de claves de la pestaña llms.txt, de modo que guardar esa pestaña los actualice y guardar cualquier otra pestaña los conserve. La pestaña SHALL mostrar la lista de URLs de los archivos por tipo de los post types habilitados y el tamaño en caracteres del `llms.txt` generado que hay en el almacenamiento (o que aún no se ha generado), y SHALL mostrar un aviso cuando ese tamaño supere 30.000 caracteres recomendando reducir el límite de vista previa.

#### Scenario: Valores por defecto
- **WHEN** el plugin se activa por primera vez
- **THEN** la guía "cuándo usar este sitio" está vacía, el límite de vista previa es 10, el límite por tipo es 1000 y no existe la clave `llms_limit` en los valores por defecto

#### Scenario: Guardar la pestaña llms.txt
- **WHEN** un administrador escribe la guía con una etiqueta HTML, pone el límite de vista previa en 5 y el límite por tipo en 2000 y guarda la pestaña llms.txt
- **THEN** la guía queda almacenada sin la etiqueta, los límites valen 5 y 2000, y `llms.txt` se regenera con esos valores en la siguiente petición

#### Scenario: Límites fuera de rango
- **WHEN** el formulario envía un límite de vista previa de 500 y un límite por tipo de 50000
- **THEN** los valores almacenados son 100 y 10000; con valores 0 o vacíos, los almacenados son los por defecto

#### Scenario: Guía por encima del límite
- **WHEN** el formulario envía una guía de 5000 caracteres
- **THEN** el valor almacenado tiene 4000 caracteres y volver a sanearlo lo deja igual

#### Scenario: Guardar otra pestaña
- **WHEN** la guía tiene contenido, los límites no son los por defecto y un administrador guarda la pestaña General
- **THEN** la guía y los límites conservan su valor

#### Scenario: Valor antiguo de llms_limit
- **WHEN** la opción almacenada contiene `llms_limit` con valor 100 de una versión anterior
- **THEN** la sanitización y la lectura de ajustes no fallan, los límites nuevos toman sus valores por defecto y `llms_limit` no influye en `llms.txt`

#### Scenario: Aviso de tamaño
- **WHEN** el `llms.txt` almacenado tiene 82.000 caracteres y un administrador abre la pestaña llms.txt
- **THEN** la pestaña muestra el tamaño y un aviso de que supera los 30.000 caracteres recomendados; con 20.000 caracteres muestra el tamaño sin aviso; sin archivo almacenado indica que aún no se ha generado

#### Scenario: URLs de los archivos por tipo
- **WHEN** `post` y `page` están habilitados y un administrador abre la pestaña llms.txt
- **THEN** ve las URLs de `/llms-page.txt` y `/llms-post.txt` junto a la de `/llms.txt`

### Requirement: Origen del contenido por entrada
Para los post types habilitados, el meta box de la capa de agentes en la pantalla de edición SHALL mostrar, junto a la casilla de exclusión, un selector "Markdown content source" con las opciones "Follow the settings" (por defecto), "Editor content" y "Rendered page". El valor SHALL guardarse como post meta protegido con el prefijo del plugin; el valor por defecto no SHALL almacenarse. En la API REST, el valor SHALL poder leerse y escribirse únicamente por usuarios con permiso de edición sobre la entrada, con saneado a `editor`, `rendered` o vacío; en la respuesta de lectura para cualquier otro usuario la clave MUST NOT aparecer en `meta`. Guardar el meta box MUST NOT generar ningún documento.

#### Scenario: Anular el origen de una entrada
- **WHEN** un editor elige "Rendered page" y guarda la entrada
- **THEN** la meta queda almacenada con `rendered` y la siguiente generación del documento usa la página renderizada; al volver a "Follow the settings" la meta se elimina

#### Scenario: Post type no habilitado
- **WHEN** se edita un ítem de un post type no habilitado
- **THEN** el selector no se muestra

#### Scenario: Lectura REST anónima
- **WHEN** un cliente sin autenticar solicita `GET /wp/v2/posts/{id}` de una entrada con el origen anulado
- **THEN** la respuesta no contiene la clave del origen en `meta`

#### Scenario: Escritura REST con permiso de edición
- **WHEN** un editor autenticado escribe `rendered` en la clave del origen mediante la API REST y luego escribe `foo`
- **THEN** el primer valor se almacena y el segundo se sanea a vacío
