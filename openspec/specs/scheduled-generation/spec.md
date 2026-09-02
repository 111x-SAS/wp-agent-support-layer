# scheduled-generation Specification

## Purpose
Genera y mantiene por programación, en lotes acotados y sin intervenir en el flujo de edición, los documentos Markdown y los archivos de descubrimiento que el resto de capacidades sirven.

## Requirements

### Requirement: Evento programado recurrente
El sistema SHALL registrar un único evento programado recurrente con el intervalo configurado en ajustes (`hourly`, `twicedaily`, `daily` o `weekly`; por defecto `daily`). Cambiar el intervalo SHALL reprogramar el evento. Desactivar el plugin SHALL eliminar el evento.

#### Scenario: Programación al activar
- **WHEN** el plugin se activa
- **THEN** existe exactamente un evento recurrente de generación con el intervalo por defecto `daily`

#### Scenario: Cambio de intervalo
- **WHEN** un administrador cambia el intervalo a `hourly`
- **THEN** el evento recurrente queda programado con intervalo `hourly` y no quedan eventos con el intervalo anterior

#### Scenario: Desactivación
- **WHEN** el plugin se desactiva
- **THEN** no queda ningún evento de generación programado

### Requirement: Ejecución por lotes acotada
Cada ejecución del evento SHALL procesar como máximo el número de ítems configurado (por defecto 50) y SHALL detenerse al superar un presupuesto de tiempo de 20 segundos, guardando la posición para continuar en la siguiente ejecución. Los ítems SHALL procesarse en orden de generación más antigua primero, incluyendo los que nunca se han generado.

#### Scenario: Sitio con más ítems que el tamaño del lote
- **WHEN** hay 120 ítems elegibles y el tamaño de lote es 50
- **THEN** la primera ejecución genera 50 documentos, la segunda otros 50 y la tercera los 20 restantes

#### Scenario: Presupuesto de tiempo agotado
- **WHEN** una ejecución supera los 20 segundos antes de completar el lote
- **THEN** la ejecución termina, guarda la posición y la siguiente ejecución continúa desde ella

### Requirement: Regeneración de archivos de descubrimiento
Al completar un ciclo sobre todos los ítems elegibles, la ejecución SHALL regenerar `llms.txt`, `llms-full.txt` (si está habilitado), `agent-skills.json`, el documento OpenAPI y el catálogo de API.

#### Scenario: Ciclo completo
- **WHEN** una ejecución procesa el último ítem pendiente del ciclo
- **THEN** los archivos de descubrimiento se regeneran en la misma ejecución

### Requirement: Limpieza de documentos no elegibles
Cada ciclo SHALL eliminar del almacenamiento los documentos cuyo contenido ya no sea elegible. Además, cuando una entrada pase de estado `publish` a cualquier otro estado, o sea eliminada, el sistema SHALL eliminar su documento almacenado de forma inmediata sin generar ningún documento nuevo.

#### Scenario: Entrada enviada a papelera
- **WHEN** una entrada con documento almacenado se envía a la papelera
- **THEN** su documento se elimina del almacenamiento en ese momento

#### Scenario: Post type deshabilitado
- **WHEN** un administrador deshabilita el post type `page` y se ejecuta el siguiente ciclo
- **THEN** los documentos de páginas se eliminan del almacenamiento

### Requirement: Sin generación en el flujo de edición
El sistema MUST NOT convertir ni generar documentos como reacción a la creación, actualización o publicación de una entrada.

#### Scenario: Publicar una entrada nueva
- **WHEN** un editor publica una entrada nueva
- **THEN** no se genera ningún documento en esa petición y el ítem queda pendiente para la siguiente ejecución programada

### Requirement: Regeneración manual
El panel de ajustes SHALL ofrecer una acción "Regenerar ahora" que programe una ejecución inmediata en segundo plano y devuelva el control al administrador sin esperar a que termine. La acción MUST requerir la capacidad `manage_options` y un nonce válido.

#### Scenario: Administrador pulsa Regenerar ahora
- **WHEN** un administrador con `manage_options` envía la acción con nonce válido
- **THEN** se programa una ejecución inmediata y la página muestra confirmación sin ejecutar la generación en la misma petición

#### Scenario: Petición sin nonce válido
- **WHEN** se envía la acción sin nonce válido
- **THEN** el sistema rechaza la acción y no programa nada

### Requirement: Estado visible de la generación
El panel SHALL mostrar la fecha de la última ejecución, la fecha de la siguiente ejecución programada, el total de ítems elegibles, cuántos tienen documento generado y cuántos están pendientes, y SHALL advertir cuando WP-Cron esté desactivado.

#### Scenario: WP-Cron desactivado
- **WHEN** la constante `DISABLE_WP_CRON` es verdadera
- **THEN** el panel muestra una advertencia indicando que la generación depende de un cron del sistema o de WP-CLI

### Requirement: Comandos WP-CLI
El sistema SHALL exponer comandos WP-CLI para generar todos los documentos pendientes o de un post type concreto, forzar la regeneración completa, mostrar el estado y vaciar el almacenamiento.

#### Scenario: Generación completa por WP-CLI
- **WHEN** se ejecuta el comando de generación con la opción de regeneración completa
- **THEN** todos los ítems elegibles obtienen documento actualizado y el comando informa del total procesado

#### Scenario: Vaciar almacenamiento
- **WHEN** se ejecuta el comando de vaciado
- **THEN** no queda ningún documento ni archivo de descubrimiento en el almacenamiento y el estado muestra todos los ítems pendientes

### Requirement: Almacenamiento protegido
Los documentos generados SHALL guardarse dentro del directorio de uploads del sitio, en un subdirectorio propio protegido contra acceso directo por HTTP, y MUST servirse únicamente a través del plugin. En multisitio, cada sitio SHALL usar su propio almacenamiento.

#### Scenario: Acceso directo al archivo
- **WHEN** un cliente solicita por HTTP la ruta directa de un documento almacenado en un servidor Apache
- **THEN** el servidor deniega el acceso

#### Scenario: Multisitio
- **WHEN** dos sitios de una red generan documentos
- **THEN** cada sitio escribe en su propio directorio de uploads y ninguno sirve documentos del otro
