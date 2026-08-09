# Navigation group captions are labels, not headings

**Status: accepted, 2026-08-02.**
The navigation groups the panes by institution using `NcAppNavigationCaption` **without**
`is-heading`, each caption's `headingId` wired to its `NcAppNavigationList` via `aria-labelledby` —
against the component's docs (*"Enable when used as a heading e.g. Before NcAppNavigationList"*).

`is-heading` renders `h{Math.max(2, headingLevel)}`, a floor of 2 with no ceiling, and in `App.vue`
the navigation precedes the content: `nav` would emit h2 "MINSAL" and h2 "ISP" above `main`'s h1
"Epidemiología" (visually hidden, from `pageHeading`) and its PaneHeader h2s — the *"heading levels
should only increase by one"* finding, a WCAG 1.3.1 issue in every audit tool. The app is otherwise
clean: three hand-written headings in the whole codebase, all `h2`
(`src/components/PaneHeader.vue:18`, `src/components/InicioPane.vue:31` and `:85`). A nav group label
is semantically a **label for a list**, not a section of the document, and Files — measured beside
us — emits no heading in its navigation at all.

> **Premise corrected 2026-08-02 (#26).** This ADR first said `NcAppContent` renders the *only*
> `<h1>`; measured on the running instance the page carries **two**, the header's "APS Conecta
> Gestión" and `pageHeading`'s "Epidemiología". The conclusion is unchanged.

## Considered options

- **`:heading-level="3"`.** Fixes the level-skip but not the ordering — they still precede the `h1`.
- **Drop the captions.** They are what turn the navigation from a tab bar into a structure.

## Consequences

- Two constraints measured on the rendered DOM, neither documented: grouped navigation cannot use the
  `#list` slot (captioned lists inside it render `ul > ul`), and each caption must be the first `<li>`
  of its list — `src/App.vue:9-25`. Collapsible `NcAppNavigationItem` parents were measured and
  dropped 2026-08-03, [ADR-0008](0008-nav-groups-do-not-collapse.md).
- **A screen-reader user loses H-key navigation between nav groups.** That is the price; the group is
  still announced, through the list's accessible name. The visual result is identical either way.
- Every `NcAppNavigationList` must carry `aria-labelledby` and every caption a unique `headingId`; a
  caption without its pairing is a visible group label with no relationship to its list.
- Verification is a heading-order check, not the console-warning check — the existing criterion
  (*"no `ariaLabel` warning"*) passes straight through this defect.
