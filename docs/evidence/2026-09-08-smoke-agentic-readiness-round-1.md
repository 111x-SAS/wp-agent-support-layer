# Smoke evidence: agentic readiness round 1 on a clean WordPress 7.1

Date: 2026-09-08. Plugin: WP Agent Support Layer at commit `c2efdf4` (distributable zip built from the working
tree with `.distignore`, unchanged version 1.0.3). Environment: Docker, `php:8.3-apache` (PHP 8.3.33) with
mod_rewrite, MySQL 8.0, clean WordPress 7.1 installed with the setup steps of `bin/smoke-docker.sh` (12 generated
posts plus "Hello world!", 2 generated pages), default settings, pretty permalinks. The standard smoke ran in full
(activation, generation, Markdown delivery, discovery files, diagnostics with 0 non-ok checks, admin forms, static
front page, batched diagnostics, crawl check, uninstall) with the checks below inserted after the discovery files.

This is the evidence for task 10.4 of the OpenSpec change `agentic-readiness-round-1`.

## API catalog (A)

```
HTTP/1.1 200 OK
Content-Type: application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"
linkset[0].anchor=http://localhost:8183/.well-known/api-catalog
linkset[0].item[0].href=http://localhost:8183/wp-json
linkset[1].anchor=http://localhost:8183/wp-json
linkset[1].service-desc[0].href=http://localhost:8183/wp-json/wpasl/v1/openapi
service-doc=http://localhost:8183/llms.txt http://localhost:8183/auth.md http://localhost:8183/agent-skills.json
```

The diagnostics report accepts the catalog with the profile parameter: `catalog: ok API catalog: HTTP 200,
application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"`.

## OpenAPI error model and versioning policy (B, C)

```
Error.required=code,message
posts.responses=200,400,404,default
404.ref=#/components/schemas/Error
problem+json=absent
description=Read-only endpoints of the WordPress REST API that agents may use without authentication. This API is
the WordPress REST API. Routes are grouped in versioned namespaces (wp/v2, wpasl/v1). Changes follow the release
cycles of WordPress core and of the WP Agent Support Layer plugin. This site does not send Deprecation or Sunset
headers. Backwards-incompatible changes to the wpasl/v1 routes are announced in the plugin changelog; changes to
core routes follow the WordPress release notes.
```

`/auth.md` contains `## When to use this site` (line 9, after the guidance was set) and `## API versioning and
deprecation` (line 44).

## Markdown 404 (D)

`curl -si /no-existe.md`:

```
HTTP/1.1 404 Not Found
Cache-Control: no-store
Content-Signal: search=yes, ai-input=yes, ai-train=no
Link: <http://localhost:8183/.well-known/api-catalog>; rel="api-catalog"
Vary: Accept
X-Content-Type-Options: nosniff
Content-Type: text/markdown; charset=utf-8

# Not found

The requested resource does not exist on Smoke, is not public, or has no Markdown version.

## Where to look instead

- [Site index (llms.txt)](http://localhost:8183/llms.txt): curated Markdown index of the public content.
- [Agent access documentation (auth.md)](http://localhost:8183/auth.md): how agents may access this site.
- [Sitemap](http://localhost:8183/wp-sitemap.xml): XML sitemap of the whole site.
- [API catalog](http://localhost:8183/.well-known/api-catalog): RFC 9727 linkset with the OpenAPI description of the public REST API.
```

`Accept: text/markdown` on `/no-existe/` answers `404 text/markdown; charset=utf-8`; a browser `Accept` on the same
URL keeps `404 text/html; charset=UTF-8`.

## llms.txt as a navigation index (E, F)

`/llms.txt` weighs 1,279 characters (below the 30,000 recommended), has `## When to use this site` after the
introduction, and its `## Posts` section ends with `- [Full list of Posts (13 items)](http://localhost:8183/llms-post.txt)`.
`/llms-post.txt` answers `200 text/markdown; charset=utf-8`, starts with `# Smoke — Posts` and the blockquote
`> All public Posts of Smoke (13 items). Index: http://localhost:8183/llms.txt`, and lists the 13 posts.
`/llms-page.txt` answers 200; `/llms-nada.txt` answers 404. The diagnostics report shows `llms-page: ok` and
`llms-post: ok` between `llms` and `auth`.

## Discovery links and shortcode (G)

Front page head:

```
<link rel="service-desc" type="application/openapi+json" href="http://localhost:8183/wp-json/wpasl/v1/openapi" />
<link rel="api-catalog" type="application/linkset+json" href="http://localhost:8183/.well-known/api-catalog" />
<link rel="describedby" type="text/markdown" href="http://localhost:8183/llms.txt" />
<link rel="service-doc" type="text/markdown" href="http://localhost:8183/auth.md" />
```

An inner post carries the same four links next to its `rel="alternate" type="text/markdown"` link (the raw count of
`<link rel="alternate">` elements is higher because core adds the feed and oEmbed alternates). A page whose content
is `[wpasl_agent_links]` renders:

```
<ul class="wpasl-agent-links"><li><a href="http://localhost:8183/auth.md" rel="service-doc" type="text/markdown">Agent access documentation (auth.md)</a></li><li><a href="http://localhost:8183/wp-json/wpasl/v1/openapi" rel="service-desc" type="application/openapi+json">OpenAPI description of the public REST API</a></li><li><a href="http://localhost:8183/.well-known/api-catalog" rel="api-catalog" type="application/linkset+json">API catalog (RFC 9727)</a></li><li><a href="http://localhost:8183/llms.txt" rel="describedby" type="text/markdown">Site index for agents (llms.txt)</a></li></ul>
```

## Automated suites at this commit

`vendor/bin/phpunit`: 355 tests, 2,621 assertions, 6 skipped (baseline before the change: 313 tests, 6 skipped).
`vendor/bin/phpunit -c tests/multisite.xml.dist`: 355 tests, 2,654 assertions, 0 skipped (baseline: 313).
`vendor/bin/phpcs --no-cache`: exit code 0.
