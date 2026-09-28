<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Search\AlertProvider;
use OCA\Epidemiologia\Service\AlertRepository;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\Sources;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What somebody typing in Nextcloud's global search box gets back.
 *
 * The provider asks the catalogue what is searchable rather than naming one
 * repository — the shape #37 fixed, when sixty alertas sanitarias were invisible
 * here while being rendered in the pane next door, because this class named
 * MinsalRepository in its constructor instead of asking what the app publishes.
 * The alertas sanitarias have since retired (ADR-0016), so today the answer is
 * MINSAL's alertas alone — and the next searchable fuente joins this box with
 * no edit here. The assertions below are on the rendered entries — their title,
 * the subline a reader uses to tell results apart, and the link — never on which
 * collaborator was called.
 */
class AlertProviderTest extends TestCase {
	use Doubles;

	/** @var list<array<string, string>> returned by the MINSAL double */
	private array $minsalHits = [];

	protected function setUp(): void {
		$this->minsalHits = [[
			'title' => 'OFICIO CP N°15844 / 2026 Actualización de la situación de sarampión',
			'url' => 'https://degreyd.minsal.cl/wp-content/uploads/2026/07/oficio.pdf',
			'year' => '2026',
			'month' => '2026-07',
		]];
	}

	public function testASearchFindsAlertasVigentes(): void {
		$titles = array_column($this->search('sarampión'), 'title');

		self::assertContains($this->minsalHits[0]['title'], $titles);
	}

	/**
	 * The kind carries the institution, because a result has to stand alone in a
	 * list mixed with files and contacts — a reader who cannot say who published
	 * what they found has found nothing they can act on. The bug this shape
	 * replaced was the notifier naming the wrong institution; a mislabelled
	 * search result sends the reader to the same wrong page.
	 */
	public function testEachResultSaysWhatKindItIsAndWhoPublishedIt(): void {
		$subline = $this->search('sarampión')[0]['subline'];

		self::assertStringContainsString('MINSAL', $subline);
		self::assertStringNotContainsString('ISP', $subline);
	}

	/**
	 * MINSAL publishes only the upload year — no real day — so the subline takes
	 * what there is rather than assuming a date field every fuente would carry.
	 */
	public function testEachResultCarriesTheDateItsFuentePublishes(): void {
		self::assertStringContainsString('2026', $this->search('sarampión')[0]['subline']);
	}

	public function testEveryResultLinksToTheSourceDocument(): void {
		foreach ($this->search('sarampión') as $entry) {
			self::assertStringStartsWith('https://', $entry['resourceUrl']);
		}
	}

	// --- what must NOT be in there -------------------------------------------

	/**
	 * That the informes are not searched is asserted in SourcesTest
	 * (testSearchLeavesTheInformesOut), where the exclusion is structural:
	 * Sources::search() has no arm for them. Counting calls on a mock here instead
	 * would be near-tautological and is ruled out by ADR-0007.
	 */

	public function testAColdCacheReturnsNothingRatherThanFailing(): void {
		// Nothing cached at all — the state before anyone has opened the app today.
		$this->minsalHits = [];

		self::assertSame([], $this->search('sarampión'));
	}

	/**
	 * A different thing from a cold cache, and the ordinary one: the fuente holds
	 * alerts, and none of them is about this. It must read as "no results", never as
	 * an error in the box every user shares.
	 */
	public function testATermThatMatchesNothingReturnsNothing(): void {
		self::assertSame([], $this->search('hantavirus'));
	}

	public function testTheQueryLimitIsRespected(): void {
		self::assertCount(1, $this->search('sarampión', limit: 1));
	}

	/**
	 * Every registered provider runs on every global search by every user, so one
	 * that fetched would put an outbound request in front of somebody looking for
	 * a Word document. The repositories here throw on any read that is not the
	 * cache-only search.
	 */
	public function testTheProviderNeverFetches(): void {
		$minsal = $this->createMock(AlertRepository::class);
		$minsal->method('search')->willReturnCallback($this->matching($this->minsalHits));
		// Every way in that can reach the network. `refresh()` is no longer among them:
		// it is private, so `get()` and `probe()` are the whole surface a provider could
		// trip over — which is the point of it being private. The informes repository is
		// armed the same way and carries no search() at all, so the provider cannot reach
		// the ISP's informe feed even by asking for it.
		$informes = $this->createMock(IspReportRepository::class);
		foreach ([$minsal, $informes] as $repository) {
			$repository->method('get')->willThrowException(new RuntimeException('a search provider must not fetch'));
			$repository->method('probe')->willThrowException(new RuntimeException('a search provider must not fetch'));
		}

		$result = $this->provider(new Sources($this->alertRepositories($minsal), $informes))->search($this->user(), $this->query('sarampión'));

		self::assertCount(1, $result->jsonSerialize()['entries']);
	}

	// -----------------------------------------------------------------------

	/** @return list<array<string, mixed>> the rendered entries */
	private function search(string $term, int $limit = 20): array {
		$minsal = $this->createMock(AlertRepository::class);
		$minsal->method('search')->willReturnCallback($this->matching($this->minsalHits));

		$result = $this->provider(new Sources($this->alertRepositories($minsal), $this->createMock(IspReportRepository::class)))
			->search($this->user(), $this->query($term, $limit));

		// SearchResult hands back SearchResultEntry objects; each serialises itself.
		// Going through that is deliberate: it is the shape the client receives, so
		// asserting on it cannot pass while what a user sees is wrong.
		return array_map(
			static fn (\JsonSerializable $entry): array => $entry->jsonSerialize(),
			$result->jsonSerialize()['entries'],
		);
	}

	/**
	 * A double that actually honours the term. Returning fixtures regardless would
	 * make "a search that matches nothing" and "a cold cache" indistinguishable —
	 * two different things a reader can hit, and only one of them is normal.
	 *
	 * @param list<array<string, string>> $hits
	 */
	private function matching(array $hits): callable {
		return static fn (string $term): array => array_values(array_filter(
			$hits,
			static fn (array $hit): bool => str_contains(
				mb_strtolower($hit['title'], 'UTF-8'),
				mb_strtolower($term, 'UTF-8'),
			),
		));
	}

	private function provider(Sources $sources): AlertProvider {
		return new AlertProvider($sources, $this->l10n());
	}

	private function query(string $term, int $limit = 20): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);

		return $query;
	}

	private function user(): IUser {
		return $this->createMock(IUser::class);
	}
}
