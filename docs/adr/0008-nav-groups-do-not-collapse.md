# Navigation groups do not collapse

The navigation's captioned groups — MINSAL and ISP — stay as `NcAppNavigationCaption` labels over
always-visible lists, **not** `NcAppNavigationItem` parents with `allowCollapse`, which is how Files
nests its own navigation. The opposite was planned, carried as a ticket, and dropped after measuring.

**Nothing overflows.** Measured on the running instance when this was written — six entries at 34px
and the captions at 38px — **442px of content in a 506px column**, footer already at the bottom;
re-measured after #52 below. On a phone the navigation is a full-height drawer, so it has more room,
not less. A disclosure control with nothing to relieve can only hide something a reader wanted.

**A collapse arrow on a small group hides a single row.** The ISP group held one entry when this was
written; it holds six since #52, and MINSAL holds four, EPIVIGILA among them rather than in a group
of its own.

**`allowCollapse` needs the group label to be a destination.** It is a prop of `NcAppNavigationItem`
and collapses *that item's children*, so the parent is a navigation entry with a name, an icon and
something to open. Ours are publishers: clicking "ISP" would open nothing, a dead control, or a
per-institution pane invented to give the arrow something to sit on.

**Files does not do what it looks like it does.** Measured beside us: **17 entries, zero captions**,
exactly two collapsible items — and both parents are views you can open, whose children are narrower
views of the same thing. Files nests destinations under destinations, never under a label. The rest
of its navigation we already match: entry height **34px**, label **15px/500**, same instance.

## Consequences

- [ADR-0005](0005-nav-captions-are-labels-not-headings.md) stands unchanged: a caption is a label for
  a list and stays one.
- The condition that would reopen this is **overflow**, not taste: if the navigation grows past the
  column — roughly ten entries at today's sizes — the right move is still not to collapse labels but
  to ask whether every pane earns a permanent row.
- Adding a source adds an entry, and possibly a third caption: 34–72px against the ~144px of slack the addendum below measures, so the
  *next* source is the one that makes this worth re-measuring.

## Still true after the ISP gained five families (2026-08-05, #52)

#52 added five family entries and a caption to the ISP, so the arithmetic was re-run. **Measured on
the built app, at all three viewports:** every navigation list together is **538px**, in a column of
**682px** at 360×740 and more above that. It fits. The alternative #52 rejected is why this is worth
recording: all 24 ISP categories in the column instead measured **1.134px against a 506px column**,
which would have forced collapsing groups and retired this ADR.

**The measurement is now a gate**, not a note — `scripts/layout.mjs` check 7 sums the lists against
the column at every viewport. A comment cannot notice a seventh row; that check can.
