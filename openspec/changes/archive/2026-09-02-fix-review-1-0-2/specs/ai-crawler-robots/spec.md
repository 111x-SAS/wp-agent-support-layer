## MODIFIED Requirements

### Requirement: Detección de robots.txt físico
Cuando exista un archivo `robots.txt` físico en la raíz del sitio, el sistema SHALL mostrar un aviso en la página de ajustes indicando que las reglas no se aplican automáticamente y SHALL ofrecer el bloque generado como texto para copiar. El bloque generado y la vista previa de solo lectura del robots.txt virtual SHALL reproducir exactamente lo que WordPress serviría en `/robots.txt`: las reglas del núcleo (`Disallow` del directorio de administración y `Allow` de `admin-ajax.php`, con rutas derivadas de la URL de administración) seguidas de las reglas del plugin, tanto si el sitio es público como si tiene desactivada la visibilidad para motores de búsqueda. El bloque MUST NOT contener un `Disallow: /` genérico que el núcleo no emita.

#### Scenario: Archivo físico presente
- **WHEN** existe `robots.txt` en la raíz de la instalación
- **THEN** la página de ajustes muestra el aviso y un área de texto con las reglas generadas

#### Scenario: Bloque idéntico al robots.txt virtual
- **WHEN** el sitio es público y se compara el bloque generado con el cuerpo que WordPress sirve en `/robots.txt`
- **THEN** ambos son idénticos

#### Scenario: Sitio no visible para motores de búsqueda
- **WHEN** la opción "Disuadir a los motores de búsqueda" está activada
- **THEN** el bloque generado sigue siendo idéntico al cuerpo servido en `/robots.txt` y no contiene `Disallow: /` bajo `User-agent: *`
