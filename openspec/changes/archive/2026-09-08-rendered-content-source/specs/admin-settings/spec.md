## MODIFIED Requirements

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

## ADDED Requirements

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
