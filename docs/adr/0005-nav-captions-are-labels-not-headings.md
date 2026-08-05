# Navigation group captions are labels, not headings

The navigation groups the panes by institution using `NcAppNavigationCaption` **without**
`is-heading`, each caption's `headingId` wired to its `NcAppNavigationList` via `aria-labelledby`.
This deviates from the component's own docs — its `is-heading` prop says *"Enable when used as a
heading e.g. Before NcAppNavigationList"* — so it is recorded to stop the next reader "fixing" it.

## Why

`is-heading` renders `h{Math.max(2, headingLevel)}` — a floor of 2 with no ceiling. In `App.vue` the
navigation precedes the content, so the two captioned groups would produce:

```
<nav>   h2 "MINSAL"   h2 "ISP"
<main>  h1 "Epidemiología"   (hidden-visually, from pageHeading)
        h2 "Alertas vigentes"     ← PaneHeader
```

`h2`s before the content's `h1` — the standard *"heading levels should only increase by one"* finding,
a WCAG 1.3.1 issue in every audit tool. The app is otherwise clean: three hand-written headings in the
whole codebase, all `h2`: `src/components/PaneHeader.vue:18` and `src/components/InicioPane.vue:31`
and `:85`. A nav group label is semantically a **label for a list**, not a section of the document, and
Files — measured beside us — emits no heading in its navigation at all.

> **Premise corrected 2026-08-02 (#26).** This ADR first said `NcAppContent` renders the document's
> *only* `<h1>`. Measured on the running instance, the page carries **two** — the header's "APS
> Conecta Gestión" and `pageHeading`'s "Epidemiología". The conclusion is unchanged.

## Considered options

- **`:heading-level="3"`.** Fixes the level-skip but not the ordering — they still precede the `h1`.
- **Keep `is-heading` at h2 and accept the finding.** Defensible (the `h1` is visually hidden and
  Nextcloud ships the component this way), but it is a real finding.
- **Drop the captions.** The captions are what turn the navigation from a tab bar into a structure.

## How it has to be built

Two constraints measured on the rendered DOM, neither in the component's docs: grouped navigation
cannot use the `#list` slot (one `<ul>` around everything, so captioned lists render `ul > ul`), and
each caption must be the first `<li>` of its list. `src/App.vue:9-25` carries both in full.
Collapsible `NcAppNavigationItem` parents were planned, measured and dropped 2026-08-03 —
[ADR-0008](0008-nav-groups-do-not-collapse.md).

## Consequences

- **A screen-reader user loses H-key navigation between nav groups.** That is the price; the group is
  still announced, through the list's accessible name rather than through a heading.
- The visual result is identical — `NcAppNavigationCaption` styles the same either way.
- Every `NcAppNavigationList` must carry `aria-labelledby`, and every caption a unique `headingId`; a
  caption without its pairing is a visible group label with no programmatic relationship to its list.
- The verification is a heading-order check, not the console-warning check — the existing criterion
  (*"no `ariaLabel` warning"*) passes straight through this defect.
