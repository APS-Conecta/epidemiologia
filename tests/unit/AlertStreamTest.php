<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use LogicException;
use OCA\Epidemiologia\BackgroundJob\RevalidateJob;
use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OCA\Epidemiologia\Service\AlertRepositories;
use OCA\Epidemiologia\Service\AlertRepository;
use OCA\Epidemiologia\Service\CertificateBundle;
use OCA\Epidemiologia\Service\Parser\FeedParser;
use OCA\Epidemiologia\Service\Parser\PageParser;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The pipeline's stream half, read through the ISP's three alert categories
 * (ADR-0017 D6/D7). AlertRepositoryTest pins everything
 * the fuentes share through degreyd; this suite pins what a stream does
 * differently — it keeps every item it ever read, walks two older pages once
 * for its history, and answers to its host's one brake: the ISP's, shared
 * with the informe.
 *
 * Built through the real AlertRepositories, so each test also pins the
 * stream fuentes' configuration: the addresses (the dispatcher answers those
 * alone), the backfill pages, the bundle, the host. Page 1 of
 * each feed is the owner's capture (2026-09-27); the retired reader's July
 * captures stand in for ANAMED's older pages — its own ANAMED page of that
 * week as ?paged=2, and its retiro-de-mercado page as ?paged=3, which shares
 * six posts with the first: the overlap a page shift between two requests
 * produces, one row each all the same.
 */
class AlertStreamTest extends TestCase {
	use Doubles;

	/** 2026-09-28T12:00:00Z. */
	private const NOW = 1790596800;

	private const BUNDLE = '/fake/ca-bundle.pem';

	private const ANAMED = 'https://www.ispch.gob.cl/categorias-alertas/anamed/feed/';

	private const PHARMACOVIGILANCE = 'https://www.ispch.gob.cl/categorias-alertas/farmacovigilancia/feed/';

	private const COSMETICS = 'https://www.ispch.gob.cl/categorias-alertas/vigilancia-de-cosmeticos/feed/';

	/** What each address answers unless a test says otherwise in $answers. */
	private const FIXTURES = [
		self::ANAMED => 'isp-anamed.xml',
		self::PHARMACOVIGILANCE => 'isp-farmacovigilancia.xml',
		self::COSMETICS => 'isp-vigilancia-de-cosmeticos.xml',
		self::ANAMED . '?paged=2' => 'isp-anamed-2026-07.xml',
		self::ANAMED . '?paged=3' => 'isp-retiro-2026-07.xml',
	];

	private array $store = [];

	/** @var array<string, Alert> the AlertMapper double's table, keyed "fuente url" in insertion order */
	private array $rows = [];

	/** @var array<string, string|int> per-address answers: a body, an HTTP status, or 0 for a timeout */
	private array $answers = [];

	/** @var list<string> */
	private array $requested = [];

	/** @var list<array> every request's options, in order */
	private array $options = [];

	/** @var list<array{0: string, 1: mixed}> */
	private array $jobs = [];

	/** @var list<string> */
	private array $unmatched = [];

	/** @var list<array{level: string, message: string, context: array}> */
	private array $logged = [];

	protected function setUp(): void {
		$this->store = [];
		$this->rows = [];
		$this->answers = [];
		$this->requested = [];
		$this->options = [];
		$this->jobs = [];
		$this->unmatched = [];
		$this->logged = [];
		$this->config = [];
	}

	/** Runs outside the repository's catch, which is the only place this can bite. */
	protected function assertPostConditions(): void {
		self::assertSame([], $this->unmatched, 'a request matched no fixture — add one, or set $answers');
	}

	// --- the three categories ------------------------------------------------

	/**
	 * Each category's feed, read into its own sector — dedupe is within a
	 * sector only, so a post the ISP filed under two categories is a row in
	 * each (ANAMED shares four with Farmacovigilancia in the captures).
	 */
	public function testEachCategoryReadsItsOwnFeedIntoItsOwnSector(): void {
		$counts = [];
		foreach ([Sources::ISP_ANAMED, Sources::ISP_PHARMACOVIGILANCE, Sources::ISP_COSMETICS] as $id) {
			$served = $this->repository($id)->get();
			self::assertFalse($served['degraded'], $id);
			$counts[$id] = count($served['items']);
		}

		self::assertSame([Sources::ISP_ANAMED => 10, Sources::ISP_PHARMACOVIGILANCE => 10, Sources::ISP_COSMETICS => 3], $counts);
		self::assertSame([self::ANAMED, self::PHARMACOVIGILANCE, self::COSMETICS], $this->requested);
		self::assertCount(4, array_intersect($this->urls(Sources::ISP_ANAMED), $this->urls(Sources::ISP_PHARMACOVIGILANCE)),
			'the shared posts are a row in each sector, not one row between them');
	}

	/**
	 * www.ispch.gob.cl serves only its leaf, so every ISP request — page 1 and
	 * the backfill alike — verifies against CertificateBundle's merged anchors,
	 * and names this app.
	 */
	public function testEveryRequestVerifiesAgainstTheBundleAndNamesItself(): void {
		$this->repository()->get();
		$this->repository()->converge();

		self::assertCount(3, $this->options, 'precondition: page 1 and both older pages were asked');
		foreach ($this->options as $options) {
			self::assertSame(self::BUNDLE, $options['verify'] ?? null);
			self::assertSame(15, $options['timeout'] ?? null);
			self::assertStringStartsWith('APS-Conecta-Epidemiologia/', $options['headers']['User-Agent'] ?? '');
		}
	}

	// --- accumulation (D6) ----------------------------------------------------

	/**
	 * A feed lists its ten latest posts, so the ten it listed yesterday are
	 * not all in today's: what it stopped listing is kept, and served. The
	 * first read queued nothing; the stale read queued the revalidation that
	 * brought the new post in.
	 */
	public function testAStreamKeepsWhatItsFeedStopsListing(): void {
		$first = $this->repository()->get();

		$this->answers[self::ANAMED] = $this->rss([['t' => 'ALERTA NUEVA', 'u' => 'https://www.ispch.gob.cl/alerta/nueva/', 'p' => 'Sat, 26 Sep 2026 15:00:00 +0000']]);
		$this->repository(now: self::NOW + 7200)->get();
		self::assertSame([[RevalidateJob::class, ['source' => Sources::ISP_ANAMED]]], $this->jobs);
		$this->repository(now: self::NOW + 7210)->revalidate();
		$served = $this->repository(now: self::NOW + 7220)->get();

		self::assertFalse($served['degraded']);
		self::assertSame(
			['https://www.ispch.gob.cl/alerta/nueva/', ...array_column($first['items'], 'url')],
			array_column($served['items'], 'url'),
			'the new post first, and every post the feed no longer lists still served',
		);
		self::assertSame('2026-09-26', $served['items'][0]['date']);
		self::assertCount(11, $this->urls(Sources::ISP_ANAMED));
	}

	/**
	 * After an app update the cache is gone and the table answers (ADR-0015
	 * §2): a stream's rows carry the publisher's day back out, the same wire
	 * the fresh read served.
	 */
	public function testTheTableServesTheSameWireTheFeedDid(): void {
		$fresh = $this->repository()->get();
		$this->store = [];

		$kept = $this->repository(now: self::NOW + 60)->get();

		self::assertNull($kept['fetched_at'], 'the table claims no freshness it cannot back');
		self::assertSame($fresh['items'], $kept['items']);
		self::assertSame(['title', 'url', 'year', 'month', 'date'], array_keys($kept['items'][0]));
	}

	/**
	 * A storage fault is never an outage, and never shrinks a stream to its
	 * latest page: the reading serves accumulated over the last copy, and the
	 * log names the set that could not be kept.
	 */
	public function testABrokenTableServesTheReadingOverTheLastCopy(): void {
		$first = $this->repository()->get();
		$this->answers[self::ANAMED] = $this->rss([['t' => 'ALERTA NUEVA', 'u' => 'https://www.ispch.gob.cl/alerta/nueva/', 'p' => 'Sat, 26 Sep 2026 15:00:00 +0000']]);

		$this->repository(now: self::NOW + 7200, brokenTable: true)->revalidate();
		$served = $this->repository(now: self::NOW + 7210, brokenTable: true)->get();

		self::assertFalse($served['degraded'], 'the fetch succeeded — a table that fails is logged, never served as an outage');
		self::assertSame(
			['https://www.ispch.gob.cl/alerta/nueva/', ...array_column($first['items'], 'url')],
			array_column($served['items'], 'url'),
		);
		self::assertSame(['Could not persist the ISP ANAMED alerts set'], $this->warnings());
	}

	// --- the one-shot backfill (D7) ---------------------------------------------

	/**
	 * Two older pages, once: every post they carry becomes a row — the six
	 * that page 3 repeats from page 2 once each — the flag goes up, and the
	 * next pass asks nothing. Additive only: the cache still serves page 1
	 * until the next store(), whose read-back brings the history in, where
	 * search finds it.
	 */
	public function testTheBackfillWalksTheOlderPagesOnceThenNeverAgain(): void {
		$this->repository()->get();

		$this->repository(now: self::NOW + 60)->converge();

		self::assertSame([self::ANAMED, self::ANAMED . '?paged=2', self::ANAMED . '?paged=3'], $this->requested);
		self::assertCount(24, $this->urls(Sources::ISP_ANAMED), '10 + 10 + the 4 page 3 does not share with page 2');
		self::assertTrue($this->config['backfilled_isp_anamed'] ?? null);
		self::assertCount(10, $this->repository(now: self::NOW + 120)->get()['items'], 'the walk touched no cache entry');

		$this->repository(now: self::NOW + 180)->converge();
		self::assertCount(3, $this->requested, 'a walked stream is never walked again');

		$this->repository(now: self::NOW + 7200)->revalidate();
		$served = $this->repository(now: self::NOW + 7210)->get();
		self::assertCount(24, $served['items'], 'the next store() reads the history back out');
		self::assertSame('2026-09-23', $served['items'][0]['date'], 'newest first across pages');
		self::assertNotSame([], $this->repository(now: self::NOW + 7220)->search('tellmi'), 'a July post is searchable');
	}

	/**
	 * Not before the present: history upserted into a table that holds no
	 * page 1 would be served as the durable copy — old alerts standing in for
	 * today's.
	 */
	public function testTheBackfillWaitsForAFirstRead(): void {
		$this->repository()->converge();

		self::assertSame([], $this->requested);
		self::assertArrayNotHasKey('backfilled_isp_anamed', $this->config);
	}

	/** WordPress 404s past a category's last page: exhaustion, and the walk is over. */
	public function testA404PastTheLastPageRetiresTheWalk(): void {
		$this->answers[self::COSMETICS . '?paged=2'] = 404;
		$this->repository(Sources::ISP_COSMETICS)->get();

		$this->repository(Sources::ISP_COSMETICS, now: self::NOW + 60)->converge();

		self::assertSame([self::COSMETICS, self::COSMETICS . '?paged=2'], $this->requested, 'page 3 is never asked past a 404');
		self::assertTrue($this->config['backfilled_isp_cosmetics'] ?? null);
		self::assertCount(3, $this->urls(Sources::ISP_COSMETICS));
	}

	/**
	 * Unreadable is not exhausted: a timeout, or a 200 that parses to
	 * nothing (a WAF page), leaves the flag down and the pages read so far
	 * kept, and tomorrow's pass asks again — until one reads through.
	 */
	public function testAnUnreadablePageLeavesTheWalkForTomorrow(): void {
		$this->repository()->get();

		$this->answers[self::ANAMED . '?paged=2'] = 0;
		$this->repository(now: self::NOW + 60)->converge();
		self::assertSame([self::ANAMED, self::ANAMED . '?paged=2'], $this->requested, 'no page 3 behind an unreadable page 2');
		self::assertArrayNotHasKey('backfilled_isp_anamed', $this->config);
		self::assertSame(['An older page of the ISP ANAMED alerts feed could not be read'], $this->warnings());

		unset($this->answers[self::ANAMED . '?paged=2']);
		$this->answers[self::ANAMED . '?paged=3'] = '<html><body>Request blocked</body></html>';
		$this->repository(now: self::NOW + 86400)->converge();
		self::assertArrayNotHasKey('backfilled_isp_anamed', $this->config, 'a WAF page is not the end of the feed');
		self::assertCount(20, $this->urls(Sources::ISP_ANAMED), 'page 2 is kept all the same');

		unset($this->answers[self::ANAMED . '?paged=3']);
		$this->repository(now: self::NOW + 2 * 86400)->converge();
		self::assertTrue($this->config['backfilled_isp_anamed'] ?? null);
		self::assertCount(24, $this->urls(Sources::ISP_ANAMED));
	}

	// --- one host (D8) ----------------------------------------------------------

	/**
	 * A refusal to one category brakes the host, in the host's own sentence,
	 * and its siblings' walks ask nothing — while the cooldown the refusal
	 * left holds, and after it, while the brake does.
	 */
	public function testARefusalToOneCategoryHoldsItsSiblingsWalks(): void {
		$this->repository()->get();
		$this->answers[self::PHARMACOVIGILANCE] = 403;
		$this->repository(Sources::ISP_PHARMACOVIGILANCE)->get();

		$this->repository(now: self::NOW + 60)->converge();
		$this->repository(now: self::NOW + 400)->converge();

		self::assertSame([self::ANAMED, self::PHARMACOVIGILANCE], $this->requested, 'a cooling, then braked, host is not asked for its history');
		self::assertArrayNotHasKey('backfilled_isp_anamed', $this->config);
		self::assertSame(
			[
				'ISP answered 403; under freno 1 time(s), waiting 7200s',
				'The ISP pharmacovigilance alerts feed could not be read',
			],
			$this->warnings(),
		);
	}

	/**
	 * The hourly pass queues one revalidation per stale fuente; the first to
	 * time out arms the ISP host's cooldown, and the two queued behind it ask
	 * nothing — one blocked host costs one 15 s timeout, not three. The next
	 * read serves each last good copy, degraded.
	 */
	public function testOneTimeoutOnTheIspHostHoldsEverySibling(): void {
		$ids = [Sources::ISP_ANAMED, Sources::ISP_PHARMACOVIGILANCE, Sources::ISP_COSMETICS];
		foreach ($ids as $id) {
			$this->repository($id)->get();
			$this->repository($id, now: self::NOW + 7200)->get();
		}
		self::assertSame(
			array_map(static fn (string $id): array => [RevalidateJob::class, ['source' => $id]], $ids),
			$this->jobs,
			'precondition: the stale reads queued one revalidation each',
		);

		$this->answers = [self::ANAMED => 0, self::PHARMACOVIGILANCE => 0, self::COSMETICS => 0];
		foreach ($ids as $id) {
			$this->repository($id, now: self::NOW + 7210)->revalidate();
		}

		self::assertCount(4, $this->requested, 'three first reads, then one timeout for the whole host');
		$served = $this->repository(Sources::ISP_COSMETICS, now: self::NOW + 7220)->get();
		self::assertTrue($served['degraded']);
		self::assertCount(3, $served['items'], 'and the last good copy still serves');
	}

	// --- doubles ----------------------------------------------------------------

	private function repository(
		string $id = Sources::ISP_ANAMED,
		?int $now = null,
		bool $brokenTable = false,
	): AlertRepository {
		return $this->repositories($now ?? self::NOW, $brokenTable)->repository($id);
	}

	/**
	 * The factory the ISP fuentes are built through, over this suite's
	 * doubles: a client answering from $answers or FIXTURES, a state-backed
	 * table, the Doubles cache and app config.
	 */
	private function repositories(int $now, bool $brokenTable): AlertRepositories {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->requested[] = $url;
			$this->options[] = $options;

			$answer = $this->answers[$url] ?? null;
			if (is_int($answer)) {
				// A status rides the exception's code, the way a
				// BadResponseException carries its response; 0 has none.
				throw new RuntimeException($answer === 0 ? 'cURL error 28: Operation timed out' : "HTTP $answer", $answer);
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($answer ?? $this->fixture($url));

			return $response;
		});
		// Client.php:445-451, mirrored: the response when the throwable
		// carries one, the throwable itself again when it does not.
		$client->method('getResponseFromThrowable')->willReturnCallback(function (Throwable $e): IResponse {
			if ($e->getCode() < 400) {
				throw $e;
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($e->getCode());

			return $response;
		});

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now));

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function ($job, $argument = null): void {
			$this->jobs[] = [$job, $argument];
		});

		/*
		 * The table, state-backed (ADR-0007): readSet answers what upsert
		 * wrote, in the mapper's order — month, then day, newest first. A
		 * stream never replaces its set, so replaceSet() is armed to throw.
		 */
		$mapper = $this->createMock(AlertMapper::class);
		$mapper->method('readSet')->willReturnCallback(function (string $fuente) use ($brokenTable): array {
			if ($brokenTable) {
				throw new RuntimeException('SQLSTATE[08006]: the connection to the database was lost');
			}

			$rows = array_values(array_filter($this->rows, static fn (Alert $a): bool => $a->getFuente() === $fuente));
			usort($rows, static fn (Alert $a, Alert $b): int
				=> [$b->getMonth(), $b->getDay()] <=> [$a->getMonth(), $a->getDay()]);

			return $rows;
		});
		$mapper->method('upsert')->willReturnCallback(function (string $fuente, Alert $alert) use ($brokenTable): void {
			if ($brokenTable) {
				throw new RuntimeException('SQLSTATE[08006]: the connection to the database was lost');
			}

			$alert->setFuente($fuente);
			$this->rows[$fuente . ' ' . $alert->getUrl()] = $alert;
		});
		$mapper->method('replaceSet')->willThrowException(new LogicException('a stream never replaces its set'));

		$bundle = $this->createMock(CertificateBundle::class);
		$bundle->method('path')->willReturn(self::BUNDLE);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				},
			);
		}

		return new AlertRepositories(
			$clients,
			$time,
			$logger,
			$mapper,
			$cacheFactory,
			$jobs,
			new PageParser($logger),
			$this->appConfig(),
			$bundle,
			new FeedParser($logger),
		);
	}

	/** @return list<string> the table's urls for one fuente */
	private function urls(string $fuente): array {
		return array_values(array_map(
			static fn (Alert $a): string => $a->getUrl(),
			array_filter($this->rows, static fn (Alert $a): bool => $a->getFuente() === $fuente),
		));
	}

	/** Every warning as nextcloud.log prints it — AlertRepositoryTest::warnings()'s rendering. */
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
	 * The captured feed an address serves. An unmatched address is recorded
	 * and asserted after the test, never thrown here: the repository catches
	 * Throwable, so a throw would read as an unreachable host and pass green.
	 */
	private function fixture(string $url): string {
		if (!isset(self::FIXTURES[$url])) {
			$this->unmatched[] = $url;

			return '';
		}

		return (string)file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURES[$url]);
	}

	/**
	 * A synthetic feed in the ISP's shape.
	 *
	 * @param list<array{t: string, u: string, p: string}> $items
	 */
	private function rss(array $items): string {
		$body = '';
		foreach ($items as $item) {
			$body .= sprintf('<item><title>%s</title><link>%s</link><pubDate>%s</pubDate></item>', $item['t'], $item['u'], $item['p']);
		}

		return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>' . $body . '</channel></rss>';
	}
}
