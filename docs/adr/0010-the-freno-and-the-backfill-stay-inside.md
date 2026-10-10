# The freno and the backfill stay inside `IspRepository`

**Status: superseded.** Its first reopen condition was met when a second repository needed backoff, as
[ADR-0017](0017-six-alert-sectors-one-pipeline.md) (2026-09-28) records: the freno is now
`lib/Service/Freno.php`, keyed by host, and `IspRepository` is gone. Accepted 2026-08-05.

An architecture review proposed extracting two mechanisms, each
into a module behind the repository's unchanged interface: the **freno** — `braked()` / `brake()` /
`frenoKey()`, an exponential backoff whose curve is a literal inside `brake()` — and the **backfill**
— `backfill()` / `page()` plus a cursor in each category's cache entry, with its own budget, exhaustion rule and
rotating start. The proposal did not survive its own evidence.

**The deletion test fails for both.** Delete either class and its three methods go back where they
came from. Complexity **moves**; it does not concentrate at N call sites and it does not vanish.

**Both seams would be hypothetical** — one adapter each, nothing varying across them.
`MinsalRepository` has no freno and needs none: one page, not 24 feeds, and it degrades on empty.

**The review's sharpest claim was wrong.** It said `probe()` "silently spends the hour's two backfill
pages", implying the daily job starves a reader's page load. `$budget` is a **local variable, set per
call** — `probe()` gets its own two pages in addition. No shared quota, no contention.

**The declared seam is honoured.** `backfill()`'s docblock promises it is *"strictly additive… never
consulted by the decisions above"* — a claim about **ordering**, which holds. What is missing is an
opt-*out*, and nothing needs one.

## Decision

**Both stay inside `IspRepository`.** Its public interface is already deep — five members over
24-feed fan-out, conditional GET, backoff, an accreting archive, revision detection and a
cursor-driven backfill — and that is the interface callers and tests cross. The cost, stated rather
than hidden: the backoff curve cannot be observed or changed without editing `IspRepository`, and its
its tests are awkward — `testEveryCategoryBrakedIsNotAnOutage` needs three repository instances
sharing a store and a comment explaining that `NOW + 3601 + 400` lands past the 300 s cooldown but
inside the 2 h freno.

## Reopen when a second adapter exists

Not when the file feels long. When one of these is true:

- **a second repository needs backoff** — that gives the seam its second adapter and the extraction
  pays for itself immediately;
- **the curve must differ per fuente**, so it becomes configuration rather than a constant;
- **the backfill must be driven by something other than a refresh** — a manual "walk the archive now"
  action, say — which is the opt-out nothing needs today.
