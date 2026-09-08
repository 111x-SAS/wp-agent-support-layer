## MODIFIED Requirements

### Requirement: Cabeceras de la respuesta Markdown
Toda respuesta Markdown SHALL incluir `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`, `X-Markdown-Tokens` con una estimación entera del número de tokens, `Link` con la relación `canonical` hacia la URL HTML, `Cache-Control: public` con un `max-age` igual al intervalo de regeneración configurado y `X-Content-Type-Options: nosniff`. La respuesta Markdown MUST NOT incluir la cabecera `X-Robots-Tag` con directivas `noai`, tanto si se obtuvo por negociación de contenido como por la URL alternativa. La cabecera `Link` de la respuesta Markdown MUST NOT reemplazar las cabeceras `Link` que otros componentes del plugin añadan a esa misma respuesta, como la relación `api-catalog`, y las cabeceras `Link` emitidas para la representación HTML antes de que la negociación de contenido eligiera Markdown MUST NOT repetirse en la respuesta Markdown: cada relación anunciada SHALL aparecer una sola vez. Las respuestas HTML de contenido elegible SHALL incluir también `Vary: Accept`, y la cabecera `Link` que el sistema añada a la respuesta HTML MUST NOT reemplazar otras cabeceras `Link` ya enviadas.

#### Scenario: Cabeceras presentes
- **WHEN** un cliente obtiene un documento Markdown
- **THEN** la respuesta contiene las cabeceras `Content-Type`, `Vary`, `X-Markdown-Tokens`, `Link`, `Cache-Control` y `X-Content-Type-Options: nosniff` con los valores especificados

#### Scenario: Estimación de tokens coherente
- **WHEN** el documento Markdown tiene 4000 bytes
- **THEN** `X-Markdown-Tokens` vale 1000

#### Scenario: Sin X-Robots-Tag en Markdown negociado
- **WHEN** el entrenamiento no está permitido y un cliente obtiene el documento Markdown mediante `Accept: text/markdown` en la URL canónica
- **THEN** la respuesta no contiene la cabecera `X-Robots-Tag`

#### Scenario: Cabecera Link adicional preservada
- **WHEN** otro componente ya envió una cabecera `Link` en la respuesta HTML de una entrada elegible
- **THEN** la respuesta contiene ambas cabeceras `Link`

#### Scenario: Cabecera Link del catálogo conservada en Markdown
- **WHEN** el manifiesto está habilitado y un cliente obtiene el documento Markdown de una entrada elegible mediante `Accept: text/markdown` en la URL canónica, mediante la URL con sufijo `.md` o mediante `?wpasl=md`
- **THEN** la respuesta contiene exactamente dos cabeceras `Link`: una con `rel="canonical"` hacia la URL HTML y otra con `rel="api-catalog"` hacia `/.well-known/api-catalog`, sin repeticiones

#### Scenario: Sin cabecera Link del catálogo con el manifiesto deshabilitado
- **WHEN** el manifiesto está deshabilitado y un cliente obtiene el documento Markdown de una entrada elegible
- **THEN** la única cabecera `Link` de la respuesta es la de `rel="canonical"`
