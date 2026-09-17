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
			// rmdir as well as unlink: one test below puts a DIRECTORY at the identity
			// path to make the publish fail, and a failed assertion before its own
			// cleanup would otherwise leave that directory in the machine's real temp
			// base dir forever — which is the litter this scratch directory exists to
			// prevent.
			is_dir($file) ? @rmdir($file) : @unlink($file);
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

	/**
	 * C1a: the ownership check above rejects a squatted file but cannot repair one —
	 * the squatter's file is not writable by us, and a sticky temp directory forbids
	 * unlinking someone else's. So whoever calls path() FIRST used to decide, for
	 * good, what every other user on the instance got. A root-run
	 * `occ background-job:execute` — the debugging move this repo invites — leaves a
	 * root-owned merge, and www-data then degrades on every request, permanently,
	 * behind a sha256 filename nobody can guess.
	 *
	 * Staged exactly that way: build the merge as root, leave it as root leaves it,
	 * then ask again under another euid. The other user must come away with its own
	 * merged bundle, not with the instance bundle.
	 *
	 * Fails if the euid is dropped from the identity hash: both users then choose the
	 * same filename, the second one cannot overwrite the first, and path() degrades.
	 */
	public function testAMergeLeftByAnotherUserDoesNotLockThisUserOut(): void {
		if (!function_exists('posix_seteuid') || posix_geteuid() !== 0) {
			self::markTestSkipped('needs root to run part of this test under a second euid');
		}

		// The web server's user where there is one, `nobody` otherwise: any euid that
		// is not this process's will do, and neither name is assumed to exist.
		$passwd = posix_getpwnam('www-data');
		$other = is_array($passwd) ? (int)$passwd['uid'] : 65534;

		[$bundle, $instanceBundle] = $this->bundle("-- REAL ANCHORS --\n");
		$asRoot = $bundle->path();
		self::assertSame(0, fileowner($asRoot), 'the first merge was meant to be written as root');

		// Everything the second user legitimately needs: a readable anchor file, a
		// writable scratch directory, and the squatter's own file left world-readable
		// but not world-writable, which is how a root-run `occ` leaves it. Without
		// these the second call would degrade for an unrelated reason and the test
		// would pass while proving nothing.
		chmod($instanceBundle, 0644);
		chmod($asRoot, 0644);
		chmod($this->scratch, 0777);
		clearstatcache();

		try {
			posix_seteuid($other);
			clearstatcache();
			$asOther = $bundle->path();
		} finally {
			posix_seteuid(0);
			clearstatcache();
		}

		self::assertNotSame($instanceBundle, $asOther, 'the second user degraded instead of building its own merge');
		self::assertNotSame($asRoot, $asOther, 'both users chose the same cache file, so one of them must lose');

		$merged = (string)file_get_contents($asOther);
		self::assertStringContainsString('-- REAL ANCHORS --', $merged);
		self::assertStringContainsString('BEGIN CERTIFICATE', $merged);
	}

	/**
	 * C1b: without ext-posix the euid used to come from getmyuid(), which reports the
	 * owner of CertificateBundle.php — root in any normal checkout — and not the
	 * owner of the process. Against a root-owned squatted file the comparison then
	 * reads `0 === 0`, so the guard trusted precisely the file it exists to reject.
	 *
	 * Asserted against the source, deliberately. The defect lives in the branch taken
	 * when ext-posix is ABSENT, and it is present everywhere this suite runs, so a
	 * behavioural test would exercise the other branch and prove nothing. What is
	 * pinned is the property that makes the branch safe: the stand-in value is a
	 * number no file owner can ever hold, so the check fails closed and the file is
	 * rebuilt.
	 *
	 * Fails if the getmyuid() fallback comes back, or if the stand-in becomes a value
	 * a real owner could hold (0, say).
	 */
	public function testTheOwnershipCheckFailsClosedWithoutExtPosix(): void {
		$source = self::source();

		self::assertSame(1, preg_match(
			'/function_exists\(\'posix_geteuid\'\)\s*\?\s*posix_geteuid\(\)\s*:\s*(\S+?);/',
			$source,
			$matches,
		), 'CertificateBundle no longer picks its euid in a shape this test can read');

		self::assertTrue(
			is_numeric($matches[1]) && (int)$matches[1] < 0,
			sprintf(
				'without ext-posix the euid becomes `%s`; it must be a number no file owner can hold, '
				. 'or an unowned file is trusted instead of rebuilt',
				$matches[1],
			),
		);
	}

	/**
	 * C1c: file_put_contents() documents itself as returning the number of bytes
	 * written, so "not false" is not success. A truncated write publishes a PARTIAL
	 * CA file under a correct identity name and with a correct owner — every later
	 * call trusts it and none ever rebuilds it, which is the one failure mode here
	 * that does not heal.
	 *
	 * Asserted against the source for a measured reason: on PHP 8.5 a short write is
	 * normalised to `false` before it reaches the caller (verified against a stream
	 * that accepts one byte and refuses the rest), so on this runtime the byte-count
	 * check and a `=== false` check are indistinguishable at runtime and only the
	 * source says which contract the code relies on. The already-covered behaviour —
	 * a write that fails degrades — is testAnUnwritableScratchDirectoryFallsBackToTheInstanceBundle.
	 *
	 * Fails if success stops being "the whole payload landed".
	 */
	public function testAWriteIsOnlyTrustedWhenTheWholePayloadLanded(): void {
		// preg_match() rather than assertMatchesRegularExpression(): the subject is a
		// whole source file, and a failure that prints it buries its own message.
		self::assertSame(1, preg_match('/\$written\s*!==\s*strlen\(\$payload\)/', self::source()),
			'the merged bundle is published on something other than the whole payload landing; '
			. 'a truncated CA file would then be cached under a correct name and never rebuilt');
	}

	/**
	 * The scratch file is an implementation detail of publishing atomically, and it
	 * has to stay one: a leftover accumulates one file per rebuild per process in
	 * the instance's temp base dir, forever, under a name nothing ever reads.
	 *
	 * Named exactly, not globbed loosely — `$merged . '.' . getmypid()` is the name
	 * the code builds, and this suite runs in that same process.
	 *
	 * Guards the NEW failure mode, not the old one: measured against the pre-fix
	 * code, which wrote straight to the identity path, this passes — there was no
	 * scratch file to leave behind. What it catches is a lost `unlink()`, which is
	 * the cost publishing atomically introduces. C1d below is the one that fails
	 * when the rename goes away.
	 */
	public function testASuccessfulBuildLeavesNoScratchFileBehind(): void {
		[$bundle] = $this->bundle("-- ANCHORS --\n");

		$merged = $bundle->path();

		self::assertFileExists($merged, 'the merged bundle was not published at all');
		self::assertSame([], glob($merged . '.' . getmypid()) ?: [],
			'the scratch file the merge is written to survived a successful publish');
	}

	/**
	 * The property rename() buys, stated as behaviour: when the publish cannot
	 * complete, the identity path holds NOTHING. It is the half of C1c that runtime
	 * can observe.
	 *
	 * Why it matters is the failure the old code had. Writing straight to the
	 * identity path meant a publish that got part-way left a truncated CA file
	 * there — correct name, correct owner — and the early return at the top of
	 * path() hands that file to every later call without ever rebuilding it. Both
	 * ISP fuentes then fail `cURL error 60` until the identity changes on its own.
	 * Degrading is fine and is asserted here; leaving a corpse at the identity path
	 * is what is not.
	 *
	 * A directory at the identity path is the one way to fail the publish
	 * deterministically as root, which bypasses permission bits — the same reason
	 * testAnUnwritableScratchDirectoryFallsBackToTheInstanceBundle uses a path that
	 * does not exist. `is_file()` is false for a directory, so path() does not take
	 * the early return and goes on to the write, and it is rename() that then fails.
	 *
	 * Measured against the pre-fix code, this passes too: a blocked write degraded
	 * there as well. It is pinning the contract rather than catching the bug that
	 * prompted it — a later change that published eagerly, or moved the degrade
	 * after the write, would fail here. The bug itself needs a PARTIAL write, which
	 * cannot be forced in-process on this runtime; C1d covers that from the source.
	 */
	public function testAPublishThatCannotCompleteLeavesNothingAtTheIdentityPath(): void {
		// One bundle, asked twice: the service holds no state between calls, and the
		// identity is a hash of its inputs, which have not moved. A second instance
		// would NOT do — bundle() writes a fresh anchors file per call, and a
		// different instance-bundle path is a different identity and a different
		// destination, so the obstacle would simply be somewhere else.
		[$bundle, $instanceBundle] = $this->bundle("-- ANCHORS --\n");
		$identity = $bundle->path();
		unlink($identity);
		mkdir($identity);

		self::assertSame($instanceBundle, $bundle->path(), 'a publish that failed did not degrade');
		self::assertFileDoesNotExist($identity . '.' . getmypid(),
			'the scratch file survived a failed publish');
		self::assertSame([], glob($identity . '/*') ?: [],
			'something was published under the identity path despite the failure');

		rmdir($identity);
	}

	/**
	 * C1d: the merge is written beside the identity path and renamed onto it, never
	 * written over it.
	 *
	 * Asserted against the source for the same measured reason as C1c above: the
	 * runtime cost of getting this wrong is a PARTIAL file at the identity path,
	 * and a partial write cannot be forced in-process on PHP 8.5 (a short write is
	 * normalised to `false` before it reaches the caller). The behaviour that IS
	 * observable is asserted in the two tests above; what only the source can say
	 * is that the destination is never the thing being written to.
	 *
	 * Fails if the payload goes straight to $merged again.
	 */
	public function testTheMergeIsRenamedIntoPlaceRatherThanWrittenOverIt(): void {
		$source = self::source();

		self::assertSame(0, preg_match('/file_put_contents\(\s*\$merged\b/', $source),
			'the payload is written directly to the identity path; a publish that got '
			. 'part-way would leave a truncated CA file there and nothing would rebuild it');
		self::assertSame(1, preg_match('/rename\(\s*\$scratch\s*,\s*\$merged\s*\)/', $source),
			'the merge is not renamed onto the identity path, so publishing is not atomic');
	}

	/** The class under test, read as text — see the two tests above for why. */
	private static function source(): string {
		$source = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CertificateBundle.php');
		self::assertNotSame('', $source, 'CertificateBundle.php could not be read, so any assertion on it is vacuous');

		return $source;
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
