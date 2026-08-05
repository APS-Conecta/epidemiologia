# Agent skills — what is specific to this repo

Three files lived here restating what the skills already do (how to run `gh issue create`, that a
label means what its name says). Cut to the facts an agent cannot infer, in #54.

**Domain docs.** Single-context: one `CONTEXT.md` and one `docs/adr/` at the repo root. No
`CONTEXT-MAP.md`, no per-context ADRs. Read the ADRs that touch the area you are about to change.

**Issue tracker.** GitHub Issues on `APS-Conecta/epidemiologia`, through the `gh` CLI. The repo runs
`/wayfinder` against it, so a map issue and its children are a real structure, not a convention.

**Triage labels.** The five canonical roles, each label string equal to its name: `needs-triage`,
`needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. Only `ready-for-agent` and `wontfix`
exist today; `/triage` creates the rest when it first needs them.

**Issue comments and commit messages are Spanish here**; everything else is English. That is the one
convention in this repo an agent gets wrong by default — see [`AGENTS.md`](../../AGENTS.md).
