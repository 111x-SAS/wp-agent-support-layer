## MODIFIED Requirements

### Requirement: Cabeceras HTTP de señales
Toda respuesta del front-end del sitio (HTML público, Markdown, llms.txt, manifiestos incluido el documento OpenAPI servido por la API REST, y robots.txt) SHALL incluir la cabecera `Content-Signal` con las tres señales. Cuando el interruptor experimental esté activado, SHALL incluir además la cabecera `Content-Usage` con `train-ai` y `search` derivados de `ai-train` y `search` usando los valores `y` o `n`. Cuando el manifiesto esté habilitado, las respuestas HTML públicas y Markdown SHALL incluir además una cabecera `Link` con la relación `api-catalog` hacia `/.well-known/api-catalog`, sin reemplazar otras cabeceras `Link`; en la respuesta Markdown esa cabecera SHALL llegar al cliente junto con la cabecera `Link` de la relación `canonical`, sea cual sea la ruta por la que se obtuvo el documento. Cuando el manifiesto esté deshabilitado, ninguna respuesta MUST anunciar el catálogo. Estas cabeceras MUST NOT enviarse en el área de administración.

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

#### Scenario: Descubrimiento del catálogo de API en Markdown
- **WHEN** el manifiesto está habilitado y un cliente obtiene el documento Markdown de una entrada publicada, por negociación `Accept` o por la URL alternativa
- **THEN** la respuesta incluye `Content-Signal`, una cabecera `Link` con `rel="api-catalog"` hacia `/.well-known/api-catalog` y la cabecera `Link` con `rel="canonical"`, y el catálogo aparece una sola vez

#### Scenario: Catálogo no anunciado en Markdown con el manifiesto deshabilitado
- **WHEN** el manifiesto está deshabilitado y un cliente obtiene el documento Markdown de una entrada publicada
- **THEN** la respuesta incluye `Content-Signal` y ninguna cabecera `Link` con `rel="api-catalog"`
