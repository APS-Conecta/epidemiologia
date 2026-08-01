<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// The contract other APS Conecta apps read. Versioned because they may
		// depend on it; session-authenticated because the only consumers are
		// inside this Nextcloud.
		['name' => 'api#alerts', 'url' => '/api/v1/alerts', 'verb' => 'GET'],
		['name' => 'api#reports', 'url' => '/api/v1/reports', 'verb' => 'GET'],
	],
];
