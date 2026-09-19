# The ISP has one job: the informes. MINSAL owns the alertas

**Status: accepted, 2026-09-19.** Decided by the owner in the same review that produced
[ADR-0015](0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md), and recording the
consequence of it here rather than there, because this is a *source ownership* decision in
[ADR-0014](0014-the-fuentes-follow-their-publishers.md)'s line, not a persistence one.

## The map, before and after

`Sources::ALL` enumerates three fuentes today. This ADR ends that:

| fuente | publisher today | after this ADR |
|---|---|---|
| `ALERTS` — las alertas epidemiológicas vigentes | MINSAL (`degreyd.minsal.cl`) | **stays** |
| `REPORTS` — el informe semanal de Circulación de Virus Respiratorios | ISP | **stays** |
| `SANITARY_ALERTS` — las alertas sanitarias del ISP | ISP, 24 category feeds | **retires** |

The app reads **two publishers, and each has one job**. MINSAL answers what is in force now; the
ISP answers the weekly series. Nothing reads ISP alertas.

## Why the third fuente retires

**Every byte read from the ISP is exposure to the hostile host.** Measured 2026-09-19
([ADR-0015](0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md)): the ISP times out
from two independent datacentre vantages while MINSAL answers 200 in the same second — and
[ADR-0014](0014-the-fuentes-follow-their-publishers.md) already measured this estate closing
itself off, per-hostname, over months. Of the ISP's two roles, only one carries data no other
publisher has: the informes. Its alertas sanitarias duplicated a category of information the
vigentes list already answers for, from a host that works.

**The pane was a zombie in waiting.** [ADR-0012](0012-the-archive-is-a-cache-and-any-app-update-empties-it.md)
records the archive as cache that any app update empties — and a blocked host is a host that cannot
refill it. Left in place, `SANITARY_ALERTS` degrades one app update from now into a pane that is
empty and cannot say why it is empty. Retiring it converts a slow death into a clean removal.

This is the owner's call, made directly — not a usage measurement, and it does not claim to be
one. What is measurable was measured; what is a judgement is the owner's.

## What the retirement costs

- `IspRepository` — 1,022 lines of twenty-four-feed machinery: the freno, the lock, the backfill,
  the parser ([ADR-0010](0010-the-freno-and-the-backfill-stay-inside.md) records why they live
  inside it).
- The `IspPane` and the family navigation under the ISP caption
  ([ADR-0013](0013-family-icons-where-the-isp-names-the-family.md) rides ISP family naming).
- Search over the alertas (`SEARCHABLE`), its narrowing by category and date (`NARROWABLE`), and
  the `TERMS` vocabulary.
- `ProbeJob`'s third probe and the fixtures that pin the feed reads (`isp-anamed.xml`,
  `isp-retiro.xml`).

The historical archive itself — [ADR-0009](0009-the-isp-archive-ships-whole.md)'s projected
1.423 alerts — stops being readable in-app. It is not lost: the machinery stays in git, and if the
estate ever reopens durably the fuente can be resurrected from history. ADR-0009 and ADR-0012
remain true of the thing they measured; the thing retires.

## What the navigation looks like after

The ISP caption keeps its single informe row. [ADR-0008](0008-nav-groups-do-not-collapse.md)
already rules the case: groups do not collapse, and a caption with one row is a caption with one
row.

## Consequences for ADR-0015

The persistence doctrine's scope is now exactly the two surviving fuentes: `REPORTS` as the
(year, semana) series with its documents kept, `ALERTS` as MINSAL's durable in-force set. The word
*archive* retires with the pane — [ADR-0015](0015-the-fuentes-persist-and-the-fetcher-introduces-itself.md)
already names the documents it keeps *documents*, and now nothing else needs the word.
