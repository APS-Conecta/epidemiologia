<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Migration;

use OCA\Epidemiologia\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Notification\IManager;

/**
 * Wipes the residue the SANITARY_ALERTS retirement leaves behind: two probe
 * config keys and any notification rows the retired fuente's outages filed.
 *
 * Why a repair step and not the migration's postSchemaChange: that hook is
 * skipped when an app's schema is applied on a first install (schemaOnly),
 * and upgraded installs arrive via post-migration — so the step is
 * registered for both events and is idempotent, territorio's EnsureSeedData
 * pattern. Nothing it deletes exists on a fresh install; deleteAppValue on a
 * missing key and markProcessed with nothing to match are both no-ops, so
 * running it twice, or on a clean install, changes nothing and says nothing.
 *
 * Why wipe at all, when the notifier's render guard already drops stale rows:
 * the guard makes them INVISIBLE, not gone. The two config keys are the worse
 * residue — ProbeRecord::clear() is reachable only for ids in Sources::ALL,
 * so nothing ever deletes `probe_failures_sanitary_alerts`; if a future
 * fuente ever reused the id, it would inherit the old count and ring a false
 * day-7 alarm on its first real outage. A residue nothing can reach is not
 * the same as no residue.
 */
class RetireSanitaryAlerts implements IRepairStep {
	/**
	 * The two ProbeRecord keys of the retired fuente, spelled out here rather
	 * than borrowed from ProbeRecord: its consts are a private prefix pair for
	 * ids in Sources::ALL, and this id left ALL with ADR-0016 — nothing live
	 * writes these keys again, so they are frozen history and this file is
	 * their tombstone. The spelling is pinned here, by this step's own suite,
	 * because no other test can go red if it drifts.
	 */
	private const FAILURES = 'probe_failures_sanitary_alerts';
	private const SINCE = 'probe_failing_since_sanitary_alerts';

	public function __construct(
		private IAppConfig $config,
		private IManager $notifications,
	) {
	}

	public function getName(): string {
		return 'Epidemiología: wipe the retired sanitary_alerts residue';
	}

	public function run(IOutput $output): void {
		$found = 0;
		foreach ([self::FAILURES, self::SINCE] as $key) {
			// -1 is absent; the keys hold ints, so the sentinel is unambiguous.
			if ($this->config->getAppValueInt($key, -1) !== -1) {
				$found++;
			}
			$this->config->deleteAppValue($key);
		}

		/*
		 * App, subject and object, no user and no parameters — exactly
		 * ProbeJob::match()'s shape: leaving the user off is what clears
		 * every admin's row. The notifications app's markProcessed DELETEs
		 * (App.php → Handler::delete), so this is the wipe, not a flag; on a
		 * clean install it matches nothing and deletes nothing.
		 */
		$this->notifications->markProcessed(
			$this->notifications->createNotification()
				->setApp(Application::APP_ID)
				->setSubject('probe_failed')
				->setObject('source', 'sanitary_alerts'),
		);

		if ($found > 0) {
			$output->info('Epidemiología: wiped the retired sanitary_alerts probe residue');
		}
	}
}
