## MODIFIED Requirements

### Requirement: Cabeceras HTTP de señales
Toda respuesta del front-end del sitio (HTML público, Markdown, llms.txt, manifiestos incluido el documento OpenAPI servido por la API REST, y robots.txt) SHALL incluir la cabecera `Content-Signal` con las tres señales. Cuando el interruptor experimental esté activado, SHALL incluir además la cabecera `Content-Usage` con `train-ai` y `search` derivados de `ai-train` y `search` usando los valores `y` o `n`. Cuando el manifiesto esté habilitado, las respuestas HTML públicas y Markdown SHALL incluir además una cabecera `Link` con la relación `api-catalog` hacia `/.well-known/api-catalog`, sin reemplazar otras cabeceras `Link`. Estas cabeceras MUST NOT enviarse en el área de administración.

#### Scenario: Cabecera en una entrada pública
- **WHEN** un cliente obtiene la página HTML de una entrada publicada
- **THEN** la respuesta incluye `Content-Signal: search=yes, ai-input=yes, ai-train=no` y `Content-Usage: train-ai=n, search=y`

#### Scenario: Cabecera experimental desactivada
- **WHEN** el interruptor de `Content-Usage` está desactivado
- **THEN** la respuesta incluye `Content-Signal` y no incluye `Content-Usage`

#### Scenario: Área de administración
- **WHEN** un usuario carga una pantalla del administrador
- **THEN** la respuesta no incluye `Content-Signal` ni `Content-Usage`

#### Scenario: Documento OpenAPI por REST
- **WHEN** un cliente solicita el documento OpenAPI en su ruta REST
- **THEN** la respuesta incluye `Content-Signal` y, con el interruptor activado, `Content-Usage`

#### Scenario: Descubrimiento del catálogo de API
- **WHEN** el manifiesto está habilitado y un cliente obtiene la página HTML de una entrada publicada
- **THEN** la respuesta incluye una cabecera `Link` con `rel="api-catalog"` hacia `/.well-known/api-catalog` junto con las demás cabeceras `Link`

### Requirement: Directivas noai en X-Robots-Tag y meta robots
Cuando `ai-train` sea `no`, las respuestas HTML públicas SHALL incluir `noai` y `noimageai` en la cabecera `X-Robots-Tag` y en la meta etiqueta `robots`, preservando cualquier otra directiva existente. La cabecera `X-Robots-Tag` con estas directivas MUST NOT añadirse a respuestas que no sean HTML: Markdown, `/robots.txt`, feeds ni sitemaps. Cuando `ai-train` sea `yes`, el sistema MUST NOT añadir estas directivas.

#### Scenario: Entrenamiento no permitido
- **WHEN** `ai-train=no` y un cliente obtiene una página HTML pública
- **THEN** la cabecera `X-Robots-Tag` y la meta `robots` contienen `noai` y `noimageai`

#### Scenario: Entrenamiento permitido
- **WHEN** `ai-train=yes` y un cliente obtiene una página HTML pública
- **THEN** ni la cabecera `X-Robots-Tag` ni la meta `robots` contienen `noai` o `noimageai`

#### Scenario: Convivencia con directivas existentes
- **WHEN** otro plugin ya añade `max-image-preview:large` a la meta robots
- **THEN** la meta robots resultante contiene tanto `max-image-preview:large` como `noai, noimageai`

#### Scenario: Respuestas no HTML
- **WHEN** `ai-train=no` y un cliente solicita `/robots.txt`, el feed principal o el índice de sitemaps
- **THEN** la respuesta incluye `Content-Signal` y no incluye una cabecera `X-Robots-Tag` con `noai`
