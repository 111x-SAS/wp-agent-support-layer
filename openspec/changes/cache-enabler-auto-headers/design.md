## Context

Ver `proposal.md` para la motivación y `specs/agent-diagnostics/spec.md` para el comportamiento exigido. Estado verificado en este checkout (`3d81b4e`, rama `maorodriguez/cache-enabler-auto-headers`):

- `PageCache` (`src/Diagnostics/PageCache.php`) genera los tres fragmentos como cadenas; `htaccess_snippet()` construye las líneas en un array local y las une con `implode()`. `notice_paragraphs()` contiene la frase «this plugin never writes .htaccess or server configuration», que `Test_Diagnostics::test_tab_shows_cache_enabler_notice_and_snippets` afirma literalmente (`'never writes .htaccess'`).
- `DiagnosticsTab` (`src/Admin/Tabs/DiagnosticsTab.php`) declara `has_form()` falso: la pestaña no va dentro del formulario de la Settings API. `render()` imprime el aviso de caché (`render_page_cache_notice()`) y después el formulario de primer nivel «Run crawler simulation» hacia `admin-post.php`. Un segundo formulario de primer nivel dentro del aviso, antes del de la simulación, cumple la regla de «Marcado de la pestaña General» de `admin-settings` (ningún formulario dentro de otro).
- Patrón de acción manual: `GenerationStatus` (`src/Admin/GenerationStatus.php`) y `DiagnosticsController` registran `admin_post_<ACTION>`, comprueban `current_user_can( Page::CAPABILITY )` y `check_admin_referer( ACTION, NONCE )`, y terminan con `wp_safe_redirect()` a `Page::url( 'tab' )` con `wpasl_notice=<clave>`. Los mensajes de resultado se eligen por esa clave fija en el renderizado.
- Loopback existente: `CrawlerProbe::fetch( $url, $user_agent, $accept, $keep_body, $headers, $analyze )` (`src/Diagnostics/CrawlerProbe.php:300`) rechaza cualquier host distinto de `home_url()`, no sigue redirecciones, limita el tiempo a `CrawlerProbe::TIMEOUT` (5 s) y el tamaño de respuesta, y conserva solo las cabeceras listadas en `CrawlerProbe::HEADERS`. `RenderedPage::fetch()` es el otro loopback, específico del renderizado (marca `X-WPASL-Render`, devuelve cuerpo y no cabeceras). `CrawlerProbe::sample_post()` devuelve la entrada elegible más reciente o `null`.
- Almacenamiento: `Storage::write()` escribe atómicamente bajo `base_dir()` (`uploads/wp-agent-support-layer/<token>/`), directorio con token aleatorio, `index.php` y, en la raíz compartida, un `.htaccess` de denegación total que `Storage::ensure()` crea una sola vez. Ese escritor no se toca. `Runner::clear()` (solo desde WP-CLI) borra todos los archivos de `base_dir()` salvo `.php` y el archivo sonda; `Storage::delete_all()` se ejecuta en la desinstalación.
- WordPress: `insert_with_markers()` y `extract_from_markers()` viven en `wp-admin/includes/misc.php`; `get_home_path()` en `wp-admin/includes/file.php`. Ambos archivos están cargados en cualquier petición de administración (`admin-post.php` incluido) y hay que cargarlos explícitamente en tests y WP-CLI. `insert_with_markers()` crea el archivo si no existe y el directorio es escribible, toma un bloqueo exclusivo, antepone a la inserción las líneas de instrucción de WordPress («The directives (lines) between "BEGIN …" and "END …" are dynamically generated…», filtrables con `insert_with_markers_inline_instructions`), reemplaza el bloque en su sitio si los marcadores ya existen y, si no, lo añade **al final** del archivo; devuelve `true` sin escribir cuando el contenido no cambia.
- Tests: `Test_Diagnostics` simula el transporte con `pre_http_request` a prioridad 10 (`fake_http`, respuestas por `URL|md|html`, con `default_response()` que ya envía `content-signal`), captura `wp_redirect` lanzando una excepción y simula Cache Enabler con `wpasl_diagnostics_page_cache`. La misma suite corre en multisitio (`tests/multisite.xml.dist`).
- `readme.txt` y `README.md` solo mencionan `.htaccess` a propósito del directorio de almacenamiento; no afirman que el plugin nunca escriba `.htaccess`.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, WPCS limpio, sin bump de versión, sin tocar `readme.txt`, `README.md` ni `languages/`; cadenas nuevas en inglés con el text domain `wp-agent-support-layer` (los nombres en español de la spec, «Aplicar automáticamente» y «Actualizar el bloque», corresponden a las cadenas «Apply automatically» y «Update the block»).

## Goals / Non-Goals

**Goals:**

- Que la escritura en `.htaccess` sea imposible sin un clic explícito de un administrador con nonce válido, y que cada escritura quede protegida por copia de seguridad, verificación real y restauración automática dentro de la misma petición.
- Que la verificación no pueda dar un falso positivo: debe distinguir «el servidor aplica el bloque» de «PHP envía la cabecera de todos modos».
- Que el archivo nunca se reordene ni se reescriba fuera del bloque entre marcadores, y que la restauración devuelva el contenido original byte a byte.
- Que todo sea simulable en PHPUnit sin Apache, sin escribir el `.htaccess` real y sin red.

**Non-Goals:**

- Escribir configuración de nginx, OpenLiteSpeed, host virtual o CDN (no hay acceso desde PHP).
- Retirar el bloque desde la interfaz o en la desinstalación (pregunta abierta).
- Aplicar el bloque en multisitio (pregunta abierta).
- Purgar la caché de Cache Enabler tras aplicar el bloque: la respuesta cacheada recibe las cabeceras del servidor sin regenerarse.
- Comprobar `AllowOverride` o la versión exacta de LiteSpeed: la verificación real es el árbitro.

## Decisions

### D1. Una clase `WPASL\Diagnostics\HtaccessHeaders` con el patrón de `GenerationStatus`

Nueva clase `src/Diagnostics/HtaccessHeaders.php` con constructor `( PageCache $page_cache, CrawlerProbe $probe, Storage $storage, Page $page )`, `register()` que engancha `admin_post_wpasl_apply_htaccess` a `handle()`, y estos métodos públicos:

- `environment()`: `array{compatible:bool, server:string, version:string, mod_headers:bool|null, reason:string}` (D2), pasado por el filtro `wpasl_htaccess_environment`.
- `file()`: ruta absoluta del `.htaccess` (D3), pasada por el filtro `wpasl_htaccess_file`.
- `writable()`: regla de escribibilidad de D3.
- `available()`: `! is_multisite() && PageCache::detect() && environment()['compatible'] && writable()`. Es lo único que consulta la pestaña para decidir si imprime la comprobación y el botón.
- `status()`: `not_applied`, `current` o `stale` (D8).
- `apply()`: la secuencia de D4–D7; devuelve `array{ok:bool, step:string, reason:string, restored:bool|null}`. No redirige ni imprime: la usan `handle()` y los tests.
- `handle()`: capability + nonce, `apply()`, guarda el resultado en un transient por usuario y redirige (D9).
- `last_result()`: lee y borra ese transient.

`DiagnosticsTab` recibe la instancia como quinto parámetro del constructor (`?HtaccessHeaders $htaccess = null`, como `$runner`) y solo imprime. `DiagnosticsController` la recibe y la pasa; `Plugin::boot()` la crea, la registra como servicio `htaccess_headers` y llama a `register()`.

Alternativas descartadas: (a) meter la acción en `DiagnosticsController::handle()` con un segundo `ACTION`: mezcla dos flujos con transients y redirecciones distintas; (b) dos clases (servicio + controlador): más cableado sin ganancia, el patrón del proyecto (`GenerationStatus`) ya une lógica y manejador en una clase pequeña.

### D2. Detección de entorno: módulo cuando existe, `SERVER_SOFTWARE` si no, y LiteSpeed por edición

Orden de la comprobación:

1. Si existe `apache_get_modules()` (PHP como módulo de Apache): compatible si la lista contiene `mod_headers` y la versión extraída de `apache_get_version()` (`/Apache\/(\d+\.\d+\.\d+)/`) es `>= 2.4.7` o no es reconocible (`mod_headers` reportado pero versión oculta por `ServerTokens Prod`). Sin `mod_headers`: no compatible, sin excepción.
2. Si no existe (PHP-FPM, CGI, LiteSpeed): `$_SERVER['SERVER_SOFTWARE']`.
   - `/^Apache(?:\/(\d+\.\d+(?:\.\d+)?))?/i`: compatible si no hay versión o es `>= 2.4.7`. `mod_headers` queda como `null` («no comprobable»); el bloque va dentro de `<IfModule mod_headers.c>`, así que sin el módulo el resultado es «cabeceras ausentes» en la verificación, nunca un error del servidor.
   - `/LiteSpeed/i`: compatible salvo que `$_SERVER['LSWS_EDITION']` contenga `Openlitespeed` (insensible a mayúsculas). **Supuesto no verificado en este checkout**: LiteSpeed expone `LSWS_EDITION` al entorno de PHP con valores como «Openlitespeed 1.7.x» o «LiteSpeed Web Server Enterprise …». Si la variable no existe, se considera compatible (Enterprise anuncia la misma cadena `LiteSpeed`) y la verificación decide; en OpenLiteSpeed sin `LSWS_EDITION` el resultado sería una aplicación seguida de restauración automática y un error claro, nunca un cambio conservado.
   - Cualquier otro valor (nginx, IIS, Caddy, vacío): no compatible.
3. El resultado pasa por `wpasl_htaccess_environment` (docblock como los demás filtros). Es la única forma de simular Apache en PHPUnit, donde `apache_get_modules()` no existe y `SERVER_SOFTWARE` es el de la CLI.

La comprobación se repite dentro de `apply()` (no se confía en lo que vio la pestaña).

### D3. Ruta y escribibilidad como en WordPress

`file()` devuelve `trailingslashit( get_home_path() ) . '.htaccess'` tras `require_once ABSPATH . 'wp-admin/includes/file.php'` si la función no existe (tests, WP-CLI); lo mismo con `wp-admin/includes/misc.php` para `insert_with_markers()` y `extract_from_markers()`. Pasa por `wpasl_htaccess_file` para que los tests apunten a un archivo temporal (`get_temp_dir()` + nombre único) y nunca al `.htaccess` del entorno de tests.

`writable()`: `file_exists( $file ) ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) )`, la misma regla que `save_mod_rewrite_rules()` de WordPress. `wp_is_writable()` en lugar de `is_writable()` por Windows.

### D4. Bloque entre marcadores con `insert_with_markers()`; sin forzar la primera línea

`PageCache` gana `htaccess_lines()` (array con los comentarios y el `<IfModule>` actuales más la línea del marcador de D5); `htaccess_snippet()` pasa a ser `implode( "\n", htaccess_lines() ) . "\n"`, de modo que el fragmento copiable y el bloque escrito son el mismo texto. `apply()` llama a `insert_with_markers( $file, HtaccessHeaders::MARKER, $page_cache->htaccess_lines() )` con `MARKER = 'WP Agent Support Layer'`.

Colocación: en la discusión previa se planteó escribir el bloque «en la primera línea» del archivo. Se descarta deliberadamente:

- `insert_with_markers()` reemplaza el bloque donde ya esté y, si no existe, lo **añade al final**; es exactamente como WordPress gestiona su propio bloque `# BEGIN WordPress` y como lo hacen los plugins de caché y seguridad. Forzar la cabecera del archivo obligaría a reimplementar la lógica de marcadores, bloqueo y truncado de WordPress, con más superficie de error en el archivo más delicado del sitio.
- Las directivas `Header` de `mod_headers` no dependen de su posición: se evalúan en el filtro de salida, para toda respuesta del directorio, y no las detiene un `RewriteRule … [L]` anterior (`[L]` termina el procesado de reescritura, no la lectura del archivo). Un bloque al final del archivo tiene el mismo efecto que uno al principio.
- Reordenar el contenido existente contradiría la spec («sin modificar, reordenar ni eliminar ninguna otra línea») y la restauración byte a byte tendría que deshacer un movimiento en lugar de una inserción.

Las líneas de instrucción que WordPress antepone dentro del bloque se dejan (son las mismas de cualquier bloque de WordPress); el comentario de cabecera del fragmento («# WP Agent Support Layer: headers that Cache Enabler drops…») se mantiene como primera línea propia del bloque. `insert_with_markers()` devuelve `false` sin escribir si el archivo no puede abrirse: `apply()` lo trata como fallo de la fase `write` (la copia de seguridad ya existe, el archivo no cambió, no hay nada que restaurar).

### D5. Cabecera marcador `X-WPASL-Headers: htaccess` para que la verificación no sea un falso positivo

Comprobar solo `Content-Signal` en la respuesta de verificación no demuestra nada: la petición de verificación pasa por PHP (lleva parámetro, Cache Enabler la excluye de la caché) y PHP envía `Content-Signal` siempre, con o sin `.htaccess` aplicado. Las alternativas eran (a) pedir dos veces la URL para forzar una respuesta cacheada sin cabeceras de PHP: no es determinista (exclusiones de Cache Enabler, modo servidor web sin `X-Cache-Handler`, CDN); (b) que PHP omita sus cabeceras cuando la petición lleve una marca: convierte en desactivables por cabecera unas señales que la spec de `content-signals` exige en toda respuesta pública. Se elige añadir al bloque una línea `Header always set X-WPASL-Headers "htaccess" "expr=%{CONTENT_TYPE} =~ m#^text/html#"` (misma condición que el resto, dentro del mismo `<IfModule>`): PHP nunca la envía, así que su presencia en cualquier respuesta HTML demuestra que el servidor procesa el bloque. `Content-Signal` se comprueba además para asegurar que la respuesta es una página del sitio y no un error con la cabecera.

Coste: una cabecera corta más en cada respuesta HTML de los sitios que apliquen o peguen el bloque; el nombre lleva el prefijo del plugin y podrá reutilizarse en el informe de la simulación en un cambio futuro. Los bloques de nginx y OpenLiteSpeed no la incluyen: no hay verificación para ellos y no se amplía su alcance.

### D6. Verificación reutilizando `CrawlerProbe::fetch()`

`apply()` verifica con `$probe->fetch( $url, $user_agent, 'text/html' )`, que ya garantiza mismo host, sin redirecciones, tiempo límite (5 s) y tamaño acotado. Para que devuelva la cabecera marcador, `x-wpasl-headers` se añade a `CrawlerProbe::HEADERS` (el informe la conservará en los resultados crudos; inocuo). URL: `get_permalink( $probe->sample_post() )` o `home_url( '/' )` si no hay muestra, con `add_query_arg( 'wpasl_verify', wp_generate_password( 8, false ), … )`: el parámetro aleatorio evita una respuesta cacheada por un CDN sin el bloque (falso negativo) y Cache Enabler lo excluye de su caché, lo que no importa porque `mod_headers` actúa sobre toda respuesta HTML del directorio. `wpasl_verify` no se registra como query var (misma razón que `RenderedPage::QUERY_ARG`: una query var registrada rompería la resolución de la portada estática). User-agent: el que ya usa `CrawlerProbe` para las comprobaciones de sitio.

Éxito: `status === 200`, `headers['x-wpasl-headers']` contiene `htaccess` y `headers['content-signal']` no vacío. Cualquier otra cosa (incluido `error` no vacío por conexión o tiempo agotado, y 301/302 por redirección a `www` o `https`) es fallo con motivo textual: `http_500`, `redirect_301`, `request_error:<mensaje>`, `header_missing:X-WPASL-Headers`.

Trade-off aceptado: una página que tarde más de 5 s en renderizar provoca restauración y error aunque el bloque funcione; el aviso lo dice y el botón permite reintentar. Se prefiere reutilizar el loopback existente a duplicarlo con otro tiempo límite.

### D7. Copia de seguridad en el almacenamiento del plugin y restauración byte a byte

Antes de escribir, `apply()` lee el archivo completo (`file_get_contents()`) y lo guarda con `$storage->write( 'system/htaccess.backup', $contents )` (escritura atómica de `Storage`, bajo el directorio con token, protegido por `index.php` y por el `.htaccess` de denegación de la raíz de almacenamiento). Si el archivo no existía, se guarda una copia vacía y el hecho queda en los metadatos. Los metadatos van en la opción `wpasl_htaccess_backup` (sin autoload): `array{time:int, existed:bool, bytes:int, file:string}`. Solo se conserva la copia más reciente: cada aplicación sobrescribe archivo y opción. Si `write()` devuelve `false`, `apply()` aborta con `step => 'backup'` sin tocar `.htaccess`.

Se descarta guardar la copia junto al original (`.htaccess.wpasl-backup`): el directorio raíz puede no admitir archivos nuevos aunque `.htaccess` sea escribible, y el almacenamiento del plugin es escribible por construcción. Se descarta la raíz compartida del almacenamiento (`Storage::root_dir()`): su ruta es adivinable y, con `AllowOverride None`, el `.htaccess` de denegación no se aplicaría. Caveat documentado: `wp wpasl clear` (`Runner::clear()`) borra los documentos generados y con ellos la copia; la copia existe para la restauración automática de la misma petición y como cortesía para una restauración manual, no como historial.

Restauración (`restore()`): si `existed`, `file_put_contents( $file, $backup, LOCK_EX )` sobre la misma ruta (no `rename()`: conservaría inodo, permisos y propietario del archivo original); si no existía, `unlink( $file )`. Devuelve `bool`; `apply()` lo comunica como `restored` y, si es `false`, el aviso indica la ruta de la copia (`Storage::path( 'system/htaccess.backup' )`) para restaurarla a mano.

### D8. Estado del bloque

`status()`: `extract_from_markers( $file, MARKER )`; vacío → `not_applied`. Si hay líneas, se comparan con `htaccess_lines()` ignorando en ambos lados las líneas que empiezan por `#` (las instrucciones que WordPress antepone dependen del idioma del sitio y los comentarios del fragmento no afectan al servidor) y los espacios de los extremos: iguales → `current`, distintas → `stale`. La pestaña imprime «Not applied», «Applied and up to date» o «Applied with different values than the current settings» y el botón «Apply automatically» o «Update the block». El estado nunca dispara nada: un bloque `stale` solo cambia el texto.

### D9. Resultado tras la redirección

`handle()` guarda el resultado de `apply()` en el transient `wpasl_htaccess_result_<user_id>` (5 minutos) y redirige a `Page::url( 'diagnostics' )` con `wpasl_notice=htaccess`. `render_page_cache_notice()` lee `last_result()` cuando la clave es `htaccess` y pinta `notice-success` («The .htaccess block was applied and verified: the sample page answered with the X-WPASL-Headers marker.») o `notice-error` con la fase (`environment`, `backup`, `write`, `verify`, `restore`), el motivo y la frase «No change was kept: the previous .htaccess was restored.» (o «could not be restored; the backup is at …»). Un transient en lugar de parámetros de URL porque el motivo es texto libre (mensaje de `WP_Error`) y no debe viajar en la query string.

### D10. Multisitio: no se ofrece

`available()` devuelve `false` en `is_multisite()`. Motivo: el `.htaccess` de la raíz es de toda la red mientras que `Content-Signal`, `Content-Usage`, `ai-train` y `manifest_enabled` son ajustes por sitio; un bloque escrito desde un sitio impondría sus valores a los demás. La suite multisitio verifica que la pestaña no muestra el botón y que `apply()` aborta con `step => 'environment'`. Queda como pregunta abierta si conviene ofrecerlo al superadministrador del sitio principal.

### D11. Tests

En `tests/test-diagnostics.php`, con `wpasl_diagnostics_page_cache` → `fake_cache_enabler()`, `wpasl_htaccess_environment` → `array( 'compatible' => true, 'server' => 'Apache/2.4.58', … )`, `wpasl_htaccess_file` → archivo temporal creado en `set_up()` y borrado en `tear_down()`, y un filtro `pre_http_request` a prioridad 20 que reconoce `wpasl_verify` en la URL y responde según cada test (200 con `x-wpasl-headers: htaccess` y `content-signal`; 200 sin marcador; 500; `WP_Error`; 301). Como `Test_Rendered_Page`, los tests que usan `apply()` directamente comprueban `$this->requests` (una sola petición de verificación, `Accept: text/html`, host propio, `redirection` 0) y el contenido del archivo con `file_get_contents()` comparado con la cadena original. Para «Ninguna escritura fuera de la acción» se guarda el `mtime` y el contenido del archivo temporal, se ejecutan `Plugin::activate()`/`sanitize()`/`Runner::run()`/`DiagnosticsController::run()`/`render_diagnostics_tab()` y se comprueba que no cambiaron y que `system/htaccess.backup` no existe. En multisitio (`is_multisite()`), los tests de la acción afirman lo contrario (sin botón, `apply()` aborta) en lugar de saltarse.

### D12. Cobertura documentada

`docs/spec-coverage.md` gana en `agent-diagnostics` una fila por escenario nuevo o modificado del delta, apuntando a los tests de D11, y el párrafo introductorio cita `cache-enabler-auto-headers`.

## Risks / Trade-offs

- [Entre la escritura y la restauración (hasta ~5 s) el sitio puede responder 500 si Apache rechaza el bloque (`AllowOverride` sin `FileInfo`, Apache 2.2 sin `expr=`/`setifempty`)] → Es la ventana mínima posible con verificación real; la comprobación de versión reduce los casos, `<IfModule>` evita el 500 por módulo ausente, y la restauración es automática e inmediata. El aviso de éxito solo aparece tras la verificación.
- [LiteSpeed Enterprise puede no recargar `.htaccess` en la siguiente petición (ajuste de autocarga)] → Falso negativo con restauración automática y error claro; nunca un cambio conservado sin verificar. Documentado en el mensaje de error como causa posible.
- [`LSWS_EDITION` ausente en OpenLiteSpeed] → Aplicación seguida de restauración y error; el administrador sigue teniendo el fragmento de OpenLiteSpeed. No hay ruta que deje un bloque sin verificar.
- [Falso negativo por página lenta (> 5 s) o por un WAF que bloquee el user-agent del plugin] → Restauración y error con motivo; reintento con un clic. Mismo límite que la simulación.
- [Un CDN sirve la verificación desde su caché sin el bloque] → El parámetro aleatorio `wpasl_verify` evita la caché de URL; una regla de CDN que ignore la query string seguiría dando falso negativo, con restauración.
- [La restauración falla (archivo bloqueado o permisos cambiados durante la petición)] → El aviso indica que no se pudo restaurar y dónde está la copia; el bloque, si quedó escrito, está dentro de `<IfModule>` y es sintácticamente el mismo que WordPress acepta, así que el daño posible es «cabeceras aplicadas sin verificar», no un sitio caído.
- [`wp wpasl clear` borra la copia de seguridad] → Documentado en D7; la copia no es un historial. Alternativa futura: excluir `system/` de `Runner::clear()`.
- [Un bloque aplicado queda desactualizado al cambiar las señales] → El estado `stale` lo muestra y el botón lo actualiza; nunca automáticamente, por diseño.
- [La cabecera marcador se expone públicamente] → Solo revela que el sitio usa este plugin, información ya pública por `llms.txt`, `robots.txt` y los manifiestos.
- [El test existente afirma «never writes .htaccess»] → Se sustituye por una aserción del párrafo nuevo (D11).

## Migration Plan

Sin migración de datos. Tras desplegar, nada cambia hasta que un administrador pulsa el botón. Rollback del cambio: revertir el commit; un bloque ya aplicado permanece en `.htaccess` (es inerte sin Cache Enabler y coherente con las cabeceras que envía PHP) y se retira a mano borrando las líneas entre los marcadores o restaurando `system/htaccess.backup`. Verificación tras publicar, en un sitio Apache de pruebas con Cache Enabler: aplicar, comprobar con `curl -sI` la portada (`x-wpasl-headers: htaccess` y `content-signal` una sola vez), una URL `.md` (sin `x-robots-tag` ni `x-wpasl-headers`) y el estado «aplicado y al día»; después cambiar `ai-train` y comprobar el estado «con valores distintos» sin que el archivo cambie.

## Open Questions

- **Multisitio**: ¿ofrecer la aplicación al superadministrador desde el sitio principal, escribiendo los valores de ese sitio? Deferrable: hoy `available()` devuelve `false` en multisitio y la spec lo recoge; habilitarlo sería un delta futuro sobre este requisito.
- **Retirada del bloque**: ¿añadir un botón «Remove the block» (vaciar el bloque con `insert_with_markers()` o restaurar la copia) y limpiar el bloque en la desinstalación? Deferrable: no cambia la aplicación ni la verificación; sería un requisito añadido.
- **Motivos negativos en la interfaz**: el encargo pide no mostrar nada cuando el entorno no es compatible o el archivo no es escribible. Mostrar el motivo («.htaccess is not writable») ayudaría al administrador; deferrable, cambiaría solo texto de la pestaña.
