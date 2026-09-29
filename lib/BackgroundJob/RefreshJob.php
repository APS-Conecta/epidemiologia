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
 * The deep pass, nightly (L3-08).
 *
 * The read path serves each fuente's first page and stops, so what a first
 * page cannot carry is done here, off the request path: the informe's
 * back-catalogue walk and document archiver, and each stream fuente's
 * one-shot backfill of its two older pages. Their worst case — the
 * informe's WALK_PAGES of pages and ARCHIVE_BUDGET of documents, a stream's
 * two pages, 15 s each — costs a nightly window nobody waits in, and the
 * documents an unopened install keeps converge here, ARCHIVE_BUDGET a night
 * (ADR-0015 §2).
 *
 * Until ADR-0017 this job also kept unopened installs fresh, with a get()
 * loop over the catalogue ahead of the deep pass. That loop is WarmJob's
 * now, hourly — which this class could not become: it is TIME_INSENSITIVE,
 * which on an instance with a maintenance window runs only inside it, and
 * its row's time_sensitive, once written 0, is never raised again (see
 * WarmJob).
 *
 * It rides Sources::converge() and no other door: not get(), which is
 * WarmJob's, and not probe(), which asks through the freno (#218beba). Each
 * converge() carries its own bounds — the informe's page and document
 * budgets, a stream's host gates and its once-per-install flag — so this
 * job needs none of its own.
 *
 * It never touches ProbeRecord: the failure counts are ProbeJob's alone
 * (CONTEXT.md's Sondeo entry), and a deep pass that throws is not an outage
 * verdict.
 *
 * 24h and TIME_INSENSITIVE, ProbeJob-aligned: both run in the platform's
 * nightly maintenance window.
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
		// Isolated per fuente (L3-03): one stream's walk that throws does not
		// cost the informe its archiver, nor the next stream its history —
		// it is logged and stepped over, and tomorrow's run asks again.
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
