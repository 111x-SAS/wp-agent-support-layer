## 1. Preparación del entorno y línea base

- [x] 1.1 Copiar `docs/reviews/2026-09-02-review.md` desde el worktree `review-v1.0.1` a este worktree y verificar que existe en `docs/reviews/`
- [x] 1.2 Ejecutar `composer install`, `composer run build` y `bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest` (contenedor `wpasl-mysql`) y verificar que `vendor/bin/phpunit` y `vendor/bin/phpcs` pasan en verde antes de tocar código

## 2. Alta: documento Markdown completo (D1)

- [x] 2.1 En `DocumentBuilder::render_html()` fijar `$more = 1` (guardando y restaurando `$more` y `$page`), eliminar `<!--more-->`, `<!--nextpage-->` y los bloques `wp:more`/`wp:nextpage` de una copia de `post_content` y pasarla al filtro `the_content`; verificar con `test_document_contains_both_halves_of_more_and_every_nextpage` en `tests/test-document-builder.php` (post con ambas marcas generado fuera de una vista singular → ambas mitades presentes, sin `more-link` ni `(more…)`)
- [x] 2.2 Añadir `test_document_is_identical_from_cron_and_from_singular_request` que compara el documento generado vía `Runner::generate_item()` con el generado vía `Accept: text/markdown` en la URL canónica y verificar que son idénticos

## 3. Alta: URL Markdown de portada estática y permalinks con query string (D2)

- [x] 3.1 En `Delivery::markdown_url()` devolver `add_query_arg('wpasl','md', $permalink)` cuando el permalink contenga `?` o sea la raíz del sitio; verificar con `test_markdown_url_for_static_front_page_uses_query_arg` y `test_markdown_url_for_post_type_without_rewrite_uses_query_arg` en `tests/test-delivery.php`
- [x] 3.2 En `Delivery::handle_md_suffix()` resolver `/.md` a `page_on_front` cuando `show_on_front === 'page'` (sujeto a elegibilidad) y mantener 404 en caso contrario; verificar con `test_md_suffix_for_home_serves_static_front_page` y con el `test_md_suffix_for_home_is_404` existente ajustado a `show_on_front = posts`
- [x] 3.3 Verificar que `<link rel="alternate">`, la cabecera `Link`, `llms.txt` y `CrawlerProbe::site_targets()` usan la URL corregida para la portada estática mediante `test_static_front_page_alternate_links_are_servable` (la URL anunciada responde 200 en Markdown)

## 4. Alta: consultas acotadas al listar elegibles (D3)

- [x] 4.1 En `Eligibility::query()` devolver los ids directamente cuando no haya callbacks en `wpasl_is_eligible`, y precargar cachés con `_prime_post_caches()` en trozos de 500 antes de re-filtrar cuando los haya; verificar con `test_eligible_ids_query_count_is_bounded` en `tests/test-eligibility.php` (60 posts → diferencia de `$wpdb->num_queries` menor que 10) y `test_eligible_ids_with_filter_primes_caches_in_batches`
- [x] 4.2 Añadir `Eligibility::count()` con consulta de conteo y usarla en `Runner::status()`; calcular `generated`/`pending` sin `get_post()`; verificar con `test_status_uses_count_query` en `tests/test-generation-status.php` (mismos totales que antes y cota de consultas)

## 5. Media: exclusión no legible por REST (D4)

- [x] 5.1 Añadir en `ExcludeMetaBox::register()` el filtro `rest_prepare_{$post_type}` que elimina `meta[_wpasl_exclude]` cuando el usuario no puede editar la entrada; verificar con `test_rest_read_hides_exclude_meta_for_anonymous` y `test_rest_read_shows_exclude_meta_for_editor_with_context_edit` en `tests/test-exclude-meta-box.php` (peticiones `WP_REST_Request` a `/wp/v2/posts/{id}`)

## 6. Media: diagnóstico por lotes acotado en tiempo (D5)

- [x] 6.1 En `CrawlerProbe::fetch()` fijar `timeout => 5`, `redirection => 0`, `limit_response_size`, conservar la cabecera `location` y el cuerpo de `robots.txt`/`llms.txt` (máx. 64 KB); verificar con `test_probe_does_not_follow_redirects` en `tests/test-diagnostics.php` (302 a `www.` → una sola petición registrada, ninguna al host de destino)
- [x] 6.2 Extraer `CrawlerProbe::probe_site()` y `probe_crawler()` manteniendo `run()` como composición; verificar que los tests existentes de la sonda siguen pasando
- [x] 6.3 Reescribir `DiagnosticsController::handle()` con la ejecución persistida en `wpasl_diagnostics_run_{user_id}`, el presupuesto `wpasl_diagnostics_time_budget` (30 s) y la redirección 303 a sí mismo con nonce mientras queden crawlers; verificar con `test_diagnostics_splits_into_batches_and_resumes` (presupuesto forzado a 0 → primera petición guarda progreso y redirige; segunda continúa por el primer pendiente; última publica el informe y borra el transient) y `test_diagnostics_step_requires_capability_and_nonce`
- [x] 6.4 Añadir `test_diagnostics_request_duration_is_bounded` que simula 5 s por petición con un reloj falso (filtro sobre `microtime` inyectado o contador de peticiones) y verifica que ninguna petición del administrador sondea más de `budget + 1 crawler`
- [x] 6.5 En `Report::site_checks()` marcar 3xx como advertencia con la `location`; mostrar en `DiagnosticsTab` un aviso cuando exista una ejecución en curso; verificar con `test_report_flags_redirect_as_warning` y con `test_tab_shows_in_progress_notice`

## 7. Media: veredicto de robots.txt desde el cuerpo servido (D6)

- [x] 7.1 Implementar `Report::robots_verdict($body, $agent)` (agrupación por `User-agent`, `Disallow: /` vs `Allow: /`, case-insensitive) y usarlo en `crawler_checks()` comparando con la política; verificar con `test_robots_verdict_matches_served_body`, `test_robots_verdict_warns_when_group_missing` y `test_robots_verdict_warns_when_body_differs_from_policy` en `tests/test-diagnostics.php`

## 8. Media: cola viva y artefactos por antigüedad (D7)

- [x] 8.1 En `Runner::run()` incorporar al frente de la cola los elegibles nunca generados que no estén en ella; verificar con `test_post_published_mid_cycle_is_processed_in_next_run` en `tests/test-runner.php`
- [x] 8.2 Regenerar artefactos cuando el último ciclo completo (y la última regeneración de artefactos) sea más antiguo que el intervalo aunque la cola no se vacíe, guardando `last_artifacts_regenerated`; verificar con `test_artifacts_regenerate_when_cycle_is_older_than_interval`
- [x] 8.3 En `Scheduler::on_settings_updated()` vaciar la cola cuando cambie `post_types` (`State::clear_queue()`); verificar con `test_changing_post_types_resets_queue`

## 9. Media: estado resistente a escrituras concurrentes (D8)

- [x] 9.1 Reescribir `State::save()` para recargar la opción (invalidando el object cache) y fusionar `generated` por timestamp máximo, aplicar los ids de `_removed` y conservar la cola en memoria; hacer que `forget()` y `Runner::prune()` registren `_removed`; verificar con `tests/test-state.php` nuevo: `test_lazy_fill_mark_survives_runner_save`, `test_prune_removal_is_not_resurrected_by_merge` y `test_removed_key_is_never_persisted`

## 10. Media: sanitización idempotente (D9)

- [x] 10.1 Renombrar el campo del formulario a `llms_full_max_mb` en `LlmsTab`, convertir en `Settings::sanitize()` solo desde ese campo y acotar `llms_full_max_bytes` en bytes cuando llegue ya convertido; actualizar los tests de `tests/test-llms-txt.php` al nuevo nombre y verificar con `test_sanitize_is_idempotent_for_every_field` en `tests/test-settings.php` (`sanitize(sanitize($input)) === sanitize($input)`, incluido el caso `add_option` sobre opción borrada) y `test_llms_full_max_out_of_range_clamps_to_100_mb`

## 11. Baja: cabeceras, URLs, autoload e i18n (D11.10 a D11.14)

- [x] 11.1 `ContentSignals::send('markdown')` retira `X-Robots-Tag`; `Delivery`, `LlmsTxtRouter` y `ManifestRouter` añaden `X-Content-Type-Options: nosniff`; `Delivery::send_html_headers()` envía `Link` con `replace = false`; verificar con `test_negotiated_markdown_has_no_x_robots_tag`, `test_markdown_and_json_responses_send_nosniff` y `test_html_link_header_does_not_replace_existing_link` (las cabeceras se capturan con `xdebug_get_headers()` o el helper existente de los tests)
- [x] 11.2 `LeagueConverter::absolutize()` usa el esquema de la URL base para `//host` y normaliza `./` y `../`; verificar con `test_absolutize_resolves_parent_segments_and_protocol_relative_urls` en `tests/test-document-builder.php`
- [x] 11.3 Autoload `yes` para `wpasl_settings` y `wpasl_storage_token` en activación y guardado, más la comprobación única por versión (`wpasl_version`, añadida a `Uninstaller::OPTIONS`) que aplica `wp_set_option_autoload_values()`; verificar con `test_settings_and_token_options_are_autoloaded` en `tests/test-settings.php` y `test_uninstall_removes_version_option` en `tests/test-lifecycle.php`
- [x] 11.4 Envolver en `__()` con comentarios `translators:` las cadenas de `Report`, `LlmsTxtBuilder::optional_links()` y `CLI\Commands`; regenerar `languages/wp-agent-support-layer.pot` con `wp i18n make-pot` y verificar con `grep -c 'Blocked by robots.txt' languages/*.pot` que las cadenas nuevas aparecen y que `vendor/bin/phpcs` no reporta `WordPress.WP.I18n`

## 12. Baja: sonda de almacenamiento, sitemap, catálogo y WP-CLI (D11.15 a D11.18)

- [x] 12.1 `Storage::ensure()` escribe `probe.txt` y `CrawlerProbe::storage_direct_url()` lo usa siempre; verificar con `test_storage_probe_runs_without_generated_documents` en `tests/test-diagnostics.php` y que `Storage::clear()` lo conserva
- [x] 12.2 `LlmsTxtBuilder::optional_links()` usa `get_sitemap_url('index')`; verificar con `test_optional_sitemap_link_uses_query_form_with_plain_permalinks` en `tests/test-llms-txt.php`
- [x] 12.3 `Catalog::all()` rechaza tokens con `.`, `[` o `]`; verificar con `test_catalog_rejects_tokens_with_dots_and_brackets` en `tests/test-crawler-robots.php`
- [x] 12.4 `Commands::generate()` termina con `WP_CLI::error()` si el post type no está habilitado o falta la librería; verificar con `test_cli_generate_errors_for_disabled_post_type` (doble de `WP_CLI` ya usado en los tests o comprobación de la excepción) en `tests/test-cli.php`

## 13. Baja: WPCS y regeneración manual (D11.19, D10)

- [x] 13.1 Acotar el `phpcs:disable` de `Storage.php`, pasar los arrays de `Catalog` a multilínea, imprimir el `<textarea>` de `DiagnosticsTab` sin sangría, mover el guion largo de `ManifestsTab` a una cadena traducible y aplicar `esc_url_raw()` a `REQUEST_URI` en `Delivery::handle_md_suffix()`; verificar con `vendor/bin/phpcs` en verde y `test_physical_robots_textarea_has_no_leading_whitespace`
- [x] 13.2 `Scheduler::run_soon()` programa `wp_schedule_single_event(time(), HOOK, array('manual'))` sin tocar el evento recurrente, y `unschedule()` limpia también los eventos únicos; verificar con `test_run_soon_schedules_single_event_and_keeps_recurring_timestamp` y `test_deactivation_clears_manual_events` en `tests/test-runner.php` (o el test de scheduler existente)

## 14. Tests faltantes de la revisión y cobertura

- [x] 14.1 Añadir `test_uninstall_removes_storage_and_options_on_every_site` en `tests/test-multisite.php` y verificar con `vendor/bin/phpunit -c tests/multisite.xml.dist`
- [x] 14.2 Actualizar `docs/spec-coverage.md`: mapear los escenarios nuevos de las seis delta specs a sus tests y restaurar a **A** las seis filas que la revisión marcó como parciales; verificar que cada test citado existe con `grep -c "function <nombre>" tests/*.php`

## 15. Verificación y release 1.0.2

- [x] 15.1 Ejecutar `vendor/bin/phpunit`, `vendor/bin/phpunit -c tests/multisite.xml.dist` y `vendor/bin/phpcs` y verificar que todo pasa en verde; ejecutar `bin/smoke-docker.sh` para PHP 7.4 y 8.3 y verificar activación limpia
- [x] 15.2 Ejecutar `bin/crawl-check.sh` contra el sitio local con portada estática configurada y verificar que la URL Markdown de la portada responde 200 y que el diagnóstico completo termina con todas las peticiones del navegador por debajo de 100 s (guardar la evidencia en `docs/evidence/`)
- [x] 15.3 Subir la versión a 1.0.2 en `wp-agent-support-layer.php` (cabecera y `WPASL_VERSION`), `readme.txt` (`Stable tag` y changelog con los hallazgos corregidos) y `README.md`; verificar con `grep -rn "1\.0\.1" --include='*.php' --include='*.txt' --include='*.md' . | grep -v vendor | grep -v changelog` que no queda ninguna referencia a 1.0.1 fuera del historial
- [x] 15.4 Commit por hito (Conventional Commits, autor Mao Rodriguez) y verificar con `git log --oneline main..HEAD` que cada grupo de tareas tiene su commit
