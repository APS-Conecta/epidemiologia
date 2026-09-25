# The fuentes persist, and the fetcher introduces itself

**Status: accepted, 2026-09-19.** Decided by the owner in a review of the connection layer, with
live measurements taken the same day. This ADR extends
[ADR-0014](0014-the-fuentes-follow-their-publishers.md) — it does not reverse it: `alerts` stays a
page read (RSS was measured and rejected there, and that reasoning stands), and this ADR's feed
decision is scoped to `reports` alone.

## The requirement, in the owner's words

The connection to the ISP and to MINSAL must be *persistent* and *auto-renewing*, and it must work
for **any user who installs the app on another machine** — the app is not this VPS. And the fetcher
must be a correct visitor: a bot the publishers have no reason to block.

## What was measured on 2026-09-19

| vantage | target | result |
|---|---|---|
| this VPS (datacentre, AS47583) | `degreyd.minsal.cl/alertas-sanitarias…` | **200** |
| this VPS (datacentre, AS47583) | `www.ispch.gob.cl`, every path tried (`/virusrespiratorios/`, the `/biomedico/…/informes-virus-respiratorios/` page, both `/feed/` forms) | **timeout** — exit 28, no response to read |
| an unrelated fetch host in a different network | `www.ispch.gob.cl` | **failed** |
| the owner's browser, Chilean residential | the informes page, `…informes-virus-respiratorios/?y=2026` | **loads** — the owner browses and reads it |

Two datacentre networks cannot load the page and a residential line in Chile can, three days after
[ADR-0014](0014-the-fuentes-follow-their-publishers.md) measured the ISP as reachable. Whether
that is a tightened Cloudflare rule on datacentre ASNs or a transient outage is **not settled from
here** — from this box the question cannot even be asked. What is settled is the design
consequence: **"blocked from the dev VPS" must be a normal, survivable state**, because the installs
that matter are clinics on residential lines, and the publisher's posture has changed three times in
a year (ADR-0014: closed per-hostname, staggered over months; 2026's own captures show 88 of 90
requests answered by a block page).

## Decision

### 1. `reports` reads a feed when the publisher offers one, never a year-scoped page

The informes are a stream — one post per semana epidemiológica — and the year-scoped page
(`?y=2026`) is the fragile way to read a stream: the year is in the URL, so every New Year is a
breaking change unless the app re-derives it, and the page is HTML whose labels are the parser's
only anchor. A WordPress feed on the same estate carries no year at all, gives every item a
`pubDate` (the **year of a semana comes from its publication date, never from a URL**), and survives
restructures one path deeper than a page scrape does. `IspRepository` already parses
`categorias-alertas/%s/feed/` — the machinery, the certificate handling, and the lock exist
([ADR-0010](0010-the-freno-and-the-backfill-stay-inside.md)).

`alerts` keeps ADR-0014's page read. A fixed document is not a stream; the rejection stands.

**Open, blocked on vantage:** whether the current informes page exposes `/feed/`, and whether
`/virusrespiratorios/` still answers, are verifiable only from a line the ISP will talk to. Both
checks are one browser visit each. Until then the feed URL is *to be confirmed*, and the page read
remains the fallback — the doctrine is about shape, not about one address.

### 2. Both fuentes land in the database; the cache becomes what it always should have been

One migration, two tables, shipped with the app:

- **`reports`** persists as the series it is — keyed **(year, semana)**, year from `pubDate`, with
  title and link, **and the linked document is kept: fetched once on first sight into the app's
  `IAppData`, its path and content hash on the row.** The accumulation that today lives only in a
  30-day `KEEP_FOR` cache becomes durable: app updates stop emptying it (superseding
  [ADR-0012](0012-the-archive-is-a-cache-and-any-app-update-empties-it.md) for this data), Redis
  flushes stop eating it, and a fresh install backfills what the feed still offers on its first
  successful fetch (paged, until the publisher stops answering — the backfill precedent is
  [ADR-0010](0010-the-freno-and-the-backfill-stay-inside.md)) — archiving every document the
  backfill names, never re-downloading one it has (the hash changes only when the publisher
  edits a published informe, and that is worth knowing).

  A link is a promise the publisher can break. [ADR-0014](0014-the-fuentes-follow-their-publishers.md)
  measured what that costs: everything the previous publisher posted after 2025-10-11 "exists at
  no other address, in no archive, in no cache" — gone, for everyone, forever. One polite GET per
  semana is the insurance that keeps an install's history whole when the next publisher dies;
  an install that saw a semana can always show it again. Not *the archive* — that word is the
  alertas payload's ([ADR-0009](0009-the-isp-archive-ships-whole.md)) — these are the *documents*.
- **`alerts`** persists the in-force set — keyed by URL, the durable last-good copy of the same
  snapshot the cache serves today. MINSAL answers 200 for now; this is the same failure class one
  hostname behind the last one, and the fix is the same shape.

The install's cache (`createDistributed`) stays exactly where it is — the hot path. What ends is the
cache being the *only* store. On a vanilla Nextcloud the "distributed" cache is the database cache
or APCu, not Redis; `KEEP_FOR = 30 days` was never a guarantee an arbitrary install could honor.
Database tables via this app's migration are the one store every install has.

### 3. Nothing depends on this VPS

The persistence, the probe, and the fetch ride Nextcloud's own abstractions only: `TimedJob`
([ProbeJob](../../lib/BackgroundJob/ProbeJob.php) and whatever refresh job follows),
`IClientService` with [CertificateBundle](../../lib/Service/CertificateBundle.php), the migration
system, `IAppConfig`. No systemd timer, no cron line on one machine, no path under
`/opt/aps-conecta-org`, no assumption that the install's cache backend is Redis. A clinic
installing this app on any box gets the same behaviour — including the one this dev VPS cannot
exercise: fetching from a residential line the publisher will actually serve.

### 4. The fetcher introduces itself, and takes no for an answer

A bot the publishers have reason to block is a connection that dies on the publisher's side and
looks, from the app's side, exactly like an outage. So the fetcher, on every request:

- **identifies itself** — a `User-Agent` that names this app and gives a contact, the way the
  Territorio app learned to introduce itself to OpenStreetMap. Anonymous is not a right;
- **rides the freno** — the rate limit is not optional machinery ([ADR-0010]);
- **backs off, does not hammer** — the `COOLDOWN` pattern stays: one failure is remembered, and
  the next page load does not retry the dead host;
- **stops retrying when a host says no** — a 403 from Cloudflare is an answer, not a challenge;
  the probe records it, admins hear about it on the [ProbeJob](../../lib/BackgroundJob/ProbeJob.php)
  schedule, and the app serves its database.

## Consequences

A fresh install during a publisher outage starts empty, and **that is correct** — the same doctrine
as ADR-0014's, one layer down: nothing in this app can conjure data the publisher has not served
*to this install*. The alertas are never seeded: shipping a stale in-force list is a clinical
hazard, worse than an empty pane that says why it is empty. What a fresh install gains is the
backfill on first success, and what every install gains is that its history stops evaporating at a
30-day TTL or an app update.

The year-scrape crash class — «the website right now is just 2026» — is gone twice over: the feed
carries no year to get wrong, and the series keys on (year, semana), so 2027's Semana 1 can never
collide with 2026's.
