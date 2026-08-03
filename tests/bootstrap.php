<?php

declare(strict_types=1);

/**
 * The suite runs inside the Nextcloud container, so it borrows that installation's
 * OCP interfaces and PSR-3 rather than vendoring a copy. This app has no PHP
 * dependencies of its own, so there is no composer.json and no vendor/ to build.
 */
$nextcloud = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';

$thirdParty = $nextcloud . '/3rdparty/autoload.php';
if (!is_file($thirdParty)) {
	fwrite(STDERR, "No encuentro Nextcloud en $nextcloud. Ejecuta las pruebas con scripts/test.sh.\n");
	exit(1);
}
require_once $thirdParty;

spl_autoload_register(static function (string $class) use ($nextcloud): void {
	// Most specific prefix FIRST: the loop returns on the first one that matches,
	// so a Tests\Unit class under the general OCA\Epidemiologia\ root would be
	// looked for in lib/Tests/Unit/ and never found. PHPUnit loads the *Test.php
	// files itself; this entry exists for what they share — the Doubles trait.
	$roots = [
		'OCA\\Epidemiologia\\Tests\\Unit\\' => __DIR__ . '/unit/',
		'OCP\\' => $nextcloud . '/lib/public/',
		'OCA\\Epidemiologia\\' => dirname(__DIR__) . '/lib/',
	];

	foreach ($roots as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) {
				require_once $file;
			}

			return;
		}
	}
});
