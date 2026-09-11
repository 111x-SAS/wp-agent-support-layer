## MODIFIED Requirements

### Requirement: Ajustes de la pestaña General
La pestaña General SHALL permitir elegir los post types habilitados entre los post types públicos (por defecto `post` y `page`), el intervalo de regeneración, el tamaño de lote y un campo de texto "Content selector (CSS)" opcional (vacío por defecto, con la descripción de que vacío significa detección automática), y ofrecer la acción "Regenerar ahora" junto al estado de generación. La pestaña MUST NOT mostrar ni permitir fijar el origen de contenido por post type ("Content source"): ese origen queda siempre en `auto` salvo que se fije por una vía ajena a esta pestaña (por ejemplo un filtro o una escritura directa del ajuste). Guardar la pestaña General SHALL sanear el origen de contenido almacenado a ausente para todo post type, equivalente a `auto`, porque el formulario ya no lo envía; esto no SHALL afectar al selector CSS ni a los ajustes de otras pestañas. El selector SHALL sanearse como texto de una línea; un selector fuera del subconjunto admitido MUST rechazarse conservando el valor almacenado y registrando un aviso de error de la Settings API. Este ajuste SHALL pertenecer al grupo de claves de la pestaña General, de modo que guardar esa pestaña lo actualice y guardar cualquier otra pestaña lo conserve, y su saneado MUST ser idempotente. Cuando el estado de generación registre fallos de renderizado, la pestaña SHALL mostrar el aviso descrito en el estado visible de la generación. El formulario de la acción manual MUST ser un formulario de primer nivel, nunca anidado dentro del formulario de la Settings API, y el botón "Guardar cambios" SHALL pertenecer al formulario de la Settings API y guardar los ajustes de la pestaña. Pulsar Intro en un campo de la pestaña SHALL enviar el formulario de ajustes, no el de la acción manual.

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
- **THEN** el HTML no contiene ningún selector de origen y cada post type habilitado se resuelve como `auto`

#### Scenario: Guardar origen y selector
- **WHEN** un administrador escribe `main article` en el selector y guarda la pestaña General
- **THEN** el selector queda almacenado, volver a sanearlo lo deja igual, y el origen de contenido de todo post type habilitado permanece `auto` porque la pestaña no lo envía

#### Scenario: Guardar la pestaña General limpia un origen forzado
- **WHEN** un post type tiene el origen de contenido forzado en `rendered` por una escritura ajena a esta pestaña y un administrador guarda la pestaña General
- **THEN** el origen almacenado para ese post type queda vacío y el post type se resuelve como `auto` en el siguiente ciclo

#### Scenario: Selector rechazado
- **WHEN** el selector almacenado es `main` y un administrador guarda `div:has(p)`
- **THEN** el valor almacenado sigue siendo `main` y la página muestra un aviso de error indicando el subconjunto admitido

#### Scenario: Guardar otra pestaña
- **WHEN** el origen de `page` es `rendered` y el selector no está vacío y un administrador guarda la pestaña Señales
- **THEN** el origen y el selector conservan su valor
