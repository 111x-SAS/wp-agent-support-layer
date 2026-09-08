## 1. Línea base

- [ ] 1.1 Comprobar el entorno de tests (`vendor/`, `wordpress-tests-lib`, contenedor `wpasl-mysql`; si falta, `composer install`, `composer run build` y `bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest`) y verificar que `vendor/bin/phpunit` y `vendor/bin/phpcs --no-cache` pasan en verde antes de tocar código, anotando el número de tests

## 2. Test de integración que reproduce el bug (D2)

- [ ] 2.1 Añadir `test_markdown_response_keeps_api_catalog_link` en `tests/test-delivery.php`: con el manifiesto habilitado por defecto, `Http::reset()`, `$_SERVER['HTTP_ACCEPT'] = 'text/markdown'`, `go_to( get_permalink( $post ) )`; afirmar que la pasada HTML ya contiene el `Link` del catálogo; `maybe_serve()` dentro de `ob_start()`; afirmar `assertSame( array( '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"', '<' . get_permalink( $post ) . '>; rel="canonical"' ), Http::effective_headers()['link'] )`; repetir la aserción exacta tras `Http::reset()` + `go_to( home_url( '/<slug>.md' ) )` (ruta `parse_request`) y tras `?wpasl=md` sobre una portada estática (`show_on_front=page`, `page_on_front`), que es la ruta de la evidencia de producción; verificar que el test falla contra el código actual (solo `canonical`) antes de corregir
- [ ] 2.2 Añadir `test_markdown_response_has_no_api_catalog_link_when_manifest_disabled` en `tests/test-delivery.php`: `update_option( Settings::OPTION, array( 'manifest_enabled' => false ) )` + `flush_cache()`, misma ruta negociada, afirmar `assertSame( array( '<' . get_permalink( $post ) . '>; rel="canonical"' ), Http::effective_headers()['link'] )` y que `content-signal` sigue presente; verificar que pasa antes y después de la corrección

## 3. Corrección en `Delivery::serve()` (D1)

- [ ] 3.1 En `src/Markdown/Delivery.php::serve()`, antes de `do_action( 'wpasl_before_serve', 'markdown', $post )`, llamar a `Http::remove_header( 'Link' )` con un comentario que explique que `send_headers` pudo anunciar ya el catálogo para la representación HTML y que la respuesta Markdown construye su propio conjunto `Link`; en el bucle de `markdown_headers()` enviar `Link` con `replace = false`, igual que `Vary` (p. ej. `! in_array( $name, array( 'Vary', 'Link' ), true )`); verificar que 2.1 y 2.2 pasan y que `test_negotiated_markdown_has_no_x_robots_tag`, `test_html_link_header_does_not_replace_existing_link` y `Test_Content_Signals::test_html_and_markdown_announce_api_catalog_link` siguen en verde
- [ ] 3.2 Revisar que el docblock de `serve()` y el de la acción `wpasl_before_serve` siguen siendo exactos (la acción se dispara antes de las cabeceras propias; los módulos que añadan `Link` deben usar `replace = false`) y verificar con `vendor/bin/phpcs --no-cache src/Markdown/Delivery.php tests/test-delivery.php` sin errores ni avisos

## 4. Cobertura documentada (D3)

- [ ] 4.1 En `docs/spec-coverage.md` añadir en `markdown-delivery` las filas "Cabecera Link del catálogo conservada en Markdown" → `Test_Delivery::test_markdown_response_keeps_api_catalog_link` (A) y "Sin cabecera Link del catálogo con el manifiesto deshabilitado" → `Test_Delivery::test_markdown_response_has_no_api_catalog_link_when_manifest_disabled` (A); en `content-signals` añadir "Descubrimiento del catálogo de API en Markdown" y "Catálogo no anunciado en Markdown con el manifiesto deshabilitado" apuntando a los mismos tests, ampliar la fila "Descubrimiento del catálogo de API" con el test de integración y mencionar `fix-markdown-api-catalog-link` en el párrafo introductorio de deltas incluidos; verificar que cada escenario de los dos deltas de `specs/` tiene fila

## 5. Verificación final

- [ ] 5.1 Ejecutar `vendor/bin/phpunit` y `vendor/bin/phpunit -c tests/multisite.xml.dist` y verificar que pasan en verde con dos tests más que en 1.1
- [ ] 5.2 Ejecutar `vendor/bin/phpcs --no-cache` sobre todo el proyecto y verificar código de salida 0
- [ ] 5.3 Ejecutar `openspec validate fix-markdown-api-catalog-link --strict` y verificar que no reporta errores; confirmar con `git diff --stat` que `readme.txt`, `README.md` y la versión del plugin no cambiaron
