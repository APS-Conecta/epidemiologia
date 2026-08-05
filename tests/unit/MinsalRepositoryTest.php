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

	/** @var list<string> request keys, in order — see requestKey() for what a key is */
	private array $requested = [];

	/** @var list<string> request keys that matched no fixture; see fixture() */
	private array $unmatched = [];

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
		$this->unmatched = [];
	}

	/** Runs outside the repository's catch, which is the only place this can bite. */
	protected function assertPostConditions(): void {
		self::assertSame(
			[],
			$this->unmatched,
			'a request matched no fixture — add one to FIXTURES, or pass body:',
		);
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

	// --- trust boundary: MINSAL's HTML is not ours ---------------------------

	/**
	 * `p()` is htmlspecialchars: it escapes quotes and angle brackets and cannot
	 * make a URL scheme safe. A defaced epi.minsal.cl serving
	 * href="javascript:…/uploads/2026/01/x.pdf" satisfies the anchor pattern and
	 * the upload-folder pattern, and would otherwise reach href unchanged — in the
	 * page, in the API other apps read, and in core's search results.
	 */
	public function testRejectsEveryUrlThatIsNotHttps(): void {
		$hostile = [
			'javascript:alert(document.domain)/uploads/2026/01/a.pdf',
			'JavaScript:alert(1)/uploads/2026/01/b.pdf',           // schemes are case-insensitive
			'data:text/html;base64,PHNjcmlwdD4=/uploads/2026/01/c.pdf',
			'vbscript:msgbox(1)/uploads/2026/01/d.pdf',
			' javascript:alert(1)/uploads/2026/01/e.pdf',          // leading space
			'http://epi.minsal.cl/uploads/2026/01/f.pdf',          // downgrade
		];
		$anchors = '<a href="https://epi.minsal.cl/uploads/2026/01/real.pdf">Alerta real</a>';
		foreach ($hostile as $i => $url) {
			$anchors .= sprintf('<a href="%s">Alerta %d</a>', $url, $i);
		}

		$items = $this->repository(
			body: json_encode([['content' => ['rendered' => $anchors], 'modified' => '2026-08-02T09:00:00']]),
		)->get(MinsalRepository::ALERTS)['items'];

		self::assertCount(1, $items, 'only the https anchor may survive');
		self::assertSame('https://epi.minsal.cl/uploads/2026/01/real.pdf', $items[0]['url']);
	}

	/** A page of nothing but hostile anchors parses to zero, which is a failure, not a result. */
	public function testAPageOfOnlyHostileUrlsIsTreatedAsAFailedParse(): void {
		$good = $this->repository()->get(MinsalRepository::ALERTS);
		$cached = $this->store;

		$hostile = '<a href="javascript:alert(1)/uploads/2026/01/x.pdf">Alerta</a>';
		$after = $this->repository(
			body: json_encode([['content' => ['rendered' => $hostile], 'modified' => '2026-08-02T09:00:00']]),
			now: self::NOW + 7200,
		)->get(MinsalRepository::ALERTS);

		self::assertTrue($after['degraded']);
		self::assertSame($good['items'], $after['items'], 'the last good list must still be served');
		// The feed's own key, not the whole store: degrading also arms the cooldown that
		// stops the outage being retried on every read, which is a second key.
		self::assertSame($cached['feed-' . MinsalRepository::ALERTS], $this->store['feed-' . MinsalRepository::ALERTS], 'the cache must not have been touched');
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
		// The feed's own key, not the whole store: degrading also arms the cooldown that
		// stops the outage being retried on every read, which is a second key.
		self::assertSame($cached['feed-' . MinsalRepository::ALERTS], $this->store['feed-' . MinsalRepository::ALERTS], 'the cache must not have been touched');
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

	/**
	 * Degrading writes nothing, so the cache stays expired and every subsequent read
	 * retries. PageController::index() fetches synchronously while someone waits, so
	 * against a host that blackholes packets this is a gateway timeout rather than the
	 * degraded banner. The ISP half carries the same rule, per feed.
	 */
	public function testAnOutageIsNotRetriedOnEveryRead(): void {
		$this->repository()->get(MinsalRepository::ALERTS);
		$this->requested = [];

		$this->repository(offline: true, now: self::NOW + 7200)->get(MinsalRepository::ALERTS);
		self::assertNotSame([], $this->requested, 'precondition: the first read after expiry does try');
		$this->requested = [];

		$this->repository(offline: true, now: self::NOW + 7260)->get(MinsalRepository::ALERTS);

		self::assertSame([], $this->requested, 'the outage was retried instead of being remembered');
	}

	/**
	 * The other half, and the one the old double could not express: the cooldown has to
	 * END. Only "it was set" was ever asserted, so recovery being automatic rested on a
	 * TTL nothing checked — raising COOLDOWN to a week would have kept the suite green and
	 * pinned both feeds to degraded until someone flushed Redis.
	 */
	/**
	 * `timeout` was the only bound on a response, so a decoder on an unbounded body
	 * fatals the worker inside a page render — reproduced on the ISP side, where a 3 MB
	 * feed exhausted 512 MB.
	 */
	public function testAResponseTooBigToBeAPageIsNotRead(): void {
		/*
		 * A page that WOULD parse, padded past the cap. A first version padded with 'x'
		 * alone and passed either way: with no PDF anchors it degraded on the parse, so
		 * the cap was never what the assertion measured.
		 */
		$link = '<a href="https://epi.minsal.cl/wp-content/uploads/2026/07/x.pdf">Alerta</a>';
		$huge = json_encode([['content' => ['rendered' => $link . str_repeat('<!-- pad -->', 60000)]]]);

		$alerts = $this->repository(body: $huge)->get(MinsalRepository::ALERTS);

		self::assertSame([], $alerts['items'], 'a body that size is not a page, whatever it parses to');
		self::assertTrue($alerts['degraded'], 'unread is not the same as empty');
	}

	public function testTheCooldownExpiresAndTheFeedIsTriedAgain(): void {
		$this->repository()->get(MinsalRepository::ALERTS);
		$this->repository(offline: true, now: self::NOW + 7200)->get(MinsalRepository::ALERTS);
		$this->requested = [];

		$this->repository(offline: true, now: self::NOW + 7200 + 301)->get(MinsalRepository::ALERTS);

		self::assertNotSame([], $this->requested, 'past COOLDOWN the feed must be asked again');
	}

	public function testTheCooldownStillServesTheLastGoodCopyAndStillSaysDegraded(): void {
		$good = $this->repository()->get(MinsalRepository::ALERTS);
		$this->repository(offline: true, now: self::NOW + 7200)->get(MinsalRepository::ALERTS);

		$during = $this->repository(offline: true, now: self::NOW + 7260)->get(MinsalRepository::ALERTS);

		self::assertTrue($during['degraded'], 'a cooldown must never look like health');
		self::assertSame($good['items'], $during['items']);
	}

	/** One feed's outage must not silence another's fetch. */
	public function testTheCooldownIsPerFeedNotGlobal(): void {
		$this->repository()->get(MinsalRepository::ALERTS);
		$this->repository(missing: ['alertas-epidemiologicas-vigentes'], now: self::NOW + 7200)
			->get(MinsalRepository::ALERTS);
		$this->requested = [];

		$this->repository(now: self::NOW + 7260)->get(MinsalRepository::IRAG);

		self::assertNotSame([], $this->requested, 'the IRAG feed was silenced by the alerts outage');
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

	/**
	 * The informes are not searched, asserted where the exclusion actually lives.
	 *
	 * An informe is identified by its semana epidemiológica, so matching one by
	 * subject happens only by coincidence — and a clínico/a searching for a disease
	 * does not want a weekly PDF that merely shares a word.
	 *
	 * Both fuentes are loaded first, so the cache genuinely holds the informes and
	 * this cannot pass by their simply being absent. "SE 29" appears in one informe
	 * title and in no alert title, which is what makes it a probe rather than a
	 * coincidence.
	 */
	public function testTheInformesAreNotSearchable(): void {
		$repository = $this->repository();
		$repository->get(MinsalRepository::ALERTS);
		$reports = $repository->get(MinsalRepository::IRAG);

		self::assertNotEmpty($reports['items'], 'the informes must be cached, or this asserts nothing');
		self::assertSame([], $repository->search('SE 29'));
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
	 * @param string|null  $body    served for every request, instead of the fixtures
	 * @param list<string> $missing request-key fragments that should 404
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
				$key = $this->requestKey($url, $options);
				$this->requested[] = $key;

				if ($offline) {
					throw new RuntimeException('cURL error 6: Could not resolve host');
				}
				foreach ($missing as $fragment) {
					if (str_contains($key, $fragment)) {
						throw new RuntimeException('HTTP 404');
					}
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body ?? $this->fixture($key));

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now ?? self::NOW));

		return new MinsalRepository($clients, $time, $this->createMock(LoggerInterface::class), $cacheFactory);
	}

	/**
	 * An in-memory cache shared across repositories, so "last good copy" is real.
	 *
	 * TTLs are honoured. A store that ignored them proved COOLDOWN and KEEP_FOR were SET
	 * and never that they EXPIRE — raise COOLDOWN to a week and every test still passed,
	 * while production would pin both feeds to degraded until someone flushed Redis. The
	 * ISP suite learned this and wrote down why; this is the same double.
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
	 * How a request is identified — the string recorded in $requested, matched by
	 * $missing, and used to pick a fixture.
	 *
	 * MINSAL serves both feeds from ONE url and tells them apart by slug, so the
	 * slug is the request's identity there; every assertion on $requested is
	 * written in slugs. Any other source is identified by its url.
	 *
	 * This used to read $options['query']['slug'] unconditionally. A source that
	 * sends no slug — an RSS feed, say — would raise on the missing key and then
	 * fall through to MINSAL's fixture, so its test would pass while asserting
	 * MINSAL's alerts. Dispatching on the url first is what makes a second source
	 * a new row rather than a rewrite.
	 */
	private function requestKey(string $url, array $options): string {
		if (str_contains($url, 'epi.minsal.cl')) {
			return (string)($options['query']['slug'] ?? $url);
		}

		return $url;
	}

	/**
	 * Which capture answers a request key: the first fragment that matches wins,
	 * so adding a source is adding a row. Captures are real upstream responses —
	 * synthetic markup would only prove the parser can read markup we wrote for it.
	 */
	private const FIXTURES = [
		'irag' => 'irag',
		'alertas-epidemiologicas-vigentes' => 'alerts',
	];

	/**
	 * An unmatched key is recorded and asserted in tearDown, NOT thrown here.
	 *
	 * Throwing looks right and is useless: this runs inside the client's get(),
	 * and MinsalRepository::request() catches Throwable — AssertionFailedError
	 * included — logs "no se pudo contactar" and returns null. A missing fixture
	 * would therefore read as an unreachable host, go down the degraded path, and
	 * let a test pass green. That is the exact failure this double exists to make
	 * impossible, so the assertion has to happen where production cannot catch it.
	 *
	 * Pass `body:` for a request that is meant to have no fixture.
	 */
	private function fixture(string $key): string {
		foreach (self::FIXTURES as $fragment => $name) {
			if (str_contains($key, (string)$fragment)) {
				return (string)file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
			}
		}

		$this->unmatched[] = $key;

		return '';
	}
}
