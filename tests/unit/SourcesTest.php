<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
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
		$revalidated = [];
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('revalidate')->willReturnCallback(function () use (&$revalidated): void {
			$revalidated[] = Sources::ALERTS;
		});
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('revalidate')->willReturnCallback(function () use (&$revalidated): void {
			$revalidated[] = Sources::REPORTS;
		});

		$sources = new Sources($minsal, $informes);
		foreach (Sources::ALL as $id) {
			$sources->revalidate($id);
		}

		self::assertSame(Sources::ALL, $revalidated, 'revalidate walks the catalogue like every other door');
	}

	/**
	 * The catalogue's door to the deep pass (L3-08) — the file's own walk
	 * doctrine applied to the one method that takes no id: converge() is
	 * direct (only REPORTS accumulates a back catalogue; see the method),
	 * and after it runs every id in ALL still answers — the deep pass must
	 * not disturb the read path's answers.
	 */
	public function testConvergeRunsAndLeavesEveryFuenteAnswering(): void {
		$passes = 0;
		$payload = ['items' => [], 'fetched_at' => null, 'degraded' => false];
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('get')->willReturn($payload);
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('get')->willReturn($payload);
		$informes->method('converge')->willReturnCallback(function () use (&$passes): void {
			$passes++;
		});

		$sources = new Sources($minsal, $informes);
		$sources->converge();

		self::assertSame(1, $passes, 'the accumulating fuente\'s deep pass ran through the catalogue\'s door');
		foreach (Sources::ALL as $id) {
			self::assertArrayHasKey('items', $sources->get($id), $id);
			self::assertArrayHasKey('degraded', $sources->get($id), $id);
		}
	}

	/**
	 * The institution is inside the phrase, not appended by the caller. The bug this
	 * replaces was a caller appending "desde MINSAL" to whatever it was handed.
	 */
	public function testEachNameCarriesItsOwnInstitution(): void {
		$sources = $this->sources();
		$l = $this->l10n();

		self::assertStringContainsString('MINSAL', $sources->name(Sources::ALERTS, $l));
		self::assertStringContainsString('ISP', $sources->name(Sources::REPORTS, $l));
		self::assertStringNotContainsString('MINSAL', $sources->name(Sources::REPORTS, $l));
	}

	public function testAllReturnsEverySourceInTheDeclaredOrder(): void {
		self::assertSame(Sources::ALL, array_keys($this->sources()->all()));
	}

	/**
	 * A hit has to carry where it came from, or the caller cannot label it — and
	 * CONTEXT.md keeps alerta sanitaria and alerta vigente as different objects
	 * that are never merged. With the alertas sanitarias retired (ADR-0016) there
	 * is one searchable fuente; the property has to hold anyway, because the label
	 * is what lets a caller say which institution published what a reader found.
	 */
	public function testSearchCarriesTheSourceWithEachHit(): void {
		$hits = $this->sources()->search('sarampión');

		self::assertSame(
			[Sources::ALERTS],
			array_column($hits, 'source'),
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

		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('get')->willReturn($payload);
		$minsal->method('probe')->willReturn(true);
		$minsal->method('search')->willReturn([['title' => 'Una alerta del MINSAL', 'url' => 'https://x/a.pdf']]);

		// No search arm: the informes are not searchable, and the repository that owns
		// them has no search() at all — which is the structural half of what
		// testSearchLeavesTheInformesOut asserts.
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('get')->willReturn($payload);
		$informes->method('probe')->willReturn(true);

		return new Sources($minsal, $informes);
	}
}
