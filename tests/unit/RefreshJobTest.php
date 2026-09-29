<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\RefreshJob;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * What the nightly deep pass does — and, as loudly, what it leaves alone.
 *
 * The job's whole surface is one loop over the catalogue — Sources::converge()
 * for the accumulating fuentes — so the pins are on the ACTIONS that loop
 * takes (which ids it asked, in order — a ledger, never a call count,
 * ADR-0007), on the cadence it keeps, and on the doors it must not ride:
 * get() is WarmJob's since ADR-0017, and probe() asks THROUGH the freno
 * (#218beba) by design — the double arms probe() to throw so that mutation
 * dies here, the same doctrine as AlertProviderTest's never-fetches pin.
 * ProbeRecord's untouched counts are pinned where the reads are, in
 * WarmJobTest.
 */
class RefreshJobTest extends TestCase {
	/** 2026-09-20T05:00:00Z — the maintenance window the platform picks for a TIME_INSENSITIVE job. */
	private const NOW = 1789880400;

	/** @var list<string> every Sources::get() ask — none: the read loop is WarmJob's */
	private array $fetched = [];

	/** @var list<string> every Sources::converge() ask, in order — the deep pass's ledger (L3-08) */
	private array $converged = [];

	/** @var list<string> every fuente whose deep pass threw, in order — the isolation ledger (L3-03) */
	private array $threw = [];

	protected function setUp(): void {
		$this->fetched = [];
		$this->converged = [];
		$this->threw = [];
	}

	/**
	 * Nightly, inside the maintenance window: the deep pass's worst case
	 * belongs where nobody waits, and the hourly work has a class of its own
	 * (WarmJob) because this job's row can never be made time-sensitive.
	 */
	public function testTheDeepPassKeepsTheNightlyWindow(): void {
		$job = $this->job();

		self::assertSame(24 * 3600, $job->getInterval());
		self::assertFalse($job->isTimeSensitive(), 'TIME_INSENSITIVE — the platform runs it in the maintenance window');
	}

	/**
	 * The deep pass (L3-08): converge() asked once per accumulating fuente,
	 * in the catalogue's order, and nothing read — the read path's page-1
	 * bound is only honest if the deep work has a home, and the hourly read
	 * loop has its own (WarmJob): a get() loop left here is the move
	 * half-made.
	 */
	public function testTheRunIsTheDeepPassAlone(): void {
		$this->runJob();

		self::assertSame(Sources::ACCUMULATING, $this->converged);
		self::assertSame([], $this->fetched, 'the read loop moved to WarmJob — the nightly job reads nothing');
	}

	/**
	 * Per-source isolation, the deep pass's half: a converge() that throws is
	 * logged and stepped over, so one stream's walk cannot cost the informe
	 * its archiver, nor the next stream its history.
	 */
	public function testAThrowingDeepPassDoesNotStarveTheNext(): void {
		$first = Sources::ACCUMULATING[0];

		$this->runJob(deepThrowsFor: $first);

		self::assertSame(
			array_slice(Sources::ACCUMULATING, 1),
			$this->converged,
			'every other accumulating fuente still got its deep pass',
		);
		self::assertSame([$first], $this->threw);
	}

	// -----------------------------------------------------------------------

	private function runJob(?string $deepThrowsFor = null): void {
		$job = $this->job($deepThrowsFor);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * The Sources double is the catalogue the job rides: converge() is the
	 * deep pass's ledger (L3-08); get() keeps a ledger too, which must stay
	 * empty — a leftover read loop would catch its own throw, so a ledger is
	 * what sees it; probe() is armed to throw, because it asks through the
	 * freno (#218beba) and a mutation that rode it would re-ask a refusing
	 * host every night, green on every other pin.
	 */
	private function job(?string $deepThrowsFor = null): RefreshJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('get')->willReturnCallback(function (string $id): array {
			$this->fetched[] = $id;

			return ['items' => [], 'fetched_at' => null, 'degraded' => false, 'total' => 0, 'has_more' => false];
		});
		$sources->method('probe')->willThrowException(
			new RuntimeException('the deep pass must ride converge() — not probe(), which asks through the freno'),
		);
		$sources->method('converge')->willReturnCallback(function (string $id) use ($deepThrowsFor): void {
			if ($id === $deepThrowsFor) {
				$this->threw[] = $id;
				throw new RuntimeException('the deep pass threw instead of finishing');
			}
			$this->converged[] = $id;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new RefreshJob($time, $sources, $this->createMock(LoggerInterface::class));
	}
}
