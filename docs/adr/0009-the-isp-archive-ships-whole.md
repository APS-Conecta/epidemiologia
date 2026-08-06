# The ISP archive ships whole, and here is the measurement that says so

**Status: accepted, 2026-08-05.** #45's spec, #49 and #51 all rest on one sentence — the archive is
too big for `IInitialState`, *"~357 KB of JSON in the HTML of every page load"* — and a review put
"put a seam under the panes' data" at the top of its recommendations. Nobody measured the wire.

## The measurement, 2026-08-05, against the live instance

| | |
|---|---|
| whole app page, gzipped, all 278 alerts | **41,0 KB** |
| ISP payload, raw | 59,1 KB |
| ISP payload, gzipped | **15,4 KB** (26 %) |
| per alert, gzipped | 91 bytes |
| projected at the full 1.423-alert archive | **~126 KB gzipped** |
| ISP pane DOM today (173 rows) | 1.125 nodes, 6,5 per row |
| projected DOM at 1.423 | ~9.254 nodes |

The 357 KB figure was uncompressed *and* about half the true raw size — the real raw projection is
~714 KB. It does not matter: the page has been served `gzip` all along, so that is **126 KB over the
wire, once per load, on a clinic LAN**, against a page that is already 41 KB.

## Decision

**The ISP archive ships whole in `IInitialState`. No slicing, no facet channel, no server-side
filtering, no data seam under the panes.**

Every alternative bought ~110 KB of compressed transfer at a permanent price: **server-side
filtering** costs a round trip per menu click, on lists a clinician flicks through; **a facet channel**
creates two sources of truth for "what exists"; **lazy background load** transfers the whole archive
anyway. The collision that killed all three: **the pane filters in the browser, over what it has
loaded, and builds its filter menus from the same array** — a partial load removes years from the
*Año* menu and shrinks the count, so the app lies about what exists.

## Consequences

- `src/main.js`'s comment claiming depth is fetched on demand is **false** and is corrected.
- The OCS narrowing from #51 stays, but its audience is **other apps** (ADR-0006), not this SPA: not
  dead code, a contract this app happens not to consume.
- The payload carries only what a reader sees: the revision fingerprint `h` is stripped on the way
  out — an internal cache field that reaches every browser is one nobody can change later.
- **Dropping `date` and deriving it in the browser** (−3,4 KB raw) was rejected: the saving is noise.
  The *timezone* defect it surfaced was real and is fixed separately.

## Reopen when — and only when — one of these is measured

- The ISP payload exceeds **200 KB gzipped** (roughly 2.200 alerts at today's 91 bytes each), or
- the ISP pane exceeds **~15.000 DOM nodes**, or a filter keystroke exceeds **100 ms** on the
  slowest machine a clinic actually uses, or
- a clinic runs the app over something that is not a LAN.

Reach for **windowing the list** then, not for paginating the payload: at 1.423 alerts the DOM is the
tighter constraint, and rendering fewer rows does not require the app to lie about what exists.
