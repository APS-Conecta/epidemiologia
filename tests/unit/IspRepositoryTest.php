<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\IspRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ISP half of the app's single test seam.
 *
 * Fixtures are real captured ISP feeds, not synthetic RSS — synthetic markup
 * would only prove the parser can read markup we wrote for it. The overlap
 * between anamed and retiro-de-mercado is genuine and is what the dedupe test
 * asserts on.
 */
class IspRepositoryTest extends TestCase {
	use Doubles;

	/** 2026-08-02T12:00:00Z */
	private const NOW = 1785672000;

	private array $store = [];

	/** @var list<string> terms requested, in order */
	private array $requested = [];

	/** Terms whose feed should throw instead of answering. */
	private array $offline = [];

	/** @var array<string, array<string, string>> request headers seen, per term */
	private array $sentHeaders = [];

	/** Every entry holding archive data — one per category since #47. */
	private function archive(): array {
		return array_filter(
			$this->store,
			static fn (string $k): bool => str_starts_with($k, 'feed-' . IspRepository::ALERTS . '-'),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/** @var list<string> "term:page" for every backfill page requested */
	private array $pagesRequested = [];

	/** Backfill pages throw (unreachable) rather than answering. */
	private bool $pagesOffline = false;

	/** Backfill pages 404 the way WordPress does past the last page. */
	private bool $pagesExhausted = false;

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
		$this->offline = [];
		$this->sentHeaders = [];
		$this->pagesRequested = [];
		$this->pagesOffline = false;
		$this->pagesExhausted = false;
		$this->logged = [];
		$this->locks = [];
	}

	// --- what the ISP actually publishes -------------------------------------

	public function testReadsEveryAlertTheFeedsCarry(): void {
		$alerts = $this->repository()->get();

		self::assertFalse($alerts['degraded']);
		self::assertNotEmpty($alerts['items']);
	}

	public function testEveryAlertCarriesATitleAnHttpsUrlAndADay(): void {
		foreach ($this->repository()->get()['items'] as $item) {
			self::assertNotSame('', $item['title']);
			self::assertStringStartsWith('https://', $item['url']);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
		}
	}

	/**
	 * The ISP files one alert under several categories, so the same link arrives
	 * more than once. Measured across six feeds: 51 slots, 43 unique.
	 */
	public function testTheSameAlertFiledUnderTwoCategoriesAppearsOnce(): void {
		$items = $this->repository()->get()['items'];
		$urls = array_column($items, 'url');

		self::assertSame(array_unique($urls), $urls, 'an alert was listed twice');
	}

	public function testTheFixturesStillOverlapSoTheDedupeIsActuallyExercised(): void {
		// Guards the test above from passing for free if a re-capture happens to
		// contain no duplicates — the same trick MinsalRepositoryTest uses for sort.
		$anamed = $this->linksIn('isp-anamed.xml');
		$retiro = $this->linksIn('isp-retiro.xml');

		self::assertNotEmpty(
			array_intersect($anamed, $retiro),
			'the captured feeds no longer share an alert; re-capture with overlap',
		);
	}

	/**
	 * The categories are the only facet a reader can filter by, and they exist
	 * nowhere in the feed itself — the <category> elements are empty, so the only
	 * source is which feed carried the item. Keeping just the first would discard
	 * the filter before it was ever built.
	 */
	public function testAnAlertKeepsEveryCategoryThatCarriedIt(): void {
		$items = $this->repository()->get()['items'];

		$shared = array_values(array_filter(
			$items,
			static fn (array $i): bool => count($i['categories']) > 1,
		));

		self::assertNotEmpty($shared, 'no alert kept more than one category — the merge is not happening');
		foreach ($items as $item) {
			self::assertNotEmpty($item['categories']);
			self::assertSame(array_unique($item['categories']), $item['categories']);
		}
	}

	public function testEveryCategoryOnAnItemIsOneWeActuallyPolled(): void {
		foreach ($this->repository()->get()['items'] as $item) {
			foreach ($item['categories'] as $category) {
				self::assertContains($category, $this->requested);
			}
		}
	}

	/**
	 * Seven of 168 live titles carry whitespace `trim()` cannot see — a leading U+00A0,
	 * a trailing U+2002. The leading ones render as a visible indent. MinsalRepository has
	 * normalised these since 0.1.0 and says why; this parser only called `trim()`.
	 */
	public function testTitlesCarryNoWhitespaceTrimCannotSee(): void {
		$body = $this->rss([
			['t' => "\u{00A0}Suturas V-Loc 90", 'u' => 'https://www.ispch.gob.cl/a/'],
			['t' => "NOTA INFORMATIVA\u{2002}", 'u' => 'https://www.ispch.gob.cl/b/'],
			['t' => "RETIRO CON\u{00A0}FINALIDAD", 'u' => 'https://www.ispch.gob.cl/c/'],
		]);

		$titles = array_column($this->repository(body: $body)->get()['items'], 'title');

		self::assertContains('Suturas V-Loc 90', $titles);
		self::assertContains('NOTA INFORMATIVA', $titles);
		self::assertContains('RETIRO CON FINALIDAD', $titles);
	}

	public function testSortsNewestFirst(): void {
		$dates = array_column($this->repository()->get()['items'], 'date');
		$sorted = $dates;
		rsort($sorted);

		self::assertSame($sorted, $dates);
	}

	public function testRejectsEveryUrlThatIsNotHttps(): void {
		$hostile = $this->rss([
			['t' => 'javascript', 'u' => 'javascript:alert(1)'],
			['t' => 'plain http', 'u' => 'http://www.ispch.gob.cl/alerta/x/'],
			['t' => 'good', 'u' => 'https://www.ispch.gob.cl/alerta/ok/'],
		]);

		$items = $this->repository(body: $hostile)->get()['items'];

		self::assertCount(1, $items);
		self::assertSame('good', $items[0]['title']);
	}

	// --- the rule that differs from MINSAL -----------------------------------

	/**
	 * The whole reason this class is not a second source inside MinsalRepository.
	 * ISP categories are sparse and which ones are empty moves week to week.
	 */
	public function testAnEmptyFeedIsNormalAndNotDegraded(): void {
		$alerts = $this->repository(body: $this->rss([]))->get();

		self::assertFalse($alerts['degraded'], 'an empty category is an answer, not a fault');
		self::assertSame([], $alerts['items']);
	}

	public function testReachingNoFeedAtAllIsDegraded(): void {
		$alerts = $this->repository(offlineAll: true)->get();

		self::assertTrue($alerts['degraded']);
	}

	public function testOneUnreachableFeedDoesNotDegradeTheRest(): void {
		$alerts = $this->repository(offline: ['anamed'])->get();

		self::assertFalse($alerts['degraded']);
		self::assertNotEmpty($alerts['items']);
	}

	// --- freno ---------------------------------------------------------------

	/**
	 * The point of the freno: a category that failed is not asked again on the very
	 * next hourly refresh. Without this, one dead category is 24 requests an hour
	 * forever, which is how this app walked into a WAF block (#45).
	 */
	public function testAFailedCategoryIsNotRequestedOnTheNextRefresh(): void {
		$this->repository(offline: ['anamed'])->get();

		$this->requested = [];
		$this->repository(now: self::NOW + 3601)->get();

		self::assertNotContains('anamed', $this->requested);
		self::assertContains('retiro-de-mercado', $this->requested, 'only the failing one is braked');
	}

	/**
	 * The detector is not the reader, and reusing the reader's rule made it lie.
	 *
	 * With every category braked — five failed rounds and the backoff is capped at a
	 * day — refresh() attempts nothing, so `$attempted === 0` and degraded is false. The
	 * daily sondeo then reported the ISP healthy, ProbeRecord cleared the consecutive
	 * count AND the failing-since stamp, the notification came off the bell, and Visión
	 * general said every source had been read without problems. For a week.
	 */
	public function testTheProbeSeesAnOutageThroughTheFreno(): void {
		// Five rounds of failure, each spaced past the previous wait: 2 h, 4 h, 8 h, 16 h.
		$t = self::NOW;
		foreach ([0, 7201, 14401, 28801, 57601] as $step) {
			$t += $step;
			$this->repository(offlineAll: true, now: $t)->get();
		}

		$this->requested = [];
		self::assertFalse(
			$this->repository(offlineAll: true, now: $t + 3601)->probe(),
			'every category braked and the host still down is an outage, not silence',
		);
		self::assertNotSame([], $this->requested, 'the detector has to ask to find out');
	}

	/**
	 * One dead category is otherwise invisible: nothing degrades while 23 feeds answer,
	 * the probe stays true, the setup check stays green — and after KEEP_FOR the category
	 * leaves the cache and, since the rail is derived from loaded data, the UI too. The
	 * failures were logged at `debug`, and Nextcloud's default level is `warn`.
	 */
	public function testABrakedCategoryIsNewsAtTheDefaultLogLevel(): void {
		$this->repository(offline: ['anamed'])->get();

		$warnings = array_filter($this->logged, static fn (array $l): bool => $l['level'] === 'warning');
		self::assertNotEmpty($warnings);
		self::assertStringContainsString(
			'anamed',
			implode(' ', array_column($warnings, 'message')),
		);
	}

	/**
	 * The only line an admin diagnoses an outage from said "24 intentos" whatever the
	 * freno had left to attempt. refresh() keeps `$attempted` separate from count(TERMS)
	 * precisely because they differ, then logged the constant.
	 */
	public function testTheOutageLineSaysHowManyWereActuallyAsked(): void {
		// One round of failure brakes all 24, so the next round attempts none of them and
		// cannot report an outage at all — the case CONTEXT.md's Freno entry names.
		$this->repository(offlineAll: true)->get();
		$this->logged = [];

		$this->repository(offlineAll: true, now: self::NOW + 3601)->get();

		$outage = array_filter(
			$this->logged,
			static fn (array $l): bool => str_contains($l['message'], 'No se pudo contactar'),
		);
		self::assertSame([], $outage, 'nothing was asked, so nothing can be reported as unreachable');
	}

	public function testAnsweringClearsTheFreno(): void {
		$this->repository(offline: ['anamed'])->get();
		$this->repository(now: self::NOW + 3601 + 7200)->get();   // freno expired, anamed answers

		$this->requested = [];
		$this->repository(now: self::NOW + 3601 + 7200 + 3601)->get();

		self::assertContains('anamed', $this->requested, 'a success must reset the count, not halve it');
	}

	/**
	 * CONTEXT.md, Freno: a braked request is not an unreachable one. If every
	 * category is braked the app has learned nothing this round — that is not an
	 * outage, and reporting one would make the banner lie.
	 */
	public function testEveryCategoryBrakedIsNotAnOutage(): void {
		$this->repository()->get();                                   // a good archive first
		$this->repository(offlineAll: true, now: self::NOW + 3601)->get();  // all fail -> all braked

		// Past the 300s outage cooldown that get() short-circuits on, still inside the
		// 2h frenos. Testing the freno means reaching refresh() at all.
		$this->requested = [];
		$alerts = $this->repository(now: self::NOW + 3601 + 400)->get();

		self::assertSame([], $this->requested, 'every category should be braked');
		self::assertFalse($alerts['degraded']);
		self::assertNotEmpty($alerts['items'], 'the archive still stands');
	}

	// --- what leaves the repository ------------------------------------------

	/**
	 * The ISP is a Chilean publisher and prints a Chilean date. `at` is a correct
	 * instant, but rendering it in the server's UTC moves anything published after
	 * 20:00 Santiago to the next day — measured on the live archive 2026-08-05, one
	 * alert of 173 displayed a day late.
	 */
	public function testTheDayIsThePublishersDayNotTheServers(): void {
		// 2026-08-04T02:30:00Z — still 3 August in Santiago (UTC-4).
		$feed = $this->rss([self::ALERT], 'Tue, 04 Aug 2026 02:30:00 +0000');

		$items = $this->repository(body: $feed)->get()['items'];

		self::assertSame('2026-08-03', $items[0]['date']);
	}

	/**
	 * The revision fingerprint is how union() spots a rewritten alert. It is a cache
	 * concern; nothing renders it, and an internal field that rides out to every
	 * browser is one nobody can change later.
	 */
	public function testTheRevisionFingerprintNeverLeavesTheRepository(): void {
		$items = $this->repository()->get()['items'];

		self::assertNotEmpty($items);
		foreach ($items as $item) {
			self::assertArrayNotHasKey('h', $item);
		}
	}

	/** But it must still be kept, or every alert reads as revised on the next pass. */
	public function testTheFingerprintIsStillStoredSoRevisionsAreStillSeen(): void {
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'lotes A']]))->get();

		$items = $this->repository(
			body: $this->rss([self::ALERT + ['d' => 'lotes A y B']]),
			now: self::NOW + 3601,
		)->get()['items'];

		self::assertSame('2026-08-02', $items[0]['updated']);
	}

	/**
	 * The archive accretes and union() keeps what it holds, so an alert stored before
	 * the timezone fix would have kept its wrong day forever — and re-fetching 1.423
	 * of them is what the WAF blocks a machine for. `at` is stored, so the day is
	 * derived on the way out and every item is corrected without a single request.
	 */
	public function testAnAlreadyStoredAlertGetsTheRightDayWithoutRefetching(): void {
		// An entry written by an older version: correct instant, day rendered in UTC.
		$at = 1785810600;   // 2026-08-04T02:30:00Z — 3 August in Santiago
		$this->store['feed-' . IspRepository::ALERTS . '-anamed'] = ['v' => [
			'items' => [['title' => 'vieja', 'url' => 'https://x/v', 'at' => $at, 'date' => '2026-08-04']],
			'etag' => '', 'modified' => '', 'fetched_at' => self::NOW,
		], 'exp' => null];

		$items = $this->repository()->get()['items'];

		self::assertSame('2026-08-03', $items[0]['date']);
	}

	// --- revisions -----------------------------------------------------------

	/** The alert as first published, then the same URL with lots added. */
	private const ALERT = ['t' => 'Retiro de lote', 'u' => 'https://www.ispch.gob.cl/alerta/x/'];

	public function testARevisedAlertIsReplacedInPlaceAndMarked(): void {
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'lotes A']]))->get();

		$items = $this->repository(
			body: $this->rss([self::ALERT + ['d' => 'lotes A y B']]),
			now: self::NOW + 3601,
		)->get()['items'];

		self::assertCount(1, $items, 'a revision is not a second row');
		self::assertSame('2026-08-02', $items[0]['updated'], 'marked with the day it was seen revised');
	}

	/**
	 * Its place in the list is the point: moving a revised 2024 alert to the top
	 * would present it as new, which is a different lie from the one being fixed.
	 */
	public function testARevisedAlertKeepsItsPublicationDate(): void {
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'lotes A']]))->get();
		$before = $this->repository()->get()['items'][0]['date'];

		$items = $this->repository(
			body: $this->rss([self::ALERT + ['d' => 'lotes A y B']]),
			now: self::NOW + 3601,
		)->get()['items'];

		self::assertSame($before, $items[0]['date']);
	}

	public function testAnUnrevisedAlertIsNeverMarked(): void {
		$feed = $this->rss([self::ALERT + ['d' => 'lotes A']]);
		$this->repository(body: $feed)->get();

		$items = $this->repository(body: $feed, now: self::NOW + 3601)->get()['items'];

		self::assertArrayNotHasKey('updated', $items[0], 'unchanged content must not re-mark');
	}

	/**
	 * The marker is a day, and a day belongs to a timezone.
	 *
	 * `date('Y-m-d')` uses the container's zone — UTC — three lines below a comment
	 * explaining why that is wrong, and between two conversions that get it right. And
	 * unlike `date`, `updated` is stored already rendered, so present() cannot correct it
	 * on the way out: a revision seen after 20:00 in Chile was labelled TOMORROW, in the
	 * pill a clinician reads.
	 */
	public function testTheMarkerCarriesThePublishersDayNotTheServersOne(): void {
		// 2026-08-06T02:00:00Z is still 2026-08-05 in Santiago, four hours behind.
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'a']]))->get();

		$items = $this->repository(
			body: $this->rss([self::ALERT + ['d' => 'b']]),
			now: 1785981600,
		)->get()['items'];

		self::assertSame('2026-08-05', $items[0]['updated']);
	}

	/** A second revision moves the marker; it is not frozen at the first one. */
	public function testTheMarkerCarriesTheLatestRevisionDate(): void {
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'a']]))->get();
		$this->repository(body: $this->rss([self::ALERT + ['d' => 'b']]), now: self::NOW + 3601)->get();

		$items = $this->repository(
			body: $this->rss([self::ALERT + ['d' => 'c']]),
			now: self::NOW + 86400 * 3,
		)->get()['items'];

		self::assertSame('2026-08-05', $items[0]['updated']);
	}

	// --- backfill ------------------------------------------------------------

	public function testBackfillWalksAtMostTheBudgetPerRun(): void {
		$this->repository()->get();

		self::assertCount(2, $this->pagesRequested, 'BACKFILL_PAGES is the whole leash');
	}

	public function testASuccessfulPageAdvancesThatCategorysCursor(): void {
		$this->repository()->get();

		self::assertNotEmpty($this->pagesRequested);
		self::assertSame(3, $this->cursor($this->pagesRequested[0]), 'page 2 fetched -> cursor now 3');
	}

	/**
	 * The criterion that keeps a blip from skipping a page forever: a failed page is
	 * retried next run, so the cursor must not move.
	 */
	public function testAFailedPageDoesNotAdvanceTheCursor(): void {
		$this->pagesOffline = true;

		$this->repository()->get();

		self::assertNotEmpty($this->pagesRequested);
		self::assertSame(2, $this->cursor($this->pagesRequested[0]), 'still page 2, to be retried');
	}

	/**
	 * WordPress 404s past the last page. That is exhaustion, not failure — braking a
	 * healthy feed at the end of its own archive would be the opposite of the point.
	 */
	public function testAnExhaustedCategoryRetiresItsCursorAndIsNeverWalkedAgain(): void {
		$this->pagesExhausted = true;

		$this->repository()->get();
		$retired = $this->pagesRequested[0];
		self::assertSame(0, $this->cursor($retired));

		/*
		 * Twenty-four passes, not one. Checking only the next hour passed against a
		 * cursor that was NOT retired at all: the rotation moves one category per hour,
		 * so the category walked this hour is not due again for a day either way. A
		 * mutation dropping the cursor from the hourly write went green on it.
		 */
		$this->pagesRequested = [];
		for ($h = 1; $h <= 24; $h++) {
			$this->repository(now: self::NOW + $h * 3601)->get();
		}

		self::assertNotContains($retired, $this->pagesRequested);
	}

	/**
	 * The property the other four tests here do not assert.
	 *
	 * They count requests, check the cursor moved, check it did not, and assert a count
	 * that cannot fall — all about the MECHANISM. Replacing the union at the write with
	 * `'items' => $items` left every one of them green, so #49's headline claim, that the
	 * archive assembles itself, was unproven.
	 */
	public function testAnAlertOnlyOnAnOlderPageIsAddedToTheArchiveAndDisplacesNothing(): void {
		$old = $this->rss([['t' => 'Retiro de 2019', 'u' => 'https://www.ispch.gob.cl/alerta-2019/']]);

		$this->repository(paged: $old)->get();

		/*
		 * Asserted on the walked category's entry, not on get(). Two weaker versions were
		 * written first and BOTH survived replacing the union with the page:
		 *
		 *   - "the older alert is in get()" — it is, either way; replacing also leaves it.
		 *   - "get() returns one more item" — merge() dedupes by URL across the 24
		 *     categories, and every fixture term but two serves the same file, so losing
		 *     one category's copy of a shared URL changes no total.
		 *
		 * Per-category membership is what union() decides, and merge() is exactly what
		 * hides it. So the entry is the surface to assert on.
		 */
		$entry = $this->store['feed-' . IspRepository::ALERTS . '-' . $this->pagesRequested[0]]['v'];
		$urls = array_column($entry['items'], 'url');

		self::assertContains('https://www.ispch.gob.cl/alerta-2019/', $urls, 'the older page is added');
		self::assertGreaterThan(1, count($urls), 'and does not take the hourly window\'s place');
	}

	/**
	 * A bookmark must not outlive the book.
	 *
	 * The cursor used to live in IAppConfig so a flush would not re-pay the walk. But a
	 * flush takes the ARCHIVE: every completed category then read "already done" while
	 * holding only the ten items the hourly pass refilled, and no path ever fetched the
	 * other ~1.180 again. Nothing logged it and nothing degraded — the Año menu simply
	 * stopped offering the years it had lost.
	 */
	public function testACompletedWalkWhoseArchiveIsGoneStartsOver(): void {
		$this->pagesExhausted = true;
		$this->repository()->get();
		$retired = $this->pagesRequested[0];
		self::assertSame(0, $this->cursor($retired), 'precondition: fully walked');

		$this->store = [];   // the flush
		$this->pagesExhausted = false;
		$this->pagesRequested = [];

		// The rotation moves ONE category per hour and walks two, so consecutive runs
		// overlap: N runs reach N+1 categories, not 2N. Twenty-four to be sure of
		// covering all of them whichever one this run happened to start on.
		for ($h = 1; $h <= 24; $h++) {
			$this->repository(now: self::NOW + $h * 3601)->get();
		}

		self::assertContains($retired, $this->pagesRequested, 'the walk starts over');
	}

	/**
	 * The ISP's WAF answers 200 with a page that is not RSS — the class docblock records
	 * it and refresh() guards it. backfill() read the same answer as "past the last page"
	 * and retired the category FOREVER; twenty-four such answers over half a day stop the
	 * archive for good, recoverable only with occ.
	 */
	public function testAnUnreadablePageDoesNotRetireTheCategory(): void {
		$this->repository(paged: '<html><body>Attention Required</body></html>')->get();

		self::assertNotEmpty($this->pagesRequested);
		self::assertSame(2, $this->cursor($this->pagesRequested[0]), 'unreadable is not exhausted');
	}

	/**
	 * backfill()'s docblock promises it "cannot empty a category, cannot set degraded, and
	 * does not touch freshness", and ADR-0010 leans on that ordering. The freno broke it
	 * across runs, not within one: a deep page is the uncached, slow query, so the archive
	 * walk timing out silenced the cached front feed the hourly pass depends on.
	 */
	public function testAFailingBackfillPageDoesNotSilenceTheHourlyFeed(): void {
		$this->pagesOffline = true;
		$this->repository()->get();

		$this->requested = [];
		$this->repository(now: self::NOW + 3601)->get();

		self::assertCount(24, $this->requested, 'every category still asked the next hour');
	}

	/**
	 * #74 removed the brake from the backfill, which was right — it gated the HOURLY
	 * fetch — but it left `null` with no counter at all. A page that can never be read
	 * (over the size cap, every item failing the https guard, a permanently broken
	 * response) is then re-requested on every rotation forever, spending budget, and that
	 * category's archive never grows past it again. Silently: nothing degrades.
	 *
	 * A permanent wrong retire was swapped for a permanent silent stall. Three tries and
	 * the walk steps over the page, saying so.
	 */
	public function testAPageThatCanNeverBeReadIsSteppedOverRatherThanRetriedForever(): void {
		// Thirty-six hours, not twenty-four. Measured: the rotation reaches each category
		// exactly twice a day, so three consecutive failures take a day and a half — which
		// is what the threshold's comment claims and what a 24-hour run could not reach.
		for ($h = 1; $h <= 36; $h++) {
			$this->repository(paged: '<html>not a feed</html>', now: self::NOW + $h * 3601)->get();
		}

		$cursors = array_map(fn (string $t): int => $this->cursor($t), array_unique($this->pagesRequested));
		self::assertNotEmpty($cursors);
		self::assertGreaterThan(2, max($cursors), 'the walk must get past a page it cannot read');

		$warnings = implode(' ', array_column(
			array_filter($this->logged, static fn (array $l): bool => $l['level'] === 'warning'),
			'message',
		));
		self::assertStringContainsString('backfill', $warnings, 'and must say it skipped one');
	}

	public function testBackfillNeverDegradesAndNeverLosesAnAlert(): void {
		$before = $this->repository()->get();

		$this->pagesOffline = true;
		$after = $this->repository(now: self::NOW + 3601)->get();

		self::assertFalse($after['degraded'], 'a failed backfill page is not an outage');
		self::assertGreaterThanOrEqual(count($before['items']), count($after['items']));
	}

	// --- one fan-out at a time -----------------------------------------------

	/**
	 * PageController::index() calls this synchronously and carries no rate limit, so
	 * without a lock every stale page load started its own 24-feed walk at 15 s a feed —
	 * ~390 s of wall clock each, holding a PHP-FPM worker. Eight tabs while the ISP is
	 * slow exhausts the pool for the whole instance, not just this app.
	 *
	 * The cooldown does not cover it: it is armed only when EVERY feed fails, and a
	 * merely slow ISP counts as reached, so it is never set at all.
	 */
	public function testASecondReaderServesTheStoredCopyRatherThanItsOwnFanOut(): void {
		$this->repository()->get();
		$this->requested = [];
		$this->locks['refresh-' . IspRepository::ALERTS] = true;   // another request, mid-walk

		$alerts = $this->repository(now: self::NOW + 3601)->get();

		self::assertSame([], $this->requested, 'one walk at a time');
		self::assertNotEmpty($alerts['items'], 'and the reader still gets the archive');
		self::assertFalse($alerts['degraded'], 'someone else refreshing is not an outage');
	}

	/**
	 * With nothing stored there is no copy to serve, and answering "no alerts" would be
	 * the lie the whole degraded/empty distinction exists to prevent. A cold start is
	 * once per instance; the herd this guards against is the hourly one.
	 */
	public function testAColdCacheFetchesEvenWhileAnotherReaderHoldsTheLock(): void {
		$this->locks['refresh-' . IspRepository::ALERTS] = true;

		$alerts = $this->repository()->get();

		self::assertNotEmpty($this->requested);
		self::assertNotEmpty($alerts['items']);
		self::assertArrayHasKey(
			'refresh-' . IspRepository::ALERTS,
			$this->locks,
			'a reader that never took the lock must not release someone else\'s',
		);
	}

	public function testTheLockIsReleasedSoTheNextHourRefreshes(): void {
		// Three calls, not two. The FIRST runs on a cold cache and therefore never takes
		// the lock at all — so a two-call version passed against a lock that was taken and
		// never released, which is the failure this test is named for.
		$this->repository()->get();
		$this->repository(now: self::NOW + 3601)->get();
		$this->requested = [];

		$this->repository(now: self::NOW + 7202)->get();

		self::assertNotEmpty($this->requested, 'a finished walk must not leave the lock held');
	}

	/**
	 * `timeout` was the only bound on a response. The ISP is a public government
	 * WordPress: a compromise or a broken plugin serving 100 MB of XML fatals the worker
	 * inside a page render, and below that threshold it is worse — union() writes all of
	 * it into the archive, which never evicts, and every page load then embeds it.
	 */
	public function testAResponseTooBigToBeAFeedIsNotRead(): void {
		$huge = '<?xml version="1.0"?><rss version="2.0"><channel>'
			. str_repeat('<item><title>x</title><link>https://www.ispch.gob.cl/a/</link></item>', 40000)
			. '</channel></rss>';

		$alerts = $this->repository(body: $huge)->get();

		self::assertSame([], $alerts['items'], 'nothing that large is a feed we can trust');
		self::assertTrue($alerts['degraded'], 'unread is not the same as an empty archive');
		self::assertSame([], $this->archive(), 'and must not be cached as one for thirty days');
	}

	// --- one door out --------------------------------------------------------

	/**
	 * present() calls itself "the one place items leave the repository", and search()
	 * made that false: it merged the stored entries straight out, so the global search
	 * box skipped both the `h` strip and the date re-derivation. An alert stored before
	 * 0.5.0 showed its UTC day there and its Santiago day in the pane.
	 */
	public function testSearchLeavesThroughTheSameDoorAsThePane(): void {
		$this->repository()->get();

		// The archive as it was written before present() derived days on read.
		$key = 'feed-' . IspRepository::ALERTS . '-anamed';
		$this->store[$key]['v']['items'] = [[
			'title' => 'Retiro de un lote',
			'url' => 'https://www.ispch.gob.cl/alerta/x/',
			'date' => '2026-08-06',           // the UTC day, wrong
			'at' => 1785981600,               // the instant, right: 2026-08-05 in Santiago
			'categories' => ['anamed'],
			'h' => 'deadbeef1234',
		]];

		$hits = $this->repository(now: self::NOW + 60)->search('retiro de un lote');

		self::assertCount(1, $hits);
		self::assertArrayNotHasKey('h', $hits[0], 'the fingerprint must not leave');
		self::assertSame('2026-08-05', $hits[0]['date'], 'the same day the pane shows');
	}

	/**
	 * The scheme guard is at ingest only, on a store that never evicts and whose every
	 * write refreshes KEEP_FOR — so one bad entry is trusted permanently, and AlertRow
	 * binds `url` to href with no guard of its own. One line at the funnel covers all
	 * four renderers and the search provider.
	 */
	public function testAnItemWhoseUrlIsNotHttpsNeverLeaves(): void {
		$this->repository()->get();

		$key = 'feed-' . IspRepository::ALERTS . '-anamed';
		$this->store[$key]['v']['items'] = [
			['title' => 'malo', 'url' => 'javascript:alert(1)', 'date' => '2026-08-01', 'at' => 1785000000, 'categories' => ['anamed']],
			['title' => 'bueno', 'url' => 'https://www.ispch.gob.cl/alerta/ok/', 'date' => '2026-08-01', 'at' => 1785000000, 'categories' => ['anamed']],
		];

		$urls = array_column($this->repository(now: self::NOW + 60)->get()['items'], 'url');

		self::assertNotContains('javascript:alert(1)', $urls);
		self::assertContains('https://www.ispch.gob.cl/alerta/ok/', $urls);
	}

	// --- retention -----------------------------------------------------------

	/**
	 * The defect a per-category store exists to remove.
	 *
	 * A global newest-first cut lets the busy categories starve the quiet ones. Measured
	 * live on 2026-08-04: the cut landed on 2025-12-12 and **10 of the 24 categories held
	 * nothing newer**, so each would have rendered empty while genuinely holding alerts.
	 * An ISP alert does not expire — a falsified batch from 2017 is still falsified — so
	 * the trimmed ones were not stale, merely outnumbered.
	 */
	public function testABusyCategoryDoesNotStarveAQuietOne(): void {
		$alerts = $this->repository(bodies: $this->crowded())->get();

		$titles = array_column($alerts['items'], 'title');

		self::assertContains('AVISO ANTIGUO PERO VIGENTE', $titles, 'the quiet category was crowded out');
		self::assertGreaterThan(60, count($alerts['items']), 'the archive is still being capped');
	}

	public function testEveryCategoryKeepsItsOwnEntry(): void {
		$this->repository(bodies: $this->crowded())->get();

		$keys = array_keys($this->store);
		self::assertGreaterThan(1, count($keys), 'the whole archive is still in one entry');
		self::assertNotContains(
			'feed-' . IspRepository::ALERTS,
			$keys,
			'the single merged entry is still being written',
		);
		self::assertNotEmpty($this->archive(), 'no per-category entry was written');
	}

	/**
	 * A feed is a ten-item window onto an archive of at least 1.423, and an ISP alert
	 * never expires. Replacing a category with whatever its window shows today would
	 * throw the rest away on every hourly refresh — so the archive has to accrete.
	 */
	public function testACategoryKeepsAlertsThatHaveFallenOutOfItsWindow(): void {
		$first = ['t' => 'ALERTA DE AYER', 'u' => 'https://www.ispch.gob.cl/alerta/ayer/'];
		$later = ['t' => 'ALERTA DE HOY', 'u' => 'https://www.ispch.gob.cl/alerta/hoy/'];

		$this->repository(body: $this->rss([$first]))->get();
		// An hour on, the window has moved and no longer shows the older alert.
		$after = $this->repository(body: $this->rss([$later]), now: self::NOW + 7200)->get();

		$titles = array_column($after['items'], 'title');
		self::assertContains('ALERTA DE HOY', $titles);
		self::assertContains('ALERTA DE AYER', $titles, 'the archive was replaced by the window');
	}

	/**
	 * An entry left by an older version must be ignored, never half-read.
	 *
	 * Reading one half-way is what turned `$stored['fetched_at'] + FRESH_FOR` into an
	 * undefined-key warning coerced to 3600 — an entry that quietly claimed an age.
	 */
	public function testACacheEntryInTheOldShapeIsIgnoredRatherThanMisread(): void {
		// The 0.4.x shape: no 'items', and a stamp from long ago. Both halves matter —
		// the stamp is what a half-read entry would contribute to the archive's age.
		$this->store['feed-' . IspRepository::ALERTS . '-anamed'] =
			['v' => ['reached' => 24, 'fetched_at' => self::NOW - 999999], 'exp' => null];

		// anamed stays unreachable, so the malformed entry is not simply overwritten by
		// the refresh — which is the only situation in which a half-read one can be seen.
		$alerts = $this->repository(offline: ['anamed'], body: $this->rss([
			['t' => 'ALERTA NUEVA', 'u' => 'https://www.ispch.gob.cl/alerta/nueva/'],
		]))->get();

		self::assertFalse($alerts['degraded']);
		self::assertSame(['ALERTA NUEVA'], array_column($alerts['items'], 'title'));
		self::assertSame(
			self::NOW,
			$alerts['fetched_at'],
			'a malformed entry was allowed to speak for the archive\'s freshness',
		);
	}

	/**
	 * Seven categories publishing ten alerts each, and one publishing a single old
	 * one — 71 in total, so a 60-item cut has to drop something.
	 *
	 * @return array<string,string>
	 */
	private function crowded(): array {
		$bodies = [];
		$busy = ['anamed', 'retiro-de-mercado', 'dispositivos-medicos', 'farmacovigilancia',
			'advertencia-de-seguridad', 'nota-informativa', 'alertas-cosmeticas'];
		foreach ($busy as $term) {
			$items = [];
			for ($i = 0; $i < 10; $i++) {
				$items[] = ['t' => strtoupper($term) . " $i", 'u' => "https://www.ispch.gob.cl/alerta/$term-$i/"];
			}
			$bodies[$term] = $this->rss($items);
		}
		// Old, and the only thing this category has ever published.
		$bodies['informe-tecnico'] = $this->rss([[
			't' => 'AVISO ANTIGUO PERO VIGENTE',
			'u' => 'https://www.ispch.gob.cl/alerta/aviso-antiguo/',
		]], 'Tue, 01 Feb 2017 10:00:00 +0000');

		// Everything else answers with nothing, which for the ISP is normal.
		foreach (self::EVERY_OTHER_TERM as $term) {
			$bodies[$term] ??= $this->rss([]);
		}

		return $bodies;
	}

	/** The 16 categories this fixture does not give content to. */
	private const EVERY_OTHER_TERM = [
		'alerta-falsificado-producto-sin-registro', 'producto-sin-registro',
		'retiro-del-mercado-dispositivo-medico', 'dispositivo-medico-falsificado',
		'cese-de-utilizacion-dp', 'nota-informativa-dp',
		'nota-informativa-cosmetovigilancia', 'vigilancia-de-cosmeticos',
		'desinfectantes-y-sanitizantes', 'falsificados-sin-registro-sanitario-desinfectantes',
		'informacion-de-seguridad', 'actualizacion-de-seguridad', 'cese-de-distribucion',
		'actualizacion-de-software', 'robo-de-dispositivos-medicos', 'robo-hurto',
	];

	// --- conditional requests ------------------------------------------------

	/**
	 * Measured against the live host on 2026-08-04: the feeds serve both validators
	 * and honour them, answering `304` with an empty body — 9.708 bytes to nothing.
	 * The app re-downloaded all 24 every hour, almost all of it byte-identical to
	 * what it already held.
	 */
	public function testAsksOnlyForWhatChangedOnceItHasSeenAFeed(): void {
		$this->repository(validators: ['etag' => '"abc"', 'last-modified' => 'Tue, 04 Aug 2026 16:12:54 GMT'])->get();

		$this->repository(now: self::NOW + 7200)->get();

		$headers = $this->sentHeaders['anamed'];
		self::assertSame('"abc"', $headers['If-None-Match'] ?? null);
		self::assertSame('Tue, 04 Aug 2026 16:12:54 GMT', $headers['If-Modified-Since'] ?? null);
	}

	public function testTheFirstEverRequestForAFeedIsNotConditional(): void {
		$this->repository()->get();

		$headers = $this->sentHeaders['anamed'];
		self::assertArrayNotHasKey('If-None-Match', $headers);
		self::assertArrayNotHasKey('If-Modified-Since', $headers);
	}

	/** A 304 is "still valid", which is neither an empty feed nor a failure. */
	public function testAnUnchangedFeedKeepsItsAlertsAndStaysHealthy(): void {
		$before = $this->repository(validators: ['etag' => '"abc"'])->get();
		self::assertNotEmpty($before['items']);

		$after = $this->repository(now: self::NOW + 7200, unchanged: ['*'])->get();

		self::assertFalse($after['degraded'], 'every feed answering 304 is the healthy steady state');
		self::assertSame(
			array_column($before['items'], 'url'),
			array_column($after['items'], 'url'),
			'a 304 lost the alerts it was confirming',
		);
	}

	/**
	 * The interaction that is easy to get wrong: #42's guard degrades when every feed
	 * answered with something unparseable. A 304 answers with NOTHING, on purpose, and
	 * must not be counted as unparseable — or the healthy steady state reports an outage.
	 */
	public function testEveryFeedAnsweringUnchangedIsNotReadAsAWafPage(): void {
		$this->repository(validators: ['etag' => '"abc"'])->get();

		$after = $this->repository(now: self::NOW + 7200, unchanged: ['*'])->get();

		self::assertFalse($after['degraded']);
		self::assertNotEmpty($after['items']);
	}

	public function testAConfirmedFeedCountsAsFreshSoItIsNotRefetchedForAnotherHour(): void {
		$this->repository(validators: ['etag' => '"abc"'])->get();
		$this->repository(now: self::NOW + 7200, unchanged: ['*'])->get();
		$this->requested = [];

		// A minute later: the 304 confirmed the copy, so nothing is due.
		$this->repository(now: self::NOW + 7260)->get();

		self::assertSame([], $this->requested, 'a confirmed copy was treated as stale');
	}

	// --- caching and degradation, same contract as MINSAL --------------------

	public function testAnUnreachableIspServesTheLastGoodCopyFlaggedDegraded(): void {
		$before = $this->repository()->get();
		self::assertNotEmpty($before['items']);

		// Past FRESH_FOR, or get() serves the still-fresh copy and never tries the
		// network — which is correct behaviour and would make this assert nothing.
		$after = $this->repository(offlineAll: true, now: self::NOW + 7200)->get();

		self::assertTrue($after['degraded']);
		self::assertSame($before['items'], $after['items']);
	}

	public function testTotalFailureNeverOverwritesTheCache(): void {
		$this->repository()->get();
		$snapshot = $this->archive();

		$this->repository(offlineAll: true, now: self::NOW + 7200)->get();

		// The archive entries, not the whole store: degrading also arms the cooldown
		// that stops the outage being retried on every read, which is a second key.
		self::assertSame($snapshot, $this->archive(), 'the last good copy was overwritten');
	}

	/**
	 * The failure mode this class documents at the top and did not guard: the host's
	 * WAF answers 200 with a body that is not RSS. Every feed is *reached*, so the
	 * reached-count says all is well, and every feed parses to nothing — which used
	 * to be cached as a legitimately empty archive and reported healthy.
	 *
	 * A clinician then reads "no hay alertas" where the truth is "we could not read".
	 */
	public function testAnAnswerWeCannotParseNeverOverwritesTheCache(): void {
		$this->repository()->get();
		$snapshot = $this->archive();
		self::assertNotEmpty($snapshot, 'precondition: a good copy exists');

		$wafPage = '<!DOCTYPE html><html><head><title>Attention Required</title></head>'
			. '<body><h1>Checking your browser…</h1></body></html>';
		$after = $this->repository(body: $wafPage, now: self::NOW + 7200)->get();

		self::assertTrue($after['degraded'], 'a body we cannot parse is a failure, not an empty archive');
		self::assertSame($snapshot, $this->archive(), 'the last good copy was overwritten');
		self::assertNotEmpty($after['items'], 'the reader still gets the last good copy');
	}

	/**
	 * PageController::index() calls all() synchronously, so every failing read is paid
	 * by someone waiting. Degrading writes nothing, so the cache stays stale and the
	 * next request misses again: 24 feeds x 15s timeout each, per page load, per user.
	 * Against a host that blackholes packets rather than refusing them, that is a
	 * gateway timeout instead of the degraded banner this app exists to show.
	 */
	public function testAnOutageIsNotRetriedOnEveryRead(): void {
		$this->repository()->get();
		$this->requested = [];

		// Past FRESH_FOR, so get() genuinely tries the network and fails.
		$this->repository(offlineAll: true, now: self::NOW + 7200)->get();
		self::assertNotSame([], $this->requested, 'precondition: the first read after expiry does try');
		$this->requested = [];

		// A second reader, moments later, must not repeat 24 failing fetches.
		$this->repository(offlineAll: true, now: self::NOW + 7260)->get();

		self::assertSame([], $this->requested, 'the outage was retried instead of being remembered');
	}

	public function testTheCooldownStillServesTheLastGoodCopyAndStillSaysDegraded(): void {
		$before = $this->repository()->get();
		$this->repository(offlineAll: true, now: self::NOW + 7200)->get();

		$during = $this->repository(offlineAll: true, now: self::NOW + 7260)->get();

		self::assertTrue($during['degraded'], 'a cooldown must never look like health');
		self::assertSame($before['items'], $during['items']);
	}

	public function testWithNothingCachedAndTheIspDownItSaysDegradedRatherThanEmpty(): void {
		$alerts = $this->repository(offlineAll: true)->get();

		self::assertTrue($alerts['degraded']);
		self::assertSame([], $alerts['items']);
		self::assertNull($alerts['fetched_at']);
	}

	public function testServesTheCacheWithoutRefetchingWhileItIsFresh(): void {
		$this->repository()->get();
		$firstPass = count($this->requested);
		$this->requested = [];

		$this->repository()->get();

		self::assertSame([], $this->requested, 'a fresh cache must not be refetched');
		self::assertGreaterThan(1, $firstPass, 'the first pass should have polled every term');
	}

	public function testEveryCategoryIsPolledNotJustAnamed(): void {
		$this->repository()->get();

		// anamed caps at 10 like the rest, so polling only it would silently lose
		// whatever the other categories carry.
		self::assertGreaterThan(20, count($this->requested));
		self::assertContains('anamed', $this->requested);
		self::assertContains('robo-hurto', $this->requested);
	}

	public function testTheProbeReportsFailureWhenNoFeedAnswers(): void {
		self::assertFalse($this->repository(offlineAll: true)->probe());
	}

	/**
	 * The probe is the only thing that tells a human, so it has to see the WAF page
	 * as a failure too. Before the guard it reported healthy: every feed answered.
	 */
	public function testTheProbeReportsFailureWhenTheFeedsAnswerWithSomethingUnparseable(): void {
		$this->repository()->get();

		$probe = $this->repository(body: '<html><body>Checking your browser…</body></html>', now: self::NOW + 7200);

		self::assertFalse($probe->probe());
	}

	public function testTheProbeIsHappyWhenTheFeedsAnswer(): void {
		self::assertTrue($this->repository()->probe());
	}

	// --- the global search box -----------------------------------------------

	public function testSearchFindsAnAlertByItsTitle(): void {
		$repository = $this->repository();
		$repository->get();

		$hits = $repository->search('amlodipino');

		self::assertNotEmpty($hits);
		self::assertStringContainsStringIgnoringCase('AMLODIPINO', $hits[0]['title']);
	}

	/**
	 * The reason the category slugs are searched at all: an ISP title names the
	 * product, not what kind of notice it is, so "cosmético" would find nothing if
	 * only titles were matched.
	 */
	public function testSearchFindsAnAlertByItsCategoryWhenTheTitleNeverSaysIt(): void {
		$repository = $this->repository();
		$repository->get();

		$hits = $repository->search('cosmeticas');

		self::assertNotEmpty($hits);
		self::assertStringNotContainsStringIgnoringCase('cosmeticas', $hits[0]['title']);
	}

	/**
	 * Every registered provider runs on every global search, so one that fetched
	 * would put 24 outbound requests in front of someone looking for a Word
	 * document. MinsalRepository::search() refuses for the same reason.
	 */
	public function testSearchNeverFetches(): void {
		$repository = $this->repository();
		$repository->get();
		$warmed = $this->requested;

		$repository->search('amlodipino');

		self::assertSame($warmed, $this->requested);
	}

	public function testSearchOnAColdCacheFindsNothingRatherThanFetching(): void {
		self::assertSame([], $this->repository()->search('amlodipino'));
		self::assertSame([], $this->requested);
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param string|null  $body       served for every term, instead of the fixtures
	 * @param list<string> $offline    terms that should throw
	 * @param bool         $offlineAll every term throws
	 */
	/**
	 * @param array<string,string> $bodies Per-term bodies, for the cases where the
	 *                                     categories must differ from each other —
	 *                                     a busy one crowding out a quiet one cannot
	 *                                     be expressed when all 24 answer the same.
	 */
	private function repository(
		?string $body = null,
		array $offline = [],
		bool $offlineAll = false,
		?int $now = null,
		array $bodies = [],
		array $validators = [],
		array $unchanged = [],
		?string $paged = null,
	): IspRepository {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options = []) use (
				$body, $offline, $offlineAll, $bodies, $validators, $unchanged, $paged,
			): IResponse {
				$term = $this->termOf($url);

				// A backfill page is a different request with different rules: no
				// validators, and 404 past the end means exhausted rather than broken.
				// Kept out of $this->requested so the hourly-pass assertions stay about
				// the hourly pass.
				if (str_contains($url, '?paged=')) {
					$this->pagesRequested[] = $term;
					if ($this->pagesOffline) {
						throw new RuntimeException('cURL error 60: SSL certificate problem');
					}
					if ($this->pagesExhausted) {
						throw new RuntimeException('Client error: 404 Not Found');
					}
					$page = $this->createMock(IResponse::class);
					$page->method('getStatusCode')->willReturn(200);
					/*
					 * An older page is DIFFERENT alerts — that is the whole point of a
					 * backfill. Falling back to the hourly body made every page identical
					 * to the current window, so union() could not add anything and no test
					 * could tell the archive assembling itself from the archive standing
					 * still. Serving fixtures here while the test served something else
					 * made three unrelated tests fail on content they never asked for, so
					 * $paged is explicit: a test that cares says so.
					 */
					$page->method('getBody')->willReturn($paged ?? $bodies[$term] ?? $body ?? $this->fixture($term));
					$page->method('getHeader')->willReturn('');

					return $page;
				}

				$this->requested[] = $term;
				$this->sentHeaders[$term] = $options['headers'] ?? [];

				if ($offlineAll || in_array($term, $offline, true)) {
					throw new RuntimeException('cURL error 60: SSL certificate problem');
				}

				$response = $this->createMock(IResponse::class);

				// A 304 carries no body at all — returning one would let a test pass
				// against code that parsed it anyway.
				if ($unchanged === ['*'] || in_array($term, $unchanged, true)) {
					$response->method('getStatusCode')->willReturn(304);
					$response->method('getBody')->willReturn('');
					$response->method('getHeader')->willReturn('');

					return $response;
				}

				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn($bodies[$term] ?? $body ?? $this->fixture($term));
				$response->method('getHeader')->willReturnCallback(
					static fn (string $h): string => $validators[strtolower($h)] ?? '',
				);

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now ?? self::NOW));
		$cacheFactory->method('createLocking')->willReturn($this->locking());

		return new IspRepository(
			$clients, $time, $this->logger(), $cacheFactory,
		);
	}

	/**
	 * The backfill cursor lives here rather than in the cache precisely so a flush
	 * cannot reset it, so the double must survive independently of $this->store.
	 */
	/** @var array<string, bool> the locking cache's contents — one key, held or not */
	private array $locks = [];

	/** @var list<array{level: string, message: string}> what the code logged, and at what level */
	private array $logged = [];

	/**
	 * Records level and message, because "a dead category is news" is a claim about the
	 * LEVEL: at `debug` — the default until #75 — nothing reaches a default install's log.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => (string)$message . ' ' . json_encode($context)];
				},
			);
		}

		return $logger;
	}

	/**
	 * add() is the whole interface that matters: it must set the key ONLY if absent, and
	 * say which happened. A double that always returned true would prove nothing.
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

	/** The walk's position for one category, where it now lives: beside its own alerts. */
	private function cursor(string $term): int {
		return (int)($this->store['feed-' . IspRepository::ALERTS . '-' . $term]['v']['backfill'] ?? 2);
	}

	/** The category slug is the request's identity here, as the slug is for MINSAL. */
	private function termOf(string $url): string {
		return preg_match('#/categorias-alertas/([^/]+)/#', $url, $m) === 1 ? $m[1] : $url;
	}

	/**
	 * Only three categories were captured. Every other term answers with the
	 * `informe-tecnico` capture, which is the sparse one-item case — so the
	 * dedupe and the sort are exercised without pretending we hold 24 files.
	 */
	private function fixture(string $term): string {
		$file = match ($term) {
			'anamed' => 'isp-anamed.xml',
			'retiro-de-mercado' => 'isp-retiro.xml',
			default => 'isp-informe-tecnico.xml',
		};

		return (string)file_get_contents(__DIR__ . '/fixtures/' . $file);
	}

	/** @return list<string> */
	private function linksIn(string $file): array {
		$xml = simplexml_load_string((string)file_get_contents(__DIR__ . '/fixtures/' . $file));

		// preserve_keys=false: every node is named `item`, so the default keyed form
		// collapses 10 siblings into 1 and the overlap check silently passes on nothing.
		return array_map(static fn ($i): string => trim((string)$i->link), iterator_to_array($xml->channel->item, false));
	}

	/** @param list<array{t: string, u: string}> $items */
	private function rss(array $items, string $pubDate = 'Tue, 28 Jul 2026 20:38:58 +0000'): string {
		$body = '';
		foreach ($items as $item) {
			$body .= sprintf(
				'<item><title>%s</title><link>%s</link><pubDate>%s</pubDate><description>%s</description></item>',
				$item['t'],
				$item['u'],
				$pubDate,
				// The half a revision actually edits: the ISP adds affected lots here and
				// leaves the title alone, so a hash over the title only would never fire.
				$item['d'] ?? '',
			);
		}

		return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>' . $body . '</channel></rss>';
	}
}
