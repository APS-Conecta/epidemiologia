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
 * Keeps an install nobody opened fresh.
 *
 * The read path refreshes only when someone opens the app, so on its own an
 * unopened install would keep serving its last fetched answer forever and
 * stop keeping new informes: the first clinician back after a fortnight
 * would find the week they need missing, and the kept-document back
 * catalogue would never finish converging (ADR-0015 §2). This job is the
 * freshness mechanism for installs nobody is looking at — one daily pass
 * through the catalogue, and an unopened install converges on the current
 * week within a day of publication. Opened panes stay the read path's
 * business: its hourly-on-open refresh keeps serving them.
 *
 * It rides Sources::get() — the same mint point the page serves from, where
 * the cache entry is written, the table upserted and the documents kept —
 * and NOT probe(), which exists to detect and deliberately asks through the
 * freno (#218beba): a daily pass through that door would re-ask a host that
 * said no, which is exactly what the brake exists to stop. get() consults
 * the freshness window, the cooldown and the freno before any request, so
 * this job needs no bounds of its own: every fetch behind it is a
 * repository's own, bounded at 15 s, and a refusing host answers degraded,
 * costing nothing (ADR-0015 §4).
 *
 * It never touches ProbeRecord. The failure counts are the daily-detection
 * substrate and ProbeJob is their sole writer — CONTEXT.md's Sondeo entry
 * keeps the rule, and ProbeRecord::record() counts calls, not days: a second
 * writer on this cadence would turn the [2, 7, 30] alarm schedule into
 * arithmetic about the wrong interval, the bell-spam defect class. A
 * degraded answer passes through unexamined: detection is ProbeJob's
 * business, and the flag is rendered where it belongs, by the read path.
 *
 * 24h and TIME_INSENSITIVE, ProbeJob-aligned: both run in the platform's
 * nightly maintenance window, off the request path, and the daily cost
 * against healthy publishers is one read per fuente.
 */
class RefreshJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Sources $sources,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		// Every fuente through the same mint point the read path rides, so
		// there is no second code path for the same work to disagree with it.
		// The answer is ignored on purpose — this job warms; ProbeJob detects,
		// and a degraded payload is a fact the read path renders, not one to
		// act on here.
		//
		// Per-source isolation (L3-03), the refresher's half: a get() that
		// throws is logged and stepped over — the sibling fuente still gets
		// its pass, and tomorrow's run retries the thrower. Deliberately NOT
		// recorded as a probe failure: the counts are ProbeJob's alone (see
		// the class docblock), and this class has no cleaner signal than the
		// log for a throw that is not an outage verdict.
		foreach (Sources::ALL as $source) {
			try {
				$this->sources->get($source);
			} catch (Throwable $e) {
				$this->logger->warning('The refresh pass of {source} threw instead of answering', [
					'source' => $source,
					'exception' => $e,
				]);
			}
		}

		// The deep pass (L3-08): the read path serves page 1 and stops, so the
		// backfills and the document archiver run HERE — off the request
		// path, where their worst case (the informe's WALK_PAGES of pages and
		// ARCHIVE_BUDGET of documents, a stream's two older pages, 15 s each)
		// costs a nightly window nobody waits in. Isolated per fuente like
		// the loop above: one stream's walk that throws does not cost the
		// informe its archiver.
		foreach (Sources::ACCUMULATING as $source) {
			try {
				$this->sources->converge($source);
			} catch (Throwable $e) {
				$this->logger->warning('The deep pass of {source} threw instead of finishing', [
					'source' => $source,
					'exception' => $e,
				]);
			}
		}
	}
}
