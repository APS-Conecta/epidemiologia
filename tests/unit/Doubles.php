<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\AlertRepositories;
use OCA\Epidemiologia\Service\AlertRepository;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\ICache;
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
	 * Named `$config`, not `$store`: the repository suites hold a CACHE in `$store`,
	 * and a trait cannot share a property name with the class that uses it.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Shared, because it was byte-identical in both repository suites and the rule it
	 * encodes is subtle: entries carry an ABSOLUTE expiry, since `$now` is per-repository
	 * and a test builds several. The MINSAL copy's own docblock said "this is the same
	 * double"; two copies of a rule this easy to get wrong is one too many.
	 *
	 * Honours TTL and remove(), because the code under test relies on both: the outage
	 * cooldown and the freno are expressed AS expiry, and a success clears a freno by
	 * removing its key. A store that ignored them let a test pass for the wrong reason
	 * — testAnsweringClearsTheFreno went green against a freno that was never cleared,
	 * only outlived. Entries carry an absolute expiry, since $now is per-repository.
	 */
	private function cache(int $now): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use ($now) {
			$e = $this->store[$key] ?? null;
			if ($e === null) {
				return null;
			}
			if ($e['exp'] !== null && $now >= $e['exp']) {
				unset($this->store[$key]);

				return null;
			}

			return $e['v'];
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value, $ttl = 0) use ($now): bool {
			$this->store[$key] = ['v' => $value, 'exp' => $ttl > 0 ? $now + $ttl : null];

			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->store[$key]);

			return true;
		});

		return $cache;
	}

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
	 * App config backed by $this->config, so a test can set up persisted state and
	 * read back what the code under test wrote. Both directions matter: ProbeJob
	 * writes the failure counters that SourcesReachable reads, and the pair only
	 * agrees because they go through the same keys.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueInt')->willReturnCallback(
			fn (string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$config->method('setAppValueInt')->willReturnCallback(
			function (string $key, int $value): bool {
				$this->config[$key] = $value;

				return true;
			},
		);
		$config->method('getAppValueArray')->willReturnCallback(
			fn (string $key, array $default = []): array => (array)($this->config[$key] ?? $default),
		);
		$config->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->config[$key]);
		});

		return $config;
	}

	/**
	 * The factory double for the app-data fake — the repositories' constructor
	 * type (OCP\Files\AppData\IAppDataFactory). The fake is passed in, not
	 * minted here, because the suite's assertions live on the same instance
	 * the repository holds: never-refetch is a claim about ONE store's ledger.
	 */
	private function appDataFactory(FakeAppData $data): IAppDataFactory {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($data);

		return $factory;
	}

	/**
	 * The factory double Sources is built with since ADR-0017: every alert id
	 * resolves to $repository. Passed in, not minted here, for
	 * appDataFactory()'s reason — what the repository double answers is the
	 * test's to say; this is only the door Sources reaches it through.
	 */
	private function alertRepositories(AlertRepository $repository): AlertRepositories {
		$factory = $this->createMock(AlertRepositories::class);
		$factory->method('repository')->willReturn($repository);

		return $factory;
	}
}
