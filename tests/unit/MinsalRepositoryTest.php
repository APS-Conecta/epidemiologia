<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Tests\Unit;

use InvalidArgumentException;
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
 * The fixture is a real captured MINSAL response, not synthetic markup. A
 * synthetic one would only prove the parser can read markup we wrote for it; a
 * captured one proves it reads what MINSAL actually publishes — 11 PDF anchors,
 * all 11 satisfying the parser's own rules, captured 2026-09-15 from the page
 * this fuente moved to after its previous host stopped answering.
 */
class MinsalRepositoryTest extends TestCase {
	use Doubles;

	/** 2026-08-01T12:00:00Z — fixed, so every freshness assertion is relative to it. */
	private const NOW = 1785585600;

	private array $store = [];

	/** @var list<string> requests made against the client, in order */
	private array $requested = [];

	/** @var list<string> request URLs that matched no fixture; see fixture() */
	private array $unmatched = [];

	/** @var list<array{level: string, message: string, context: array}> what the repository logged */
	private array $logged = [];

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
		$this->unmatched = [];
		$this->logged = [];
	}

	/** Runs outside the repository's catch, which is the only place this can bite. */
	protected function assertPostConditions(): void {
		self::assertSame(
			[],
			$this->unmatched,
			'a request matched no fixture — add one, or pass body:',
		);
	}

	// --- parsing -----------------------------------------------------------

	public function testAlertsComeFromThePageAndParseIntoItems(): void {
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html'));

		$result = $repo->get(MinsalRepository::ALERTS);

		self::assertFalse($result['degraded']);
		self::assertNotSame([], $result['items'], 'the fixture must carry alerts, or this test asserts nothing');
		foreach ($result['items'] as $item) {
			self::assertStringStartsWith('https://', $item['url']);
			self::assertNotSame('', $item['title']);
			self::assertMatchesRegularExpression('/^\d{4}$/', $item['year']);
		}
	}

	public function testEveryAlertKeepsItsOwnTitle(): void {
		// The page's own anchor labels are descriptive and unique, so no row may
		// collapse into a shared link label. If MINSAL ever switches to a repeated
		// "Descargar Documento", parse()'s rowTitles() fallback must carry it — and
		// this assertion is what says so out loud.
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html'));

		$titles = array_column($repo->get(MinsalRepository::ALERTS)['items'], 'title');

		self::assertSame(array_unique($titles), $titles);
	}

	public function testReadsEveryAlertMinsalPublishes(): void {
		$alerts = $this->repository()->get(MinsalRepository::ALERTS);

		self::assertFalse($alerts['degraded']);
		self::assertCount(11, $alerts['items']);
	}

	public function testEveryAlertCarriesATitleAUrlAndAYear(): void {
		foreach ($this->repository()->get(MinsalRepository::ALERTS)['items'] as $alert) {
			self::assertNotSame('', $alert['title']);
			self::assertStringEndsWith('.pdf', $alert['url']);
			self::assertMatchesRegularExpression('/^20\d{2}$/', $alert['year']);
		}
	}

	/** MINSAL does not repeat a PDF from two rows on this page, unlike the old one. */
	public function testTheSamePdfIsListedOnce(): void {
		$urls = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'url');

		self::assertSame(array_unique($urls), $urls);
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

	public function testSortsAlertsNewestFirstAllTheWayDown(): void {
		$months = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'month');

		for ($i = 1, $n = count($months); $i < $n; $i++) {
			self::assertGreaterThanOrEqual(
				$months[$i],
				$months[$i - 1],
				"alert $i is newer than the one before it — the sort regressed to document order",
			);
		}
	}

	/**
	 * Guard against a future re-capture quietly removing the thing under test:
	 * the page's own anchor order is not chronological — one 2026-06 alert
	 * (`1781616195625_OFI.pdf`) sits after two 2026-05 alerts in the raw markup
	 * — so if this ever stopped holding, testSortsAlertsNewestFirstAllTheWayDown
	 * would be passing for free rather than exercising sort() at all.
	 */
	public function testTheFixtureStillContainsTheInversionThisSortExistsToFix(): void {
		$html = (string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
		preg_match_all(
			'#<a[^>]+href="[^"]*/uploads/(\d{4})/(\d{2})/[^"]+\.pdf"#i',
			$html,
			$found,
		);
		$document = array_map(
			static fn (string $y, string $m): string => $y . '-' . $m,
			$found[1],
			$found[2],
		);
		self::assertCount(11, $document, 'the fixture should hold the 11 alerts that were measured');

		$inversions = 0;
		for ($i = 1, $n = count($document); $i < $n; $i++) {
			if ($document[$i - 1] < $document[$i]) {
				$inversions++;
			}
		}

		self::assertSame(1, $inversions, 'the captured fixture no longer matches what was measured');
	}

	/**
	 * The page's own June alerts are not chronological in the markup (see the
	 * inversion test above), so this pins that a stable sort keeps them in the
	 * page's own relative order once grouped by month, rather than in whatever
	 * order a comparator with ties happens to leave them.
	 */
	public function testKeepsMinsalsOwnOrderWithinTheSameMonth(): void {
		$items = $this->repository()->get(MinsalRepository::ALERTS)['items'];

		$june = array_values(array_filter($items, static fn (array $a): bool => $a['month'] === '2026-06'));

		self::assertCount(3, $june);
		self::assertStringContainsString('DECRETO-58', $june[0]['url']);
		self::assertStringContainsString('17264_2026', $june[1]['url']);
		self::assertStringContainsString('1781616195625', $june[2]['url']);
	}

	// --- trust boundary: MINSAL's HTML is not ours ---------------------------

	/**
	 * `p()` is htmlspecialchars: it escapes quotes and angle brackets and cannot
	 * make a URL scheme safe. A defaced page serving
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
			'http://degreyd.minsal.cl/uploads/2026/01/f.pdf',      // downgrade
		];
		$anchors = '<a href="https://degreyd.minsal.cl/uploads/2026/01/real.pdf">Alerta real</a>';
		foreach ($hostile as $i => $url) {
			$anchors .= sprintf('<a href="%s">Alerta %d</a>', $url, $i);
		}

		$items = $this->repository(body: $anchors)->get(MinsalRepository::ALERTS)['items'];

		self::assertCount(1, $items, 'only the https anchor may survive');
		self::assertSame('https://degreyd.minsal.cl/uploads/2026/01/real.pdf', $items[0]['url']);
	}

	/** A page of nothing but hostile anchors parses to zero, which is a failure, not a result. */
	public function testAPageOfOnlyHostileUrlsIsTreatedAsAFailedParse(): void {
		$good = $this->repository()->get(MinsalRepository::ALERTS);
		$cached = $this->store;

		$hostile = '<a href="javascript:alert(1)/uploads/2026/01/x.pdf">Alerta</a>';
		$after = $this->repository(body: $hostile, now: self::NOW + 7200)->get(MinsalRepository::ALERTS);

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
		$after = $this->repository(body: '<p>En mantención</p>', now: self::NOW + 7200)
			->get(MinsalRepository::ALERTS);

		self::assertTrue($after['degraded']);
		self::assertSame($good['items'], $after['items'], 'the last good list must still be served');
		self::assertSame($cached['feed-' . MinsalRepository::ALERTS], $this->store['feed-' . MinsalRepository::ALERTS], 'the cache must not have been touched');
	}

	public function testAnUnreachableMinsalServesTheLastGoodCopyFlaggedDegraded(): void {
		$good = $this->repository()->get(MinsalRepository::ALERTS);

		$offline = $this->repository(offline: true, now: self::NOW + 7200);
		$after = $offline->get(MinsalRepository::ALERTS);

		self::assertTrue($after['degraded']);
		self::assertCount(11, $after['items']);
		// CONTEXT.md: "a freno never makes a stale fuente look fresh". This is the first assertion
		// anywhere that a degraded read leaves `fetched_at` where it was — the property that would
		// silently make an outage look like a successful fetch if it ever stopped holding.
		self::assertSame($good['fetched_at'], $after['fetched_at']);
	}

	public function testWithNothingCachedAndMinsalDownItSaysDegradedRatherThanEmpty(): void {
		$result = $this->repository(offline: true)->get(MinsalRepository::ALERTS);

		self::assertTrue($result['degraded']);
		self::assertSame([], $result['items']);
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
	 * degraded banner.
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
	 * `timeout` was the only bound on a response, so an unbounded body risks the
	 * worker's memory in preg_match_all() rather than in a decoder now that there
	 * is no JSON envelope — reproduced on the ISP side, where a 3 MB feed
	 * exhausted 512 MB.
	 */
	public function testAResponseTooBigToBeAPageIsNotRead(): void {
		/*
		 * A page that WOULD parse, padded past the cap. A first version padded with 'x'
		 * alone and passed either way: with no PDF anchors it degraded on the parse, so
		 * the cap was never what the assertion measured.
		 */
		$link = '<a href="https://degreyd.minsal.cl/wp-content/uploads/2026/07/x.pdf">Alerta</a>';
		$huge = $link . str_repeat('<!-- pad -->', 60000);

		$alerts = $this->repository(body: $huge)->get(MinsalRepository::ALERTS);

		self::assertSame([], $alerts['items'], 'a body that size is not a page, whatever it parses to');
		self::assertTrue($alerts['degraded'], 'unread is not the same as empty');
	}

	/**
	 * "Carried no alerts" and "could not be read" are different faults. Since
	 * there is no JSON envelope to fail decoding, the only way to land here is
	 * the client throwing outright — a body that merely lacks PDF anchors is
	 * genuinely read, and genuinely carries nothing, which is the other test
	 * below.
	 *
	 * Asserted on the LOG and never on the payload: both faults degrade, both serve
	 * the last good copy, and the payload is byte-identical. The sentence is the
	 * whole difference, which is why it is the thing pinned here.
	 */
	public function testAPageThatCouldNotBeReadIsNotReportedAsOneThatCarriedNoAlerts(): void {
		$alerts = $this->repository(offline: true)->get(MinsalRepository::ALERTS);

		self::assertTrue($alerts['degraded'], 'an unreachable host is not an empty list');
		self::assertNotSame([], $this->loggedAt('warning'), 'an unreadable page must say so');
		self::assertStringNotContainsStringIgnoringCase(
			'carried no alerts',
			implode(' | ', $this->loggedAt('warning')),
			'MINSAL was never read — reporting it as having returned nothing names the wrong fault',
		);
	}

	/**
	 * The other side of the pair, so the correction above cannot be made by deleting the
	 * distinction: a page that WAS read and genuinely parsed to nothing must still say
	 * so, in those words. This is the fault the never-overwrite rule exists for — MINSAL
	 * restructuring its page into something the parser no longer recognises.
	 */
	public function testAPageThatWasReadAndParsedToNothingStillSaysSo(): void {
		$alerts = $this->repository(body: '<p>Sin alertas publicadas.</p>')->get(MinsalRepository::ALERTS);

		self::assertTrue($alerts['degraded'], 'zero items never overwrites a good copy');
		self::assertStringContainsString(
			'carried no alerts',
			implode(' | ', $this->loggedAt('warning')),
			'a page that was read and parsed to nothing is exactly what this sentence is for',
		);
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

	// --- an unknown feed is a caller's mistake, not a cache entry ------------

	public function testAskingForAnUnknownFeedThrowsRatherThanMintingACacheEntry(): void {
		$repository = $this->repository();

		try {
			$repository->get('bogus');
			self::fail('an id other than ALERTS must not be answered');
		} catch (InvalidArgumentException $e) {
			// expected
		}

		self::assertArrayNotHasKey('feed-bogus', $this->store, 'a rejected feed must not mint a cache entry');
	}

	public function testProbingAnUnknownFeedAlsoThrows(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->repository()->probe('bogus');
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

	/**
	 * degreyd's `modified` field read 2026-06-24 while its table listed alerts
	 * dated 31 July 2026 — the field never tracked the content, which is why
	 * probe() compares a digest of the parsed items instead. This is the test
	 * that would have stayed green through that exact silent failure if probe()
	 * still trusted a publication date.
	 */
	public function testProbeReportsChangeWhenTheContentChangesButTheDateDoesNot(): void {
		$first = (string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
		$second = str_replace('uploads/2026/06/', 'uploads/2026/08/', $first);

		$repo = $this->repository($first);
		$repo->get(MinsalRepository::ALERTS);

		$changed = $this->repository($second, reuseCache: true);
		self::assertTrue($changed->probe(MinsalRepository::ALERTS));
	}

	public function testTheProbeReportsFailureWhenMinsalStopsParsing(): void {
		$repository = $this->repository(body: '<p>nada</p>');

		self::assertFalse($repository->probe(MinsalRepository::ALERTS));
	}

	public function testTheProbeReportsFailureWhenMinsalIsUnreachable(): void {
		self::assertFalse($this->repository(offline: true)->probe(MinsalRepository::ALERTS));
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param string|null $body       served for every request, instead of the fixture
	 * @param int|null    $now        later than NOW to push a cached copy past its freshness window
	 * @param bool        $reuseCache false gives this repository its own empty cache instead of
	 *                                sharing $this->store with earlier repository() calls in the
	 *                                same test — nothing needs that today, but the sharing is the
	 *                                default precisely because most tests rely on it (a repository
	 *                                built later in the same test seeing what an earlier one wrote),
	 *                                so a call site that means to start clean has to say so.
	 */
	private function repository(
		?string $body = null,
		bool $offline = false,
		?int $now = null,
		bool $reuseCache = true,
	): MinsalRepository {
		if (!$reuseCache) {
			$this->store = [];
		}

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $offline): IResponse {
				$this->requested[] = $url;

				if ($offline) {
					throw new RuntimeException('cURL error 6: Could not resolve host');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body ?? $this->fixture($url));

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now ?? self::NOW));

		return new MinsalRepository($clients, $time, $this->logger(), $cacheFactory);
	}

	/**
	 * A logger that records instead of discarding.
	 *
	 * Here because one failure path is only observable as a log line: a body that is
	 * not a page degrades correctly and silently, so "degraded" is true whether or not
	 * anything was written down. Asserting the payload cannot tell the two apart, and
	 * the difference is the whole of an admin's diagnosis.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				},
			);
		}

		return $logger;
	}

	/** Every message logged at $level, for an assertion that names the sentence. */
	private function loggedAt(string $level): array {
		return array_values(array_map(
			static fn (array $l): string => $l['message'],
			array_filter($this->logged, static fn (array $l): bool => $l['level'] === $level),
		));
	}

	/**
	 * The one real captured response this suite is tested against. MINSAL serves
	 * this fuente from exactly one fixed URL now, so there is no slug or category
	 * left to dispatch on the way IspRepositoryTest still has to.
	 *
	 * An unmatched url is recorded and asserted in tearDown, NOT thrown here.
	 * Throwing looks right and is useless: this runs inside the client's get(),
	 * and MinsalRepository::request() catches Throwable — AssertionFailedError
	 * included — logs a warning and returns null. A missing fixture would
	 * therefore read as an unreachable host, go down the degraded path, and let
	 * a test pass green. That is the exact failure this double exists to make
	 * impossible, so the assertion has to happen where production cannot catch it.
	 *
	 * Pass `body:` for a request that is meant to have no fixture.
	 */
	private function fixture(string $url): string {
		if (!str_contains($url, 'degreyd.minsal.cl')) {
			$this->unmatched[] = $url;

			return '';
		}

		return (string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
	}
}
