## 1. Preparación del entorno y línea base

- [x] 1.1 Ejecutar `composer install`, `composer run build` y `bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest` (contenedor `wpasl-mysql`; sexto argumento `true` si no hay cliente `mysql`) y verificar que `vendor/bin/phpunit`, `vendor/bin/phpunit -c tests/multisite.xml.dist` y `vendor/bin/phpcs` pasan en verde antes de tocar código (226 tests)

## 2. Alta: acción manual fuera del formulario de ajustes (D1, hallazgo 1)

- [x] 2.1 En `Page::render()` cerrar `</form>` y disparar `do_action( 'wpasl_page_after_form', $current, $this )` para toda pestaña; en `GenerationStatus::register()` engancharse a ese hook y renderizar solo cuando la pestaña sea `general`; documentar en `GeneralTab::render()` que `wpasl_general_tab_after` se ejecuta dentro del formulario de ajustes; verificar con `test_general_tab_renders_status_form_outside_settings_form` en `tests/test-settings.php` (render completo con `GenerationStatus` registrado: exactamente dos `<form`, ninguno anidado, `name="submit"` y `wpasl_settings[_tab]` en el formulario `options.php`, `wpasl_regenerate_nonce` en el de `admin-post.php`)
- [x] 2.2 Añadir `test_general_tab_after_hook_still_fires_inside_form` que registra un callback en `wpasl_general_tab_after` y verifica que su salida aparece antes del `</form>` del formulario de ajustes, y `test_page_after_form_hook_receives_current_tab` para la firma del hook nuevo
- [x] 2.3 Ampliar `bin/smoke-docker.sh` con un POST autenticado (cookie de login + nonce leído de la pestaña General) a `admin-post.php?action=wpasl_regenerate` y verificar la redirección 302 a `tools.php?page=wp-agent-support-layer&tab=general&wpasl_notice=scheduled` y la presencia del evento único en `wp cron event list`; verificar además que un POST a `options.php` con `wpasl_settings[_tab]=general` y `batch_size=10` redirige con `settings-updated=true` y persiste el valor

## 3. Alta: página de entradas servible (D4, hallazgo 2)

- [x] 3.1 En `Delivery` añadir `posts_page_id()` e `is_markdown_context()`; resolver en `handle_md_suffix()` la ruta de la página de entradas antes de `url_to_postid()`; usar `is_markdown_context()` en `maybe_serve()`, `send_html_headers()` y `print_alternate_link()`; verificar con `test_posts_page_is_served_by_md_suffix_accept_and_query_arg` en `tests/test-delivery.php` (`show_on_front=page`, `page_on_front=A`, `page_for_posts=B` → `/blog.md`, `/blog/` con `Accept: text/markdown` y `/blog/?wpasl=md` responden 200 con `# <título de B>`)
- [x] 3.2 Añadir `test_posts_page_html_announces_alternate_link` (el `<head>` de `/blog/` contiene `link rel="alternate" type="text/markdown"` y la cabecera `Link`) y, en `tests/test-llms-txt.php`, `test_every_emitted_markdown_url_is_servable` (cada URL de `llms.txt` responde 200 en Markdown, incluida la página de entradas)

## 4. Media: confirmación al guardar (D2, hallazgo 3)

- [x] 4.1 Sustituir `settings_errors( self::GROUP )` por `settings_errors()` en `Page::render()`; verificar con `test_page_shows_settings_saved_notice` en `tests/test-settings.php` (`$_GET['settings-updated']='true'` y transient `settings_errors` con la entrada `general/settings_updated` → el HTML contiene `Settings saved.` exactamente una vez)

## 5. Media: sanitización parcial (D3, hallazgo 4)

- [x] 5.1 En `Settings::sanitize()` devolver `$current` con `_tab` no vacío y desconocido; con `_tab` vacío limitar las claves a las presentes en la entrada (mapeando `llms_full_max_bytes_mb` a `llms_full_max_bytes`); verificar con `test_sanitize_with_unknown_tab_keeps_every_stored_value`, `test_sanitize_without_tab_updates_only_present_keys` y que `test_sanitize_is_idempotent_for_every_field` sigue pasando
- [x] 5.2 Añadir `test_third_party_tab_saves_without_wiping_settings` que registra un `Tab` falso con slug `acme` en `wpasl_register_tabs`, renderiza `Page::render()` en esa pestaña y ejecuta `sanitize( array( '_tab' => 'acme', 'acme_field' => 'x' ) )` verificando que `post_types` y las señales se conservan

## 6. Media: sitios creados tras la activación en red (D5, hallazgo 5)

- [ ] 6.1 En `Plugin::boot()` añadir el handler de `wp_initialize_site` (prioridad 100) que, con el plugin en `active_sitewide_plugins`, ejecuta `Lifecycle::activate_site()` dentro de `switch_to_blog()`; en `Plugin::maybe_upgrade()` reprogramar cuando `Scheduler::current_interval()` sea `null` y asegurar el almacenamiento; verificar con `test_site_created_after_network_activation_is_configured` en `tests/test-multisite.php` (evento recurrente y opción presentes en el sitio nuevo) y `test_site_created_without_network_activation_is_untouched`
- [ ] 6.2 Añadir `test_maybe_upgrade_reschedules_missing_event` en `tests/test-scheduler.php` (borrar el evento, ejecutar `maybe_upgrade()` → `wp_next_scheduled( Scheduler::HOOK )` no es `false`)

## 7. Media: WP-CLI acotado por post type (D6, hallazgos 6 y 13)

- [ ] 7.1 Añadir `$post_types` a `Runner::run()`, pasarlo a `build_queue()` en la rama de reconstrucción, salir de `run_cycle()` con cola vacía y pasar `$types` desde `Commands::generate()` con `--batch`; verificar con `test_generate_post_type_with_batch_only_processes_that_type` y `test_generate_post_type_without_eligible_items_processes_nothing` en `tests/test-cli.php`
- [ ] 7.2 Implementar `reset_cycle( $post_types = null )` que solo olvide las marcas de esos tipos; verificar con `test_generate_all_with_post_type_keeps_other_types_generated` (posts siguen en `generated` tras `--all --post-type=page`) y `test_reset_cycle_without_types_forgets_everything` en `tests/test-runner.php`

## 8. Media: fallos de generación visibles (D7, hallazgo 7)

- [ ] 8.1 Añadir `failed` al estado (`State::save()` fusiona la clave), motivo en `write_document()`/`Storage::write()` con `error_log()`, contador, acción `wpasl_generation_failed`, umbral `wpasl_max_failures` en `never_generated_ids()`/`build_queue()` y reinicio tras éxito; verificar con `test_failed_items_are_counted_and_deprioritized` en `tests/test-runner.php` (generador stub que devuelve `false`: tras dos `run()` el estado tiene `failed[$id] === 2` y la acción se disparó; con tres fallos y un ítem sano, el siguiente `run()` procesa primero el sano) y `test_successful_generation_clears_failure_counter`
- [ ] 8.2 Exponer `failed` en `Runner::status()`, fila "Failed items" en `GenerationStatus::render()` y línea en `wp wpasl status`; verificar con `test_status_shows_failed_items` en `tests/test-generation-status.php` y `test_status_command_lists_failed_items` en `tests/test-cli.php`
- [ ] 8.3 Añadir `test_storage_write_failure_is_logged` en `tests/test-storage.php` (directorio de solo lectura o `wp_mkdir_p` forzado a fallar → `write()` devuelve `false` y el registro de errores contiene la ruta)

## 9. Media: escrituras del estado acotadas (D8, hallazgo 8)

- [ ] 9.1 Cortocircuitar `Runner::remove_document()` para post types no habilitados y registrar los rellenos perezosos de `generate_item()` en el estado en curso cuando `run()` está activo; verificar con `test_trashing_non_enabled_post_type_does_not_write_state` y `test_run_with_full_llms_writes_state_once` en `tests/test-runner.php` (100 ítems no generados con `llms_full_enabled`, contador en `pre_update_option_wpasl_state` igual a 1 y las 100 marcas presentes)

## 10. Media: vista previa de robots.txt igual al núcleo (D9, hallazgo 9)

- [ ] 10.1 Reescribir `RobotsTxt::generated_output()` para reproducir `do_robots()` (paths de `admin_url()`, sin rama `Disallow: /`, `$public` solo al filtro); verificar con `test_generated_output_equals_do_robots_when_public` y `test_generated_output_equals_do_robots_when_not_public` en `tests/test-crawler-robots.php` (comparación exacta con `ob_start(); do_robots();` para `blog_public` 1 y 0), reemplazando la comprobación de prefijo de `test_generated_output_matches_the_virtual_file`

## 11. Media: llms.txt por fichero y llms-full.txt en frío (D10, hallazgo 10)

- [ ] 11.1 Añadir `LlmsTxtBuilder::generate_file()` y usarla en `generate()` y en `LlmsTxtRouter::document()`; verificar con `test_llms_txt_request_does_not_build_llms_full` en `tests/test-llms-txt.php` (con `llms_full_enabled`, tras `invalidate()` pedir `/llms.txt` → 200, `llms-full.txt` ausente, `list_files('md')` vacío)
- [ ] 11.2 Responder 503 con `Retry-After` y `Cache-Control: no-store` en `/llms-full.txt` ausente, programar `wpasl_build_llms_full` mediante `Scheduler::schedule_llms_full()` (sin duplicados) y registrar su handler; incluir el hook en `Uninstaller`; verificar con `test_missing_llms_full_returns_503_and_schedules_build` (503, cabeceras, evento programado; ejecutar el hook → fichero presente y 200) y `test_llms_full_build_event_is_not_duplicated`

## 12. Media: diagnóstico por crawler para .md y robots.txt (D11, hallazgo 11)

- [ ] 12.1 Añadir `post_md` y `robots` a `CrawlerProbe::probe_crawler()`, las comprobaciones `markdown_url` y `robots_fetch` a `Report::crawler_checks()` y su render en `DiagnosticsTab`; actualizar `test_probe_uses_crawler_user_agents_and_accept_headers` (5 peticiones por crawler); verificar con `test_report_contains_markdown_url_check_per_crawler` y `test_ua_specific_block_on_md_is_reported_only_for_that_crawler` en `tests/test-diagnostics.php` (403 en `.md` solo para `ClaudeBot` vía `pre_http_request`)

## 13. Media: señales en el documento OpenAPI por REST (D12, hallazgo 12)

- [ ] 13.1 Inyectar `ContentSignals` en `ManifestRouter` (parámetro opcional, actualizado en `Plugin::boot()`) y añadir sus cabeceras en `rest_openapi()`; verificar ampliando `test_openapi_rest_route` en `tests/test-agent-manifest.php` (`Content-Signal` y `Content-Usage` presentes; solo `Content-Signal` con el interruptor desactivado)

## 14. Baja: ciclo de vida y desinstalación (D13, hallazgos 14 y 29)

- [ ] 14.1 Quitar `flush_rewrite_rules()` de `Lifecycle::deactivate()`, paginar `get_sites()` en `Lifecycle::for_each_site()` y `Uninstaller` (100 por página), y usar `Scheduler::unschedule()` más `wp_clear_scheduled_hook( 'wpasl_build_llms_full' )` en `Uninstaller`; verificar con `test_uninstall_clears_manual_event` en `tests/test-lifecycle.php` (`run_soon()` antes de desinstalar → `_get_cron_array()` sin `wpasl_generate`) y `test_deactivate_does_not_flush_rewrite_rules`; en multisitio, `test_network_activation_paginates_sites` con más de 100 sitios simulados mediante el filtro `sites_pre_query`

## 15. Baja: cabeceras, rutas y manifiestos (D14, D15, hallazgos 15 a 19, 24 y 25)

- [ ] 15.1 Añadir la clave `sent` al log de `Http` y el parámetro `$only_sent` a `effective_headers()`; verificar con `test_log_marks_headers_not_sent` en un `tests/test-http.php` nuevo y que `tests/test-delivery.php` sigue pasando
- [ ] 15.2 Servir el catálogo con `Content-Type: application/linkset+json` sin `charset` y ajustar `test_api_catalog_route`; emitir `Link: <…/.well-known/api-catalog>; rel="api-catalog"` en HTML y Markdown cuando `manifest_enabled`; verificar con `test_html_and_markdown_announce_api_catalog_link` y `test_no_api_catalog_link_when_manifest_disabled` en `tests/test-content-signals.php`
- [ ] 15.3 Restringir `X-Robots-Tag: noai` a HTML (contexto `WP` sin `feed`, `robots`, `sitemap` ni `sitemap-stylesheet`); verificar con `test_robots_feed_and_sitemap_have_no_noai_header` en `tests/test-content-signals.php` (`go_to('/robots.txt')`, `/feed/`, `/wp-sitemap.xml` → `Content-Signal` presente, sin `X-Robots-Tag` del plugin)
- [ ] 15.4 Comparar paths exactos en `LlmsTxtRouter` y `ManifestRouter`; verificar con `test_non_canonical_root_paths_are_not_served` en `tests/test-llms-txt.php` y `tests/test-agent-manifest.php` (`/llms.txt/`, `//llms.txt`, `/agent-skills.json/`, `/.well-known/api-catalog/` → no interceptados)
- [ ] 15.5 Plantilla `read-markdown` con `{+path}` y `servers`/`paths` válidos con enlaces simples; verificar con `test_read_markdown_template_uses_reserved_expansion` y `test_openapi_servers_url_has_no_query_string_with_plain_permalinks` en `tests/test-agent-manifest.php`

## 16. Baja: diagnóstico (D16, hallazgos 20 y 21)

- [ ] 16.1 Añadir el helper de citado para shell en `DiagnosticsTab` y aplicarlo a URL y user-agent de los comandos `curl`; verificar con `test_curl_commands_are_shell_safe` en `tests/test-diagnostics.php` (user-agent con comilla simple por filtro → comando con `'\''`)
- [ ] 16.2 Normalizar el informe en `Report::load()` contra `Report::defaults()` con comprobación de versión; verificar con `test_tab_renders_report_from_previous_version_without_notices` (transient sin `infrastructure.storage_exposed` ni comprobaciones nuevas → render sin avisos de PHP y comprobaciones marcadas como no disponibles)

## 17. Baja: llms.txt (hallazgos 22 y 23)

- [ ] 17.1 Omitir `## Optional` sin enlaces y aplicar el orden por menú solo a `page`; verificar con `test_optional_section_is_omitted_without_links` y `test_hierarchical_cpt_is_ordered_by_date` en `tests/test-llms-txt.php`

## 18. Baja: entrega Markdown y conversión (hallazgos 27, 28, 30, 33, 35)

- [ ] 18.1 Exigir límite de segmento en `Delivery::relative_path()`; verificar con `test_md_suffix_respects_base_path_segment_boundary` en `tests/test-delivery.php` (sitio en `/blog/`: `/blogx.md` → 404, `/blog/x.md` → 200)
- [ ] 18.2 Conservar la barra de la raíz en `LeagueConverter::absolutize()` para referencias `?…` y corregir la aserción de `tests/test-converter.php:48` a `https://example.org/?p=1`; añadir `set_base_url()` a `ConverterInterface` y eliminar `method_exists` en `DocumentBuilder`; verificar con `test_converter_interface_requires_base_url` (conversor de prueba que implementa la interfaz completa recibe la URL base)
- [ ] 18.3 Guardar y restaurar `$pages`, `$numpages`, `$multipage`, `$authordata` e `$id` en `DocumentBuilder`; verificar con `test_building_documents_restores_all_post_globals` en `tests/test-document-builder.php` (generar dos documentos fuera de una vista singular → los globals valen lo mismo que antes)
- [ ] 18.4 Unificar el predicado de exclusión (`meta_value NOT IN ('', '0')` y el mismo criterio en `is_excluded()`); verificar con `test_non_canonical_exclusion_value_excludes_everywhere` en `tests/test-eligibility.php` (`update_post_meta( $id, '_wpasl_exclude', 'yes' )` → no elegible por petición ni en `eligible_ids()`)

## 19. Baja: almacenamiento (hallazgos 31 y 32)

- [ ] 19.1 Eliminar `*.tmp` con más de una hora en `Runner::prune()`; añadir `web.config` en `Storage::ensure()` e `index.php` en cada directorio creado por `Storage::write()`; verificar con `test_prune_removes_stale_tmp_files` en `tests/test-runner.php` y `test_write_creates_index_in_new_subdirectories` y `test_ensure_writes_web_config` en `tests/test-storage.php`

## 20. Documentación, cobertura y release 1.0.3

- [ ] 20.1 Añadir la sección "Filters" a `readme.txt` (y su resumen en `README.md`) con todos los filtros y acciones listados en D16, incluidos `wpasl_page_after_form`, `wpasl_generation_failed` y `wpasl_max_failures`; verificar que cada nombre listado existe en `src/` con `grep`
- [ ] 20.2 Actualizar `docs/spec-coverage.md` con las filas nuevas y modificadas de los ocho deltas (incluidas las once filas que el review marca como parciales) apuntando a los tests creados; verificar que cada test citado existe con `grep -rn "function test_" tests/`
- [ ] 20.3 Regenerar `languages/wp-agent-support-layer.pot` con las cadenas nuevas ("Failed items", 503 de `llms-full.txt`, comprobaciones nuevas del diagnóstico) y verificar que `vendor/bin/phpcs` sigue limpio
- [ ] 20.4 Ejecutar `vendor/bin/phpunit`, `vendor/bin/phpunit -c tests/multisite.xml.dist` y `vendor/bin/phpcs` en verde; ejecutar `bash bin/smoke-docker.sh` en PHP 7.4 y 8.3 con los pasos nuevos de 2.3 y guardar la evidencia en `docs/evidence/2026-09-XX-smoke-1.0.3.md`; comprobación manual del hallazgo 1 en un WordPress limpio (guardar "Items per run", "Regenerate now" y "Regenerate everything") documentada en la misma evidencia
- [ ] 20.5 Subir la versión a 1.0.3 en `wp-agent-support-layer.php` (cabecera y `WPASL_VERSION`), `readme.txt` (`Stable tag`, changelog y upgrade notice que mencione el cambio de `ConverterInterface` y el hook nuevo) y `README.md`; verificar con `grep -rn "1\.0\.2" --include=*.php --include=*.txt --include=*.md .` que no queda ninguna referencia a la versión anterior fuera del changelog y de `docs/`
