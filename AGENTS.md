# AGENTS.md — Epidemiología (canonical repo rules)

Rules for any AI agent working in this repo. `CLAUDE.md` imports this file, mirroring the parent
platform repo's shape.

**This repo is nested inside another one.** It is cloned into `gestion/apps/epidemiologia`, which
`gestion` gitignores and bind-mounts to `/var/www/html/custom_apps`. `gestion/AGENTS.md` therefore
loads as parent context whenever you work here — its rules (language split, secrets handling,
"do not touch" siblings) still apply. Nothing below replaces them; it adds.

**Language.** One line decides it: **Spanish is what a clinician reads. Everything else is English.**

| Surface | Language |
| --- | --- |
| UI strings — `t('epidemiologia', …)`, `n(…)`, and anything rendered in the app | **Spanish** |
| Code, identifiers, comments, test names, docblocks | **English** |
| `docs/`, `CONTEXT.md`, `docs/adr/` | **English** |
| `README.md`, `CHANGELOG.md` | **English** |
| `scripts/` output | **English** |

`README.md` and `CHANGELOG.md` used to be Spanish, on the reasoning that "the clinic reads them".
They do not: a clinician reads the app, and these two are read by whoever maintains it. Settled
2026-08-03 (#37) and both files converted in the same commit.

**Commit messages and issue comments stay Spanish**, matching the existing log and tracker. Those
are the two deliberate exceptions, kept for the same reason: a history that switches language
halfway is harder to read than one that is consistently in either. Issue *bodies* written as specs
are English, as #1, #25 and #37 are — they are read alongside the code they describe.

> **Resolved 2026-08-03.** This file used to flag a contradiction: the map's standing rules
> (issue #2) said *"code comments in Spanish"* while the entire codebase wrote them in English. The
> table above settles it in favour of the code — comments are English, and no file needs converting
> because none was ever Spanish.

## Agent skills

### Issue tracker

GitHub Issues on `APS-Conecta/epidemiologia`, via the `gh` CLI; the repo already runs `/wayfinder`
against it. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
