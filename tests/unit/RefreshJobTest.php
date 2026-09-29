<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\RefreshJob;
use OCA\Epidemiologia\Service\ProbeRecord;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * What the daily refresher does — and, as loudly, what it leaves alone.
 *
 * The job's whole surface is two loops over the catalogue — Sources::get()
 * for every fuente, then Sources::converge() for the accumulating ones — so
 * the pins are on the ACTIONS those loops take (which ids they asked, in
 * order — a ledger, never a call count, ADR-0007) and on the
 * state it must not touch (ProbeRecord's counts, whose sole writer is
 * ProbeJob). The door matters as much as the loop: probe() asks THROUGH the
 * freno (#218beba) by design, so a refresher that rode it would re-ask a
 * host that said no once a day — the double arms probe() to throw so that
 * mutation dies here, the same doctrine as AlertProviderTest's
 * never-fetches pin.
 */
class RefreshJobTest extends TestCase {
	use Doubles;

	/** 2026-09-20T05:00:00Z — the maintenance window the platform picks for a TIME_INSENSITIVE job. */
	private const NOW = 1789880400;

	/** @var list<string> every Sources::get() ask, in order — the job's whole visible behaviour */
	private array $fetched = [];

	/** @var list<string> every Sources::converge() ask, as "id after N reads", in order — the deep pass's ledger (L3-08) */
	private array $converged = [];

	/** @var list<string> every fuente whose get() threw, in order — the isolation ledger (L3-03) */
	private array $threw = [];

	protected function setUp(): void {
		$this->fetched = [];
		$this->converged = [];
		$this->threw = [];
		$this->config = [];
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
	 * The rule the catalogue states and this design inherits: the failure
	 * counts are the daily-detection substrate and ProbeJob is their sole
	 * writer. ProbeJob ran yesterday and a fuente failed; today's refresh
	 * pass must leave that judgement exactly where the probe left it — a
	 * refresher that also counted would turn the [2, 7, 30] alarm schedule
	 * into arithmetic about the wrong interval, the bell-spam defect class.
	 */
	public function testARefreshPassLeavesTheProbeStateProbeJobOwnsAlone(): void {
		$record = $this->probeRecord();
		$record->record(Sources::ALERTS, false);

		$this->runJob();

		self::assertSame(1, $record->consecutive(Sources::ALERTS), 'the count answers the probe\'s judgement, not the refresher\'s');
		self::assertSame(self::NOW, $record->failingSince(Sources::ALERTS), 'and so does the stamp');
	}

	/**
	 * Per-source isolation, the refresher's half (L3-03): a get() that
	 * throws is logged and stepped over — the sibling fuente still gets its
	 * pass, and tomorrow's run retries the thrower. No probe state is
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
		self::assertSame([Sources::ALERTS], $this->threw, 'the thrower was stepped over, not buried');
		self::assertSame(0, $record->consecutive(Sources::ALERTS), 'the throw was not counted — a get() that throws is not an outage verdict, and ProbeRecord is ProbeJob\'s alone');
	}

	/**
	 * The deep pass (L3-08): converge() asked once per accumulating fuente,
	 * in the catalogue's order, each after the whole get() loop — the read
	 * path's page-1 bound is only honest if the deep work has a home.
	 */
	public function testTheRunEndsWithTheDeepPass(): void {
		$this->runJob();

		$reads = count(Sources::ALL);
		self::assertSame(
			array_map(static fn (string $id): string => "$id after $reads reads", Sources::ACCUMULATING),
			$this->converged,
		);
	}

	/**
	 * Per-source isolation, the deep pass's half: a converge() that throws is
	 * logged and stepped over, so one stream's walk cannot cost the informe
	 * its archiver, nor the next stream its history.
	 */
	public function testAThrowingDeepPassDoesNotStarveTheNext(): void {
		$first = Sources::ACCUMULATING[0];

		$this->runJob(deepThrowsFor: $first);

		$reads = count(Sources::ALL);
		self::assertSame(
			array_map(static fn (string $id): string => "$id after $reads reads", array_slice(Sources::ACCUMULATING, 1)),
			$this->converged,
			'every other accumulating fuente still got its deep pass',
		);
		self::assertSame([$first], $this->threw);
	}

	// -----------------------------------------------------------------------

	private function runJob(?string $throwFor = null, ?string $deepThrowsFor = null): void {
		$job = $this->job($throwFor, $deepThrowsFor);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * The Sources double is the catalogue the job rides: get() is armed as
	 * the read path's mint point and probe() is armed to throw, because the
	 * two doors differ in exactly one load-bearing way — probe() asks
	 * through the freno (#218beba) — and a mutation that swapped them would
	 * hammer a refusing host once a day, green on every other pin.
	 * converge() is the deep pass's ledger (L3-08), and records how many
	 * reads came before it.
	 */
	private function job(?string $throwFor = null, ?string $deepThrowsFor = null): RefreshJob {
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
			new RuntimeException('the refresher must ride get(), which consults the freno — not probe(), which asks through it'),
		);
		$sources->method('converge')->willReturnCallback(function (string $id) use ($deepThrowsFor): void {
			if ($id === $deepThrowsFor) {
				$this->threw[] = $id;
				throw new RuntimeException('the deep pass threw instead of finishing');
			}
			$this->converged[] = $id . ' after ' . count($this->fetched) . ' reads';
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new RefreshJob($time, $sources, $this->createMock(LoggerInterface::class));
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
