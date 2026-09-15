<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\IspRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCA\Epidemiologia\Service\Sources;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue exists so that a source cannot be half-wired. These tests are what
 * make that true: every one of them walks Sources::ALL rather than naming the three
 * sources, so adding a fourth to the list and forgetting one of the match arms
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
	 * The institution is inside the phrase, not appended by the caller. The bug this
	 * replaces was a caller appending "desde MINSAL" to whatever it was handed.
	 */
	public function testEachNameCarriesItsOwnInstitution(): void {
		$sources = $this->sources();
		$l = $this->l10n();

		self::assertStringContainsString('MINSAL', $sources->name(Sources::ALERTS, $l));
		self::assertStringContainsString('MINSAL', $sources->name(Sources::REPORTS, $l));
		self::assertStringContainsString('ISP', $sources->name(Sources::SANITARY_ALERTS, $l));
		self::assertStringNotContainsString('MINSAL', $sources->name(Sources::SANITARY_ALERTS, $l));
	}

	public function testAllReturnsEverySourceInTheDeclaredOrder(): void {
		self::assertSame(Sources::ALL, array_keys($this->sources()->all()));
	}

	/**
	 * A hit has to carry where it came from, or the caller cannot label it — and
	 * CONTEXT.md is explicit that an alerta sanitaria and an alerta vigente are
	 * different objects that are never merged.
	 */
	public function testSearchCarriesTheSourceWithEachHit(): void {
		$hits = $this->sources()->search('sarampión');

		self::assertSame(
			[Sources::ALERTS, Sources::SANITARY_ALERTS],
			array_column($hits, 'source'),
		);
		self::assertSame('Una alerta del MINSAL', $hits[0]['item']['title']);
		self::assertSame('Una alerta del ISP', $hits[1]['item']['title']);
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

	// --- narrowing (#51) ----------------------------------------------------

	public function testPageWithoutNarrowingIsTheWholeSource(): void {
		$page = $this->sources(items: $this->threeAlerts())->page(Sources::SANITARY_ALERTS);

		self::assertCount(3, $page['items']);
	}

	public function testPageKeepsOnlyTheAskedCategory(): void {
		$page = $this->sources(items: $this->threeAlerts())
			->page(Sources::SANITARY_ALERTS, category: 'anamed');

		self::assertSame(['a', 'c'], array_column($page['items'], 'title'));
	}

	/** Strictly older, so paging on the last `at` seen cannot repeat that item. */
	public function testPageKeepsOnlyWhatIsStrictlyOlderThanTheCursor(): void {
		$page = $this->sources(items: $this->threeAlerts())
			->page(Sources::SANITARY_ALERTS, olderThan: 200);

		self::assertSame(['c'], array_column($page['items'], 'title'));
	}

	public function testPageLeavesTheEnvelopeAlone(): void {
		$whole = $this->sources(items: $this->threeAlerts())->get(Sources::SANITARY_ALERTS);
		$page = $this->sources(items: $this->threeAlerts())->page(Sources::SANITARY_ALERTS);

		self::assertSame(array_keys($whole), array_keys($page), 'a narrowed answer is the same shape');
	}

	/**
	 * MINSAL items carry `year`/`month`; they have no `categories` and no `at`. The
	 * filter read those two keys off every source, so narrowing a MINSAL fuente
	 * returned a confidently wrong answer rather than refusing: `category` matched
	 * nothing at all, and `olderThan` matched everything.
	 */
	public function testNarrowingAFuenteThatCannotBeNarrowedIsRefused(): void {
		$sources = $this->sources(items: [['title' => 'un oficio', 'url' => 'https://x/a.pdf', 'year' => '2026']]);

		self::assertNull($sources->page(Sources::ALERTS, category: 'anamed'));
		self::assertNull($sources->page(Sources::ALERTS, olderThan: 999));
	}

	/**
	 * The guard was judged on `items[0]`, so it disappeared exactly when the source had
	 * nothing to judge. During a MINSAL outage the same unanswerable narrowing that
	 * gets 400 on a good day came back **200 with an empty list** — a wrong answer that
	 * looks right, which is what this method's docblock says it exists to refuse.
	 *
	 * Judged on the fuente, not on a sample of its contents.
	 */
	public function testNarrowingIsRefusedEvenWhileTheFuenteIsEmpty(): void {
		$sources = $this->sources(items: []);

		self::assertNull($sources->page(Sources::ALERTS, category: 'anamed'));
		self::assertNull($sources->page(Sources::ALERTS, olderThan: 999));
	}

	/** And the ISP, which CAN be narrowed, still answers while empty. */
	public function testAnEmptyIspStillAcceptsANarrowing(): void {
		$sources = $this->sources(items: []);

		self::assertSame([], $sources->page(Sources::SANITARY_ALERTS, category: 'anamed')['items']);
	}

	/**
	 * Measured on the live route before this: all three answered **200 with an empty
	 * list**, which is the confident-empty the 400 exists to refuse.
	 */
	public function testACategoryTheFuenteDoesNotPublishIsRefused(): void {
		$sources = $this->sources(items: $this->threeAlerts());

		self::assertNull($sources->page(Sources::SANITARY_ALERTS, category: 'no-such-category'));
		self::assertNotNull(
			$sources->page(Sources::SANITARY_ALERTS, category: 'anamed'),
			'and one it does publish is still answerable',
		);
	}

	/** A cursor is an instant; `?olderThan=abc` reaches PHP as 0 and matched nothing. */
	public function testACursorThatIsNotAnInstantIsRefused(): void {
		$sources = $this->sources(items: $this->threeAlerts());

		self::assertNull($sources->page(Sources::SANITARY_ALERTS, olderThan: 0));
		self::assertNull($sources->page(Sources::SANITARY_ALERTS, olderThan: -1));
	}

	/**
	 * The cap was invisible. `?source=sanitary_alerts` returned 100 items where no
	 * parameters at all returned 273 — naming what you want got you less than not
	 * naming it — and nothing in the envelope distinguished a page from the whole set.
	 */
	public function testANarrowedResponseSaysHowMuchItLeftOut(): void {
		$many = array_map(
			static fn (int $i): array => [
				'title' => "alerta $i",
				'url' => "https://x/$i",
				'categories' => ['anamed'],
				'at' => 1785000000 - $i,
				'date' => '2026-08-01',
			],
			range(1, 130),
		);

		$page = $this->sources(items: $many)->page(Sources::SANITARY_ALERTS);

		self::assertCount(100, $page['items']);
		self::assertSame(130, $page['total'], 'the caller has to be able to tell 100 from all of it');
		self::assertTrue($page['has_more']);
	}

	public function testAResponseThatFitsSaysThereIsNoMore(): void {
		$page = $this->sources(items: $this->threeAlerts())->page(Sources::SANITARY_ALERTS);

		self::assertSame(3, $page['total']);
		self::assertFalse($page['has_more']);
	}

	/** Asking for nothing in particular is always answerable. */
	public function testAFuenteThatCannotBeNarrowedStillAnswersUnnarrowed(): void {
		$sources = $this->sources(items: [['title' => 'un oficio', 'url' => 'https://x/a.pdf', 'year' => '2026']]);

		self::assertCount(1, $sources->page(Sources::ALERTS)['items']);
	}

	/** @return list<array<string, mixed>> newest first, as the repository hands them over */
	private function threeAlerts(): array {
		return [
			['title' => 'a', 'url' => 'https://x/a', 'at' => 300, 'categories' => ['anamed']],
			['title' => 'b', 'url' => 'https://x/b', 'at' => 200, 'categories' => ['robo-hurto']],
			['title' => 'c', 'url' => 'https://x/c', 'at' => 100, 'categories' => ['anamed']],
		];
	}

	/**
	 * The published contract must NAME every key the route can return.
	 *
	 * `total` and `has_more` were added to page() so a capped response could be told from a
	 * complete one — and the docblock the OpenAPI extractor reads was not updated with them, so
	 * openapi.json has been describing a narrower envelope than the route returns. A consumer
	 * reading the spec cannot discover that paging exists at all, which is the defect those two
	 * keys were added to fix, one level up.
	 *
	 * Against `properties` and not `required`: the route answers in two shapes. Omitting
	 * `?source` returns every source whole, with no page to report on, so the two paging keys
	 * are genuinely optional and asserting them as required would be asserting something false.
	 *
	 * Read from the COMMITTED spec, not from the docblock: comparing the docblock to the spec it
	 * generates would pass by construction.
	 */
	public function testThePublishedContractNamesEveryKeyTheRouteCanReturn(): void {
		$sources = $this->sources($this->threeAlerts());
		$returned = array_unique(array_merge(
			array_keys((array)$sources->page(Sources::SANITARY_ALERTS)),
			array_keys($sources->all()[Sources::SANITARY_ALERTS]),
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

		$isp = $this->createMock(IspRepository::class);
		$isp->method('get')->willReturn($payload);
		$isp->method('probe')->willReturn(true);
		$isp->method('search')->willReturn([['title' => 'Una alerta del ISP', 'url' => 'https://y/b']]);

		// No search arm: the informes are not searchable, and the repository that owns
		// them has no search() at all — which is the structural half of what
		// testSearchLeavesTheInformesOut asserts.
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('get')->willReturn($payload);
		$informes->method('probe')->willReturn(true);

		return new Sources($minsal, $isp, $informes);
	}
}
