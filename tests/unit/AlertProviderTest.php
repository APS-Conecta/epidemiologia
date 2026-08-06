<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Search\AlertProvider;
use OCA\Epidemiologia\Service\IspRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCA\Epidemiologia\Service\Sources;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What somebody typing in Nextcloud's global search box gets back.
 *
 * Until #37 this provider named ONE repository in its constructor, so sixty
 * alertas sanitarias were invisible here while being rendered in the pane beside
 * it. The assertions below are on the rendered entries — their title, the subline
 * a reader uses to tell two kinds of alert apart, and the link — never on which
 * collaborator was called.
 */
class AlertProviderTest extends TestCase {
	use Doubles;

	/**
	 * A term both fuentes really do carry: MINSAL numbers its oficios "N°15844",
	 * the ISP numbers its recalls "N° 22/26". Needed because the doubles honour the
	 * term, so a test wanting one result of each kind has to ask for something that
	 * genuinely matches both rather than assuming every search returns everything.
	 */
	private const IN_BOTH = 'N°';

	/** @var list<array<string, string>> returned by the MINSAL double */
	private array $minsalHits = [];

	/** @var list<array<string, string>> returned by the ISP double */
	private array $ispHits = [];

	protected function setUp(): void {
		$this->minsalHits = [[
			'title' => 'OFICIO CP N°15844 / 2026 Actualización de la situación de sarampión',
			'url' => 'https://epi.minsal.cl/wp-content/uploads/2026/07/oficio.pdf',
			'year' => '2026',
			'month' => '2026-07',
		]];
		$this->ispHits = [[
			'title' => 'ALERTA DE RETIRO DEL MERCADO N° 22/26',
			'url' => 'https://www.ispch.gob.cl/alerta/22-26/',
			'date' => '2026-07-28',
			'at' => '1785196800',
		]];
	}

	// --- both kinds of alert are findable ------------------------------------

	public function testASearchFindsAlertasVigentes(): void {
		$titles = array_column($this->search('sarampión'), 'title');

		self::assertContains($this->minsalHits[0]['title'], $titles);
	}

	/**
	 * The defect this file exists for: sixty alertas sanitarias reachable in the
	 * pane and unreachable from the box every user actually types into.
	 */
	public function testASearchAlsoFindsAlertasSanitariasIsp(): void {
		$titles = array_column($this->search('retiro'), 'title');

		self::assertContains($this->ispHits[0]['title'], $titles);
	}

	// --- and stay telling apart, which CONTEXT.md requires -------------------

	/**
	 * An alerta sanitaria and an alerta vigente are different objects that are
	 * never merged. Once they share one result list, the subline is the only thing
	 * keeping them apart — a product recall read as an epidemiological oficio is a
	 * clinical misunderstanding, not a cosmetic one.
	 */
	public function testEachResultSaysWhatKindItIsAndWhoPublishedIt(): void {
		// Looked up by title, not by position: no story specifies an order between
		// fuentes, so a test that pins one would fail on a change nobody asked about.
		$entries = $this->search(self::IN_BOTH);
		$minsal = $this->entryStartingWith($entries, 'OFICIO');
		$isp = $this->entryStartingWith($entries, 'ALERTA DE RETIRO');

		self::assertStringContainsString('MINSAL', $minsal['subline']);
		self::assertStringNotContainsString('ISP', $minsal['subline']);
		self::assertStringContainsString('ISP', $isp['subline']);
		self::assertStringNotContainsString('MINSAL', $isp['subline']);
	}

	/**
	 * The two fuentes publish different date fields — the ISP a real day, MINSAL
	 * only the upload year — so the subline takes whichever exists rather than
	 * assuming one of them.
	 */
	public function testEachResultCarriesTheDateItsFuentePublishes(): void {
		$entries = $this->search(self::IN_BOTH);

		self::assertStringContainsString(
			'2026',
			$this->entryStartingWith($entries, 'OFICIO')['subline'],
			'MINSAL publishes only the upload year',
		);
		self::assertStringContainsString(
			'2026-07-28',
			$this->entryStartingWith($entries, 'ALERTA DE RETIRO')['subline'],
			'the ISP publishes a real day',
		);
	}

	public function testEveryResultLinksToTheSourceDocument(): void {
		foreach ($this->search(self::IN_BOTH) as $entry) {
			self::assertStringStartsWith('https://', $entry['resourceUrl']);
		}
	}

	// --- what must NOT be in there -------------------------------------------

	/**
	 * That the informes are not searched is asserted in MinsalRepositoryTest, where
	 * the exclusion is implemented — it loads both fuentes and shows a term unique
	 * to an informe finding nothing. Asserting it here instead would have meant
	 * counting calls on a mock, which ADR-0007 rules out and which would have been
	 * near-tautological anyway: Sources::search() has no arm for the informes, so a
	 * regression would throw rather than call twice.
	 */

	public function testAColdCacheReturnsNothingRatherThanFailing(): void {
		// Nothing cached at all — the state before anyone has opened the app today.
		$this->minsalHits = [];
		$this->ispHits = [];

		self::assertSame([], $this->search('sarampión'));
	}

	/**
	 * A different thing from a cold cache, and the ordinary one: both fuentes hold
	 * alerts, and none of them is about this. It must read as "no results", never as
	 * an error in the box every user shares.
	 */
	public function testATermThatMatchesNothingReturnsNothing(): void {
		self::assertSame([], $this->search('hantavirus'));
	}

	public function testTheQueryLimitIsRespected(): void {
		self::assertCount(1, $this->search(self::IN_BOTH, limit: 1));
	}

	/**
	 * Every registered provider runs on every global search by every user, so one
	 * that fetched would put an outbound request in front of somebody looking for a
	 * Word document. The repositories here throw on any read that is not the
	 * cache-only search.
	 */
	public function testTheProviderNeverFetches(): void {
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('search')->willReturnCallback($this->matching($this->minsalHits));
		$isp = $this->createMock(IspRepository::class);
		$isp->method('search')->willReturnCallback($this->matching($this->ispHits));
		// Every way in that can reach the network. `refresh()` is no longer among them:
		// it is private, so `get()` and `probe()` are the whole surface a provider could
		// trip over — which is the point of it being private.
		foreach ([$minsal, $isp] as $repository) {
			$repository->method('get')->willThrowException(new RuntimeException('a search provider must not fetch'));
			$repository->method('probe')->willThrowException(new RuntimeException('a search provider must not fetch'));
		}

		$result = $this->provider(new Sources($minsal, $isp))->search($this->user(), $this->query(self::IN_BOTH));

		self::assertCount(2, $result->jsonSerialize()['entries']);
	}

	// -----------------------------------------------------------------------

	/** @return list<array<string, mixed>> the rendered entries */
	private function search(string $term, int $limit = 20): array {
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('search')->willReturnCallback($this->matching($this->minsalHits));
		$isp = $this->createMock(IspRepository::class);
		$isp->method('search')->willReturnCallback($this->matching($this->ispHits));

		$result = $this->provider(new Sources($minsal, $isp))->search($this->user(), $this->query($term, $limit));

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

	/** The entry whose title starts with $prefix, so no test depends on result order. */
	private function entryStartingWith(array $entries, string $prefix): array {
		foreach ($entries as $entry) {
			if (str_starts_with($entry['title'], $prefix)) {
				return $entry;
			}
		}
		self::fail('no result whose title starts with ' . $prefix);
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
