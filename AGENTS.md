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
| `nextcloud.log` lines — every `LoggerInterface` message | **English** |

**Commit messages and issue comments are English, from 2026-08-08.** They were Spanish, and
everything up to that date stays Spanish — history is not rewritten. The carve-out went because it
contradicted the paragraph above it: `gestion/AGENTS.md` puts commits in English, and *"nothing below
replaces them"* is what makes nesting this repo inside that one work at all. Between a promise and an
exception to it, the promise is the load-bearing sentence. Issue *bodies* written as specs were
already English, as #1, #25 and #37 are.

## Agent skills

Domain docs, the issue tracker and the triage labels: [`docs/agents/README.md`](docs/agents/README.md).
