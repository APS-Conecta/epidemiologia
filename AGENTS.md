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

**Commit messages and issue comments stay Spanish**, matching the existing log and tracker. Those
are the two deliberate exceptions, kept for the same reason: a history that switches language
halfway is harder to read than one that is consistently in either. Issue *bodies* written as specs
are English, as #1, #25 and #37 are — they are read alongside the code they describe.

## Agent skills

Domain docs, the issue tracker and the triage labels: [`docs/agents/README.md`](docs/agents/README.md).
