# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Semantic versioning, still on `0.x`: **an incompatible change raises the minor.**

Written in English, like the rest of this repo outside the UI — see [`AGENTS.md`](AGENTS.md).
Entries up to 0.2.0 were originally written in Spanish and were translated on 2026-08-03; the
history they describe is unchanged.

## [0.3.0] — 2026-08-03

One list of sources. The app had three sources and **seven places that enumerated
them independently**; adding the ISP updated four of them. The other three were not
separate bugs — they were the same omission, and a fourth source would have
produced it again.

### ⚠️ Breaking — the API moves again, and this time to stay

```
before  GET /ocs/v2.php/apps/epidemiologia/api/v1/alerts
        GET /ocs/v2.php/apps/epidemiologia/api/v1/reports
now     GET /ocs/v2.php/apps/epidemiologia/api/v1/sources
        OCS-APIRequest: true
```

The two previous routes **no longer exist**: they return 404. No aliases and no
grace period, for the same reason as in 0.2.0 — nobody was consuming them, and
keeping two contracts costs more than breaking an empty one.

The response carries **every source in one call**, each with its own freshness and
its own degraded state:

```json
{ "ocs": { "data": {
  "alerts":          { "updated_at": "…", "degraded": false, "items": [ … ] },
  "reports":         { "updated_at": "…", "degraded": false, "items": [ … ] },
  "sanitary_alerts": { "updated_at": "…", "degraded": false, "items": [ … ] }
}}}
```

**Why one route and not three**: it is how Nextcloud serves the only kind of
consumer this contract exists for. `dashboard.rst` returns `ocs.data` keyed by
widget, and `cloud/capabilities` keyed by app; the resource-per-endpoint split —
as `groupfolders` does it — is for mutable things with ids and a CRUD surface,
which this app has none of. And from now on **adding a source adds a key**, not a
route: no version bump forced by the route table and no container restart. Full
reasoning and evidence in
[`docs/adr/0006`](docs/adr/0006-one-ocs-route-keyed-by-source.md).

The tablero is **not** in the response: it is a constant URL, with nothing to
fetch, parse, cache or degrade — which are the three reasons anything routes
through this app at all.

### Fixed

- **A notification about the ISP said MINSAL.** `Notifier` picked its wording with a
  ternary on `irag`, so **any** source that was not the reports rendered as *"no se
  pudo leer las alertas epidemiológicas vigentes desde MINSAL"*, and the body sent
  the reader off to check a MINSAL page that was perfectly healthy. An ISP outage
  pointed an administrator at the wrong site.
- **Administración → Visión general stayed green during an ISP outage.** `ProbeJob`
  had been writing `probe_failures_isp-alerts` since 0.2.0 and **nothing read it**:
  the check walked only MINSAL's two sources, by name.
- **The ISP's 60 alerts never appeared in the global search box.** The provider
  named one repository in its constructor instead of asking what the app publishes.
  It now searches both alert sources, each result says what kind it is and which
  institution it came from, and the ISP search covers the category slugs too — so
  "dispositivo" finds alerts whose title never says the word.

All three had the same cause, so they are fixed in one place rather than three.

### Added

- **`Service/Sources.php`, the catalogue**: what sources exist, who owns each, and
  what they are called. The seven consumers read it instead of restating it. It
  **does not merge `MinsalRepository` with `IspRepository`** and must not: their
  degradation rules genuinely differ — MINSAL's emptiness is always a failure,
  an empty ISP feed is normal — and that disagreement is why the second class
  exists.
- `IspRepository::search()`, cache-only, under the same ban on touching the network
  as MINSAL's: every registered provider runs on every search by every user.
- Tests: every assertion walks the whole catalogue, so a fourth source added to the
  list and forgotten in a `match` fails here. And the assertion that was missing —
  which institution a notification names — ships with the fix.

### Changed

- `MinsalReachable` is now **`SourcesReachable`**, and the row reads *"conexión con
  las fuentes"*.
- Outside a repository, a source is identified by its catalogue id: `alerts`,
  `reports` and `sanitary_alerts`. `CONTEXT.md` names the third object *alerta
  sanitaria ISP* and lists *alerta ISP* under "Avoid", so the id follows the
  glossary rather than the class constant.
- **The probe counters are renamed to those ids**: `probe_failures_irag` →
  `probe_failures_reports`, and `probe_failures_isp-alerts` →
  `probe_failures_sanitary_alerts`. The old ones are orphaned; in practice a source
  that was failing restarts its day count.
- Pending notifications written by 0.2.0 carry the old key and are **discarded**
  rather than rendered wrongly: `Notifier` answers `UnknownNotificationException`
  for a row it cannot read, which is how Nextcloud documents getting out of that.
- The page receives **one `IInitialState` key** instead of one per source. Each key
  was a contract between PHP and JavaScript that had to be spelled identically in
  two languages.
- **`README.md` and this file are now in English**, along with the output of
  `scripts/`. Spanish is what a clinician reads — the UI — and these are read by
  whoever maintains the app. The rule now lives in `AGENTS.md`.

## [0.2.0] — 2026-08-02

Alignment with Nextcloud 34's conventions, plus six defects that the audit against
the manual found and that had existed since the first version.

### ⚠️ Breaking — the API moved

```
before  GET /apps/epidemiologia/api/v1/alerts
now     GET /ocs/v2.php/apps/epidemiologia/api/v1/alerts
        OCS-APIRequest: true
```

The previous route **no longer exists**: it returns 404. No alias and no grace
period, because nobody was consuming it yet and keeping two contracts costs more
than breaking an empty one. Three things change for whoever reads it:

1. The `OCS-APIRequest: true` header **is mandatory**. Without it Nextcloud answers
   `412` before reaching the controller.
2. The data sits **inside `ocs.data`**, not at the root of the response.
3. With `format=xml` you get the same thing in XML.

**Why**: the web page / information centre that is coming is a browser on another
origin, and that cannot be served from a plain `Controller` — enabling CORS there
is "generally and highly discouraged" because the CSRF check fails unless security
is disabled. The previous shape simply could not serve it.

The contract now lives in [`openapi.json`](openapi.json), generated from the code
with `scripts/openapi.sh`.

### Fixed

- **A `javascript:` URL from MINSAL reached `href` unvalidated.** `p()` is
  `htmlspecialchars`: it escapes quotes and angle brackets, and cannot make a
  scheme safe. Reproduced before being fixed. `parse()` now accepts only `https://`,
  and rejects are counted and logged instead of vanishing silently.
- **`intl` was an undeclared hard dependency.** `fold()` uses `Normalizer`, which
  Nextcloud only *recommends*, and `fold()` runs on **every unified search by every
  user**: on an instance without intl, a fatal took out global search for people who
  had never opened this app.
- **The "problemas técnicos" notice had an invisible border.** `--color-warning` is
  a pale cream background, not an orange; used as a border it gave **1.08:1**
  contrast against its own fill. Now **3.07:1**, and the text **7.42:1**.
- **The daily probe could fill the bell forever.** Nextcloud deduplicates
  notifications at no layer, so a MINSAL outage filed two notices per administrator
  per day indefinitely. Now a `[2, 7, 30]` consecutive-failure ladder: **a 30-day
  outage produces 3 notices, not 30.**
- **The tab widget was not accessible.** The `<li>`s broke the `tablist`'s ARIA
  ownership, and without a roving `tabindex` all four tabs sat in the tab sequence
  at once while the arrow keys also walked them.
- **The tablero's iframe leaked the intranet URL** to Microsoft on every load. Now
  `referrerpolicy="no-referrer"`.
- **The app had no icon**: the menu showed the generic placeholder.
- **Four cards' radii rendered at 8px**, not the 12px they were designed with:
  `--border-radius-large` is deprecated and aliases `--border-radius-element`, so
  its fallback never applied.
- Exceptions were logged with `getMessage()`, throwing away the trace exactly when
  it is needed.

### Added

- **`ISetupCheck`** in Administración → Visión general: it says whether the instance
  still reaches MINSAL, and **since when** if not. It reads the same counter the
  notification uses, so the two cannot disagree, and it **never calls MINSAL** —
  setup checks run synchronously while an administrator waits for the page.
- **`openapi.json`** and `scripts/openapi.sh`.
- A rate limit of 120 requests per minute per user on the API.
- Configurable `notify_groups`: whoever keeps the instance running is not
  necessarily a Nextcloud administrator.
- An `.htaccess` closing off `node_modules/`, `src/`, `tests/`, `scripts/` and
  `tools/`. Nextcloud's own `.htaccess` whitelists `js` and `mjs`, so without this
  the ~300 MB of dependencies were being served.

### Changed

- **The frontend was rewritten on `@nextcloud/vue`** (9.9.0, Vue 3.5.40), with Vite.
  `templates/index.php` goes from 189 lines to 10; data arrives through
  `IInitialState` instead of through `$_`.
- **Zero font sizes and zero radii in raw pixels.** That was the underlying problem:
  Nextcloud already sets 15px and a 1.5 line height on `body` and 1.8em on `h2`, and
  the previous CSS fought both — which is why the app looked unlike the official
  ones.
- Attribute routes; `appinfo/routes.php` deleted.
- `#[\Override]` on the 13 methods that implement an interface.
- `<php min-version>` rises to 8.2, which is Nextcloud 34's real floor.
- Data age uses `NcDateTime`: it stays correct in a tab left open for hours.

### Notes for whoever deploys

- **There is a build step now.** `js/` and `css/` are committed, so deployment stays
  a `git pull` — but anyone touching `src/` has to run `npm run build` and commit
  the result. `scripts/smoke.sh` checks it.
- **`node_modules` should not be in a production instance's webroot.** The
  `.htaccess` covers it, but that depends on Apache and on `AllowOverride`; the
  right answer is not to build there.
- **Raising the app version puts the whole instance into "requires upgrade"** until
  `occ upgrade` runs: `occ` becomes limited and the web routes stop answering. So a
  `git pull` bringing a version change **is not just a `git pull`** — `occ upgrade`
  has to follow it.
- Changing attribute routes needs a **container restart**: `CachingRouter` caches the
  route collection for an hour in APCu, which is per-SAPI.

## [0.1.0] — 2026-08-01

First release. Alertas vigentes, the weekly ETI/IRAG report, MINSAL's embedded
dashboard and a link to EPIVIGILA. A read-through caching proxy, with no database.
