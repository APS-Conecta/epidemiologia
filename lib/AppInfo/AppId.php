<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\AppInfo;

/**
 * The app id, on a leaf (L0-01).
 *
 * It used to live on Application, which made the facade a false import hub:
 * eight files imported it solely for the constant, and the app's one
 * import-graph cycle ran through it — Application → Notifier → Sources →
 * IspReportRepository → Application. A leaf imports nothing, so the one
 * string every class reaches for can never close a cycle again.
 *
 * Sources was the review's other candidate and is not a leaf either: its
 * constructor takes both repositories, so a repository importing it for the
 * id would close Sources ↔ repository — the same defect in a new hat.
 * MinsalRepository's own cache-prefix literal is the older precedent that
 * nothing needs the facade; this class is the honest version of it.
 */
class AppId {
	public const APP_ID = 'epidemiologia';
}
