#!/usr/bin/env bash
# Fetches a site's pages and discovery files with real AI crawler user-agents and prints what each receives.
# Usage: bin/crawl-check.sh https://example.org [/path-of-a-post/]
set -u
BASE="${1:?base url}"
PATH_="${2:-/}"
UAS=(
  "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot"
  "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot"
  "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot"
  "Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)"
  "Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)"
  "Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)"
  "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)"
  "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)"
  "Mozilla/5.0 (compatible; Google-Extended)"
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot-Extended)"
  "CCBot/2.0 (https://commoncrawl.org/faq/)"
  "Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)"
  "meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)"
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/600.2.5 (KHTML, like Gecko) Version/8.0.2 Safari/600.2.5 (Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)"
  "Mozilla/5.0 (compatible; DuckAssistBot/1.0; +http://duckduckgo.com/duckassistbot.html)"
  "Mozilla/5.0 (compatible; MistralAI-User/1.0; +https://docs.mistral.ai/robots)"
)
hdr() { curl -s -o /dev/null -D - -A "$1" -H "Accept: $2" "$3" | tr -d '\r' | awk 'NR==1{print "  " $0} tolower($0) ~ /^(content-type|content-signal|content-usage|x-robots-tag|vary|x-markdown-tokens|link: <[^>]*\.md)/{print "  " $0}'; }
for ua in "${UAS[@]}"; do
  name="$(echo "$ua" | grep -oE '(GPTBot|ChatGPT-User|OAI-SearchBot|ClaudeBot|Claude-User|Claude-SearchBot|PerplexityBot|Perplexity-User|Google-Extended|Applebot-Extended|CCBot|Bytespider|meta-externalagent|Amazonbot|DuckAssistBot|MistralAI-User)' | head -1)"
  echo "### $name"
  echo "HTML  $BASE$PATH_"; hdr "$ua" "text/html,*/*;q=0.8" "$BASE$PATH_"
  echo "MD    $BASE$PATH_ (Accept: text/markdown)"; hdr "$ua" "text/markdown, text/html;q=0.9, */*;q=0.8" "$BASE$PATH_"
  echo "robots.txt verdict:"; curl -s -A "$ua" "$BASE/robots.txt" | awk -v ua="$name" 'BEGIN{IGNORECASE=1} tolower($0)=="user-agent: " tolower(ua){f=1;next} f&&/^user-agent:/{f=0} f&&/^([Aa]llow|[Dd]isallow):/{print "  " $0; f=0}'
  echo
done
echo "### Discovery files (any user-agent)"
for p in /robots.txt /llms.txt /agent-skills.json /.well-known/api-catalog /wp-json/wpasl/v1/openapi; do
  printf '%-30s ' "$p"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' -A "${UAS[0]}" "$BASE$p"
done
