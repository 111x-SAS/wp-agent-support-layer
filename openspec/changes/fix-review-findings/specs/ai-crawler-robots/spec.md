## MODIFIED Requirements

### Requirement: Catálogo de crawlers de IA
El sistema SHALL mantener un catálogo de user-agents de crawlers de IA con proveedor, propósito (`training`, `search` o `agent`) y enlace a la documentación oficial, ampliable por desarrolladores mediante un filtro. El catálogo inicial SHALL incluir al menos GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Bytespider, Meta-ExternalAgent, Meta-ExternalFetcher, Amazonbot, cohere-ai, Diffbot, DuckAssistBot, YouBot y MistralAI-User. Un token de user-agent añadido por filtro MUST descartarse si contiene espacios, `#`, `:`, `.`, `[` o `]`, ya que no podría representarse en robots.txt ni en el formulario de sobrescrituras.

#### Scenario: Catálogo ampliado por filtro
- **WHEN** un desarrollador añade un user-agent mediante el filtro del catálogo
- **THEN** el user-agent aparece en la página de ajustes y en el robots.txt generado con la política de su grupo

#### Scenario: Token con caracteres no admitidos
- **WHEN** un desarrollador añade mediante el filtro el token `foo.bar`
- **THEN** el token no aparece en el catálogo, en la página de ajustes ni en el robots.txt generado
