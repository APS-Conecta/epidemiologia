## 2026-10-06 - Reactive filter sources in composables

**Learning:** When creating composables like `useFilters` that take list items, non-computed initialization (`items.map(...)`) causes filters to hold stale data if `items` is passed as a Vue `ref` or updated reactively.
**Action:** Always wrap derived list mapping or option derivations in `computed()` using `unref(items)` so reactive updates in the parent component or state re-evaluate filters automatically.
