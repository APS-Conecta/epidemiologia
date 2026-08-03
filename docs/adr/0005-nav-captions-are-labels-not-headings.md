# Navigation group captions are labels, not headings

The navigation groups the panes by institution using `NcAppNavigationCaption` **without**
`is-heading`, with each caption's `headingId` wired to its `NcAppNavigationList` via
`aria-labelledby`. This deviates from the component's own documentation — its `is-heading` prop says
*"Enable when used as a heading e.g. Before NcAppNavigationList"* — so it is recorded here to stop
the next reader from "fixing" it.

## Why

`is-heading` renders `h{Math.max(2, headingLevel)}` — a floor of 2 with no ceiling. `NcAppContent`
renders the document's only `<h1>` from `pageHeading`, as `class="hidden-visually"`. In `App.vue` the
navigation precedes the content, so three captions would produce:

```
<nav>   h2 "MINSAL"   h2 "ISP"   h2 "Notificación"
<main>  h1 "Epidemiología"  (hidden-visually)
        h2 "Alertas epidemiológicas vigentes"     ← PaneHeader
```

Three `h2`s before the page's only `h1` — the standard *"page should begin with h1 / heading levels
should only increase by one"* finding, and a WCAG 1.3.1 issue in every audit tool. The app is
currently clean on this (one hand-written heading in the whole codebase), so adding the captions is
what would introduce the defect.

Semantically, a nav group label is a **label for a list**, not a section of the document. Marking it
up as a label is the honest reading, not merely the convenient one.

## Considered options

- **`:heading-level="3"`.** Fixes the level-skip but not the ordering — they still precede the `h1`.
- **Keep `is-heading` at h2 and accept the finding.** Defensible (the `h1` is visually hidden and
  Nextcloud ships the component this way) but it is a real finding, and this app already carries
  deferred a11y items in #19 and #20.
- **Drop the captions.** Would trade the fix for the defect: the captions are what turn the
  navigation from a tab bar into a structure, which was the whole point.

## Consequences

- **A screen-reader user loses H-key navigation between nav groups.** That is the price. The group is
  still announced, through the list's accessible name rather than through a heading.
- The visual result is identical — `NcAppNavigationCaption` styles the same either way.
- Every `NcAppNavigationList` must carry `aria-labelledby`, and every caption a unique `headingId`.
  A caption without its pairing is worse than no caption: a visible group label with no
  programmatic relationship to the list it labels.
- The verification for this is a heading-order check, not the console-warning check — the existing
  criterion (*"no `ariaLabel` warning"*) passes straight through this defect.
