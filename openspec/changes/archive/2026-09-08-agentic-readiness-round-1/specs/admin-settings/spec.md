## ADDED Requirements

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
