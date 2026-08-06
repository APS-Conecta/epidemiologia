<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What the sondeo remembers between runs: how many consecutive days each fuente
 * has failed, and when the current run started.
 *
 * CONTEXT.md says of the sondeo that it "is the only writer of the
 * consecutive-failure counts that both the notificación and Administración →
 * Visión general read, so those two can be incomplete but never contradict each
 * other". That was enforced by both files spelling the same config-key prefix —
 * `SourcesReachable` imported `ProbeJob` for no reason except to borrow two
 * strings, and both test suites read and wrote the raw store. A shared string is
 * not an interface; it is an agreement nobody can see from a signature.
 *
 * So the counts get one. This class is the only thing that knows the keys exist.
 *
 * Deliberately NOT the notification schedule. `ProbeJob` shouts on the 2nd, 7th
 * and 30th day; `SourcesReachable` reddens the row from the 1st. Those are two
 * different judgements about two different surfaces — a bell is right at a
 * transition, a status row at a persistent state — and folding them in here would
 * make the setup check depend on notification policy to render a row. That
 * dependency is the shape of the defect being removed, not a smaller version of it.
 * They can still drift, but now they drift over a named method rather than over a
 * string prefix nobody greps for.
 *
 * IAppConfig rather than the cache: a Redis flush must not tell the instance that
 * a fuente which has been down for a fortnight is healthy again.
 */
class ProbeRecord {
	private const FAILURES = 'probe_failures_';

	private const SINCE = 'probe_failing_since_';

	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Record one probe and answer how many consecutive failures now stand.
	 *
	 * Returns the count so a caller deciding whether to notify does not have to
	 * read back what it just wrote — the read-after-write that made `ProbeJob`'s
	 * old `failed()` and `recovered()` two shapes of the same thing.
	 *
	 * @param string $source a Sources::ALL id
	 * @param bool $reachable what the probe found
	 * @return int consecutive failures after recording; 0 when reachable
	 */
	public function record(string $source, bool $reachable): int {
		if ($reachable) {
			$this->clear($source);

			return 0;
		}

		$failures = $this->consecutive($source) + 1;
		$this->config->setAppValueInt(self::FAILURES . $source, $failures);

		// Stamped on the first failure only. Re-stamping would make a fortnight-old
		// outage report itself as having started this morning, which is the one thing
		// the status row uses this for.
		if ($failures === 1) {
			$this->config->setAppValueInt(self::SINCE . $source, $this->time->getTime());
		}

		return $failures;
	}

	/**
	 * Has this fuente ever been probed at all?
	 *
	 * `record()` writes the count on BOTH paths — 0 on success, n on failure — so the key
	 * existing is the only evidence a run happened. Without this, `consecutive()` reads 0
	 * for a key that was never written, and "never probed" is indistinguishable from
	 * "probed, all good": Visión general asserted a result that did not exist for the
	 * ~29 hours between an install and the first maintenance window.
	 */
	public function everProbed(string $source): bool {
		// -1 as the sentinel, because 0 is a real and common value here.
		return $this->config->getAppValueInt(self::FAILURES . $source, -1) !== -1;
	}

	/** Consecutive failed probes; 0 when the fuente answered last time it was asked. */
	public function consecutive(string $source): int {
		return $this->config->getAppValueInt(self::FAILURES . $source);
	}

	/** When the current run of failures began, or null when the fuente is healthy. */
	public function failingSince(string $source): ?int {
		$since = $this->config->getAppValueInt(self::SINCE . $source);

		// Zero is "never stamped", not "1970" — the row must say nothing rather than
		// claim the outage began at the epoch, which is what reading it raw produced.
		return $since === 0 ? null : $since;
	}

	/** Recovery wipes the run rather than decrementing it: the word is *consecutive*. */
	private function clear(string $source): void {
		$this->config->setAppValueInt(self::FAILURES . $source, 0);
		$this->config->deleteAppValue(self::SINCE . $source);
	}
}
