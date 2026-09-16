## MODIFIED Requirements

### Requirement: Estado de generación resistente a escrituras concurrentes
El registro de qué ítems tienen documento generado y la cola pendiente SHALL sobrevivir a escrituras concurrentes: una marca de generación registrada por una petición bajo demanda mientras una ejecución programada está en curso MUST conservarse cuando la ejecución guarde su estado, y viceversa. Invalidar los documentos de uno o varios post types concretos (regeneración completa restringida a un tipo) SHALL olvidar únicamente las marcas de generación y los fallos de los ítems elegibles de esos tipos: una marca de generación registrada concurrentemente para un ítem de otro post type, entre la lectura del estado y su guardado, MUST conservarse. La regeneración completa sin restricción de tipo SHALL seguir vaciando incondicionalmente las marcas, los fallos y la cola. El estado MUST NOT reescribirse cuando la operación no lo altere: despublicar o eliminar un ítem de un post type no habilitado MUST NOT escribir el estado, y la generación de documentos que ocurra dentro de una ejecución programada SHALL registrarse en el estado de esa ejecución en lugar de escribir la opción por cada ítem.

#### Scenario: Generación bajo demanda durante una ejecución
- **WHEN** una ejecución programada carga el estado, una petición bajo demanda genera y marca otro ítem, y después la ejecución guarda su estado
- **THEN** el estado guardado contiene la marca del ítem generado bajo demanda y las marcas de los ítems de la ejecución

#### Scenario: Marca concurrente de otro post type durante una regeneración completa restringida a un tipo
- **WHEN** `wp wpasl generate --all --post-type=page` carga el estado para invalidar los documentos de `page` y, antes de guardarlo, una petición concurrente genera y marca una entrada de tipo `post`
- **THEN** el estado guardado ya no contiene la marca de la página invalidada, sí contiene la marca de la entrada de tipo `post` registrada concurrentemente y la cola queda vacía; esta verificación relee el estado saltándose la caché local de opciones, para no confundir una copia obsoleta de este mismo proceso con el estado real

#### Scenario: Regeneración completa sin restricción de tipo
- **WHEN** un administrador pulsa "Regenerar todo" o se ejecuta `wp wpasl generate --all` sin `--post-type`
- **THEN** el estado guardado no contiene ninguna marca de generación, ningún fallo ni ítems en cola, sea cual sea lo que otra petición haya registrado mientras tanto

#### Scenario: Despublicación de un post type no habilitado
- **WHEN** se envía a la papelera un ítem de un post type no habilitado
- **THEN** el valor almacenado del estado de generación no cambia

#### Scenario: Construcción de llms-full.txt dentro de una ejecución
- **WHEN** una ejecución programada construye `llms-full.txt` y genera 100 documentos que faltaban
- **THEN** el estado se escribe una sola vez al final de la ejecución y contiene las 100 marcas
