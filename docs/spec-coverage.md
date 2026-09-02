# Specification coverage

Every scenario of the OpenSpec change `agent-support-layer-v1` mapped to the automated test that
exercises it (PHPUnit against the official WordPress test suite), or to the manual evidence when
the behaviour depends on a web server. Test classes live in `tests/`.

Legend: **A** automated (PHPUnit), **M** manual evidence (`docs/evidence/`), **A+M** both.

## markdown-delivery

| Requirement / scenario | Test | |
| --- | --- | --- |
| Elegible: entrada publicada de un post type habilitado | `Test_Eligibility::test_published_post_of_enabled_type_is_eligible`, `Test_Delivery::test_accept_header_serves_markdown_on_canonical_url` | A |
| Elegible: entrada despublicada después de la última generación | `Test_Delivery::test_unpublished_after_generation_is_not_served` | A |
| Elegible: entrada protegida con contraseña | `Test_Eligibility::test_password_protected_is_not_eligible`, `Test_Delivery::test_non_eligible_content_is_never_served_as_markdown` | A |
| Elegible: post type no habilitado | `Test_Eligibility::test_disabled_post_type_is_not_eligible`, `Test_Delivery::test_no_alternate_link_for_disabled_post_type` | A |
| Elegible: entrada excluida manualmente | `Test_Eligibility::test_excluded_post_is_not_eligible`, `Test_Delivery::test_non_eligible_content_is_never_served_as_markdown` | A |
| Accept: agente que prefiere Markdown | `Test_Delivery::test_prefers_markdown` (markdown only, equal preference, agent with fallbacks), `test_accept_header_serves_markdown_on_canonical_url` | A+M |
| Accept: navegador que prefiere HTML | `Test_Delivery::test_prefers_markdown` (browser), `test_browser_accept_gets_html` | A+M |
| Accept: ambos tipos con preferencia por HTML | `Test_Delivery::test_prefers_markdown` (html preferred, markdown below wildcard) | A |
| Sufijo .md sobre enlace permanente bonito | `Test_Delivery::test_md_suffix_serves_markdown`, `test_md_suffix_for_page_hierarchy` | A+M |
| Sufijo .md sobre contenido inexistente | `Test_Delivery::test_md_suffix_for_unknown_content_is_404`, `test_md_suffix_for_home_is_404` | A+M |
| Parámetro de consulta con enlaces simples | `Test_Delivery::test_markdown_url_with_plain_permalinks`, `test_query_var_serves_markdown_regardless_of_accept` | A |
| Cabeceras presentes | `Test_Delivery::test_markdown_headers`, `test_cache_max_age_follows_the_schedule` | A+M |
| Estimación de tokens coherente | `Test_Delivery::test_markdown_headers`, `Test_Document_Builder::test_token_estimate` | A |
| Enlace alternativo en una entrada elegible | `Test_Delivery::test_html_headers_and_alternate_link_for_eligible_post` | A+M |
| Sin enlace en contenido no elegible | `Test_Delivery::test_no_alternate_link_for_disabled_post_type` | A |
| Front matter completo | `Test_Document_Builder::test_front_matter_contains_required_keys_and_taxonomies`, `test_description_falls_back_to_content_and_taxonomies_are_omitted_when_empty` | A |
| Limpieza de elementos no textuales | `Test_Converter::test_non_content_elements_are_removed`, `Test_Document_Builder::test_scripts_forms_iframes_are_stripped_and_links_absolutized` | A |
| Enlaces relativos | `Test_Converter::test_relative_links_and_images_become_absolute`, `test_absolute_and_special_links_are_untouched` | A |
| Documento previamente generado | `Test_Delivery::test_stored_document_is_served_without_reconverting` | A |
| Documento ausente | `Test_Delivery::test_accept_header_serves_markdown_on_canonical_url` (lazy fill), `Test_Runner::test_generate_item_lazily_fills_and_records` | A |
| Edición de una entrada | `Test_Delivery::test_editing_does_not_change_the_served_document`, `Test_Runner::test_updating_a_post_keeps_the_stored_document_until_the_next_cycle` | A |

## scheduled-generation

| Requirement / scenario | Test | |
| --- | --- | --- |
| Programación al activar | `Test_Lifecycle::test_activation_registers_defaults_storage_and_cron`, `test_activation_is_idempotent_for_cron` | A |
| Cambio de intervalo | `Test_Scheduler::test_changing_the_interval_reschedules_without_duplicates`, `test_schedule_uses_configured_interval` | A |
| Desactivación | `Test_Lifecycle::test_deactivation_removes_cron` | A |
| Sitio con más ítems que el tamaño del lote | `Test_Runner::test_120_items_with_batch_50_take_three_runs` | A |
| Presupuesto de tiempo agotado | `Test_Runner::test_time_budget_stops_the_run_and_the_next_run_continues` | A |
| Ciclo completo | `Test_Runner::test_120_items_with_batch_50_take_three_runs` (artifacts after the last batch), `Test_Llms_Txt::test_builder_is_registered_as_artifact_generator`, `Test_Agent_Manifest::test_builder_is_an_artifact_generator_and_respects_the_toggle` | A |
| Entrada enviada a papelera | `Test_Runner::test_trashing_a_post_deletes_its_document_immediately_without_generating`, `test_deleting_a_post_deletes_its_document` | A |
| Post type deshabilitado | `Test_Runner::test_prune_removes_documents_of_disabled_post_type` | A |
| Publicar una entrada nueva | `Test_Runner::test_publishing_or_updating_never_generates` | A |
| Administrador pulsa Regenerar ahora | `Test_Generation_Status::test_handler_schedules_and_redirects_without_generating`, `Test_Scheduler::test_run_soon_makes_the_recurring_event_due_now` | A |
| Petición sin nonce válido | `Test_Generation_Status::test_handler_requires_nonce`, `test_handler_requires_capability` | A |
| WP-Cron desactivado | `Test_Generation_Status::test_render_shows_status_and_cron_warning`, `test_render_without_warning_when_cron_enabled` | A |
| Generación completa por WP-CLI | `Test_CLI::test_generate_all_processes_every_item_and_reports_the_total`, `test_generate_post_type_and_batch` | A+M |
| Vaciar almacenamiento | `Test_CLI::test_status_and_clear`, `Test_Runner::test_clear_empties_storage_and_state` | A+M |
| Acceso directo al archivo | `Test_Storage::test_htaccess_denies_access` (rules) + Docker smoke test on Apache (HTTP 403) | A+M |
| Multisitio | `Test_Multisite::test_each_site_has_its_own_storage_and_documents`, `test_network_activation_sets_up_every_site` (run with `tests/multisite.xml.dist`) | A |

## content-signals

| Requirement / scenario | Test | |
| --- | --- | --- |
| Valores por defecto | `Test_Content_Signals::test_default_values`, `Test_Settings::test_defaults` | A |
| robots.txt con señales por defecto | `Test_Content_Signals::test_robots_txt_gets_directive_inside_the_wildcard_group` | A+M |
| Cambio de señal | `Test_Content_Signals::test_robots_txt_via_core_filter_reflects_changes` | A |
| Cabecera en una entrada pública | `Test_Content_Signals::test_headers_by_default`, `test_hooks_are_wired` | A+M |
| Cabecera experimental desactivada | `Test_Content_Signals::test_headers_without_experimental_header` | A |
| Área de administración | `Test_Content_Signals::test_nothing_in_admin` | A |
| Entrenamiento no permitido | `Test_Content_Signals::test_headers_by_default`, `test_meta_robots_gets_noai_and_keeps_existing_directives` | A+M |
| Entrenamiento permitido | `Test_Content_Signals::test_headers_when_training_allowed`, `test_meta_robots_untouched_when_training_allowed` | A |
| Convivencia con directivas existentes | `Test_Content_Signals::test_meta_robots_gets_noai_and_keeps_existing_directives` | A |

## ai-crawler-robots

| Requirement / scenario | Test | |
| --- | --- | --- |
| Catálogo ampliado por filtro | `Test_Crawler_Robots::test_catalog_filter_adds_an_agent`, `test_catalog_contains_the_required_agents_with_groups` | A |
| Defaults con señales por defecto | `Test_Crawler_Robots::test_default_policies_follow_the_signals`, `test_signals_change_the_defaults` | A |
| Sobrescritura individual | `Test_Crawler_Robots::test_individual_override_is_respected`, `test_sanitizer_drops_default_and_keeps_valid_overrides` | A |
| Crawler bloqueado | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups` | A+M |
| Crawler permitido | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups` | A+M |
| Reglas del núcleo intactas | `Test_Crawler_Robots::test_robots_txt_output_keeps_core_lines_and_adds_groups`, `test_generated_output_matches_the_virtual_file` | A |
| Archivo físico presente | `Test_Crawler_Robots::test_physical_file_detection` | A |

## llms-txt

| Requirement / scenario | Test | |
| --- | --- | --- |
| Petición a llms.txt | `Test_Llms_Txt::test_route_serves_llms_txt_with_lazy_generation` | A+M |
| Documento ausente | `Test_Llms_Txt::test_route_serves_llms_txt_with_lazy_generation`, `test_route_serves_stored_file_without_rebuilding` | A |
| Documento con post y page | `Test_Llms_Txt::test_structure_with_pages_and_posts`, `test_blockquote_falls_back_when_the_tagline_is_empty`, `test_pages_are_ordered_by_menu_order_then_title` | A |
| Descripción personalizada | `Test_Llms_Txt::test_custom_description_and_intro` | A |
| Más ítems que el límite | `Test_Llms_Txt::test_limit_keeps_the_most_recent_posts` | A |
| Entrada excluida | `Test_Llms_Txt::test_excluded_and_non_eligible_items_are_not_listed` | A |
| llms-full habilitado | `Test_Llms_Txt::test_llms_full_enabled_concatenates_documents` | A |
| llms-full deshabilitado | `Test_Llms_Txt::test_llms_full_disabled_is_404` | A+M |
| Límite de tamaño | `Test_Llms_Txt::test_llms_full_truncates_at_the_last_complete_item` | A |
| (physical file precedence, settings invalidation) | `Test_Llms_Txt::test_physical_file_takes_precedence`, `test_settings_change_invalidates_stored_files` | A |

## agent-manifest

| Requirement / scenario | Test | |
| --- | --- | --- |
| Manifiesto por defecto | `Test_Agent_Manifest::test_agent_skills_document`, `test_agent_skills_route` | A+M |
| Manifiesto deshabilitado | `Test_Agent_Manifest::test_routes_are_404_when_disabled` | A |
| Post type habilitado y expuesto en REST | `Test_Agent_Manifest::test_default_capabilities_cover_rest_markdown_index_and_openapi` | A |
| Post type habilitado pero sin REST | `Test_Agent_Manifest::test_post_type_without_rest_gets_no_rest_capabilities` | A |
| Capacidad añadida por filtro | `Test_Agent_Manifest::test_filter_adds_a_capability_and_authenticated_ones_are_dropped` | A |
| Documento válido | `Test_Agent_Manifest::test_openapi_document`, `test_openapi_rest_route` | A+M |
| Solo métodos GET | `Test_Agent_Manifest::test_openapi_document` | A |
| Catálogo disponible | `Test_Agent_Manifest::test_api_catalog_route` | A+M |
| Cambio de correo de contacto | `Test_Agent_Manifest::test_contact_email_change_is_reflected_on_next_request` | A |

## agent-diagnostics

| Requirement / scenario | Test | |
| --- | --- | --- |
| Ejecución completa | `Test_Diagnostics::test_probe_uses_crawler_user_agents_and_accept_headers`, `test_probe_only_contacts_its_own_host` | A+M |
| Sin permisos | `Test_Diagnostics::test_handler_requires_capability_and_nonce`, `test_handler_runs_and_redirects` | A |
| Crawler bloqueado en robots.txt | `Test_Diagnostics::test_report_blocked_crawler_is_coherent_and_healthy_site_is_ok` | A+M |
| Markdown no servido | `Test_Diagnostics::test_report_flags_html_returned_for_markdown_request`, `test_report_flags_waf_block_and_missing_headers` | A |
| Cloudflare detectado | `Test_Diagnostics::test_report_detects_cloudflare_and_edge_markdown` | A |
| Almacenamiento expuesto | `Test_Diagnostics::test_report_flags_exposed_storage` | A |
| Comandos disponibles | `Test_Diagnostics::test_tab_renders_checklist_and_curl_commands` | A |

## admin-settings

| Requirement / scenario | Test | |
| --- | --- | --- |
| Acceso con permisos | `Test_Settings::test_page_is_registered_under_tools_for_administrators`, `test_page_renders_general_tab_for_administrators` | A |
| Acceso sin permisos | `Test_Settings::test_page_denies_editors` | A |
| Post types por defecto | `Test_Settings::test_defaults`, `test_page_renders_general_tab_for_administrators` | A |
| Post type no público | `Test_Settings::test_selectable_post_types_exclude_attachment_and_non_public` | A |
| Marcar exclusión | `Test_Exclude_Meta_Box::test_save_with_valid_nonce_sets_and_clears_meta`, `test_save_without_nonce_is_ignored`, `test_save_by_user_without_permission_is_ignored`, `test_meta_is_exposed_in_rest_for_editors_only` | A |
| Post type no habilitado (casilla oculta) | `Test_Exclude_Meta_Box::test_meta_box_is_added_only_for_enabled_post_types`, `test_meta_is_registered_only_for_enabled_post_types` | A |
| PHP inferior al mínimo | `Test_Requirements::test_php_below_minimum_produces_notice`, `test_wordpress_below_minimum_produces_notice`, `test_plugin_headers_declare_minimums` (the "nothing loads" branch is guarded by the bootstrap and covered by the activation refusal in `wpasl_activate()`) | A |
| Desinstalación limpia | `Test_Lifecycle::test_uninstall_removes_everything_but_content` + Docker smoke test (`wp plugin uninstall`) | A+M |
| Sin llamadas externas | `Test_Privacy::test_only_the_crawler_probe_calls_the_http_api`, `test_no_telemetry_or_external_hosts_in_runtime_code`, `Test_Diagnostics::test_probe_only_contacts_its_own_host` | A |
| (translations) | `Test_Plugin_Bootstrap::test_spanish_translation_is_bundled` | A |
