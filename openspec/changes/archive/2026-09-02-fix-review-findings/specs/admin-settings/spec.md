## MODIFIED Requirements

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

### Requirement: Privacidad y ausencia de llamadas externas
El plugin MUST NOT realizar peticiones a servidores distintos del propio sitio ni enviar telemetría. Las peticiones que el diagnóstico realiza al propio sitio MUST NOT seguir redirecciones, de modo que una redirección hacia otro host nunca se siga. Todo texto de la interfaz SHALL ser traducible con el text domain `wp-agent-support-layer`.

#### Scenario: Sin llamadas externas
- **WHEN** se ejecuta cualquier funcionalidad del plugin, incluido el diagnóstico
- **THEN** ninguna petición HTTP sale hacia un host distinto del propio sitio

#### Scenario: Redirección a otro host
- **WHEN** una URL sondeada por el diagnóstico responde 302 hacia `https://www.example.com/` u otro host
- **THEN** el diagnóstico no realiza ninguna petición al host de destino y registra la redirección como advertencia

## ADDED Requirements

### Requirement: Sanitización idempotente de ajustes
La sanitización de los ajustes SHALL ser idempotente: aplicarla sobre un valor ya sanitizado MUST devolver el mismo valor. El tamaño máximo de `llms-full.txt` SHALL introducirse en el formulario en megabytes y almacenarse en bytes; un valor almacenado en bytes que vuelva a pasar por la sanitización MUST conservarse.

#### Scenario: Doble sanitización
- **WHEN** el formulario envía un tamaño máximo de 5 MB y el valor resultante vuelve a sanitizarse (por ejemplo, al crearse la opción por primera vez)
- **THEN** el valor almacenado sigue siendo 5 MB en bytes

#### Scenario: Valor fuera de rango
- **WHEN** el formulario envía un tamaño máximo de 500 MB
- **THEN** el valor almacenado es 100 MB en bytes
