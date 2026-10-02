## 2026-10-01 - Cache Intl.DateTimeFormat instances for date and month formatting
**Learning:** Instantiating `Intl.DateTimeFormat` on every call inside date/month formatting functions (`fecha`, `mes`) causes measurable performance overhead (~144ms vs ~3ms per 1000 calls).
**Action:** Cache `Intl.DateTimeFormat` instances per locale instead of creating new instances on every call.

## 2026-10-02 - Pre-extract accessors and short-circuit primitive filters before string search in composable filters
**Learning:** In array filtering composables like `useFilters`, evaluating accessor functions (`yearOf(i)`, `monthOf(i)`) on every re-evaluation and placing string search (`_fold.includes(needle)`) before primitive equality checks (`_year === year.value`) forces string operations on non-matching items. Pre-extracting accessors into item fields during setup and short-circuiting primitive equality checks first reduces filter time by ~66% (~386ms to ~129ms per 1000 filtering passes on 5000 items).
**Action:** When filtering collections with multiple predicates, pre-extract item attributes at setup and order fast primitive equality checks before substring search.
