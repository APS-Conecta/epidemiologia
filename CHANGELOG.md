# Registro de cambios

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado semántico, todavía en `0.x`: **un cambio incompatible sube la minor.**

## [0.2.0] — 2026-08-02

Alineación con las convenciones de Nextcloud 34, más seis defectos que la
auditoría contra el manual encontró y que existían desde la primera versión.

### ⚠️ Incompatible — la API cambió de sitio

```
antes   GET /apps/epidemiologia/api/v1/alerts
ahora   GET /ocs/v2.php/apps/epidemiologia/api/v1/alerts
        OCS-APIRequest: true
```

La ruta anterior **ya no existe**: devuelve 404. No queda alias ni periodo de
gracia, porque no la consumía nadie todavía y mantener dos contratos cuesta más
que romper uno vacío. Tres cosas cambian para quien la lea:

1. La cabecera `OCS-APIRequest: true` **es obligatoria**. Sin ella Nextcloud
   responde `412` antes de llegar al controlador.
2. Los datos van **dentro de `ocs.data`**, no en la raíz de la respuesta.
3. Con `format=xml` sale lo mismo en XML.

**Por qué**: la página web / centro de información que viene es un navegador en
otro origen, y eso no se puede servir desde un `Controller` normal — activar CORS
ahí está «generalmente y encarecidamente desaconsejado» porque la comprobación
CSRF falla salvo que se desactive la seguridad. La forma anterior sencillamente
no podía atenderlo.

El contrato está ahora en [`openapi.json`](openapi.json), generado desde el
código con `scripts/openapi.sh`.

### Corregido

- **Una URL `javascript:` desde MINSAL llegaba a `href` sin validar.** `p()` es
  `htmlspecialchars`: escapa comillas y ángulos, y no puede hacer seguro un
  esquema. Reproducido antes de arreglarlo. Ahora `parse()` sólo acepta `https://`,
  y los descartes se cuentan y registran en vez de desaparecer en silencio.
- **`intl` era dependencia dura sin declarar.** `fold()` usa `Normalizer`, que
  Nextcloud sólo *recomienda*, y `fold()` se ejecuta en **cada búsqueda unificada
  de cada usuario**: en una instancia sin intl, un fatal se llevaba por delante la
  búsqueda global de gente que nunca había abierto esta app.
- **El aviso de «problemas técnicos» tenía el borde invisible.** `--color-warning`
  es un fondo crema pálido, no un naranja; usado como borde daba **1,08:1** de
  contraste contra su propio relleno. Ahora **3,07:1**, y el texto **7,42:1**.
- **El sondeo diario podía llenar la campana para siempre.** Nextcloud no
  deduplica notificaciones en ninguna capa, así que una caída de MINSAL archivaba
  dos avisos por administrador y por día indefinidamente. Ahora escalera de
  `[2, 7, 30]` fallos consecutivos: **una caída de 30 días produce 3 avisos, no 30.**
- **El widget de pestañas no era accesible.** Los `<li>` rompían la propiedad ARIA
  del `tablist`, y sin roving `tabindex` las cuatro pestañas estaban a la vez en
  la secuencia de tabulación mientras las flechas también las recorrían.
- **El iframe del tablero filtraba la URL de la intranet** a Microsoft en cada
  carga. Ahora `referrerpolicy="no-referrer"`.
- **La app no tenía icono**: el menú mostraba el marcador genérico.
- **Los radios de cuatro tarjetas se renderizaban a 8px**, no a los 12px con los
  que se diseñaron: `--border-radius-large` está deprecado y es un alias de
  `--border-radius-element`, así que su fallback nunca se aplicó.
- Las excepciones se registraban con `getMessage()`, tirando la traza justo
  cuando hace falta.

### Añadido

- **`ISetupCheck`** en Administración → Visión general: dice si la instancia
  sigue alcanzando MINSAL, y **desde cuándo** si no. Lee el mismo contador que usa
  la notificación, así que no pueden discrepar, y **nunca llama a MINSAL** — los
  setup checks se ejecutan de forma síncrona mientras un administrador espera la
  página.
- **`openapi.json`** y `scripts/openapi.sh`.
- Límite de tasa de 120 peticiones por minuto y usuario en la API.
- `notify_groups` configurable: quien mantiene la instancia no es necesariamente
  administrador de Nextcloud.
- Un `.htaccess` que cierra `node_modules/`, `src/`, `tests/`, `scripts/` y
  `tools/`. El `.htaccess` de Nextcloud pone `js` y `mjs` en su lista blanca, así
  que sin esto los ~300 MB de dependencias quedaban servidos.

### Cambiado

- **El frontend se reescribió sobre `@nextcloud/vue`** (9.9.0, Vue 3.5.40), con
  Vite. `templates/index.php` pasa de 189 líneas a 10; los datos llegan por
  `IInitialState` en vez de por `$_`.
- **Cero tamaños de fuente y cero radios en píxeles crudos.** Ese era el problema
  de fondo: Nextcloud ya pone 15px y 1.5 de interlineado en `body` y 1.8em en
  `h2`, y el CSS anterior peleaba contra ambos — por eso la app se veía distinta
  a las oficiales.
- Rutas por atributos; `appinfo/routes.php` borrado.
- `#[\Override]` en los 13 métodos que implementan una interfaz.
- `<php min-version>` sube a 8.2, que es el suelo real de Nextcloud 34.
- La antigüedad del dato usa `NcDateTime`: se mantiene correcta en una pestaña
  abierta durante horas.

### Notas para quien despliegue

- **Ahora hay un paso de compilación.** `js/` y `css/` se versionan, así que el
  despliegue sigue siendo un `git pull` — pero quien toque `src/` tiene que correr
  `npm run build` y versionar el resultado. `scripts/smoke.sh` lo comprueba.
- **`node_modules` no debería estar en el webroot de una instancia de
  producción.** El `.htaccess` lo tapa, pero depende de Apache y de
  `AllowOverride`; lo correcto es no compilar ahí.
- **Subir la versión de la app deja la instancia entera en modo «requiere
  actualización»** hasta que se ejecute `occ upgrade`: `occ` queda limitado y las
  rutas web dejan de responder. O sea que un `git pull` que traiga un cambio de
  versión **no es sólo un `git pull`** — hay que correr `occ upgrade` detrás.
- Al cambiar rutas por atributos hay que **reiniciar el contenedor**:
  `CachingRouter` cachea la colección de rutas una hora en APCu, que es por SAPI.

## [0.1.0] — 2026-08-01

Primera versión. Alertas vigentes, informe semanal ETI/IRAG, tablero del MINSAL
incrustado y enlace a EPIVIGILA. Proxy de lectura con caché, sin base de datos.
