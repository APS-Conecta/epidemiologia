# The freno and the backfill stay inside `IspRepository`

**Status: accepted, 2026-08-05.** Recorded because an architecture review proposed extracting both
and the proposal did not survive its own evidence.

## Context

The 2026-08-05 review listed them as complete mechanisms with their own state and rules, reachable
only through a 24-feed refresh:

- the **freno** — `braked()` / `brake()` / `frenoKey()`, an exponential backoff whose curve is a
  literal inside `brake()`;
- the **backfill** — `backfill()` / `page()` plus a cursor in `IAppConfig`, with its own budget,
  exhaustion rule and rotating start.

It proposed each become an internal module behind the repository's unchanged interface.

## What the evidence actually said

**The deletion test fails for both.** Delete either class and its three methods go back where they
came from. Complexity **moves**; it does not concentrate at N call sites and it does not vanish. That
is the signal the test exists to give, and it says no.

**Both seams would be hypothetical.** One adapter each — there is no second implementation and
nothing varies across them. `MinsalRepository` has no freno and needs none: it is one page, not 24
feeds, and it degrades on empty rather than backing a feed off.

**The review's sharpest claim was wrong.** It said `probe()` "silently spends the hour's two backfill
pages", implying the daily job starves a reader's page load. `$budget` is a **local variable, set per
call** — `probe()` gets its own two pages in addition. There is no shared quota and no contention.
The archive advances marginally faster because the job runs; that is closer to desirable than to a
defect.

**The declared seam is honoured.** `backfill()`'s docblock promises it is *"strictly additive… it
runs after the decisions above are made and its result is never consulted by them"*. That is a claim
about **ordering**, and the ordering holds. What is missing is an opt-*out*, and nothing needs one.

## Decision

**Both stay inside `IspRepository`.** Its public interface is already deep — five members over 24-feed
fan-out, conditional GET, backoff, an accreting archive, revision detection and a cursor-driven
backfill — and that is the interface callers and tests cross. Adding two classes to make an
already-passing test less awkward is structure bought on speculation.

## The cost, stated rather than hidden

The backoff curve cannot be observed or changed without editing `IspRepository`, and its three tests
are awkward: `testEveryCategoryBrakedIsNotAnOutage` needs three repository instances sharing a store
and a comment explaining that `NOW + 3601 + 400` lands past the 300 s cooldown but inside the 2 h
freno. That awkwardness is accepted. It is the price of not inventing a seam, and the tests pass and
have been negative-controlled.

## Reopen when a second adapter exists

Not when the file feels long. When one of these is true:

- **a second repository needs backoff** — that gives the seam its second adapter and the extraction
  pays for itself immediately;
- **the curve must differ per fuente**, so it becomes configuration rather than a constant;
- **the backfill must be driven by something other than a refresh** — a manual "walk the archive now"
  action, say — which is the opt-out nothing needs today.
