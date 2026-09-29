<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\BackgroundJob;

use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * The revalidation a stale serve dispatched (L1-01/L3-04): first paint
 * serves the expired-but-kept copy immediately and THIS job refreshes
 * behind the response, so a healthy-but-slow publisher never delays paint.
 *
 * One-off by construction — dispatched from the read path with the fuente's
 * id, never registered in info.xml (IJobList one-offs are not), and
 * QueuedJob removes itself from the list on start. Runs the same mint point
 * an inline refresh would: revalidate() goes through refresh()/store(),
 * so the cache entry written and the table upserted are the read path's
 * own — no second code path for the same work to disagree with.
 *
 * A revalidation that fails degrades exactly as an inline one would (the
 * cooldown arms; the NEXT reader sees the banner with the last-good copy),
 * and the dispatch marker is cleared in revalidate()'s finally either way,
 * so a crashed job self-heals through its own TTL.
 */
class RevalidateJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Sources $sources,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$this->sources->revalidate((string)($argument['source'] ?? ''));
	}
}
