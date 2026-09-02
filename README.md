# WP Agent Support Layer

[![CI](https://github.com/111x-SAS/wp-agent-support-layer/actions/workflows/ci.yml/badge.svg)](https://github.com/111x-SAS/wp-agent-support-layer/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)

WordPress plugin that makes a site discoverable, readable and operable by AI agents and crawlers ("agent-readiness"), without touching your infrastructure.

| Capability | What you get |
| --- | --- |
| **Markdown delivery** | `Accept: text/markdown` negotiation and `.md` URLs for posts and pages, with YAML front matter and `<link rel="alternate">` discovery. Pre-generated on a schedule, never on save. |
| **Content Signals** | `search`, `ai-input`, `ai-train` in robots.txt, `Content-Signal` / `Content-Usage` headers, `noai` in `X-Robots-Tag` and the robots meta tag. |
| **AI crawler rules** | One robots.txt group per known AI crawler (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, ...) with defaults derived from the signals and per-crawler overrides. |
| **llms.txt** | Curated Markdown index at `/llms.txt`, optional `/llms-full.txt`. |
| **Agent manifests** | `/agent-skills.json` (JSON-LD), OpenAPI 3.1 at `/wp-json/wpasl/v1/openapi`, `/.well-known/api-catalog` (RFC 9727). |
| **Diagnostics** | Loopback simulation of real AI crawlers, run in short batches (safe behind Cloudflare's 100 s limit), with a per-crawler report checked against the served robots.txt, CDN detection, WAF checklist and `curl` commands. |

Requires WordPress 7.0+ and PHP 7.4+. Tested on PHP 7.4, 8.0, 8.2 and 8.3. No external requests, no telemetry.

## Install

Download the latest zip from [Releases](https://github.com/111x-SAS/wp-agent-support-layer/releases) and upload it from **Plugins → Add New → Upload**, or once published, install it from WordPress.org.

A git checkout is **not** installable as is: the HTML-to-Markdown library must be prefixed first. From the plugin directory run `composer run build`.

## Configure

**Tools → Agent Support Layer**

- **General**: content types (default: posts and pages), regeneration interval (default: daily), items per run, generation status, *Regenerate now*.
- **Signals**: `search`, `ai-input`, `ai-train` and the experimental `Content-Usage` header.
- **Crawlers**: allow / block per AI crawler, preview of the generated robots.txt.
- **llms.txt**: description, introduction, items per section, `llms-full.txt` toggle and size limit.
- **Manifests**: toggle and contact email.
- **Diagnostics**: run the crawler simulation and review the report.

Each post has an *Exclude from the agent layer* checkbox in the editor sidebar.

## Caches and CDNs

Content negotiation depends on `Vary: Accept`. Many page caches and CDNs ignore it and serve cached HTML to agents that ask for Markdown.

- Prefer the `.md` URLs (`/my-post.md`). They are separate resources with their own cache entries and their own `Cache-Control: public, max-age=<regeneration interval>`.
- If you cache HTML at the edge, either honour `Vary: Accept` or exclude requests whose `Accept` header contains `text/markdown` from the HTML cache.
- Do not strip response headers: `Content-Signal`, `Content-Usage`, `X-Robots-Tag` and `Link` are part of the feature.
- `robots.txt`, `llms.txt`, `agent-skills.json` and `/.well-known/api-catalog` may be cached for their `Cache-Control` lifetime.
- **Cloudflare "Markdown for Agents"** converts HTML at the edge. Keep only one converter: disable it to use the plugin's documents (front matter, llms.txt links), or keep it and use the plugin for signals, robots rules, llms.txt and manifests. Diagnostics warns when both are active.

## Web server notes

Generated documents live in `wp-content/uploads/wp-agent-support-layer/<token>/` and are only served through WordPress, which re-checks that the content is still public on every request.

- **Apache**: the plugin writes an `.htaccess` that denies direct access.
- **nginx** (does not read `.htaccess`):

  ```nginx
  location ^~ /wp-content/uploads/wp-agent-support-layer/ { deny all; }
  ```

- `.md` URLs are resolved by the plugin at `parse_request`; no rewrite rules are needed beyond WordPress' own front controller.
- If a physical `robots.txt` or `llms.txt` exists in the site root, the web server serves it and the plugin shows what it would have generated.

## WP-CLI

```bash
wp wpasl status                      # counters, last / next run, schedule
wp wpasl generate                    # run the current cycle to completion
wp wpasl generate --all              # regenerate everything
wp wpasl generate --post-type=page   # one type only
wp wpasl generate --batch            # a single scheduled-size batch
wp wpasl clear                       # delete generated files and reset state
```

If `DISABLE_WP_CRON` is set, call `wp-cron.php` from a system cron or run `wp wpasl generate` periodically.

## Hooks for developers

| Hook | Purpose |
| --- | --- |
| `wpasl_is_eligible` | Veto a post from the agent layer. |
| `wpasl_markdown_html`, `wpasl_markdown_front_matter`, `wpasl_markdown_document`, `wpasl_markdown_language` | Adjust the Markdown documents. |
| `wpasl_crawler_catalog` | Add AI crawlers. |
| `wpasl_llms_sections`, `wpasl_llms_optional_links`, `wpasl_llms_txt` | Adjust llms.txt. |
| `wpasl_agent_capabilities`, `wpasl_agent_skills`, `wpasl_openapi`, `wpasl_api_catalog` | Adjust the manifests. See [docs/agent-skills.md](docs/agent-skills.md). |
| `wpasl_diagnostics_crawlers` | Limit the crawlers simulated by the diagnostics. |
| `wpasl_diagnostics_time_budget` | Seconds of probing per admin request before the diagnostics hand over to the next batch (default 30; each request also probes at least one crawler). |
| `wpasl_run_time_budget` | Seconds allowed per generation run (default 20). |
| `wpasl_max_failures` | Failures after which an item is retried only after the rest of the queue (default 3). |
| `wpasl_generation_failed` (action) | A document could not be generated or stored: post, reason and attempt count. |
| `wpasl_cron_disabled` | Whether WP-Cron counts as disabled for the warning in the General tab. |
| `wpasl_before_serve` (action), `wpasl_terminate_after_serve` | Runs before a document is sent; whether the request ends afterwards. |
| `wpasl_register_tabs`, `wpasl_page_after_form`, `wpasl_general_tab_after` (actions) | Add a settings tab; print blocks after a tab's form (own forms go here); print fields inside the General tab's form. |
| `wpasl_physical_robots_path`, `wpasl_physical_llms_path` | Paths checked for physical robots.txt / llms.txt files. |
| `wpasl_services` | Replace or add services at boot (a custom converter implements `ConverterInterface`, including `set_base_url()`). |

The full list, with the file that documents each one, is in the "Filters and actions" section of `readme.txt`.

## Development

```bash
composer install                # dev dependencies (PHPUnit, WPCS, polyfills)
composer run strauss            # prefix league/html-to-markdown into vendor-prefixed/
composer run install-wp-tests   # needs MySQL on 127.0.0.1:3306 (root/root)
composer test                   # PHPUnit against the official WordPress test suite
composer lint                   # PHPCS with WordPress Coding Standards + PHPCompatibility 7.4+
```

Specifications are written with [OpenSpec](https://github.com/Fission-AI/OpenSpec) in `openspec/` before any code; the scenario-to-test map is in [docs/spec-coverage.md](docs/spec-coverage.md). CI runs PHPUnit on PHP 7.4 / 8.0 / 8.2 / 8.3 (single site and multisite), PHPCS and the official Plugin Check on every push. Tagging `vX.Y.Z` builds the distributable zip and attaches it to a GitHub release.

## Author and license

Developed by **Mao Rodriguez** (mao@111x.co). Supported by **111X S.A.S** ([111x.co](https://111x.co), contacto@111x.co).

Licensed under the GPL-2.0-or-later. You may use, modify and redistribute this plugin under the terms of that license, which requires keeping the copyright and authorship notices intact.

---

## En español

Plugin de WordPress que prepara un sitio para agentes y crawlers de IA: entrega en Markdown por `Accept: text/markdown` y URLs `.md`, Content Signals en robots.txt y cabeceras, reglas por crawler de IA, `llms.txt`, manifiestos de agente (`agent-skills.json`, OpenAPI 3.1, `/.well-known/api-catalog`) y un panel de diagnóstico de solo lectura. La interfaz está en inglés e incluye traducción al español.

Los documentos Markdown se generan por programación (WP-Cron) en lotes acotados y se guardan en `uploads/wp-agent-support-layer/`; guardar una entrada nunca dispara conversiones. Si usas nginx, añade la regla `deny all` indicada arriba; si usas una caché de página o CDN, apóyate en las URLs `.md` o haz que respete `Vary: Accept`. Las especificaciones se redactan con OpenSpec en `openspec/` antes de escribir código.
