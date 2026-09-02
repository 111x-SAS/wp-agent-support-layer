## Purpose

Declara de forma explícita y consistente, en robots.txt, cabeceras HTTP y meta etiquetas, las preferencias del sitio sobre el uso de su contenido por sistemas de IA.

## ADDED Requirements

### Requirement: Ajustes de señales
El sistema SHALL ofrecer tres señales configurables con valores `yes` o `no`: `search` (por defecto `yes`), `ai-input` (por defecto `yes`) y `ai-train` (por defecto `no`). El sistema SHALL ofrecer un interruptor para la cabecera experimental `Content-Usage` (por defecto activado).

#### Scenario: Valores por defecto
- **WHEN** el plugin se activa sin configuración previa
- **THEN** las señales son `search=yes`, `ai-input=yes`, `ai-train=no`

### Requirement: Directiva Content-Signal en robots.txt
El robots.txt servido por WordPress SHALL incluir una línea `Content-Signal:` con las tres señales en el formato `search=yes, ai-input=yes, ai-train=no`, situada dentro del grupo `User-agent: *`, junto con una línea de comentario que referencie la política de Content Signals.

#### Scenario: robots.txt con señales por defecto
- **WHEN** un cliente solicita `/robots.txt` con la configuración por defecto
- **THEN** el grupo `User-agent: *` contiene `Content-Signal: search=yes, ai-input=yes, ai-train=no`

#### Scenario: Cambio de señal
- **WHEN** un administrador cambia `ai-train` a `yes`
- **THEN** la línea `Content-Signal` refleja `ai-train=yes` en la siguiente petición a `/robots.txt`

### Requirement: Cabeceras HTTP de señales
Toda respuesta del front-end del sitio (HTML público, Markdown, llms.txt, manifiestos y robots.txt) SHALL incluir la cabecera `Content-Signal` con las tres señales. Cuando el interruptor experimental esté activado, SHALL incluir además la cabecera `Content-Usage` con `train-ai` y `search` derivados de `ai-train` y `search` usando los valores `y` o `n`. Estas cabeceras MUST NOT enviarse en el área de administración.

#### Scenario: Cabecera en una entrada pública
- **WHEN** un cliente obtiene la página HTML de una entrada publicada
- **THEN** la respuesta incluye `Content-Signal: search=yes, ai-input=yes, ai-train=no` y `Content-Usage: train-ai=n, search=y`

#### Scenario: Cabecera experimental desactivada
- **WHEN** el interruptor de `Content-Usage` está desactivado
- **THEN** la respuesta incluye `Content-Signal` y no incluye `Content-Usage`

#### Scenario: Área de administración
- **WHEN** un usuario carga una pantalla del administrador
- **THEN** la respuesta no incluye `Content-Signal` ni `Content-Usage`

### Requirement: Directivas noai en X-Robots-Tag y meta robots
Cuando `ai-train` sea `no`, las respuestas HTML públicas SHALL incluir `noai` y `noimageai` en la cabecera `X-Robots-Tag` y en la meta etiqueta `robots`, preservando cualquier otra directiva existente. Cuando `ai-train` sea `yes`, el sistema MUST NOT añadir estas directivas.

#### Scenario: Entrenamiento no permitido
- **WHEN** `ai-train=no` y un cliente obtiene una página HTML pública
- **THEN** la cabecera `X-Robots-Tag` y la meta `robots` contienen `noai` y `noimageai`

#### Scenario: Entrenamiento permitido
- **WHEN** `ai-train=yes` y un cliente obtiene una página HTML pública
- **THEN** ni la cabecera `X-Robots-Tag` ni la meta `robots` contienen `noai` o `noimageai`

#### Scenario: Convivencia con directivas existentes
- **WHEN** otro plugin ya añade `max-image-preview:large` a la meta robots
- **THEN** la meta robots resultante contiene tanto `max-image-preview:large` como `noai, noimageai`
