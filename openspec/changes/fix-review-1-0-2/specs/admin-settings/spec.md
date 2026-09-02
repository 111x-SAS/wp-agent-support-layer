## MODIFIED Requirements

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
La pestaña General SHALL permitir elegir los post types habilitados entre los post types públicos (por defecto `post` y `page`), el intervalo de regeneración, el tamaño de lote y ofrecer la acción "Regenerar ahora" junto al estado de generación. El formulario de la acción manual MUST ser un formulario de primer nivel, nunca anidado dentro del formulario de la Settings API, y el botón "Guardar cambios" SHALL pertenecer al formulario de la Settings API y guardar los ajustes de la pestaña. Pulsar Intro en un campo de la pestaña SHALL enviar el formulario de ajustes, no el de la acción manual.

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
