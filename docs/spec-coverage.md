# Specification coverage

Every scenario of the main specs (`openspec/specs`, including the deltas of the changes
`fix-review-findings`, 1.0.2, `fix-review-1-0-2`, 1.0.3, `fix-markdown-api-catalog-link`, `detect-cache-enabler-page-cache`, `auth-md-discovery`, `agentic-readiness-round-1`, `rendered-content-source` and `cache-enabler-auto-headers`) mapped to the automated test that
exercises it (PHPUnit against the official WordPress test suite), or to the manual evidence when the behaviour depends on a web server.
Test classes live in `tests/`.

Legend: **A** automated (PHPUnit), **M** manual evidence (`docs/evidence/`; the 1.0.3 smoke is `2026-09-02-smoke-1.0.3.md`, the rendered content source smoke is `2026-09-08-smoke-rendered-content-source.md`), **A+M** both.

## markdown-delivery

| Requirement / scenario | Test | |
| --- | --- | --- |
| Elegible: entrada publicada de un post type habilitado | `Test_Eligibility::test_published_post_of_enabled_type_is_eligible`, `Test_Delivery::test_accept_header_serves_markdown_on_canonical_url` | A |
| Elegible: entrada despublicada después de la última generación | `Test_Delivery::test_unpublished_after_generation_is_not_served` | A |
| Elegible: entrada protegida con contraseña | `Test_Eligibility::test_password_protected_is_not_eligible`, `Test_Delivery::test_non_eligible_content_is_never_served_as_markdown` | A |
| Elegible: post type no habilitado | `Test_Eligibility::test_disabled_post_type_is_not_eligible`, `Test_Delivery::test_no_alternate_link_for_disabled_post_type` | A |
| Elegible: entrada excluida manualmente | `Test_Eligibility::test_excluded_post_is_not_eligible`, `Test_Delivery::test_non_eligible_content_is_never_served_as_markdown` | A |
| Marca de exclusión con valor no canónico | `Test_Eligibility::test_non_canonical_exclusion_value_excludes_everywhere` | A |
| Accept: agente que prefiere Markdown | `Test_Delivery::test_prefers_markdown` (markdown only, equal preference, agent with fallbacks), `test_accept_header_serves_markdown_on_canonical_url` | A+M |
| Accept: navegador que prefiere HTML | `Test_Delivery::test_prefers_markdown` (browser), `test_browser_accept_gets_html` | A+M |
| Accept: ambos tipos con preferencia por HTML | `Test_Delivery::test_prefers_markdown` (html preferred) | A |
| Accept: comodín con Markdown poco preferido / preferido | `Test_Delivery::test_prefers_markdown` (markdown below wildcard, wildcard only, agent with fallbacks) | A |
| Sufijo .md sobre enlace permanente bonito | `Test_Delivery::test_md_suffix_serves_markdown`, `test_md_suffix_for_page_hierarchy` | A+M |
| Sufijo .md sobre contenido inexistente | `Test_Delivery::test_md_suffix_for_unknown_content_is_404`, `test_md_suffix_for_home_is_404`, `Test_Markdown_404::test_md_suffix_for_unknown_content_serves_markdown_404` | A+M |
| 404 Markdown: sufijo .md sobre contenido inexistente (404, `text/markdown`, `no-store`, `nosniff`, enlaces) | `Test_Markdown_404::test_md_suffix_for_unknown_content_serves_markdown_404` | A |
| 404 Markdown: sufijo .md sobre contenido no elegible (borrador, mismo cuerpo) | `Test_Markdown_404::test_md_suffix_for_draft_serves_markdown_404` | A |
| 404 Markdown: Accept que prefiere Markdown en una URL inexistente (`Vary: Accept`, también `?wpasl=md`) | `Test_Markdown_404::test_accept_markdown_on_unknown_url_serves_markdown_404` | A |
| 404 Markdown: navegador en una URL inexistente (404 HTML del tema; con `.md` manda el sufijo) | `Test_Markdown_404::test_browser_accept_keeps_html_404` | A |
| 404 Markdown: enlaces según la configuración (`auth.md`, sitemap, catálogo y `Link` según ajustes) | `Test_Markdown_404::test_links_follow_configuration` | A |
| 404 Markdown: sin reflejo de la petición | `Test_Markdown_404::test_body_never_reflects_the_request` | A |
| 404 Markdown: cabeceras de señales (`Content-Signal`, sin `X-Robots-Tag`, un solo `Link rel="api-catalog"`) | `Test_Markdown_404::test_headers_and_single_api_catalog_link` | A |
| 404 Markdown: filtro sobre el cuerpo (`wpasl_markdown_404`) | `Test_Markdown_404::test_body_filter` | A |
| Portada estática (URL con `wpasl=md` y `/.md`) | `Test_Delivery::test_markdown_url_for_static_front_page_uses_query_arg`, `test_md_suffix_for_home_serves_static_front_page`, `test_md_suffix_for_home_is_404_when_front_page_is_excluded`, `test_static_front_page_alternate_links_are_servable` | A+M |
| Página de entradas (`page_for_posts`) | `Test_Delivery::test_posts_page_is_served_by_md_suffix_accept_and_query_arg`, `Test_Llms_Txt::test_every_emitted_markdown_url_is_servable` | A |
| Instalación en subdirectorio | `Test_Delivery::test_md_suffix_respects_base_path_segment_boundary` | A |
| Ruta reservada para auth.md (con y sin publicación) | `Test_Auth_Md::test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg`, `test_non_canonical_root_paths_are_not_served` | A |
| URL alternativa de un contenido que colisiona con auth.md (HTML, `Link`, `llms.txt`, `/auth/.md`) | `Test_Auth_Md::test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg`, `Test_Llms_Txt::test_every_emitted_markdown_url_is_servable` | A |
| Ruta reservada en subdirectorio | `Test_Auth_Md::test_reserved_path_respects_base_path` | A |
| Post type sin reglas de reescritura | `Test_Delivery::test_markdown_url_for_post_type_without_rewrite_uses_query_arg` | A |
| Raíz sin portada estática | `Test_Delivery::test_md_suffix_for_home_is_404` | A |
| Parámetro de consulta con enlaces simples | `Test_Delivery::test_markdown_url_with_plain_permalinks`, `test_query_var_serves_markdown_regardless_of_accept` | A |
| Cabeceras presentes (incl. `nosniff`) | `Test_Delivery::test_markdown_headers`, `test_cache_max_age_follows_the_schedule`, `test_markdown_and_json_responses_send_nosniff` | A+M |
| Estimación de tokens coherente | `Test_Delivery::test_markdown_headers`, `Test_Document_Builder::test_token_estimate` | A |
| Sin X-Robots-Tag en Markdown negociado | `Test_Delivery::test_negotiated_markdown_has_no_x_robots_tag` | A |
| Cabecera Link adicional preservada | `Test_Delivery::test_html_link_header_does_not_replace_existing_link` (also keeps the `api-catalog` link added by the signals) | A |
| Cabecera Link del catálogo conservada en Markdown (`Accept`, sufijo `.md` y `?wpasl=md`) | `Test_Delivery::test_markdown_response_keeps_api_catalog_link` | A |
| Sin cabecera Link del catálogo con el manifiesto deshabilitado | `Test_Delivery::test_markdown_response_has_no_api_catalog_link_when_manifest_disabled` | A |
| Enlace alternativo en una entrada elegible | `Test_Delivery::test_html_headers_and_alternate_link_for_eligible_post` | A+M |
| Sin enlace en contenido no elegible | `Test_Delivery::test_no_alternate_link_for_disabled_post_type` | A |
| Enlace alternativo en la página de entradas | `Test_Delivery::test_posts_page_html_announces_alternate_link` | A |
| Front matter completo (incluida la clave `source`) | `Test_Document_Builder::test_front_matter_contains_required_keys_and_taxonomies`, `test_description_falls_back_to_content_and_taxonomies_are_omitted_when_empty`, `test_front_matter_has_source_editor_by_default` | A |
| Clave source según el origen (`editor` / `rendered`, resto del documento sin cambios) | `Test_Document_Builder::test_front_matter_has_source_editor_by_default`, `test_rendered_source_uses_loopback_body`, `test_loopback_failure_falls_back_to_editor_and_reports` (fallback keeps `source: "editor"`) | A+M |
| Filtros compartidos por ambos orígenes (`wpasl_markdown_html` sobre el editor y el fragmento renderizado) | `Test_Document_Builder::test_html_filter_applies_to_rendered_fragment` | A |
| Limpieza de elementos no textuales | `Test_Converter::test_non_content_elements_are_removed`, `Test_Document_Builder::test_scripts_forms_iframes_are_stripped_and_links_absolutized` | A |
| Enlaces relativos (todo conversor recibe la URL base) | `Test_Converter::test_relative_links_and_images_become_absolute`, `test_absolute_and_special_links_are_untouched`, `Test_Document_Builder::test_converter_interface_requires_base_url` | A |
| Contenido con more y nextpage | `Test_Document_Builder::test_document_contains_both_halves_of_more_and_every_nextpage`, `test_block_more_and_nextpage_wrappers_are_removed`, `test_render_restores_more_and_page_globals`, `Test_Delivery::test_document_is_identical_from_cron_and_from_singular_request` | A |
| Ruta relativa con segmentos padre | `Test_Document_Builder::test_absolutize_resolves_parent_segments_and_protocol_relative_urls` | A |
| Referencia solo con cadena de consulta en la raíz | `Test_Converter::test_relative_links_and_images_become_absolute` | A |
| Documento previamente generado | `Test_Delivery::test_stored_document_is_served_without_reconverting` | A |
| Documento ausente | `Test_Delivery::test_accept_header_serves_markdown_on_canonical_url` (lazy fill), `Test_Runner::test_generate_item_lazily_fills_and_records` | A |
| Edición de una entrada | `Test_Delivery::test_editing_does_not_change_the_served_document`, `Test_Runner::test_updating_a_post_keeps_the_stored_document_until_the_next_cycle` | A |
| Generación sin efectos sobre la petición | `Test_Document_Builder::test_building_documents_restores_all_post_globals`, `test_render_restores_more_and_page_globals` | A |
| Origen: anulación por entrada (`post_override`, gana sobre Elementor) | `Test_Content_Source::test_post_override_wins`, `Test_Exclude_Meta_Box::test_save_stores_source_override_and_clears_default` | A+M |
| Origen: ajuste por tipo de contenido (`post_type_setting`) | `Test_Content_Source::test_post_type_setting_wins_over_auto_conditions` | A |
| Origen: plantilla asignada (`page_template`; `default` no aplica) | `Test_Content_Source::test_assigned_page_template_triggers_rendered` | A+M |
| Origen: meta de constructor (Elementor completo, sin `_elementor_data`, Divi, Beaver Builder, Bricks, Oxygen, Breakdance, clave por filtro) | `Test_Content_Source::test_builder_meta_triggers_rendered` | A+M |
| Origen: archivo de plantilla con contenido fijo (`template_file:single-solucion.php`, lectura acotada, parte literal seguida un nivel, caché por mtime) | `Test_Content_Source::test_template_file_without_the_content_triggers_rendered`, `test_template_part_literal_is_followed_one_level`, `test_template_cache_keyed_by_mtime`, `test_block_theme_template_without_post_content_triggers_rendered` | A |
| Origen: una clave de array `'include' => ...` (get_posts()/WP_Query) no se confunde con un include dinámico | `Test_Content_Source::test_include_as_array_key_does_not_look_like_a_dynamic_include` | A |
| Origen: archivo de plantilla que imprime el editor o incluye partes dinámicas (inconcluyente) | `Test_Content_Source::test_template_file_with_the_content_or_dynamic_part_is_inconclusive`, `test_template_part_literal_is_followed_one_level`, `test_block_theme_template_without_post_content_triggers_rendered` | A |
| Origen: contenido del editor vacío sin umbral (`editor`) y umbral activado por filtro (`empty_editor`) | `Test_Content_Source::test_empty_editor_rule_is_off_by_default_and_enabled_by_filter`, `Test_Document_Builder::test_empty_editor_is_rendered_and_its_body_is_reused_on_fallback` | A |
| Origen: contenido normal (`editor` / `default`) | `Test_Content_Source::test_normal_content_defaults_to_editor`, `Test_Document_Builder::test_front_matter_has_source_editor_by_default` | A+M |
| Origen: resolución sustituida por filtro (`wpasl_content_source`) | `Test_Content_Source::test_resolution_filter` | A |
| Loopback: petición de renderizado (una petición `GET` al propio host con `Accept: text/html`, cabecera y parámetro, 10 s, 2 MB, sin redirecciones automáticas) | `Test_Rendered_Page::test_request_carries_marker_accept_timeout_and_size`, `test_external_host_is_never_requested`, `Test_Document_Builder::test_rendered_source_uses_loopback_body` | A+M |
| Loopback: la petición de renderizado nunca recibe Markdown ni genera (cabecera, parámetro, portada estática) | `Test_Delivery::test_render_request_never_gets_markdown_nor_generates`, `test_render_request_on_static_front_page_resolves_front_page` | A+M |
| Loopback: argumentos filtrables (`wpasl_render_request_args`) | `Test_Rendered_Page::test_args_filter_changes_timeout` | A |
| Loopback: redirección al mismo host (seguida como máximo dos veces; tres encadenadas fallan) | `Test_Rendered_Page::test_same_host_redirect_is_followed_at_most_twice` | A |
| Loopback: redirección a otro host (no se contacta, `redirect_external_host`, documento con el editor) | `Test_Rendered_Page::test_external_redirect_fails_without_contacting_host`, `Test_Document_Builder::test_loopback_failure_falls_back_to_editor_and_reports` | A |
| Loopback: bloqueado o fuera de tiempo (error de conexión, timeout, 403/500/503 → editor, motivo registrado, sin fallo de conversión) | `Test_Rendered_Page::test_http_errors_and_wp_error_fail_with_reason`, `test_non_html_content_type_fails`, `Test_Document_Builder::test_loopback_failure_falls_back_to_editor_and_reports`, `Test_Runner::test_render_failure_is_recorded_without_counting_as_conversion_failure` | A+M |
| Loopback: HTML sin región de contenido (`no_content`) | `Test_Document_Builder::test_no_content_region_falls_back`, `Test_Content_Extractor::test_no_content_is_reported` | A |
| Loopback: generación bajo demanda con loopback (en la petición; documento con el editor si falla) | `Test_Delivery::test_lazy_fill_of_rendered_item_does_loopback_in_request`, `test_lazy_fill_serves_editor_when_loopback_fails`, `test_document_is_identical_from_cron_and_from_singular_request` | A+M |
| Extracción: página de Elementor (widgets, sin cabecera, pie ni menú, H1 una sola vez) | `Test_Content_Extractor::test_elementor_page_keeps_widgets_and_drops_chrome`, `test_duplicate_h1_is_removed_once`, `Test_Document_Builder::test_rendered_source_uses_loopback_body` | A+M |
| Extracción: tema clásico (`article`, no `aside`) | `Test_Content_Extractor::test_classic_theme_uses_article_not_aside` | A |
| Extracción: sin región reconocible (`body` sin `nav`) | `Test_Content_Extractor::test_without_region_falls_back_to_body_without_nav` | A |
| Extracción: elementos eliminados (script, style, form, button, hidden, aria-hidden, screen-reader-text) | `Test_Content_Extractor::test_removed_elements_do_not_leak` | A |
| Extracción: selector configurado (gana; sin coincidencias sigue la detección automática; filtro `wpasl_content_selector`) | `Test_Content_Extractor::test_configured_selector_wins_and_falls_back_when_missing` | A |
| Extracción: selector inválido (`div:has(p)`, `a::before` rechazados; `div.entry-content, main article` aceptado) | `Test_Content_Extractor::test_invalid_selectors_are_rejected`, `test_selector_to_xpath`, `Test_Settings::test_content_source_and_selector_defaults_and_sanitization` | A |
| Extracción: lista de selectores filtrable (`wpasl_rendered_content_selectors`, `wpasl_rendered_remove_selectors`) | `Test_Content_Extractor::test_selector_list_filter` | A |

## scheduled-generation

| Requirement / scenario | Test | |
| --- | --- | --- |
| Programación al activar | `Test_Lifecycle::test_activation_registers_defaults_storage_and_cron`, `test_activation_is_idempotent_for_cron` | A |
| Cambio de intervalo | `Test_Scheduler::test_changing_the_interval_reschedules_without_duplicates`, `test_schedule_uses_configured_interval` | A |
| Desactivación | `Test_Lifecycle::test_deactivation_removes_cron` | A |
| Sitio con más ítems que el tamaño del lote | `Test_Runner::test_120_items_with_batch_50_take_three_runs` | A |
| Presupuesto de tiempo agotado | `Test_Runner::test_time_budget_stops_the_run_and_the_next_run_continues` | A |
| Loopback acotado por el presupuesto (deadline por acción, timeout = tiempo restante) | `Test_Rendered_Page::test_deadline_caps_timeout_and_defers_below_minimum`, `Test_Runner::test_run_started_and_finished_actions_carry_deadline` | A |
| Ítem diferido al final del presupuesto (vuelve al frente, sin fallos, la ejecución termina) | `Test_Runner::test_deferred_item_returns_to_front_and_run_ends`, `Test_Rendered_Page::test_deadline_caps_timeout_and_defers_below_minimum` | A |
| Reintento de loopbacks fallidos (tras los nunca generados) | `Test_Runner::test_render_failed_items_go_first_after_fresh_ones` | A |
| Alta a mitad de ciclo | `Test_Runner::test_post_published_mid_cycle_is_processed_in_next_run` | A |
| Ciclo completo | `Test_Runner::test_120_items_with_batch_50_take_three_runs` (artifacts on the first run and after the last batch), `Test_Llms_Txt::test_builder_is_registered_as_artifact_generator`, `Test_Agent_Manifest::test_builder_is_an_artifact_generator_and_respects_the_toggle` | A |
| Ciclo largo en un sitio grande | `Test_Runner::test_artifacts_regenerate_when_cycle_is_older_than_interval` | A |
| Entrada enviada a papelera | `Test_Runner::test_trashing_a_post_deletes_its_document_immediately_without_generating`, `test_deleting_a_post_deletes_its_document` | A |
| Post type deshabilitado | `Test_Runner::test_prune_removes_documents_of_disabled_post_type` | A |
| Post type añadido (reinicio de la cola) | `Test_Runner::test_changing_post_types_resets_queue`, `Test_State::test_clear_queue_keeps_generated` | A |
| Publicar una entrada nueva | `Test_Runner::test_publishing_or_updating_never_generates_synchronously` | A |
| Edición de una entrada publicada (invalidación inmediata, regenerado en la siguiente petición o ciclo) | `Test_Runner::test_updating_a_published_post_invalidates_the_stored_document_immediately`, `Test_Delivery::test_editing_refreshes_the_served_document` | A |
| Guardado sin cambios de contenido (también invalida) | `Test_Runner::test_resaving_a_published_post_without_changes_still_invalidates` | A |
| Administrador pulsa Regenerar ahora | `Test_Generation_Status::test_handler_schedules_and_redirects_without_generating`, `Test_Scheduler::test_run_soon_schedules_single_event_and_keeps_recurring_timestamp`, `test_run_soon_does_not_duplicate_a_due_event` | A |
| Botón de la pestaña General (formulario de primer nivel) | `Test_Settings::test_general_tab_renders_status_form_outside_settings_form` + Docker smoke test (authenticated POST to `admin-post.php`, 302 and one-off event) | A+M |
| Horario recurrente intacto | `Test_Scheduler::test_run_soon_schedules_single_event_and_keeps_recurring_timestamp`, `test_deactivation_clears_manual_events`, `Test_Runner::test_manual_event_argument_does_not_limit_the_run` | A |
| Petición sin nonce válido | `Test_Generation_Status::test_handler_requires_nonce`, `test_handler_requires_capability` | A |
| WP-Cron desactivado | `Test_Generation_Status::test_render_shows_status_and_cron_warning`, `test_render_without_warning_when_cron_enabled` | A |
| Ítems con fallos (estado visible) | `Test_Generation_Status::test_status_shows_failed_items`, `Test_CLI::test_status_command_lists_failed_items` | A |
| Ítems con fallo de renderizado (panel: recuento, motivo y fecha, aviso hacia Diagnóstico; "0" y sin aviso) | `Test_Generation_Status::test_render_failures_row_and_notice` | A+M |
| Estado con fallos de renderizado (WP-CLI: `render_failed`, `last_render_error`) | `Test_CLI::test_status_shows_render_failures` | A+M |
| Origen de un ítem (WP-CLI `source <id>`: `rendered` / `builder:elementor`; error con id inexistente o no elegible) | `Test_CLI::test_source_command_reports_resolution_and_errors` | A+M |
| Origen o selector cambiados (la cola se descarta, las marcas se conservan; guardar sin cambios no descarta) | `Test_Scheduler::test_changing_content_source_or_selector_clears_queue_and_keeps_generated`, `test_saving_general_without_changes_keeps_queue` | A |
| Fallo de loopback registrado (503: documento con el editor, generado, sin fallo de conversión, contador, acción y error log) | `Test_Runner::test_render_failure_is_recorded_without_counting_as_conversion_failure`, `Test_State::test_render_failures_are_recorded_cleared_and_merged` | A+M |
| Fallo de loopback resuelto (el contador desaparece, `source: rendered`) | `Test_Runner::test_render_success_clears_counter` | A+M |
| Fallo de loopback bajo demanda durante una ejecución (marca y fallo conservados al guardar) | `Test_Runner::test_on_demand_render_failure_during_run_is_kept_in_saved_state`, `Test_State::test_render_failures_are_recorded_cleared_and_merged`, `Test_Delivery::test_lazy_fill_serves_editor_when_loopback_fails` | A |
| Fallos persistentes (en el umbral no se antepone; contadores de ítems no elegibles eliminados al completar el ciclo) | `Test_Runner::test_render_failed_items_at_threshold_keep_normal_order`, `test_completed_cycle_prunes_render_failed_of_ineligible_items` | A |
| Fallo persistente de escritura | `Test_Runner::test_failed_items_are_counted_and_deprioritized`, `Test_Storage::test_storage_write_failure_is_logged` | A |
| Fallo resuelto | `Test_Runner::test_successful_generation_clears_failure_counter` | A |
| Acción y registro del fallo | `Test_Runner::test_failed_items_are_counted_and_deprioritized` (`wpasl_generation_failed` and the PHP error log) | A |
| Generación completa por WP-CLI | `Test_CLI::test_generate_all_processes_every_item_and_reports_the_total`, `test_generate_post_type_and_batch` | A+M |
| Vaciar almacenamiento | `Test_CLI::test_status_and_clear`, `Test_Runner::test_clear_empties_storage_and_state` | A+M |
| Post type no habilitado (WP-CLI termina con error) | `Test_CLI::test_cli_generate_errors_for_disabled_post_type`, `test_cli_generate_errors_when_the_converter_is_missing` | A |
| Post type con lote | `Test_CLI::test_generate_post_type_with_batch_only_processes_that_type` | A |
| Post type sin ítems elegibles | `Test_CLI::test_generate_post_type_without_eligible_items_processes_nothing` | A |
| Regeneración completa de un post type | `Test_CLI::test_generate_all_with_post_type_keeps_other_types_generated`, `Test_Runner::test_reset_cycle_without_types_forgets_everything` | A |
| Generación bajo demanda durante una ejecución (estado concurrente) | `Test_State::test_lazy_fill_mark_survives_runner_save`, `test_newest_timestamp_wins_on_merge`, `test_prune_removal_is_not_resurrected_by_merge`, `test_forget_removes_from_queue_and_generated`, `test_removed_key_is_never_persisted`, `test_reset_cycle_replaces_instead_of_merging` | A |
| Despublicación de un post type no habilitado (sin escritura del estado) | `Test_Runner::test_trashing_non_enabled_post_type_does_not_write_state` | A |
| Construcción de llms-full.txt dentro de una ejecución (una sola escritura) | `Test_Runner::test_run_with_full_llms_writes_state_once` | A |
| Estado con muchos ítems (cota de consultas) | `Test_Eligibility::test_eligible_ids_query_count_is_bounded`, `test_eligible_ids_with_filter_primes_caches_in_batches`, `test_count_matches_eligible_ids`, `Test_Generation_Status::test_status_uses_count_query` | A |
| Acceso directo al archivo | `Test_Storage::test_htaccess_denies_access` (rules) + Docker smoke test on Apache (HTTP 403) | A+M |
| Subdirectorios protegidos (`web.config`, `index.php`) | `Test_Storage::test_write_creates_index_in_new_subdirectories`, `test_ensure_writes_web_config` | A |
| Temporal huérfano | `Test_Runner::test_prune_removes_stale_tmp_files` | A |
| Multisitio | `Test_Multisite::test_each_site_has_its_own_storage_and_documents`, `test_network_activation_sets_up_every_site`, `test_uninstall_removes_storage_and_options_on_every_site` (run with `tests/multisite.xml.dist`) | A |

## content-signals

| Requirement / scenario | Test | |
| --- | --- | --- |
| Valores por defecto | `Test_Content_Signals::test_default_values`, `Test_Settings::test_defaults` | A |
| robots.txt con señales por defecto | `Test_Content_Signals::test_robots_txt_gets_directive_inside_the_wildcard_group` | A+M |
| Cambio de señal | `Test_Content_Signals::test_robots_txt_via_core_filter_reflects_changes` | A |
| Cabecera en una entrada pública | `Test_Content_Signals::test_headers_by_default`, `test_hooks_are_wired` | A+M |
| Cabecera experimental desactivada | `Test_Content_Signals::test_headers_without_experimental_header` | A |
| Área de administración | `Test_Content_Signals::test_nothing_in_admin` | A |
| Documento OpenAPI por REST | `Test_Agent_Manifest::test_openapi_rest_route` | A |
| Descubrimiento del catálogo de API (`Link rel="api-catalog"`) | `Test_Content_Signals::test_html_and_markdown_announce_api_catalog_link`, `test_no_api_catalog_link_when_manifest_disabled`, `Test_Delivery::test_html_link_header_does_not_replace_existing_link`, `test_markdown_response_keeps_api_catalog_link` | A |
| Descubrimiento del catálogo de API en Markdown | `Test_Delivery::test_markdown_response_keeps_api_catalog_link` (full delivery through `Delivery::serve()`, catalog announced exactly once) | A |
| Catálogo no anunciado en Markdown con el manifiesto deshabilitado | `Test_Delivery::test_markdown_response_has_no_api_catalog_link_when_manifest_disabled` | A |
| Entrenamiento no permitido | `Test_Content_Signals::test_headers_by_default`, `test_meta_robots_gets_noai_and_keeps_existing_directives` | A+M |
| Entrenamiento permitido | `Test_Content_Signals::test_headers_when_training_allowed`, `test_meta_robots_untouched_when_training_allowed` | A |
| Convivencia con directivas existentes | `Test_Content_Signals::test_meta_robots_gets_noai_and_keeps_existing_directives` | A |
| Respuestas no HTML (robots.txt, feeds, sitemaps sin `noai`) | `Test_Content_Signals::test_robots_feed_and_sitemap_have_no_noai_header` | A |

## ai-crawler-robots

| Requirement / scenario | Test | |
| --- | --- | --- |
| Catálogo ampliado por filtro | `Test_Crawler_Robots::test_catalog_filter_adds_an_agent`, `test_catalog_contains_the_required_agents_with_groups` | A |
| Token con caracteres no admitidos | `Test_Crawler_Robots::test_catalog_rejects_tokens_with_dots_and_brackets` | A |
| Defaults con señales por defecto | `Test_Crawler_Robots::test_default_policies_follow_the_signals`, `test_signals_change_the_defaults` | A |
| Sobrescritura individual | `Test_Crawler_Robots::test_individual_override_is_respected`, `test_sanitizer_drops_default_and_keeps_valid_overrides` | A |
| Crawler bloqueado | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups` | A+M |
| Crawler permitido | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups` | A+M |
| Reglas del núcleo intactas | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups`, `test_generated_output_equals_do_robots_when_public` | A |
| Puntero a auth.md | `Test_Auth_Md::test_capability_catalog_and_robots_announce_auth_md`, `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups` | A+M |
| Sin puntero con auth.md desactivado | `Test_Auth_Md::test_nothing_announces_auth_md_when_disabled` | A |
| Archivo físico presente | `Test_Crawler_Robots::test_physical_file_detection` | A |
| Bloque idéntico al robots.txt virtual | `Test_Crawler_Robots::test_generated_output_equals_do_robots_when_public` | A |
| Sitio no visible para motores de búsqueda | `Test_Crawler_Robots::test_generated_output_equals_do_robots_when_not_public` | A |

## llms-txt

| Requirement / scenario | Test | |
| --- | --- | --- |
| Petición a llms.txt | `Test_Llms_Txt::test_route_serves_llms_txt_with_lazy_generation` | A+M |
| Documento ausente | `Test_Llms_Txt::test_route_serves_llms_txt_with_lazy_generation`, `test_route_serves_stored_file_without_rebuilding` | A |
| Petición a llms.txt sin arrastrar llms-full.txt | `Test_Llms_Txt::test_llms_txt_request_does_not_build_llms_full` | A |
| Ruta no canónica (`/llms.txt/`, `//llms.txt`) | `Test_Llms_Txt::test_non_canonical_root_paths_are_not_served` | A |
| Documento con post y page | `Test_Llms_Txt::test_structure_with_pages_and_posts`, `test_blockquote_falls_back_when_the_tagline_is_empty`, `test_pages_are_ordered_by_menu_order_then_title` | A |
| Guía "cuándo usar este sitio" (`## When to use this site` tras la introducción; ausente cuando está vacía) | `Test_Llms_Txt::test_when_to_use_section` | A |
| Vista previa con enlace a la lista completa (10 de 25 + `Full list of Posts (25 items)`) | `Test_Llms_Txt::test_preview_and_full_list_line` | A |
| Tipo con menos ítems que la vista previa (3 páginas, sin línea "Full list") | `Test_Llms_Txt::test_preview_and_full_list_line` | A |
| Descripción personalizada | `Test_Llms_Txt::test_custom_description_and_intro` | A |
| Sitemap con enlaces permanentes simples | `Test_Llms_Txt::test_optional_sitemap_link_uses_query_form_with_plain_permalinks` | A |
| Sitemap de un plugin SEO (línea `Sitemap:` de robots.txt o filtro `wpasl_sitemap_url`; clases verificadas en el código de Yoast, Rank Math, AIOSEO y SEOPress) | `Test_Llms_Txt::test_optional_sitemap_from_seo_plugin_or_filter` | A |
| Sitemap indeterminable (sin enlace) | `Test_Llms_Txt::test_optional_omits_sitemap_when_unknown` | A |
| Sin enlaces opcionales | `Test_Llms_Txt::test_optional_section_is_omitted_without_links` | A |
| Más ítems que el límite (vista previa de 10 en llms.txt, las 150 en `/llms-post.txt`) | `Test_Llms_Txt::test_type_file_lists_all_items_and_truncates`, `test_limit_keeps_the_most_recent_posts` | A |
| Más ítems que el límite por tipo (20 de 30 con nota de truncado y `(20 items)` en llms.txt) | `Test_Llms_Txt::test_type_file_lists_all_items_and_truncates` | A |
| Entrada excluida (ni en llms.txt ni en el archivo por tipo) | `Test_Llms_Txt::test_excluded_and_non_eligible_items_are_not_listed` | A |
| Post type jerárquico personalizado (orden por fecha) | `Test_Llms_Txt::test_hierarchical_cpt_is_ordered_by_date` | A |
| llms-full habilitado (construido por la ejecución programada) | `Test_Llms_Txt::test_llms_full_enabled_concatenates_documents` | A |
| llms-full concatena las listas completas (vista previa 2, 5 documentos) | `Test_Llms_Txt::test_llms_full_concatenates_full_lists` | A |
| llms-full habilitado pero ausente (503, `Retry-After`, evento único) | `Test_Llms_Txt::test_missing_llms_full_returns_503_and_schedules_build`, `test_llms_full_build_event_is_not_duplicated`, `Test_Lifecycle::test_uninstall_clears_manual_event` | A |
| llms-full deshabilitado | `Test_Llms_Txt::test_llms_full_disabled_is_404` | A+M |
| Límite de tamaño | `Test_Llms_Txt::test_llms_full_truncates_at_the_last_complete_item` | A |
| (physical file precedence, settings invalidation) | `Test_Llms_Txt::test_physical_file_takes_precedence`, `test_settings_change_invalidates_stored_files` | A |
| Archivo por tipo: petición (200, cabeceras de llms.txt, `Content-Signal`, H1 con el nombre del sitio y `Posts`) | `Test_Llms_Txt::test_type_file_route_headers_and_lazy_generation` | A |
| Archivo por tipo: documento ausente (solo ese archivo se genera; sin regenerar después) | `Test_Llms_Txt::test_type_file_route_headers_and_lazy_generation` | A |
| Archivo por tipo: tipo no habilitado o inexistente (`/llms-attachment.txt`, `/llms-nada.txt`, tipo deshabilitado) | `Test_Llms_Txt::test_type_file_unknown_or_disabled_type_is_404` | A |
| Archivo por tipo: ruta no canónica (`/llms-post.txt/`, `//llms-post.txt`) | `Test_Llms_Txt::test_type_file_non_canonical_paths` | A |
| Archivo por tipo: archivo físico presente (segundo argumento de `wpasl_physical_llms_path`) | `Test_Llms_Txt::test_type_file_physical_precedence` | A |
| Post type llamado full (sin archivo por tipo, sin línea "Full list", `/llms-full.txt` intacto) | `Test_Llms_Txt::test_reserved_type_full` | A |
| Archivo por tipo: regeneración e invalidación (ciclo escribe, guardar ajustes borra) | `Test_Llms_Txt::test_invalidation_and_regeneration_remove_stale_type_files` | A |
| Archivo por tipo: tipo deshabilitado (desaparece en el siguiente ciclo y responde 404) | `Test_Llms_Txt::test_invalidation_and_regeneration_remove_stale_type_files` | A |
| Toda URL emitida se sirve (llms.txt, cada archivo por tipo y cada `/llms-<tipo>.txt` enlazado) | `Test_Llms_Txt::test_every_emitted_markdown_url_is_servable` | A |

## agent-manifest

| Requirement / scenario | Test | |
| --- | --- | --- |
| Manifiesto por defecto | `Test_Agent_Manifest::test_agent_skills_document`, `test_agent_skills_route` | A+M |
| Manifiesto deshabilitado | `Test_Agent_Manifest::test_routes_are_404_when_disabled` | A |
| Plantilla de lectura en Markdown (`{+path}`) | `Test_Agent_Manifest::test_read_markdown_template_uses_reserved_expansion` | A |
| Ruta no canónica del manifiesto y del catálogo | `Test_Agent_Manifest::test_non_canonical_root_paths_are_not_served` | A |
| Post type habilitado y expuesto en REST | `Test_Agent_Manifest::test_default_capabilities_cover_rest_markdown_index_and_openapi` | A |
| Post type habilitado pero sin REST | `Test_Agent_Manifest::test_post_type_without_rest_gets_no_rest_capabilities` | A |
| Capacidad añadida por filtro | `Test_Agent_Manifest::test_filter_adds_a_capability_and_authenticated_ones_are_dropped` | A |
| Documento válido (`openapi`, `info`, `servers`, `paths`, `components.schemas.Error`) | `Test_Agent_Manifest::test_openapi_document`, `test_openapi_rest_route`, `test_openapi_error_schema_and_responses` | A+M |
| Solo métodos GET | `Test_Agent_Manifest::test_openapi_document` | A |
| Enlaces permanentes simples (`servers` sin query string) | `Test_Agent_Manifest::test_openapi_servers_url_has_no_query_string_with_plain_permalinks` | A |
| Modelo de error tipado (`components.schemas.Error`, respuestas `400`, `404` y `default` con `$ref`) | `Test_Agent_Manifest::test_openapi_error_schema_and_responses` | A |
| Sin RFC 9457 (`application/problem+json` ausente) | `Test_Agent_Manifest::test_openapi_has_no_problem_json`, `test_openapi_description_states_versioning_policy` | A |
| Política de versionado en la descripción (`wp/v2`, `wpasl/v1`, sin `Deprecation`/`Sunset`, changelog; también con enlaces simples) | `Test_Agent_Manifest::test_openapi_description_states_versioning_policy` | A |
| Catálogo disponible (`profile` RFC 9727, `linkset[0].item`, `linkset[1].service-desc`) | `Test_Agent_Manifest::test_api_catalog_route` | A+M |
| Ruta no canónica del catálogo (`/.well-known/api-catalog/`) | `Test_Agent_Manifest::test_non_canonical_root_paths_are_not_served` | A |
| Enlaces permanentes simples del catálogo (`item[0].href` y `linkset[1].anchor` en la forma `?rest_route=`) | `Test_Agent_Manifest::test_api_catalog_with_plain_permalinks` | A |
| Cambio de correo de contacto | `Test_Agent_Manifest::test_contact_email_change_is_reflected_on_next_request` | A |
| auth.md declarado como capacidad | `Test_Auth_Md::test_capability_catalog_and_robots_announce_auth_md`, `Test_Agent_Manifest::test_default_capabilities_cover_rest_markdown_index_and_openapi` | A |
| auth.md desactivado no se declara | `Test_Auth_Md::test_nothing_announces_auth_md_when_disabled` | A |
| auth.md en el catálogo (`linkset[1].service-doc`; `linkset[0]` sin `service-desc` ni `service-doc`) | `Test_Auth_Md::test_capability_catalog_and_robots_announce_auth_md`, `Test_Agent_Manifest::test_api_catalog_route` | A+M |
| auth.md desactivado fuera del catálogo | `Test_Auth_Md::test_nothing_announces_auth_md_when_disabled`, `Test_Agent_Manifest::test_api_catalog_route` | A |
| Petición a auth.md (cabeceras, `Content-Signal`, sin `X-Robots-Tag`) | `Test_Auth_Md::test_route_serves_auth_md_with_lazy_generation` | A+M |
| auth.md: documento ausente (generación bajo demanda, sin regenerar después) | `Test_Auth_Md::test_route_serves_auth_md_with_lazy_generation` | A |
| auth.md: publicación desactivada | `Test_Auth_Md::test_route_is_404_when_disabled` | A |
| auth.md: manifiestos deshabilitados | `Test_Auth_Md::test_route_is_404_when_manifests_disabled` | A |
| auth.md: archivo físico presente (y aviso en la pestaña) | `Test_Auth_Md::test_physical_file_takes_precedence` | A |
| auth.md: ruta no canónica (`/auth.md/`, `//auth.md`) | `Test_Auth_Md::test_non_canonical_root_paths_are_not_served` | A |
| Contenido con slug auth y archivo raíz | `Test_Auth_Md::test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg` | A |
| Contenido con slug auth con la publicación desactivada | `Test_Auth_Md::test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg` | A |
| auth.md: estructura por defecto (incluida `## API versioning and deprecation`) | `Test_Auth_Md::test_document_structure`, `test_versioning_section` | A+M |
| auth.md: guía "cuándo usar este sitio" (entre `## Audience` y `## Registration and credential provisioning`; ausente cuando está vacía) | `Test_Auth_Md::test_when_to_use_section_present_and_absent` | A |
| auth.md: política de versionado y deprecación (espacios de nombres reales, sin `Deprecation`/`Sunset`, changelog, sin promesas) | `Test_Auth_Md::test_versioning_section` | A |
| auth.md: correo de contacto | `Test_Auth_Md::test_contact_email_falls_back_to_admin_email` | A |
| auth.md: notas del administrador | `Test_Auth_Md::test_admin_notes_section` | A |
| auth.md: sin credenciales ni autorización de terceros | `Test_Auth_Md::test_document_never_mentions_oauth_or_application_passwords` | A+M |
| auth.md: enlaces permanentes simples | `Test_Auth_Md::test_plain_permalinks_describe_query_arg_and_rest_route` | A |
| auth.md: filtro sobre el documento | `Test_Auth_Md::test_filter_changes_the_document` | A |
| Cambio de notas de auth.md (invalidación) | `Test_Auth_Md::test_settings_change_invalidates_stored_file` | A |
| Regeneración de auth.md al final del ciclo (y borrado con la publicación desactivada) | `Test_Auth_Md::test_builder_is_registered_as_artifact_generator_and_respects_the_toggle` | A |
| Enlaces de descubrimiento en la portada (`service-desc`, `api-catalog`, `describedby`, `service-doc`) | `Test_Discovery_Links::test_head_links_on_front_page` | A |
| Enlaces de descubrimiento en una página interior (junto al `alternate` de la entrada) | `Test_Discovery_Links::test_head_links_on_singular_post_alongside_alternate` | A |
| Enlaces de descubrimiento con auth.md desactivado (sin `service-doc`, los otros tres presentes) | `Test_Discovery_Links::test_no_auth_md_link_when_unpublished` | A |
| Enlaces de descubrimiento con los manifiestos deshabilitados (head sin `link`, shortcode vacío) | `Test_Discovery_Links::test_nothing_when_manifests_disabled`, `test_links_are_never_printed_in_the_admin` | A |
| Shortcode `[wpasl_agent_links]` (`ul.wpasl-agent-links`, cuatro `href` escapados) | `Test_Discovery_Links::test_shortcode_renders_list` | A |
| Filtro compartido `wpasl_discovery_links` (head y shortcode) | `Test_Discovery_Links::test_links_filter` | A |

## agent-diagnostics

| Requirement / scenario | Test | |
| --- | --- | --- |
| Ejecución completa (resultado por crawler para `.md` y robots.txt, y la URL de renderizado como comprobación de sitio) | `Test_Diagnostics::test_probe_uses_crawler_user_agents_and_accept_headers`, `test_report_contains_markdown_url_check_per_crawler`, `test_probe_only_contacts_its_own_host`, `test_probe_does_not_follow_redirects`, `test_render_target_is_probed_with_marker_and_html_accept` | A+M |
| Sin permisos | `Test_Diagnostics::test_handler_requires_capability_and_nonce`, `test_handler_runs_and_redirects` | A |
| Prueba dividida en lotes | `Test_Diagnostics::test_diagnostics_splits_into_batches_and_resumes`, `test_diagnostics_step_requires_capability_and_nonce`, `test_tab_shows_in_progress_notice` | A+M |
| Peticiones lentas (cota por petición del administrador) | `Test_Diagnostics::test_diagnostics_request_duration_is_bounded` + Docker smoke test (`docs/evidence/2026-09-02-smoke-1.0.2.md`, `docs/evidence/2026-09-02-smoke-1.0.3.md`) | A+M |
| Redirección en una URL sondeada | `Test_Diagnostics::test_report_flags_redirect_as_warning` | A |
| Bloqueo por user-agent en la URL .md | `Test_Diagnostics::test_ua_specific_block_on_md_is_reported_only_for_that_crawler` | A |
| Crawler bloqueado en robots.txt (veredicto del cuerpo servido) | `Test_Diagnostics::test_report_blocked_crawler_is_coherent_and_healthy_site_is_ok`, `test_robots_verdict_matches_served_body` | A+M |
| robots.txt servido sin el grupo del crawler | `Test_Diagnostics::test_robots_verdict_warns_when_group_missing`, `test_robots_verdict_warns_when_body_differs_from_policy` | A |
| Markdown no servido | `Test_Diagnostics::test_report_flags_html_returned_for_markdown_request`, `test_report_flags_waf_block_and_missing_headers` | A |
| Informe de una versión anterior | `Test_Diagnostics::test_tab_renders_report_from_previous_version_without_notices` | A |
| Cloudflare detectado | `Test_Diagnostics::test_report_detects_cloudflare_and_edge_markdown` | A |
| Cache Enabler detectado por la cabecera X-Cache-Handler | `Test_Diagnostics::test_report_detects_cache_enabler_from_x_cache_handler` | A |
| Cache Enabler activo sin cabecera X-Cache-Handler | `Test_Diagnostics::test_report_uses_local_detection_without_x_cache_handler` | A |
| Otra caché de página (valor tal cual, textos genéricos) | `Test_Diagnostics::test_report_shows_other_page_cache_handler_verbatim` | A |
| Sin caché de página (textos genéricos) | `Test_Diagnostics::test_report_without_page_cache_keeps_generic_messages`, `test_report_flags_waf_block_and_missing_headers` | A |
| Informe anterior sin hallazgo de caché de página | `Test_Diagnostics::test_tab_renders_report_from_previous_version_without_notices` | A |
| Aviso con Cache Enabler activo (texto, cabeceras perdidas, `Accept`, no afectados, servidor web o CDN, sin afirmar que el plugin nunca escribe `.htaccess`) | `Test_Diagnostics::test_tab_shows_cache_enabler_notice_and_snippets` | A |
| Fragmentos con la configuración por defecto (`.htaccess` y nginx, condicionados a HTML, URL real del sitio, marcador `X-WPASL-Headers` en el bloque de `.htaccess`) | `Test_Diagnostics::test_tab_shows_cache_enabler_notice_and_snippets` | A |
| Fragmento para OpenLiteSpeed sin X-Robots-Tag (ni marcador) | `Test_Diagnostics::test_openlitespeed_snippet_omits_x_robots_tag` | A |
| Fragmentos con entrenamiento permitido y sin manifiesto (marcador presente pese a ello) | `Test_Diagnostics::test_snippets_follow_settings` | A |
| Marcador nunca enviado desde PHP (página HTML, `.md`, `robots.txt`, `llms.txt`) | `Test_Diagnostics::test_marker_header_is_never_sent_by_php` | A |
| `fetch()` conserva la cabecera del marcador | `Test_Diagnostics::test_probe_fetch_keeps_the_marker_header` | A |
| Solo fragmentos cuando la aplicación automática no está disponible | `Test_Diagnostics::test_tab_hides_htaccess_apply_control_when_unavailable` | A |
| Entorno compatible y archivo escribible (detección Apache/LiteSpeed vía `SERVER_SOFTWARE`, filtro de sustitución) | `Test_Diagnostics::test_htaccess_environment_detection` | A |
| Disponibilidad (`available()`: Cache Enabler, entorno, escribibilidad, multisitio) | `Test_Diagnostics::test_htaccess_availability` | A |
| Estado del bloque (no aplicado, al día, con valores distintos) | `Test_Diagnostics::test_htaccess_status` | A |
| Copia de seguridad y restauración byte a byte, solo la copia más reciente | `Test_Diagnostics::test_htaccess_backup_and_restore` | A |
| Forma de la petición de verificación (host propio, `Accept: text/html`, sin redirección, parámetro `wpasl_verify`) | `Test_Diagnostics::test_htaccess_verify_request_shape` | A |
| Resultados de la verificación (éxito, sin marcador, 500, error de conexión, redirección) | `Test_Diagnostics::test_htaccess_verify_outcomes` | A |
| Aplicación correcta (copia, bloque entre marcadores, una sola petición, estado al día) | `Test_Diagnostics::test_htaccess_apply_success` | A |
| Bloque ya presente con valores anteriores (reemplazo en su sitio, líneas ajenas intactas) | `Test_Diagnostics::test_htaccess_apply_replaces_existing_block_in_place` | A |
| Verificación con error, tiempo agotado o código distinto de 200 (restauración automática) | `Test_Diagnostics::test_htaccess_apply_rolls_back_on_verification_failure` | A |
| Archivo inexistente restaurado (se elimina si la verificación falla) | `Test_Diagnostics::test_htaccess_apply_removes_created_file_when_it_did_not_exist` | A |
| Copia de seguridad imposible (aborta sin escribir ni verificar) | `Test_Diagnostics::test_htaccess_apply_aborts_when_backup_fails` | A |
| Multisitio (sin botón, `apply()` aborta con `step => 'environment'`) | `Test_Diagnostics::test_htaccess_apply_aborts_on_multisite`, `test_htaccess_availability` | A |
| Sin permisos o sin nonce | `Test_Diagnostics::test_htaccess_handler_requires_capability_and_nonce` | A |
| Acción completa con redirección y transient de resultado | `Test_Diagnostics::test_htaccess_handler_applies_and_redirects` | A |
| Servicio cableado (`Plugin::get('htaccess_headers')`, `admin_post_wpasl_apply_htaccess`) | `Test_Diagnostics::test_htaccess_headers_service_is_wired` | A |
| Comprobación de capacidad y botón en la pestaña (un solo formulario, sin anidar) | `Test_Diagnostics::test_tab_shows_htaccess_apply_control_when_available` | A |
| Avisos de resultado (éxito, fallo restaurado, fallo con restauración fallida) | `Test_Diagnostics::test_tab_shows_htaccess_result_notices` | A |
| Ninguna escritura fuera de la acción (activación, ajustes, generación, simulación, renderizado de la pestaña) | `Test_Diagnostics::test_htaccess_is_never_written_outside_the_action` | A |
| Ajustes cambiados tras aplicar (queda «con valores distintos» sin reescribir) | `Test_Diagnostics::test_htaccess_block_goes_stale_without_rewriting` | A |
| Detección de entorno y ruta sustituidas por filtro (`wpasl_htaccess_environment`, `wpasl_htaccess_file`) | `Test_Diagnostics::test_htaccess_environment_detection`, `test_htaccess_status` (todos los tests de `HtaccessHeaders` usan `wpasl_htaccess_file`) | A |
| Recordatorio de Cloudflare (con y sin informe Cloudflare) | `Test_Diagnostics::test_tab_shows_cache_enabler_notice_and_snippets`, `test_snippets_follow_settings` | A |
| Sin Cache Enabler (sin aviso ni fragmentos) | `Test_Diagnostics::test_tab_hides_cache_notice_without_page_cache`, `test_report_detects_cache_enabler_from_x_cache_handler` (report without local notice) | A |
| Detección local sustituida por filtro (`wpasl_diagnostics_page_cache`) | `Test_Diagnostics::test_page_cache_detection_is_null_here_and_replaceable_by_filter`, `test_report_uses_local_detection_without_x_cache_handler`, `test_tab_shows_cache_enabler_notice_and_snippets` | A |
| Aviso en la pestaña Señales (con enlace a Diagnóstico; Manifiestos sin cambios) | `Test_Content_Signals::test_signals_tab_warns_about_cache_enabler`, `test_api_catalog_link_matches_the_header_sent` | A |
| Almacenamiento expuesto | `Test_Diagnostics::test_report_flags_exposed_storage` | A+M |
| Almacenamiento sin documentos generados (archivo sonda) | `Test_Diagnostics::test_storage_probe_runs_without_generated_documents` | A |
| Comandos disponibles | `Test_Diagnostics::test_tab_renders_checklist_and_curl_commands`, `test_tab_curl_textarea_has_no_leading_whitespace` | A |
| Comando con comilla en el user-agent (citado para la shell) | `Test_Diagnostics::test_curl_commands_are_shell_safe` | A |
| auth.md servido | `Test_Diagnostics::test_report_checks_auth_md`, `test_probe_uses_crawler_user_agents_and_accept_headers` | A+M |
| auth.md ausente | `Test_Diagnostics::test_report_checks_auth_md` | A |
| auth.md con tipo inesperado | `Test_Diagnostics::test_report_checks_auth_md` | A |
| Archivos por tipo comprobados (`llms-page.txt` correcto, `llms-post.txt` con 404 en error, entre `llms.txt` y `auth.md`) | `Test_Diagnostics::test_report_checks_type_files`, `test_probe_uses_crawler_user_agents_and_accept_headers`, `test_report_checks_auth_md` | A |
| llms.txt demasiado grande (82.000 caracteres → advertencia; 20.000 → correcto) | `Test_Diagnostics::test_report_warns_on_large_llms_txt` | A |
| Catálogo con profile (`application/linkset+json; profile="…rfc9727"`) | `Test_Diagnostics::test_report_accepts_catalog_with_profile` | A |
| Sin peticiones de registro (solo `GET` a los objetivos conocidos, incluidos los archivos por tipo y la URL de renderizado) | `Test_Diagnostics::test_probe_only_sends_get_to_known_targets`, `test_probe_only_contacts_its_own_host` | A |
| URL de renderizado correcta (cabecera y parámetro, `Accept: text/html`, selector `main` en el informe) | `Test_Diagnostics::test_render_target_is_probed_with_marker_and_html_accept`, `test_report_render_check_ok_with_region` | A+M |
| URL de renderizado sin región (advertencia: cuerpo entero, configurar el selector) | `Test_Diagnostics::test_report_render_check_warns_without_region` | A |
| URL de renderizado bloqueada (error de conexión, 403, `text/markdown` → error con la consecuencia) | `Test_Diagnostics::test_report_render_check_errors_on_block_markdown_or_connection_error` | A+M |
| Comando para la URL de renderizado (`curl -s -H 'X-WPASL-Render: 1' '<URL>'`) y lista de verificación sobre el loopback | `Test_Diagnostics::test_tab_renders_checklist_and_curl_commands`, `test_curl_commands_are_shell_safe` | A+M |
| Aviso con fallos de renderizado registrados (recuento, motivo, fecha, causas habituales) | `Test_Diagnostics::test_tab_shows_render_failures_notice_and_hides_it_without_failures` | A+M |
| Sin fallos de renderizado registrados (sin aviso) | `Test_Diagnostics::test_tab_shows_render_failures_notice_and_hides_it_without_failures` | A |
| Comando para auth.md y lista de verificación con auth.md y los archivos por tipo | `Test_Diagnostics::test_tab_renders_checklist_and_curl_commands` | A |
| Comandos para los archivos por tipo (`curl -s '<home_url>/llms-page.txt'`, `/llms-post.txt`; solo tipos habilitados) | `Test_Diagnostics::test_tab_renders_checklist_and_curl_commands` | A |

## admin-settings

| Requirement / scenario | Test | |
| --- | --- | --- |
| Acceso con permisos | `Test_Settings::test_page_is_registered_under_tools_for_administrators`, `test_page_renders_general_tab_for_administrators` | A |
| Acceso sin permisos | `Test_Settings::test_page_denies_editors` | A |
| Confirmación al guardar | `Test_Settings::test_page_shows_settings_saved_notice` + Docker smoke test ("Settings saved." after a real POST) | A+M |
| Post types por defecto | `Test_Settings::test_defaults`, `test_page_renders_general_tab_for_administrators` | A |
| Post type no público | `Test_Settings::test_selectable_post_types_exclude_attachment_and_non_public` | A |
| Guardar la pestaña General | `Test_Settings::test_general_tab_renders_status_form_outside_settings_form` + Docker smoke test (POST to `options.php`, value persisted) | A+M |
| Regenerar desde la pestaña General | `Test_Settings::test_general_tab_renders_status_form_outside_settings_form` + Docker smoke test (POST to `admin-post.php`, 302 to the General tab) | A+M |
| Marcado de la pestaña General (formularios no anidados) | `Test_Settings::test_general_tab_renders_status_form_outside_settings_form`, `test_general_tab_after_hook_still_fires_inside_form`, `test_page_after_form_hook_receives_current_tab` | A |
| Marcar exclusión | `Test_Exclude_Meta_Box::test_save_with_valid_nonce_sets_and_clears_meta`, `test_save_without_nonce_is_ignored`, `test_save_by_user_without_permission_is_ignored`, `test_meta_is_exposed_in_rest_for_editors_only` | A |
| Lectura REST anónima | `Test_Exclude_Meta_Box::test_rest_read_hides_exclude_meta_for_anonymous` | A |
| Lectura REST con permiso de edición | `Test_Exclude_Meta_Box::test_rest_read_shows_exclude_meta_for_editor_with_context_edit` | A |
| Post type no habilitado (casilla oculta) | `Test_Exclude_Meta_Box::test_meta_box_is_added_only_for_enabled_post_types`, `test_meta_is_registered_only_for_enabled_post_types` | A |
| Origen por tipo por defecto (`auto` seleccionado, selector vacío) | `Test_Settings::test_defaults`, `test_content_source_and_selector_defaults_and_sanitization`, `test_general_tab_renders_content_source_and_selector` | A+M |
| Guardar origen y selector (`page` → `rendered`, `main article`, idempotente, `foo` → `auto`) | `Test_Settings::test_content_source_and_selector_defaults_and_sanitization`, `test_sanitize_is_idempotent_for_every_field`, `test_general_tab_renders_content_source_and_selector` | A |
| Selector rechazado (`div:has(p)` conserva `main` y registra el aviso) | `Test_Settings::test_content_source_and_selector_defaults_and_sanitization` | A |
| Guardar otra pestaña (Señales conserva origen y selector) | `Test_Settings::test_saving_another_tab_keeps_auth_md_settings` | A |
| Origen por entrada: anular el origen de una entrada (`rendered` almacenado; "Follow the settings" elimina la meta; sin generación) | `Test_Exclude_Meta_Box::test_save_stores_source_override_and_clears_default`, `test_render_shows_select_only_for_enabled_types`, `Test_Content_Source::test_post_override_wins` | A+M |
| Origen por entrada: post type no habilitado (selector no mostrado) | `Test_Exclude_Meta_Box::test_render_shows_select_only_for_enabled_types`, `test_source_meta_is_registered_only_for_enabled_post_types` | A |
| Origen por entrada: lectura REST anónima (sin la clave en `meta`) | `Test_Exclude_Meta_Box::test_source_meta_hidden_for_anonymous_and_writable_by_editor` | A |
| Origen por entrada: escritura REST con permiso de edición (`rendered` almacenado, `foo` saneado a vacío) | `Test_Exclude_Meta_Box::test_source_meta_hidden_for_anonymous_and_writable_by_editor` | A |
| Doble sanitización | `Test_Settings::test_sanitize_is_idempotent_for_every_field` | A |
| auth.md: valores por defecto | `Test_Settings::test_defaults` | A |
| auth.md: guardar la pestaña Manifiestos (casilla, notas sin HTML, 404 en la siguiente petición) | `Test_Settings::test_auth_md_notes_are_truncated_to_4000_chars`, `Test_Auth_Md::test_route_is_404_when_disabled`, `test_settings_change_invalidates_stored_file` | A |
| auth.md: guardar otra pestaña | `Test_Settings::test_saving_another_tab_keeps_auth_md_settings` | A |
| auth.md: notas por encima del límite | `Test_Settings::test_auth_md_notes_are_truncated_to_4000_chars`, `test_sanitize_is_idempotent_for_every_field` | A |
| auth.md: enlaces de la pestaña (URL, casilla, notas) | `Test_Auth_Md::test_manifests_tab_renders_auth_md_fields`, `Test_Agent_Manifest::test_manifests_tab_renders` | A |
| Valor fuera de rango (límite de llms-full.txt) | `Test_Settings::test_llms_full_max_out_of_range_clamps_to_100_mb`, `Test_Llms_Txt::test_tab_renders_and_sanitizes_mb` | A |
| llms.txt: valores por defecto (guía vacía, vista previa 10, límite por tipo 1000, sin `llms_limit`) | `Test_Settings::test_defaults` | A |
| llms.txt: guardar la pestaña llms.txt (guía sin HTML, límites 5 y 2000, regeneración en la siguiente petición) | `Test_Settings::test_llms_when_to_use_is_sanitized_and_truncated`, `test_llms_limits_ranges_and_defaults`, `Test_Llms_Txt::test_settings_change_invalidates_stored_files`, `test_invalidation_and_regeneration_remove_stale_type_files` | A |
| llms.txt: límites fuera de rango (500 → 100, 50000 → 10000; 0 o vacío → por defecto) | `Test_Settings::test_llms_limits_ranges_and_defaults`, `Test_Llms_Txt::test_tab_renders_and_sanitizes_mb` | A |
| llms.txt: guía por encima del límite (5000 → 4000, idempotente) | `Test_Settings::test_llms_when_to_use_is_sanitized_and_truncated`, `test_sanitize_is_idempotent_for_every_field` | A |
| llms.txt: guardar otra pestaña (guía y límites conservados) | `Test_Settings::test_saving_general_tab_keeps_llms_settings`, `test_llms_limits_ranges_and_defaults` | A |
| llms.txt: valor antiguo de `llms_limit` (inerte: sin error, límites por defecto, sin efecto en llms.txt) | `Test_Settings::test_stored_llms_limit_from_previous_version_is_inert` | A |
| llms.txt: aviso de tamaño (82.000 → aviso; 20.000 → sin aviso; sin archivo → no generado) | `Test_Llms_Txt::test_tab_shows_size_and_warning` | A |
| llms.txt: URLs de los archivos por tipo en la pestaña (`/llms-page.txt`, `/llms-post.txt`) y campo de la guía | `Test_Llms_Txt::test_tab_shows_size_and_warning`, `test_tab_renders_when_to_use_field` | A |
| Entrada parcial sin pestaña | `Test_Settings::test_sanitize_without_tab_updates_only_present_keys` | A |
| Pestaña desconocida (tercero) | `Test_Settings::test_sanitize_with_unknown_tab_keeps_every_stored_value`, `test_third_party_tab_saves_without_wiping_settings` | A |
| PHP inferior al mínimo | `Test_Requirements::test_php_below_minimum_produces_notice`, `test_wordpress_below_minimum_produces_notice`, `test_plugin_headers_declare_minimums` (the "nothing loads" branch is guarded by the bootstrap and covered by the activation refusal in `wpasl_activate()`) | A |
| Desinstalación limpia | `Test_Lifecycle::test_uninstall_removes_everything_but_content`, `test_uninstall_removes_version_option`, `Test_Multisite::test_uninstall_removes_storage_and_options_on_every_site` + Docker smoke test (`wp plugin uninstall`) | A+M |
| Desinstalación con evento único pendiente | `Test_Lifecycle::test_uninstall_clears_manual_event` | A |
| Sitio creado tras la activación en red | `Test_Multisite::test_site_created_after_network_activation_is_configured`, `test_site_created_without_network_activation_is_untouched`, `Test_Scheduler::test_maybe_upgrade_reschedules_missing_event` | A |
| (recorrido paginado de sitios, desactivación sin flush) | `Test_Multisite::test_network_activation_paginates_sites`, `Test_Lifecycle::test_deactivate_does_not_flush_rewrite_rules` | A |
| (opciones con autoload y rutina de actualización) | `Test_Lifecycle::test_settings_and_token_options_are_autoloaded` | A |
| Sin llamadas externas | `Test_Privacy::test_only_the_crawler_probe_calls_the_http_api`, `test_no_telemetry_or_external_hosts_in_runtime_code`, `Test_Diagnostics::test_probe_only_contacts_its_own_host` | A |
| Redirección a otro host | `Test_Diagnostics::test_probe_does_not_follow_redirects`, `test_report_flags_redirect_as_warning` | A |
| (translations) | `Test_Plugin_Bootstrap::test_spanish_translation_is_bundled` | A |
