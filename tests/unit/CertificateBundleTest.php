<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\CertificateBundle;
use OCP\ICertificateManager;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

class CertificateBundleTest extends TestCase {
	/** @return array{CertificateBundle, string} the bundle and the fake instance anchor file */
	private function bundle(string $anchors, ?string $scratch = null): array {
		$instanceBundle = tempnam(sys_get_temp_dir(), 'anchors');
		file_put_contents($instanceBundle, $anchors);

		$manager = $this->createMock(ICertificateManager::class);
		$manager->method('getAbsoluteBundlePath')->willReturn($instanceBundle);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($scratch ?? sys_get_temp_dir());

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

	public function testAnUnwritableScratchDirectoryFallsBackToTheInstanceBundle(): void {
		// The machine-agnostic case that matters: a read-only or full scratch dir
		// must produce today's behaviour, not a fatal inside a page render.
		[$bundle, $instanceBundle] = $this->bundle("-- ANCHORS --\n", '/nonexistent-scratch-dir');

		self::assertSame($instanceBundle, $bundle->path());
	}
}
