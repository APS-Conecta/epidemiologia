<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\AlertRepositories;
use OCA\Epidemiologia\Service\AlertRepository;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\Sources;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue exists so that a source cannot be half-wired. These tests are what
 * make that true: every one of them walks Sources::ALL rather than naming the
 * fuentes, so adding a third to the list and forgetting one of the match arms
 * fails here instead of in production — which is exactly how the ISP shipped
 * counted by the probe and invisible to the setup check, the notifier and search.
 */
class SourcesTest extends TestCase {
	use Doubles;

	/** @var list<string> every door the ledgered() pipelines were asked through, as "door id", in order */
	private array $asked = [];

	protected function setUp(): void {
		$this->asked = [];
	}

	public function testEveryIdResolvesToAPayload(): void {
		$sources = $this->sources();

		foreach (Sources::ALL as $id) {
			// No default match arm, so a missing arm throws UnhandledMatchError here.
			self::assertArrayHasKey('items', $sources->get($id), $id);
			self::assertArrayHasKey('degraded', $sources->get($id), $id);
		}
	}

	public function testEveryIdResolvesToBothPhrasings(): void {
		$sources = $this->sources();
		$l = $this->l10n();

		foreach (Sources::ALL as $id) {
			self::assertNotSame('', $sources->name($id, $l), $id);
			self::assertNotSame('', $sources->kind($id, $l), $id);
		}
	}

	public function testEveryIdCanBeProbed(): void {
		$sources = $this->sources();

		foreach (Sources::ALL as $id) {
			self::assertTrue($sources->probe($id), $id);
		}
	}

	/**
	 * The catalogue's doors are walked, never named — revalidate joins get,
	 * probe, name, kind and search in being pinned over ALL (the ISP-shipped-
	 * invisible lesson; see the class docblock).
	 */
	public function testEveryIdCanBeRevalidated(): void {
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('revalidate')->willReturnCallback(function (): void {
			$this->asked[] = 'revalidate ' . Sources::REPORTS;
		});

		$sources = new Sources($this->ledgered(), $informes);
		foreach (Sources::ALL as $id) {
			$sources->revalidate($id);
		}

		self::assertSame(
			array_map(static fn (string $id): string => "revalidate $id", Sources::ALL),
			$this->asked,
			'revalidate walks the catalogue like every other door, each id to its own pipeline',
		);
	}

	/**
	 * Each alert door reaches the pipeline of the id it was asked for. The
	 * shared double answers every id with one repository, so it cannot see a
	 * copied arm: `self::EPI => $this->alerts->repository(self::ALERTS)`
	 * serves degreyd's alerts under epi's name and passes every walk above.
	 * With two alert fuentes on one factory (ADR-0017), that arm is one
	 * paste away in each of the four methods that take an id.
	 */
	public function testEachAlertIdReachesItsOwnPipeline(): void {
		$sources = new Sources($this->ledgered(), $this->createMock(IspReportRepository::class));
		$alerts = array_values(array_diff(Sources::ALL, [Sources::REPORTS]));

		foreach ($alerts as $id) {
			$sources->get($id);
			$sources->probe($id);
		}
		$sources->search('sarampión');

		self::assertSame(
			[
				...array_merge(...array_map(static fn (string $id): array => ["get $id", "probe $id"], $alerts)),
				...array_map(static fn (string $id): string => "search $id", $alerts),
			],
			$this->asked,
		);
	}

	/**
	 * The catalogue's door to the deep pass (L3-08), walked like every other
	 * door: each accumulating id reaches its own owner's converge() — the
	 * informe's walk and archiver, or its stream's backfill (ADR-0017 D7).
	 * ACCUMULATING is a subset of ALL, or the job would converge a fuente
	 * nothing serves.
	 */
	public function testConvergeWalksEveryAccumulatingFuenteToItsOwnDeepPass(): void {
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('converge')->willReturnCallback(function (): void {
			$this->asked[] = 'converge ' . Sources::REPORTS;
		});

		$sources = new Sources($this->ledgered(), $informes);
		foreach (Sources::ACCUMULATING as $id) {
			$sources->converge($id);
		}

		self::assertSame([], array_diff(Sources::ACCUMULATING, Sources::ALL));
		self::assertSame(
			array_map(static fn (string $id): string => "converge $id", Sources::ACCUMULATING),
			$this->asked,
		);
	}

	/**
	 * An in-force set replaces itself whole inside get(), so it has no deep
	 * pass — and asking for one is a wiring mistake that must throw, not a
	 * pass that quietly does nothing: an in-force id added to ACCUMULATING
	 * breaks here.
	 */
	public function testAnInForceFuenteHasNoDeepPass(): void {
		$sources = $this->sources();
		$inForce = array_values(array_diff(Sources::ALL, Sources::ACCUMULATING));
		$refused = [];

		foreach ($inForce as $id) {
			try {
				$sources->converge($id);
			} catch (\UnhandledMatchError) {
				$refused[] = $id;
			}
		}

		self::assertNotSame([], $inForce, 'a catalogue with no in-force fuente would pass this for free');
		self::assertSame($inForce, $refused, 'no arm, no pass — the catalogue\'s loudness');
	}

	/**
	 * The institution is inside the phrase, not appended by the caller. The bug this
	 * replaces was a caller appending "desde MINSAL" to whatever it was handed.
	 */
	public function testEachNameCarriesItsOwnInstitution(): void {
		$sources = $this->sources();
		$l = $this->l10n();

		self::assertStringContainsString('MINSAL', $sources->name(Sources::ALERTS, $l));
		foreach ([Sources::REPORTS, Sources::ISP_ANAMED, Sources::ISP_PHARMACOVIGILANCE, Sources::ISP_COSMETICS] as $isp) {
			self::assertStringContainsString('ISP', $sources->name($isp, $l), $isp);
			self::assertStringNotContainsString('MINSAL', $sources->name($isp, $l), $isp);
		}
	}

	public function testAllReturnsEverySourceInTheDeclaredOrder(): void {
		self::assertSame(Sources::ALL, array_keys($this->sources()->all()));
	}

	/**
	 * A hit has to carry where it came from, or the caller cannot label it — and
	 * CONTEXT.md keeps alerta sanitaria and alerta vigente as different objects
	 * that are never merged. Every alert fuente is searched (ADR-0017), the
	 * double answers the same hit for each, and each hit carries the id it came
	 * from: the label is what lets a caller say which institution published
	 * what a reader found.
	 */
	public function testSearchCarriesTheSourceWithEachHit(): void {
		$hits = $this->sources()->search('sarampión');

		self::assertSame(
			array_values(array_diff(Sources::ALL, [Sources::REPORTS])),
			array_column($hits, 'source'),
			'every alert fuente is searched, in the catalogue\'s order',
		);
		self::assertSame('Una alerta del MINSAL', $hits[0]['item']['title']);
	}

	/**
	 * Informes are not searched. One is a weekly PDF identified by its semana
	 * epidemiológica, so a subject search would only ever match it by accident.
	 */
	public function testSearchLeavesTheInformesOut(): void {
		self::assertNotContains(
			Sources::REPORTS,
			array_column($this->sources()->search('sarampión'), 'source'),
		);
	}

	/**
	 * The published contract must NAME every key the route can return.
	 *
	 * `total` and `has_more` were added to the envelope so a capped response could
	 * be told from a complete one — and the docblock the OpenAPI extractor reads
	 * was not updated with them, so openapi.json has been describing a narrower
	 * envelope than the route returns. A consumer that cannot tell "that is all"
	 * from "that is a page" is the confident-empty defect again, one level up.
	 *
	 * Against `properties` and not `required`: the docblock types the two keys as
	 * optional (`total?`/`has_more?`), so the spec lists them as non-required
	 * properties — asserting them as required would be asserting something the
	 * published type does not promise.
	 *
	 * Read from the COMMITTED spec, not from the docblock: comparing the docblock
	 * to the spec it generates would pass by construction.
	 */
	public function testThePublishedContractNamesEveryKeyTheRouteCanReturn(): void {
		$sources = $this->sources([['title' => 'un oficio', 'url' => 'https://x/a.pdf', 'year' => '2026']]);
		$returned = array_unique(array_merge(
			array_keys((array)$sources->get(Sources::ALERTS)),
			array_keys($sources->all()[Sources::ALERTS]),
		));
		sort($returned);

		$spec = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/openapi.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$declared = array_keys($spec['paths']['/ocs/v2.php/apps/epidemiologia/api/v1/sources']['get']
			['responses']['200']['content']['application/json']['schema']
			['properties']['ocs']['properties']['data']['additionalProperties']['properties']);
		sort($declared);

		self::assertSame($returned, $declared, sprintf(
			'openapi.json names [%s] but the route can return [%s] — fix the @return on '
			. 'ApiController::sources() and regenerate with scripts/openapi.sh',
			implode(', ', $declared),
			implode(', ', $returned),
		));
	}

	// -----------------------------------------------------------------------

	private function sources(array $items = []): Sources {
		$payload = ['items' => $items, 'fetched_at' => null, 'degraded' => false];

		$minsal = $this->createMock(AlertRepository::class);
		$minsal->method('get')->willReturn($payload);
		$minsal->method('probe')->willReturn(true);
		$minsal->method('search')->willReturn([['title' => 'Una alerta del MINSAL', 'url' => 'https://x/a.pdf']]);

		// No search arm: the informes are not searchable, and the repository that owns
		// them has no search() at all — which is the structural half of what
		// testSearchLeavesTheInformesOut asserts.
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('get')->willReturn($payload);
		$informes->method('probe')->willReturn(true);

		return new Sources($this->alertRepositories($minsal), $informes);
	}

	/**
	 * A factory double that hands each id a pipeline of its own, and ledgers
	 * every door asked through it as "door id" in $this->asked — the double
	 * that can tell one fuente's pipeline from another's, which the shared
	 * alertRepositories() cannot.
	 */
	private function ledgered(): AlertRepositories {
		$factory = $this->createMock(AlertRepositories::class);
		$factory->method('repository')->willReturnCallback(function (string $id): AlertRepository {
			$repository = $this->createMock(AlertRepository::class);
			$repository->method('get')->willReturnCallback(function () use ($id): array {
				$this->asked[] = "get $id";

				return ['items' => [], 'fetched_at' => null, 'degraded' => false];
			});
			$repository->method('probe')->willReturnCallback(function () use ($id): bool {
				$this->asked[] = "probe $id";

				return true;
			});
			$repository->method('revalidate')->willReturnCallback(function () use ($id): void {
				$this->asked[] = "revalidate $id";
			});
			$repository->method('search')->willReturnCallback(function () use ($id): array {
				$this->asked[] = "search $id";

				return [];
			});
			$repository->method('converge')->willReturnCallback(function () use ($id): void {
				$this->asked[] = "converge $id";
			});

			return $repository;
		});

		return $factory;
	}
}
