# AGENTS.md — Epidemiología (canonical repo rules)

Rules for any AI agent working in this repo. `CLAUDE.md` imports this file, mirroring the parent
platform repo's shape.

**This repo is nested inside another one.** It is cloned into `gestion/apps/epidemiologia`, which
`gestion` gitignores and bind-mounts to `/var/www/html/custom_apps`. `gestion/AGENTS.md` therefore
loads as parent context whenever you work here — its rules (language split, secrets handling,
"do not touch" siblings) still apply. Nothing below replaces them; it adds.

**Language.** Code, identifiers, test names and `docs/` in **English**. UI strings (`t('epidemiologia', …)`)
and code comments in **Spanish**. `README.md` and `CHANGELOG.md` are Spanish because the clinic reads
them. This is the standing rule from the map, issue #2.

## Agent skills

### Issue tracker

GitHub Issues on `APS-Conecta/epidemiologia`, via the `gh` CLI; the repo already runs `/wayfinder`
against it. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
