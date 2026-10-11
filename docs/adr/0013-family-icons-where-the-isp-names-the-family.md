# Family icons where the ISP names the family, and none where it does not

**Status: superseded.** The family navigation it governs left with `IspPane` and `familias.js` when
[ADR-0016](0016-the-isp-has-one-job.md) (2026-09-19) retired the ISP's alertas. Accepted 2026-08-07.
This narrows a rule `App.vue` has carried since #52. That rule was
right when it was written and half of it still is; the half that is not was falsified by the module
written two issues later.

## The rule, and what it claimed

One icon for all six ISP navigation rows, on the grounds that *"five invented glyphs would assert a
visual taxonomy the ISP does not publish"*. That is the same principle #1 applies to MINSAL disease
tags: a clinician reads what the app groups as what the source declared.

## What falsified it

`familias.js` opens by stating the opposite, with a count:

> 16 of its 24 category slugs name their family in the slug itself — the `-dp` suffix for
> dispositivos, `cosmet…`, `desinfectante…`, and the medicamentos cluster. Grouping those is reading
> the ISP's naming, not inventing a taxonomy.

So four of the five families — **dispositivos, cosméticos, desinfectantes, medicamentos** — are the
ISP's own nomenclature, read off its URLs. Giving each a glyph asserts nothing the publisher has not
already asserted. The fifth is `otros`, whose label is literally *"Sin familia declarada por el
ISP"*, and there the original reasoning holds exactly: a glyph for "no declared family" would invent
the classification the rule exists to prevent.

The rule was written when the navigation had one ISP row. It was generalised to six in #52 without
being re-derived against the module that had just defined what a family is.

## Decision

**A distinct icon for each of the four families the ISP names. No glyph for `otros`.**

`Pill` · `Stethoscope` · `Lipstick` · `Spray` — from the icon set the whole suite uses, chosen for
what the ISP says the family contains, not for how the alerts inside it feel.

The pane's own row (`Alertas del ISP`, the whole archive) keeps `Pill`, because it is not a family:
it is the archive, and its icon is the app's, not a classification.

## Consequences

- **`otros` still reserves its icon's width.** `NcAppNavigationItem` renders the icon slot with no
  column of its own, so an absent icon shifts that row's label left against the four above it —
  checked in the component's compiled template, not assumed. The row therefore renders an empty box
  of the icon's size. An empty box is not a glyph; it asserts nothing, and the list still lines up.
- **The icon map lives in `App.vue`, not in `familias.js`.** `familias.js` is plain data and its
  tests run under vitest's `node` environment; importing Vue single-file components into it would
  drag a component runtime into a module deliberately kept free of one — the same split
  `src/filters.js` records against `src/state.js`. The navigation is the only consumer.
- **Adding a family is now a decision about its icon too.** At runtime a missed lookup degrades
  safely — the empty box, never a borrowed glyph — but `family-icons.test.js` fails until the new
  family has one, so the blank row cannot ship unnoticed among four that are iconed. That test also
  ties the map's keys back to `familias.js`, because a rename there would otherwise drop a glyph in
  silence: the two files hold one fact, which is the shape `familias.js` was itself written to stop
  (#52).

## Reopen when

- **the ISP stops naming families in its slugs**, which is what makes four of these readable rather
  than invented — the grouping itself would go with it, and `familias.js` is where that breaks first;
- **`otros` acquires a declared meaning**, at which point it is a family like the others and gets an
  icon on the same grounds;
- **a family's icon has to carry severity or urgency**, which is a different assertion entirely and
  is not one either publisher makes.
