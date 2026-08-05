# AGENTS.md — Epidemiología (canonical repo rules)

Rules for any AI agent working in this repo. `CLAUDE.md` imports this file.

**This repo is nested inside another one.** It is cloned into `gestion/apps/epidemiologia`, which
`gestion` gitignores and bind-mounts to `/var/www/html/custom_apps`, so `gestion/AGENTS.md` loads as
parent context whenever you work here. Its rules still apply; nothing below replaces them.

**Language.** One line decides it: **Spanish is what a clinician reads. Everything else is English.**

| Surface | Language |
| --- | --- |
| UI strings — `t('epidemiologia', …)`, `n(…)`, anything rendered in the app | **Spanish** |
| Code, identifiers, comments, test names, docblocks | **English** |
| `README.md`, `CHANGELOG.md`, `CONTEXT.md`, `docs/` | **English** |
| `scripts/` output | **English** |

**Commit messages and issue comments stay Spanish**, matching the existing log and tracker: a history
that switches language halfway is harder to read than one consistently in either. Issue *bodies*
written as specs are English, as #1, #25 and #37 are.

## Agent skills

Domain docs, the issue tracker and the triage labels: [`docs/agents/README.md`](docs/agents/README.md).
