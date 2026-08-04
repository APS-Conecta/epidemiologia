# Navigation groups do not collapse

The three navigation groups — MINSAL, ISP, Notificación — stay as `NcAppNavigationCaption` labels
over always-visible lists. They are **not** rewritten as `NcAppNavigationItem` parents with
`allowCollapse`, which is how Files nests its own navigation.

This ADR exists because the opposite was planned. It was raised as *"add the hide function Files
has"*, carried as a ticket, and dropped after measuring — so the measurement is recorded here rather
than lost with the ticket.

## Why not

**Nothing overflows.** Measured on the running instance: six entries at 34px and three captions at
38px come to **442px of content in a 506px column**, with the footer already at the bottom. On a
phone the navigation is a full-height drawer, so it has more room, not less. A disclosure control
that never has anything to relieve can only ever hide something a reader wanted.

**Two of the three groups hold one pane.** ISP has *Alertas sanitarias*; Notificación has
*EPIVIGILA*. A collapse arrow on a group of one is a control whose entire effect is to hide a single
row.

**`allowCollapse` needs the group label to be a destination.** It is a prop of
`NcAppNavigationItem` — it collapses *that item's children*, so the parent is a navigation entry
with a name, an icon, and something to open. Ours are publishers. Clicking "ISP" would have to open
either nothing, which is a dead control, or a per-institution pane invented to give the arrow
something to sit on.

**Files does not do what it looks like it does.** Measured beside us: Files' navigation has **17
entries and zero captions**, and exactly two collapsible items — *Compartidos* and *Árbol de
carpetas*. Both parents are themselves views you can open; the children are narrower views of the
same thing. Files nests destinations under destinations. It never nests destinations under a label.
Our groups are labels. The pattern does not transfer, and copying its shape without its premise
would produce the dead control above.

**The rest of Files' navigation we already match**, which is the other half of why there is nothing
to do here: entry height **34px** and label **15px/500** on both, measured on the same instance.

## Consequences

- [ADR-0005](0005-nav-captions-are-labels-not-headings.md) stands unchanged. A caption is a label
  for a list, and it stays one.
- The condition that would reopen this is **overflow**, not taste: if the navigation grows past the
  column — roughly ten entries at today's sizes — the right move is still not to collapse labels but
  to ask whether every pane earns a permanent row.
- Adding a source adds an entry, and possibly a fourth caption. That is 34–72px against 64px of
  slack, so the *next* source is the one that makes this worth re-measuring.
