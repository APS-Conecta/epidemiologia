<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What the repository says about itself, checked against itself.
 *
 * Nothing asserted the version anywhere, so it drifted into three different
 * answers: info.xml said 0.4.0, package.json 0.3.0 and the PUBLISHED API contract
 * 0.0.1. Nobody was lying on purpose; there was simply no place where disagreeing
 * cost anything. This is that place.
 *
 * It lives in the unit suite rather than in a shell gate because the unit suite is
 * the one thing that already runs on every push, and reading three files needs no
 * instance, no container and no network.
 */
class MetadataTest extends TestCase {
	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	/** appinfo/info.xml is the authority: it is what Nextcloud installs and what vite-config parses. */
	private static function declaredVersion(): string {
		$info = simplexml_load_string((string)file_get_contents(self::root() . '/appinfo/info.xml'));

		return (string)$info->version;
	}

	public function testInfoXmlDeclaresAVersion(): void {
		self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', self::declaredVersion());
	}

	/**
	 * The rule is "agree", not "exist": removing a redundant declaration satisfies it
	 * just as well as syncing one, and removing is the better fix where nothing reads it.
	 */
	public function testEveryFileThatDeclaresAVersionDeclaresTheSameOne(): void {
		$expected = self::declaredVersion();

		foreach (['package.json' => 'version', 'openapi.json' => null] as $file => $key) {
			$path = self::root() . '/' . $file;
			if (!file_exists($path)) {
				continue;
			}

			$json = json_decode((string)file_get_contents($path), true);
			$found = $key === null ? ($json['info']['version'] ?? null) : ($json[$key] ?? null);

			if ($found === null) {
				continue;
			}

			self::assertSame($expected, $found, "$file declares a version that info.xml does not");
		}
	}

	/**
	 * info.xml declares AGPL. The AGPL requires conveying a copy of itself with the
	 * work, so declaring it and shipping no text is a compliance defect, not a tidiness one.
	 *
	 * The value is matched against a SET rather than one string because the appstore
	 * schema accepts two spellings and prefers one: `info.xsd` enumerates the SPDX
	 * identifiers alongside the old short codes and marks `agpl`, `mit`, `mpl`,
	 * `apache` and `gpl3` **deprecated**. This app declares `AGPL-3.0-or-later`. The
	 * deprecated spelling stays recognised so that the compliance check below keeps
	 * applying to a manifest that still uses it, rather than the whole test turning
	 * into a spelling assertion and quietly stopping.
	 */
	private const AGPL = ['AGPL-3.0-or-later', 'AGPL-3.0-only', 'agpl'];

	public function testTheDeclaredLicenceIsActuallyShipped(): void {
		$info = simplexml_load_string((string)file_get_contents(self::root() . '/appinfo/info.xml'));
		self::assertContains((string)$info->licence, self::AGPL, 'this test only knows how to check the AGPL');

		$candidates = array_filter(
			array_map(
				static fn (string $name): string => self::root() . '/' . $name,
				['LICENSE', 'LICENSE.txt', 'COPYING', 'COPYING.txt'],
			),
			'file_exists',
		);

		self::assertNotEmpty($candidates, 'info.xml declares agpl but no licence text is shipped');

		$text = (string)file_get_contents((string)reset($candidates));
		self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', $text);
		self::assertStringContainsString('Version 3', $text);
	}

	/**
	 * All or none, and this repo chose all. Two of ten files carried a header and the
	 * rest did not, which reads as "these two are licensed and those eight are unclear".
	 */
	public function testEverySourceFileCarriesTheSameLicenceHeader(): void {
		$missing = [];
		foreach ($this->phpFilesIn(self::root() . '/lib') as $file) {
			if (!str_contains((string)file_get_contents($file), 'SPDX-License-Identifier: AGPL-3.0-or-later')) {
				$missing[] = substr($file, strlen(self::root()) + 1);
			}
		}

		self::assertSame([], $missing, 'these files carry no SPDX licence header');
	}

	/**
	 * Both entry points stay reachable by someone who is not an administrator.
	 *
	 * This app is for clinicians, and `#[NoAdminRequired]` is the whole of what says so:
	 * without it Nextcloud's SecurityMiddleware answers 403 to every non-admin, and the
	 * pane and the OCS route become admin-only. Deleting one attribute is a one-line,
	 * user-facing authorization change.
	 *
	 * Nothing would notice. `scripts/smoke.sh` runs as `${EPI_USER:-admin}`, so every
	 * assertion it makes about the route and the page keeps passing while no clinician
	 * can open either — the check holds while the property it stands for does not.
	 *
	 * It asserts the ATTRIBUTE and not a response, deliberately. The rule is enforced by
	 * middleware, so a unit test that instantiates the controller and calls the method
	 * bypasses exactly the thing under test and would prove nothing; the middleware
	 * itself is Nextcloud's, and asserting it here would be asserting the platform.
	 * What belongs to this app is the decision to carry the attribute, and that is what
	 * is pinned.
	 *
	 * Against [ADR-0007](../../docs/adr/0007-tests-follow-branching-not-layering.md)'s
	 * Consequences line, which names both controllers as deliberately untested — and
	 * FOR that ADR's own rule, which counts "a policy expressed as a dependency" as a
	 * decision worth a test. Between the rule and the list of classes it was applied to,
	 * the rule is the load-bearing half.
	 */
	public function testBothControllersStayReachableByPeopleWhoAreNotAdministrators(): void {
		$entryPoints = [
			\OCA\Epidemiologia\Controller\ApiController::class => 'sources',
			\OCA\Epidemiologia\Controller\PageController::class => 'index',
		];

		foreach ($entryPoints as $class => $method) {
			$attributes = (new \ReflectionMethod($class, $method))
				->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);

			self::assertCount(1, $attributes, sprintf(
				'%s::%s() lost #[NoAdminRequired] — every non-admin now gets 403, while '
				. 'smoke.sh, which runs as admin, stays green',
				$class,
				$method,
			));
		}
	}

	/** @return list<string> */
	private function phpFilesIn(string $dir): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		sort($files);

		return $files;
	}
}
