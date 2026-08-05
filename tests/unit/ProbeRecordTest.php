<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\ProbeRecord;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the sondeo remembers between runs.
 *
 * These assertions are on the record's own answers, never on the config keys
 * underneath — which is the point of the class existing. Before it, ProbeJobTest
 * asserted `$store['probe_failures_alerts']` and SourcesReachableTest wrote the
 * same key by hand, so the two surfaces agreed only because both files spelled the
 * same string. ADR-0007 names that exact shape as the reason a counter can be
 * green while the notification it drives says the wrong institution.
 */
class ProbeRecordTest extends TestCase {
	use Doubles;

	/** 2026-08-05T12:00:00Z */
	private const NOW = 1785931200;

	private const DAY = 86400;

	public function testAFuenteNobodyHasProbedIsNotFailing(): void {
		$record = $this->record();

		self::assertSame(0, $record->consecutive('alerts'));
		self::assertNull($record->failingSince('alerts'));
	}

	public function testFailuresAccumulate(): void {
		$record = $this->record();

		self::assertSame(1, $record->record('alerts', false));
		self::assertSame(2, $record->record('alerts', false));
		self::assertSame(2, $record->consecutive('alerts'));
	}

	/** The whole word is *consecutive*: one good day wipes the run, it does not decrement it. */
	public function testOneSuccessClearsTheRunOutright(): void {
		$record = $this->record();
		$record->record('alerts', false);
		$record->record('alerts', false);
		$record->record('alerts', false);

		self::assertSame(0, $record->record('alerts', true));
		self::assertNull($record->failingSince('alerts'));
	}

	/**
	 * Stamped on the FIRST failure and left alone after, or the status row would
	 * report an outage that started this morning however long it has really run.
	 */
	public function testFailingSinceIsTheFirstFailureNotTheLatest(): void {
		$record = $this->record(self::NOW);
		$record->record('alerts', false);
		$this->record(self::NOW + self::DAY)->record('alerts', false);

		self::assertSame(self::NOW, $this->record()->failingSince('alerts'));
	}

	/** Each fuente is counted apart — an ISP outage must not reset MINSAL's clock. */
	public function testEachFuenteIsCountedOnItsOwn(): void {
		$record = $this->record();
		$record->record('alerts', false);
		$record->record('alerts', false);
		$record->record('sanitary_alerts', false);

		self::assertSame(2, $record->consecutive('alerts'));
		self::assertSame(1, $record->consecutive('sanitary_alerts'));
	}

	public function testRecoveringOneFuenteLeavesTheOtherFailing(): void {
		$record = $this->record();
		$record->record('alerts', false);
		$record->record('sanitary_alerts', false);

		$record->record('alerts', true);

		self::assertSame(0, $record->consecutive('alerts'));
		self::assertSame(1, $record->consecutive('sanitary_alerts'));
	}

	/**
	 * The invariant SourcesReachable used to defend against, moved to where it is
	 * now enforced: a fuente with failures always knows when they started, so no
	 * row can report an outage that began at the epoch.
	 */
	public function testAFailingFuenteAlwaysKnowsWhenItStarted(): void {
		$record = $this->record();

		foreach (['alerts', 'reports', 'sanitary_alerts'] as $source) {
			for ($day = 1; $day <= 3; $day++) {
				$record->record($source, false);
				self::assertNotNull($record->failingSince($source), "$source on day $day");
			}
		}
	}

	private function record(?int $now = null): ProbeRecord {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		return new ProbeRecord($this->appConfig(), $time);
	}
}
