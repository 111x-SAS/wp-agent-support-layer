#!/usr/bin/env bash
# Installs the built plugin zip in a clean WordPress inside Docker (PHP version given as $1)
# and exercises activation, generation, Markdown delivery, discovery files, diagnostics and uninstall.
# Usage: bin/smoke-docker.sh 7.4 path/to/wp-agent-support-layer.zip [wordpress-version]
set -euo pipefail

PHP_VERSION="${1:?php version}"
ZIP="$(cd "$(dirname "${2:?zip path}")" && pwd)/$(basename "$2")"
WP_VERSION="${3:-latest}"
TAG="$(echo "$PHP_VERSION" | tr -d .)"
NET="wpasl-smoke-$TAG"
DB="wpasl-smoke-db-$TAG"
WEB="wpasl-smoke-web-$TAG"
PORT="$((8100 + TAG % 100))"
IMAGE="wpasl-smoke:php$PHP_VERSION-$PORT"

cleanup() { docker rm -f "$WEB" "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress mysql:8.0 --default-authentication-plugin=mysql_native_password >/dev/null

docker build -q -t "$IMAGE" - <<DOCKERFILE >/dev/null
FROM php:${PHP_VERSION}-apache
RUN docker-php-ext-install mysqli >/dev/null && a2enmod rewrite >/dev/null \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp
DOCKERFILE

docker run -d --name "$WEB" --network "$NET" -p "$PORT:$PORT" -v "$ZIP:/tmp/plugin.zip:ro" "$IMAGE" >/dev/null
W() { docker exec -u www-data "$WEB" php -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html "$@"; }
R() { docker exec "$WEB" "$@"; }

R sh -c 'chown -R www-data:www-data /var/www/html'
for i in $(seq 1 30); do docker exec "$DB" mysqladmin ping -uroot -proot --silent 2>/dev/null && break; sleep 2; done
W core download --version="$WP_VERSION" --quiet
W config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost="$DB" --quiet
W core install --url="http://localhost:$PORT" --title="Smoke" --admin_user=admin --admin_password=admin --admin_email=smoke@example.org --skip-email --quiet
W rewrite structure '/%postname%/' --quiet
R sh -c 'printf "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %%{REQUEST_FILENAME} !-f\nRewriteCond %%{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n" > /var/www/html/.htaccess && chown www-data:www-data /var/www/html/.htaccess'
W post generate --count=3 --quiet
W post generate --count=2 --post_type=page --quiet
W plugin install /tmp/plugin.zip --activate --quiet

echo "PHP $(R php -r 'echo PHP_VERSION;') / WordPress $(W core version)"
echo "== activation"; W plugin list --name=wp-agent-support-layer --field=status
W option get wpasl_settings --format=json | head -c 200; echo
echo "== generate"; W wpasl generate; W wpasl status --format=csv | tr '\n' ' '; echo
SLUG="$(W post list --post_type=post --field=post_name | head -1)"
B="http://localhost:$PORT"
echo "== html headers"; curl -s -o /dev/null -D - "$B/$SLUG/" | grep -iE '^(HTTP|vary|link: <[^>]*\.md|content-signal|content-usage|x-robots-tag)'
echo "== accept markdown"; curl -s -D - -H 'Accept: text/markdown' "$B/$SLUG/" | grep -iE '^(HTTP|content-type|x-markdown-tokens|cache-control)|^---$|^title:' | head -6
echo "== .md url"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "$B/$SLUG.md"
echo "== .md 404"; curl -s -o /dev/null -w '%{http_code}\n' "$B/no-such-page.md"
echo "== robots"; curl -s "$B/robots.txt" | grep -E 'Content-Signal|User-agent: GPTBot' | head -2
echo "== llms"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "$B/llms.txt"
echo "== skills"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "$B/agent-skills.json"
echo "== catalog"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "$B/.well-known/api-catalog"
echo "== openapi"; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "$B/wp-json/wpasl/v1/openapi"
TOKEN="$(W option get wpasl_storage_token)"
FILE="$(R sh -c "ls /var/www/html/wp-content/uploads/wp-agent-support-layer/$TOKEN/md/post | head -1")"
echo "== direct storage access (expect 403)"; curl -s -o /dev/null -w '%{http_code}\n' "$B/wp-content/uploads/wp-agent-support-layer/$TOKEN/md/post/$FILE"
echo "== diagnostics (loopback inside the container)"; W eval 'add_filter("wpasl_diagnostics_crawlers", function($c){ return array_intersect_key($c, array_flip(array("GPTBot","PerplexityBot"))); }); $r = WPASL\Plugin::instance()->get("diagnostics")->run(); $bad = 0; foreach ($r["site"] as $k => $c) { if ("ok" !== $c["status"]) { $bad++; echo "site.$k: {$c["status"]} {$c["message"]}\n"; } } foreach ($r["crawlers"] as $a => $d) { foreach ($d["checks"] as $k => $c) { if ("ok" !== $c["status"]) { $bad++; echo "$a.$k: {$c["status"]} {$c["message"]}\n"; } } } echo "non-ok checks: $bad\n";'
echo "== crawl check (real user-agents)"; "$(dirname "$0")/crawl-check.sh" "$B" "/$SLUG/" > "${CRAWL_OUT:-/dev/null}"; echo "saved to ${CRAWL_OUT:-/dev/null}"
echo "== uninstall"; W plugin deactivate wp-agent-support-layer --quiet; W plugin uninstall wp-agent-support-layer --quiet
echo "options left: $(W option list --search='wpasl_%' --format=count)"
echo "storage left: $(R sh -c 'test -d /var/www/html/wp-content/uploads/wp-agent-support-layer && echo yes || echo no')"
echo "== done"
