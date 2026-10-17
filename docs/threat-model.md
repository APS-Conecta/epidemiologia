# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work.*

## Data this repository holds

- **Published metadata only.** Two tables: `epidemiologia_alert` (fuente, title, url, year, month,
  day, tipo) and `epidemiologia_report` (year, se, title, url, month, published_at, path, hash)
  (`lib/Migration/Version001000Date20260920000000.php`, `lib/Migration/Version001100Date20260928000000.php`).
- **One copy of each ISP informe PDF**, in the app data folder `informes`
  (`lib/Service/IspReportRepository.php:210`), served by `/informe/{year}/{semana}`.
- **No patient data.** The README says the app "persists only what its publishers publish"
  (`README.md:6`), and `CONTEXT.md:4` says "No patient data enters this context."

## What an attacker wants

1. **Patient data elsewhere in the same Nextcloud** (staff Files, Talk, Mail). The path is acting in a
   logged-in staff member's session: script injection (XSS) through feed content rendered in the UI,
   or a forged request. This app is the door, not the vault.
2. **Tampering with what clinicians read**: a forged or altered alert, date or informe that changes a
   clinical decision. The integrity of published figures matters here.
3. **Server-side request forgery (SSRF)**: making the server fetch an attacker-chosen URL from inside
   the intranet.
4. **Denial of service**: exhausting the hourly jobs or the API with oversized or slow feeds.

## Entry points

| Entry point | Who can call it | Guard |
|---|---|---|
| `GET /` — `PageController` (`lib/Controller/PageController.php`) | any logged-in user | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET, read-only). Its CSP allows framing `https://app.powerbi.com` for the Tablero MINSAL (`PageController.php:76-78`): a third-party frame inside the staff session. |
| `GET /informe/{year}/{semana}` — `InformeController::show` (`appinfo/routes.php`, `lib/Controller/InformeController.php:74`) | any logged-in user | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET). It serves the kept PDF **inline, from the Nextcloud origin** (`InformeController.php:124`), and only the `%PDF` magic bytes vouch for it (`lib/Service/IspReportRepository.php:848`). Without a kept copy it redirects to an https URL built from (se, year), "never feed-supplied" (`InformeController.php:45-46`). |
| OCS `GET /api/v1/sources` — `ApiController` (`lib/Controller/ApiController.php`) | any logged-in user | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| Unified search — `AlertProvider` (`lib/Search/AlertProvider.php:61`) | any logged-in user | returns each alert's feed `title` and `url` to Nextcloud's global search: a rendering sink outside this app's Vue code |
| Background jobs `ProbeJob`, `RefreshJob`, `WarmJob` (`appinfo/info.xml:36-38`), and `RevalidateJob`, queued on demand (`lib/Service/AlertRepository.php:198`, `IspReportRepository.php:381`) | the server | none needed; they run the fetches below |
| Outbound fetches of feeds and pages (`lib/Service/AlertRepositories.php`, `AlertRepository.php`, `IspReportRepository.php`) | the jobs | fixed hosts: `degreyd.minsal.cl`, `epi.minsal.cl`, `www.ispch.gob.cl`, `www.paho.org`. TLS is verified against a **merged** CA bundle: the instance's anchors plus the ISP's certificate (`lib/Service/CertificateBundle.php`). It widens trust; it pins nothing. |
| **Outbound fetch of each informe PDF** (`IspReportRepository::document`, `:821`) | the jobs | **The URL is feed-supplied:** the first `<a href>` in the ISP item's `content:encoded` (`:914`), https-only (`:918`), any host. Guards: Nextcloud's `IClient`, which refuses local and private addresses unless `allow_local_remote_servers` is set; a 15 s timeout; `DOC_MAX_BYTES`, 8 MiB (`:840`); the `%PDF` check (`:848`). TLS uses the same merged bundle (`:829`), so the added ISP anchor is trusted for whatever host the feed names. This is the SSRF surface. |
| Admin notifications (`lib/Notification/Notifier.php:90-101`) and the `SourcesReachable` setup check (`lib/SetupCheck/SourcesReachable.php`) | admins | low risk: fixed source names and a link to the app's own route |
| Parsers of hostile input: RSS/XML (`lib/Service/Parser/FeedParser.php`, `IspReportRepository::parse`), HTML pages (`lib/Service/Parser/*`) | data the publishers send | `LIBXML_NONET` (#148); https-only links (`FeedParser.php:84`, `PahoParser.php:125`, `PageParser.php:117`, `IspReportRepository.php:918`) |

## Trust boundaries

- **Publishers' pages and feeds are untrusted.** Titles, links and dates are rendered to staff.
  Vue's `{{ }}` text binding escapes them, and `src/` has no `v-html`. Any new raw-HTML sink would be
  a direct XSS path. Global search renders the same titles outside Vue (`AlertProvider`).
- **Links from publishers** are rendered as links. Every parser keeps only `https://` hrefs, so a
  `javascript:` URL is refused; an attacker-chosen https URL still reaches a clinician's click.
- **The informe PDF is publisher content served from our origin**, inline, with only its magic bytes
  checked. Its `application/pdf` type and the browser's PDF viewer are what contain a hostile file.
- **Users are all trusted staff of one CESFAM**, with no per-user data. Breaking one user's
  permissions gives nothing beyond what every user already sees.
- **The platform (Nextcloud)** owns authentication, sessions, CSRF tokens and the outbound HTTP client.

## Accepted risks

None recorded.
