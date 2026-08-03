<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\BackgroundJob;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

/**
 * Detects that a source changed under us.
 *
 * The read path is lazy — it refreshes only when someone opens the app — so on
 * its own a MINSAL restructure in December would go unnoticed until a clinician
 * opened Epidemiología in February, and their experience of discovering it would
 * be "esto no funciona".
 *
 * This job exists to *detect*, not to warm the cache. That distinction is why it
 * earns its keep where a cache-warming job would not; a warm cache is a side
 * effect. Yearly cost is roughly 310 KB.
 *
 * It keeps a consecutive-failure count per source, which is the substrate for
 * both admin-facing signals: the backoff below, and SourcesReachable, the setup
 * check that shows the same state in Administración → Visión general. They read
 * one value, so they cannot disagree — and since #37 they also enumerate from one
 * list, so they cannot cover different sources either, which is exactly how the
 * ISP ended up counted here and invisible there.
 */
class ProbeJob extends TimedJob {
	/**
	 * Consecutive daily failures at which admins are told: two days, a week, a month.
	 *
	 * Nextcloud deduplicates notifications *nowhere* — `Manager::notify()` fans out,
	 * the handler runs a bare INSERT, `oc_notifications` has no unique index on the
	 * tuple we set, and the read path does not collapse either. Without a schedule
	 * this job would file two rows per admin per day for as long as MINSAL is down,
	 * and a bell that cries every day gets muted — which destroys the one signal the
	 * job exists to produce.
	 *
	 * Two days, not the three that updatenotification uses for its own connection
	 * errors: a pending software update can wait, a stale list of epidemiological
	 * alerts is consulted daily in the CESFAM. One day would be too eager — the read
	 * path already refreshes hourly, so a single failed probe is usually a blip that
	 * has healed by itself.
	 */
	private const NOTIFY_AT = [2, 7, 30];

	/** Consecutive failed probes, per source. 0 means healthy. */
	public const FAILURES = 'probe_failures_';

	/** Unix time of the first failure in the current run, per source. */
	public const FAILING_SINCE = 'probe_failing_since_';

	public function __construct(
		ITimeFactory $time,
		private Sources $sources,
		private IManager $notifications,
		private IGroupManager $groups,
		private IAppConfig $config,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		// Every source through the SAME backoff and the same counters, so two
		// simultaneous outages cannot drown each other out — each keeps its own
		// failure count and its own "failing since" stamp, and each is notified on
		// its own 2nd, 7th and 30th consecutive day.
		//
		// They probe differently underneath: MinsalRepository asks one page for its
		// modification date, while IspRepository has 24 feeds and no cheap liveness
		// signal, so its probe is a full refresh. That is the price of a source with
		// no equivalent of `modified`, and it is the repositories' business, not this
		// job's — which is why this loop no longer names either of them.
		foreach (Sources::ALL as $source) {
			$this->judge($source, $this->sources->probe($source));
		}
	}

	private function judge(string $source, bool $healthy): void {
		if ($healthy) {
			$this->recovered($source);
		} else {
			$this->failed($source);
		}
	}

	/** Clear the state and take the notification back off the bell. */
	private function recovered(string $source): void {
		if ($this->config->getAppValueInt(self::FAILURES . $source) === 0) {
			return;
		}

		$this->config->setAppValueInt(self::FAILURES . $source, 0);
		$this->config->deleteAppValue(self::FAILING_SINCE . $source);
		$this->notifications->markProcessed($this->match($source));
	}

	private function failed(string $source): void {
		$failures = $this->config->getAppValueInt(self::FAILURES . $source) + 1;
		$this->config->setAppValueInt(self::FAILURES . $source, $failures);

		if ($failures === 1) {
			$this->config->setAppValueInt(self::FAILING_SINCE . $source, $this->time->getTime());
		}
		if (!in_array($failures, self::NOTIFY_AT, true)) {
			return;
		}

		$this->tellAdmins($source, $failures);
	}

	/**
	 * Administrators only. The fault is technical and they are the only people who
	 * can repair it; a clinician who cannot fix it should not be paged about it.
	 * What clinicians get instead is the "problemas técnicos" banner on the page,
	 * which is the signal they can actually act on.
	 */
	private function tellAdmins(string $source, int $days): void {
		// Clear first, so the bell holds one row per source rather than one per alarm:
		// on day 30 an admin should see one current notification, not three stale ones.
		$this->notifications->markProcessed($this->match($source));

		$notification = $this->match($source)
			->setDateTime(new \DateTime('@' . $this->time->getTime()))
			->setSubject('probe_failed', ['source' => $source, 'days' => $days]);

		foreach ($this->admins() as $uid) {
			$this->notifications->notify($notification->setUser($uid));
		}
	}

	/**
	 * App, subject and object only — no user, no parameters. This is what
	 * markProcessed() matches on, and leaving the user off clears it for everyone.
	 */
	private function match(string $source): INotification {
		return $this->notifications->createNotification()
			->setApp(Application::APP_ID)
			->setSubject('probe_failed')
			->setObject('source', $source);
	}

	/**
	 * @return list<string>
	 */
	private function admins(): array {
		// Configurable for the same reason updatenotification makes it configurable:
		// whoever keeps this instance running is not necessarily a Nextcloud admin.
		$uids = [];
		foreach ($this->config->getAppValueArray('notify_groups', ['admin']) as $gid) {
			foreach ($this->groups->get((string)$gid)?->getUsers() ?? [] as $user) {
				$uids[$user->getUID()] = true;
			}
		}

		return array_keys($uids);
	}
}
