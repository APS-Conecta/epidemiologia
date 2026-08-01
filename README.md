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

Dos rutas JSON de solo lectura, autenticadas con la sesión de Nextcloud:

```
GET /apps/epidemiologia/api/v1/alerts
GET /apps/epidemiologia/api/v1/reports

{ "updated_at": "2026-07-31T22:15:04",
  "degraded": false,
  "items": [ { "title": "OFICIO CP N°15844 / 2026 …", "url": "https://…pdf", "year": "2026" } ] }
```

Las alertas también traen `month`; los informes, además, `se` (semana epidemiológica).

**Es un contrato**: los nombres de los campos no cambian sin subir la versión de la ruta. Conviene
leer por aquí y no directamente desde MINSAL, por la caché compartida, por el formato ya parseado y
por el indicador `degraded`.

No hay ruta pública ni CORS: hoy los consumidores viven dentro de este Nextcloud. Uno externo
necesitaría además un límite de tasa, y esa es una decisión para cuando exista.

## Desarrollo

```sh
scripts/test.sh     # suite unitaria dentro del contenedor (PHPUnit 11)
scripts/smoke.sh    # verifica el cableado contra la instancia en marcha
```

`scripts/test.sh` cubre lo único con lógica de verdad: el parseo, el orden, el año, la semana
epidemiológica, la degradación y el plegado de acentos. Las fixtures son **respuestas reales de
MINSAL capturadas**, no HTML inventado: así se prueba que el parser lee lo que MINSAL publica de
verdad, con sus rarezas incluidas.

`scripts/smoke.sh` verifica lo que ninguna prueba unitaria ve — que la app se habilita, que la ruta
responde, que el trabajo diario quedó registrado y que **la CSP permite el dominio del tablero**.
Esa última es una sola línea en el controlador; sin ella el tablero se ve en blanco, y parece que
MINSAL está caído en vez de que lo rompimos nosotros.

### Cosas que muerden

- **`occ app:enable` sobre una app ya habilitada no registra el trabajo en segundo plano.** Si
  `smoke.sh` avisa de que falta, hay que hacer `occ app:disable` y luego `occ app:enable`.
- **`opcache.revalidate_freq` es 60 s**, así que un cambio en PHP puede tardar un minuto en verse.
- **No conviene canalizar `docker exec … | grep -q`**: `grep` corta la tubería al primer acierto, el
  escritor muere con SIGPIPE y, con `pipefail`, una comprobación correcta se reporta como fallo.

## Instalación

El directorio se coloca en `gestion/apps/` (montado en `custom_apps`) y se habilita con
`occ app:enable epidemiologia`. No hay migraciones que correr ni nada que compilar: la página se
renderiza en el servidor y el JavaScript son cuarenta líneas sin dependencias.

## Licencia

AGPL-3.0-or-later. Fuente de los datos: Departamento de Epidemiología, MINSAL.
