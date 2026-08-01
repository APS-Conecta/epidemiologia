<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repository is the app's single test seam: everything with branching logic
 * lives behind it, and the controllers, the search provider and the probe job are
 * thin glue over it.
 *
 * Fixtures are real captured MINSAL responses, not synthetic markup. Synthetic
 * fixtures would only prove the parser can read markup we wrote for it; captured
 * ones prove it reads what MINSAL actually publishes — including the oddities
 * measured on 2026-08-01, which are what several of these tests pin down.
 */
class MinsalRepositoryTest extends TestCase {
	/** 2026-08-01T12:00:00Z — fixed so the year-templated IRAG slug is deterministic. */
	private const NOW = 1785585600;

	private array $store = [];

	/** @var list<string> slugs requested, in order */
	private array $requested = [];

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
	}

	// --- parsing -----------------------------------------------------------

	public function testReadsEveryAlertMinsalPublishes(): void {
		$alerts = $this->repository()->get(MinsalRepository::ALERTS);

		self::assertFalse($alerts['degraded']);
		self::assertCount(75, $alerts['items']);
		self::assertSame('2026-07-31T22:15:04', $alerts['updated_at']);
	}

	public function testEveryAlertCarriesATitleAUrlAndAYear(): void {
		foreach ($this->repository()->get(MinsalRepository::ALERTS)['items'] as $alert) {
			self::assertNotSame('', $alert['title']);
			self::assertStringEndsWith('.pdf', $alert['url']);
			self::assertMatchesRegularExpression('/^20\d{2}$/', $alert['year']);
		}
	}

	/**
	 * 47 of the 75 titles carry no year at all, so a title-derived year would
	 * leave most of the list unfilterable. The upload path is the only field with
	 * full coverage, which is why it is the one we use.
	 */
	public function testDerivesAYearForAlertsWhoseTitleHasNone(): void {
		$items = $this->repository()->get(MinsalRepository::ALERTS)['items'];

		$titleless = array_filter(
			$items,
			static fn (array $a): bool => preg_match('/\b20\d{2}\b/', $a['title']) !== 1,
		);

		self::assertGreaterThan(30, count($titleless), 'the fixture should still contain year-less titles');
		foreach ($titleless as $alert) {
			self::assertNotSame('', $alert['year']);
		}
	}

	public function testUnescapesEntitiesAndCollapsesWhitespaceInTitles(): void {
		foreach ($this->repository()->get(MinsalRepository::ALERTS)['items'] as $alert) {
			self::assertStringNotContainsString('&nbsp;', $alert['title']);
			self::assertStringNotContainsString('&#', $alert['title']);
			self::assertStringNotContainsString('  ', $alert['title']);
			self::assertSame(trim($alert['title']), $alert['title']);
			// U+00A0 is not matched by \s even under /u, so it needs its own pass.
			self::assertStringNotContainsString("\u{00A0}", $alert['title']);
		}
	}

	// --- ordering ----------------------------------------------------------

	/**
	 * MINSAL's own page order is clean for the first ~35 anchors and then carries
	 * 9 inversions. Rendering in document order shows a visibly jumbled list, so
	 * this is the regression that matters most.
	 */
	public function testSortsAlertsNewestFirstAllTheWayDown(): void {
		$years = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'year');

		self::assertSame($years, array_reverse(array_reverse($years)));
		for ($i = 1, $n = count($years); $i < $n; $i++) {
			self::assertGreaterThanOrEqual(
				$years[$i],
				$years[$i - 1],
				"alert $i is newer than the one before it — the sort regressed to document order",
			);
		}
	}

	public function testTheFixtureStillContainsTheInversionsThisSortExistsToFix(): void {
		// Guard against a future re-capture quietly removing the thing under test:
		// if MINSAL ever sorts their own page, this test should fail loudly rather
		// than let testSortsAlertsNewestFirstAllTheWayDown pass for free.
		$page = json_decode((string)file_get_contents(__DIR__ . '/fixtures/alerts.json'), true);
		preg_match_all(
			'#<a[^>]+href="[^"]*/uploads/(\d{4})/(\d{2})/[^"]+\.pdf"#i',
			(string)$page[0]['content']['rendered'],
			$found,
		);
		$document = array_map(
			static fn (string $y, string $m): string => $y . '-' . $m,
			$found[1],
			$found[2],
		);
		self::assertCount(75, $document, 'the fixture should hold the 75 alerts that were measured');

		$inversions = 0;
		for ($i = 1, $n = count($document); $i < $n; $i++) {
			if ($document[$i - 1] < $document[$i]) {
				$inversions++;
			}
		}

		self::assertSame(9, $inversions, 'the captured fixture no longer matches what was measured');
	}

	/**
	 * 75 alerts across 26 months means roughly three share every sort key. Within
	 * a month MINSAL's own curation is the only ordering signal we have, so the
	 * sort must be stable and preserve it.
	 */
	public function testKeepsMinsalsOwnOrderWithinTheSameMonth(): void {
		$items = $this->repository()->get(MinsalRepository::ALERTS)['items'];

		$july = array_values(array_filter($items, static fn (array $a): bool => $a['month'] === '2026-07'));

		self::assertCount(2, $july);
		self::assertStringContainsString('15844', $july[0]['title']);
		self::assertStringContainsString('17264', $july[1]['title']);
	}

	// --- IRAG --------------------------------------------------------------

	public function testPicksTheCurrentSemanaEpidemiologicaFirst(): void {
		$reports = $this->repository()->get(MinsalRepository::IRAG);

		self::assertFalse($reports['degraded']);
		self::assertSame('29', $reports['items'][0]['se']);

		$weeks = array_map('intval', array_column($reports['items'], 'se'));
		self::assertSame($weeks, array_reverse(array_reverse($weeks)));
		for ($i = 1, $n = count($weeks); $i < $n; $i++) {
			self::assertGreaterThanOrEqual($weeks[$i], $weeks[$i - 1]);
		}
	}

	/**
	 * Every January the year-templated slug would 404 until MINSAL puts the new
	 * year up. Falling back one year survives the rollover with no code change.
	 */
	public function testFallsBackOneYearWhenThisYearsIragPageIsNotThereYet(): void {
		$repository = $this->repository(missing: ['-ano-2026']);

		$reports = $repository->get(MinsalRepository::IRAG);

		self::assertFalse($reports['degraded']);
		self::assertNotEmpty($reports['items']);
		self::assertSame(
			[
				'influenza-e-infecciones-respiratorias-agudas-graves-irag-informes-ano-2026',
				'influenza-e-infecciones-respiratorias-agudas-graves-irag-informes-ano-2025',
			],
			$this->requested,
		);
	}

	// --- failure: the rule the whole design rests on -------------------------

	public function testAParseReturningNothingNeverOverwritesTheCache(): void {
		$repository = $this->repository();
		$good = $repository->get(MinsalRepository::ALERTS);
		$cached = $this->store;

		// MINSAL restructures: the page still answers 200, but no PDF anchors left.
		// An hour later, so the cached copy is stale and a real refresh is attempted.
		$broken = $this->repository(
			body: '{"0":{"content":{"rendered":"<p>En mantención</p>"},"modified":"2026-08-02T09:00:00"}}',
			now: self::NOW + 7200,
		);
		$after = $broken->get(MinsalRepository::ALERTS);

		self::assertTrue($after['degraded']);
		self::assertSame($good['items'], $after['items'], 'the last good list must still be served');
		self::assertSame($cached, $this->store, 'the cache must not have been touched');
	}

	public function testAnUnreachableMinsalServesTheLastGoodCopyFlaggedDegraded(): void {
		$good = $this->repository()->get(MinsalRepository::ALERTS);

		$offline = $this->repository(offline: true, now: self::NOW + 7200);
		$after = $offline->get(MinsalRepository::ALERTS);

		self::assertTrue($after['degraded']);
		self::assertCount(75, $after['items']);
		self::assertSame($good['updated_at'], $after['updated_at']);
	}

	public function testWithNothingCachedAndMinsalDownItSaysDegradedRatherThanEmpty(): void {
		$result = $this->repository(offline: true)->get(MinsalRepository::ALERTS);

		self::assertTrue($result['degraded']);
		self::assertSame([], $result['items']);
		self::assertNull($result['updated_at']);
	}

	public function testServesTheCacheWithoutRefetchingWhileItIsFresh(): void {
		$repository = $this->repository();
		$repository->get(MinsalRepository::ALERTS);
		$repository->get(MinsalRepository::ALERTS);

		self::assertCount(1, $this->requested, 'a fresh cache must not be refetched');
	}

	// --- search --------------------------------------------------------------

	public function testFoldsAccentsSoSarampionFindsSarampion(): void {
		$repository = $this->repository();
		$repository->get(MinsalRepository::ALERTS);

		$hits = $repository->search('sarampion');

		self::assertNotEmpty($hits);
		foreach ($hits as $hit) {
			self::assertStringContainsStringIgnoringCase('sarampi', $hit['title']);
		}
		self::assertSame(
			count($hits),
			count($repository->search('SARAMPIÓN')),
			'folding must apply to both sides of the comparison',
		);
	}

	public function testSearchNeverFetchesAndReturnsNothingOnAColdCache(): void {
		$repository = $this->repository();

		self::assertSame([], $repository->search('sarampion'));
		self::assertSame([], $this->requested, 'the global search box must never wait on MINSAL');
	}

	public function testSearchIgnoresAnEmptyTerm(): void {
		$repository = $this->repository();
		$repository->get(MinsalRepository::ALERTS);

		self::assertSame([], $repository->search('   '));
	}

	// --- probe ---------------------------------------------------------------

	public function testTheProbeIsQuietWhenMinsalHasNotChanged(): void {
		$repository = $this->repository();
		$repository->get(MinsalRepository::ALERTS);
		$before = count($this->requested);

		self::assertTrue($repository->probe(MinsalRepository::ALERTS));
		self::assertCount($before + 1, $this->requested, 'an unchanged feed costs exactly one probe');
	}

	public function testTheProbeReportsFailureWhenMinsalStopsParsing(): void {
		$repository = $this->repository(body: '{"0":{"content":{"rendered":"<p>nada</p>"},"modified":"2026-08-02T09:00:00"}}');

		self::assertFalse($repository->probe(MinsalRepository::ALERTS));
	}

	public function testTheProbeReportsFailureWhenMinsalIsUnreachable(): void {
		self::assertFalse($this->repository(offline: true)->probe(MinsalRepository::ALERTS));
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param string|null  $body    served for every slug, instead of the fixtures
	 * @param list<string> $missing slug fragments that should 404
	 * @param int|null     $now     later than NOW to push a cached copy past its freshness window
	 */
	private function repository(
		?string $body = null,
		array $missing = [],
		bool $offline = false,
		?int $now = null,
	): MinsalRepository {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $missing, $offline): IResponse {
				$slug = (string)$options['query']['slug'];
				$this->requested[] = $slug;

				if ($offline) {
					throw new RuntimeException('cURL error 6: Could not resolve host');
				}
				foreach ($missing as $fragment) {
					if (str_contains($slug, $fragment)) {
						throw new RuntimeException('HTTP 404');
					}
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body ?? $this->fixture($slug));

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache());

		return new MinsalRepository($clients, $time, $this->createMock(LoggerInterface::class), $cacheFactory);
	}

	/** An in-memory cache shared across repositories, so "last good copy" is real. */
	private function cache(): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->store[$key] = $value;

			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->store[$key]);

			return true;
		});

		return $cache;
	}

	private function fixture(string $slug): string {
		$name = str_contains($slug, 'irag') ? 'irag' : 'alerts';

		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
	}
}
