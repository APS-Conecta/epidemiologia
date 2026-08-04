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
	 */
	public function testTheDeclaredLicenceIsActuallyShipped(): void {
		$info = simplexml_load_string((string)file_get_contents(self::root() . '/appinfo/info.xml'));
		self::assertSame('agpl', (string)$info->licence, 'this test only knows how to check the AGPL');

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
