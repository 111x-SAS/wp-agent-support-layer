## MODIFIED Requirements

### Requirement: URL alternativa con sufijo .md
Cuando el sitio use enlaces permanentes bonitos, el sistema SHALL servir el documento Markdown de un contenido elegible en la URL resultante de añadir `.md` a la ruta del enlace permanente, sin importar la cabecera `Accept`. Con enlaces permanentes simples, el sistema SHALL aceptar el parámetro de consulta `wpasl=md` sobre la URL canónica. Cuando el enlace permanente de un contenido elegible sea la raíz del sitio (página configurada como portada estática) o contenga una cadena de consulta (post type público sin reglas de reescritura), la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/.md` SHALL resolver a la portada estática cuando exista. La página configurada como "Página de entradas" (`page_for_posts`) SHALL servirse en Markdown por sufijo `.md`, por negociación `Accept` y por `wpasl=md` cuando sea elegible, exactamente igual que cualquier otra página. Cuando el sitio esté instalado en un subdirectorio, la ruta `.md` SHALL reconocer el path base solo como segmento completo. La ruta exacta `/auth.md` (relativa a la raíz del sitio) SHALL estar reservada para el documento `auth.md` de la capa de agentes: el sufijo `.md` MUST NOT resolverla a ningún contenido, exista o no un contenido con slug `auth` y esté o no publicada `auth.md`; el archivo raíz gana. Para un contenido elegible cuya URL con sufijo `.md` coincida con esa ruta reservada, la URL alternativa anunciada SHALL ser la URL canónica con el parámetro `wpasl=md`, y `/auth/.md` SHALL seguir resolviendo a ese contenido. Una URL `.md` que no corresponda a contenido elegible MUST responder 404. La URL alternativa anunciada en el HTML, en la cabecera `Link`, en `llms.txt` y en el diagnóstico MUST ser siempre una URL que el sistema sirva.

#### Scenario: Sufijo .md sobre enlace permanente bonito
- **WHEN** un cliente solicita `/blog/mi-entrada/.md` o `/blog/mi-entrada.md` para una entrada elegible con enlace permanente `/blog/mi-entrada/`
- **THEN** el sistema responde 200 con el documento Markdown

#### Scenario: Sufijo .md sobre contenido inexistente
- **WHEN** un cliente solicita `/no-existe.md`
- **THEN** el sistema responde 404

#### Scenario: Parámetro de consulta con enlaces simples
- **WHEN** el sitio usa enlaces permanentes simples y un cliente solicita `/?p=12&wpasl=md` para una entrada elegible
- **THEN** el sistema responde 200 con el documento Markdown

#### Scenario: Portada estática
- **WHEN** una página elegible está configurada como portada estática y el sitio usa enlaces permanentes bonitos
- **THEN** la URL alternativa anunciada es `https://example.com/?wpasl=md`, esa URL responde 200 con el documento Markdown y `/.md` responde 200 con el mismo documento

#### Scenario: Página de entradas
- **WHEN** el sitio muestra una portada estática, otra página elegible con enlace permanente `/blog/` está configurada como "Página de entradas" y un cliente solicita `/blog.md`, `/blog/` con `Accept: text/markdown` o `/blog/?wpasl=md`
- **THEN** el sistema responde 200 con el documento Markdown de esa página, cuyo título encabeza el documento, y `llms.txt` enlaza a `https://example.com/blog.md`

#### Scenario: Post type sin reglas de reescritura
- **WHEN** un ítem elegible pertenece a un post type público cuyo enlace permanente es `/?post_type=doc&p=7`
- **THEN** la URL alternativa anunciada es `/?post_type=doc&p=7&wpasl=md` y responde 200 con el documento Markdown

#### Scenario: Raíz sin portada estática
- **WHEN** el sitio muestra las últimas entradas en la portada y un cliente solicita `/.md`
- **THEN** el sistema responde 404

#### Scenario: Instalación en subdirectorio
- **WHEN** el sitio está instalado en `https://example.com/blog/`, existe una entrada elegible con enlace permanente `/blog/x/` y un cliente solicita `/blogx.md`
- **THEN** el sistema responde 404 y `/blog/x.md` responde 200

#### Scenario: Ruta reservada para auth.md
- **WHEN** existe una página elegible con enlace permanente `/auth/` y un cliente solicita `/auth.md`
- **THEN** el sistema no sirve el documento Markdown de la página: con `auth.md` publicado responde el documento `auth.md` del sitio y con `auth.md` desactivado responde 404

#### Scenario: URL alternativa de un contenido que colisiona con auth.md
- **WHEN** existe una página elegible con enlace permanente `https://example.com/auth/` y el sitio usa enlaces permanentes bonitos
- **THEN** la URL alternativa anunciada en el HTML, en la cabecera `Link` y en `llms.txt` es `https://example.com/auth/?wpasl=md`, esa URL responde 200 con el documento Markdown de la página y `/auth/.md` responde 200 con el mismo documento

#### Scenario: Ruta reservada en subdirectorio
- **WHEN** el sitio está instalado en `https://example.com/blog/`, `auth.md` está publicado y un cliente solicita `/blog/auth.md`
- **THEN** la respuesta es el documento `auth.md` del sitio, y `/auth.md` fuera del path base no lo es
