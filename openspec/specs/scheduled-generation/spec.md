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
Cada ejecución del evento SHALL procesar como máximo el número de ítems configurado (por defecto 50) y SHALL detenerse al superar un presupuesto de tiempo de 20 segundos, guardando la posición para continuar en la siguiente ejecución. Los ítems SHALL procesarse en orden de generación más antigua primero, incluyendo los que nunca se han generado. Al comenzar cada ejecución, los ítems elegibles que nunca se hayan generado y no estén en la cola SHALL incorporarse al frente de la cola en curso.

#### Scenario: Sitio con más ítems que el tamaño del lote
- **WHEN** hay 120 ítems elegibles y el tamaño de lote es 50
- **THEN** la primera ejecución genera 50 documentos, la segunda otros 50 y la tercera los 20 restantes

#### Scenario: Presupuesto de tiempo agotado
- **WHEN** una ejecución supera los 20 segundos antes de completar el lote
- **THEN** la ejecución termina, guarda la posición y la siguiente ejecución continúa desde ella

#### Scenario: Alta a mitad de ciclo
- **WHEN** se publica una entrada nueva mientras un ciclo tiene ítems pendientes en la cola
- **THEN** la siguiente ejecución genera el documento de la entrada nueva antes de continuar con el resto de la cola

### Requirement: Regeneración de archivos de descubrimiento
Al completar un ciclo sobre todos los ítems elegibles, la ejecución SHALL regenerar `llms.txt`, `llms-full.txt` (si está habilitado), `agent-skills.json`, el documento OpenAPI y el catálogo de API. Además, cuando una ejecución termine sin completar el ciclo pero el último ciclo completo sea más antiguo que el intervalo configurado (o nunca se haya completado), la ejecución SHALL regenerar igualmente los archivos de descubrimiento, de modo que en sitios grandes nunca queden más de un intervalo desactualizados.

#### Scenario: Ciclo completo
- **WHEN** una ejecución procesa el último ítem pendiente del ciclo
- **THEN** los archivos de descubrimiento se regeneran en la misma ejecución

#### Scenario: Ciclo largo en un sitio grande
- **WHEN** el intervalo es `daily`, el ciclo lleva más de un día sin completarse y una ejecución termina con ítems pendientes
- **THEN** los archivos de descubrimiento se regeneran en esa ejecución con los documentos disponibles

### Requirement: Limpieza de documentos no elegibles
Cada ciclo SHALL eliminar del almacenamiento los documentos cuyo contenido ya no sea elegible. Además, cuando una entrada pase de estado `publish` a cualquier otro estado, o sea eliminada, el sistema SHALL eliminar su documento almacenado de forma inmediata sin generar ningún documento nuevo.

#### Scenario: Entrada enviada a papelera
- **WHEN** una entrada con documento almacenado se envía a la papelera
- **THEN** su documento se elimina del almacenamiento en ese momento

#### Scenario: Post type deshabilitado
- **WHEN** un administrador deshabilita el post type `page` y se ejecuta el siguiente ciclo
- **THEN** los documentos de páginas se eliminan del almacenamiento

### Requirement: Sin generación en el flujo de edición
El sistema MUST NOT convertir ni generar documentos como reacción a la creación, actualización o publicación de una entrada. La entrada publicada SHALL quedar pendiente y procesarse en la siguiente ejecución programada aunque haya un ciclo en curso.

#### Scenario: Publicar una entrada nueva
- **WHEN** un editor publica una entrada nueva
- **THEN** no se genera ningún documento en esa petición y el ítem queda pendiente para la siguiente ejecución programada

### Requirement: Regeneración manual
El panel de ajustes SHALL ofrecer una acción "Regenerar ahora" que programe una ejecución inmediata en segundo plano y devuelva el control al administrador sin esperar a que termine. La acción MUST requerir la capacidad `manage_options` y un nonce válido. La ejecución inmediata SHALL programarse como evento único y MUST NOT modificar el horario del evento recurrente. El formulario de la acción SHALL enviarse al manejador de acciones administrativas del plugin y MUST ser un formulario de primer nivel de la página, nunca anidado dentro del formulario de la Settings API.

#### Scenario: Administrador pulsa Regenerar ahora
- **WHEN** un administrador con `manage_options` envía la acción con nonce válido
- **THEN** se programa una ejecución inmediata y la página muestra confirmación sin ejecutar la generación en la misma petición

#### Scenario: Petición sin nonce válido
- **WHEN** se envía la acción sin nonce válido
- **THEN** el sistema rechaza la acción y no programa nada

#### Scenario: Horario recurrente intacto
- **WHEN** el evento recurrente está programado para dentro de 6 horas y un administrador pulsa Regenerar ahora
- **THEN** existe un evento único inmediato y el evento recurrente sigue programado para dentro de 6 horas

#### Scenario: Botón de la pestaña General
- **WHEN** un administrador pulsa el botón "Regenerar ahora" renderizado en la pestaña General de un WordPress limpio
- **THEN** el navegador envía el formulario de la acción manual (no el de ajustes), la respuesta es una redirección a la pestaña General con el aviso de programación y existe un evento único inmediato

### Requirement: Estado visible de la generación
El panel SHALL mostrar la fecha de la última ejecución, la fecha de la siguiente ejecución programada, el total de ítems elegibles, cuántos tienen documento generado, cuántos están pendientes y cuántos tienen fallos registrados, y SHALL advertir cuando WP-Cron esté desactivado.

#### Scenario: WP-Cron desactivado
- **WHEN** la constante `DISABLE_WP_CRON` es verdadera
- **THEN** el panel muestra una advertencia indicando que la generación depende de un cron del sistema o de WP-CLI

#### Scenario: Ítems con fallos
- **WHEN** dos ítems tienen fallos registrados
- **THEN** el panel y el comando de estado muestran "2" como ítems con fallos

### Requirement: Comandos WP-CLI
El sistema SHALL exponer comandos WP-CLI para generar todos los documentos pendientes o de un post type concreto, forzar la regeneración completa, mostrar el estado y vaciar el almacenamiento. Cuando se indique un post type, el comando MUST procesar únicamente ítems de ese post type en todas sus combinaciones: con y sin límite de lote, y aunque el post type no tenga ningún ítem elegible. Cuando se combine la regeneración completa con un post type, el comando SHALL invalidar solo los documentos de ese post type y conservar las marcas de generación del resto. El comando de generación MUST terminar con error, y no con éxito, cuando el post type indicado no esté habilitado en los ajustes o cuando la librería de conversión no esté disponible.

#### Scenario: Generación completa por WP-CLI
- **WHEN** se ejecuta el comando de generación con la opción de regeneración completa
- **THEN** todos los ítems elegibles obtienen documento actualizado y el comando informa del total procesado

#### Scenario: Vaciar almacenamiento
- **WHEN** se ejecuta el comando de vaciado
- **THEN** no queda ningún documento ni archivo de descubrimiento en el almacenamiento y el estado muestra todos los ítems pendientes

#### Scenario: Post type no habilitado
- **WHEN** se ejecuta el comando de generación con un post type que no está habilitado en los ajustes
- **THEN** el comando termina con un error que indica el post type y no reporta éxito

#### Scenario: Post type con lote
- **WHEN** hay una entrada y una página pendientes y se ejecuta la generación de `page` con límite de lote
- **THEN** solo existe el documento de la página y el total informado es 1

#### Scenario: Post type sin ítems elegibles
- **WHEN** hay tres entradas pendientes, ninguna página, y se ejecuta la generación de `page`
- **THEN** no se genera ningún documento y el total informado es 0

#### Scenario: Regeneración completa de un post type
- **WHEN** entradas y páginas tienen documento generado y se ejecuta la regeneración completa de `page`
- **THEN** las páginas se regeneran y las entradas conservan su marca de generación sin figurar como pendientes

### Requirement: Almacenamiento protegido
Los documentos generados SHALL guardarse dentro del directorio de uploads del sitio, en un subdirectorio propio protegido contra acceso directo por HTTP, y MUST servirse únicamente a través del plugin. La protección SHALL incluir reglas de denegación para Apache y para IIS y un archivo índice vacío en la raíz del almacenamiento y en cada subdirectorio que el sistema cree. Los archivos temporales de escritura atómica que queden huérfanos SHALL eliminarse en la limpieza de cada ciclo. En multisitio, cada sitio SHALL usar su propio almacenamiento.

#### Scenario: Acceso directo al archivo
- **WHEN** un cliente solicita por HTTP la ruta directa de un documento almacenado en un servidor Apache
- **THEN** el servidor deniega el acceso

#### Scenario: Multisitio
- **WHEN** dos sitios de una red generan documentos
- **THEN** cada sitio escribe en su propio directorio de uploads y ninguno sirve documentos del otro

#### Scenario: Subdirectorios protegidos
- **WHEN** se escribe el primer documento de un post type
- **THEN** el directorio de ese post type contiene un archivo índice vacío y la raíz del almacenamiento contiene las reglas de denegación para Apache e IIS

#### Scenario: Temporal huérfano
- **WHEN** existe un archivo temporal de escritura con más de una hora de antigüedad y se ejecuta la limpieza del ciclo
- **THEN** el archivo temporal se elimina

### Requirement: Reinicio de la cola al cambiar los post types
Cuando cambie el conjunto de post types habilitados, el sistema SHALL descartar la cola en curso para que la siguiente ejecución la reconstruya con el nuevo conjunto.

#### Scenario: Post type añadido
- **WHEN** un administrador habilita `product` mientras hay una cola en curso
- **THEN** la siguiente ejecución construye una cola nueva que incluye los productos elegibles

### Requirement: Estado de generación resistente a escrituras concurrentes
El registro de qué ítems tienen documento generado y la cola pendiente SHALL sobrevivir a escrituras concurrentes: una marca de generación registrada por una petición bajo demanda mientras una ejecución programada está en curso MUST conservarse cuando la ejecución guarde su estado, y viceversa. El estado MUST NOT reescribirse cuando la operación no lo altere: despublicar o eliminar un ítem de un post type no habilitado MUST NOT escribir el estado, y la generación de documentos que ocurra dentro de una ejecución programada SHALL registrarse en el estado de esa ejecución en lugar de escribir la opción por cada ítem.

#### Scenario: Generación bajo demanda durante una ejecución
- **WHEN** una ejecución programada carga el estado, una petición bajo demanda genera y marca otro ítem, y después la ejecución guarda su estado
- **THEN** el estado guardado contiene la marca del ítem generado bajo demanda y las marcas de los ítems de la ejecución

#### Scenario: Despublicación de un post type no habilitado
- **WHEN** se envía a la papelera un ítem de un post type no habilitado
- **THEN** el valor almacenado del estado de generación no cambia

#### Scenario: Construcción de llms-full.txt dentro de una ejecución
- **WHEN** una ejecución programada construye `llms-full.txt` y genera 100 documentos que faltaban
- **THEN** el estado se escribe una sola vez al final de la ejecución y contiene las 100 marcas

### Requirement: Listado de ítems elegibles acotado en consultas
Obtener la lista de ítems elegibles SHALL requerir un número de consultas a la base de datos acotado por una constante pequeña, independiente del número de ítems, salvo que un filtro externo obligue a evaluar cada ítem individualmente. El total de elegibles mostrado en el estado de generación SHALL obtenerse con una consulta de conteo.

#### Scenario: Estado con muchos ítems
- **WHEN** hay 1000 ítems elegibles sin filtros externos de elegibilidad y se abre la pestaña General
- **THEN** el cálculo de elegibles ejecuta menos de 10 consultas a la base de datos

### Requirement: Fallos de generación visibles y despriorizados
Cuando la generación de un ítem falle (el conversor no devuelve un documento o el documento no puede escribirse en el almacenamiento), el sistema SHALL registrar el fallo en el estado de generación con un contador por ítem, SHALL exponer el número de ítems con fallos en el estado visible del panel y en el comando de estado de WP-CLI, SHALL emitir una acción con el ítem y el motivo y SHALL escribir el motivo en el registro de errores de PHP. Un ítem que haya fallado un número de veces igual o superior a un umbral (por defecto 3) MUST NOT volver a colocarse al frente de la cola en cada ejecución; SHALL reintentarse solo al final de la cola y su contador SHALL reiniciarse cuando la generación tenga éxito.

#### Scenario: Fallo persistente de escritura
- **WHEN** el almacenamiento no admite escritura y se ejecutan tres ejecuciones consecutivas
- **THEN** el estado muestra el número de ítems con fallos, la cuarta ejecución procesa primero los ítems sin fallos y ningún ítem fallido ocupa el lote completo

#### Scenario: Fallo resuelto
- **WHEN** un ítem con dos fallos registrados se genera con éxito
- **THEN** su contador de fallos desaparece del estado y el ítem figura como generado

#### Scenario: Acción y registro
- **WHEN** falla la escritura del documento de un ítem
- **THEN** se emite la acción de fallo con el ítem y el motivo, y el registro de errores de PHP contiene una línea con el identificador del ítem y el motivo
