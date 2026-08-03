<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\ProbeJob;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The backoff schedule, which is the whole point of this job's rewrite.
 *
 * Nextcloud deduplicates notifications nowhere, so without a schedule a MINSAL
 * outage files two rows per admin per day forever, the bell gets muted, and the
 * detection stops being a detection. These tests exist to keep that schedule
 * honest — an untested backoff is a backoff that quietly becomes "every run".
 */
class ProbeJobTest extends TestCase {
	private const NOW = 1785585600;

	/** @var array<string, mixed> in-memory IAppConfig */
	private array $store = [];

	/** @var list<string> uids notified, in order, one entry per notify() */
	private array $notified = [];

	/** @var int calls to markProcessed() */
	private int $cleared = 0;

	protected function setUp(): void {
		$this->store = [];
		$this->notified = [];
		$this->cleared = 0;
	}

	public function testAHealthyProbeTellsNobody(): void {
		$this->runDays(5, healthy: true);

		self::assertSame([], $this->notified);
		self::assertSame(0, $this->store['probe_failures_alerts'] ?? 0);
	}

	public function testTheFirstFailedProbeIsNotWorthWakingAnyoneFor(): void {
		// The read path refreshes hourly, so one failed probe is usually a blip that
		// has already healed. Counting it, but not shouting about it, is the point.
		$this->runDays(1, healthy: false);

		self::assertSame([], $this->notified);
		self::assertSame(1, $this->store['probe_failures_alerts']);
		self::assertSame(self::NOW, $this->store['probe_failing_since_alerts']);
	}

	public function testAdminsAreToldOnTheSecondConsecutiveFailure(): void {
		$this->runDays(2, healthy: false);

		// three sources (MINSAL alertas, MINSAL IRAG, ISP) x one admin
		self::assertSame(['admin', 'admin', 'admin'], $this->notified);
	}

	public function testAThirtyDayOutageProducesThreeAlarmsAndNotThirty(): void {
		$this->runDays(30, healthy: false);

		// days 2, 7 and 30, for each of the three sources — not 90 notifications
		self::assertCount(9, $this->notified);
		self::assertSame(30, $this->store['probe_failures_alerts']);
	}

	public function testTheFailingSinceStampIsSetOnceAndNotMovedEveryDay(): void {
		$this->runDays(9, healthy: false);

		// It is the answer to "since when", so it must be the FIRST failure, not the last.
		self::assertSame(self::NOW, $this->store['probe_failing_since_alerts']);
	}

	public function testEveryAlarmClearsTheOldOneFirst(): void {
		$this->runDays(7, healthy: false);

		// Two alarms per source (days 2 and 7), each preceded by a markProcessed, so
		// the bell holds one row per source rather than one per alarm.
		self::assertSame(6, $this->cleared);
	}

	public function testRecoveryResetsTheStateAndTakesTheNotificationOffTheBell(): void {
		$this->runDays(3, healthy: false);
		$this->cleared = 0;
		$this->runDays(1, healthy: true);

		self::assertSame(0, $this->store['probe_failures_alerts']);
		self::assertArrayNotHasKey('probe_failing_since_alerts', $this->store);
		self::assertSame(3, $this->cleared, 'one per source');
	}

	public function testAlreadyHealthyRunsDoNotTouchTheBellAtAll(): void {
		$this->runDays(3, healthy: true);

		self::assertSame(0, $this->cleared, 'nothing to clear, so nothing is cleared');
	}

	public function testTheOutageClockRestartsAfterARecovery(): void {
		$this->runDays(1, healthy: false);
		$this->runDays(1, healthy: true);
		$this->runDays(1, healthy: false);

		// If the counter had not been reset this would already be the second failure
		// and would have alarmed. A recovery has to genuinely clear the slate.
		self::assertSame([], $this->notified);
		self::assertSame(1, $this->store['probe_failures_alerts']);
	}

	/**
	 * The ISP is a separate source with a separate counter. If it shared MINSAL's,
	 * an ISP outage would reset MINSAL's clock on every healthy MINSAL day and the
	 * alarm would never reach day 2.
	 *
	 * The keys are the registry ids, not the repositories' internal feed names:
	 * everything outside a repository speaks one vocabulary, which is what stops
	 * the job and the setup check counting different things.
	 */
	public function testEachSourceKeepsItsOwnFailureCount(): void {
		$this->runDays(3, healthy: false);

		self::assertSame(3, $this->store['probe_failures_alerts']);
		self::assertSame(3, $this->store['probe_failures_reports']);
		self::assertSame(3, $this->store['probe_failures_sanitary_alerts']);
	}

	public function testTheNotifiedGroupIsConfigurable(): void {
		$this->store['notify_groups'] = ['informatica'];
		$this->runDays(2, healthy: false);

		self::assertSame(['tecnico', 'tecnico', 'tecnico'], $this->notified);
	}

	// -----------------------------------------------------------------------

	private function runDays(int $days, bool $healthy): void {
		for ($day = 0; $day < $days; $day++) {
			$job = $this->job($healthy, self::NOW + $day * 86400);
			(new ReflectionMethod($job, 'run'))->invoke($job, null);
		}
	}

	private function job(bool $healthy, int $now): ProbeJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('probe')->willReturn($healthy);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setSubject', 'setObject', 'setDateTime', 'setUser'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('getUser')->willReturnCallback(fn (): string => $this->lastUid);

		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('markProcessed')->willReturnCallback(function (): void {
			$this->cleared++;
		});
		// setUser() is what carries the recipient, so record it there: asserting on
		// notify() alone cannot tell one admin from another.
		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification): INotification {
				$this->lastUid = $uid;

				return $notification;
			},
		);
		$notifications->method('notify')->willReturnCallback(function (): void {
			$this->notified[] = $this->lastUid;
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid): ?IGroup {
				$uid = ['admin' => 'admin', 'informatica' => 'tecnico'][$gid] ?? null;
				if ($uid === null) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$group = $this->createMock(IGroup::class);
				$group->method('getUsers')->willReturn([$user]);

				return $group;
			},
		);

		return new ProbeJob($time, $sources, $notifications, $groups, $this->config());
	}

	private string $lastUid = '';

	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueInt')->willReturnCallback(
			fn (string $key, int $default = 0): int => (int)($this->store[$key] ?? $default),
		);
		$config->method('setAppValueInt')->willReturnCallback(
			function (string $key, int $value): bool {
				$this->store[$key] = $value;

				return true;
			},
		);
		$config->method('getAppValueArray')->willReturnCallback(
			fn (string $key, array $default = []): array => (array)($this->store[$key] ?? $default),
		);
		$config->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->store[$key]);
		});

		return $config;
	}
}
