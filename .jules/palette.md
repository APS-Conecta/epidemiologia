## 2026-10-01 - NcTextField's default "Clear text" label is not Chilean Spanish
**Learning:** `@nextcloud/vue` 9.9.0 labels the trailing clear button with its own `t('Clear text')`, translated only for `es`, `es-AR`, `es-EC` and `es-MX`; an `es-CL` user hears English. The default is not a reason to skip the label.
**Action:** Give icon-only `NcTextField`/`NcInputField` trailing buttons an explicit `:trailing-button-label="t('epidemiologia', '…')"`, and make sure the PR actually contains the source change it describes.
