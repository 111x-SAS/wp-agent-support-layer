=== WP Agent Support Layer ===
Contributors: maorodriguez
Tags: ai, agents, markdown, llms.txt, robots.txt
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site discoverable, readable and operable by AI agents: Markdown delivery, Content Signals, AI crawler rules, llms.txt and agent manifests.

== Description ==

WP Agent Support Layer prepares a WordPress site for AI agents and crawlers ("agent-readiness") without touching your infrastructure:

* **Markdown delivery** – serves a Markdown version of posts and pages through `Accept: text/markdown` content negotiation and a `.md` URL, pre-generated on a schedule and never on save.
* **Content Signals** – declares `search`, `ai-input` and `ai-train` preferences in robots.txt, HTTP headers and meta tags.
* **AI crawler rules** – adds per-crawler rules for GPTBot, ClaudeBot, PerplexityBot, Google-Extended and others to the WordPress robots.txt.
* **llms.txt** – publishes a curated Markdown index of the site at `/llms.txt`.
* **Agent manifests** – publishes `/agent-skills.json`, an OpenAPI 3.1 description of the public REST API and `/.well-known/api-catalog`.
* **Diagnostics** – simulates real AI crawlers against your own site from the Tools menu, read-only.

The plugin never contacts external services and sends no telemetry.

Developed by Mao Rodriguez and supported by [111X S.A.S](https://111x.co).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it.
3. Go to Tools → Agent Support Layer to choose post types and review the defaults.

== Frequently Asked Questions ==

= Does it modify my physical robots.txt? =

No. It only extends the virtual robots.txt WordPress serves. If a physical file exists, the plugin shows the generated rules so you can paste them.

= Does it send data anywhere? =

No. All requests, including diagnostics, target your own site.

== Changelog ==

= 0.1.0 =
* Initial scaffolding.
