<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\BackgroundJob;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\Notification\IManager;

/**
 * Detects that MINSAL changed under us.
 *
 * The read path is lazy — it refreshes only when someone opens the app — so on
 * its own a MINSAL restructure in December would go unnoticed until a clinician
 * opened Epidemiología in February, and their experience of discovering it would
 * be "esto no funciona".
 *
 * This job exists to *detect*, not to warm the cache. That distinction is why it
 * earns its keep where a cache-warming job would not; a warm cache is a side
 * effect. Yearly cost is roughly 310 KB.
 */
class ProbeJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MinsalRepository $repository,
		private IManager $notifications,
		private IGroupManager $groups,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		foreach ([MinsalRepository::ALERTS, MinsalRepository::IRAG] as $feed) {
			if (!$this->repository->probe($feed)) {
				$this->tellAdmins($feed);
			}
		}
	}

	/**
	 * Administrators only. The fault is technical and they are the only people who
	 * can repair it; a clinician who cannot fix it should not be paged about it.
	 * What clinicians get instead is the "problemas técnicos" banner on the page,
	 * which is the signal they can actually act on.
	 */
	private function tellAdmins(string $feed): void {
		$notification = $this->notifications->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setDateTime(new \DateTime('@' . $this->time->getTime()))
			->setObject('feed', $feed)
			->setSubject('probe_failed', ['feed' => $feed]);

		foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
			$this->notifications->notify($notification->setUser($admin->getUID()));
		}
	}
}
