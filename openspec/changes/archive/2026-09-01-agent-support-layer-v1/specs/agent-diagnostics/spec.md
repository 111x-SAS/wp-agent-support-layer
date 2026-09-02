## Purpose

Permite al administrador comprobar desde el propio sitio cómo lo ven los crawlers de IA reales y qué debe revisar en la infraestructura, sin modificar nada ni contactar servicios externos.

## ADDED Requirements

### Requirement: Ejecución de la prueba de rastreo
La página de diagnóstico SHALL ofrecer una acción que realice peticiones HTTP desde el servidor a URLs del propio sitio (portada, una entrada elegible de muestra, su URL `.md`, `/robots.txt`, `/llms.txt`, `/agent-skills.json` y `/.well-known/api-catalog`) usando los user-agents del catálogo de crawlers y las cabeceras `Accept: text/markdown` y `Accept: text/html`. La acción MUST requerir `manage_options` y nonce válido, MUST limitar cada petición a 10 segundos y MUST NOT contactar ningún host distinto del propio sitio.

#### Scenario: Ejecución completa
- **WHEN** un administrador lanza la prueba
- **THEN** se obtiene un resultado por cada combinación de URL y user-agent con código HTTP, `Content-Type` y cabeceras relevantes

#### Scenario: Sin permisos
- **WHEN** un usuario sin `manage_options` intenta lanzar la prueba
- **THEN** el sistema rechaza la acción

### Requirement: Informe por crawler
El informe SHALL mostrar, por cada crawler, el veredicto de robots.txt para su user-agent, si obtuvo la portada y la entrada de muestra, si recibió Markdown al pedir `text/markdown`, y si las cabeceras `Content-Signal`, `X-Robots-Tag` y `Link rel="alternate"` estaban presentes. Cada comprobación SHALL etiquetarse como correcta, advertencia o error, y el informe SHALL guardarse durante una hora para consultarlo sin repetir las peticiones.

#### Scenario: Crawler bloqueado en robots.txt
- **WHEN** GPTBot tiene política `block`
- **THEN** el informe muestra para GPTBot el veredicto "bloqueado por robots.txt" como comprobación correcta, coherente con la configuración

#### Scenario: Markdown no servido
- **WHEN** la petición con `Accept: text/markdown` a la entrada de muestra devuelve HTML
- **THEN** el informe marca la comprobación de negociación de contenido como error e indica que una caché o CDN puede estar interfiriendo

### Requirement: Detección de CDN y advertencias de infraestructura
El informe SHALL indicar si las respuestas provienen de un CDN o proxy conocido a partir de cabeceras como `cf-ray`, `server` o `x-cache`, y SHALL advertir cuando detecte que Cloudflare ya convierte a Markdown en el borde. El informe SHALL comprobar si los archivos del almacenamiento son accesibles por acceso directo y marcarlo como error si lo son.

#### Scenario: Cloudflare detectado
- **WHEN** las respuestas incluyen la cabecera `cf-ray`
- **THEN** el informe indica "Cloudflare detectado" y muestra la lista de verificación de WAF y rate-limiting

#### Scenario: Almacenamiento expuesto
- **WHEN** la petición directa a un documento almacenado devuelve 200
- **THEN** el informe marca "almacenamiento accesible públicamente" como error con la instrucción de corrección para el servidor web

### Requirement: Lista de verificación de infraestructura y comandos externos
La página SHALL mostrar una lista de verificación estática para la auditoría de WAF, rate-limiting y filtrado de IP orientada a crawlers verificados, y SHALL mostrar comandos `curl` equivalentes a las pruebas realizadas para que puedan repetirse desde fuera del servidor.

#### Scenario: Comandos disponibles
- **WHEN** un administrador abre la página de diagnóstico
- **THEN** ve la lista de verificación y al menos un comando `curl` por crawler con su user-agent y la cabecera `Accept` correspondiente
