## Context

Ver `proposal.md` para la motivación y los deltas en `specs/` para el comportamiento exigido. Estado actual verificado en este worktree (`1f3b83b`):

- `ContentSignals::register()` engancha `send()` a `send_headers` y a `wpasl_before_serve` (`src/Signals/ContentSignals.php:47-48`). Con `manifest_enabled`, `send()` añade `Link: </.well-known/api-catalog>; rel="api-catalog"` con `replace = false` tanto en la pasada HTML (`send_headers`) como en el contexto `markdown` (`:156-159`).
- `Delivery::serve()` (`src/Markdown/Delivery.php:413-445`) dispara `wpasl_before_serve` y **después** recorre `markdown_headers()` con `Http::send_header( $name, $value, 'Vary' !== $name )` (`:430-432`). `Link: <permalink>; rel="canonical"` se envía con `replace = true`, de modo que PHP descarta toda cabecera `Link` anterior, incluida la del catálogo enviada un instante antes por las señales. En cambio, `send_html_headers()` (`:327-336`) envía sus cabeceras con `replace = false` y la spec ya exige que la respuesta HTML no reemplace otras `Link`.
- Hay dos rutas hasta `serve()` con estados de cabeceras distintos. La negociación por `Accept` y `?wpasl=md` sobre una URL normal se resuelven en `maybe_serve()` (`template_redirect`, prioridad 1), cuando `send_headers` ya emitió la pasada HTML: `Content-Signal`, `Content-Usage`, `X-Robots-Tag` y el `Link` del catálogo. `send_html_headers()` (prioridad 2) y el `Link` de la API REST del núcleo (prioridad 11) todavía no han corrido. El sufijo `.md` y `?wpasl=md` sobre la portada estática se resuelven en `handle_md_suffix()` (`parse_request`), antes de `send_headers`, sin pasada HTML previa. La evidencia de producción (`/?wpasl=md` en un sitio con portada estática) corresponde a la segunda ruta.
- Consecuencia: en la ruta negociada, con `replace = false` sin más, la respuesta Markdown llevaría el `Link` del catálogo dos veces (pasada HTML más contexto `markdown`). Hoy el `replace = true` del canónico "limpia" esa duplicación por accidente, a costa de perder el catálogo.
- `ContentSignals::send( 'markdown' )` ya resuelve el mismo problema para `X-Robots-Tag`: elimina la cabecera de la pasada HTML antes de enviar el conjunto Markdown (`:148-152`).
- `Http::send_header()`/`remove_header()` registran cada operación y `Http::effective_headers()` la reproduce (`src/Http.php`). En PHPUnit `headers_sent()` es `true`, así que ese registro es lo único que los tests pueden inspeccionar; `Http::reset()` lo vacía. `Test_Delivery` ya sigue el patrón completo `go_to()` (dispara `send_headers`) + `maybe_serve()` con `wpasl_terminate_after_serve` en `false` (`test_negotiated_markdown_has_no_x_robots_tag`), y `go_to( '/slug.md' )` ejercita la ruta `parse_request`.
- `wpasl_before_serve` es una acción pública documentada en `readme.txt` y `README.md` ("se dispara justo antes de enviar un documento; los módulos añaden cabeceras aquí"). `LlmsTxtRouter` y `ManifestRouter` la disparan también antes de sus propias cabeceras, que no incluyen `Link`.

Restricciones: PHP 7.4+, WordPress 7.0+, sin dependencias nuevas, WPCS limpio, sin opción nueva en admin, sin bump de versión ni cambio en el changelog de `readme.txt`, sin alterar la firma ni el orden relativo de la acción `wpasl_before_serve`.

## Goals / Non-Goals

**Goals:**

- Que la respuesta Markdown contenga exactamente un `Link rel="api-catalog"` y un `Link rel="canonical"` con el manifiesto habilitado, por las tres rutas (`Accept`, `.md`, `?wpasl=md`), y solo el canónico con el manifiesto deshabilitado.
- Que un test de integración reproduzca el fallo atravesando `Delivery::serve()` con las señales registradas, y falle contra el código actual.
- Cambio mínimo y local en `Delivery::serve()`, sin tocar `ContentSignals`, `Http` ni los otros routers.

**Non-Goals:**

- Conservar en la respuesta Markdown cabeceras `Link` de terceros emitidas para la representación HTML (hoy tampoco se conservan).
- Deduplicar cabeceras de forma genérica en `Http`.
- Cambiar el momento en que se dispara `wpasl_before_serve` o su documentación.
- Publicar una versión nueva; ese paso se decidirá en otro cambio.

## Decisions

### D1. `Delivery::serve()` es el dueño del conjunto `Link` de la respuesta Markdown

En `serve()`, justo antes de `do_action( 'wpasl_before_serve', 'markdown', $post )`, se elimina la cabecera `Link` acumulada hasta ese momento con `Http::remove_header( 'Link' )`, con un comentario paralelo al de `ContentSignals`: la pasada HTML de `send_headers` pudo anunciar ya el catálogo para la representación HTML y la respuesta Markdown construye su propio conjunto. A continuación, el bucle de cabeceras envía `Link` con `replace = false`, igual que `Vary` (por ejemplo, `! in_array( $name, array( 'Vary', 'Link' ), true )`). Orden resultante: el hook añade `api-catalog`, `serve()` añade `canonical`.

- Alternativa descartada: solo `replace = false` en `Link`. Resuelve la ruta `.md`, pero en la ruta negociada duplica el catálogo (pasada HTML más contexto `markdown`). Una cabecera repetida es válida en HTTP pero contradice la spec ("una sola vez") y ensucia la salida.
- Alternativa descartada: disparar `wpasl_before_serve` después de las cabeceras de `Delivery`. Corrige el bug con una sola línea, pero cambia la semántica observable de una acción pública (los listeners pasarían a poder sobrescribir `Content-Type`, `Cache-Control`, etc.) y `LlmsTxtRouter`/`ManifestRouter` seguirían el orden contrario.
- Alternativa descartada: que `ContentSignals::send( 'markdown' )` elimine `Link` antes de añadir el catálogo. Las señales no son dueñas de `Link` y borrarían cabeceras que no emitieron; además `header_remove()` no permite quitar un único valor.
- Efecto colateral aceptado: una cabecera `Link` de terceros enviada durante `send_headers` no llega a la respuesta Markdown. Es el comportamiento actual (`replace = true` ya la descartaba) y el documento Markdown no es la representación para la que se emitió. Un tercero que añada `Link` en `wpasl_before_serve` con `replace = false` sí la conserva ahora, que es lo que la documentación del hook promete.

### D2. Test de integración en `Test_Delivery`, no en `Test_Content_Signals`

El test que reproduce el bug vive en `tests/test-delivery.php`, junto a `test_negotiated_markdown_has_no_x_robots_tag`, porque necesita `Delivery::serve()` con `ContentSignals` registrado por `Plugin::boot()`: `Http::reset()`, `go_to( permalink )` con `Accept: text/markdown` (dispara `send_headers`, se comprueba que la pasada HTML ya anunció el catálogo), `maybe_serve()` dentro de `ob_start()`, y aserción estricta `assertSame( array( <catálogo>, <canónico> ), Http::effective_headers()['link'] )`. La misma aserción se repite para `go_to( '/slug.md' )` y para `?wpasl=md` sobre una portada estática (la ruta de la evidencia de producción). Un segundo test deshabilita el manifiesto (`update_option( Settings::OPTION, array( 'manifest_enabled' => false ) )` + `flush_cache()`) y espera `array( <canónico> )`. El test unitario existente en `Test_Content_Signals` no cambia: sigue cubriendo que las señales nunca reemplazan `Link`.

- Alternativa descartada: ampliar `test_html_and_markdown_announce_api_catalog_link` llamando a `serve()` desde `Test_Content_Signals`. Mezcla responsabilidades y esa clase no prepara permalinks ni el filtro `wpasl_terminate_after_serve`.

### D3. Cobertura documentada sin tocar `readme.txt`

`docs/spec-coverage.md` gana las filas de los escenarios nuevos en `markdown-delivery` y `content-signals`, y la fila existente "Descubrimiento del catálogo de API" pasa a citar también el test de integración. La cabecera del documento menciona este cambio entre los deltas incluidos. El changelog de `readme.txt` no se toca en este cambio.

## Risks / Trade-offs

- [`Http::remove_header( 'Link' )` elimina también cabeceras `Link` de terceros emitidas antes de `serve()`] → Comportamiento idéntico al actual (`replace = true`); documentado en D1 y fuera del alcance de la spec, que solo protege las `Link` añadidas a la propia respuesta Markdown.
- [En PHPUnit `headers_sent()` es `true` y la corrección solo se observa en el registro de `Http`] → El registro reproduce fielmente `header()`/`header_remove()` (`effective_headers()`), y el mismo mecanismo ya valida `X-Robots-Tag`; la comprobación real en producción se hace con `curl -sI` tras el despliegue (ver plan de migración).
- [El test nuevo podría pasar contra el código actual si la aserción no fuera estricta] → Se exige igualdad exacta del array de `Link` (orden y cardinalidad), y la tarea pide ejecutar el test contra el código sin corregir para confirmar que falla.

## Migration Plan

Sin migración de datos ni cambio de opciones. Despliegue como cualquier parche: tras publicar, `curl -sI https://cognosonline.com/?wpasl=md` y `curl -sI -H 'Accept: text/markdown' https://cognosonline.com/<entrada>/` deben devolver dos cabeceras `link` (`canonical` y `api-catalog`). Rollback: revertir el commit; el único efecto es volver a perder el catálogo en Markdown.
