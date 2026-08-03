# The OCS contract is one route keyed by source, not a route per source

`GET /ocs/v2.php/apps/epidemiologia/api/v1/sources` returns every source this app publishes in one
response, keyed by source id:

```json
{ "ocs": { "data": {
  "alerts":          { "updated_at": "…", "degraded": false, "items": [ … ] },
  "reports":         { "updated_at": "…", "degraded": false, "items": [ … ] },
  "sanitary_alerts": { "updated_at": "…", "degraded": false, "items": [ … ] }
}}}
```

This looks un-REST, so it is recorded here to stop the next reader from splitting it back into
`/alerts`, `/reports` and `/sanitary-alerts`. That split is what 0.2.0 shipped, and it was replaced
deliberately.

## Why

**Nextcloud itself splits this choice by what the data *is*, and is consistent about it.**

| Shape | Core example | Data |
| --- | --- | --- |
| Resource per endpoint | `groupfolders`: `/folders`, `/folders/{id}`, `/folders/{id}/acl`, … (13 paths) | Mutable things with ids, created and deleted individually |
| **One endpoint, keyed by source** | `dashboard`: `/ocs/v2.php/apps/dashboard/api/v1/widgets` and `/widget-items`, both returning `ocs.data` as an object keyed by widget id (`developer_manual/digging_deeper/dashboard.rst:462`, `:522`) | Read-only feeds one consumer renders together |
| Same again | `GET /ocs/v2.php/cloud/capabilities`, keyed by app | Read-only, fetched all at once |

This app's data is unambiguously the second kind: three read-only lists, no ids, no writes, and one
prospective consumer — the front page / centro de información — that wants all of them at once.
Nextcloud built the aggregate shape *for exactly that consumer*. Three endpoints would make it spend
three round trips reassembling what we already hold behind one cache, and the app's own page already
receives precisely this object through `IInitialState`.

**The key names the source, so access stays clear.** A consumer that only wants the ISP reads one
key. Per-source `updated_at` and `degraded` survive, which a flat merged list would destroy — and
`CONTEXT.md` is explicit that an *alerta sanitaria ISP* is a different kind of object from an *alerta
vigente* and is never merged with one.

**Growth stops touching the contract.** Adding a fourth source adds a key. No new route means no
version bump forced by the route table and no container restart for `CachingRouter`, which is the
trap `CHANGELOG.md` records for exactly this file.

## Considered options

- **Keep `/alerts` and `/reports`, add `/sanitary-alerts`.** Additive, breaks nothing. Rejected
  because it is the two-contracts cost the 0.2.0 entry explicitly refused, paid for consumers that do
  not exist, and because each added source would re-pay both release traps.
- **Keep the old routes as aliases over the aggregate.** Same objection, plus two shapes to keep
  correct forever.
- **Put the accesos (tablero, EPIVIGILA) in the payload too.** Rejected on the app's own test: `PLAN.md`
  §8 gives three reasons to route anything through this app — our cache, our parser, our degraded
  flag. A hardcoded Power BI URL has none of the three, so proxying it would add a contract and buy
  nothing. Adding a key later is additive if a consumer ever asks.
- **Copy `dashboard`'s `sinceIds`,** so a client can skip items it holds. Rejected as premature: an
  hour of cache freshness and a payload around 30 KB.

## Consequences

- **`/ocs/v2.php/apps/epidemiologia/api/v1/alerts` and `…/reports` are gone, with no aliases and no
  grace period.** Nobody consumed them; this is the same call 0.2.0 made and wrote down. It is an
  incompatible change, so it raises the minor: **0.3.0**.
- `scripts/smoke.sh` asserts both old routes return 404, so a re-added contract fails the check
  rather than quietly living alongside this one.
- A consumer that wants one source still receives all three. At roughly 30 KB behind an hour of
  cache, that is cheaper than the round trips the split shape would cost it.
- The response's key set is `Sources::ALL`. The registry, not this controller, is what decides what
  the contract contains — which is why `openapi.json` is generated from the code and never edited by
  hand.
