# WP Agent Support Layer

[![CI](https://github.com/111x-SAS/wp-agent-support-layer/actions/workflows/ci.yml/badge.svg)](https://github.com/111x-SAS/wp-agent-support-layer/actions/workflows/ci.yml)

WordPress plugin that makes a site discoverable, readable and operable by AI agents and crawlers ("agent-readiness"), without touching your infrastructure:

- **Markdown delivery** through `Accept: text/markdown` content negotiation and `.md` URLs, pre-generated on a schedule.
- **Content Signals** (`search`, `ai-input`, `ai-train`) in robots.txt, HTTP headers and meta tags.
- **AI crawler rules** for GPTBot, ClaudeBot, PerplexityBot, Google-Extended and others in the WordPress robots.txt.
- **llms.txt** with a curated Markdown index of the site.
- **Agent manifests**: `/agent-skills.json`, OpenAPI 3.1 for the public REST API and `/.well-known/api-catalog`.
- **Diagnostics** that simulate real AI crawlers against your own site, read-only.

Requires WordPress 7.0+ and PHP 7.4+. Tested on PHP 7.4, 8.0, 8.2 and 8.3.

## Development

```bash
composer install            # dev dependencies (PHPUnit, WPCS, polyfills)
composer run strauss        # prefix league/html-to-markdown into vendor-prefixed/
composer run install-wp-tests   # needs a MySQL server on 127.0.0.1:3306 (root/root)
composer test               # PHPUnit against the official WordPress test suite
composer lint               # PHPCS with WordPress Coding Standards
```

Specifications live in `openspec/` and are written with [OpenSpec](https://github.com/Fission-AI/OpenSpec) before any code.

## Author and license

Developed by **Mao Rodriguez** (mao@111x.co). Supported by **111X S.A.S** ([111x.co](https://111x.co), contacto@111x.co).

Licensed under the GPL-2.0-or-later. You may use, modify and redistribute this plugin under the terms of that license, which requires keeping the copyright and authorship notices intact.

---

## En español

Plugin de WordPress que prepara un sitio para agentes y crawlers de IA: entrega en Markdown, Content Signals, reglas para crawlers de IA en robots.txt, `llms.txt` y manifiestos de agente, más un panel de diagnóstico de solo lectura. La interfaz está en inglés e incluye traducción al español. Las especificaciones se redactan con OpenSpec en `openspec/` antes de escribir código.
