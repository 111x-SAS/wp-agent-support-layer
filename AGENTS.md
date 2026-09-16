# AGENTS.md

This file provides guidance to Claude Code (claude.ai/code) and other AI coding agents when working with code in this repository.

## What this is

WordPress plugin (`wp-agent-support-layer`) that makes a site discoverable, readable and operable by AI agents/crawlers: Markdown delivery (`Accept: text/markdown` negotiation + `.md` URLs), Content Signals, per-crawler robots.txt rules, `llms.txt`, agent manifests (`agent-skills.json`, OpenAPI 3.1, `/.well-known/api-catalog`), and a read-only diagnostics panel that simulates real AI crawlers via loopback requests.

Requires WordPress 7.0+, PHP 7.4+ (tested 7.4/8.0/8.2/8.3). No external requests, no telemetry.

## Commands

```bash
composer install                # dev dependencies (PHPUnit, WPCS, polyfills)
composer run strauss            # prefix league/html-to-markdown into vendor-prefixed/ (required before the plugin runs from a git checkout)
composer run install-wp-tests   # sets up the WP test suite; needs MySQL on 127.0.0.1:3306 (root/root)
composer test                   # full PHPUnit suite against the WP test suite
composer lint                   # PHPCS (WordPress Coding Standards + PHPCompatibility 7.4+)
composer lint:fix               # phpcbf autofix
composer run build              # --no-dev install + strauss + dev install; produces an installable checkout
```

Single test file or filter (after `install-wp-tests`):

```bash
vendor/bin/phpunit tests/test-runner.php
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit -c tests/multisite.xml.dist   # multisite suite
```

WP-CLI (on a real install):

```bash
wp wpasl status
wp wpasl generate [--all] [--post-type=page] [--batch]
wp wpasl clear
```

A git checkout is **not installable as-is**: `league/html-to-markdown` must be prefixed first via `composer run strauss` (or `composer run build`). Without it, `LeagueConverter::is_available()` is false and the plugin shows an admin notice instead of registering the item generator.

## Architecture

**Service container, not a DI framework.** `src/Plugin.php` (`Plugin::boot()`) is the single composition root: it instantiates every service, wires them into `$this->services` (an id => object map), and calls `apply_filters( 'wpasl_services', ... )` so third parties can swap services before `register()` runs on each one. `Plugin::get( $id )` is the only lookup path — there is no autowiring. When adding a service, register it in `boot()` and give it a `register()` method if it needs to hook into WordPress.

**Generation is pull-based and scheduled, never on save.** `Generation\Scheduler` owns the WP-Cron event; `Generation\Runner` drives each cycle in bounded batches (`wpasl_run_time_budget`, default 20s) and persists progress via `Generation\State` so a run can resume after a timeout or a failure (`wpasl_max_failures` before an item is deprioritized). Two generator roles feed the Runner:
- `ItemGeneratorInterface` — one implementation, `Markdown\DocumentBuilder`, produces the per-post Markdown documents.
- `ArtifactGeneratorInterface` — site-wide artifacts (`Llms\LlmsTxtBuilder`, `Manifest\ManifestBuilder`, `Manifest\AuthMdBuilder`) registered via `$runner->add_artifact_generator()`.

Saving a post never triggers conversion; `Content\Eligibility` only decides whether a post is *in scope* for a future run.

**Markdown pipeline**: `Markdown\ContentSource` resolves what HTML to convert (rendered page vs. raw post content, builder-aware), `Markdown\ContentExtractor` narrows it to the main content region, `Markdown\LeagueConverter` (wrapping the Strauss-prefixed `league/html-to-markdown` in `vendor-prefixed/`) does HTML→Markdown, and `Markdown\DocumentBuilder` assembles the final document (YAML front matter + body) via the `wpasl_markdown_*` filters. `Markdown\Delivery` handles the `Accept: text/markdown` negotiation and serves `.md` URLs, resolved at `parse_request` (no rewrite rules needed).

**Generated files are storage, not the source of truth.** `Storage` writes documents/artifacts under `wp-content/uploads/wp-agent-support-layer/<token>/` and every request is re-checked against `Eligibility`/`Settings` before serving — a stale file on disk never bypasses the public/eligible check. `.htaccess` (Apache) is written by `Diagnostics\HtaccessHeaders` to deny direct access; nginx needs a manual `deny all` (see README).

**Robots/signals/manifests are computed on read, not stored as artifacts** (except llms.txt/manifests, which are scheduled artifacts): `Robots\RobotsTxt` + `Robots\Catalog` + `Robots\Policy` derive one robots.txt group per known AI crawler from the `Signals\ContentSignals` state, with per-crawler overrides. `Manifest\CapabilityRegistry` feeds both `ManifestBuilder` (agent-skills.json / OpenAPI / api-catalog) and `AuthMdBuilder`.

**Diagnostics is read-only and self-batching.** `Diagnostics\CrawlerProbe` simulates each known crawler via loopback HTTP requests, budgeted by `wpasl_diagnostics_time_budget` (default 30s, at least one crawler per admin request) so it survives edge 100s limits (e.g. Cloudflare). `Diagnostics\Report` cross-checks probe results against the served robots.txt; `Diagnostics\PageCache` detects CDN/page-cache interference (notably Cache Enabler stripping headers — bypass cache before diagnosing, don't trust a cached response).

**Admin UI** is tab-based: `Admin\Page` renders tabs registered via `wpasl_register_tabs`; each tab under `Admin/Tabs/` is self-contained and can print its own form (`wpasl_page_after_form`) or fields inside the General tab's form (`wpasl_general_tab_after`).

**Extensibility is filter/action-based**, not class extension — see the full hook table in `README.md` ("Hooks for developers") and the "Filters and actions" section of `readme.txt` for which file documents each one. `wpasl_services` is the escape hatch for replacing any service, including supplying a custom `ConverterInterface`.

## Spec-driven workflow

Specs are written with [OpenSpec](https://github.com/Fission-AI/OpenSpec) in `openspec/` **before** any code — `openspec/specs/<capability>/` holds current specs, `openspec/changes/` holds proposals/in-flight and archived changes. `docs/spec-coverage.md` maps scenarios to tests. Don't implement a behavior change without an OpenSpec change backing it.

For a full change (not a quick one-off edit), use the `/ship-change <description>` skill (`.claude/skills/ship-change/SKILL.md`) — it runs this project's established plan → implement → review → merge → archive pipeline: an OpenSpec proposal, a user approval gate, an isolated Orca worktree that implements and opens the PR, an automated code-review pass with auto-fix in that same worktree, a second approval gate, then squash-merge plus OpenSpec spec sync/archive and worktree cleanup. See `openspec/changes/archive/2026-09-10-hide-content-source-ui/` for a worked example.

## Coding standards notes specific to this repo

- Files follow PSR-4 (`WPASL\` → `src/`), so PHPCS's `WordPress.Files.FileName.*` rules are disabled in `.phpcs.xml.dist` — don't re-enable file-name-matches-class-name expectations.
- Global prefix is `wpasl`/`WPASL` (enforced by `WordPress.NamingConventions.PrefixAllGlobals`, except under `tests/`).
- Tests follow WordPress core test-suite conventions, not WPCS variable/comment rules (`tests/` is exempted from `ValidVariableName` and `Squiz.Commenting`/`Generic.Commenting`).
- PHPCS can return stale cached results locally — run with `--no-cache` and check the exit code when in doubt.
