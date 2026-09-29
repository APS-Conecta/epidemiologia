<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\BackgroundJob;

use OCA\Epidemiologia\AppInfo\AppId;
use OCA\Epidemiologia\Service\Probe;
use OCA\Epidemiologia\Service\ProbeRecord;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Throwable;

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
	 * errors: a pending software update can wait, whereas a stale list of alertas
	 * vigentes hides an alert while it is in force. One day would be too eager —
	 * the read path already refreshes hourly, so a single failed probe is usually a
	 * blip that has healed by itself.
	 */
	private const NOTIFY_AT = [2, 7, 30];



	public function __construct(
		ITimeFactory $time,
		private Sources $sources,
		private IManager $notifications,
		private IGroupManager $groups,
		private ProbeRecord $record,
		// Still here, but for `notify_groups` alone — who to tell. The failure counts
		// it used to hold are ProbeRecord's now.
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
		// They probe differently underneath: AlertRepository fetches its one page
		// every time — an HTML page carries no field-limited request the way its old
		// JSON endpoint's `modified` did — and only skips rewriting the cache when a
		// digest of the parsed items is unchanged. IspReportRepository's probe is one
		// feed fetch through the brake, re-upserting page 1 as it goes (no digest to
		// skip on, D8) — the deep pass is converge()'s, on the refresh job (L3-08).
		// That cost is each repository's own business, not this job's — which is why
		// this loop names neither of them.
		//
		// Per-source isolation (L3-03): a probe that THROWS must neither starve the
		// sibling fuente nor vanish this one's ProbeRecord — unrecorded, the day
		// counts as neither a failure nor a success and the [2, 7, 30] schedule
		// silently skips it. Thrown answers as Unreadable — the fuente's own fault,
		// holding nobody — the loop continues, and each fuente keeps its own count
		// either way: the property this loop has always promised.
		//
		// The host grain (ADR-0017 D8), which this loop missed until 0.11.2: four
		// fuentes share the ISP's host, and when a host gives no answer at all
		// (Probe::Unreachable) its other fuentes are not asked in the same pass —
		// each would pay the same 15-second timeout and learn nothing new (the
		// lab, 2026-09-29: four in a row, a minute of cron). They are recorded as
		// failing with it, so every count stays true. A host that answered, even
		// with a refusal, holds nobody: each address gets its own ask, and the
		// next answer is what lifts a brake (Freno::lift()).
		$unreachable = [];
		$failing = [];
		foreach (Sources::ALL as $source) {
			$host = $this->sources->host($source);

			if (isset($unreachable[$host])) {
				$verdict = Probe::Unreachable;
			} else {
				try {
					$verdict = $this->sources->probe($source);
				} catch (Throwable) {
					$verdict = Probe::Unreadable;
				}

				if ($verdict === Probe::Unreachable) {
					$unreachable[$host] = true;
				}
			}

			$failures = $this->judge($source, $verdict === Probe::Healthy);
			if ($failures > 0) {
				$failing[$host][$source] = $failures;
			}
		}

		foreach ($failing as $fuentes) {
			$this->ring($fuentes);
		}
	}

	/**
	 * One probe recorded, and the notification taken off the bell on recovery.
	 *
	 * The count lives in ProbeRecord, which returns it rather than making this read
	 * back what it just wrote; this hands it on, 0 for a healthy day. WHEN to shout
	 * is ring()'s, once the pass knows which fuentes failed with which host.
	 */
	private function judge(string $source, bool $reachable): int {
		$before = $this->record->consecutive($source);
		$failures = $this->record->record($source, $reachable);

		// Only when there was something to take down, or every healthy day would
		// re-notify the notification manager about a notification nobody filed.
		if ($reachable && $before > 0) {
			$this->notifications->markProcessed($this->match($source));
		}

		return $reachable ? 0 : $failures;
	}

	/**
	 * One bell per host and pass: under the first of its failing fuentes whose
	 * count is a day for one, naming the others that failed with it. Four bells
	 * for one unreachable ISP told an admin four times what one sentence says.
	 * The fuentes keep their own counts; only the telling is shared.
	 *
	 * @param array<string, int> $failing consecutive failures by source, in ALL's order
	 */
	private function ring(array $failing): void {
		foreach ($failing as $source => $days) {
			if (in_array($days, self::NOTIFY_AT, true)) {
				$this->tellAdmins($source, $days, array_values(array_diff(array_keys($failing), [$source])));

				return;
			}
		}
	}

	/**
	 * Administrators only. The fault is technical and they are the only people who
	 * can repair it; a clinician who cannot fix it should not be paged about it.
	 * What clinicians get instead is the "problemas técnicos" banner on the page,
	 * which is the signal they can actually act on.
	 */
	/** @param list<string> $with the host's other failing fuentes, which this bell also names */
	private function tellAdmins(string $source, int $days, array $with): void {
		// Clear first, so the bell holds one row per source rather than one per alarm:
		// on day 30 an admin should see one current notification, not three stale ones.
		$this->notifications->markProcessed($this->match($source));

		$notification = $this->match($source)
			->setDateTime(new \DateTime('@' . $this->time->getTime()))
			->setSubject('probe_failed', ['source' => $source, 'days' => $days] + ($with === [] ? [] : ['with' => $with]));

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
			->setApp(AppId::APP_ID)
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
