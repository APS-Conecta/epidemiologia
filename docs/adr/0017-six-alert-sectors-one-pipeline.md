# Six alert sectors, one pipeline

**Status: accepted, 2026-09-28.** Decided by the owner over a design review held on 2026-09-27 and
2026-09-28, with the measurements below taken on the same days. Supersedes
[ADR-0016](0016-the-isp-has-one-job.md): the ISP's alertas are read again — three of its categories,
not the twenty-four — and the informe stays the ISP's one series, as ADR-0016 left it. Extends
[ADR-0014](0014-the-fuentes-follow-their-publishers.md) (each list is read where its publisher
publishes it) and [ADR-0015](0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md) (every
fuente persists, and the fetcher names itself).

## The request

Inicio is the intranet's shared *what is happening* board, for everyone in the organisation. The
owner asked for five more alert lists — the Departamento de Epidemiología's vigentes, the ISP's
ANAMED, Farmacovigilancia and Vigilancia de cosméticos, and the OPS/OMS's alertas y actualizaciones
epidemiológicas — each with its own pane and the same filters, kept current, all read by **one
pipeline with as few parsers as possible**, and Inicio rebuilt as one tile per list.

## What was measured

| fuente | address | from this VPS | from the owner's residential line |
|---|---|---|---|
| MINSAL, alertas vigentes | `degreyd.minsal.cl/alertas-sanitarias-y-epidemiologicas-vigentes-en-chile/` | 200, 13 alertas | — |
| MINSAL, Departamento de Epidemiología | `epi.minsal.cl/alertas-epidemiologicas-vigentes/` | 403, Cloudflare's block page | 200 — 78 PDF links, 77 alertas (one PDF is linked twice), 2026-09-27 |
| ISP, three alert categories | `www.ispch.gob.cl/categorias-alertas/{anamed,farmacovigilancia,vigilancia-de-cosmeticos}/feed/` | TCP timeout, and from a second network | 200 — 10, 10 and 3 items, 2026-09-27 |
| OPS/OMS, alertas y actualizaciones | `www.paho.org/es/alertas-actualizaciones-epidemiologicas` | 200, on a chain the instance's own anchors verify — 18 per page, 38 pages, 2026-09-28 | — |

Every parser is proven against those captures, committed byte for byte under `tests/unit/fixtures/`.

`epi.minsal.cl` is the host [ADR-0014](0014-the-fuentes-follow-their-publishers.md) measured closed
to five networks on 2026-09-15, the owner's own line among them. It answers that line again and still
refuses a datacentre: ADR-0014's reopen clause is half met — one network, not more than one. What this
ADR opens there is a second list, not a new address for `alerts`, which stays on degreyd's page.
Whether degreyd's eight links back to that host now resolve from a residential line was not
re-measured.

## Why ADR-0016 no longer decides this

ADR-0016 retired the ISP's alertas for three reasons, and none of them survives:

- **"Every byte read from the ISP is exposure to the hostile host."** The informe already reads that
  host, and the three feeds share its brake and its cooldown (D8): the first timeout of a pass holds
  all four, a queued refresh re-asks the host before it fetches, and a fuente with nothing stored costs
  one bounded attempt per cache lifetime (D9). The exposure is one host's, and bounded, as it was.
- **"The pane was a zombie in waiting."** That pane lived in a cache any app update empties. Since
  ADR-0015 every fuente persists to a table, a stream keeps what it has read (D6), and a sector with
  nothing to show shows its warning and its publisher's page, never *Sin alertas*.
- **"Its alertas sanitarias duplicated a category of information the vigentes list already answers
  for."** ANAMED's, Farmacovigilancia's and Vigilancia de cosméticos' alerts are about medicines and
  products, which MINSAL's lists do not publish — the owner's reading, and the three categories the
  owner asked for. The other twenty-one stay retired.

ADR-0016's own condition for a return — *"if the estate ever reopens durably"* — is not what happened:
the ISP still refuses this server. What changed is that a refusal is a state the app survives, per
sector, which ADR-0015 made the design goal.

## Decision

The numbering is the design's, and the code cites it (`ADR-0017 D8`).

### D1 — One pipeline class, one instance per fuente, built by a factory

Every alert fuente runs through `AlertRepository`, one instance each, configured by a readonly
`Fuente` and built by `AlertRepositories::repository($id)` — autowired and memoised. Nextcloud's
container keys a service by class name, so six configured instances of one class are one factory, not
six registrations. It replaces `MinsalRepository` with no behaviour change for degreyd: its keys, its
log sentences and its wire shape are what its suite pinned before the move.

### D2 — Three parsers behind `Parser`

`PageParser` reads degreyd's and epi's pages (`MinsalRepository`'s parse, moved), `FeedParser` the
ISP's category feeds (the retired `IspRepository`'s parse, restored), `PahoParser` the OPS/OMS's list.
[ADR-0011](0011-no-l10n-file-and-no-seam-without-an-adapter.md)'s rule is met: three adapters, six
fuentes.

### D3 — The informe stays a sibling

`IspReportRepository` keeps its class: a series of weekly documents, keyed by (year, semana) and kept,
is a rule the pipeline does not have. It shares the ISP host's brake and cooldown, and gains the same
revalidation guard and cold path as the pipeline.

### D4 — `Sources` keeps one match arm per id per method

Default-less, at seven ids as at two: an id in `ALL` without an arm throws `UnhandledMatchError`, and
`SourcesTest` walks `ALL` through every method. [`docs/fuentes-checklist.md`](../fuentes-checklist.md)
lists the arms.

### D5 — The alert table is altered in place

`Version001100Date20260928000000` adds `fuente` (default `'alerts'`, which labels degreyd's rows),
swaps the primary key to (fuente, url), adds `day` and `tipo`, and widens `title` to 1000 characters —
epi's longest is 270. It is the first primary-key swap on a populated table in the organisation, and
Nextcloud has no down-migrations: the rollback is the dump taken before the upgrade.

### D6 — Two storage rules, told apart by the `Fuente`

An **in-force** page (degreyd's, epi's) names what is in force today: its set is replaced whole, and a
withdrawn alert vanishes with it. A **stream** (the ISP's categories, the OPS/OMS's list) shows only
its latest posts: each item is upserted on (fuente, url) and the fuente serves its table read back,
newest first; a storage failure serves the fresh reading over the last copy. No lock — the key is the
backstop. `Fuente::$backfill` is the one field that tells the two apart.

### D7 — A stream walks two older pages, once

The nightly deep pass walks a stream's backfill pages — WordPress's `?paged=2` and `?paged=3`,
Drupal's `?page=1` and `?page=2` — once it has a stored copy and while its host is neither braked nor
cooling, then sets `backfilled_<id>` in the app config. A 404 is exhaustion; anything else unreadable
is retried the next night. App config and not the cache, for the informe's cursor's reason: an app
update empties the cache.

### D8 — One brake and one cooldown per host

The grain is the host: `freno-{host}`, `cooldown-{host}`. degreyd keeps the key `alerts`, so its keys
are byte for byte what they were; epi is `epi`, the OPS/OMS `paho`, and the ISP `isp`
(`AlertRepositories::ISP_HOST`), shared by the informe and the three feeds. A queued revalidation
re-asks its host's gates before it fetches, so one timeout also stops the siblings' queued jobs.

**Amended 2026-09-29 (0.11.2): the sondeo follows the host too.** It asks through the brake by
design, so it had kept the fuente grain: on the lab, one unreachable ISP cost four 15-second timeouts
in a row and would have rung four bells for one outage. `probe()` now answers one of three verdicts
(`Probe`). When a host gives no answer at all, `ProbeJob` does not ask that host's other fuentes
again in the same pass, and records them as failing with it. A host that answered, even with a
refusal, holds nobody, because each address can answer differently and the next answer is what lifts
a brake. Each fuente keeps its own failure count, and a host's failing fuentes ring **one** bell
together, under the first of them, naming the rest.

### D9 — A cold fuente fetches synchronously once per cache lifetime

The first read with nothing stored sets `cold-{id}` and fetches, as before. A later one, still with
nothing stored, dispatches a revalidation and answers `{items: [], fetched_at: null, degraded: true}`
at once. The informe's cold path follows the same rule. A blocked host costs one bounded attempt per
cache lifetime on the read path, then nothing.

### D10 — Two jobs: an hourly warm pass and a nightly deep pass

`WarmJob` — a new class, hourly, `TIME_SENSITIVE` — asks every fuente through its reader. `RefreshJob`
— daily, `TIME_INSENSITIVE` — runs the deep pass over `Sources::ACCUMULATING`. A new class, because
`oc_jobs.time_sensitive` starts at 1, the first run of an insensitive job writes 0, nothing writes 1
again and an upgrade does not reset it, while an insensitive job runs only inside the maintenance
window (05:00–09:59 UTC on Gestión). `occ background-job:list` prints no interval; `oc_jobs` and
`occ background-job:execute <id>`'s *Next execution* show it (measured on Nextcloud 34, 2026-09-28).

### D11 — Wire shape

In-force items stay `{title, url, year, month}`, byte for byte for degreyd. Stream items add `date`,
the publisher's day (`YYYY-MM-DD`); the OPS/OMS's add `tipo`. A key a fuente does not have is absent,
never null. `openapi.json` is generated from `ApiController::sources()`'s `@return`, so each key
entered that docblock and the spec was regenerated.

### D12 — Ids and presentation order

`Sources::ALL` is `alerts`, `epi_alerts`, `reports`, `isp_anamed`, `isp_pharmacovigilance`,
`isp_cosmetics`, `paho_alerts`, in the order the app presents them. `SEARCHABLE` is the six alert ids;
`ACCUMULATING` is `reports` and the four streams. `sanitary_alerts` is never reused:
`RetireSanitaryAlerts` wipes its residue on every upgrade.

### D13 — The OPS/OMS's `tipo` is the title's first words

Folded for case, accents and spacing: a title opening *alerta epidemiológica* is `alerta`, one opening
*actualización epidemiológica* is `actualizacion`, anything else `otro` — 32 of the 36 measured fold to
the first two. It is the publisher's own word for its document, not a severity and not a disease tag,
so [`CONTEXT.md`](../../CONTEXT.md) still has no word for either.

### D14 — Frontend shape

Registry entries gain `source`, the backend id: six sector panes, each one instance of a generic
`AlertsPane`, and a group `ops`. Radio names come from the pane id (`${pane}-year|month|tipo`), and a
Tipo menu appears wherever an item carries `tipo`. `App.vue` writes the OPS/OMS's captioned block out by
hand, like the two before it. Inicio is one tile per sector, in navigation order, then the informe's
card.

**Amended during the design, twice.** The escape address is per list, not per publisher: `SITIOS`
(`src/pure.js`), keyed by backend id, because one `url` per publisher would have sent the ANAMED pane's
reader to the informe's page. And `FUENTES` is `{name, caption, count}` per publisher: `caption` is the
publisher standing alone — *ISP*, not *el ISP* — read by the navigation's captions and by Inicio's
tiles, one string for both.

### D15 — Labels

MINSAL: Alertas vigentes · **Departamento de Epidemiología** · Tablero ETI e IRAG · EPIVIGILA.
ISP: Circulación de Virus Respiratorios · **Medicamentos (ANAMED)** · **Farmacovigilancia** ·
**Vigilancia de cosméticos**. **OPS/OMS**: **Alertas y actualizaciones**. Tipo: Alerta · Actualización ·
**Otra publicación**.

### D16 — Consequences accepted, not changed

`ProbeJob`, `SourcesReachable` and `Notifier` scale over `ALL` untouched, so on an install a publisher
refuses — this VPS — up to five fuentes (epi, the informe and the ISP's three) ring the admin bell on
days 2, 7 and 30, and the setup check lists each. The search provider keeps its `kind · year` line.
`fold()` lives in `AlertRepository`.

## Not built

- Notifications to staff on new alerts; facets by ISP sub-type or by disease; deduplication across
  sectors — the same ISP post filed under two categories is one row in each, where the ISP filed it;
  any severity ranking.
- The other twenty-one ISP categories, or a crawl deeper than two pages: ADR-0016's retirement of the
  archive ([ADR-0009](0009-the-isp-archive-ships-whole.md)) stands.
- Any evasion of a block — a relay, a proxy, a borrowed User-Agent
  ([ADR-0015](0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md) §4).
- A loop over the navigation's groups: the third block is written out.

## Consequences

- **A datacentre install shows four sectors with nothing in them, and says why.** From this VPS the
  Departamento de Epidemiología's and the ISP's three sectors show their warning and their publisher's
  page; a clinic on a residential line reads them. The ISP's leaf certificate, which expires
  2026-11-23 (`certs/README.md`), now gates four fuentes instead of one.
- **[ADR-0008](0008-nav-groups-do-not-collapse.md)'s trigger is reached.** The navigation holds Inicio
  and nine rows under three captions — the ten entries it named. `scripts/layout.mjs` check 7
  re-measures the column at every viewport, and ADR-0008 stands for as long as that check passes.
- **The freno's grain is the host, not the request** (D8) — [`CONTEXT.md`](../../CONTEXT.md) says so
  now. [ADR-0010](0010-the-freno-and-the-backfill-stay-inside.md)'s condition for extracting the brake
  was met before this ADR, when two repositories needed it.
- **Two of [ADR-0013](0013-family-icons-where-the-isp-names-the-family.md)'s glyphs return**: the pill
  for ANAMED's medicines and the lipstick for cosmetics.
- **The layout gate's sector checks run without an instance.** Checks 6, 10, 11 and 12 of
  `scripts/layout.mjs` run against the built bundle on a synthetic page; check 7 and the informe's
  width need Nextcloud's own CSS, so they are judged on an instance only ([`README.md`](../../README.md),
  *Development*).
