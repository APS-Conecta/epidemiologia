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
use ReflectionMethod;
use RuntimeException;

/**
 * What the daily refresher does — and, as loudly, what it leaves alone.
 *
 * The job's whole surface is one loop over the catalogue through
 * Sources::get(), so the pins are on the ACTIONS that loop takes (which ids
 * it asked, in order — a ledger, never a call count, ADR-0007) and on the
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

	protected function setUp(): void {
		$this->fetched = [];
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

	// -----------------------------------------------------------------------

	private function runJob(): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * The Sources double is the catalogue the job rides: get() is armed as
	 * the read path's mint point and probe() is armed to throw, because the
	 * two doors differ in exactly one load-bearing way — probe() asks
	 * through the freno (#218beba) — and a mutation that swapped them would
	 * hammer a refusing host once a day, green on every other pin.
	 */
	private function job(): RefreshJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('get')->willReturnCallback(function (string $id): array {
			$this->fetched[] = $id;

			return ['items' => [], 'fetched_at' => null, 'degraded' => false, 'total' => 0, 'has_more' => false];
		});
		$sources->method('probe')->willThrowException(
			new RuntimeException('the refresher must ride get(), which consults the freno — not probe(), which asks through it'),
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new RefreshJob($time, $sources);
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
