<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\WarmJob;
use OCA\Epidemiologia\Service\ProbeRecord;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * What the hourly warm pass does — and, as loudly, what it leaves alone.
 *
 * The job's whole surface is one loop over the catalogue through
 * Sources::get(), moved here from RefreshJob (ADR-0017 D10), so the pins are
 * on the ACTIONS that loop takes (which ids it asked, in order — a ledger,
 * never a call count, ADR-0007), on the cadence it is registered with, and
 * on what it must not touch: ProbeRecord's counts (ProbeJob's alone), the
 * deep pass (RefreshJob's, nightly), and probe(), which asks THROUGH the
 * freno (#218beba) by design — the double arms probe() to throw so that
 * mutation dies here, the same doctrine as AlertProviderTest's
 * never-fetches pin.
 */
class WarmJobTest extends TestCase {
	use Doubles;

	/** 2026-09-20T14:00:00Z — a working hour, outside the maintenance window, when only TIME_SENSITIVE jobs run. */
	private const NOW = 1789912800;

	/** @var list<string> every Sources::get() ask, in order — the job's whole visible behaviour */
	private array $fetched = [];

	/** @var list<string> every Sources::converge() ask — none: the deep pass is RefreshJob's */
	private array $converged = [];

	/** @var list<string> every fuente whose get() threw, in order — the isolation ledger (L3-03) */
	private array $threw = [];

	/** @var list<array<string, mixed>> the context of every warning logged, in order */
	private array $warned = [];

	protected function setUp(): void {
		$this->fetched = [];
		$this->converged = [];
		$this->threw = [];
		$this->warned = [];
		$this->config = [];
	}

	/**
	 * Hourly, and at any hour: the two numbers that are the whole reason this
	 * class exists apart from RefreshJob. A copy of RefreshJob's constructor —
	 * the likeliest way to write this class — keeps TIME_INSENSITIVE and makes
	 * "hourly" five runs a day inside the maintenance window, green on every
	 * other pin.
	 */
	public function testTheJobRunsEveryHourAtAnyHour(): void {
		$job = $this->job();

		self::assertSame(3600, $job->getInterval());
		self::assertTrue($job->isTimeSensitive(), 'TIME_SENSITIVE — an insensitive job runs only inside the maintenance window');
	}

	public function testTheJobAsksGetForEachFuenteInTheCataloguesOrder(): void {
		$this->runJob();

		self::assertSame(
			Sources::ALL,
			$this->fetched,
			'one get() per fuente, in the catalogue\'s order — the same mint point the page serves from',
		);
	}

	/**
	 * The deep pass stays nightly: an hourly converge() would run the
	 * informe's walk and archiver, and every stream's backfill, in the
	 * working day's cron. A ledger, not a throwing double — the loop's
	 * per-fuente catch would bury the throw and pass.
	 */
	public function testTheDeepPassIsLeftToTheNightlyJob(): void {
		$this->runJob();

		self::assertSame([], $this->converged, 'converge() is RefreshJob\'s — nightly, in the maintenance window');
	}

	/**
	 * The rule the catalogue states and this design inherits: the failure
	 * counts are the daily-detection substrate and ProbeJob is their sole
	 * writer. ProbeJob ran and a fuente failed; the next warm pass must leave
	 * that judgement exactly where the probe left it — a warm pass that also
	 * counted would turn the [2, 7, 30] alarm schedule into arithmetic about
	 * hours, the bell-spam defect class.
	 */
	public function testAWarmPassLeavesTheProbeStateProbeJobOwnsAlone(): void {
		$record = $this->probeRecord();
		$record->record(Sources::ALERTS, false);

		$this->runJob();

		self::assertSame(1, $record->consecutive(Sources::ALERTS), 'the count answers the probe\'s judgement, not the warm pass\'s');
		self::assertSame(self::NOW, $record->failingSince(Sources::ALERTS), 'and so does the stamp');
	}

	/**
	 * Per-source isolation, the warm pass's half (L3-03): a get() that
	 * throws is logged and stepped over — every sibling fuente still gets its
	 * pass, and the next hour's run retries the thrower. No probe state is
	 * written: the counts are ProbeJob's alone, and a throw here is not an
	 * outage verdict.
	 */
	public function testAThrowingFuenteDoesNotStarveItsSibling(): void {
		$record = $this->probeRecord();

		$this->runJob(throwFor: Sources::ALERTS);

		self::assertSame(
			array_values(array_diff(Sources::ALL, [Sources::ALERTS])),
			$this->fetched,
			'every sibling fuente still got its pass',
		);
		self::assertSame([Sources::ALERTS], $this->threw, 'the thrower was stepped over');
		self::assertSame([Sources::ALERTS], array_column($this->warned, 'source'), 'and said so, naming itself — not buried');
		self::assertInstanceOf(RuntimeException::class, $this->warned[0]['exception'] ?? null, 'with the exception an admin diagnoses it by');
		self::assertSame(0, $record->consecutive(Sources::ALERTS), 'the throw was not counted — a get() that throws is not an outage verdict, and ProbeRecord is ProbeJob\'s alone');
	}

	// -----------------------------------------------------------------------

	private function runJob(?string $throwFor = null): void {
		$job = $this->job($throwFor);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * The Sources double is the catalogue the job rides: get() is armed as
	 * the read path's mint point and probe() is armed to throw, because the
	 * two doors differ in exactly one load-bearing way — probe() asks
	 * through the freno (#218beba) — and a mutation that swapped them would
	 * hammer a refusing host every hour, green on every other pin.
	 * converge() is the deep pass's ledger, which must stay empty here, and
	 * the logger keeps the context of each warning.
	 */
	private function job(?string $throwFor = null): WarmJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('get')->willReturnCallback(function (string $id) use ($throwFor): array {
			if ($id === $throwFor) {
				$this->threw[] = $id;
				throw new RuntimeException('the fuente threw instead of answering');
			}
			$this->fetched[] = $id;

			return ['items' => [], 'fetched_at' => null, 'degraded' => false, 'total' => 0, 'has_more' => false];
		});
		$sources->method('probe')->willThrowException(
			new RuntimeException('the warm pass must ride get(), which consults the freno — not probe(), which asks through it'),
		);
		$sources->method('converge')->willReturnCallback(function (string $id): void {
			$this->converged[] = $id;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warned[] = $context;
			},
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new WarmJob($time, $sources, $logger);
	}

	/**
	 * The real ProbeRecord over the shared config double, not a mock: the
	 * assertions in this suite are about its answers, and ProbeRecordTest
	 * owns its semantics — this file asks it only as a clinician's install
	 * would, through the interface.
	 */
	private function probeRecord(): ProbeRecord {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new ProbeRecord($this->appConfig(), $time);
	}
}
