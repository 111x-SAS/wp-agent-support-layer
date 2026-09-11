## Why

La pestaña General expone un selector "Content source" por cada post type habilitado (Auto/Editor content/Rendered page), pero en la práctica ningún sitio necesita forzar `editor` o `rendered`: `auto` ya resuelve correctamente el origen del contenido y el selector solo añade una decisión manual que el administrador no quiere gestionar. Se retira el control visual para que el origen quede siempre en `auto`, sin tocar la lógica de resolución existente.

## What Changes

- Se retira de la pestaña General el fieldset "Content source" (el `<select>` Auto/Editor/Rendered por post type habilitado y su párrafo de ayuda).
- El origen de contenido deja de ser configurable desde la interfaz: todo post type se resuelve en modo `auto`, tal como ya ocurre hoy para un post type sin valor almacenado.
- **BREAKING**: un administrador que hubiera forzado `editor` o `rendered` para algún post type deja de poder cambiarlo desde la pantalla; el siguiente guardado de la pestaña General limpia cualquier valor forzado que existiera (la pestaña ya no envía `content_source`, y el saneado de esa pestaña trata la ausencia del campo como ningún valor forzado).
- Sin cambios en `Settings::content_source()`, el caso `content_source` del saneado, `ContentSource` ni en los tests existentes (`tests/test-settings.php`, `tests/test-content-source.php`): la resolución `auto`/`editor`/`rendered` sigue disponible para quien la fije por otra vía (filtro, `update_option` directo, meta box por entrada).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `admin-settings`: la pestaña General deja de mostrar y de permitir guardar el origen de contenido por post type; ese ajuste desaparece de su marcado y de los escenarios que lo cubrían, y guardar la pestaña General ya no lo modifica intencionalmente (queda en `auto` por ausencia de valor).

## Impact

- Código modificado: `src/Admin/Tabs/GeneralTab.php` (se retira el fieldset "Content source" y la variable `$sources` que solo alimentaba ese bloque).
- Sin cambios: `src/Settings.php`, `src/Markdown/ContentSource.php`, `src/Markdown/DocumentBuilder.php`, `src/CLI/Commands.php`, `src/Admin/ExcludeMetaBox.php` (el meta box por entrada conserva su propio selector "Markdown content source", que no se ve afectado).
- Tests: `tests/test-content-source.php` no cambia (prueba la resolución `auto`/`editor`/`rendered`, no la interfaz). `tests/test-settings.php::test_general_tab_renders_content_source_and_selector` SHALL actualizarse: deja de esperar los `<select name="wpasl_settings[content_source][...]">` y el texto "Content source" en el marcado, conserva las aserciones sobre "Content selector (CSS)" y su descripción, y dentro del mismo test se sanea la aserción de la línea 350 ('value="div.entry-content &gt; .inner"'), que no depende del fieldset retirado. El resto de `test-settings.php` (saneado de `content_source`, persistencia en `store_non_defaults()`) no cambia porque prueba `Settings`, no la pestaña.
- Sin cambio de versión ni de `readme.txt`, `README.md` o `languages/`.
