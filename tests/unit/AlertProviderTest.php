<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Search\AlertProvider;
use OCA\Epidemiologia\Service\IspRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCA\Epidemiologia\Service\Sources;
use OCP\IL10N;
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
		$sublines = array_column($this->search('alerta'), 'subline');

		self::assertCount(2, $sublines);
		self::assertStringContainsString('MINSAL', $sublines[0]);
		self::assertStringContainsString('ISP', $sublines[1]);
		self::assertStringNotContainsString('ISP', $sublines[0]);
		self::assertStringNotContainsString('MINSAL', $sublines[1]);
	}

	/**
	 * The two fuentes publish different date fields — the ISP a real day, MINSAL
	 * only the upload year — so the subline takes whichever exists rather than
	 * assuming one of them.
	 */
	public function testEachResultCarriesTheDateItsFuentePublishes(): void {
		$sublines = array_column($this->search('alerta'), 'subline');

		self::assertStringContainsString('2026', $sublines[0], 'MINSAL carries a year');
		self::assertStringContainsString('2026-07-28', $sublines[1], 'the ISP carries a day');
	}

	public function testEveryResultLinksToTheSourceDocument(): void {
		foreach ($this->search('alerta') as $entry) {
			self::assertStringStartsWith('https://', $entry['resourceUrl']);
		}
	}

	// --- what must NOT be in there -------------------------------------------

	/**
	 * Informes are identified by their semana epidemiológica, so a subject search
	 * matches one only by coincidence. Asserted rather than left true by accident.
	 */
	public function testTheInformesAreNotSearched(): void {
		$minsal = $this->createMock(MinsalRepository::class);
		// Sources::search() asks MinsalRepository once, for the alertas. A second
		// call would mean the informes had been folded in.
		$minsal->expects(self::once())->method('search')->willReturn($this->minsalHits);
		$isp = $this->createMock(IspRepository::class);
		$isp->method('search')->willReturn([]);

		$this->provider(new Sources($minsal, $isp))->search($this->user(), $this->query('alerta'));
	}

	public function testAColdCacheReturnsNothingRatherThanFailing(): void {
		$this->minsalHits = [];
		$this->ispHits = [];

		self::assertSame([], $this->search('sarampión'));
	}

	public function testTheQueryLimitIsRespected(): void {
		self::assertCount(1, $this->search('alerta', limit: 1));
	}

	/**
	 * Every registered provider runs on every global search by every user, so one
	 * that fetched would put an outbound request in front of somebody looking for a
	 * Word document. The repositories here throw on any read that is not the
	 * cache-only search.
	 */
	public function testTheProviderNeverFetches(): void {
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('search')->willReturn($this->minsalHits);
		$isp = $this->createMock(IspRepository::class);
		$isp->method('search')->willReturn($this->ispHits);
		foreach ([$minsal, $isp] as $repository) {
			$repository->method('get')->willThrowException(new RuntimeException('a search provider must not fetch'));
			$repository->method('refresh')->willThrowException(new RuntimeException('a search provider must not fetch'));
		}

		$result = $this->provider(new Sources($minsal, $isp))->search($this->user(), $this->query('alerta'));

		self::assertCount(2, $result->jsonSerialize()['entries']);
	}

	// -----------------------------------------------------------------------

	/** @return list<array<string, mixed>> the rendered entries */
	private function search(string $term, int $limit = 20): array {
		$minsal = $this->createMock(MinsalRepository::class);
		$minsal->method('search')->willReturn($this->minsalHits);
		$isp = $this->createMock(IspRepository::class);
		$isp->method('search')->willReturn($this->ispHits);

		$result = $this->provider(new Sources($minsal, $isp))->search($this->user(), $this->query($term, $limit));

		// SearchResult hands back SearchResultEntry objects; each serialises itself.
		// Going through that is deliberate: it is the shape the client receives, so
		// asserting on it cannot pass while what a user sees is wrong.
		return array_map(
			static fn (\JsonSerializable $entry): array => $entry->jsonSerialize(),
			$result->jsonSerialize()['entries'],
		);
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

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $parameters === []
				? $text
				: vsprintf($text, (array)$parameters),
		);

		return $l;
	}
}
