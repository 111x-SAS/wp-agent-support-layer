## Why

Dos errores de corrección pequeños, ya diagnosticados por completo, deben cerrarse antes de cortar la 1.0.4-rc2. (1) La regeneración completa restringida a un post type (`wp wpasl generate --all --post-type=<tipo>`) todavía guarda el estado de generación reemplazándolo entero con una copia cargada al inicio, así que una marca de generación o un fallo que otra petición concurrente (cron o relleno bajo demanda) registre para una entrada de otro post type mientras tanto se descarta en silencio; es la misma carrera que el commit 9564334 ya cerró en la ejecución programada y en la poda. (2) El análisis de archivos de plantilla toma por `include`/`require` dinámico cualquier identificador que meramente empiece por esas palabras (`$include_path = ...;`, `$this->require_once_algo( $x );`), marca la plantilla como no concluyente y desactiva sin aviso el origen `rendered` para plantillas que no contienen ningún include dinámico real.

## What Changes

- La regeneración completa restringida a uno o varios post types SHALL olvidar solo las marcas de generación de las entradas elegibles de esos tipos y conservar, mediante la fusión con el estado recién releído, cualquier marca de generación que otra petición haya registrado concurrentemente para entradas de otros tipos. La regeneración completa sin restricción (acción "Regenerar todo" del panel, `--all` sin `--post-type`) no cambia: sigue vaciando incondicionalmente las marcas, los fallos y la cola.
- El análisis de un archivo de plantilla SHALL reconocer `include`, `require`, `include_once` y `require_once` únicamente como construcciones del lenguaje: un identificador más largo que empiece por esas palabras (una variable como `$include_path`, un método como `require_once_algo()`) MUST NOT contar como include dinámico. Los includes dinámicos reales (`include $var;`, `require_once $file;`, `include get_template_directory() . '/x.php';`), el include literal sin espacio (`include'./x.php';`) y la exclusión ya existente de la clave de array `'include' => array(...)` conservan exactamente su comportamiento actual.
- Sin cambios de comportamiento visibles fuera de esos dos puntos: sin nuevas dependencias, sin migración, sin cambios en ajustes, interfaz ni formato de los documentos.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `scheduled-generation`: el requisito "Estado de generación resistente a escrituras concurrentes" gana el escenario de una marca registrada concurrentemente para una entrada de otro post type mientras se invalidan los documentos de un tipo concreto, que MUST conservarse.
- `markdown-delivery`: el requisito "Origen del contenido del documento" precisa que solo las construcciones `include`/`require` del lenguaje cuentan como include dinámico de un archivo de plantilla, con un escenario para identificadores que meramente empiezan por esas palabras.

## Impact

- Código modificado: `src/Generation/Runner.php` (método `reset_cycle()`, solo la rama restringida a post types: pasa a usar el mismo patrón de estado "estrechado" más lista de ids eliminados que ya usan `prune()` y el final de `run()`, guardando con fusión; la rama sin post types conserva el guardado con reemplazo) y `src/Markdown/ContentSource.php` (método `analyze_template()`, expresión regular de detección de `include`/`require` y su comentario explicativo).
- Sin cambios: `src/Generation/State.php` (su `narrowed()` y la fusión de `save()` ya ofrecen todo lo necesario), `src/CLI/Commands.php`, `src/Admin/GenerationStatus.php`.
- Tests: `tests/test-runner.php` gana un test de regresión para la regeneración completa restringida a un tipo con una marca concurrente de otro tipo (mismo patrón de caché local "envenenada" que `test_prune_self_persist_does_not_resurrect_a_concurrently_forgotten_item`); `tests/test-content-source.php` gana un test de regresión con identificadores que empiezan por `include` y por `require` en una plantilla de contenido fijo. Los tests existentes `test_reset_cycle_replaces_instead_of_merging` (`tests/test-state.php`), `test_reset_cycle_without_types_forgets_everything` (`tests/test-runner.php`) y `test_include_as_array_key_does_not_look_like_a_dynamic_include` (`tests/test-content-source.php`) MUST seguir pasando sin modificarse.
- Sin cambio de versión ni de `readme.txt`, `README.md` o `languages/` dentro de este cambio; el bump a 1.0.4-rc2 se hace aparte.
