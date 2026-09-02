## Why

Los agentes y crawlers de IA (ChatGPT, Claude, Perplexity, Gemini, Copilot) no encuentran en un sitio WordPress estándar ni una versión legible del contenido, ni una declaración de permisos de uso, ni un índice curado ni un manifiesto de capacidades. El sitio de CognosOnline necesita ser "agent-ready" y no existe un plugin en WordPress.org que cubra estas acciones de forma integrada, declarativa y sin depender de la infraestructura.

## What Changes

- Nuevo plugin **WP Agent Support Layer** (`wp-agent-support-layer`, prefijo `wpasl_`) para WordPress 7.0+ y PHP 7.4+, publicable en el repositorio oficial de WordPress.org y mantenido en GitHub.
- Entrega de contenido en **Markdown** para los post types elegidos (por defecto `post` y `page`): negociación de contenido con `Accept: text/markdown`, URL alternativa `.md`, `<link rel="alternate">` de descubrimiento y cabeceras informativas.
- **Generación programada** (WP-Cron) de los documentos Markdown y de los archivos de descubrimiento, con caché en disco al estilo de los plugins de sitemaps: nunca se regenera al guardar una entrada.
- **Content Signals**: declaración de preferencias `search`, `ai-input`, `ai-train` en robots.txt, cabeceras HTTP (`Content-Signal`, `Content-Usage` del borrador IETF AIPREF), `X-Robots-Tag` y meta robots.
- **Reglas para crawlers de IA** en el robots.txt virtual de WordPress, con lista curada de user-agents agrupados por propósito (entrenamiento, búsqueda, agentes bajo demanda).
- **llms.txt** en la raíz del dominio con el índice curado del sitio, y `llms-full.txt` opcional.
- **Manifiestos de agente**: `agent-skills.json` (JSON-LD) con las capacidades públicas declaradas, catálogo OpenAPI 3.1 de los endpoints REST públicos relevantes y `/.well-known/api-catalog` (RFC 9727).
- **Panel de diagnóstico** en Herramientas que simula crawlers de IA reales contra el propio sitio (acción 8 del documento) y muestra una lista de verificación para la auditoría de WAF/CDN (acción 4). Solo lectura; sin llamadas a servicios externos.
- **Página de ajustes** bajo Herramientas con las opciones mínimas necesarias y valores por defecto seguros, más comandos WP-CLI para operar la generación.

Fuera de alcance de este cambio: la acción 7 (scanner externo de agent-readiness), la modificación de reglas WAF en Cloudflare u otros proveedores, el bloqueo activo de crawlers a nivel de servidor y la implementación funcional de acciones de negocio (agendar citas, descargar brochures).

## Capabilities

### New Capabilities
- `markdown-delivery`: servir la versión Markdown de entradas y páginas públicas mediante negociación de contenido, URL `.md` y enlaces de descubrimiento.
- `scheduled-generation`: motor de generación programada y por lotes de los documentos Markdown y archivos de descubrimiento, con regeneración manual, WP-CLI y limpieza de contenido no elegible.
- `content-signals`: declaración de preferencias de uso del contenido por IA en robots.txt, cabeceras HTTP y meta etiquetas.
- `ai-crawler-robots`: reglas específicas para user-agents de crawlers de IA en el robots.txt virtual de WordPress.
- `llms-txt`: publicación de `/llms.txt` (y `/llms-full.txt` opcional) con el índice estructurado del sitio.
- `agent-manifest`: publicación de `/agent-skills.json`, del documento OpenAPI 3.1 y de `/.well-known/api-catalog`.
- `agent-diagnostics`: pruebas de rastreo simuladas desde el propio servidor y lista de verificación de infraestructura.
- `admin-settings`: página de ajustes en Herramientas, exclusión por entrada, activación/desactivación/desinstalación limpias y requisitos mínimos.

### Modified Capabilities
<!-- Ninguna: el proyecto no tiene specs previas. -->

## Impact

- Código nuevo: plugin completo en PHP 7.4+ con namespace `WPASL`, sin JavaScript compilado.
- Dependencia de producción: `league/html-to-markdown` (MIT), empaquetada con namespace prefijado para evitar conflictos con otros plugins.
- Dependencias de desarrollo: PHPUnit 9 con `yoast/phpunit-polyfills`, WordPress Coding Standards, PHPCompatibilityWP, Plugin Check.
- Superficie pública del sitio: nuevas respuestas en `/llms.txt`, `/llms-full.txt`, `/agent-skills.json`, `/.well-known/api-catalog`, `/wp-json/wpasl/v1/openapi`, URLs `*.md`, líneas nuevas en `/robots.txt` y cabeceras HTTP adicionales en el front-end.
- Almacenamiento: opciones en `wp_options`, un post meta de exclusión, archivos generados en el directorio de uploads del sitio, un evento WP-Cron.
- Infraestructura: cachés de página y CDN deben respetar `Vary: Accept`; si Cloudflare "Markdown for Agents" está activo, ambas conversiones coexisten y el diagnóstico lo advierte.
