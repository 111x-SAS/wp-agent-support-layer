# Smoke evidence: auth.md on a clean WordPress 7.1

Date: 2026-09-08. Plugin: WP Agent Support Layer at commit `3f6d45c` (distributable zip built from the working
tree with `.distignore`, unchanged version 1.0.3). Environment: Docker, `php:8.3-apache` with mod_rewrite,
MySQL 8.0, clean WordPress 7.1 installed with the setup steps of `bin/smoke-docker.sh`, default settings,
pretty permalinks.

This is the evidence for task 9.4 of the OpenSpec change `auth-md-discovery`: `/auth.md` responds 200 with
`Content-Type: text/markdown; charset=utf-8`, the content signals and `nosniff`, its H1 ends in `auth.md`, the body
never mentions OAuth, and `/.well-known/api-catalog`, `/agent-skills.json` and `/robots.txt` link it. A page with
slug `auth` keeps its Markdown through `?wpasl=md`, `/auth/.md` and the announced alternate link, and the site's
`/auth.md` is served instead of that page. Disabling "Publish auth.md" turns `/auth.md` into 404 and removes it
from robots.txt and the catalog on the next request. The `//auth.md` request answers 301 because core's canonical
redirect collapses the double slash before the plugin runs; the redirect target `/auth.md` is the document.

Script: the setup of `bin/smoke-docker.sh` followed by the checks below (`curl` from the host, `wp eval` inside
the container).

```
mysqld is alive
PHP 8.3.33 / WordPress 7.1
== activation
active
== generate
key,value eligible,7 generated,7 pending,0 failed,0 queued,0 last_run,2026-09-08T19:02:35+00:00 last_cycle_completed,2026-09-08T19:02:35+00:00 next_run,2026-09-08T19:03:34+00:00 schedule,daily artifacts,"llms-txt,manifests,auth-md" 
== auth.md headers
HTTP/1.1 200 OK
Content-Signal: search=yes, ai-input=yes, ai-train=no
Content-Usage: train-ai=n, search=y
X-Markdown-Tokens: 676
Cache-Control: public, max-age=86400
X-Content-Type-Options: nosniff
Content-Type: text/markdown; charset=utf-8
== auth.md first line
# Smoke auth.md
== auth.md lines / OAuth mentions
      46
0
== catalog
"href": "http://localhost:8183/auth.md"
== skills
"id": "auth-md"
"url": "http://localhost:8183/auth.md"
== robots
# llms.txt: http://localhost:8183/llms.txt
# auth.md: http://localhost:8183/auth.md
== non canonical
/auth.md/ -> 404
//auth.md -> 301
== page with slug auth
# Smoke auth.md
/auth/?wpasl=md -> 200 text/markdown; charset=utf-8
/auth/.md -> 200 text/markdown; charset=utf-8
<link rel="alternate" type="text/markdown" href="http://localhost:8183/auth/?wpasl=md"
Link: <http://localhost:8183/auth/?wpasl=md>; rel="alternate"; type="text/markdown"
Link: <http://localhost:8183/wp-json/wp/v2/pages/10>; rel="alternate"; title="JSON"; type="application/json"
== diagnostics site checks
robots: ok robots.txt: HTTP 200, text/plain; charset=utf-8.
llms: ok llms.txt: HTTP 200, text/markdown; charset=utf-8.
auth: ok auth.md: HTTP 200, text/markdown; charset=utf-8.
skills: ok agent-skills.json: HTTP 200, application/ld+json; charset=utf-8.
catalog: ok API catalog: HTTP 200, application/linkset+json.
markdown_url: ok Markdown URL of the sample item: HTTP 200, text/markdown; charset=utf-8.
storage: ok Direct access to the generated documents is denied.
== disable auth.md
saved
/auth.md -> 404
robots auth lines: 0
catalog auth links: 0
== done
```
