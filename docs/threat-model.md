# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work; the 🗡️ Breaker agent starts here.*

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
| `GET /` — `PageController` (`lib/Controller/PageController.php`) | any logged-in user | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET, read-only) |
| `GET /informe/{year}/{semana}` — `InformeController::show` (`appinfo/routes.php`, `lib/Controller/InformeController.php:74`) | any logged-in user | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET). It looks up the stored record and redirects only to an https URL built from (se, year), "never feed-supplied" (`InformeController.php:45-46`). |
| OCS `GET /api/v1/sources` — `ApiController` (`lib/Controller/ApiController.php`) | any logged-in user | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| Background jobs `ProbeJob`, `RefreshJob`, `WarmJob` (`appinfo/info.xml:36-38`) | the server, on schedule | none needed. They fetch fixed publisher hosts (below). |
| Outbound fetches (`lib/Service/AlertRepositories.php`, `AlertRepository.php`, `IspReportRepository.php`) | the jobs | fixed hosts only: `degreyd.minsal.cl`, `epi.minsal.cl`, `www.ispch.gob.cl`, `www.paho.org`, with a pinned CA bundle (`lib/Service/CertificateBundle.php`) |
| Parsers of hostile input: RSS/XML (`lib/Service/Parser/FeedParser.php`, `IspReportRepository::parse`), HTML pages (`lib/Service/Parser/*`) | data the publishers send | `LIBXML_NONET` (#148); the anchor-count guard in `PageParser` |

## Trust boundaries

- **Publishers' pages and feeds are untrusted.** Titles, links and dates are rendered to staff.
  Vue's `{{ }}` text binding escapes them, and `src/` has no `v-html`. Any new raw-HTML sink would be
  a direct XSS path.
- **Links from publishers** are rendered as links. A `javascript:` or attacker-controlled URL in a
  feed would reach a clinician's click.
- **Users are all trusted staff of one CESFAM**, with no per-user data. Breaking one user's
  permissions gives nothing beyond what every user already sees.
- **The platform (Nextcloud)** owns authentication, sessions and CSRF tokens.

## Accepted risks

None recorded.
