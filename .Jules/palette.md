## 2026-08-08 - NcTextField Trailing Button Accessibility
**Learning:** `NcTextField` in `@nextcloud/vue` when using `show-trailing-button` and `trailing-button-icon` needs explicit `:trailing-button-label` to provide an accessible ARIA name/label for screen reader users on icon-only clear buttons.
**Action:** Always provide `:trailing-button-label="t('epidemiologia', '...')" ` whenever using `show-trailing-button` on an `NcTextField`.
