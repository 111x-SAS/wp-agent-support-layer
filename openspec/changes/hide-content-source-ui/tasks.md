## 1. Interfaz

- [ ] 1.1 En `src/Admin/Tabs/GeneralTab.php`, retirar el fieldset "Content source" (el bloque `<?php if ( ! empty( $enabled ) ) : ?> ... <?php endif; ?>` que renderiza el `<select>` por post type) y la variable `$sources`, que solo alimentaba ese bloque; verificar que `php -l src/Admin/Tabs/GeneralTab.php` no reporta errores de sintaxis.
- [ ] 1.2 Cargar la pestaña General como administrador en un sitio de prueba y confirmar visualmente que ya no aparece el bloque "Content source" mientras "Content selector (CSS)" y el resto de campos siguen presentes.

## 2. Tests

- [ ] 2.1 En `tests/test-settings.php::test_general_tab_renders_content_source_and_selector`, quitar las aserciones sobre los `<select name="wpasl_settings[content_source]...">` y el texto "Content source" (líneas 327-331 y 348-349 del archivo actual), conservando las aserciones sobre "Content selector (CSS)" y su valor; renombrar el método a `test_general_tab_renders_content_selector` si el nombre deja de reflejar lo que prueba.
- [ ] 2.2 Añadir un test que confirme que guardar la pestaña General limpia un origen forzado previamente mediante `update_option` directo (por ejemplo, fijar `content_source => ['page' => 'rendered']`, invocar `sanitize()` con `_tab = 'general'` y los demás campos de esa pestaña, y comprobar que `content_source` queda `array()`), cubriendo el escenario "Guardar la pestaña General limpia un origen forzado" de la spec.
- [ ] 2.3 Ejecutar `vendor/bin/phpunit` y `vendor/bin/phpunit -c tests/multisite.xml.dist` y verificar que ambas suites pasan sin fallos ni riesgos nuevos.
- [ ] 2.4 Ejecutar `vendor/bin/phpcs --no-cache` sobre `src/Admin/Tabs/GeneralTab.php` y `tests/test-settings.php` y verificar que no reporta incidencias nuevas.

## 3. Documentación de especificación

- [ ] 3.1 Verificar con `openspec validate hide-content-source-ui --strict` que la propuesta y el delta spec son válidos antes de archivar el cambio.
