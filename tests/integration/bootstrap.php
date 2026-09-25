<?php

declare(strict_types=1);

/**
 * Boots the real Nextcloud the mappers are typed against — the container the
 * unit suite already runs in — so the private OC\DB machinery the in-memory
 * connection needs is present. The database the tests talk to is their own:
 * an in-memory SQLite built per test (see IntegrationTestCase), never the
 * install's own database.
 *
 * territorio's integration doctrine, on the SQLite the review names (L3-06):
 * this app has no compose rig of its own, and the one thing a real insert
 * answers — do an Entity's camelCase properties land in the migration's
 * snake_case columns with the right types — is answerable without one.
 */
$nextcloud = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';

if (!is_file($nextcloud . '/lib/base.php')) {
	fwrite(STDERR, "No encuentro Nextcloud en $nextcloud. Ejecuta las pruebas con scripts/test.sh.\n");
	exit(1);
}
require_once $nextcloud . '/lib/base.php';

\OC_App::loadApp('epidemiologia');

/*
 * The one class the suite shares: PHPUnit loads the *Test.php files itself,
 * and the abstract base underneath them needs this entry — the same doctrine
 * tests/bootstrap.php carries for the Doubles trait.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Epidemiologia\\Tests\\Integration\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
