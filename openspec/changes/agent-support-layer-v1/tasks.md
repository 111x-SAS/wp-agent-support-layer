## 1. Scaffolding, toolchain y CI (Fase 2, requiere aprobación al cierre)

- [x] 1.1 Instalar Composer y WP-CLI con Homebrew y verificar `composer --version` y `wp --version`
- [x] 1.2 Crear el esqueleto del plugin (`wp-agent-support-layer.php` con cabeceras, `readme.txt`, `LICENSE` GPL-2.0-or-later, `uninstall.php`, `.editorconfig`, `.gitignore`, `.distignore`, `languages/`) y verificar que WordPress lo lista como plugin activable
- [x] 1.3 Crear `composer.json` con autoload PSR-4 `WPASL\`, dependencia `league/html-to-markdown` y dev-deps (PHPUnit 9.6, yoast/phpunit-polyfills ^4, WPCS ^3, PHPCompatibilityWP, Strauss, Plugin Check) y verificar `composer install` en PHP 8.5 local y `composer validate`
- [x] 1.4 Configurar Strauss para prefijar `league/html-to-markdown` en `vendor-prefixed/` bajo `WPASL\Vendor` y verificar que `composer run build` genera las clases prefijadas
- [x] 1.5 Ejecutar `wp scaffold plugin-tests --ci=github` y adaptar `phpunit.xml.dist`, `tests/bootstrap.php` y `bin/install-wp-tests.sh`; verificar que la suite de humo pasa localmente contra MySQL en Docker
- [x] 1.6 Configurar `.phpcs.xml.dist` con WordPress, WordPress-Extra, WordPress-Docs, PHPCompatibilityWP (`testVersion 7.4-`), text domain y prefijos permitidos; verificar `vendor/bin/phpcs` sin errores sobre el esqueleto
- [x] 1.7 Crear `.github/workflows/ci.yml` con jobs `test` (matriz PHP 7.4/8.0/8.2/8.3, servicio MySQL 8.0, `bin/install-wp-tests.sh`, PHPUnit), `lint` (PHPCS) y `plugin-check`; verificar en GitHub que los tres jobs pasan en el primer push
- [x] 1.8 Crear `.github/workflows/release.yml` que construya el zip distribuible al publicar un tag y verificar el artefacto con un tag de prueba
- [x] 1.9 Crear el repositorio en GitHub, hacer el push inicial (OpenSpec + scaffolding) y verificar que la CI está en verde

## 2. Núcleo del plugin y ajustes (admin-settings)

- [x] 2.1 Implementar el arranque: comprobación de requisitos (WP 7.0, PHP 7.4), constantes, autoloader propio y contenedor de servicios; verificar con test que en PHP inferior al mínimo no se registran hooks y se muestra aviso
- [x] 2.2 Implementar activación (almacenamiento protegido, opciones por defecto, cron, flush), desactivación (unschedule) y `uninstall.php`; verificar con tests que tras desinstalar no quedan opciones `wpasl_`, meta ni directorio
- [x] 2.3 Implementar la página bajo Herramientas con Settings API, pestañas y sanitización; verificar con tests que un Editor recibe denegación y que los valores por defecto son `post` y `page`, `daily`, lote 50
- [x] 2.4 Implementar la casilla "Excluir de la capa de agentes" como meta box y post meta protegido con `auth_callback`; verificar con tests que solo aparece en post types habilitados y que la meta se guarda con nonce
- [x] 2.5 Añadir text domain, `.pot` y traducción `es_ES`; verificar con `wp i18n make-pot` que no hay cadenas sin text domain

## 3. Motor de generación programada (scheduled-generation)

- [x] 3.1 Implementar el almacenamiento (`Storage`): directorio en uploads con `index.php` y `.htaccess`, rutas por post type e ID, `state.json`; verificar con tests de escritura, lectura, borrado y aislamiento por sitio en multisitio
- [x] 3.2 Implementar el evento recurrente `wpasl_generate`, la reprogramación al cambiar el intervalo y el unschedule; verificar con tests sobre `wp_next_scheduled`
- [x] 3.3 Implementar el `Runner` por lotes con cursor, límite de ítems y presupuesto de 20 s, y la regeneración de archivos de descubrimiento al cerrar ciclo; verificar con tests el caso de 120 ítems y lote 50
- [x] 3.4 Implementar la limpieza de no elegibles por ciclo y el borrado inmediato en `transition_post_status` y `deleted_post`; verificar con tests que enviar a papelera borra el archivo y no genera nada
- [x] 3.5 Implementar "Regenerar ahora" (evento único inmediato + `spawn_cron`) y el bloque de estado con advertencia de `DISABLE_WP_CRON`; verificar con tests que la petición admin no ejecuta la generación inline
- [x] 3.6 Implementar los comandos WP-CLI `generate`, `status` y `clear`; verificar ejecutándolos contra el entorno local de Docker

## 4. Entrega en Markdown (markdown-delivery)

- [x] 4.1 Implementar `Eligibility` (post type habilitado y público, `publish`, sin contraseña, no excluido); verificar con tests cada condición por separado
- [x] 4.2 Implementar `MarkdownConverterInterface` y el adaptador sobre la librería prefijada con limpieza de `script`, `style`, `form`, `iframe`, `noscript` y comentarios, y absolutización de URLs; verificar con tests de instantánea sobre HTML de muestra
- [x] 4.3 Implementar `DocumentBuilder` (front matter YAML, H1, cuerpo) a partir de `the_content` renderizado; verificar con tests que el front matter contiene todas las claves requeridas y que las taxonomías aparecen cuando existen
- [x] 4.4 Implementar la negociación por `Accept` en `template_redirect` con parser de `q`; verificar con tests los tres escenarios de la spec (prefiere Markdown, prefiere HTML, ambos con HTML preferido)
- [x] 4.5 Implementar la resolución del sufijo `.md` y del parámetro `wpasl=md` en `parse_request` usando `url_to_postid`; verificar con tests el 200 para elegibles y el 404 para inexistentes
- [x] 4.6 Implementar las cabeceras de respuesta Markdown (`Content-Type`, `Vary`, `X-Markdown-Tokens`, `Link canonical`, `Cache-Control`) y `Vary: Accept` en HTML elegible; verificar con tests que 4000 bytes producen 1000 tokens
- [x] 4.7 Implementar `link rel="alternate"` en `wp_head` y la cabecera `Link` en `send_headers`; verificar con tests su presencia en elegibles y ausencia en no elegibles
- [x] 4.8 Implementar el relleno perezoso (generar y almacenar si falta) y verificar con tests que guardar una entrada no altera el documento almacenado

## 5. Señales de contenido (content-signals)

- [x] 5.1 Implementar los ajustes `search`, `ai-input`, `ai-train` y el interruptor de `Content-Usage` con sus defaults; verificar con tests los valores por defecto
- [x] 5.2 Implementar la inserción de `Content-Signal:` dentro del grupo `User-agent: *` del robots.txt virtual con comentario de referencia; verificar con tests sobre la salida del filtro `robots_txt`
- [x] 5.3 Implementar las cabeceras `Content-Signal` y `Content-Usage` en respuestas de front-end y su ausencia en admin; verificar con tests de `send_headers` y de la respuesta Markdown
- [x] 5.4 Implementar `noai, noimageai` en `X-Robots-Tag` y en `wp_robots` cuando `ai-train=no`, preservando directivas existentes; verificar con tests los escenarios de convivencia

## 6. Reglas para crawlers de IA (ai-crawler-robots)

- [x] 6.1 Implementar el `Catalog` con los user-agents iniciales, grupos, proveedor y documentación, y el filtro `wpasl_crawler_catalog`; verificar con test que un user-agent añadido por filtro aparece en el catálogo
- [x] 6.2 Implementar la política por crawler con defaults derivados de las señales y sobrescrituras; verificar con tests que con defaults GPTBot queda `block` y OAI-SearchBot `allow`, y que una sobrescritura individual se respeta
- [x] 6.3 Implementar la generación de grupos `User-agent`/`Allow|Disallow` y el comentario de `llms.txt` al final del robots.txt virtual sin alterar el núcleo; verificar con tests que las líneas del núcleo permanecen intactas
- [x] 6.4 Implementar la pestaña Crawlers (lista agrupada con radio allow/block) y la detección de `robots.txt` físico con área de texto para copiar; verificar con test que la detección se activa cuando el archivo existe

## 7. llms.txt (llms-txt)

- [x] 7.1 Implementar el `LlmsTxtBuilder` (H1, blockquote, bloque libre, secciones por post type con URLs `.md`, límite y orden, sección Optional); verificar con tests la estructura y el límite de 100
- [x] 7.2 Implementar el enrutado de `/llms.txt` y `/llms-full.txt` con precedencia del archivo físico y aviso en ajustes; verificar con tests el 200 con `text/markdown` y el 404 cuando `llms-full` está deshabilitado
- [x] 7.3 Implementar `llms-full.txt` con concatenación, separadores, límite de 5 MB y nota de truncado; verificar con test que supera el límite y trunca en un ítem completo
- [x] 7.4 Implementar la pestaña llms.txt (descripción, bloque libre, límite, interruptor y tamaño máximo de `llms-full`); verificar con test de sanitización

## 8. Manifiestos de agente (agent-manifest)

- [x] 8.1 Implementar `CapabilityRegistry` con las capacidades derivadas (búsqueda, listado y lectura por post type con REST, Markdown, llms.txt, OpenAPI) y el filtro `wpasl_agent_capabilities`; verificar con tests que un post type sin REST no genera capacidades REST
- [x] 8.2 Implementar el `agent-skills.json` JSON-LD y su enrutado con `application/ld+json`, 404 cuando está deshabilitado; verificar con tests que el JSON es válido y contiene `@context` y contacto
- [x] 8.3 Implementar el generador OpenAPI 3.1 desde `rest_get_server()->get_routes()` limitado a GET públicos y su ruta REST `wpasl/v1/openapi`; verificar con tests que `openapi` es `3.1.0` y que solo hay operaciones `get`
- [x] 8.4 Implementar `/.well-known/api-catalog` como linkset RFC 9727; verificar con test el `Content-Type` y la relación `service-desc`
- [x] 8.5 Implementar la invalidación de los tres documentos al guardar ajustes relevantes; verificar con test que cambiar el correo de contacto se refleja en la siguiente petición

## 9. Diagnóstico (agent-diagnostics)

- [x] 9.1 Implementar el `CrawlerProbe` con peticiones loopback por user-agent y `Accept`, timeout 10 s y bloqueo de hosts externos; verificar con tests usando `pre_http_request` para simular respuestas
- [x] 9.2 Implementar el evaluador de robots.txt por user-agent y el informe con estados correcto/advertencia/error guardado en transient de 1 h; verificar con tests los escenarios "bloqueado coherente" y "Markdown no servido"
- [x] 9.3 Implementar la detección de CDN (`cf-ray`, `server`, `x-cache`), la advertencia de Cloudflare Markdown for Agents y la prueba de acceso directo al almacenamiento; verificar con tests simulando cabeceras
- [x] 9.4 Implementar la pestaña Diagnóstico con la lista de verificación de WAF y los comandos `curl` por crawler; verificar manualmente en el entorno Docker que la prueba se ejecuta y el informe se muestra

## 10. Cumplimiento WordPress.org y documentación

- [x] 10.1 Redactar `readme.txt` completo (descripción, instalación, FAQ, privacidad, changelog, `Stable tag`) y verificar con el validador de readme de WordPress.org
- [x] 10.2 Redactar `README.md` de GitHub (inglés con sección en español), guía de cachés/CDN y nota para nginx; verificar que los enlaces internos resuelven
- [x] 10.3 Ejecutar Plugin Check localmente y en CI y corregir todos los hallazgos; verificar que el job `plugin-check` pasa sin errores
- [x] 10.4 Verificar la instalación desde el zip de release en un WordPress 7.1 limpio con PHP 7.4 y con PHP 8.3 en Docker, recorriendo activación, generación, `.md`, `llms.txt`, manifiestos, diagnóstico y desinstalación

## 11. Verificación integral y cierre

- [x] 11.1 Ejecutar la suite completa en la matriz de CI y confirmar cobertura de todos los escenarios de las specs con un mapa escenario→test
- [x] 11.2 Ejecutar las pruebas de rastreo con `curl` y user-agents reales contra el entorno Docker y documentar los resultados como evidencia de la acción 8
- [ ] 11.3 Etiquetar `v1.0.0`, generar el release en GitHub y preparar el envío a WordPress.org (a la espera de aprobación del slug)
