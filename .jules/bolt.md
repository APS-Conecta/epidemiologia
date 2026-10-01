## 2026-10-01 - Cache Intl.DateTimeFormat instances for date and month formatting
**Learning:** Instantiating `Intl.DateTimeFormat` on every call inside date/month formatting functions (`fecha`, `mes`) causes measurable performance overhead (~144ms vs ~3ms per 1000 calls).
**Action:** Cache `Intl.DateTimeFormat` instances per locale instead of creating new instances on every call.
