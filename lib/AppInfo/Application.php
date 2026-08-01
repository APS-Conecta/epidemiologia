<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\AppInfo;

use OCA\Epidemiologia\Notification\Notifier;
use OCA\Epidemiologia\Search\AlertProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'epidemiologia';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Everything else is autowired: no external PHP dependency, so no composer
		// autoloader to pull in and no service that cannot be constructed.
		$context->registerSearchProvider(AlertProvider::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
