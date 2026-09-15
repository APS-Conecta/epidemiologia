<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\CertificateBundle;
use OCP\ICertificateManager;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class CertificateBundleTest extends TestCase {
	/**
	 * A directory this test owns, created fresh per test and removed in
	 * tearDown() — NEVER the machine's real temp base dir. A previous version of
	 * this suite used sys_get_temp_dir() directly, which is the exact directory
	 * getTempBaseDir() returns in production too; a root-run test writing there
	 * left a root-owned leftover at a fixed filename that the web server's user
	 * could neither read past nor repair, and ISP requests verified against a
	 * test fixture. Isolation here is not tidiness, it is what stops that
	 * recurring.
	 */
	private string $scratch;

	protected function setUp(): void {
		$this->scratch = sys_get_temp_dir() . '/epi-certbundle-test-' . bin2hex(random_bytes(8));
		mkdir($this->scratch);
	}

	protected function tearDown(): void {
		foreach (glob($this->scratch . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->scratch);
	}

	/**
	 * @return array{CertificateBundle, string} the bundle and the fake instance anchor file
	 */
	private function bundle(string $anchors, ?string $tempBaseDir = null): array {
		$instanceBundle = tempnam($this->scratch, 'anchors');
		file_put_contents($instanceBundle, $anchors);

		$manager = $this->createMock(ICertificateManager::class);
		$manager->method('getAbsoluteBundlePath')->willReturn($instanceBundle);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($tempBaseDir ?? $this->scratch);

		return [new CertificateBundle($manager, $temp), $instanceBundle];
	}

	public function testTheBundleCarriesBothTheInstanceAnchorsAndTheShippedIntermediate(): void {
		[$bundle] = $this->bundle("-- INSTANCE ANCHORS --\n");

		$merged = (string)file_get_contents($bundle->path());

		self::assertStringContainsString('-- INSTANCE ANCHORS --', $merged);
		self::assertStringContainsString('BEGIN CERTIFICATE', $merged);
	}

	public function testItRebuildsWhenTheInstanceAnchorsChange(): void {
		[$bundle, $instanceBundle] = $this->bundle("-- FIRST --\n");
		$bundle->path();

		file_put_contents($instanceBundle, "-- SECOND --\n");
		touch($instanceBundle, time() + 5);
		clearstatcache();

		self::assertStringContainsString('-- SECOND --', (string)file_get_contents($bundle->path()));
	}

	/**
	 * I2: getAbsoluteBundlePath() switches between two different files depending on
	 * whether any CA is imported — an admin removing their last import flips the
	 * source to the packaged default, whose mtime is its release date and can be
	 * OLDER than an already-existing merge. An mtime-only staleness check would see
	 * "not newer" and keep serving the old merge forever, silently continuing to
	 * trust a CA the admin just removed.
	 *
	 * The new file's mtime is deliberately set far in the PAST, so this fails
	 * against exactly that regression (comparing only "is either input newer than
	 * the merge?") while passing against a check that keys on the input's identity,
	 * path included.
	 */
	public function testSwitchingToADifferentButOlderInstanceBundleStillRebuilds(): void {
		[$bundle] = $this->bundle("-- IMPORTED CA --\n");
		$bundle->path();

		$older = tempnam($this->scratch, 'anchors');
		file_put_contents($older, "-- PACKAGED DEFAULT --\n");
		touch($older, time() - (365 * 24 * 3600));
		clearstatcache();

		$manager = $this->createMock(ICertificateManager::class);
		$manager->method('getAbsoluteBundlePath')->willReturn($older);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($this->scratch);
		$afterRemoval = new CertificateBundle($manager, $temp);

		$merged = (string)file_get_contents($afterRemoval->path());

		self::assertStringContainsString('-- PACKAGED DEFAULT --', $merged);
	}

	/**
	 * C1: a file at the exact path this process would choose is still not proof
	 * this process wrote it. Measured in production — a root-owned leftover at this
	 * app's (then-constant) filename that www-data could not read past or repair,
	 * so ISP requests verified against a stray fixture instead of real anchors.
	 *
	 * Simulated here by building the real merge once, then handing ownership of
	 * that exact file to another uid and corrupting its content — root can do both,
	 * and this suite runs as root in the container. A path() call afterwards must
	 * not return the corrupted bytes.
	 */
	public function testACachedFileThisProcessDoesNotOwnIsRebuiltRatherThanTrusted(): void {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			self::markTestSkipped('needs root to chown a file away from the test process');
		}

		[$bundle] = $this->bundle("-- REAL ANCHORS --\n");
		$first = $bundle->path();

		file_put_contents($first, '-- POISONED BY SOMEONE ELSE --');
		chown($first, 65534); // nobody — guaranteed not this process's euid
		clearstatcache();

		$rebuilt = (string)file_get_contents($bundle->path());

		self::assertStringNotContainsString('POISONED', $rebuilt);
		self::assertStringContainsString('-- REAL ANCHORS --', $rebuilt);
	}

	public function testAnUnwritableScratchDirectoryFallsBackToTheInstanceBundle(): void {
		// The write itself failing — a full or read-only scratch directory. A
		// nonexistent directory is the one way to force this deterministically even
		// when the test process is root, which bypasses permission bits entirely.
		[$bundle, $instanceBundle] = $this->bundle("-- ANCHORS --\n", '/nonexistent-scratch-dir');

		self::assertSame($instanceBundle, $bundle->path());
	}

	/**
	 * I1: ITempManager::getTempBaseDir() is documented to throw
	 * UnexpectedValueException when a hardened deployment has no writable
	 * candidate directory at all — the lookup itself can fail, not only a
	 * subsequent write. A mock that merely RETURNS an unwritable path (the
	 * previous version of this test) can never exercise that: the real
	 * collaborator never returns such a path, it throws instead.
	 */
	public function testALookupThatThrowsFallsBackToTheInstanceBundle(): void {
		$instanceBundle = tempnam($this->scratch, 'anchors');
		file_put_contents($instanceBundle, "-- ANCHORS --\n");

		$manager = $this->createMock(ICertificateManager::class);
		$manager->method('getAbsoluteBundlePath')->willReturn($instanceBundle);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willThrowException(
			new UnexpectedValueException('no writable temp directory could be found'),
		);

		$bundle = new CertificateBundle($manager, $temp);

		self::assertSame($instanceBundle, $bundle->path());
	}
}
