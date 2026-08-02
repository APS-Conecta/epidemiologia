# Epidemiología

App de Nextcloud 34 para APS Conecta Gestión. Muestra dentro de la intranet la información
epidemiológica que el MINSAL publica, y enlaza lo que no se puede mostrar.

Cuatro vistas:

| Vista | Qué es | De dónde sale |
| --- | --- | --- |
| **Alertas vigentes** | Los oficios en vigencia, filtrables por texto y por año | REST público de `epi.minsal.cl` |
| **Informe semanal** | El informe ETI/IRAG de la semana epidemiológica en curso | REST público de `epi.minsal.cl` |
| **Tablero MINSAL** | El tablero nacional, incrustado | Power BI *«Publish to web»* |
| **EPIVIGILA** | Qué es, cómo entrar y el estado real de acceso | Enlace, nunca incrustado |

Solo lee y enlaza información pública. **No copia ni rehospeda documentos del Estado, no crea
tablas en la base de datos y no toca información de pacientes.**

## Cómo funciona

Un *proxy* de lectura con caché, sin base de datos:

- Las páginas se resuelven **por slug, nunca por ID** — los IDs cambian cuando MINSAL republica.
- Los PDF se **leen de los enlaces, nunca se construyen**: la carpeta de subida no se deduce de la
  semana epidemiológica (SE 29 está en `2026/07`, SE 24 en `2026/06`).
- La caché se refresca cada hora, pero **una lectura que devuelve cero elementos es un fallo, nunca
  un resultado**: no se sobrescribe la caché, se sigue mostrando la última copia buena con un aviso
  de *problemas técnicos*, y se avisa a quien administra. Sin esa regla, un cambio en la página de
  MINSAL reemplazaría 75 alertas reales por una lista vacía que un clínico lee como
  «no hay alertas vigentes».
- Un trabajo diario a las 03:00 pregunta solo por la fecha de modificación (**859 bytes** frente a
  44 KB) para **detectar** que MINSAL cambió. Existe para detectar, no para calentar la caché.

## Para otras apps

Dos rutas OCS de solo lectura. **La cabecera `OCS-APIRequest` no es opcional**: sin ella Nextcloud
responde `412` antes de llegar al controlador.

```
GET /ocs/v2.php/apps/epidemiologia/api/v1/alerts?format=json
GET /ocs/v2.php/apps/epidemiologia/api/v1/reports?format=json
OCS-APIRequest: true

{ "ocs": { "meta": { "status": "ok", "statuscode": 200 },
           "data": { "updated_at": "2026-07-31T22:15:04",
                     "degraded": false,
                     "items": [ { "title": "OFICIO CP N°15844 / 2026 …",
                                  "url": "https://…pdf", "year": "2026" } ] } } }
```

Los datos van dentro de `ocs.data`. Con `format=xml` sale lo mismo en XML, gratis. Las alertas
traen además `month`; los informes, `se` (semana epidemiológica).

**El contrato está en [`openapi.json`](openapi.json)**, generado desde los atributos y los
docblocks del controlador con `scripts/openapi.sh` — nunca a mano, porque una especificación
escrita a mano se separa del código en silencio, que es peor que no tener ninguna.

Conviene leer por aquí y no directamente desde MINSAL: caché compartida, formato ya parseado, y el
indicador `degraded`.

### Por qué OCS y no una ruta normal

El manual llama a `OCSController` el método actual, pero la razón concreta es la página web / centro
de información que viene: **un navegador en otro origen no se puede servir de otra forma**. Activar
CORS sobre un `Controller` normal está *«generalmente y encarecidamente desaconsejado»* — la
comprobación CSRF falla salvo que se desactive la seguridad.

Todavía **no hay `#[CORS]`**: hace falta un origen concreto y el tablero aún no tiene uno. Añadirlo
a ciegas ampliaría la superficie para nadie. Sí hay límite de tasa: 120 peticiones por minuto y
usuario, que es un techo contra un consumidor desbocado, no una cuota.

> La ruta anterior, `/apps/epidemiologia/api/v1/…`, **ya no existe**. No está obsoleta: se fue.
> No la consumía nadie todavía, y mantener dos contratos habría costado más que romper uno vacío.

## Desarrollo

```sh
scripts/test.sh     # suite unitaria dentro del contenedor (PHPUnit 11)
scripts/smoke.sh    # verifica el cableado contra la instancia en marcha
scripts/openapi.sh  # regenera openapi.json desde el controlador
```

`scripts/test.sh` cubre lo único con lógica de verdad: el parseo, el orden, el año, la semana
epidemiológica, la degradación y el plegado de acentos. Las fixtures son **respuestas reales de
MINSAL capturadas**, no HTML inventado: así se prueba que el parser lee lo que MINSAL publica de
verdad, con sus rarezas incluidas.

`scripts/smoke.sh` verifica lo que ninguna prueba unitaria ve: que la app se habilita, que el
trabajo diario quedó registrado, que la ruta OCS responde **con su sobre** y rechaza con `412` sin
la cabecera, que la ruta antigua devuelve 404, que el icono se sirve, que **el bundle de `js/`
coincide con `src/`**, y que **la CSP permite el dominio del tablero**. Esa última es una sola línea
en el controlador; sin ella el tablero se ve en blanco, y parece que MINSAL está caído en vez de que
lo rompimos nosotros.

### Cosas que muerden

- **`occ app:enable` sobre una app ya habilitada no registra el trabajo en segundo plano.** Si
  `smoke.sh` avisa de que falta, hay que hacer `occ app:disable` y luego `occ app:enable`.
- **Subir `<version>` en `info.xml` deja toda la instancia en «requiere actualización».** `occ`
  pasa a modo limitado y las rutas web dejan de responder hasta ejecutar `occ upgrade`. Un
  `git pull` que traiga un cambio de versión necesita ese paso detrás.
- **`opcache.revalidate_freq` es 60 s**, así que un cambio en PHP puede tardar un minuto en verse.
- **Al añadir o cambiar rutas por atributos hay que reiniciar el contenedor.** `CachingRouter`
  guarda la colección de rutas ya compilada **una hora** en la caché *local* (APCu), y APCu es por
  SAPI: limpiarla desde `occ` no sirve, porque la CLI y la web no la comparten. El síntoma es un
  `998 Invalid query` en OCS mientras `occ` lista la ruta tan tranquilo.
- **No conviene canalizar `docker exec … | grep -q`**: `grep` corta la tubería al primer acierto, el
  escritor muere con SIGPIPE y, con `pipefail`, una comprobación correcta se reporta como fallo.

## Instalación

El directorio se coloca en `gestion/apps/` (montado en `custom_apps`) y se habilita con
`occ app:enable epidemiologia`. **No hay migraciones que correr**: la app no crea tablas.

`js/` y `css/` **se versionan**, así que desplegar sigue siendo un `git pull` y no hace falta Node
en el servidor. Quien toque `src/` sí tiene que ejecutar `npm run build` y versionar el resultado —
`scripts/smoke.sh` avisa si el bundle se quedó atrás.

> **`node_modules` no debería vivir en el webroot de una instancia de producción.** El `.htaccess`
> de la app lo cierra, pero eso depende de Apache y de `AllowOverride`; el `.htaccess` de Nextcloud
> pone `js` y `mjs` en su lista blanca de extensiones, así que sin esa protección los ~300 MB de
> dependencias quedan servidos a cualquier usuario autenticado. Lo correcto es compilar en otro
> sitio y desplegar sólo lo necesario.

## Licencia

AGPL-3.0-or-later. Fuente de los datos: Departamento de Epidemiología, MINSAL.
