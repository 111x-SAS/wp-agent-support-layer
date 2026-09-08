## Why

Desde 1.0.3 el plugin anuncia el catálogo de API (RFC 9727) con `Link: </.well-known/api-catalog>; rel="api-catalog"` en las respuestas HTML y Markdown cuando el manifiesto está habilitado, pero la respuesta Markdown lo pierde: el envío de las cabeceras propias del documento reemplaza la cabecera `Link` que las señales de contenido acababan de añadir. En producción, `curl -sI https://cognosonline.com/?wpasl=md` devuelve únicamente `link: <https://cognosonline.com/>; rel="canonical"`, mientras que la misma URL en HTML trae ambos enlaces. El test existente no lo detecta porque ejecuta las señales de contenido aisladas, sin pasar por la entrega del documento. Un agente que llegue por Markdown no descubre el catálogo, que es justo el público objetivo de esa cabecera.

## What Changes

- La respuesta Markdown conserva la cabecera `Link` con `rel="api-catalog"` junto con `Link` `rel="canonical"` cuando el manifiesto está habilitado, y no la incluye cuando está deshabilitado. El catálogo aparece una sola vez aunque la negociación de contenido ocurra después de que la respuesta HTML ya lo hubiera anunciado.
- La cabecera `Link` de la respuesta Markdown deja de reemplazar las cabeceras `Link` que otros componentes añaden a esa misma respuesta (mismo criterio que `Vary` y que la respuesta HTML), y las cabeceras `Link` emitidas para la representación HTML antes de la negociación no se arrastran a la respuesta Markdown.
- Test de integración que reproduce el fallo pasando por la entrega completa del documento (negociación por `Accept`, sufijo `.md` y `?wpasl=md`), con el manifiesto habilitado y deshabilitado.
- `docs/spec-coverage.md` incorpora los escenarios nuevos.

Sin cambios **BREAKING**: no hay opciones nuevas, no cambia ninguna URL, filtro ni acción, y no se modifica la versión ni el changelog de `readme.txt` (la publicación se decidirá aparte).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `markdown-delivery`: el requisito de cabeceras de la respuesta Markdown exige que su `Link` no reemplace las cabeceras `Link` añadidas por otros componentes a la misma respuesta y que el catálogo de API se conserve una sola vez.
- `content-signals`: el requisito de cabeceras HTTP de señales añade los escenarios de descubrimiento del catálogo en la respuesta Markdown (manifiesto habilitado y deshabilitado).

## Impact

- **Código:** `src/Markdown/Delivery.php` (`serve()`); sin cambios en `src/Signals/ContentSignals.php` ni en `src/Http.php`.
- **Tests:** casos nuevos en `tests/test-delivery.php` que atraviesan `Delivery::serve()` con las señales de contenido registradas; `tests/test-content-signals.php` se mantiene.
- **Docs:** `docs/spec-coverage.md`. `readme.txt` y `README.md` no cambian.
- **Datos, dependencias, admin:** sin migración, sin dependencias nuevas, sin opciones nuevas.
