<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\BackgroundJob;

use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps an install nobody opened fresh, every hour (ADR-0017 D10).
 *
 * The read path refreshes only when someone opens the app, so on its own an
 * unopened install would keep serving its last fetched answer forever: the
 * first clinician back after a fortnight would find the fortnight's alerts
 * missing, and a stream fuente — the ISP's three categories, OPS/OMS — would
 * lose for good every post that scrolled off its first page in between (its
 * backfill walks two older pages once, not every gap). This job is the
 * freshness mechanism for installs nobody is looking at: one pass through
 * the catalogue an hour, the read path's own freshness window (FRESH_FOR),
 * so what a publisher posts reaches Inicio within about two hours whether or
 * not anyone opened the app.
 *
 * It rides Sources::get() — the same mint point the page serves from — and
 * NOT probe(), which exists to detect and deliberately asks through the
 * freno (#218beba): an hourly pass through that door would re-ask a host
 * that said no twenty-four times a day, which is exactly what the brake
 * exists to stop. get() consults the freshness window and the host's
 * cooldown and freno before any request, so this job needs no bounds of its
 * own. The tick is a relay: a stale copy is served and one RevalidateJob
 * dispatched behind the dedup marker, which fetches on a later cron tick
 * after re-asking the host's gates; only a fuente with nothing stored
 * fetches here, synchronously, once per cache lifetime (the cold path) — so
 * a fresh install's first reads land in this job, whose new row is due at
 * once, rather than in somebody's render.
 *
 * It never runs converge(). The deep pass — the informe's walk and archiver,
 * each stream's one-shot backfill — is RefreshJob's, nightly, where its
 * worst case (the informe's WALK_PAGES of pages at 15 s each) costs a window
 * nobody waits in.
 *
 * It never touches ProbeRecord. The failure counts are the daily-detection
 * substrate and ProbeJob is their sole writer — CONTEXT.md's Sondeo entry
 * keeps the rule, and ProbeRecord::record() counts calls, not days: a second
 * writer on this cadence would turn the [2, 7, 30] alarm schedule into
 * arithmetic about hours, the bell-spam defect class. A degraded answer
 * passes through unexamined: detection is ProbeJob's business, and the flag
 * is rendered where it belongs, by the read path.
 *
 * TIME_SENSITIVE, and a class of its own for it. On an instance with a
 * maintenance window (Gestión's opens at 05:00 UTC), the platform runs a
 * TIME_INSENSITIVE job only inside it — five runs a day, not twenty-four.
 * And the sensitivity lives in the job's row, not its class:
 * JobList::setLastRun() writes time_sensitive = 0 after every run of an
 * insensitive job and nothing ever writes it back, so RefreshJob could not
 * have been made hourly by changing its constant. A new class is a new row,
 * and a new row starts sensitive (the column's default).
 */
class WarmJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Sources $sources,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		// Every fuente through the same mint point the read path rides, so
		// there is no second code path for the same work to disagree with it.
		// The answer is ignored on purpose — this job warms; ProbeJob detects,
		// and a degraded payload is a fact the read path renders, not one to
		// act on here.
		//
		// Per-source isolation (L3-03), the warm pass's half: a get() that
		// throws is logged and stepped over — every sibling fuente still gets
		// its pass, and the next hour's run retries the thrower. Deliberately
		// NOT recorded as a probe failure: the counts are ProbeJob's alone
		// (see the class docblock), and this class has no cleaner signal than
		// the log for a throw that is not an outage verdict.
		foreach (Sources::ALL as $source) {
			try {
				$this->sources->get($source);
			} catch (Throwable $e) {
				$this->logger->warning('The warm pass of {source} threw instead of answering', [
					'source' => $source,
					'exception' => $e,
				]);
			}
		}
	}
}
