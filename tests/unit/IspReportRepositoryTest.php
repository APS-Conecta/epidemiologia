<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Db\Report;
use OCA\Epidemiologia\Db\ReportMapper;
use OCA\Epidemiologia\Service\CertificateBundle;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The informe fuente, feed-shaped.
 *
 * `fixtures/isp-informes.xml` is a real captured feed body, per this repo's
 * standing rule — synthetic RSS would only prove the parser can read markup
 * we wrote for it. The capture landed 2026-09-20: the documento feed's own
 * page 1, minted from a reachable vantage (the dev VPS cannot reach the
 * ISP, #130). The two capture-named tests read it; every other pin builds
 * its own feed with rss(): the mechanics must not wait on a network this
 * box is blocked from.
 *
 * The doubles carry three disciplines the suites they replace learned the
 * hard way: the client double answers getResponseFromThrowable with the REAL
 * contract (response when the throwable carries one, rethrow when it does
 * not — a stub that answered one for a timeout would smuggle the
 * every-timeout-is-a-refusal conflation in through the double), the cache
 * double stores entries wrapped with absolute expiry (Doubles.php: the freno
 * and the cooldown are expressed AS expiry), and the mapper double IS the
 * table, state-backed by (year, se) — so a reissue is assertable as ONE row
 * and SE 1 of two years as TWO, never as collaborator call counts
 * (ADR-0007).
 *
 * The document arms follow the same doctrine: the FakeAppData from Slice 1
 * is the store (its NotFoundException is the real one, its $written ledger
 * is the never-refetch assertion surface — actions, not call counts), the
 * client double answers document urls from a $docs map and fails them like
 * an outage when unarmed — the DEFAULT, because every wire-url pin from the
 * reader slice was written against rows that never keep a document, and an
 * arm that silently served feed bytes as a document would turn each of
 * them into a test passing for the wrong reason. Document requests are
 * ledgered in $this->documents, separate from the feed reads: the
 * lifecycle pins count page-1 fetches and the document pins count
 * documents, and one shared ledger would let each assertion pass for the
 * other's reasons.
 */
class IspReportRepositoryTest extends TestCase {
	use Doubles;

	/** 2026-09-15T12:00:00Z. Fixed, because every freshness assertion is relative to it. */
	private const NOW = 1789473600;

	/** Past FRESH_FOR (one hour), so a second reader genuinely refreshes. */
	private const STALE = 7200;

	/** The week the default rss() reading carries: SE 35, filed 2026-09. */
	private const WEEK = [
		't' => 'Semana 35, 2026',
		'd' => 'Informe circulación virus respiratorios SE35 08-09-2026 V3',
		'u' => 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-circulacion-virus-respiratorios-SE35-08-09-2026-V3.pdf',
	];

	/**
	 * The path the CertificateBundle double answers with. Never read from
	 * disk — the mocked client answers regardless — so it only has to be
	 * recognisable.
	 */
	private const BUNDLE = '/fake/ca-bundle.pem';

	/** The in-memory distributed cache, shared by every repository one test builds. */
	private array $store = [];

	/** The persisted series: the ReportMapper double's table — Report entities, keyed 'year-se'. */
	private array $rows = [];

	/** @var list<string> page-1 requests made, in order (the walk's pages live in $pagesRequested) */
	private array $requested = [];

	/** @var list<array<string, mixed>> the options of every request made, in order — page 1 and walk */
	private array $options = [];

	/** @var list<int> every walk page requested, in order */
	private array $pagesRequested = [];

	/** @var array<int, string> walk-page bodies by page number; a page with no entry 404s (exhaustion). */
	private array $pages = [];

	/** Walk pages throw (unreachable — no answer class) rather than answering. */
	private bool $pagesOffline = false;

	/** Walk pages answer this refusal status instead of a body. */
	private ?int $pagesRefused = null;

	/** The mapper double refuses everything — the storage-fault class, while the feed still answers. */
	private bool $storageDown = false;

	/** The app-data fake from Slice 1: the kept documents' store, shared by every repository one test builds. */
	private FakeAppData $appData;

	/** @var array<string, string> document url => the bytes the publisher serves for it */
	private array $docs = [];

	/** @var list<string> every document request, in order — the never-refetch ledger */
	private array $documents = [];

	/** @var list<string> every linkToRoute() call's route name, in order */
	private array $routes = [];

	/** Document fetches answer this refusal status instead of bytes. */
	private ?int $docsRefused = null;

	/** @var list<string> request URLs that matched no fixture; see fixture() */
	private array $unmatched = [];

	/** @var list<array{level: string, message: string, context: array}> what the repository logged */
	private array $logged = [];

	/** @var array<string, bool> the locking cache's contents — one key, held or not */
	private array $locks = [];

	protected function setUp(): void {
		$this->store = [];
		$this->rows = [];
		$this->requested = [];
		$this->options = [];
		$this->pagesRequested = [];
		$this->pages = [];
		$this->pagesOffline = false;
		$this->pagesRefused = null;
		$this->storageDown = false;
		$this->appData = new FakeAppData();
		$this->docs = [];
		$this->documents = [];
		$this->routes = [];
		$this->docsRefused = null;
		$this->unmatched = [];
		$this->logged = [];
		$this->locks = [];
		$this->config = [];
	}

	/** Runs outside the repository's catch, which is the only place this can bite. */
	protected function assertPostConditions(): void {
		self::assertSame(
			[],
			$this->unmatched,
			'a request matched no fixture — add one, or pass body:',
		);
	}

	// --- what the feed actually publishes -------------------------------------

	public function testTheCaptureParsesIntoTheSeries(): void {
		$result = $this->repository()->get();

		self::assertFalse($result['degraded']);
		$items = $result['items'];
		self::assertNotSame([], $items, 'the capture must carry informes, or this test asserts nothing');
		foreach ($items as $item) {
			self::assertMatchesRegularExpression('/^\\d{1,2}$/', $item['se']);
			self::assertMatchesRegularExpression('/^\\d{4}$/', $item['year']);
			self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}$/', $item['month']);
			self::assertStringStartsWith('https://', $item['url']);
			self::assertNotSame('', $item['title']);
		}

		// The wire's address for a week the document is not kept for is the
		// publisher's own page — the formula publisherPage() derives, pinned
		// here against the capture's own <link> for its newest informe.
		self::assertSame('https://www.ispch.gob.cl/documento/semana-36-2026/', $items[0]['url']);
	}

	/**
	 * Guards every capture-named test from passing for free after a
	 * re-capture: the fixture must keep carrying what the parser and the
	 * wire address need — informe labels in the descriptions, https PDF
	 * hrefs inside content:encoded, pubDates, and the semana page links
	 * publisherPage() derives to match — or the rest of this
	 * suite proves nothing about what the ISP actually publishes.
	 */
	public function testTheCaptureStillCarriesWhatTheParserNeeds(): void {
		$xml = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.xml');

		self::assertNotSame('', $xml, 'the capture is missing — minting it is this slice\'s recorded manual prerequisite');
		self::assertGreaterThan(0, preg_match_all('/Informe\\s+circulaci[oó]n\\s+virus\\s+respiratorios\\s+SE\\s*\\d{1,2}/iu', $xml), 'no informe labels in the descriptions — re-capture');
		self::assertGreaterThan(0, preg_match_all('#<a\\s[^>]*href="https://[^"]+\\.pdf"#iu', $xml), 'no https PDF links inside content:encoded — re-capture');
		self::assertGreaterThan(0, preg_match_all('#<pubDate>[^<]++</pubDate>#', $xml), 'no pubDates — the year derives from them, re-capture');
		self::assertGreaterThan(0, preg_match_all('#<link>\\s*https://www\\.ispch\\.gob\\.cl/documento/semana-\\d{1,2}-\\d{4}/\\s*</link>#', $xml), 'no semana page links — the wire\'s derived page address is pinned against them, re-capture');
	}

	/**
	 * Only the informe is an informe: the documento feed carries the ISP's
	 * other posts too — the capture's own page 1 mixes an E. coli study and
	 * a working-group acta into the weekly series — and only the label tells
	 * the weekly series from them. Pinned
	 * synthetically because the capture's own mix is a fact about the ISP the
	 * capture-guard deliberately does not require — a feed of nothing but
	 * informes is a legitimate shape, and requiring a rejectable post in the
	 * capture would fail a re-capture the ISP had tidied.
	 *
	 * The pin kills the dropped-continue mutation: without the filter, a
	 * non-matching item falls through and inherits the previous iteration's
	 * $semana, so this test would see three informes where there is one.
	 */
	public function testOnlyTheWeeklyInformeIsKeptFromTheFeed(): void {
		$feed = $this->rss([
			['d' => 'E coli 19082026', 'u' => 'https://www.ispch.gob.cl/wp-content/uploads/2026/08/E-coli-19082026.pdf'],
			self::WEEK,
			['d' => 'ACTA GT N° 7-26', 'u' => 'https://www.ispch.gob.cl/wp-content/uploads/2026/08/ACTA-GT-N°-7-26.pdf'],
		]);

		$items = $this->repository(body: $feed)->get()['items'];

		self::assertCount(1, $items, 'the feed\'s other posts are not informes');
		self::assertSame('35', $items[0]['se']);
	}

	public function testTheSemanaComesFromTheDescriptionAndTheDateFromPubDate(): void {
		$feed = $this->rss([['d' => 'Informe circulación virus respiratorios SE35 08-09-2026', 't' => 'Semana 35, 2026', 'u' => 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-circulacion-virus-respiratorios-SE35-08-09-2026.pdf', 'p' => 'Tue, 08 Sep 2026 20:38:58 +0000']]);

		$items = $this->repository(body: $feed)->get()['items'];

		self::assertCount(1, $items);
		self::assertSame('35', $items[0]['se']);
		self::assertSame('2026', $items[0]['year'], 'the year comes from pubDate, never from a URL');
		self::assertSame('2026-09', $items[0]['month'], 'the pane prints the month beside the semana');
		self::assertSame('Semana 35, 2026', $items[0]['title'], 'the publisher\'s own words — rendered by the pane as hidden accessible text');
	}

	/**
	 * The ISP is a Chilean publisher and reads a Chilean date. `format()` on
	 * the server's UTC would file a pubDate of the first of January under the
	 * new year while Santiago still calls it December — the exact boundary
	 * case the migration's docblock names, and the one a UTC derivation
	 * gets wrong.
	 */
	public function testTheYearIsThePublishersYearNotTheServers(): void {
		// 2027-01-01T01:00:00Z is still 2026-12-31 in Santiago (UTC-3 in the
		// southern summer). Filing it under 2027 would put SE 53 a year early
		// and make the (year, se) key disagree with the week it names.
		$feed = $this->rss([['d' => 'Informe circulación virus respiratorios SE53', 'u' => 'https://www.ispch.gob.cl/se53.pdf', 'p' => 'Fri, 01 Jan 2027 01:00:00 +0000']]);

		$items = $this->repository(body: $feed)->get()['items'];

		self::assertSame('2026', $items[0]['year']);
		self::assertSame('2026-12', $items[0]['month']);
	}

	// --- the (year, se) identity ----------------------------------------------

	/**
	 * The collision the redesign exists to end: the se-only cache key this
	 * class used to carry could hold one SE 1 only, and the January it
	 * collided was survivable only because ADR-0012 guaranteed the series
	 * never spanned a year — a guarantee the table destroys by design.
	 */
	public function testTheSameSemanaOfDifferentYearsIsTwoInformes(): void {
		$week1of2026 = $this->rss([['d' => 'Informe circulación virus respiratorios SE1', 'u' => 'https://www.ispch.gob.cl/2026-se1.pdf', 'p' => 'Fri, 10 Jan 2026 12:00:00 +0000']]);
		$week1of2027 = $this->rss([['d' => 'Informe circulación virus respiratorios SE1', 'u' => 'https://www.ispch.gob.cl/2027-se1.pdf', 'p' => 'Fri, 08 Jan 2027 12:00:00 +0000']]);

		$this->repository(body: $week1of2026)->get();
		$items = $this->repository(body: $week1of2027, reuseCache: true, now: self::NOW + self::STALE)->get()['items'];

		self::assertCount(2, $this->rows, 'two years of SE 1 are two rows, not one replacing the other');
		self::assertSame(['2027', '2026'], array_column($items, 'year'), 'and the newer one is served first');
	}

	/**
	 * The reason the key is (year, se) and not url: the ISP reissues a week
	 * under a new version suffix (…-V3, …-V4), and two rows for one week are
	 * a duplicate the pane — which prints the semana and the month, never the
	 * filename — cannot tell apart.
	 */
	public function testARepublishedWeekReplacesTheOneItSupersedes(): void {
		$v3 = 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-SE35-08-09-2026-V3.pdf';
		$v4 = 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-SE35-08-09-2026-V4.pdf';

		$this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE35 08-09-2026', 'u' => $v3]]))->get();
		$items = $this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE35 08-09-2026', 'u' => $v4]]), reuseCache: true, now: self::NOW + self::STALE)->get()['items'];

		self::assertCount(1, $this->rows, 'a reissue is the same informe, not a second one');
		self::assertSame($v4, $this->rows['2026-35']->getUrl(), 'the reissue\'s exact document address is the row\'s provenance now — the archiver\'s target and the reissue signal');
		self::assertSame('https://www.ispch.gob.cl/documento/semana-35-2026/', $items[0]['url'], 'the visible address is the stable page; the informe behind it is the reissue');
	}

	/**
	 * `SE05` and `SE5` are one week typed two ways by the same
	 * WordPress editor the `/u` flag defends against, and the row key is
	 * derived from those digits: stored as typed, a republish that dropped
	 * the zero wrote a SECOND row for the same week.
	 */
	public function testAZeroPaddedSemanaIsTheSameWeekAsAnUnpaddedOne(): void {
		$padded = $this->rss([['d' => 'Informe circulación virus respiratorios SE05', 'u' => 'https://www.ispch.gob.cl/se05.pdf']]);
		$bare = $this->rss([['d' => 'Informe circulación virus respiratorios SE5', 'u' => 'https://www.ispch.gob.cl/se5.pdf']]);

		$this->repository(body: $padded)->get();
		$items = $this->repository(body: $bare, reuseCache: true, now: self::NOW + self::STALE)->get()['items'];

		self::assertCount(1, $this->rows, 'the same week, typed twice, is not two informes');
		self::assertSame('5', $items[0]['se'], 'the semana is stored canonically, not as typed');
		self::assertSame('https://www.ispch.gob.cl/se5.pdf', $this->rows['2026-5']->getUrl(), 'the later reading wins — the same week, retyped');
	}

	/**
	 * Semanas restart every January, so "newest first" is (year, se) — the
	 * mapper's own order, pinned here through the double. The pane shows the
	 * first rows of this list on Inicio.
	 */
	public function testTheSeriesIsServedNewestFirstAcrossAYearBoundary(): void {
		$december = $this->rss([['d' => 'Informe circulación virus respiratorios SE52', 'u' => 'https://www.ispch.gob.cl/se52.pdf', 'p' => 'Tue, 22 Dec 2026 12:00:00 +0000']]);
		$january = $this->rss([['d' => 'Informe circulación virus respiratorios SE2', 'u' => 'https://www.ispch.gob.cl/se2.pdf', 'p' => 'Fri, 08 Jan 2027 12:00:00 +0000']]);

		$this->repository(body: $december)->get();
		$items = $this->repository(body: $january, reuseCache: true, now: self::NOW + self::STALE)->get()['items'];

		self::assertSame(['2', '52'], array_column($items, 'se'), 'the new year\'s second week outranks the old year\'s fifty-second');
	}

	// --- failure: zero items is a failure, never a result ----------------------

	public function testAPageThatYieldsNoInformeDegradesAndKeepsTheLastGoodCopy(): void {
		$good = $this->repository(body: $this->rss([self::WEEK]))->get()['items'];

		$broken = $this->repository(body: '<p>En mantención</p>', reuseCache: true, now: self::NOW + self::STALE)->get();

		self::assertTrue($broken['degraded']);
		self::assertSame($good, $broken['items'], 'a failed read must never replace the last good copy');
		self::assertCount(1, $this->rows, 'the persisted series keeps the last good copy too');
	}

	/**
	 * "Could not be read" and "was read and carried no informe" are different
	 * faults and the log has to name which: the payload is byte-identical in
	 * both, and the WAF-200 case is the one that misleads even a log reader.
	 */
	public function testAFeedThatCouldNotBeReadIsNotReportedAsOneThatCarriedNoInforme(): void {
		$result = $this->repository(body: $this->rss([self::WEEK]), offline: true)->get();

		self::assertTrue($result['degraded']);
		self::assertStringNotContainsStringIgnoringCase(
			'no informe',
			implode(' | ', $this->loggedAt('warning')),
			'the ISP was never reached — saying its feed carried no informe names the wrong fault',
		);
	}

	/** The other side of the pair: the restructure fault must still be named, in those words. */
	public function testAFeedThatWasReadAndParsedToNothingStillSaysSo(): void {
		$result = $this->repository(body: '<p>Sin informes publicados.</p>')->get();

		self::assertTrue($result['degraded'], 'zero items never overwrites a good copy');
		self::assertStringContainsString(
			'carried no informe',
			implode(' | ', $this->loggedAt('warning')),
			'a feed that was read and parsed to nothing is exactly what this sentence is for',
		);
	}

	public function testWithNothingCachedAndTheIspDownItSaysDegradedRatherThanEmpty(): void {
		$result = $this->repository(body: $this->rss([self::WEEK]), offline: true)->get();

		self::assertTrue($result['degraded']);
		self::assertSame([], $result['items']);
		self::assertNull($result['fetched_at']);
	}

	// --- lifecycle --------------------------------------------------------------

	public function testServesTheCacheWithoutRefetchingWhileItIsFresh(): void {
		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->get();

		self::assertCount(1, $this->requested, 'a fresh cache must not be refetched');
	}

	/**
	 * Degrading writes nothing, so the entry stays stale and every read would
	 * retry. PageController::index() fetches synchronously while someone
	 * waits, so against a host that drops packets rather than refusing them
	 * that is a gateway timeout instead of the degraded banner.
	 */
	public function testAnOutageIsNotRetriedOnEveryRead(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, offline: true)->get();
		$this->requested = [];

		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE + 60, offline: true)->get();

		self::assertSame([], $this->requested, 'the outage was retried instead of being remembered');
	}

	public function testTheCooldownExpiresAndTheFeedIsTriedAgain(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, offline: true)->get();
		$this->requested = [];

		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE + 301, offline: true)->get();

		self::assertNotSame([], $this->requested, 'past COOLDOWN the feed must be asked again — recovery stays automatic');
	}

	/**
	 * The sondeo asks through the freshness window, not around it: it exists
	 * to DETECT, and reusing the reader's rule is what made an outage report
	 * itself as silence (#75).
	 */
	public function testTheProbeAsksEvenWhenTheCachedCopyIsStillFresh(): void {
		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$this->requested = [];

		self::assertTrue($repository->probe());
		self::assertCount(1, $this->requested, 'a detector that asks nothing detects nothing');
	}

	public function testTheProbeReportsFailureWhenTheFeedStopsParsing(): void {
		self::assertFalse($this->repository(body: '<html><body>en mantención</body></html>')->probe());
	}

	public function testTheProbeReportsFailureWhenTheIspIsUnreachable(): void {
		self::assertFalse($this->repository(body: $this->rss([self::WEEK]), offline: true)->probe());
	}

	// --- the brake: an answer is not a challenge -------------------------------

	public function testARefusalArmsTheBrakeAndReadsStopAsking(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();

		$refused = $this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, refused: 403)->get();
		self::assertTrue($refused['degraded'], 'a refusal is served like any outage');
		self::assertCount(2, $this->requested, 'precondition: the refusal was asked once, after expiry');

		// Past COOLDOWN (armed at +7200, gone at +7500), still inside the brake
		// (armed at +7200 until +14400). The cooldown-only code this replaces
		// would have asked here — that ask is what this pin exists to hold.
		$held = $this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + 7501, refused: 403)->get();

		self::assertTrue($held['degraded']);
		self::assertNotSame([], $held['items'], 'the last good copy serves while the brake holds');
		self::assertCount(2, $this->requested, 'a braked read must not ask — a 403 is an answer, not a challenge');
		self::assertSame(1, $this->store['freno-isp-informes']['v']['n'] ?? null, 'one refusal, one count');
	}

	public function testATimeoutStaysCooldownClassAndNeverArmsTheBrake(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();

		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, offline: true)->get();
		self::assertArrayNotHasKey('freno-isp-informes', $this->store, 'no answer came back — there is no refusal to remember');

		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + 7501, offline: true)->get();

		self::assertCount(3, $this->requested, 'past COOLDOWN the feed must be asked again — recovery stays automatic');
	}

	/**
	 * #218beba: a fully-braked reader must still report unreachable to
	 * ProbeJob — asked, not assumed, and the bookkeeping runs through the ask
	 * exactly as on the hourly path.
	 */
	public function testTheProbeAsksThroughTheBrakeAndReportsARefusal(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, refused: 403)->get();
		$this->requested = [];

		$probe = $this->repository(body: $this->rss([self::WEEK]), now: self::NOW + 7260, refused: 403);

		self::assertFalse($probe->probe());
		self::assertCount(1, $this->requested, 'the probe asked through the brake');
		self::assertSame(2, $this->store['freno-isp-informes']['v']['n'] ?? null, 'the ask escalated the count, it did not assume the state');
	}

	public function testAHostThatAnswersAgainClearsTheBrakeSoReadsResume(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, refused: 403)->get();
		self::assertArrayHasKey('freno-isp-informes', $this->store);

		// The daily ask catches the recovery, and the answer clears the key.
		self::assertTrue($this->repository(body: $this->rss([self::WEEK]), now: self::NOW + 7260)->probe());
		self::assertArrayNotHasKey('freno-isp-informes', $this->store, 'an answer removes the key outright — recovery is one step');

		/*
		 * Past the cooldown the refusal left (+7500) AND past the probe's own
		 * freshness write-back: the probe above runs store(), which re-caches
		 * the reading with fetched_at at the probe's clock (NOW+7260, D8 —
		 * the reader's probe has no digest skip by design), so the entry is
		 * fresh until NOW+10860. A read inside that window serves the fresh
		 * entry without asking — which is correct, and which is why the
		 * +7501 this pin was born with (copied from the Minsal analog, whose
		 * probe DOES digest-skip) asserted a fourth fetch that never
		 * happened: 3, not 4. Past both windows, the read resumes asking.
		 */
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + 10861)->get();
		self::assertCount(4, $this->requested, 'the read resumed once the brake cleared');
	}

	/**
	 * The interaction that is easy to get wrong: the brake lifts on ANY answer
	 * — before the parse is judged — so a WAF 200 after a refusal lands in the
	 * cooldown, not back under the brake it just lifted. A WAF block page is
	 * indistinguishable from a restructure, and braking on it would refuse a
	 * healthy host forever.
	 */
	public function testAWafPageAfterARefusalLandsInTheCooldownNotBackUnderTheBrake(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE, refused: 403)->get();

		$waf = $this->repository(body: '<html><body>Attention Required</body></html>', reuseCache: true, now: self::NOW + 7260);

		self::assertFalse($waf->probe());
		self::assertArrayNotHasKey('freno-isp-informes', $this->store, 'the 200 lifted the brake even though the parse failed');
		self::assertNotNull($this->store['cooldown-isp-informes']['v'] ?? null, 'the WAF page lands in the cooldown');
	}

	// --- the one-shot backfill ---------------------------------------------------

	/**
	 * Asserted on the SERVED items, not on request counts: #49's headline
	 * claim was unproven because every backfill test watched the mechanism
	 * instead of the archive it existed to build. The walk's older weeks reach
	 * the pane only through the table read-back, so this is also the pin
	 * that the walk's output serves.
	 */
	public function testAFreshInstallWalksTheWholeSeriesAndRetiresTheWalk(): void {
		$this->pages = [
			2 => $this->rss([['d' => 'Informe circulación virus respiratorios SE33', 'u' => 'https://www.ispch.gob.cl/se33.pdf', 'p' => 'Tue, 18 Aug 2026 12:00:00 +0000']]),
			3 => $this->rss([['d' => 'Informe circulación virus respiratorios SE32', 'u' => 'https://www.ispch.gob.cl/se32.pdf', 'p' => 'Tue, 11 Aug 2026 12:00:00 +0000']]),
		];

		$repository = $this->repository(body: $this->rss([self::WEEK]));

		// The reader's bound (L3-08): a cold get() serves page 1 and asks no walk page —
		// the walk's rows reach the pane only through the NEXT store() read-back.
		self::assertSame([], $this->pagesRequested, 'the reader path triggers no walk pages');
		self::assertSame(['35'], array_column($repository->get()['items'], 'se'), 'first paint is page 1 alone');

		// The deep pass is converge()'s now — the same walk, the same retirement.
		$repository->converge();
		self::assertSame(0, $this->cursor(), 'the 404 past the last page retired the walk');

		$items = $this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE)->get()['items'];

		self::assertSame(['35', '33', '32'], array_column($items, 'se'), 'the walked weeks are in the series');
	}

	public function testARetiredWalkIsNeverWalkedAgain(): void {
		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();
		self::assertSame(0, $this->cursor(), 'precondition: page 2 404ed and retired the walk');

		$this->pagesRequested = [];
		$repository->converge();

		self::assertSame([], $this->pagesRequested, 'a retired walk must cost no page requests');
	}

	public function testAnUnreadablePageIsResumedRatherThanRetiredOrSkipped(): void {
		$this->pagesOffline = true;

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertSame(2, $this->cursor(), 'an unreadable page must not advance the walk');
		self::assertSame(1, $this->stalls(), 'the first stall is counted');

		$repository->converge();

		self::assertContains(2, $this->pagesRequested, 'the walk resumes from the page it could not read');
		self::assertSame(2, $this->cursor(), 'and does not advance past it on a second stall');
	}

	/**
	 * #74's silent stall, on the one-shot cadence: a page that can NEVER be
	 * read would be re-requested on every refresh forever, spending a request
	 * an hour while the series never grows past it. Three tries, then the walk
	 * steps over the page and says so.
	 */
	public function testAPageThatCanNeverBeReadIsSteppedOver(): void {
		$this->pagesOffline = true;

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();
		$repository->converge();
		$repository->converge();

		self::assertGreaterThan(2, $this->cursor(), 'after three stalls the walk steps over the page');
		$warnings = implode(' | ', $this->loggedAt('warning'));
		self::assertStringContainsString('skips it', $warnings, 'and must say it skipped one');
	}

	/**
	 * The ISP's WAF answers 200 with a body that is not RSS. The old backfill
	 * read the same answer as "past the last page" and retired the walk
	 * permanently; here it must stop nothing but the current pass.
	 */
	public function testAWafPageIsNotExhaustion(): void {
		$this->pages = [2 => '<html><body>Attention Required</body></html>'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertSame(2, $this->cursor(), 'a 200 that parses to nothing is not the 404 that retires');
		self::assertSame(1, $this->stalls());
	}

	/**
	 * The front page is the cheap, cached query and the deep page the
	 * expensive uncached one: a deep page's refusal must stop the walk — the
	 * stall path resumes it — without arming the freno the hourly read rides.
	 * This is the ordering the old backfill promised it does not break, now
	 * stated for one feed instead of 24 categories.
	 */
	public function testADeepPageRefusalStopsTheWalkWithoutBrakingTheRead(): void {
		$this->pagesRefused = 403;

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$items = $repository->get()['items'];

		self::assertNotSame([], $items, 'page 1 answered — the deep page is not the read');
		self::assertArrayNotHasKey('freno-isp-informes', $this->store, 'a deep page must not arm the freno');

		$repository->converge();
		self::assertSame(2, $this->cursor(), 'the walk resumes from the same page next refresh');

		$this->requested = [];
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE)->get();
		self::assertNotSame([], $this->requested, 'the read still asks page 1 an hour later');
	}

	/**
	 * LOCK_FOR was derived from WALK_PAGES × 15 s: a walk longer than its own
	 * lock window would let a concurrent reader co-walk mid-refresh. The
	 * budget holds the walk inside the window; the cursor carries the rest to
	 * the next refresh instead.
	 */
	public function testAWalkLongerThanTheLockWindowContinuesNextRefreshRatherThanOutlivingIt(): void {
		for ($p = 2; $p <= 64; $p++) {
			$this->pages[$p] = $this->rss([['d' => "Informe circulación virus respiratorios SE$p", 'u' => "https://www.ispch.gob.cl/se$p.pdf"]]);
		}

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertCount(63, $this->pagesRequested, 'the walk stopped at its budget');
		self::assertSame(65, $this->cursor(), 'the cursor advanced past every walked page');
		self::assertNotSame(0, $this->cursor(), 'and the walk is not retired — there was more to read');
	}

	// --- one walk at a time ------------------------------------------------------

	public function testASecondReaderServesTheStoredCopyRatherThanItsOwnRefresh(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->requested = [];
		$this->locks['refresh-isp-informes'] = true;   // another request, mid-refresh

		$served = $this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE)->get();

		self::assertSame([], $this->requested, 'one refresh at a time');
		self::assertNotSame([], $served['items'], 'and the reader still gets the series');
		self::assertFalse($served['degraded'], 'someone else refreshing is not an outage');
	}

	/**
	 * With nothing stored — a fresh install; the table survives app updates
	 * now, so this window is once per install rather than per flush — there
	 * is no copy to serve, and answering "no informes" would be the lie the
	 * degraded/empty distinction exists to prevent, so a cold reader
	 * refreshes rather than waits. Since the walk moved to converge()
	 * (L3-08), two of them racing are two bounded page-1 fetches, not two
	 * walks — the exception's old excuse is gone and the behaviour stays.
	 */
	public function testAColdReaderRefreshesWhileAnotherReaderHoldsTheLock(): void {
		$this->locks['refresh-isp-informes'] = true;

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$items = $repository->get()['items'];

		self::assertNotSame([], $items, 'the cold reader serves page 1');
		self::assertSame([], $this->pagesRequested, 'the reader path asks no walk page (L3-08)');
		self::assertArrayHasKey(
			'refresh-isp-informes',
			$this->locks,
			'a reader that never took the lock must not release someone else\'s',
		);
	}

	public function testTheLockIsReleasedSoTheNextRefreshRefreshes(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		self::assertArrayNotHasKey('refresh-isp-informes', $this->locks, 'a finished refresh must not leave the lock held');

		$this->requested = [];
		$this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE)->get();
		self::assertNotSame([], $this->requested, 'and the next refresh is free to run');
	}

	// --- the durable series -------------------------------------------------------

	/**
	 * ADR-0012: an app update empties the cache. ADR-0015 §2: the table is
	 * what the flush no longer takes with it. An outage on top of the update
	 * is still an outage — but the series still serves, degraded, from the
	 * persisted copy, claiming no freshness it cannot back.
	 */
	public function testAnAppUpdateThatEmptiesTheCacheStillServesTheSeries(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();

		$after = $this->repository(body: $this->rss([self::WEEK]), reuseCache: false, now: self::NOW + self::STALE, offline: true)->get();

		self::assertTrue($after['degraded'], 'an outage on top of an app update is still an outage');
		self::assertNotSame([], $after['items'], 'the persisted series is the durable last-good copy');
		self::assertNull($after['fetched_at'], 'no freshness is claimed for a copy whose fetch time left with the cache');
		self::assertSame(['35'], array_column($after['items'], 'se'), 'the served copy IS the persisted series');
	}

	/**
	 * The other half of the fallback: the table is not a static museum piece
	 * — one healthy fetch after the flush re-materializes the cache entry
	 * from the table read-back, and costs one request, because the walk is
	 * retired and page 1 is all that is left to ask.
	 */
	public function testATableCopySelfHealsTheCacheInOneFetchAfterAnAppUpdate(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();
		$this->requested = [];

		$after = $this->repository(body: $this->rss([self::WEEK]), reuseCache: false, now: self::NOW + self::STALE)->get();

		self::assertFalse($after['degraded']);
		self::assertNotNull($this->store['isp-informes']['v']['fetched_at'] ?? null, 'the cache re-materialized from the table in one fetch');
		self::assertCount(1, $this->requested, 'one fetch — the reader path is page 1 alone since L3-08; walking is converge()\'s, on the job');
	}

	/**
	 * The storage-fault class, pinned: a fetch that succeeded must serve the
	 * reading — never a degrade, ADR-0015 §2's doctrine — the last copy must
	 * survive it, and the fallback door must serve the WIRE shape: the fresh
	 * reading is parse()-shaped and carries `published_at`, which is the
	 * entity's, not the browser's. This is the one path out that wire() does
	 * not build, so it is the one a leaked field would take.
	 */
	public function testAStorageFailureServesTheReadingThroughTheSameWireShape(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();

		// The database goes away; the feed still answers, now with SE 36.
		$this->storageDown = true;
		$week36 = $this->rss([
			['d' => 'Informe circulación virus respiratorios SE36', 'u' => 'https://www.ispch.gob.cl/se36.pdf', 'p' => 'Tue, 22 Sep 2026 12:00:00 +0000'],
		]);
		$after = $this->repository(body: $week36, reuseCache: true, now: self::NOW + self::STALE)->get();

		self::assertFalse($after['degraded'], 'a storage failure is not a publisher failure');
		self::assertSame(['36', '35'], array_column($after['items'], 'se'), 'the fresh reading serves, accumulated over the last copy');
		self::assertSame('https://www.ispch.gob.cl/documento/semana-36-2026/', $after['items'][0]['url'], 'the no-table door serves the page address too');
		foreach ($after['items'] as $item) {
			self::assertSame(
				['title', 'url', 'year', 'month', 'se'],
				array_keys($item),
				'the fallback door serves the wire shape — published_at is the entity\'s, not the wire\'s',
			);
		}

		$warnings = array_values(array_filter(
				$this->logged,
				static fn (array $l): bool => str_contains($l['message'], 'Could not persist'),
			));
		self::assertCount(1, $warnings, 'the storage fault is named in the log, its own fault class');
	}

	// --- the kept document: ADR-0015 §2 -----------------------------------------

	/**
	 * One fetch, on first sight: the bytes into IAppData, the path and the
	 * sha256 of those bytes on the row, and the wire's href for the row
	 * becomes the app's own route — the publisher's address stays on the row
	 * as provenance. The write ledger and the document ledger both hold
	 * exactly one entry, which is the "once" in "fetched once" (ADR-0007:
	 * actions, not call counts).
	 */
	public function testADocumentIsKeptOnFirstSightAndTheWireNamesTheRoute(): void {
		$bytes = '%PDF-1.4 the week 35 informe, kept by the install';
		$this->docs = [self::WEEK['u'] => $bytes];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		$row = $this->rows['2026-35'];
		self::assertSame('informe-2026-35.pdf', $row->getPath(), 'the document is kept, its name on the row');
		self::assertSame(hash('sha256', $bytes), $row->getHash(), 'and the sha256 of the kept bytes vouches for them');
		self::assertSame([$bytes], array_values($this->appData->files), 'the bytes live in the app\'s own data');
		self::assertSame(['informe-2026-35.pdf'], $this->appData->written, 'written once — fetched once, on first sight');
		self::assertSame([self::WEEK['u']], $this->documents, 'one document request');

		// The route href reaches the pane on the next store() read-back — the
		// one exit the series has (L3-08).
		$items = $this->repository(body: $this->rss([self::WEEK]), reuseCache: true, now: self::NOW + self::STALE)->get()['items'];
		self::assertSame('/apps/epidemiologia/informe/2026/35', $items[0]['url'], 'a kept row\'s href IS the local route');
		self::assertSame(['epidemiologia.informe.show'], $this->routes, 'the href names the route routes.php registers');
	}

	/**
	 * The never-refetch pin: the next nightly pass finds the walk retired
	 * and the row's url standing, so the row is not in the archiver's work
	 * list and the document is not asked for again — the hash matching is
	 * not even a comparison here, it is the ABSENCE of the question
	 * (ADR-0015 §2, "never re-downloading one it has").
	 */
	public function testAKeptDocumentIsNeverRefetched(): void {
		$this->docs = [self::WEEK['u'] => '%PDF-1.4 the week 35 informe'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();
		self::assertSame(['informe-2026-35.pdf'], $this->appData->written);

		$repository->converge();

		self::assertSame(['informe-2026-35.pdf'], $this->appData->written, 'and never re-written');
		self::assertCount(1, $this->documents, 'a row whose document is kept is never re-downloaded');
	}

	/**
	 * An app update empties the cache, not the table (ADR-0012 + ADR-0015
	 * §2), so the kept document must survive it: the table's row carries
	 * the path and the hash through entity()'s find, and the re-materialized
	 * entry serves the route href — all without one document request.
	 */
	public function testAKeptDocumentSurvivesAnAppUpdateWithoutBeingRefetched(): void {
		$this->docs = [self::WEEK['u'] => '%PDF-1.4 the week 35 informe'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();
		self::assertCount(1, $this->documents);

		// The app update: cache namespace moved; the table and the kept file hold.
		$after = $this->repository(body: $this->rss([self::WEEK]), reuseCache: false, now: self::NOW + self::STALE)->get();

		self::assertFalse($after['degraded']);
		self::assertSame('/apps/epidemiologia/informe/2026/35', $after['items'][0]['url'], 'the route href re-materialized from the table');
		self::assertCount(1, $this->documents, 'the kept document survived the flush — no second fetch');
		self::assertSame(['informe-2026-35.pdf'], $this->appData->written);
	}

	/**
	 * The reissue, on the kept copy: a new url for the same week releases the
	 * kept document (the reissue is what a clinician must open — Slice 3's
	 * reissue pin, on the wire), and the archiver re-keeps the new one in
	 * the same pass. One fetch, a hash that changed, the same file name — a
	 * reissue is the same week, not a second row and not a second file.
	 */
	public function testARepublishedWeekReplacesItsKeptDocument(): void {
		$v3 = self::WEEK['u'];
		$v4 = 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-SE35-08-09-2026-V4.pdf';
		$this->docs = [
			$v3 => '%PDF-1.4 the V3 informe',
			$v4 => '%PDF-1.4 the V4 informe',
		];

		$repository = $this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE35 08-09-2026', 'u' => $v3]]));
		$repository->get();
		$repository->converge();
		self::assertSame(hash('sha256', '%PDF-1.4 the V3 informe'), $this->rows['2026-35']->getHash());

		$this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE35 08-09-2026', 'u' => $v4]]), reuseCache: true, now: self::NOW + self::STALE)->get();
		$repository->converge();

		self::assertSame([$v3, $v4], $this->documents, 'the reissue was fetched — the one re-download the hash change is worth');
		self::assertSame(hash('sha256', '%PDF-1.4 the V4 informe'), $this->rows['2026-35']->getHash(), 'the row now vouches for the reissue\'s bytes');
		self::assertSame(['%PDF-1.4 the V4 informe'], array_values($this->appData->files), 'the same name carries the new bytes, not a second file');
		self::assertSame('informe-2026-35.pdf', $this->rows['2026-35']->getPath());
	}

	/**
	 * A document the publisher will not serve is never the feed's failure:
	 * no degraded flag, no freno (the cheap feed read must not pay for the
	 * deep document's refusal — the page() doctrine), and the row keeps the
	 * publisher's own page on the wire until its document can be kept.
	 */
	public function testADocumentRefusalLeavesTheRowLinkedAndDoesNotBrakeTheRead(): void {
		$this->docsRefused = 403;

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$result = $repository->get();
		$repository->converge();

		self::assertFalse($result['degraded'], 'a document that cannot be kept is not the fuente\'s failure');
		self::assertNull($this->rows['2026-35']->getPath(), 'nothing is claimed that was not kept');
		self::assertSame('https://www.ispch.gob.cl/documento/semana-35-2026/', $result['items'][0]['url'], 'the publisher\'s own page answers until the document is kept');
		self::assertArrayNotHasKey('freno-isp-informes', $this->store, 'a document\'s refusal must not arm the read\'s brake');
		self::assertSame([], $this->appData->written, 'and nothing was written');
	}

	/**
	 * The ISP's WAF answers document urls with 200 and an HTML block page —
	 * the exact shape that once got 24 empty pages cached as healthy
	 * (ae98ade). Bytes that do not start with the PDF magic are not the
	 * informe, whatever the url says, and are not kept.
	 */
	public function testAWafPageIsNotKeptAsADocument(): void {
		$this->docs = [self::WEEK['u'] => '<html><body>Attention Required</body></html>'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertNull($this->rows['2026-35']->getPath(), 'an HTML answer is not the informe');
		self::assertSame([], $this->appData->written);
	}

	/** The bound holds for documents too (#130): everything that fetches is bounded. */
	public function testAnOversizeDocumentIsNotKept(): void {
		$this->docs = [self::WEEK['u'] => str_repeat('%PDF-1.4 ', 1_048_577)];   // ~9.4 MB

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertNull($this->rows['2026-35']->getPath(), 'an oversize body is refused, not kept');
		self::assertSame([], $this->appData->written);
	}

	/**
	 * A fresh install's walk names years of weeks at once; the nightly pass
	 * keeps the newest ARCHIVE_BUDGET of them and the rest wait for the next
	 * pass — the current week is always in the first one, and the back
	 * catalogue converges over the job's daily cadence instead of holding
	 * anyone's worker for one long download (L3-08).
	 */
	public function testArchivalIsBoundedPerPassAndContinuesTheNextPass(): void {
		$this->docs = ['https://www.ispch.gob.cl/se40.pdf' => '%PDF-1.4 se40'];

		$weeks = [];
		for ($se = 39; $se >= 30; $se--) {
			$at = self::NOW - 7 * 86400 * (40 - $se);
			$weeks[] = ['d' => "Informe circulación virus respiratorios SE$se", 'u' => "https://www.ispch.gob.cl/se$se.pdf", 'p' => gmdate('D, d M Y H:i:s +0000', $at)];
			$this->docs["https://www.ispch.gob.cl/se$se.pdf"] = "%PDF-1.4 se$se";
		}
		$this->pages = [2 => $this->rss($weeks)];

		$repository = $this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE40', 'u' => 'https://www.ispch.gob.cl/se40.pdf']]));
		$repository->get();
		$repository->converge();

		self::assertCount(10, $this->documents, 'the pass stopped at its budget');
		self::assertCount(10, $this->appData->written);
		self::assertNotNull($this->rows['2026-40']->getPath(), 'the current week is in the first pass');
		self::assertNotNull($this->rows['2026-31']->getPath());
		self::assertNull($this->rows['2026-30']->getPath(), 'the oldest week waited — the work list is newest first');

		$repository->converge();

		self::assertCount(11, $this->documents, 'the next nightly pass finished the list');
		self::assertCount(11, $this->appData->written);
		self::assertNotNull($this->rows['2026-30']->getPath());
	}

	/**
	 * The #74 lesson on the document scale: a row whose document will never
	 * answer must not wedge the pass — attempts spend the budget one unit
	 * each, and the rows behind a dead one are still kept this pass. A
	 * `continue`→`break` mutation on a document failure stays green without
	 * this pin.
	 */
	public function testADeadDocumentDoesNotWedgeThePass(): void {
		// SE 40's document is not armed (unreachable, forever); SE 39's is.
		$this->pages = [2 => $this->rss([['d' => 'Informe circulación virus respiratorios SE39', 'u' => 'https://www.ispch.gob.cl/se39.pdf', 'p' => 'Tue, 08 Sep 2026 12:00:00 +0000']])];
		$this->docs = ['https://www.ispch.gob.cl/se39.pdf' => '%PDF-1.4 se39'];

		$repository = $this->repository(body: $this->rss([['d' => 'Informe circulación virus respiratorios SE40', 'u' => 'https://www.ispch.gob.cl/se40.pdf']]));
		$repository->get();
		$repository->converge();

		self::assertNull($this->rows['2026-40']->getPath(), 'the dead document is not claimed');
		self::assertSame('informe-2026-39.pdf', $this->rows['2026-39']->getPath(), 'the row behind the dead one was kept in the same pass');
		self::assertCount(2, $this->documents, 'both attempts spent the budget, one unit each');
	}

	/**
	 * A fresh install has no 'informes' folder in app data — the first keep
	 * creates it: the real IAppData's getFolder throws until something calls
	 * newFolder, which is the branch folder() exists for, and the fake now
	 * models it (precedent #136: a new fetch path ships its latent defect on
	 * its unexercised branch — this is the fresh install's first pass).
	 */
	public function testAFirstKeepCreatesTheFolderAndKeepsTheDocument(): void {
		$this->appData->withFolder = false;
		$this->docs = [self::WEEK['u'] => '%PDF-1.4 the week 35 informe'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertSame('informe-2026-35.pdf', $this->rows['2026-35']->getPath(), 'the first keep made the folder and kept the document through it');
		self::assertSame(['informe-2026-35.pdf'], $this->appData->written);
	}

	/**
	 * Politeness is not only the feed's: every document fetch names this app,
	 * stays bounded, and verifies against the merged bundle — the same three
	 * the feed read carries (ADR-0015 §4). The document request is the last
	 * one the pass makes, which is how the pin finds its options.
	 */
	public function testEveryDocumentFetchNamesItselfStaysBoundedAndVerifiesAgainstTheMergedBundle(): void {
		$this->docs = [self::WEEK['u'] => '%PDF-1.4 the week 35 informe'];

		$repository = $this->repository(body: $this->rss([self::WEEK]));
		$repository->get();
		$repository->converge();

		self::assertNotSame([], $this->documents, 'precondition: a document was actually fetched');
		$options = $this->options[count($this->options) - 1];
		self::assertSame(
			'APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)',
			$options['headers']['User-Agent'] ?? null,
			'a document request is a request this app makes, and it says who it is',
		);
		self::assertSame(15, $options['timeout'] ?? null, 'bounded like every fetch (#130)');
		self::assertSame(self::BUNDLE, $options['verify'] ?? null, 'the ISP serves only its leaf for documents too');
	}

	// --- trust boundary: the ISP's feed is not ours ------------------------------

	/**
	 * `p()` is htmlspecialchars: it escapes quotes and angle brackets and cannot
	 * make a URL scheme safe. A defaced feed serving a label this parser
	 * accepts would otherwise reach href byte for byte — in the pane, in the
	 * OCS payload and in the initial state.
	 */
	public function testRejectsALinkThatIsNotHttps(): void {
		$feed = $this->rss([
			['d' => 'Informe circulación virus respiratorios SE35', 'u' => 'javascript:alert(1)/uploads/2026/09/x.pdf'],
			['d' => 'Informe circulación virus respiratorios SE36', 'u' => 'https://www.ispch.gob.cl/ok.pdf'],
		]);

		$items = $this->repository(body: $feed)->get()['items'];

		self::assertCount(1, $items);
		self::assertSame('https://www.ispch.gob.cl/documento/semana-36-2026/', $items[0]['url']);

		// Dropped loudly, not quietly: a partial silent loss is the one
		// failure this app is built to avoid.
		$dropped = array_values(array_filter(
				$this->logged,
				static fn (array $l): bool => str_contains($l['message'], 'Dropped'),
			));
		self::assertCount(1, $dropped, 'the reject must be named');
		self::assertSame(1, $dropped[0]['context']['rejected'] ?? null);
	}

	/**
	 * The year is half the identity and it comes from pubDate or not at all:
	 * an informe whose pubDate cannot be read cannot be keyed, and dropping it
	 * silently would be the partial silent loss. Kept unkeyed it would
	 * collide with every other keyless week — so it is dropped, and said.
	 */
	public function testAnInformeWhosePubDateCannotBeReadIsNotKept(): void {
		$feed = $this->rss([['d' => 'Informe circulación virus respiratorios SE35', 'u' => 'https://www.ispch.gob.cl/x.pdf', 'p' => 'not a date at all']]);

		$result = $this->repository(body: $feed)->get();

		self::assertSame([], $result['items'], 'a semana whose year cannot be derived has no identity');
		self::assertTrue($result['degraded']);
		$dropped = array_values(array_filter(
				$this->logged,
				static fn (array $l): bool => str_contains($l['message'], 'Dropped'),
			));
		self::assertSame(1, $dropped[0]['context']['rejected'] ?? null, 'and the drop is named, not silent');
	}

	// --- politeness ----------------------------------------------------------------

	public function testEveryRequestIntroducesItselfAndStaysBounded(): void {
		$this->repository(body: $this->rss([self::WEEK]))->get();

		self::assertSame(
			'APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)',
			$this->options[0]['headers']['User-Agent'] ?? null,
			'every outbound request names this app (ADR-0015 §4)',
		);
		self::assertSame(15, $this->options[0]['timeout'] ?? null, 'the read stays bounded — a gate that blocks silently is not a gate (#130)');
	}

	/**
	 * `www.ispch.gob.cl` serves its leaf certificate and nothing else, so an
	 * instance that verifies against its own anchors alone fails every request
	 * with `cURL error 60`. Asserted on the option, never by relaxing it:
	 * `verify => false` is forbidden in this repo, and a test that accepted it
	 * would be the way it arrived.
	 */
	public function testEveryRequestVerifiesAgainstTheMergedCaBundle(): void {
		$this->pages = [2 => $this->rss([['d' => 'Informe circulación virus respiratorios SE34', 'u' => 'https://www.ispch.gob.cl/se34.pdf']])];

		$this->repository(body: $this->rss([self::WEEK]))->get();

		self::assertNotSame([], $this->options, 'precondition: something was actually requested');
		foreach ($this->options as $options) {
			self::assertSame(self::BUNDLE, $options['verify'] ?? null);
		}
	}

	// --- doubles -------------------------------------------------------------------

	/**
	 * @param string|null $body       served for every page-1 request, instead of the fixture
	 * @param bool        $offline    the host cannot be reached — a TLS fault, a refused
	 *                                connection, a timeout; all arrive as a throw with no
	 *                                answer attached
	 * @param int|null    $now        an explicit clock, past NOW to push a cached copy
	 *                                past its freshness window
	 * @param bool        $reuseCache false gives this repository its own empty cache instead
	 *                                of sharing $this->store with earlier repository() calls
	 *                                in the same test — the app-update tests use it to model
	 *                                ADR-0012's namespace move, and the mapper double keeps
	 *                                sharing $this->rows either way, because the table is
	 *                                what survives the update. The walk cursor and its stall
	 *                                count survive it too: app config, not cache.
	 * @param int|null    $refused    an HTTP status the host answers with instead of a feed —
	 *                                the refusal class, carried by the throwable the way a
	 *                                BadResponseException carries its response
	 */
	private function repository(
		?string $body = null,
		bool $offline = false,
		?int $now = null,
		bool $reuseCache = true,
		?int $refused = null,
	): IspReportRepository {
		if (!$reuseCache) {
			$this->store = [];
		}

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $offline, $refused): IResponse {
				$this->options[] = $options;

				// A walk page is a different request with different rules: no
				// fixture, and 404 past the last page is exhaustion, not
				// failure. Kept out of $this->requested so the read-path
				// assertions stay about the read path.
				if (($page = $this->pageOf($url)) !== null) {
					$this->pagesRequested[] = $page;

					if ($this->pagesRefused !== null) {
						throw new RuntimeException('HTTP ' . $this->pagesRefused . ' returned without a body');
					}
					if ($this->pagesOffline) {
						throw new RuntimeException('cURL error 28: Operation timed out');
					}
					if (!isset($this->pages[$page])) {
						// WordPress 404s past the last page, and the client
						// throws on it — getResponseFromThrowable() below
						// answers the status the production code reads.
						throw new RuntimeException('Client error: `GET …paged=' . $page . '` resulted in a `404 Not Found`');
					}

					$response = $this->createMock(IResponse::class);
					$response->method('getBody')->willReturn($this->pages[$page]);

					return $response;
				}

				/*
				 * A document request: an informe's PDF, not a feed page —
				 * every url the archiver asks for ends in .pdf (the capture
				 * guard pins that), and an armed one is recognized even
				 * without the suffix. Ledgered SEPARATELY from the feed
				 * reads: the never-refetch pins count documents and the
				 * lifecycle pins count page-1 fetches, and one shared ledger
				 * would let each assertion pass for the other's reasons.
				 */
				if (isset($this->docs[$url]) || str_ends_with($url, '.pdf')) {
					$this->documents[] = $url;

					if ($this->docsRefused !== null) {
						throw new RuntimeException('HTTP ' . $this->docsRefused . ' returned without a body');
					}

					if (!isset($this->docs[$url])) {
						// Not armed: the publisher is unreachable for this
						// document. The DEFAULT, deliberately — every
						// reader-slice pin about wire urls was written against
						// rows that never keep a document, and the honest way
						// to keep them is an arm that fails like an outage,
						// not one that silently served feed bytes as a
						// document.
						throw new RuntimeException('cURL error 28: Operation timed out');
					}

					$response = $this->createMock(IResponse::class);
					$response->method('getBody')->willReturn($this->docs[$url]);

					return $response;
				}

				$this->requested[] = $url;

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
		 * timeout read as a refusal — the exact conflation ADR-0015 §4 exists
		 * to remove, smuggled in through the double.
		 *
		 * The walk-page refusal arm is checked first and independent of the
		 * repository's own refusal arm: the deep-page pin exists to exercise
		 * a refusal that carries a response — a mutation making page() brake
		 * on any 4xx must be caught by it, and it can only be caught if the
		 * status actually reads.
		 */
		$client->method('getResponseFromThrowable')->willReturnCallback(
			function (Throwable $e) use ($refused): IResponse {
				if ($this->pagesRefused !== null && str_contains($e->getMessage(), 'HTTP ' . $this->pagesRefused)) {
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn($this->pagesRefused);

					return $response;
				}

				if (str_contains($e->getMessage(), '404')) {
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn(404);

					return $response;
				}

				if ($refused !== null && str_contains($e->getMessage(), "HTTP $refused")) {
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn($refused);

					return $response;
				}

				throw $e;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now ?? self::NOW));
		$cacheFactory->method('createLocking')->willReturn($this->locking());

		/*
		 * The mapper double is the table, state-backed (ADR-0007): upsert
		 * answers what all() and unarchived() read, the (year, se) key
		 * decides, and each reader mirrors its mapper method's own ORDER BY
		 * — so a reissue is assertable as ONE row and SE 1 of two years as
		 * TWO, never as call counts.
		 */
		$mapper = $this->createMock(ReportMapper::class);
		$mapper->method('all')->willReturnCallback(function (): array {
			if ($this->storageDown) {
				throw new RuntimeException('database is gone');
			}

			$reports = array_values($this->rows);
			usort($reports, static fn (Report $a, Report $b): int
				=> [$b->getPublishedAt(), (int)$b->getSe()] <=> [$a->getPublishedAt(), (int)$a->getSe()]);

			return $reports;
		});
		$mapper->method('find')->willReturnCallback(function (string $year, string $se): ?Report {
			if ($this->storageDown) {
				throw new RuntimeException('database is gone');
			}

			return $this->rows[$year . '-' . $se] ?? null;
		});
		$mapper->method('unarchived')->willReturnCallback(function (): array {
			if ($this->storageDown) {
				throw new RuntimeException('database is gone');
			}

			$reports = array_values(array_filter(
				$this->rows,
				static fn (Report $r): bool => $r->getPath() === null,
			));
			usort($reports, static fn (Report $a, Report $b): int
				=> $b->getPublishedAt() <=> $a->getPublishedAt());

			return $reports;
		});
		$mapper->method('upsert')->willReturnCallback(function (Report $report): void {
			if ($this->storageDown) {
				throw new RuntimeException('database is gone');
			}

			$this->rows[$report->getYear() . '-' . $report->getSe()] = $report;
		});

		// The mocked $client answers every request regardless of 'verify', so
		// the path this double returns is never read from disk — only its
		// presence as a request option matters here.
		$bundle = $this->createMock(CertificateBundle::class);
		$bundle->method('path')->willReturn(self::BUNDLE);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			function (string $route, array $params): string {
				// The route name is ledgered: the wire's href for a kept row
				// is this route, and a typo in the const breaks every kept
				// href at once — assert the name in the kept-document pins.
				$this->routes[] = $route;

				return '/apps/epidemiologia/informe/' . $params['year'] . '/' . $params['semana'];
			},
		);

		return new IspReportRepository(
			$clients, $bundle, $time, $this->logger(), $mapper, $this->appConfig(), $urlGenerator, $cacheFactory, $this->appDataFactory($this->appData),
		);
	}

	/**
	 * The one real captured feed body this suite is tested against. An
	 * unmatched url is recorded and asserted in assertPostConditions, NOT
	 * thrown here: this runs inside the client's get(), and request()
	 * catches Throwable — AssertionFailedError included — so a missing
	 * fixture would read as an unreachable host and let a test pass green.
	 * Same reason as MinsalRepositoryTest's copy.
	 */
	private function fixture(string $url): string {
		if (!str_contains($url, 'ispch.gob.cl')) {
			$this->unmatched[] = $url;

			return '';
		}

		return (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.xml');
	}

	/** The walk page number of a URL, or null when the request is page 1. */
	private function pageOf(string $url): ?int {
		return preg_match('/[?&]paged=(\\d+)/', $url, $m) === 1 ? (int)$m[1] : null;
	}

	/**
	 * The synthetic informe item, shaped like the documento feed's own: the
	 * label (d) is what INFORME reads, the PDF (u) rides content:encoded's
	 * anchor, and <link> is the post's own page — /documento/semana-N-2026/,
	 * the publisher's redirecting short-link — never the document, which is
	 * the trap this shape pins the reader against. t (the display title) is
	 * optional: no pin reads it but the one that asserts it.
	 *
	 * @param list<array{d: string, u: string, t?: string, p?: string}> $items
	 * @param string $pubDate the RFC-822 date every item carries unless its own 'p' says otherwise
	 */
	private function rss(array $items, string $pubDate = 'Tue, 15 Sep 2026 12:00:00 +0000'): string {
		$body = '';
		foreach ($items as $item) {
			$chunk = sprintf(
				'<item>%s<link>https://www.ispch.gob.cl/documento/</link><description>%s</description>'
				. '<content:encoded><![CDATA[<p><a href="%s">%s</a></p>]]></content:encoded>'
				. '<pubDate>%s</pubDate></item>',
				// Inside the item: prepending <title> before <item> lands it at channel
				// level, where the parser rightly reads no item title at all.
				isset($item['t']) ? sprintf('<title>%s</title>', $item['t']) : '',
				$item['d'],
				$item['u'],
				$item['d'],
				$item['p'] ?? $pubDate,
			);
			$body .= $chunk;
		}

		return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>' . $body . '</channel></rss>';
	}

	/**
	 * add() is the whole interface that matters: it must set the key ONLY if
	 * absent, and say which happened. A double that always returned true would
	 * prove nothing.
	 */
	private function locking(): IMemcache {
		$lock = $this->createMock(IMemcache::class);
		$lock->method('add')->willReturnCallback(function (string $key): bool {
			if (isset($this->locks[$key])) {
				return false;
			}
			$this->locks[$key] = true;

			return true;
		});
		$lock->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->locks[$key]);

			return true;
		});

		return $lock;
	}

	/**
	 * A logger that records instead of discarding. Failure paths are only
	 * observable as log lines — the payload is byte-identical across fault
	 * classes, and the sentence is the whole of an admin's diagnosis.
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

	/** The walk's position, where the reader keeps it: app config, defaulting to the first unwalked page. */
	private function cursor(): int {
		return (int)($this->config['backfill_page'] ?? 2);
	}

	/** The current page's unreadable-attempt count. */
	private function stalls(): int {
		return (int)($this->config['backfill_stalls'] ?? 0);
	}
}
