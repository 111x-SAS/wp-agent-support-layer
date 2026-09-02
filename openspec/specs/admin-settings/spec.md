# admin-settings Specification

## Purpose
Concentra la configuración del plugin en una página simple bajo Herramientas, con valores por defecto seguros, y garantiza requisitos mínimos, exclusión por entrada y ciclo de vida limpio (activación, desactivación, desinstalación).

## Requirements

### Requirement: Página de ajustes en Herramientas
El sistema SHALL registrar una única página "Agent Support Layer" bajo el menú Herramientas, accesible solo a usuarios con `manage_options`, organizada en pestañas: General, Señales, Crawlers, llms.txt, Manifiestos y Diagnóstico. Todos los ajustes SHALL guardarse mediante la Settings API con sanitización y nonce.

#### Scenario: Acceso con permisos
- **WHEN** un administrador abre Herramientas
- **THEN** ve el submenú "Agent Support Layer" y puede cargar la página

#### Scenario: Acceso sin permisos
- **WHEN** un usuario con rol Editor intenta cargar la página por URL
- **THEN** el sistema deniega el acceso

### Requirement: Ajustes de la pestaña General
La pestaña General SHALL permitir elegir los post types habilitados entre los post types públicos (por defecto `post` y `page`), el intervalo de regeneración, el tamaño de lote y ofrecer la acción "Regenerar ahora" junto al estado de generación.

#### Scenario: Post types por defecto
- **WHEN** el plugin se activa por primera vez
- **THEN** los post types habilitados son exactamente `post` y `page`

#### Scenario: Post type no público
- **WHEN** existe un post type registrado como no público
- **THEN** no aparece como opción seleccionable

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
Al activarse, el plugin SHALL crear el almacenamiento protegido, registrar las opciones por defecto, programar la generación y vaciar las reglas de reescritura. Al desactivarse, SHALL desprogramar la generación. Al desinstalarse, SHALL eliminar sus opciones, transients, el post meta de exclusión y el directorio de almacenamiento, sin afectar a ningún contenido del sitio.

#### Scenario: Desinstalación limpia
- **WHEN** el plugin se desinstala
- **THEN** no quedan opciones con prefijo `wpasl_`, ni post meta de exclusión, ni el directorio de almacenamiento

### Requirement: Privacidad y ausencia de llamadas externas
El plugin MUST NOT realizar peticiones a servidores distintos del propio sitio ni enviar telemetría. Las peticiones que el diagnóstico realiza al propio sitio MUST NOT seguir redirecciones, de modo que una redirección hacia otro host nunca se siga. Todo texto de la interfaz SHALL ser traducible con el text domain `wp-agent-support-layer`.

#### Scenario: Sin llamadas externas
- **WHEN** se ejecuta cualquier funcionalidad del plugin, incluido el diagnóstico
- **THEN** ninguna petición HTTP sale hacia un host distinto del propio sitio

#### Scenario: Redirección a otro host
- **WHEN** una URL sondeada por el diagnóstico responde 302 hacia `https://www.example.com/` u otro host
- **THEN** el diagnóstico no realiza ninguna petición al host de destino y registra la redirección como advertencia

### Requirement: Sanitización idempotente de ajustes
La sanitización de los ajustes SHALL ser idempotente: aplicarla sobre un valor ya sanitizado MUST devolver el mismo valor. El tamaño máximo de `llms-full.txt` SHALL introducirse en el formulario en megabytes y almacenarse en bytes; un valor almacenado en bytes que vuelva a pasar por la sanitización MUST conservarse.

#### Scenario: Doble sanitización
- **WHEN** el formulario envía un tamaño máximo de 5 MB y el valor resultante vuelve a sanitizarse (por ejemplo, al crearse la opción por primera vez)
- **THEN** el valor almacenado sigue siendo 5 MB en bytes

#### Scenario: Valor fuera de rango
- **WHEN** el formulario envía un tamaño máximo de 500 MB
- **THEN** el valor almacenado es 100 MB en bytes
