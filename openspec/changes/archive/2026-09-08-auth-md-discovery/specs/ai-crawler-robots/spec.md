## MODIFIED Requirements

### Requirement: Grupos en el robots.txt virtual
El robots.txt servido por WordPress SHALL incluir, tras el contenido generado por el núcleo, un grupo `User-agent:` por cada crawler bloqueado con `Disallow: /` y un grupo por cada crawler permitido con `Allow: /`, seguido de una línea de comentario que indique la URL de `/llms.txt` y, cuando `auth.md` esté publicado, de una línea de comentario `# auth.md: <URL absoluta de /auth.md>`; cuando `auth.md` no esté publicado, esa línea MUST NOT aparecer. El bloque que la pestaña Crawlers ofrece para copiar en un robots.txt físico SHALL contener las mismas líneas. El sistema MUST NOT alterar las reglas generadas por el núcleo ni por otros plugins.

#### Scenario: Crawler bloqueado
- **WHEN** GPTBot tiene política `block` y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene un grupo `User-agent: GPTBot` seguido de `Disallow: /`

#### Scenario: Crawler permitido
- **WHEN** PerplexityBot tiene política `allow` y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene un grupo `User-agent: PerplexityBot` seguido de `Allow: /`

#### Scenario: Reglas del núcleo intactas
- **WHEN** un cliente solicita `/robots.txt`
- **THEN** las líneas `Disallow: /wp-admin/` y `Allow: /wp-admin/admin-ajax.php` del núcleo siguen presentes y sin modificar

#### Scenario: Puntero a auth.md
- **WHEN** `auth.md` está publicado y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene la línea `# llms.txt: <URL>` seguida de `# auth.md: <URL absoluta de /auth.md>`

#### Scenario: Sin puntero con auth.md desactivado
- **WHEN** la publicación de `auth.md` está desactivada y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene la línea de `llms.txt` y ninguna línea `# auth.md:`
