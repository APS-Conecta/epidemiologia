<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\ProbeJob;
use OCA\Epidemiologia\Service\Probe;
use OCA\Epidemiologia\Service\ProbeRecord;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The backoff schedule, which is the whole point of this job's rewrite.
 *
 * Nextcloud deduplicates notifications nowhere, so without a schedule a MINSAL
 * outage files two rows per admin per day forever, the bell gets muted, and the
 * detection stops being a detection. These tests exist to keep that schedule
 * honest — an untested backoff is a backoff that quietly becomes "every run".
 */
class ProbeJobTest extends TestCase {
	use Doubles;

	private const NOW = 1785585600;

	/**
	 * The hosts the double answers with — the real map, pinned against the
	 * factory by AlertRepositoryTest::testTheIspsFuentesShareOneHostAndEveryOtherHostIsItsOwn:
	 * the informe and the ISP's three alert feeds on one host, everyone else alone.
	 */
	private const HOSTS = [
		Sources::REPORTS => 'isp',
		Sources::ISP_ANAMED => 'isp',
		Sources::ISP_PHARMACOVIGILANCE => 'isp',
		Sources::ISP_COSMETICS => 'isp',
	];

	/** @var list<string> ids the job asked Sources::probe() for, in order */
	private array $probed = [];

	/** @var list<array<string, mixed>> the parameters of every bell rung, in order */
	private array $bells = [];

	/** @var list<string> uids notified, in order, one entry per notify() */
	private array $notified = [];

	/** @var int calls to markProcessed() */
	private int $cleared = 0;

	protected function setUp(): void {
		$this->config = [];
		$this->notified = [];
		$this->cleared = 0;
		$this->probed = [];
		$this->bells = [];
	}

	public function testAHealthyProbeTellsNobody(): void {
		$this->runDays(5, healthy: true);

		self::assertSame([], $this->notified);
		self::assertSame(0, $this->probeRecord()->consecutive('alerts'));
	}

	public function testTheFirstFailedProbeIsNotWorthWakingAnyoneFor(): void {
		// The read path refreshes hourly, so one failed probe is usually a blip that
		// has already healed. Counting it, but not shouting about it, is the point.
		$this->runDays(1, healthy: false);

		self::assertSame([], $this->notified);
		self::assertSame(1, $this->probeRecord()->consecutive('alerts'));
		self::assertSame(self::NOW, $this->probeRecord()->failingSince('alerts'));
	}

	public function testAdminsAreToldOnTheSecondConsecutiveFailure(): void {
		$this->runDays(2, healthy: false);

		// one bell per host whose fuentes failed x one admin — not one per fuente
		self::assertSame(array_fill(0, $this->hosts(), 'admin'), $this->notified);
	}

	public function testAThirtyDayOutageProducesThreeAlarmsAndNotThirty(): void {
		$this->runDays(30, healthy: false);

		// days 2, 7 and 30, for each host — not thirty per host
		self::assertCount(3 * $this->hosts(), $this->notified);
		self::assertSame(30, $this->probeRecord()->consecutive('alerts'));
	}

	public function testTheFailingSinceStampIsSetOnceAndNotMovedEveryDay(): void {
		$this->runDays(9, healthy: false);

		// It is the answer to "since when", so it must be the FIRST failure, not the last.
		self::assertSame(self::NOW, $this->probeRecord()->failingSince('alerts'));
	}

	public function testEveryAlarmClearsTheOldOneFirst(): void {
		$this->runDays(7, healthy: false);

		// Two alarms per host (days 2 and 7), each preceded by a markProcessed, so
		// the bell holds one row per host rather than one per alarm.
		self::assertSame(2 * $this->hosts(), $this->cleared);
	}

	public function testRecoveryResetsTheStateAndTakesTheNotificationOffTheBell(): void {
		$this->runDays(3, healthy: false);
		$this->cleared = 0;
		$this->runDays(1, healthy: true);

		self::assertSame(0, $this->probeRecord()->consecutive('alerts'));
		self::assertNull($this->probeRecord()->failingSince('alerts'));
		self::assertSame(count(Sources::ALL), $this->cleared, 'one per source');
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
		self::assertSame(1, $this->probeRecord()->consecutive('alerts'));
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

		self::assertSame(3, $this->probeRecord()->consecutive('alerts'));
		self::assertSame(3, $this->probeRecord()->consecutive('reports'));
	}

	public function testTheNotifiedGroupIsConfigurable(): void {
		$this->config['notify_groups'] = ['informatica'];
		$this->runDays(2, healthy: false);

		self::assertSame(array_fill(0, $this->hosts(), 'tecnico'), $this->notified);
	}

	/**
	 * Per-source isolation (L3-03): probe() throws for ALERTS, answers true
	 * for REPORTS — both fuentes keep their own judgement, the thrower as a
	 * failure and the sibling as the healthy day it had. Unrecorded, the
	 * thrower's day would count as neither and the [2, 7, 30] schedule would
	 * silently skip it.
	 */
	public function testAThrowingProbeIsRecordedAsAFailureAndDrownsNobody(): void {
		$this->runDays(1, healthy: true, throwFor: 'alerts');

		self::assertSame(1, $this->probeRecord()->consecutive(Sources::ALERTS));
		self::assertSame(0, $this->probeRecord()->consecutive(Sources::REPORTS));
	}

	/**
	 * The host grain (ADR-0017 D8), applied to the sondeo. REPORTS is the ISP's
	 * first fuente in ALL; when its host gives no answer at all, the ISP's three
	 * alert feeds are not asked in the same pass — each would pay the same
	 * 15-second timeout and learn nothing the first did not (the lab,
	 * 2026-09-29: four in a row, a minute of cron) — and are recorded as
	 * failing with it, so each fuente's count stays true.
	 */
	public function testAHostThatGivesNoAnswerIsAskedOncePerPass(): void {
		$this->runDays(1, healthy: true, verdicts: [Sources::REPORTS => Probe::Unreachable]);

		$held = [Sources::ISP_ANAMED, Sources::ISP_PHARMACOVIGILANCE, Sources::ISP_COSMETICS];
		self::assertSame(array_values(array_diff(Sources::ALL, $held)), $this->probed,
			'the ISP asked once, every other host asked as before');
		foreach ([Sources::REPORTS, ...$held] as $id) {
			self::assertSame(1, $this->probeRecord()->consecutive($id), "$id is recorded as failing with its host");
		}
		self::assertSame(0, $this->probeRecord()->consecutive(Sources::PAHO), 'a host that answered is judged on its own');
	}

	/**
	 * The other failures hold nobody. A fuente its host answered for — a
	 * restructured page, a refusal — is that address's business, and a probe
	 * that threw is the fuente's own fault (L3-03): its siblings get their asks.
	 */
	public function testOnlyAHostWithNoAnswerHoldsItsSiblings(): void {
		$this->runDays(1, healthy: true, throwFor: Sources::REPORTS, verdicts: [Sources::ISP_ANAMED => Probe::Unreadable]);

		self::assertSame(Sources::ALL, $this->probed, 'every fuente asked');
		self::assertSame(1, $this->probeRecord()->consecutive(Sources::REPORTS));
		self::assertSame(1, $this->probeRecord()->consecutive(Sources::ISP_ANAMED));
		self::assertSame(0, $this->probeRecord()->consecutive(Sources::ISP_PHARMACOVIGILANCE));
	}

	/**
	 * One outage, one bell: a host's failing fuentes ring together on their
	 * day, under the first of them, naming the rest. Four bells for one
	 * unreachable ISP told an admin four times what one sentence says.
	 */
	public function testAHostOutageRingsOneBellNamingTheFuentesItHeld(): void {
		$this->runDays(2, healthy: true, verdicts: [Sources::REPORTS => Probe::Unreachable]);

		self::assertSame(['admin'], $this->notified);
		self::assertSame([[
			'source' => Sources::REPORTS,
			'days' => 2,
			'with' => [Sources::ISP_ANAMED, Sources::ISP_PHARMACOVIGILANCE, Sources::ISP_COSMETICS],
		]], $this->bells);
	}

	// -----------------------------------------------------------------------

	/** How many hosts the catalogue spans — one bell each when all fail. */
	private function hosts(): int {
		return count(array_unique(array_map(static fn (string $id): string => self::HOSTS[$id] ?? $id, Sources::ALL)));
	}

	/** @param array<string, Probe> $verdicts per-id answers; the rest follow $healthy */
	private function runDays(int $days, bool $healthy, ?string $throwFor = null, array $verdicts = []): void {
		for ($day = 0; $day < $days; $day++) {
			$job = $this->job($healthy, self::NOW + $day * 86400, $throwFor, $verdicts);
			(new ReflectionMethod($job, 'run'))->invoke($job, null);
		}
	}

	/** @param array<string, Probe> $verdicts */
	private function job(bool $healthy, int $now, ?string $throwFor = null, array $verdicts = []): ProbeJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('probe')->willReturnCallback(
			function (string $id) use ($healthy, $throwFor, $verdicts): Probe {
				$this->probed[] = $id;
				if ($id === $throwFor) {
					throw new RuntimeException('the probe threw instead of answering');
				}

				// A failing fuente answers Unreadable unless told otherwise: the host
				// answered, so nobody is held — each keeps its own ask and count.
				return $verdicts[$id] ?? ($healthy ? Probe::Healthy : Probe::Unreadable);
			},
		);
		$sources->method('host')->willReturnCallback(static fn (string $id): string => self::HOSTS[$id] ?? $id);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setObject', 'setDateTime', 'setUser'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		// A bell's parameters are what it says; match() sets the subject without any.
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use ($notification): INotification {
				if ($parameters !== []) {
					$this->bells[] = $parameters;
				}

				return $notification;
			},
		);
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

		return new ProbeJob($time, $sources, $notifications, $groups, $this->probeRecord($now), $this->appConfig());
	}

	/**
	 * The real ProbeRecord over the shared config double, not a mock. It is the thing
	 * whose answers these assertions are about, and stubbing it would leave them
	 * asserting that this job calls a collaborator — which ADR-0007 rejects.
	 */
	private function probeRecord(?int $now = null): ProbeRecord {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		return new ProbeRecord($this->appConfig(), $time);
	}

	private string $lastUid = '';

}
