## MODIFIED Requirements

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

### Requirement: Sin generación en el flujo de edición
El sistema MUST NOT convertir ni generar documentos como reacción a la creación, actualización o publicación de una entrada. La entrada publicada SHALL quedar pendiente y procesarse en la siguiente ejecución programada aunque haya un ciclo en curso.

#### Scenario: Publicar una entrada nueva
- **WHEN** un editor publica una entrada nueva
- **THEN** no se genera ningún documento en esa petición y el ítem queda pendiente para la siguiente ejecución programada

### Requirement: Regeneración manual
El panel de ajustes SHALL ofrecer una acción "Regenerar ahora" que programe una ejecución inmediata en segundo plano y devuelva el control al administrador sin esperar a que termine. La acción MUST requerir la capacidad `manage_options` y un nonce válido. La ejecución inmediata SHALL programarse como evento único y MUST NOT modificar el horario del evento recurrente.

#### Scenario: Administrador pulsa Regenerar ahora
- **WHEN** un administrador con `manage_options` envía la acción con nonce válido
- **THEN** se programa una ejecución inmediata y la página muestra confirmación sin ejecutar la generación en la misma petición

#### Scenario: Petición sin nonce válido
- **WHEN** se envía la acción sin nonce válido
- **THEN** el sistema rechaza la acción y no programa nada

#### Scenario: Horario recurrente intacto
- **WHEN** el evento recurrente está programado para dentro de 6 horas y un administrador pulsa Regenerar ahora
- **THEN** existe un evento único inmediato y el evento recurrente sigue programado para dentro de 6 horas

### Requirement: Comandos WP-CLI
El sistema SHALL exponer comandos WP-CLI para generar todos los documentos pendientes o de un post type concreto, forzar la regeneración completa, mostrar el estado y vaciar el almacenamiento. El comando de generación MUST terminar con error, y no con éxito, cuando el post type indicado no esté habilitado en los ajustes o cuando la librería de conversión no esté disponible.

#### Scenario: Generación completa por WP-CLI
- **WHEN** se ejecuta el comando de generación con la opción de regeneración completa
- **THEN** todos los ítems elegibles obtienen documento actualizado y el comando informa del total procesado

#### Scenario: Vaciar almacenamiento
- **WHEN** se ejecuta el comando de vaciado
- **THEN** no queda ningún documento ni archivo de descubrimiento en el almacenamiento y el estado muestra todos los ítems pendientes

#### Scenario: Post type no habilitado
- **WHEN** se ejecuta el comando de generación con un post type que no está habilitado en los ajustes
- **THEN** el comando termina con un error que indica el post type y no reporta éxito

## ADDED Requirements

### Requirement: Reinicio de la cola al cambiar los post types
Cuando cambie el conjunto de post types habilitados, el sistema SHALL descartar la cola en curso para que la siguiente ejecución la reconstruya con el nuevo conjunto.

#### Scenario: Post type añadido
- **WHEN** un administrador habilita `product` mientras hay una cola en curso
- **THEN** la siguiente ejecución construye una cola nueva que incluye los productos elegibles

### Requirement: Estado de generación resistente a escrituras concurrentes
El registro de qué ítems tienen documento generado y la cola pendiente SHALL sobrevivir a escrituras concurrentes: una marca de generación registrada por una petición bajo demanda mientras una ejecución programada está en curso MUST conservarse cuando la ejecución guarde su estado, y viceversa.

#### Scenario: Generación bajo demanda durante una ejecución
- **WHEN** una ejecución programada carga el estado, una petición bajo demanda genera y marca otro ítem, y después la ejecución guarda su estado
- **THEN** el estado guardado contiene la marca del ítem generado bajo demanda y las marcas de los ítems de la ejecución

### Requirement: Listado de ítems elegibles acotado en consultas
Obtener la lista de ítems elegibles SHALL requerir un número de consultas a la base de datos acotado por una constante pequeña, independiente del número de ítems, salvo que un filtro externo obligue a evaluar cada ítem individualmente. El total de elegibles mostrado en el estado de generación SHALL obtenerse con una consulta de conteo.

#### Scenario: Estado con muchos ítems
- **WHEN** hay 1000 ítems elegibles sin filtros externos de elegibilidad y se abre la pestaña General
- **THEN** el cálculo de elegibles ejecuta menos de 10 consultas a la base de datos
