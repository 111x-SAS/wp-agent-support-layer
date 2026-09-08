## MODIFIED Requirements

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

### Requirement: Reinicio de la cola al cambiar los post types
Cuando cambie el conjunto de post types habilitados, el origen de contenido de algún post type o el selector CSS de contenido, el sistema SHALL descartar la cola en curso para que la siguiente ejecución la reconstruya, sin eliminar los documentos almacenados ni sus marcas de generación.

#### Scenario: Post type añadido
- **WHEN** un administrador habilita `product` mientras hay una cola en curso
- **THEN** la siguiente ejecución construye una cola nueva que incluye los productos elegibles

#### Scenario: Origen o selector cambiados
- **WHEN** un administrador cambia el origen de `page` de `auto` a `rendered`, o cambia el selector CSS, mientras hay una cola en curso
- **THEN** la cola en curso se descarta, las marcas de generación se conservan y la siguiente ejecución reconstruye la cola; guardar la pestaña General sin cambiar esos valores no descarta la cola

## ADDED Requirements

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
