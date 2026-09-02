## Purpose

Expresa en robots.txt reglas granulares por user-agent para los crawlers de IA conocidos, agrupados por propósito, de modo que la intención del sitio deje de depender de la regla genérica.

## ADDED Requirements

### Requirement: Catálogo de crawlers de IA
El sistema SHALL mantener un catálogo de user-agents de crawlers de IA con proveedor, propósito (`training`, `search` o `agent`) y enlace a la documentación oficial, ampliable por desarrolladores mediante un filtro. El catálogo inicial SHALL incluir al menos GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Bytespider, Meta-ExternalAgent, Meta-ExternalFetcher, Amazonbot, cohere-ai, Diffbot, DuckAssistBot, YouBot y MistralAI-User.

#### Scenario: Catálogo ampliado por filtro
- **WHEN** un desarrollador añade un user-agent mediante el filtro del catálogo
- **THEN** el user-agent aparece en la página de ajustes y en el robots.txt generado con la política de su grupo

### Requirement: Política por crawler con valores por defecto derivados de las señales
Cada crawler SHALL tener una política `allow` o `block`. El valor por defecto de cada grupo SHALL derivarse de las señales de contenido: el grupo `training` se bloquea cuando `ai-train=no` y se permite en caso contrario; los grupos `search` y `agent` se permiten cuando `search=yes` y `ai-input=yes` respectivamente. Un administrador SHALL poder sobrescribir la política de un crawler individual.

#### Scenario: Defaults con señales por defecto
- **WHEN** las señales son `search=yes`, `ai-input=yes`, `ai-train=no` y no hay sobrescrituras
- **THEN** GPTBot, CCBot y ClaudeBot quedan en `block`, y OAI-SearchBot, PerplexityBot y ChatGPT-User quedan en `allow`

#### Scenario: Sobrescritura individual
- **WHEN** un administrador marca GPTBot como `allow` manteniendo `ai-train=no`
- **THEN** el robots.txt generado permite GPTBot y sigue bloqueando el resto del grupo `training`

### Requirement: Grupos en el robots.txt virtual
El robots.txt servido por WordPress SHALL incluir, tras el contenido generado por el núcleo, un grupo `User-agent:` por cada crawler bloqueado con `Disallow: /` y un grupo por cada crawler permitido con `Allow: /`, seguido de una línea de comentario que indique la URL de `/llms.txt`. El sistema MUST NOT alterar las reglas generadas por el núcleo ni por otros plugins.

#### Scenario: Crawler bloqueado
- **WHEN** GPTBot tiene política `block` y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene un grupo `User-agent: GPTBot` seguido de `Disallow: /`

#### Scenario: Crawler permitido
- **WHEN** PerplexityBot tiene política `allow` y un cliente solicita `/robots.txt`
- **THEN** la respuesta contiene un grupo `User-agent: PerplexityBot` seguido de `Allow: /`

#### Scenario: Reglas del núcleo intactas
- **WHEN** un cliente solicita `/robots.txt`
- **THEN** las líneas `Disallow: /wp-admin/` y `Allow: /wp-admin/admin-ajax.php` del núcleo siguen presentes y sin modificar

### Requirement: Detección de robots.txt físico
Cuando exista un archivo `robots.txt` físico en la raíz del sitio, el sistema SHALL mostrar un aviso en la página de ajustes indicando que las reglas no se aplican automáticamente y SHALL ofrecer el bloque generado como texto para copiar.

#### Scenario: Archivo físico presente
- **WHEN** existe `robots.txt` en la raíz de la instalación
- **THEN** la página de ajustes muestra el aviso y un área de texto con las reglas generadas
