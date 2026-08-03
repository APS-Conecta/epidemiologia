<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;

/**
 * The two platform doubles that every test here needs and none of them owns.
 *
 * Extracted after a review counted FOUR byte-identical copies of the `t()` arm
 * and two of the app config. What is NOT in here is just as deliberate: each
 * test builds its own `Sources` with its own repository doubles, because those
 * differ in what they return — payloads, search hits, exceptions. Folding them
 * into one builder would merge things that only look alike, and produce a helper
 * with more parameters than the call sites it replaced.
 */
trait Doubles {
	/**
	 * In-memory app config. Declared here rather than in each test because it is
	 * the double's state, not the test's — the config methods below are the only
	 * things that should read or write it directly.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Translation that actually formats, so an assertion can match the rendered
	 * sentence rather than a template. Carries all three arms; the ones a given
	 * test does not exercise cost nothing.
	 */
	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $parameters === []
				? $text
				: vsprintf($text, (array)$parameters),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, $many),
		);
		// A date is rendered as a marker, not formatted: the format belongs to the
		// platform and to the reader's locale, so asserting it would make a test
		// fail on a language change that broke nothing.
		$l->method('l')->willReturnCallback(
			static fn (string $type, $data, array $options = []): string => $type . ':' . $data->getTimestamp(),
		);

		return $l;
	}

	/**
	 * App config backed by $this->store, so a test can set up persisted state and
	 * read back what the code under test wrote. Both directions matter: ProbeJob
	 * writes the failure counters that SourcesReachable reads, and the pair only
	 * agrees because they go through the same keys.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueInt')->willReturnCallback(
			fn (string $key, int $default = 0): int => (int)($this->store[$key] ?? $default),
		);
		$config->method('setAppValueInt')->willReturnCallback(
			function (string $key, int $value): bool {
				$this->store[$key] = $value;

				return true;
			},
		);
		$config->method('getAppValueArray')->willReturnCallback(
			fn (string $key, array $default = []): array => (array)($this->store[$key] ?? $default),
		);
		$config->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->store[$key]);
		});

		return $config;
	}
}
