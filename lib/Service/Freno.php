<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\ICache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How long a repository declines to re-request one thing it fetches, after
 * that thing failed or its host answered no (ADR-0015 §4; ADR-0010's
 * single-impl rationale died with IspRepository, and this is one home for
 * the concept both siblings carried as a copy).
 *
 * One instance per request-grain — CONTEXT.md: "its grain is what is
 * requested", one MINSAL page, one ISP feed — constructed by the repository
 * that owns the grain, on the same cache it reads. The deliberately
 * different call-site rules (a deep page's or a document's refusal never
 * arms the read's brake — the cheap feed read must not pay for the deep
 * request's troubles) stay at their call sites; what this class makes
 * visible is that the curve itself was always one curve.
 */
class Freno {
	public function __construct(
		private ICache $cache,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private string $key,
		private string $subject,
	) {
	}

	/** Is this grain still braked? The stored value IS the moment the brake expires. */
	public function braked(): bool {
		$until = $this->cache->get($this->key);

		return $until !== null && $this->time->getTime() < (int)$until['until'];
	}

	/**
	 * Doubling from two hours, capped at a day: two hours because refreshes
	 * are hourly, anything shorter is not a freno, it is a rounding error.
	 * The count outlives the wait it caused, which is what makes the next
	 * refusal cost more; an answer removes the key outright (lift()), so
	 * recovery is one step and not a slow unwind.
	 *
	 * ponytail: the 3600 is both grains' hourly cadence in one number;
	 * parameterize the base wait if a grain ever refreshes on another
	 * cadence. CONTEXT.md states the doubling-from-two-hours as the freno's
	 * own doctrine, so the number living here is domain-correct.
	 */
	public function brake(int $status): void {
		$n = (int)($this->cache->get($this->key)['n'] ?? 0) + 1;
		$wait = min(2 * 3600 * (2 ** ($n - 1)), 86400);

		$this->logger->warning('{subject} answered {status}; under freno {n} time(s), waiting {wait}s', [
			'subject' => $this->subject, 'status' => $status, 'n' => $n, 'wait' => $wait,
		]);

		$this->cache->set($this->key, [
			'n' => $n,
			'until' => $this->time->getTime() + $wait,
		], 86400 + $wait);
	}

	/**
	 * An answer — any answer — is the host not refusing. The brake lifts
	 * here and not on a successful parse so recovery is one step, and a WAF
	 * 200 lands in the cooldown, not back under the brake it just lifted.
	 * One cache DELETE per successful fetch, priced and kept.
	 */
	public function lift(): void {
		$this->cache->remove($this->key);
	}

	/**
	 * The status a transport failure carries, or null when it carries none.
	 *
	 * getResponseFromThrowable() is the status affordance: it hands back the
	 * response a BadResponseException carries — and RETHROWS when the
	 * throwable has none, which is exactly the timeout/TLS class. The
	 * rethrow is folded into a null here because every caller's next step is
	 * the same comparison: a 4xx/5xx is the host SAYING no (an answer, not a
	 * challenge — it arms the caller's brake), while a timeout, a TLS error
	 * or a DNS miss carries no response to read and stays cooldown-class —
	 * faults that lift on their own.
	 */
	public static function status(IClient $client, Throwable $e): ?int {
		try {
			return $client->getResponseFromThrowable($e)->getStatusCode();
		} catch (Throwable) {
			return null;
		}
	}
}
