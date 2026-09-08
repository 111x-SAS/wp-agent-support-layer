## ADDED Requirements

### Requirement: Respuesta 404 en Markdown para agentes
Cuando una petición con sufijo `.md` o con el parámetro `wpasl=md` no resuelva a contenido elegible, o cuando una petición cuya cabecera `Accept` prefiera `text/markdown` (con el mismo criterio de preferencia que la negociación de contenido) termine en una respuesta 404 de WordPress, el sistema SHALL responder con código 404, `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, las cabeceras de señales de contenido de las respuestas no HTML (sin `X-Robots-Tag`) y, con los manifiestos habilitados, una única cabecera `Link` con `rel="api-catalog"`. El cuerpo SHALL ser un documento Markdown corto en inglés, generado con valores reales del sitio, con un encabezado de nivel 1 que indique que el recurso no se encontró, una frase que lo explique y una lista de enlaces a `/llms.txt`, a `/auth.md` cuando esté publicado, al sitemap del sitio cuando el sistema pueda determinar su URL, y al catálogo de API cuando los manifiestos estén habilitados. El cuerpo MUST NOT reflejar la URL solicitada ni ningún otro dato de la petición. Un desarrollador SHALL poder modificar el cuerpo mediante un filtro. Las respuestas 404 a peticiones que no cumplan ninguna de las condiciones anteriores (por ejemplo, un navegador con `Accept: text/html`) MUST seguir siendo la página 404 HTML habitual del tema. El sistema MUST NOT interferir con las redirecciones canónicas que WordPress aplica a una URL inexistente antes de responder 404.

#### Scenario: Sufijo .md sobre contenido inexistente
- **WHEN** un cliente solicita `/no-existe.md`
- **THEN** la respuesta es 404 con `Content-Type: text/markdown; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, y el cuerpo contiene un H1 con "not found" y enlaces a `/llms.txt` y al catálogo de API

#### Scenario: Sufijo .md sobre contenido no elegible
- **WHEN** un cliente solicita la URL `.md` de una entrada en borrador
- **THEN** la respuesta es 404 en Markdown con el mismo cuerpo

#### Scenario: Accept que prefiere Markdown en una URL inexistente
- **WHEN** un cliente envía `Accept: text/markdown` a `/no-existe/`
- **THEN** la respuesta es 404 en Markdown con `Vary: Accept`

#### Scenario: Navegador en una URL inexistente
- **WHEN** un cliente envía `Accept: text/html,application/xhtml+xml,*/*;q=0.8` a `/no-existe/` o a `/no-existe.md`
- **THEN** con `/no-existe/` la respuesta es la página 404 HTML del tema, y con `/no-existe.md` la respuesta es 404 en Markdown (el sufijo manda)

#### Scenario: Enlaces según la configuración
- **WHEN** `auth.md` está publicado, los sitemaps del núcleo están habilitados y un cliente solicita `/no-existe.md`
- **THEN** el cuerpo enlaza `/auth.md` y la URL del índice de sitemaps; con `auth.md` desactivado no enlaza `/auth.md`; con los manifiestos deshabilitados no enlaza el catálogo y la respuesta no lleva `Link rel="api-catalog"`

#### Scenario: Sin reflejo de la petición
- **WHEN** un cliente solicita `/<script>alert(1)</script>.md`
- **THEN** el cuerpo del 404 en Markdown no contiene la cadena solicitada

#### Scenario: Cabeceras de señales
- **WHEN** el entrenamiento no está permitido y un cliente solicita `/no-existe.md`
- **THEN** la respuesta contiene `Content-Signal` y no contiene `X-Robots-Tag`, y con los manifiestos habilitados contiene exactamente una cabecera `Link` con `rel="api-catalog"`

#### Scenario: Filtro sobre el cuerpo
- **WHEN** un desarrollador añade una línea mediante el filtro del cuerpo
- **THEN** la respuesta 404 en Markdown contiene esa línea
