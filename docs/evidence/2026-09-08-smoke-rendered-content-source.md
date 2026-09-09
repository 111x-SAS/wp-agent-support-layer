# Smoke evidence: rendered content source on a clean WordPress 7.1

Date: 2026-09-08. Plugin: WP Agent Support Layer at commit `95c6692` (distributable zip built from the working
tree with `.distignore`, unchanged version 1.0.3). Environment: Docker, `php:8.3-apache` with mod_rewrite,
MySQL 8.0, clean WordPress 7.1 installed with the setup steps of `bin/smoke-docker.sh`, the classic theme
Twenty Twenty-One, default plugin settings, pretty permalinks.

This is the evidence for task 11.4 of the OpenSpec change `rendered-content-source`. Elementor is not installed:
a mu-plugin plays the page builder by injecting a section through `the_content` only when the theme renders the
page in the loop (what a builder does), and the page carries the Elementor meta (`_elementor_edit_mode` =
`builder`, non-empty `_elementor_data`). Checks: `wp wpasl source <id>` reports `rendered` with the reason
(`builder:elementor`, `page_template`) and errors for a missing or draft item; `curl <permalink>.md` returns
`source: "rendered"` with the text that only exists on the rendered page; the render request with
`Accept: text/markdown` (header, or query argument alone) returns the HTML page, also on the static front page;
the diagnostics "Rendered page (loopback)" check is correct and names the `main` region; with the loopback blocked
by a mu-plugin (`pre_http_request` returning a WP_Error for the render URL) the `.md` is served with
`source: "editor"`, the state counts the failures, the error log names the item and reason, the General tab
shows the rows and the notice, the Diagnostics tab shows the notice, the `curl` command and the checklist line,
and the report marks the loopback check as an error; unblocking and regenerating clears the counter and serves the
rendered body again; the per-post override forces the editor content. The two generated posts and "Hello world!"
resolve `rendered` with reason `empty_editor` because their editor content converts to fewer than 100
characters (design decision A1, 3d).

Script: the setup of `bin/smoke-docker.sh` followed by the checks below (`curl` from the host, `wp` inside the
container).

```
mysqld is alive
PHP 8.3.33 / WordPress 7.1 / theme twentytwentyone
== wp wpasl source (builder meta, assigned template, normal)
key	value
source	rendered
reason	builder:elementor
selector	auto
key,value source,rendered reason,builder:elementor selector,auto 
key,value source,rendered reason,page_template selector,auto 
key,value source,editor reason,default selector,auto 
== wp wpasl source errors
Error: Post #999999 does not exist.
Error: Post #10 is not eligible for the agent layer (status).
== generate (loopback inside the container)
Success: Processed 7 item(s) and regenerated the discovery files.
generated,7 render_failed,0 last_render_error,none 
== curl blackboard.md (expect source rendered and the rendered-only text)
source: "rendered"
description: "Rendered only section This paragraph only exists on the rendered page, injected by the builder simulation. - Rendered bullet one - Rendered bullet two"
## Rendered only section
This paragraph only exists on the rendered page, injected by the builder simulation.
- Rendered bullet one
- Rendered bullet two
== curl landing.md (assigned template: rendered, body from the theme page)
source: "rendered"
== curl normal.md (editor)
source: "editor"
== render request with Accept: text/markdown returns HTML
200 text/html; charset=UTF-8
html doctype: 2
== render request via query arg only, Accept markdown
200 text/html; charset=UTF-8
== static front page with render marker resolves the front page
<title>Smoke</title>
== diagnostics: Rendered page (loopback) check
render: ok Rendered page (loopback): HTTP 200, text/html; charset=UTF-8, content region main.
non-ok site checks: 0
== block the loopback (mu-plugin) and regenerate
[wp-agent-support-layer] Could not fetch the rendered page of post #1 (request_error:http_request_failed), attempt 1; the editor content was used.
[wp-agent-support-layer] Could not fetch the rendered page of post #5 (request_error:http_request_failed), attempt 1; the editor content was used.
[wp-agent-support-layer] Could not fetch the rendered page of post #6 (request_error:http_request_failed), attempt 1; the editor content was used.
failed,0 render_failed,5 last_render_error,"request_error:http_request_failed (#8, 2026-09-09T00:11:41+00:00)" 
source: "editor"
description: "Editor placeholder."
Editor placeholder.
sources of every eligible item while blocked:
#9 Normal: source,editor reason,default selector,auto 
#7 Blackboard: source,rendered reason,builder:elementor selector,auto 
#8 Landing: source,rendered reason,page_template selector,auto 
#5 Post 1: source,rendered reason,empty_editor selector,auto 
#6 Post 2: source,rendered reason,empty_editor selector,auto 
#2 Sample Page: source,editor reason,default selector,auto 
#1 Hello world!: source,rendered reason,empty_editor selector,auto 
General tab notice: 1 (expect 1)
General tab rows: Items with a rendered-page failure</th><td>5 Last rendered-page failure</th><td>request_error:http_request_failed (#8, September 9, 2026 12:11 am) 
General tab content source selects: wpasl_settings[content_source][post] wpasl_settings[content_source][page] 
Diagnostics tab notice: 1 (expect 1)
Diagnostics tab curl command: curl -s -H 'X-WPASL-Render: 1' 'http://localhost:8183/normal/?wpasl_render=1'
Diagnostics checklist loopback line: 1 (expect 1)
render (blocked): error Rendered page (loopback): HTTP 0 cURL error 28: simulated timeout; the site cannot fetch its own pages, items whose content source is the rendered page are served with the editor content.
== unblock and regenerate: counter cleared, rendered again
Success: Processed 7 item(s) and regenerated the discovery files.
render_failed,0 last_render_error,"request_error:http_request_failed (#8, 2026-09-09T00:11:41+00:00)" 
source: "rendered"
description: "Rendered only section This paragraph only exists on the rendered page, injected by the builder simulation. - Rendered bullet one - Rendered bullet two"
## Rendered only section
== per-post override: editor
key,value source,editor reason,post_override selector,auto 
source: "editor"
description: "Editor placeholder."
Editor placeholder.
== done
```
