<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The fake's own contract, pinned the way the cache double's expiry once
 * wasn't (Doubles.php: "a store that ignored them let a test pass for the
 * wrong reason"): NotFound on the absent file, the write ledger in order,
 * putContent overwriting rather than appending, the folder gate. ~30 lines,
 * because every document test from Slice 4 rests on these four behaviours.
 */
class FakeAppDataTest extends TestCase {
	public function testGetFileThrowsTheRealNotFoundExceptionForAnAbsentName(): void {
		$this->expectException(NotFoundException::class);
		(new FakeAppData())->getFile('informe-2026-35.pdf');
	}

	public function testNewFileWritesAndIsLedgered(): void {
		$data = new FakeAppData();
		$file = $data->newFile('informe-2026-35.pdf', '%PDF-1.4 bytes');

		self::assertSame('%PDF-1.4 bytes', $file->getContent());
		self::assertSame(['informe-2026-35.pdf'], $data->written);
		self::assertTrue($data->fileExists('informe-2026-35.pdf'));
	}

	public function testPutContentOverwritesAndLedgersTheWrite(): void {
		$data = new FakeAppData();
		$file = $data->newFile('informe-2026-35.pdf', 'old bytes');
		$file->putContent('new bytes');

		self::assertSame('new bytes', $data->getFile('informe-2026-35.pdf')->getContent());
		self::assertSame(['informe-2026-35.pdf', 'informe-2026-35.pdf'], $data->written);
	}

	public function testOnlyTheInformesFolderExists(): void {
		$data = new FakeAppData();
		self::assertSame($data, $data->getFolder('informes'));
		$this->expectException(NotFoundException::class);
		$data->getFolder('elsewhere');
	}

	/**
	 * The fresh install, before the first keep: the folder does not exist,
	 * getFolder answers the real IAppData's way (a NotFoundException, which
	 * is what the reader's folder() catches), and newFolder creates it —
	 * exactly once, answering ever after.
	 */
	public function testTheFolderDoesNotExistUntilItIsCreated(): void {
		$data = new FakeAppData();
		$data->withFolder = false;

		try {
			$data->getFolder('informes');
			self::fail('the fresh install has no folder yet — the reader must create it');
		} catch (NotFoundException) {
			// The real IAppData's answer, which is what the reader's folder()
			// catches.
		}

		self::assertSame($data, $data->newFolder('informes'));
		self::assertSame($data, $data->getFolder('informes'), 'created exactly once, answering ever after');
	}
}
