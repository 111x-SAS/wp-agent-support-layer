## ADDED Requirements

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
