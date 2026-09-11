# scheduled-generation Specification

## Purpose
Genera y mantiene por programación, en lotes acotados y sin generar documentos en el flujo de edición, los documentos Markdown y los archivos de descubrimiento que el resto de capacidades sirven.

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
Cada ejecución del evento SHALL procesar como máximo el número de ítems configurado (por defecto 50) y SHALL detenerse al superar un presupuesto de tiempo de 20 segundos, guardando la posición para continuar en la siguiente ejecución. Los ítems SHALL procesarse en orden de generación más antigua primero, incluyendo los que nunca se han generado. Al comenzar cada ejecución, los ítems elegibles que nunca se hayan generado y no estén en la cola SHALL incorporarse al frente de la cola en curso, seguidos de los ítems cuyo último loopback de renderizado falló. El loopback de renderizado de un ítem MUST NOT esperar más allá del final del presupuesto de la ejecución: su tiempo máximo SHALL acotarse al tiempo restante y, cuando el tiempo restante sea inferior a un mínimo (2 segundos), el ítem SHALL diferirse sin contarse como fallo, volviendo al frente de la cola, y la ejecución SHALL terminar. Fuera de una ejecución programada (generación bajo demanda o WP-CLI sin presupuesto) el loopback SHALL usar su tiempo máximo configurado.

#### Scenario: Sitio con más ítems que el tamaño del lote
- **WHEN** hay 120 ítems elegibles y el tamaño de lote es 50
- **THEN** la primera ejecución genera 50 documentos, la segunda otros 50 y la tercera los 20 restantes

#### Scenario: Presupuesto de tiempo agotado
- **WHEN** una ejecución supera los 20 segundos antes de completar el lote
- **THEN** la ejecución termina, guarda la posición y la siguiente ejecución continúa desde ella

#### Scenario: Alta a mitad de ciclo
- **WHEN** se publica una entrada nueva mientras un ciclo tiene ítems pendientes en la cola
- **THEN** la siguiente ejecución genera el documento de la entrada nueva antes de continuar con el resto de la cola

#### Scenario: Loopback acotado por el presupuesto
- **WHEN** una ejecución con presupuesto de 20 segundos lleva 15 segundos consumidos y el siguiente ítem tiene origen `rendered` con tiempo máximo configurado de 10 segundos
- **THEN** la petición de renderizado se realiza con un tiempo máximo de 5 segundos

#### Scenario: Ítem diferido al final del presupuesto
- **WHEN** una ejecución lleva consumidos 19 segundos de 20 y el siguiente ítem tiene origen `rendered`
- **THEN** el ítem no se procesa, vuelve al frente de la cola, no suma fallos de ningún tipo y la ejecución termina

#### Scenario: Reintento de loopbacks fallidos
- **WHEN** el loopback de dos ítems falló en la ejecución anterior y la cola en curso tiene otros ítems pendientes
- **THEN** la siguiente ejecución procesa esos dos ítems antes que el resto de la cola, después de los ítems nunca generados

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

### Requirement: Invalidación al guardar una entrada publicada
Cuando una entrada que ya estaba en estado `publish` se guarde de nuevo (permaneciendo publicada o dejando de estarlo; con o sin cambios aparentes en el contenido), el sistema SHALL eliminar de inmediato su documento almacenado, sin generar ningún documento nuevo en esa misma petición, y SHALL marcarla como no generada para que quede al frente de la siguiente ejecución programada. Esto cubre los casos en que el origen resuelto del contenido pudo cambiar sin que el editor tocara el cuerpo del editor (una plantilla de tema añadida o modificada, un constructor de páginas reconfigurado), ya que solo una nueva resolución puede detectarlo. Una entrada que nunca estuvo publicada (primera publicación) MUST NOT disparar esta invalidación: no puede tener un documento o marca de estado previos, porque la elegibilidad exige `publish`.

#### Scenario: Edición de una entrada publicada
- **WHEN** un editor guarda cambios en una entrada publicada que ya tiene un documento almacenado
- **THEN** el documento almacenado se elimina en esa misma petición, sin generar uno nuevo, y la siguiente petición de Markdown o el siguiente ciclo programado lo regenera con el contenido actual

#### Scenario: Guardado sin cambios de contenido
- **WHEN** un editor pulsa "Actualizar" en una entrada publicada sin modificar el contenido
- **THEN** el documento almacenado también se invalida, porque el sistema no puede saber si el origen resuelto (plantilla, constructor) cambió fuera del editor

#### Scenario: Primera publicación no invalida nada
- **WHEN** una entrada se publica por primera vez (no estaba previamente en `publish`)
- **THEN** el sistema no realiza ninguna lectura ni escritura del estado de generación para esa entrada, porque no puede existir nada que invalidar

#### Scenario: Edición concurrente de una entrada ya generada en un ciclo anterior
- **WHEN** una ejecución programada está procesando otras entradas y, mientras tanto, una entrada distinta (generada en un ciclo anterior, sin tocar por esta ejecución) se guarda y se invalida
- **THEN** al terminar la ejecución, su marca de "generada" no se restaura: el guardado final de la ejecución respeta la invalidación concurrente en vez de sobrescribirla con su copia en memoria, que ya estaba desactualizada; esta verificación relee el estado saltándose la caché local de opciones, para no confundir una copia obsoleta de este mismo proceso con el estado real

#### Scenario: Edición concurrente de otra entrada durante un relleno diferido (fuera de una ejecución programada)
- **WHEN** una petición de Markdown genera bajo demanda el documento de una entrada (fuera de un ciclo) y, entre su lectura y su escritura del estado, una entrada distinta se invalida por un guardado concurrente
- **THEN** esa invalidación tampoco se pierde: la marca de la entrada distinta no se restaura

#### Scenario: Edición concurrente al iniciar un ciclo por WP-CLI restringido a un tipo
- **WHEN** `wp wpasl generate --post-type=<tipo>` reconstruye la cola y, justo antes de guardar, una entrada de otro tipo se invalida por un guardado concurrente
- **THEN** esa invalidación tampoco se pierde

#### Scenario: Edición concurrente durante una poda manual
- **WHEN** se ejecuta una poda de documentos no elegibles y, mientras tanto, una entrada que sigue siendo elegible (y por tanto no se poda) se invalida por un guardado concurrente
- **THEN** esa invalidación tampoco se pierde

#### Scenario: Cambios concurrentes a otras entradas durante `forget()`, un fallo de renderizado registrado o uno resuelto
- **WHEN** se olvida una entrada, se registra el fallo de renderizado de una entrada, o se limpia el fallo de una entrada, y mientras tanto una entrada distinta se invalida (olvidada) o su fallo de renderizado se registra o se limpia por un guardado concurrente
- **THEN** ese cambio concurrente a la entrada distinta tampoco se pierde

#### Scenario: Fallo de renderizado resuelto concurrentemente para una entrada que la ejecución en curso no toca
- **WHEN** una ejecución programada está procesando otras entradas y, mientras tanto, el renderizado de una entrada distinta (con un fallo de renderizado ya registrado, sin tocar por esta ejecución) se resuelve por un guardado concurrente
- **THEN** al terminar la ejecución, su contador de fallos no se restaura

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
El panel SHALL mostrar la fecha de la última ejecución, la fecha de la siguiente ejecución programada, el total de ítems elegibles, cuántos tienen documento generado, cuántos están pendientes, cuántos tienen fallos registrados, cuántos tienen un fallo de loopback de renderizado registrado junto con el motivo del último fallo, y SHALL advertir cuando WP-Cron esté desactivado. Cuando exista al menos un fallo de renderizado registrado, el panel SHALL mostrar un aviso que indique que esos ítems se sirven con el contenido del editor hasta que el loopback funcione y remita a la pestaña Diagnóstico.

#### Scenario: WP-Cron desactivado
- **WHEN** la constante `DISABLE_WP_CRON` es verdadera
- **THEN** el panel muestra una advertencia indicando que la generación depende de un cron del sistema o de WP-CLI

#### Scenario: Ítems con fallos
- **WHEN** dos ítems tienen fallos registrados
- **THEN** el panel y el comando de estado muestran "2" como ítems con fallos

#### Scenario: Ítems con fallo de renderizado
- **WHEN** el loopback de tres ítems falló, el último con motivo `http_403`
- **THEN** el panel muestra "3" como ítems con fallo de renderizado, el motivo `http_403` con la fecha del último fallo, y el aviso que remite a Diagnóstico; sin fallos registrados muestra "0" y ningún aviso

### Requirement: Comandos WP-CLI
El sistema SHALL exponer comandos WP-CLI para generar todos los documentos pendientes o de un post type concreto, forzar la regeneración completa, mostrar el estado y vaciar el almacenamiento, y un comando que muestre, para un ítem dado, el origen de contenido resuelto y su razón. Cuando se indique un post type, el comando MUST procesar únicamente ítems de ese post type en todas sus combinaciones: con y sin límite de lote, y aunque el post type no tenga ningún ítem elegible. Cuando se combine la regeneración completa con un post type, el comando SHALL invalidar solo los documentos de ese post type y conservar las marcas de generación del resto. El comando de generación MUST terminar con error, y no con éxito, cuando el post type indicado no esté habilitado en los ajustes o cuando la librería de conversión no esté disponible. El comando de estado SHALL incluir el número de ítems con fallo de renderizado y el motivo del último fallo.

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

#### Scenario: Estado con fallos de renderizado
- **WHEN** el loopback de un ítem falló con motivo `timeout` y se ejecuta el comando de estado
- **THEN** la salida contiene las filas `render_failed` con valor 1 y `last_render_error` con `timeout`

#### Scenario: Origen de un ítem
- **WHEN** se ejecuta el comando de origen con el id de una entrada construida con Elementor
- **THEN** la salida muestra `rendered` y la razón `builder:elementor`; con un id inexistente o no elegible el comando termina con error

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
Cuando cambie el conjunto de post types habilitados, el origen de contenido de algún post type o el selector CSS de contenido, el sistema SHALL descartar la cola en curso para que la siguiente ejecución la reconstruya, sin eliminar los documentos almacenados ni sus marcas de generación.

#### Scenario: Post type añadido
- **WHEN** un administrador habilita `product` mientras hay una cola en curso
- **THEN** la siguiente ejecución construye una cola nueva que incluye los productos elegibles

#### Scenario: Origen o selector cambiados
- **WHEN** un administrador cambia el origen de `page` de `auto` a `rendered`, o cambia el selector CSS, mientras hay una cola en curso
- **THEN** la cola en curso se descarta, las marcas de generación se conservan y la siguiente ejecución reconstruye la cola; guardar la pestaña General sin cambiar esos valores no descarta la cola

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

### Requirement: Fallos de renderizado registrados sin descartar el ítem
Cuando el loopback de renderizado de un ítem falle, el sistema SHALL registrar en el estado de generación un contador de fallos de renderizado por ítem y el último fallo (ítem, motivo y fecha), SHALL emitir una acción con el ítem y el motivo, SHALL escribir una línea en el registro de errores de PHP con el identificador del ítem y el motivo, y MUST NOT incrementar el contador de fallos de conversión ni impedir que el documento generado con el contenido del editor se almacene y se marque como generado. Cuando el loopback de un ítem con fallos de renderizado registrados tenga éxito, su contador SHALL desaparecer. Al completar un ciclo, los contadores de ítems que ya no sean elegibles SHALL eliminarse. Un ítem cuyo contador de fallos de renderizado alcance el umbral de fallos configurado MUST NOT volver a colocarse al frente de la cola; se reintenta en su orden normal.

#### Scenario: Fallo de loopback registrado
- **WHEN** el loopback de un ítem responde 503 durante una ejecución programada
- **THEN** el documento del ítem se almacena con el contenido del editor, el ítem figura como generado, el contador de fallos de conversión no cambia, el estado registra un fallo de renderizado para el ítem con motivo `http_503`, se emite la acción de fallo de renderizado y el registro de errores contiene una línea con el id y el motivo

#### Scenario: Fallo de loopback resuelto
- **WHEN** un ítem con dos fallos de renderizado registrados obtiene la página renderizada con éxito
- **THEN** su contador desaparece del estado y el documento almacenado lleva `source: rendered`

#### Scenario: Fallo de loopback bajo demanda durante una ejecución
- **WHEN** una ejecución programada está en curso y una petición bajo demanda genera un ítem cuyo loopback falla
- **THEN** al terminar la ejecución, el estado guardado contiene la marca de generación del ítem y su fallo de renderizado

#### Scenario: Fallos persistentes
- **WHEN** el loopback de un ítem falla en tres ejecuciones consecutivas
- **THEN** en la cuarta ejecución el ítem no se antepone a la cola y se procesa en su orden normal
