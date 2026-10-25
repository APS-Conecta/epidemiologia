# Epidemiología

Aplicación de Nextcloud 34 para APS Conecta Gestión que muestra dentro de la intranet lo que publican el MINSAL, el ISP y la OPS/OMS, y enlaza lo que no se puede mostrar.

## Qué es

Persiste solo lo que sus fuentes publican —los metadatos de cada fuente y una copia del documento de cada informe— y no toca datos de pacientes. Las alertas siguen siendo enlaces a los documentos de quien las publica. La interfaz está en español y todo lo demás en inglés ([`AGENTS.md`](AGENTS.md)).

| Vista | Qué es | De dónde viene |
| --- | --- | --- |
| **Inicio** | Una tarjeta por sector de alertas, en el orden de la navegación —su elemento más reciente, completo, y su propio estado degradado— y después la tarjeta del informe | Todo lo que sigue, ya cargado |
| **Alertas vigentes** | Los oficios vigentes, filtrables por texto, año y mes | La página en que el MINSAL publica el conjunto vigente, en `degreyd.minsal.cl` |
| **Departamento de Epidemiología** | Las alertas que el Departamento de Epidemiología mantiene vigentes, con los mismos filtros | Su propia página en `epi.minsal.cl` |
| **Tablero ETI e IRAG** | El tablero nacional, incrustado | Power BI *«Publish to web»* |
| **EPIVIGILA** | Qué es, cómo ingresar y la situación real de acceso | Un enlace, nunca incrustado |
| **Circulación de Virus Respiratorios** | El informe semanal del ISP, por semana epidemiológica | El feed del ISP, persistido como una serie (año, semana); el PDF de cada informe se guarda una vez en el almacenamiento propio de la aplicación |
| **Medicamentos (ANAMED)**, **Farmacovigilancia**, **Vigilancia de cosméticos** | Las alertas del ISP en tres de sus categorías, filtrables por texto, año y mes | El feed de cada categoría en `www.ispch.gob.cl`; cada elemento que mostró queda en la tabla de la aplicación |
| **Alertas y actualizaciones** | Las alertas y actualizaciones epidemiológicas de la OPS/OMS, filtrables también por tipo | Su lista en `www.paho.org`, guardada del mismo modo |

Para otras aplicaciones expone **una** ruta OCS de solo lectura, `GET /ocs/v2.php/apps/epidemiologia/api/v1/sources?format=json`, con la cabecera `OCS-APIRequest: true` (sin ella Nextcloud responde `412`). El contrato es [`openapi.json`](openapi.json), generado por `scripts/openapi.sh` desde el controlador y nunca a mano; la forma de ruta única está en el [ADR 0006](docs/adr/0006-one-ocs-route-keyed-by-source.md). Los siete ids, en orden de presentación: `alerts`, `epi_alerts`, `reports`, `isp_anamed`, `isp_pharmacovigilance`, `isp_cosmetics`, `paho_alerts`. El detalle de parámetros, esquema y errores está en la página de desarrollo.

## Documentación

La explicación completa vive en el sitio de documentación:

- [Guía de uso](https://aps-conecta.github.io/documentation/usuario/epidemiologia.html): las vistas, la lectura de Inicio y los filtros.
- [Referencia de desarrollo](https://aps-conecta.github.io/documentation/desarrollo/epidemiologia.html): la ruta OCS, el ciclo de vida y el freno de cada fuente, los trabajos en segundo plano, la configuración, la actualización, cómo añadir una fuente, la rotación del certificado del ISP, las trampas de la plataforma y la deuda técnica.
- [Novedades](https://aps-conecta.github.io/documentation/proyecto/novedades/epidemiologia.html): los cambios visibles para quien usa o administra la aplicación.

Dentro de este repositorio quedan solo los documentos que viajan con el código:

- [`CONTEXT.md`](CONTEXT.md): el vocabulario (fuente, sondeo y lo que este contexto deliberadamente no nombra), en inglés.
- [`AGENTS.md`](AGENTS.md): las reglas del repositorio para agentes de IA, en inglés.
- [`docs/adr/`](docs/adr/): cada decisión y el razonamiento vigente cuando se tomó. Los números son direcciones: la numeración parte en 0002 y no se reutiliza ni se renumera.
- [`docs/threat-model.md`](docs/threat-model.md): qué busca un atacante, los puntos de entrada y sus resguardos.
- [`docs/agents/README.md`](docs/agents/README.md): dónde están los documentos de dominio, el tablero de incidencias y las etiquetas de triaje.
- [`certs/README.md`](certs/README.md): por qué el certificado intermedio del ISP viaja en este repositorio, de dónde se derivó y el comando que lo reemplaza.
- [`openapi.json`](openapi.json): el contrato OCS, generado.
- [`CHANGELOG.md`](CHANGELOG.md): qué cambió y cuándo.
- [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md): los componentes de terceros que lleva el bundle y su licencia.

Las decisiones que alcanzan más allá de esta aplicación pertenecen al repositorio troncal, no a este: ver [ADR-0007 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0007-org-wide-decisions-live-in-this-repository.md).

## Estado

**0.12.0, 2026-10-24.** La aplicación se publica ahora desde su propia release firmada: el flujo compartido de la organización (llamado por `.github/workflows/release.yml`, fijado por SHA) compila el frontend, empaca el tarball de forma determinista, firma `SHA256SUMS` con la llave de aplicaciones dentro del entorno `release` y publica los cuatro artefactos que el instalador de la suite verifica contra `apps.pub` (instalador #34). `v0.12.0` es la primera etiqueta con página de release. `js/` y `css/` dejan de versionarse: son producto de compilación (abajo). Lo demás sigue como en la 0.11.3: seis sectores de alertas sobre un solo pipeline ([ADR 0017](docs/adr/0017-six-alert-sectors-one-pipeline.md)), cada fuente persistida y siguiendo a su publicador, el informe con su propia serie y un trabajo horario que mantiene una instalación que nadie abre.

**Desde un centro de datos, cuatro sectores no tienen nada que mostrar, y lo dicen.** `epi.minsal.cl` responde a un host así con el 403 de Cloudflare y el ISP no responde (medido el 2026-09-27): los sectores del Departamento de Epidemiología y los tres del ISP muestran su aviso y un enlace a la página del publicador, y la campana de administración nombra a cada uno, y al informe, en los días 2, 7 y 30. Un establecimiento con conexión residencial lee los seis.

Lo que no está hecho, cada cosa por una razón: la ruta OCS no lleva `#[CORS]`, que necesita un origen concreto y no un comodín; nada propaga el establecimiento a esta aplicación, así que lee `notify_groups` y sus propios contadores del sondeo y nada más ([ADR-0009 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0009-the-establishment-is-instance-configuration.md)); `scripts/smoke.sh` y `scripts/layout.sh` se ejecutan a mano. Las etiquetas `v0.3.0`…`v0.11.3` no tienen página de release en GitHub y no la tendrán: nacieron del tarball que `gestion` construía desde una etiqueta. Desde la `v0.12.0` cada etiqueta publica su release firmada (arriba) y el instalador de la suite instala desde ella; el tarball de `gestion` se retira con el horneado (instalador #42).

Dos trampas medidas, que ya costaron un diagnóstico equivocado (su detalle y el procedimiento están en la página de desarrollo):

- **`www.ispch.gob.cl` sirve solo su certificado hoja** (`ssl_verify_result=20`): el cliente HTTP de Nextcloud, curl y Python fallan. La aplicación trae el intermedio que falta, `certs/gsgccr6alphasslca2025.crt`, y `CertificateBundle` lo une a las anclas de la instancia solo para las solicitudes al ISP, con la verificación activa: nunca `verify=False`. Ese certificado fija un emisor y **la hoja del ISP vence el 2026-11-23**; si el ISP deja de responder, leer la cadena antes de suponer una caída.
- **El WAF del ISP responde vacío a un User-Agent literal `curl/*`** y 200 a cualquier otro, lo que se lee igual que una falla de TLS. La aplicación se identifica en cada solicitud (`APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)`), así que una reproducción reducida a `curl` no prueba que el host esté caído.

## Inicio rápido de desarrollo

En una instalación real, la aplicación llega desde su release firmada: el manifiesto de la suite
es la única lista de aplicaciones y el aprovisionamiento ya no clona nada. Lo que sigue es para
trabajar sobre el código, contra una suite en marcha.

1. **Clonar este repositorio y construir el frontend.** `js/` y `css/` no están versionados: un
   clon necesita `npm ci && npm run build` antes de que la aplicación sirva.
2. **Llevar el código al contenedor.** Los comandos `occ` de esta guía corren en el contenedor
   de Nextcloud de la suite (`docker exec -u www-data aps-conecta-nextcloud php occ …`); los
   scripts del repositorio usan ese mismo contenedor y aceptan `EPI_CONTAINER` para otro destino.
3. **Comprobar que los tres trabajos en segundo plano quedaron registrados** (en el contenedor):
   `docker exec -u www-data aps-conecta-nextcloud php occ background-job:list | grep -E 'ProbeJob|RefreshJob|WarmJob'`.
   Exactamente tres filas es lo correcto: el sondeo que detecta, el pase horario que mantiene al día una instalación que nadie abre y el pase profundo nocturno que recorre los catálogos antiguos. **Una fila ausente significa que la habilitación no la registró:** ver las trampas de la plataforma en la página de desarrollo.
4. **Abrir `/apps/epidemiologia`** en la URL de la instalación (`http://localhost/…` es la base *dentro* del contenedor, por eso la usa `scripts/smoke.sh` y un navegador no puede). Inicio muestra una tarjeta por sector de alertas, cada una con lo último que publicó su fuente. Desde la terminal, `scripts/smoke.sh` comprueba el mismo cableado y más.

Pruebas: `scripts/test.sh` ejecuta la batería unitaria dentro del contenedor (PHPUnit 11), `npm test` la lógica del frontend (Vitest), `scripts/smoke.sh` el cableado contra la instancia en marcha, `scripts/layout.sh` la disposición en un navegador real a tres anchos y `scripts/openapi.sh` regenera `openapi.json`. La integración continua (`.github/workflows/ci.yml`) corre en `main` y en los pull requests, no en cada push.

`js/` y `css/` **no están versionados**: los construye el flujo de release y viajan dentro del tarball firmado; un clon necesita `npm ci && npm run build` antes de que la aplicación sirva. Para actualizar una instalación desde un clon: `git pull`, `npm ci && npm run build`, `docker exec -u www-data aps-conecta-nextcloud php occ upgrade` y reiniciar el contenedor web. **La migración de la 0.11.0 reescribe la clave primaria de `epidemiologia_alert` y Nextcloud no tiene migraciones de retroceso:** respaldar la base de datos antes de esa actualización. La configuración (`notify_groups` y los contadores que escribe la propia aplicación), el procedimiento para añadir una fuente y el resto de las trampas están en la [referencia de desarrollo](https://aps-conecta.github.io/documentation/desarrollo/epidemiologia.html).

## Licencia

AGPL-3.0-or-later; véase [`LICENSE`](LICENSE). No es una elección libre: la aplicación compila `@nextcloud/vue` (AGPL-3.0-or-later) en los bundles de `js/`, junto con paquetes de Nextcloud bajo GPL-3.0-or-later, de modo que hereda el copyleft de lo que enlaza. [ADR-0006 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0006-agpl-across-the-org.md) registra la decisión de toda la organización que se siguió de eso. Como la sección 13 de la AGPL cubre el uso en red, al personal que usa esta aplicación por la intranet se le debe su código fuente cuando lo pida.

Datos del MINSAL, del ISP de Chile y de la OPS/OMS. Los componentes de terceros y sus avisos están en [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md); los avisos y licencias de la organización viven en [Aviso y licencias](https://aps-conecta.github.io/documentation/aviso.html).
