# The OCS contract is one route keyed by source, not a route per source

`GET /ocs/v2.php/apps/epidemiologia/api/v1/sources` returns every source this app publishes in one
response, keyed by source id, each value carrying `updated_at`, `degraded` and `items`. Recorded
because it looks un-REST: to stop the next reader splitting it back into `/alerts`, `/reports` and
`/sanitary-alerts` — the shape 0.2.0 shipped, replaced deliberately.

## Why

**Nextcloud splits this choice by what the data *is*, and is consistent about it.**

| Shape | Core example | Data |
| --- | --- | --- |
| Resource per endpoint | `groupfolders`: `/folders`, `/folders/{id}`, `/folders/{id}/acl`, … (13 paths) | Mutable things with ids, created and deleted individually |
| **One endpoint, keyed by source** | `dashboard`: `/ocs/v2.php/apps/dashboard/api/v1/widgets` and `/widget-items`, both returning `ocs.data` as an object keyed by widget id (`developer_manual/digging_deeper/dashboard.rst:462`, `:522`) | Read-only feeds one consumer renders together |
| Same again | `GET /ocs/v2.php/cloud/capabilities`, keyed by app | Read-only, fetched all at once |

Ours is the second kind: three read-only lists, no ids, no writes, one consumer wanting all of them
at once — which the app's own page already gets through `IInitialState`. The key names the source, so
per-source `updated_at` and `degraded` survive; `CONTEXT.md` is explicit that an *alerta sanitaria
ISP* is never merged with an *alerta vigente*. A fourth source adds a key, not a route: no version
bump from the route table, no container restart for `CachingRouter` (`README.md` § "Things that bite").

## Considered options

- **Keep `/alerts` and `/reports`, add `/sanitary-alerts`**, or alias them over the aggregate. The
  two-contracts cost 0.2.0 refused, paid for consumers that do not exist, re-paid per added source.
- **Put the accesos (tablero, EPIVIGILA) in the payload.** The three reasons to route anything
  through this app are our cache, our parser, our degraded flag; a hardcoded Power BI URL has none.
- **Copy `dashboard`'s `sinceIds`.** Premature at an hour of cache freshness and a ~30 KB payload.

## Consequences

- **`…/api/v1/alerts` and `…/reports` are gone, no aliases, no grace period.** Nobody consumed them;
  the same call 0.2.0 made. Incompatible, so it raises the minor: **0.3.0**.
- `scripts/smoke.sh` asserts both old routes return 404, so a re-added contract fails the check.
- A consumer wanting one source still receives all three — cheaper than the split's round trips.
- The key set is `Sources::ALL`: the registry, not this controller, decides what the contract holds,
  which is why `openapi.json` is generated from the code and never edited by hand.

## Amendment — the route takes parameters (2026-08-05, #51)

**Nothing above is withdrawn**; a call with no parameters answers as before, envelope for envelope.
Three optional parameters were added — `source`, `category`, `olderThan`; supply `source` and the
response carries that source alone, narrowed and capped at one page. Parameters, not a
`/sources/{id}` route, which would make the key set a URL fact instead of `Sources::ALL`. An unknown
`source` answers **404** rather than reaching `Sources::get()`'s default-less `match`: that loudness
is for an id added to `ALL` and nowhere else, not for a caller's typo, which must not be a 500.

**Its premise is withdrawn.** It was argued from "~357 KB of JSON in the HTML of every page load";
[ADR-0009](0009-the-isp-archive-ships-whole.md) measured the wire — the page is gzipped: 41 KB whole,
ISP payload 15,4 KB, ~126 KB projected at the full archive. The parameters stay, for other apps.
