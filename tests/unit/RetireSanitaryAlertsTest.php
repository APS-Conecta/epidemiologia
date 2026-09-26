<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\AppInfo\AppId;
use OCA\Epidemiologia\Migration\RetireSanitaryAlerts;
use OCP\Migration\IOutput;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The tombstone's own contract: two keys wiped, idempotence observed (not
 * claimed), and the bell-row match shape held to ProbeJob::match()'s exact
 * identity — because nothing else in the app can go red if this step drifts
 * (ADR-0007: the class holds decisions — the sentinel read, the found gate,
 * the no-user match). The manager double's setters are armed per argument,
 * the RefreshJobTest doctrine: a drift in the match shape dies as a thrown
 * RuntimeException, not as a green test about nothing.
 */
class RetireSanitaryAlertsTest extends TestCase {
	use Doubles;

	/** @var list<string> every info line the step emitted, in order */
	private array $said = [];

	/** @var list<INotification> every notification markProcessed received */
	private array $marked = [];

	protected function setUp(): void {
		$this->config = [];
		$this->said = [];
		$this->marked = [];
	}

	public function testWipesTheTwoKeysTheProbeCanNoLongerReach(): void {
		$this->config['probe_failures_sanitary_alerts'] = 6;
		$this->config['probe_failing_since_sanitary_alerts'] = 1789880400;

		$this->runStep();

		self::assertArrayNotHasKey('probe_failures_sanitary_alerts', $this->config,
			'the resurrection hazard: a reused id would inherit the old count');
		self::assertArrayNotHasKey('probe_failing_since_sanitary_alerts', $this->config);
	}

	/**
	 * Idempotence observed, not claimed: the install + post-migration double
	 * registration is safe only if the second run neither throws nor reports
	 * a wipe that did not happen.
	 */
	public function testRunningItTwiceWipesOnceAndThenChangesNothing(): void {
		$this->config['probe_failures_sanitary_alerts'] = 6;
		$this->config['probe_failing_since_sanitary_alerts'] = 1789880400;

		$this->runStep();
		self::assertSame(
				['Epidemiología: wiped the retired sanitary_alerts probe residue'],
				$this->said,
			);

		$this->runStep();
		self::assertSame([], $this->config, 'nothing was re-created by the second run');
		self::assertCount(1, $this->said, 'the second run reports no wipe that did not happen');
	}

	/**
	 * The match shape is ProbeJob::match()'s identity for the retired id —
	 * the armed setters below make any drift throw, and the marked ledger
	 * proves the ask happened at all.
	 */
	public function testTheStaleBellRowsAreMarkedWithProbeJobsMatchShape(): void {
		$this->runStep();

		self::assertCount(1, $this->marked, 'the stale probe_failed rows are never marked processed');
	}

	// -----------------------------------------------------------------------

	private function runStep(): void {
		(new RetireSanitaryAlerts($this->appConfig(), $this->manager()))->run($this->printer());
	}

	private function printer(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		return $output;
	}

	private function manager(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$notification->method('setApp')->willReturnCallback(
				static function (string $app) use ($notification): INotification {
					if ($app !== AppId::APP_ID) {
						throw new RuntimeException("the wipe must mark rows for this app, got '$app'");
					}

					return $notification;
				});
			$notification->method('setSubject')->willReturnCallback(
				static function (string $subject) use ($notification): INotification {
					if ($subject !== 'probe_failed') {
						throw new RuntimeException("the wipe must mark probe_failed rows, got '$subject'");
					}

					return $notification;
				});
			$notification->method('setObject')->willReturnCallback(
				static function (string $type, string $id) use ($notification): INotification {
					if ($type !== 'source' || $id !== 'sanitary_alerts') {
						throw new RuntimeException("the wipe must mark rows for ('source', 'sanitary_alerts'), got ('$type', '$id')");
					}

					return $notification;
				});
			// "No user" is the load-bearing half of the match: leaving the user
			// off is what clears every admin's row at once (ProbeJob::match's
			// rule, and the reason the repair step never calls setUser).
			$notification->method('setUser')->willThrowException(new RuntimeException(
				'the wipe matches every user only because the notification carries none',
			));

			return $notification;
		});
		$manager->method('markProcessed')->willReturnCallback(function (INotification $notification): void {
			$this->marked[] = $notification;
		});

		return $manager;
	}
}
