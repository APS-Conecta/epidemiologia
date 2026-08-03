# Tests follow branching, not layering

A class in `lib/` gets a unit test when it contains a **decision** — a branch, a loop over a list, a
fallback, or a policy expressed as a dependency. It is left to `scripts/smoke.sh` when it only
delegates.

This replaces the rule the repo carried until now: *"the repository is the single seam"*, with
everything else treated as thin glue not worth testing.

## Why

**The old rule was disproved by three defects, all of which shipped past a green suite.** When the
ISP arrived as a third fuente, four of the seven places that enumerate fuentes were updated. The
three that were not were all in the supposed "thin glue":

| Class | What it actually held | What shipped |
| --- | --- | --- |
| `Notifier` | a ternary on the feed id | an ISP outage rendered as *"…desde MINSAL"* |
| `SourcesReachable` | a loop over a hardcoded list | Visión general green through an ISP outage |
| `AlertProvider` | a policy choice in its constructor | 60 alertas sanitarias absent from global search |

None of them was thin. They simply were not the repository.

**And the suite did not notice**, which is the sharper half. `ProbeJobTest` asserted that
`probe_failures_sanitary_alerts` reached 3, and passed, while the notificación that counter produces
named the wrong institution. The test checked the plumbing; nobody had asserted the sentence.

Issue #25 already stated the principle — *"a good test asserts what a clínico/a or a consuming app
could observe"* — so the failure was not a missing idea. It was a layering rule that told us which
classes to skip, and the layer boundary did not match where decisions live.

## Considered options

- **Test every class in `lib/`.** No judgement call about what counts as a decision. Rejected: three
  of the five untested classes only delegate, and `smoke.sh` already asserts their observable
  behaviour against the running instance — every fuente key in the payload, the OCS envelope, the
  412 without the header, the frame-domain header, the sondeo being registered. Unit-testing them
  would assert delegation twice and observable behaviour zero extra times.
- **Keep the single-seam rule and push assertions into `Sources`.** Cheapest, and honours what was
  written down. Rejected: the registry cannot express *"the status row reports an ISP outage"*,
  which is exactly the sentence that was missing.
- **Rewrite the existing tests that assert internals.** Rejected as a separate concern: those tests
  are green and their assertions are insufficient rather than wrong. The evidenced gap was absent
  assertions, and a large diff across a passing suite carries its own risk.

## Consequences

- `SourcesReachable` and `AlertProvider` gain tests. `ApiController`, `PageController` and
  `Application` stay untested by unit tests **on purpose**, and that is now a reasoned position
  rather than an omission: they delegate, and `smoke.sh` covers what they produce.
- **New tests assert what a person could observe** — the severity of a status row and which fuente
  it names, whether a search finds a thing and how it is labelled — never how a counter is stored or
  how many times a collaborator was called.
- **A regression test that has never failed is worth nothing**, so each new guard was proved by
  reintroducing the bug it exists for: enumerating MINSAL's two feeds by name, always claiming a
  start date, letting the check fetch, restricting search to MINSAL, dropping the institution from a
  result's label. Each mutation failed exactly the tests that describe it. Worth repeating for any
  future guard.
- **`Sources::ALL` is what the tests walk**, not a list of three names. A fourth fuente added to the
  catálogo and forgotten elsewhere fails in the suite rather than in front of a clinician — which is
  the specific recurrence this exists to prevent.
- The plan document's debt entry asserting the old rule is corrected in the same change. Leaving it
  would mean two documents disagreeing about the same rule, and the next reader believing the older
  one.
