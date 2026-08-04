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
	/** 2026-08-02T12:00:00Z */
	private const NOW = 1785672000;

	/** The one cache key this repository writes, as IspRepository::key() builds it. */
	private const KEY = 'feed-' . IspRepository::ALERTS;

	private array $store = [];

	/** @var list<string> terms requested, in order */
	private array $requested = [];

	/** Terms whose feed should throw instead of answering. */
	private array $offline = [];

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
		$this->offline = [];
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
		$snapshot = $this->store[self::KEY];

		$this->repository(offlineAll: true, now: self::NOW + 7200)->get();

		// The archive key, not the whole store: degrading also arms the cooldown that
		// stops the outage being retried on every read, which is a second key.
		self::assertSame($snapshot, $this->store[self::KEY], 'the last good copy was overwritten');
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
		$snapshot = $this->store[self::KEY];
		self::assertNotEmpty($snapshot['items'], 'precondition: a good copy exists');

		$wafPage = '<!DOCTYPE html><html><head><title>Attention Required</title></head>'
			. '<body><h1>Checking your browser…</h1></body></html>';
		$after = $this->repository(body: $wafPage, now: self::NOW + 7200)->get();

		self::assertTrue($after['degraded'], 'a body we cannot parse is a failure, not an empty archive');
		self::assertSame($snapshot, $this->store[self::KEY], 'the last good copy was overwritten');
		self::assertSame($snapshot['items'], $after['items'], 'the reader still gets the last good copy');
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
	private function repository(
		?string $body = null,
		array $offline = [],
		bool $offlineAll = false,
		?int $now = null,
	): IspRepository {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($body, $offline, $offlineAll): IResponse {
				$term = $this->termOf($url);
				$this->requested[] = $term;

				if ($offlineAll || in_array($term, $offline, true)) {
					throw new RuntimeException('cURL error 60: SSL certificate problem');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body ?? $this->fixture($term));

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache());

		return new IspRepository($clients, $time, $this->createMock(LoggerInterface::class), $cacheFactory);
	}

	private function cache(): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->store[$key] = $value;

			return true;
		});

		return $cache;
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
	private function rss(array $items): string {
		$body = '';
		foreach ($items as $item) {
			$body .= sprintf(
				'<item><title>%s</title><link>%s</link><pubDate>Tue, 28 Jul 2026 20:38:58 +0000</pubDate></item>',
				$item['t'],
				$item['u'],
			);
		}

		return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>' . $body . '</channel></rss>';
	}
}
