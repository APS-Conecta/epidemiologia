<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

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

	// -----------------------------------------------------------------------

	private function sources(array $items = []): Sources {
		$payload = ['items' => $items, 'updated_at' => null, 'fetched_at' => null, 'degraded' => false];

		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('get')->willReturn($payload);
		$minsal->method('probe')->willReturn(true);
		$minsal->method('search')->willReturn([['title' => 'Una alerta del MINSAL', 'url' => 'https://x/a.pdf']]);

		$isp = $this->createMock(IspRepository::class);
		$isp->method('get')->willReturn($payload);
		$isp->method('probe')->willReturn(true);
		$isp->method('search')->willReturn([['title' => 'Una alerta del ISP', 'url' => 'https://y/b']]);

		return new Sources($minsal, $isp);
	}
}
