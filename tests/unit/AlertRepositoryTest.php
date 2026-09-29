<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\RevalidateJob;
use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OCA\Epidemiologia\Service\AlertRepositories;
use OCA\Epidemiologia\Service\AlertRepository;
use OCA\Epidemiologia\Service\CertificateBundle;
use OCA\Epidemiologia\Service\Parser\FeedParser;
use OCA\Epidemiologia\Service\Parser\PageParser;
use OCA\Epidemiologia\Service\Parser\PahoParser;
use OCA\Epidemiologia\Service\Probe;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The repository is the app's single test seam: everything with branching logic
 * lives behind it, and the controllers, the search provider and the probe job are
 * thin glue over it.
 *
 * The fixture is a real captured MINSAL response, not synthetic markup. A
 * synthetic one would only prove the parser can read markup we wrote for it; a
 * captured one proves it reads what MINSAL actually publishes — 13 PDF anchors,
 * all 13 satisfying the parser's own rules, captured 2026-09-15 from the page
 * this fuente moved to after its previous host stopped answering. Two of the
 * 13 pad their href with a trailing space inside the quotes and were silently
 * dropped until round 2 of review caught it (see
 * testTheEbolaAlertsAreNotSilentlyDropped) — a real fixture earns its keep
 * exactly here, by carrying a defect nobody would have thought to write.
 *
 * The alert pipeline's suite, read through degreyd (ADR-0017). It was
 * MinsalRepositoryTest while degreyd had a class of its own; the pipeline is
 * built here the way Sources asks for it — through AlertRepositories — so every
 * test below also pins degreyd's configuration: its address (fixture() answers
 * degreyd.minsal.cl, and epi.minsal.cl only for epi's own tests), its keys and
 * its sentences.
 */
class AlertRepositoryTest extends TestCase {
	use Doubles;

	/** 2026-08-01T12:00:00Z — fixed, so every freshness assertion is relative to it. */
	private const NOW = 1785585600;

	private array $store = [];

	/** The persisted set: the AlertMapper double's table — Alert entities, like the real one holds. */
	private array $rows = [];

	/** @var list<string> the fuente every readSet()/replaceSet() was scoped to, in call order */
	private array $scopes = [];

	/** @var list<array> every request's options, in order — the UA pin reads the first */
	private array $options = [];

	/** @var list<string> requests made against the client, in order */
	private array $requested = [];

	/** @var list<array{0: string, 1: mixed}> every IJobList::add() call, in order */
	private array $jobs = [];

	/** @var list<string> request URLs that matched no fixture; see fixture() */
	private array $unmatched = [];

	/** @var list<array{level: string, message: string, context: array}> what the repository logged */
	private array $logged = [];

	protected function setUp(): void {
		$this->store = [];
		$this->rows = [];
		$this->scopes = [];
		$this->options = [];
		$this->requested = [];
		$this->jobs = [];
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

		$result = $repo->get();

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

		$titles = array_column($repo->get()['items'], 'title');

		self::assertSame(array_unique($titles), $titles);
	}

	public function testReadsEveryAlertMinsalPublishes(): void {
		$alerts = $this->repository()->get();

		self::assertFalse($alerts['degraded']);
		self::assertCount(13, $alerts['items']);
	}

	/**
	 * Two of the page's 13 anchors pad their href with a trailing space inside
	 * the quotes — `href="…OFICIO_CP_N_11926_2026_ebola.pdf "` — which the old
	 * `[^"]+\.pdf"` pattern required to sit immediately before the closing
	 * quote, so these two never entered $matches at all. Both are real,
	 * in-force alerts about the same Bundibugyo/ébola outbreak, and the
	 * class's own $rejected counter could not see them either: it counts URLs
	 * the regex captured and then rejected, and these were never captured.
	 * "Dropped N non-https links" would have stayed silent while the fuente
	 * quietly showed 11 of 13 — the exact failure class's own comment on
	 * $rejected says this app exists to avoid.
	 */
	public function testTheEbolaAlertsAreNotSilentlyDropped(): void {
		// By filename, not the full url: both PDFs are still hosted on the
		// previous host (MINSAL moved the index page, not every file it links
		// to), and this file otherwise carries zero references to it.
		$urls = array_column($this->repository()->get()['items'], 'url');

		self::assertNotSame(
			[],
			array_filter($urls, static fn (string $u): bool => str_ends_with($u, 'ebola.pdf')),
			'the ébola alert (OFICIO_CP_N_11926_2026) must not be dropped',
		);
		self::assertNotSame(
			[],
			array_filter($urls, static fn (string $u): bool => str_ends_with($u, 'Virus_Bundibugyo.pdf')),
			'the Bundibugyo alert (OFICIO_CP_N_12712_2026) must not be dropped',
		);
	}

	public function testEveryAlertCarriesATitleAUrlAndAYear(): void {
		foreach ($this->repository()->get()['items'] as $alert) {
			self::assertNotSame('', $alert['title']);
			self::assertStringEndsWith('.pdf', $alert['url']);
			self::assertMatchesRegularExpression('/^20\d{2}$/', $alert['year']);
		}
	}

	/** MINSAL does not repeat a PDF from two rows on this page, unlike the old one. */
	public function testTheSamePdfIsListedOnce(): void {
		$urls = array_column($this->repository()->get()['items'], 'url');

		self::assertSame(array_unique($urls), $urls);
	}

	public function testUnescapesEntitiesAndCollapsesWhitespaceInTitles(): void {
		foreach ($this->repository()->get()['items'] as $alert) {
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
		$months = array_column($this->repository()->get()['items'], 'month');

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
		// `\s*` before the closing quote, same as parse()'s own pattern and for
		// the same reason: two of the 13 anchors pad their href with a trailing
		// space, and a stricter pattern here would undercount independently of
		// whatever parse() does, silently making this pin worth less than it claims.
		preg_match_all(
			'#<a[^>]+href="[^"]*/uploads/(\d{4})/(\d{2})/[^"]+\.pdf\s*"#i',
			$html,
			$found,
		);
		$document = array_map(
			static fn (string $y, string $m): string => $y . '-' . $m,
			$found[1],
			$found[2],
		);
		self::assertCount(13, $document, 'the fixture should hold the 13 alerts that were measured');

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
		$items = $this->repository()->get()['items'];

		$june = array_values(array_filter($items, static fn (array $a): bool => $a['month'] === '2026-06'));

		self::assertCount(4, $june);
		self::assertStringContainsString('DECRETO-58', $june[0]['url']);
		self::assertStringContainsString('17264_2026', $june[1]['url']);
		self::assertStringContainsString('1781616195625', $june[2]['url']);
		self::assertStringContainsString('Virus_Bundibugyo', $june[3]['url']);
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

		$items = $this->repository(body: $anchors)->get()['items'];

		self::assertCount(1, $items, 'only the https anchor may survive');
		self::assertSame('https://degreyd.minsal.cl/uploads/2026/01/real.pdf', $items[0]['url']);
	}

	/** A page of nothing but hostile anchors parses to zero, which is a failure, not a result. */
	public function testAPageOfOnlyHostileUrlsIsTreatedAsAFailedParse(): void {
		$good = $this->repository()->get();
		$cached = $this->store;

		$hostile = '<a href="javascript:alert(1)/uploads/2026/01/x.pdf">Alerta</a>';
		$this->repository(body: $hostile, now: self::NOW + 7200)->revalidate();
		$after = $this->repository(body: $hostile, now: self::NOW + 7260)->get();

		self::assertTrue($after['degraded']);
		self::assertSame($good['items'], $after['items'], 'the last good list must still be served');
		// The feed's own key, not the whole store: degrading also arms the cooldown that
		// stops the outage being retried on every read, which is a second key.
		self::assertSame($cached['feed-alerts'], $this->store['feed-alerts'], 'the cache must not have been touched');
	}

	// --- failure: the rule the whole design rests on -------------------------

	public function testAParseReturningNothingNeverOverwritesTheCache(): void {
		$repository = $this->repository();
		$good = $repository->get();
		$cached = $this->store;

		// MINSAL restructures: the page still answers 200, but no PDF anchors left.
		// An hour later, the revalidation runs behind the response and the
		// restructure degrades without ever touching the cache.
		$this->repository(body: '<p>En mantención</p>', now: self::NOW + 7200)->revalidate();
		self::assertSame($cached['feed-alerts'], $this->store['feed-alerts'], 'the cache must not have been touched');

		$after = $this->repository(body: '<p>En mantención</p>', now: self::NOW + 7260)->get();

		self::assertTrue($after['degraded']);
		self::assertSame($good['items'], $after['items'], 'the last good list must still be served');
		self::assertSame($cached['feed-alerts'], $this->store['feed-alerts'], 'the cache must not have been touched');
	}

	public function testAnUnreachableMinsalServesTheLastGoodCopyFlaggedDegraded(): void {
		$good = $this->repository()->get();

		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();
		$after = $this->repository(offline: true, now: self::NOW + 7260)->get();

		self::assertTrue($after['degraded']);
		self::assertCount(13, $after['items']);
		// CONTEXT.md: "a freno never makes a stale fuente look fresh". This is the first assertion
		// anywhere that a degraded read leaves `fetched_at` where it was — the property that would
		// silently make an outage look like a successful fetch if it ever stopped holding.
		self::assertSame($good['fetched_at'], $after['fetched_at']);
	}

	public function testWithNothingCachedAndMinsalDownItSaysDegradedRatherThanEmpty(): void {
		$result = $this->repository(offline: true)->get();

		self::assertTrue($result['degraded']);
		self::assertSame([], $result['items']);
	}

	public function testServesTheCacheWithoutRefetchingWhileItIsFresh(): void {
		$repository = $this->repository();
		$repository->get();
		$repository->get();

		self::assertCount(1, $this->requested, 'a fresh cache must not be refetched');
	}

	/**
	 * Degrading writes nothing, so the cache stays expired and every subsequent read
	 * retries. PageController::index() fetches synchronously while someone waits, so
	 * against a host that blackholes packets this is a gateway timeout rather than the
	 * degraded banner.
	 */
	public function testAnOutageIsNotRetriedOnEveryRead(): void {
		$this->repository()->get();
		$this->requested = [];
		$this->jobs = [];

		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();
		self::assertNotSame([], $this->requested, 'precondition: the revalidation after expiry does try');
		$this->requested = [];

		$this->repository(offline: true, now: self::NOW + 7260)->get();

		self::assertSame([], $this->requested, 'the outage was retried instead of being remembered');
		self::assertSame([], $this->jobs, 'a cooldown-gated read dispatches nothing');
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

		$alerts = $this->repository(body: $huge)->get();

		self::assertSame([], $alerts['items'], 'a body that size is not a page, whatever it parses to');
		self::assertTrue($alerts['degraded'], 'unread is not the same as empty');
	}

	/**
	 * The ébola anchors above are ONE shape of anchor the regex in parse() can
	 * miss; this seeds a DIFFERENT one — a `.pdf` href sitting in a `<link>`
	 * tag rather than inside a well-formed `<a …>…</a>` — to prove the cheap
	 * upstream count actually fires on a shape it has never seen, not only on
	 * the one bug it was written to explain. A warning nobody has watched fire
	 * is not a warning; this is what watches it.
	 */
	public function testWarnsWhenAnAnchorIsLostToTheRegex(): void {
		$good = '<a href="https://degreyd.minsal.cl/uploads/2026/01/good.pdf">Alerta buena</a>';
		// Not inside <a>…</a>, so the richer anchor pattern cannot match it — but
		// it is still an href ending in .pdf, which the cheap count does see.
		$ghost = '<link rel="alternate" href="https://degreyd.minsal.cl/uploads/2026/01/ghost.pdf">';

		$alerts = $this->repository(body: $good . $ghost)->get();

		self::assertFalse($alerts['degraded'], 'one real anchor is a healthy read, not a failed one');
		self::assertCount(1, $alerts['items']);

		$warnings = array_values(array_filter($this->logged, static fn (array $l): bool => $l['level'] === 'warning'));
		self::assertCount(1, $warnings, 'a page with more pdf hrefs than parsed anchors must warn, exactly once');
		self::assertSame(2, $warnings[0]['context']['present'] ?? null, 'must count the lost href too, not only the parsed one');
		self::assertSame(1, $warnings[0]['context']['matched'] ?? null, 'must count only the anchor the regex actually matched');
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
		$alerts = $this->repository(offline: true)->get();

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
		$alerts = $this->repository(body: '<p>Sin alertas publicadas.</p>')->get();

		self::assertTrue($alerts['degraded'], 'zero items never overwrites a good copy');
		self::assertStringContainsString(
			'carried no alerts',
			implode(' | ', $this->loggedAt('warning')),
			'a page that was read and parsed to nothing is exactly what this sentence is for',
		);
	}

	/**
	 * ADR-0017: every alert fuente shares these lines, so they are templates
	 * now, and degreyd's Fuente renders each exactly as it read while this
	 * pipeline was MinsalRepository's — an admin's grep for yesterday's line
	 * still finds today's. Five faults, each on a cache of its own; the last is
	 * the table's, which pins the storage rule on the way: logged, never a
	 * degrade — the fetch succeeded, so the fresh copy serves.
	 */
	public function testDegreydsLinesReadAsTheyDidBeforeTheMove(): void {
		$huge = '<a href="https://degreyd.minsal.cl/wp-content/uploads/2026/07/x.pdf">Alerta</a>'
			. str_repeat('<!-- pad -->', 60000);

		$this->repository(offline: true)->get();
		$this->repository(body: '<p>Sin alertas publicadas.</p>', reuseCache: false)->get();
		$this->repository(refused: 403, reuseCache: false)->get();
		$this->repository(body: $huge, reuseCache: false)->get();
		$served = $this->repository(reuseCache: false, brokenTable: true)->get();

		self::assertSame(
			[
				'Could not reach the MINSAL alerts page',
				'The MINSAL alerts page could not be read',
				'The MINSAL alerts page carried no alerts',
				'MINSAL answered 403; under freno 1 time(s), waiting 7200s',
				'The MINSAL alerts page could not be read',
				sprintf('MINSAL alerts page of %d bytes, discarded', strlen($huge)),
				'The MINSAL alerts page could not be read',
				'Could not read the persisted MINSAL alerts set',
				'Could not persist the MINSAL alerts set',
			],
			$this->warnings(),
		);
		self::assertFalse($served['degraded'], 'a table that fails is logged, never served as an outage');
		self::assertCount(13, $served['items']);
	}

	public function testTheCooldownExpiresAndTheFeedIsTriedAgain(): void {
		$this->repository()->get();
		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();
		$this->requested = [];

		$this->repository(offline: true, now: self::NOW + 7200 + 301)->revalidate();

		self::assertNotSame([], $this->requested, 'past COOLDOWN the feed must be asked again');
	}

	public function testTheCooldownStillServesTheLastGoodCopyAndStillSaysDegraded(): void {
		$good = $this->repository()->get();
		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();

		$during = $this->repository(offline: true, now: self::NOW + 7260)->get();

		self::assertTrue($during['degraded'], 'a cooldown must never look like health');
		self::assertSame($good['items'], $during['items']);
	}

	// --- stale serve: first paint never waits (L1-01) -------------------------

	public function testAStaleCopyServesImmediatelyAndDispatchesOneRevalidation(): void {
		$this->repository()->get();
		$this->requested = [];
		$this->jobs = [];

		$stale = $this->repository(now: self::NOW + 7200)->get();

		self::assertFalse($stale['degraded'], 'a stale serve is not the degraded state');
		self::assertCount(13, $stale['items']);
		self::assertSame([], $this->requested, 'first paint asked nothing — the publisher wait moved off the render path');
		self::assertSame(
			[[RevalidateJob::class, ['source' => 'alerts']]],
			$this->jobs,
			'exactly one dispatch, argument the Sources id — and ::class pins the production string against a rename',
		);
		self::assertNotNull($this->store['revalidating-alerts'] ?? null, 'the marker holds the dispatch');
	}

	public function testASecondStaleReaderDoesNotDispatchAgain(): void {
		$this->repository()->get();
		$this->repository(now: self::NOW + 7200)->get();
		$this->repository(now: self::NOW + 7260)->get();

		self::assertCount(1, $this->jobs, 'the marker dedups the dispatch');
	}

	public function testAMarkerThatOutlivesItsJobRedispatches(): void {
		$this->repository()->get();
		$this->repository(now: self::NOW + 7200)->get();   // dispatch at 7200, marker TTL 300

		$this->repository(now: self::NOW + 7501)->get();   // marker expired

		self::assertCount(2, $this->jobs, 'a job that never ran is re-dispatched past the marker TTL');
	}

	public function testARevalidationClearsTheMarkerOnSuccessAndFailure(): void {
		$this->repository()->get();
		$this->repository(now: self::NOW + 7200)->get();
		self::assertArrayHasKey('revalidating-alerts', $this->store);

		$this->repository(now: self::NOW + 7201)->revalidate();

		self::assertArrayNotHasKey('revalidating-alerts', $this->store, 'a finished revalidation frees the next dispatch');

		// Past the freshness window that successful revalidation re-armed
		// (7201 + 3600): the entry must be STALE again before a reader
		// re-dispatches — at +7202 it would serve fresh and never reach
		// the marker. (Step 8 review catch.)
		$this->repository(offline: true, now: self::NOW + 10801)->get();
		self::assertArrayHasKey('revalidating-alerts', $this->store);
		$this->repository(offline: true, now: self::NOW + 10802)->revalidate();
		self::assertArrayNotHasKey('revalidating-alerts', $this->store, 'a FAILED revalidation clears it too — the cooldown, not the marker, paces the retry');
	}

	public function testAColdInstallStillFetchesSynchronouslyOnce(): void {
		$result = $this->repository()->get();

		self::assertFalse($result['degraded']);
		self::assertCount(1, $this->requested, 'nothing stored: today\'s bounded synchronous fetch, once');
		self::assertSame([], $this->jobs, 'no dispatch without a copy to serve');
	}

	/**
	 * Sync once, then behind the response (ADR-0017 D9). The first cold read
	 * still fetches while someone waits — a fresh install has nothing else to
	 * show. Once that fetch failed and its cooldown lapsed with nothing
	 * stored, the next cold read asks nothing: it dispatches the revalidation
	 * and says degraded, so a blocked host costs a render one bounded wait per
	 * cache lifetime instead of one every five minutes. The revalidation it
	 * dispatched is what fills the fuente.
	 */
	public function testASecondColdReadMovesTheFetchBehindTheResponse(): void {
		$first = $this->repository(offline: true)->get();
		self::assertTrue($first['degraded']);
		self::assertCount(1, $this->requested, 'precondition: the first cold read fetched while someone waited');

		$second = $this->repository(offline: true, now: self::NOW + 301)->get();
		$third = $this->repository(offline: true, now: self::NOW + 302)->get();

		self::assertCount(1, $this->requested, 'past the cooldown, a cold read asks nothing while someone waits');
		self::assertSame(['items' => [], 'fetched_at' => null, 'degraded' => true], $second, 'nothing to serve is degraded, never an empty healthy list');
		self::assertSame($second, $third);
		self::assertSame(
			[[RevalidateJob::class, ['source' => Sources::ALERTS]]],
			$this->jobs,
			'one dispatch behind the response, deduped by the marker',
		);

		$this->repository(now: self::NOW + 303)->revalidate();
		$filled = $this->repository(now: self::NOW + 304)->get();

		self::assertFalse($filled['degraded'], 'the revalidation it dispatched filled the fuente');
		self::assertCount(13, $filled['items']);
	}

	/**
	 * The revalidator asks the host's gates again (ADR-0017 D8). The hourly
	 * pass queues one revalidation per stale fuente, and the first of them to
	 * meet an outage arms its host's cooldown — seeded here as any fuente on
	 * the host writes it, after this job was queued. The job behind it asks
	 * nothing, and still frees its marker for the reader past the cooldown.
	 */
	public function testAQueuedRevalidationMakesNoRequestWhileTheHostCoolsDown(): void {
		$this->repository()->get();
		$this->repository(now: self::NOW + 7200)->get();
		self::assertCount(1, $this->jobs, 'precondition: the stale read queued its revalidation');

		$this->cache(self::NOW + 7200)->set('cooldown-alerts', 1, 300);
		$this->repository(now: self::NOW + 7210)->revalidate();

		self::assertCount(1, $this->requested, 'a revalidation queued behind a cooling host asks nothing');
		self::assertArrayNotHasKey('revalidating-' . Sources::ALERTS, $this->store, 'and the skip still frees the marker');
	}

	/**
	 * The brake's half of the same guard: past the cooldown a refusal leaves
	 * and inside its brake, a queued revalidation asks nothing and escalates
	 * nothing — the probe stays the one ask that goes through the brake.
	 */
	public function testAQueuedRevalidationMakesNoRequestWhileTheHostIsBraked(): void {
		$this->repository()->get();
		$this->repository(refused: 403, now: self::NOW + 7200)->revalidate();

		$this->repository(refused: 403, now: self::NOW + 7501)->revalidate();

		self::assertCount(2, $this->requested, 'a braked host is not asked by a queued revalidation');
		self::assertCount(
			1,
			array_filter($this->warnings(), static fn (string $w): bool => str_contains($w, 'answered 403')),
			'nothing asked, nothing escalated',
		);
	}

	// --- search --------------------------------------------------------------

	public function testFoldsAccentsSoSarampionFindsSarampion(): void {
		$repository = $this->repository();
		$repository->get();

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

	/**
	 * Retargeted from "returns nothing on a cold cache" — replaces
	 * testSearchNeverFetchesAndReturnsNothingOnAColdCache wholesale: an app
	 * update empties the CACHE, not the table (ADR-0015 §2), so a cold cache
	 * with a warm table is the post-update state every install hits, and search
	 * must answer from the persisted set — still without a single fetch. The
	 * empty branch keeps its place in the same test: cold cache AND cold table
	 * is the fresh install, and the never-seeded table must not conjure an
	 * answer.
	 */
	public function testSearchNeverFetchesAndAnswersFromThePersistedSetWhenOnlyTheCacheIsCold(): void {
		$repository = $this->repository();

		// The fresh install: nothing cached, nothing persisted.
		self::assertSame([], $repository->search('sarampion'));
		self::assertSame([], $this->requested, 'the global search box must never wait on MINSAL');

		$repository->get();

		// The app update: ADR-0012's cache namespace move, the table left holding.
		$this->store = [];
		$this->requested = [];

		$hits = $repository->search('sarampion');

		self::assertNotSame([], $hits, 'the persisted set must answer when only the cache is cold');
		self::assertSame([], $this->requested, 'a table read is not a fetch — the never-fetch contract holds');
	}

	public function testSearchIgnoresAnEmptyTerm(): void {
		$repository = $this->repository();
		$repository->get();

		self::assertSame([], $repository->search('   '));
	}

	// --- probe ---------------------------------------------------------------

	public function testTheProbeIsQuietWhenMinsalHasNotChanged(): void {
		$repository = $this->repository();
		$repository->get();
		$before = count($this->requested);

		self::assertSame(Probe::Healthy, $repository->probe());
		self::assertCount($before + 1, $this->requested, 'an unchanged feed costs exactly one probe');
	}

	/**
	 * degreyd's `modified` field read 2026-06-24 while its table listed alerts
	 * dated 31 July 2026 — the field never tracked the content, which is why
	 * probe() compares a digest of the parsed items instead. This is the test
	 * that would have failed to catch that exact silent failure if probe() still
	 * trusted a publication date.
	 *
	 * Asserted on the CONSEQUENCE of detection, never on probe()'s return value
	 * alone: `true` means "healthy", and it comes back both when the digest
	 * catches the change and refreshes, AND when a broken comparison misses the
	 * change entirely — `probe()` would still find a non-empty, parseable page
	 * either way. A bare `assertTrue()` cannot tell a real digest apart from
	 * `$items !== [] && $stored !== null`, a comparison that reports "unchanged"
	 * through every content change and still returns true — the exact shape of
	 * the fault this task exists to remove, and the same shape as the deleted
	 * fixture-era test whose own note said "nothing asserted that two alerts are
	 * told apart, so nothing saw it." What only a real comparison moves is the
	 * CACHE: after probe(), get() must serve the /2026/08/ urls, not the stale
	 * /2026/06/ ones — and the request count must show the refresh happened.
	 */
	public function testProbeReportsChangeWhenTheContentChangesButTheDateDoesNot(): void {
		$first = (string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
		$second = str_replace('uploads/2026/06/', 'uploads/2026/08/', $first);

		$repo = $this->repository($first);
		$repo->get();
		$before = count($this->requested);

		$changed = $this->repository($second, reuseCache: true);
		self::assertSame(Probe::Healthy, $changed->probe());

		$urls = implode(' ', array_column($changed->get()['items'], 'url'));
		self::assertStringContainsString('/2026/08/', $urls, 'the digest must have caught the change and refreshed the cache');
		self::assertStringNotContainsString('/2026/06/', $urls, 'the stale copy must not still be served');
		self::assertCount($before + 1, $this->requested, 'one probe fetch, and no second one from get() since the refresh already left the cache fresh');
	}

	public function testTheProbeReportsFailureWhenMinsalStopsParsing(): void {
		$repository = $this->repository(body: '<p>nada</p>');

		// The host answered: the page is this fuente's fault, not the host's.
		self::assertSame(Probe::Unreadable, $repository->probe());
	}

	public function testTheProbeReportsFailureWhenMinsalIsUnreachable(): void {
		// No answer at all: the host's fault, which is what holds its siblings (ProbeJob).
		self::assertSame(Probe::Unreachable, $this->repository(offline: true)->probe());
	}

	// --- the durable set: ADR-0015 §2 ----------------------------------------

	public function testAnAppUpdateThatEmptiesTheCacheStillServesThePersistedSet(): void {
		$this->repository()->get();

		// The app update emptied the cache (ADR-0012); MINSAL is down on top of it.
		$offline = $this->repository(offline: true, reuseCache: false, now: self::NOW + 7200);
		$offline->revalidate();

		$after = $offline->get();

		self::assertTrue($after['degraded'], 'an outage on top of an app update is still an outage');
		self::assertCount(13, $after['items'], 'the persisted set is the durable last-good copy');
		self::assertNull($after['fetched_at'], 'no freshness is claimed for a copy whose fetch time left with the cache');
		self::assertSame(
			array_map(static fn (Alert $a): string => $a->getUrl(), $this->rows),
			array_column($after['items'], 'url'),
			'the served copy IS the persisted set',
		);
	}

	public function testAProbeOnAColdCacheRestoresRatherThanTrustsAReDerivedDigest(): void {
		$this->repository()->get();
		self::assertCount(13, $this->rows);

		// The app update: cache namespace moved (ADR-0012), table still holding.
		$repository = $this->repository(reuseCache: false);
		$this->requested = [];

		self::assertSame(Probe::Healthy, $repository->probe());

		// D8: the fabricated entry carries no digest, so the probe cannot
		// "match" its way out of re-storing — the cache entry is back, from the
		// one fetch the probe always costs, and the replace-set re-ran.
		self::assertArrayHasKey('feed-alerts', $this->store, 'the probe must re-store on a cold cache');
		self::assertCount(13, $this->rows, 'the replace-set re-ran idempotently');
		self::assertCount(1, $this->requested, 'one probe fetch');
	}

	public function testAWithdrawnAlertVanishesFromThePersistedSet(): void {
		$two = '<a href="https://degreyd.minsal.cl/uploads/2026/07/a.pdf">Alerta vigente</a>'
			. '<a href="https://degreyd.minsal.cl/uploads/2026/07/b.pdf">Alerta retirada</a>';
		$one = '<a href="https://degreyd.minsal.cl/uploads/2026/07/a.pdf">Alerta vigente</a>';

		$this->repository(body: $two)->get();
		self::assertSame(
			['https://degreyd.minsal.cl/uploads/2026/07/a.pdf', 'https://degreyd.minsal.cl/uploads/2026/07/b.pdf'],
			array_map(static fn (Alert $a): string => $a->getUrl(), $this->rows),
			'precondition: both alerts persisted',
		);

		$this->repository(body: $one, now: self::NOW + 7200)->revalidate();

		self::assertSame(
			['https://degreyd.minsal.cl/uploads/2026/07/a.pdf'],
			array_map(static fn (Alert $a): string => $a->getUrl(), $this->rows),
			'a withdrawn alert vanishes with the set that no longer names it',
		);
	}

	public function testAFailedParseWritesNothingToThePersistedSet(): void {
		$this->repository()->get();
		$before = array_map(static fn (Alert $a): string => $a->getUrl(), $this->rows);

		// Both entry classes of fail(): nothing read at all, and read-but-parsed-
		// to-nothing. The second revalidation sits past +7501 on purpose: the first
		// armed the cooldown until then, and a read inside it would short-circuit
		// at the cooldown branch and never see the fault — pinning nothing.
		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();
		$this->repository(body: '<p>En mantención</p>', now: self::NOW + 7501)->revalidate();

		self::assertSame(
			$before,
			array_map(static fn (Alert $a): string => $a->getUrl(), $this->rows),
			'fail() writes nothing — the persisted set is the last good one',
		);
	}

	/**
	 * ADR-0017: the table is every alert fuente's now, so this fuente reads and
	 * replaces only its own set — asked for by id, never the whole table.
	 * Recorded by the double rather than asserted inside it: store() catches
	 * Throwable, so an assertion thrown there would be swallowed as a storage
	 * fault and the test would pass for the wrong reason.
	 */
	public function testTheSetIsReadAndReplacedUnderItsOwnFuente(): void {
		$this->repository()->get();

		self::assertSame(
			['alerts', 'alerts'],
			$this->scopes,
			'the cold read asked the table, then the fetch replaced the set — both under alerts',
		);
	}

	// --- the brake: an answer is not a challenge ------------------------------

	public function testARefusalArmsTheBrakeAndReadsStopAsking(): void {
		$this->repository()->get();

		$this->repository(refused: 403, now: self::NOW + 7200)->revalidate();
		self::assertCount(2, $this->requested, 'precondition: the refusal was asked once, after expiry');

		// Past COOLDOWN (armed at +7200, gone at +7500), still inside the brake
		// (armed at +7200 until +14400). The cooldown-only code this replaces
		// would have asked here — that ask is what this pin exists to hold.
		$held = $this->repository(refused: 403, now: self::NOW + 7501)->get();

		self::assertTrue($held['degraded']);
		self::assertCount(13, $held['items'], 'the last good copy serves while the brake holds');
		self::assertCount(2, $this->requested, 'a braked read must not ask — a 403 is an answer, not a challenge');
		self::assertSame(1, $this->store['freno-alerts']['v']['n'] ?? null, 'one refusal, one count');
	}

	public function testATimeoutStaysCooldownClassAndNeverArmsTheBrake(): void {
		$this->repository()->get();

		$this->repository(offline: true, now: self::NOW + 7200)->revalidate();
		self::assertArrayNotHasKey('freno-alerts', $this->store, 'no answer came back — there is no refusal to remember');

		$this->repository(offline: true, now: self::NOW + 7501)->revalidate();

		self::assertCount(3, $this->requested, 'past COOLDOWN the feed must be asked again — recovery stays automatic');
	}

	public function testTheProbeAsksThroughTheBrakeAndReportsARefusal(): void {
		$this->repository()->get();
		$this->repository(refused: 403, now: self::NOW + 7200)->revalidate();
		$this->requested = [];

		// #218beba: a fully-braked reader must still report unreachable to
		// ProbeJob — asked, not assumed, and the bookkeeping runs through the
		// ask exactly as on the hourly path.
		$probe = $this->repository(refused: 403, now: self::NOW + 7260);

		// A refusal is an answer: Unreadable, never Unreachable — the host's other
		// addresses still get their own asks, and the next answer lifts the brake.
		self::assertSame(Probe::Unreadable, $probe->probe());
		self::assertCount(1, $this->requested, 'the probe asked through the brake');
		self::assertSame(2, $this->store['freno-alerts']['v']['n'] ?? null, 'the ask escalated the count, it did not assume the state');
	}

	public function testAHostThatAnswersAgainClearsTheBrakeSoReadsResume(): void {
		$this->repository()->get();
		$this->repository(refused: 403, now: self::NOW + 7200)->revalidate();
		self::assertArrayHasKey('freno-alerts', $this->store);

		// The daily ask catches the recovery, and the answer clears the key.
		self::assertSame(Probe::Healthy, $this->repository(now: self::NOW + 7260)->probe());
		self::assertArrayNotHasKey('freno-alerts', $this->store, 'an answer removes the key outright — recovery is one step');

		// Past the cooldown the refusal left (+7500), the read resumes asking.
		$this->repository(now: self::NOW + 7501)->revalidate();
		self::assertCount(4, $this->requested, 'the read resumed once the brake cleared');
	}

	public function testEveryRequestIntroducesItselfAndStaysBounded(): void {
		$this->repository()->get();

		self::assertSame(
			'APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)',
			$this->options[0]['headers']['User-Agent'] ?? null,
			'every outbound request names this app (ADR-0015 §4)',
		);
		self::assertSame(15, $this->options[0]['timeout'] ?? null, 'the read stays bounded — a gate that blocks silently is not a gate (#130)');
	}

	// --- epi: a second page on a host of its own (ADR-0017) -----------------

	/**
	 * epi.minsal.cl is read by the same parser from its own address, into its
	 * own cache entry and its own table scope — and its host is its own:
	 * degreyd's outage, left behind as degreyd's cooldown, does not hold it.
	 */
	public function testEpiReadsItsOwnPageUnderItsOwnHost(): void {
		$this->repository(offline: true)->get();
		$this->requested = [];
		$this->scopes = [];

		$epi = $this->repositories()->repository(Sources::EPI)->get();

		self::assertFalse($epi['degraded'], 'degreyd\'s host being down is not epi\'s');
		self::assertCount(77, $epi['items']);
		self::assertSame(['https://epi.minsal.cl/alertas-epidemiologicas-vigentes/'], $this->requested);
		self::assertSame(['epi_alerts', 'epi_alerts'], $this->scopes, 'read and replaced under its own fuente');
		self::assertArrayHasKey('feed-epi_alerts', $this->store);
	}

	/**
	 * The other direction, in epi's own sentences: Cloudflare's 403 to a
	 * datacentre brakes epi's host, names epi in the log, and leaves
	 * degreyd's reads asking.
	 */
	public function testARefusalFromEpiBrakesEpiAloneAndSaysSo(): void {
		$this->repositories(refused: 403)->repository(Sources::EPI)->get();

		self::assertSame(
			[
				'MINSAL epi answered 403; under freno 1 time(s), waiting 7200s',
				'The MINSAL epi alerts page could not be read',
			],
			$this->warnings(),
		);
		self::assertArrayHasKey('freno-epi', $this->store);

		$degreyd = $this->repository()->get();

		self::assertFalse($degreyd['degraded'], 'epi\'s brake does not hold degreyd');
		self::assertCount(2, $this->requested);
	}

	// --- the factory -------------------------------------------------------

	/**
	 * The catalogue's loudness, carried across the factory (ADR-0017). Every
	 * suite that builds Sources hands it a factory double, so none of them walks
	 * AlertRepositories' own match — and an id listed in Sources::ALL and
	 * missing there would reach production as an UnhandledMatchError on Inicio,
	 * for everyone. Every id but the informe's is an alert fuente; each resolves
	 * here, to one instance however often Sources asks.
	 */
	public function testEveryAlertIdInTheCatalogueResolvesToOnePipeline(): void {
		$repositories = $this->repositories();
		$ids = array_diff(Sources::ALL, [Sources::REPORTS]);
		self::assertNotSame([], $ids, 'a catalogue with no alert fuente would pass this walk for free');

		foreach ($ids as $id) {
			self::assertSame($repositories->repository($id), $repositories->repository($id), $id);
		}
	}

	/**
	 * The host map the sondeo groups by (ADR-0017 D8): the ISP's three alert
	 * feeds on the ISP's host — which the informe shares, Sources::host() — and
	 * every other alert fuente alone on its own. ProbeJobTest's double answers
	 * with this map; this is where the map is the factory's, not a copy's.
	 */
	public function testTheIspsFuentesShareOneHostAndEveryOtherHostIsItsOwn(): void {
		$repositories = $this->repositories();
		$hosts = [];
		foreach (array_diff(Sources::ALL, [Sources::REPORTS]) as $id) {
			$hosts[$id] = $repositories->host($id);
		}

		self::assertSame([
			Sources::ALERTS => 'alerts',
			Sources::EPI => 'epi',
			Sources::ISP_ANAMED => AlertRepositories::ISP_HOST,
			Sources::ISP_PHARMACOVIGILANCE => AlertRepositories::ISP_HOST,
			Sources::ISP_COSMETICS => AlertRepositories::ISP_HOST,
			Sources::PAHO => 'paho',
		], $hosts);
	}

	// --- doubles -------------------------------------------------------------

	/** degreyd's pipeline, as Sources is handed it — see repositories() for the doubles. */
	private function repository(
		?string $body = null,
		bool $offline = false,
		?int $now = null,
		bool $reuseCache = true,
		?int $refused = null,
		bool $brokenTable = false,
	): AlertRepository {
		return $this->repositories($body, $offline, $now, $reuseCache, $refused, $brokenTable)->repository(Sources::ALERTS);
	}

	/**
	 * The factory every alert pipeline is built through (ADR-0017), over this
	 * suite's doubles — the one place the suite constructs anything.
	 *
	 * @param string|null $body        served for every request, instead of the fixture
	 * @param int|null    $refused     an HTTP status the host answers with instead of a page —
	 *                                 the refusal class, carried by the throwable the way a
	 *                                 BadResponseException carries its response
	 * @param int|null    $now         later than NOW to push a cached copy past its freshness window
	 * @param bool        $reuseCache  false gives this repository its own empty cache instead of
	 *                                 sharing $this->store with earlier repository() calls in the
	 *                                 same test — the app-update tests use it to model ADR-0012's
	 *                                 namespace move, and the mapper double keeps sharing $this->rows
	 *                                 either way, because the table is what survives the update.
	 * @param bool        $brokenTable every read and write of the table throws — a storage fault,
	 *                                 which the pipeline logs and never serves as an outage
	 */
	private function repositories(
		?string $body = null,
		bool $offline = false,
		?int $now = null,
		bool $reuseCache = true,
		?int $refused = null,
		bool $brokenTable = false,
	): AlertRepositories {
		if (!$reuseCache) {
			$this->store = [];
		}

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $offline, $refused): IResponse {
				$this->requested[] = $url;
				$this->options[] = $options;

				if ($refused !== null) {
					throw new RuntimeException("HTTP $refused returned without a body");
				}
				if ($offline) {
					throw new RuntimeException('cURL error 6: Could not resolve host');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body ?? $this->fixture($url));

				return $response;
			},
		);

		/*
		 * The real contract, mirrored (Client.php:445-451): the response when the
		 * throwable carries one, the throwable itself again when it does not. A
		 * stub that answered a response for the offline class would make every
		 * timeout read as a refusal — the exact conflation this slice exists to
		 * remove, smuggled in through the double.
		 */
		$client->method('getResponseFromThrowable')->willReturnCallback(
			function (Throwable $e) use ($refused): IResponse {
				if ($refused === null) {
					throw $e;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($refused);

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now ?? self::NOW));

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function ($job, $argument = null): void {
			$this->jobs[] = [$job, $argument];
		});

		/*
		 * The mapper double is the table, state-backed (ADR-0007): readSet answers
		 * what replaceSet wrote, the whole set replaced — so a withdrawn alert is
		 * assertable as an absent row, never as a call count.
		 */
		$mapper = $this->createMock(AlertMapper::class);
		$mapper->method('readSet')->willReturnCallback(function (string $fuente) use ($brokenTable): array {
			$this->scopes[] = $fuente;
			if ($brokenTable) {
				throw new RuntimeException('SQLSTATE[08006]: the connection to the database was lost');
			}

			return $this->rows;
		});
		$mapper->method('replaceSet')->willReturnCallback(function (string $fuente, array $alerts) use ($brokenTable): void {
			$this->scopes[] = $fuente;
			if ($brokenTable) {
				throw new RuntimeException('SQLSTATE[08006]: the connection to the database was lost');
			}
			$this->rows = $alerts;
		});

		// One recorder for both: the parse's warnings land in $this->logged
		// exactly as they did while parse() was this repository's own method.
		$logger = $this->logger();

		// The ISP's and OPS/OMS's collaborators too, because the factory
		// takes them: this suite's fuentes never reach them (AlertStreamTest's do).
		return new AlertRepositories(
			$clients,
			$time,
			$logger,
			$mapper,
			$cacheFactory,
			$jobs,
			new PageParser($logger),
			$this->appConfig(),
			$this->createMock(CertificateBundle::class),
			new FeedParser($logger),
			new PahoParser($logger),
		);
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
	 * Every warning as nextcloud.log prints it: each `{key}` replaced by its
	 * context value, the interpolation Nextcloud's Log applies before a line is
	 * written. What an admin greps for is the rendered sentence, not the template.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array_map(
			static fn (array $l): string => (string)preg_replace_callback(
				'/\{(\w+)\}/',
				static fn (array $m): string => is_scalar($l['context'][$m[1]] ?? null) ? (string)$l['context'][$m[1]] : $m[0],
				$l['message'],
			),
			array_values(array_filter($this->logged, static fn (array $l): bool => $l['level'] === 'warning')),
		);
	}

	/**
	 * The real captured responses this suite is tested against: degreyd's page,
	 * and epi's for epi's own tests. Each fuente reads exactly one fixed URL, so
	 * the host is all there is to dispatch on.
	 *
	 * An unmatched url is recorded and asserted in tearDown, NOT thrown here.
	 * Throwing looks right and is useless: this runs inside the client's get(),
	 * and AlertRepository::request() catches Throwable — AssertionFailedError
	 * included — logs a warning and returns null. A missing fixture would
	 * therefore read as an unreachable host, go down the degraded path, and let
	 * a test pass green. That is the exact failure this double exists to make
	 * impossible, so the assertion has to happen where production cannot catch it.
	 *
	 * Pass `body:` for a request that is meant to have no fixture.
	 */
	private function fixture(string $url): string {
		if (str_contains($url, 'epi.minsal.cl')) {
			return (string)file_get_contents(__DIR__ . '/fixtures/epi-alertas-vigentes.html');
		}

		if (!str_contains($url, 'degreyd.minsal.cl')) {
			$this->unmatched[] = $url;

			return '';
		}

		return (string)file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
	}
}
