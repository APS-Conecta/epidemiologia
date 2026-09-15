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
	use Doubles;

	/** 2026-08-01T12:00:00Z — fixed, so every freshness assertion is relative to it. */
	private const NOW = 1785585600;

	private array $store = [];

	/** @var list<string> request keys, in order — see requestKey() for what a key is */
	private array $requested = [];

	/** @var list<string> request keys that matched no fixture; see fixture() */
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
			'a request matched no fixture — add one to FIXTURES, or pass body:',
		);
	}

	// --- parsing -----------------------------------------------------------

	public function testReadsEveryAlertMinsalPublishes(): void {
		$alerts = $this->repository()->get(MinsalRepository::ALERTS);

		self::assertFalse($alerts['degraded']);
		// 74, not the fixture's 75 anchors: MINSAL links one PDF from two rows of its
		// own table, and the same alert is not two alerts.
		self::assertCount(74, $alerts['items']);
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
	/**
	 * MINSAL's alerts page is a TABLE — `Nombre de la alerta | Fecha | Descarga` — and for
	 * the older half the anchor text is the literal string "Descargar Documento" while the
	 * name sits in the row's first cell. Taking the anchor text as the title put **27 of
	 * 75** identical rows in front of a clinician, 36 % of the pane, since 0.1.0.
	 *
	 * The fixture has carried the defect all along. Nothing asserted that two alerts are
	 * told apart, so nothing saw it — and reading the parser could not, because the other
	 * half of the same page does put the title in the anchor.
	 */
	public function testNoAlertIsTitledWithALinkLabel(): void {
		$titles = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'title');

		self::assertNotContains('Descargar Documento', $titles);
		// The property, not the string: nothing a reader sees may be shared by rows that
		// are otherwise identical to them. MINSAL's table does name two pairs of genuinely
		// different alerts alike; those differ in the month the pane prints beside them.
		$seen = array_map(
			static fn (array $i): string => $i['title'] . '|' . $i['month'],
			$this->repository()->get(MinsalRepository::ALERTS)['items'],
		);

		self::assertSame(array_unique($seen), $seen, 'two rows a reader cannot tell apart');
	}

	/** MINSAL links the same PDF from two rows of its own table. */
	public function testTheSamePdfIsListedOnce(): void {
		$urls = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'url');

		self::assertSame(array_unique($urls), $urls);
	}

	/** And the half that DOES name itself in the anchor keeps its oficio number. */
	public function testAnAlertNamedInItsAnchorKeepsThatName(): void {
		$titles = array_column($this->repository()->get(MinsalRepository::ALERTS)['items'], 'title');

		self::assertNotEmpty(
			array_filter($titles, static fn (string $t): bool => str_starts_with($t, 'OFICIO')),
			'the anchor is the better title when it is one, and carries the oficio number',
		);
	}

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
		self::assertCount(74, $after['items']);
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

	/**
	 * "Returned 0 items" and "was never read" are different faults, and the log said
	 * the first for both.
	 *
	 * `refresh()` computes `$items = $page === null ? [] : parse(...)`, so a fetch that
	 * failed outright arrives at the zero-items guard looking exactly like a page that
	 * parsed to nothing — and the one line an admin diagnoses from claimed MINSAL had
	 * answered with an empty list when MINSAL had not answered at all. Under the WAF
	 * this instance is actually behind (403, 5.481 bytes of HTML, measured 2026-09-15)
	 * that sentence sends the reader to look for a MINSAL that stopped publishing.
	 *
	 * It is the same correction IspRepository already carries, where logging
	 * `count(TERMS)` instead of `$attempted` meant "24 intentos" could mean one.
	 *
	 * Asserted on the LOG and never on the payload: both faults degrade, both serve the
	 * last good copy, and the payload is byte-identical. The sentence is the whole
	 * difference, which is why it is the thing pinned here.
	 */
	public function testAFeedThatCouldNotBeReadIsNotReportedAsOneThatAnsweredEmpty(): void {
		$waf = '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
			. '<body><h1>Access denied</h1></body></html>';

		$alerts = $this->repository(body: $waf)->get(MinsalRepository::ALERTS);

		self::assertTrue($alerts['degraded'], 'a body we cannot read is not an empty list');
		self::assertNotSame([], $this->loggedAt('warning'), 'an unreadable feed must say so');
		self::assertStringNotContainsStringIgnoringCase(
			'0 items',
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
	public function testAPageThatWasReadAndParsedToNothingStillReportsZeroItems(): void {
		// A well-formed WordPress page whose rendered content carries no PDF anchor at
		// all: read successfully, parsed honestly, nothing in it.
		$empty = json_encode([['content' => ['rendered' => '<p>Sin alertas publicadas.</p>'], 'modified' => '']]);

		$alerts = $this->repository(body: (string)$empty)->get(MinsalRepository::ALERTS);

		self::assertTrue($alerts['degraded'], 'zero items never overwrites a good copy');
		self::assertStringContainsString(
			'0 items',
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
	 * @param string|null $body served for every request, instead of the fixtures
	 * @param int|null    $now  later than NOW to push a cached copy past its freshness window
	 */
	private function repository(
		?string $body = null,
		bool $offline = false,
		?int $now = null,
	): MinsalRepository {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $offline): IResponse {
				$key = $this->requestKey($url, $options);
				$this->requested[] = $key;

				if ($offline) {
					throw new RuntimeException('cURL error 6: Could not resolve host');
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

		return new MinsalRepository($clients, $time, $this->logger(), $cacheFactory);
	}

	/**
	 * A logger that records instead of discarding.
	 *
	 * Here because one failure path is only observable as a log line: a body that is
	 * not a WordPress page degrades correctly and silently, so "degraded" is true
	 * whether or not anything was written down. Asserting the payload cannot tell the
	 * two apart, and the difference is the whole of an admin's diagnosis.
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
	 * How a request is identified — the string recorded in $requested, matched by
	 * $missing, and used to pick a fixture.
	 *
	 * MINSAL serves its pages from ONE url and tells them apart by slug, so the
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
