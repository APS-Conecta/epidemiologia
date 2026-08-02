# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

Edit the right-hand column to match whatever vocabulary you actually use.

## State on `APS-Conecta/epidemiologia`, checked 2026-08-02

Two of the five already exist under exactly the canonical names, so nothing here creates a duplicate:

- **`ready-for-agent`** — exists, green `#0E8A16`, described *"Spec is complete and unambiguous; an
  agent can implement it"*. Already applied to issue #1.
- **`wontfix`** — exists (GitHub's default label).
- **`needs-triage`**, **`needs-info`**, **`ready-for-human`** — do not exist yet; `/triage` creates
  them on first use.

The `wayfinder:*` labels are a **different vocabulary** and are deliberately not mapped here. They
mark a ticket's *type*; these mark its *state*. See `issue-tracker.md`.
