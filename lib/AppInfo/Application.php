<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\AppInfo;

use OCA\Epidemiologia\Notification\Notifier;
use OCA\Epidemiologia\Search\AlertProvider;
use OCA\Epidemiologia\SetupCheck\SourcesReachable;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public function __construct(array $urlParams = []) {
		parent::__construct(AppId::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Everything else is autowired: no external PHP dependency, so no composer
		// autoloader to pull in and no service that cannot be constructed.
		$context->registerSearchProvider(AlertProvider::class);
		$context->registerNotifierService(Notifier::class);
		// The same probe state the notification reports, as a permanent row in
		// Administración → Visión general. A status row cannot spam; the bell can.
		$context->registerSetupCheck(SourcesReachable::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
