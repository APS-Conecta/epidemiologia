<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Db\Report;
use OCA\Epidemiologia\Db\ReportMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The weekly informe, as the ISP publishes it: posts of the documento feed,
 * one informe a week among the ISP's other documents.
 *
 * Rebuilt feed-shaped (ADR-0015 §1): the year of a semana comes from its
 * pubDate rendered in the publisher's zone, never from a URL, which is what
 * lets the series key on (year, se) and survive its own January — SE 1 of
 * 2027 is a different row from SE 1 of 2026, where the se-only cache key this
 * class used to carry could only ever hold one of them.
 *
 * The series persists to the app's own table (ADR-0015 §2): the cache in
 * front of it is the hot path, and the table is what an app update's cache
 * flush (ADR-0012) no longer takes with it. The back catalogue is unfetchable
 * from the page — the year archive renders client-side — so the feed's paged
 * history is the one place the series exists server-side, and the one-shot
 * backfill walks it once, on the refresh job (L3-08), until the 404 past the
 * last page retires the walk for good.
 *
 * Each informe's document is KEPT (ADR-0015 §2): fetched once on first
 * sight into IAppData, the path and the sha256 of the kept bytes on the
 * row, and never downloaded again while the row's url stands — a link is a
 * promise the publisher can break (ADR-0014 measured what that costs), and
 * an install that saw a semana can always show it again. A kept row's wire
 * url is the app's own /informe/{year}/{semana} route; an unarchived row's
 * is the publisher's stable page for the week — /documento/semana-{se}-{year}/,
 * the address that opens the current informe — and the route's fallback is
 * that same page, never the version-suffixed document address the row keeps
 * as provenance, the archiver's target and the reissue signal. A reissue
 * (…-V3 → …-V4) releases the kept copy and re-keeps the new one: the
 * reissue is what a clinician must open.
 *
 * The freno, the lock, 404-as-exhaustion and the WP-RSS parse are lifted here
 * from IspRepository before ADR-0016 deletes it: a shared seam would have
 * exactly one implementation, and the no-single-impl rule stands (ADR-0010's
 * reopen clause closes again).
 *
 * It differs from MinsalRepository — whose lifecycle it otherwise mirrors,
 * table fallback and refusal-brake included — in exactly one rule, the one
 * that has always made these two siblings rather than one class: an informe's
 * zero items is always a failure, never a result. It shares with both of them
 * the certificate: the ISP serves only its leaf, so `verify` points at
 * CertificateBundle's merged anchors or every request dies `cURL error 60` on
 * a fresh install. `verify => false` is forbidden here as everywhere.
 */
class IspReportRepository {
	/**
	 * The feed address — D4's verification answered (2026-09-20): the guessed
	 * /virusrespiratorios/feed/ never existed. The informes are posts of the
	 * ISP's general documento series, and this is that series' feed — the one
	 * constant D4 said the fix would be, plus the item reads the documento
	 * shape forces (the semana from <description>, the PDF from
	 * <content:encoded>'s anchor). The committed capture is this feed's page
	 * 1, minted from a reachable vantage, and the capture-guard test holds
	 * it honest.
	 */
	private const SOURCE = 'https://www.ispch.gob.cl/documento/feed/';

	/**
	 * The label is the discriminator, and it carries the semana — the same
	 * match that once read the page's anchor text now reads the feed item's
	 * <description>, which is that anchor text word for word ("Informe
	 * circulación virus respiratorios SE35 08-09-2026 V3"): the only place
	 * the semana epidemiológica appears, a label whose version suffix cannot
	 * be derived from a week number, so the item is read, never constructed.
	 * The title cannot tell the series — "Semana 35, 2026" names a week, and
	 * the feed's other posts (an E. coli study, a working-group acta in the
	 * capture's own page 1) are told apart by nothing else.
	 *
	 * `/u` is load-bearing, not decoration: it turns on Unicode character
	 * properties, so `\s` also matches the non-breaking space the same
	 * WordPress editor leaves between words. Without it a description typed
	 * with a U+00A0 stops matching and the week is lost in silence.
	 */
	private const INFORME = '/Informe\\s+circulaci[oó]n\\s+virus\\s+respiratorios\\s+SE\\s*(\\d{1,2})/iu';

	/**
	 * A published day belongs to whoever published it, so pubDate is rendered
	 * here and not in the server's zone (UTC in this container) nor the
	 * reader's — Chile spans three, and Easter Island is two hours from
	 * Santiago. The doctrine survives the method it came from: publisherDay()
	 * dies with IspRepository, but the year of a semana is the one fact this
	 * class derives from it.
	 */
	private const PUBLISHER_TZ = 'America/Santiago';

	/**
	 * The largest feed body we will read. `timeout` bounds the wait, not the
	 * memory: a 3 MB feed exhausts the image's 512 MB inside
	 * simplexml_load_string, fataling the worker mid-render — the measured
	 * reason the cap exists. 512 KB against a largest-real-feed of 9.5 KB
	 * (anamed): twelve times the biggest thing the ISP has ever served.
	 *
	 * ponytail: the body is already a string when we see it, so this bounds
	 * the PARSE, not the download; the download-bound upgrade (`on_headers`
	 * plus a chunked fallback) is only worth it if a hostile response ever
	 * gets that far.
	 */
	private const FEED_MAX_BYTES = 512 * 1024;

	/**
	 * How long a failed refresh is remembered, so an outage is not retried on
	 * every page load. Well under FRESH_FOR: recovery stays automatic and
	 * needs nobody.
	 */
	private const COOLDOWN = 300;

	/** How long a cached copy counts as fresh. Same windows as both siblings. */
	private const FRESH_FOR = 3600;

	/**
	 * How long the copy survives in Redis — far longer than FRESH_FOR, on
	 * purpose: the degraded banner needs a last good copy to serve, and it
	 * can only do that if the copy outlived its own freshness window.
	 */
	private const KEEP_FOR = 30 * 24 * 3600;

	/**
	 * Who is asking, on every request. A bot the publishers have no reason to
	 * block is a visitor they can name: anonymous is not a right, and a WAF
	 * that blocks what it cannot identify looks, from this side, exactly like
	 * an outage (ADR-0015 §4).
	 */
	private const UA = 'APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)';

	/**
	 * How long the refresh lock is held before another reader may take it.
	 * Stated for the walk it used to bound and kept rather than re-derived
	 * (L3-08): the number no longer bounds a walk — the walk is converge()'s
	 * now, and holds no lock — and it still sits comfortably above the page-1
	 * refresh's worst case (a 15-second fetch plus the upserts), and shrinking
	 * it buys nothing.
	 */
	private const LOCK_FOR = 1440;

	/**
	 * The walk's per-refresh page budget: 63 pages at 15 s each is 960 s of
	 * network — LOCK_FOR holds a quarter above it for the upserts the walk
	 * interleaves, so a walk never outlives its own lock; a feed with more
	 * history than the budget continues next refresh from its cursor instead.
	 * Sixty-three pages at ten items a page is 630 items — the documento
	 * feed's own mix runs eight informes in ten, a decade of weekly
	 * informes: no honest feed reaches it, and a pathological one that
	 * paginates forever is bounded by it.
	 */
	private const WALK_PAGES = 63;

	/**
	 * Consecutive unreadable attempts on one walk page before the walk steps
	 * over it. Three, re-derived for the one-shot cadence from the rotation it
	 * was calibrated to: a page is reached once per refresh (hourly at worst
	 * while the pane is open), so three is three hours of transient trouble
	 * tolerated before one page is given up on — the same arithmetic the old
	 * twice-a-day constant carried.
	 */
	private const STALLS_BEFORE_SKIP = 3;

	/**
	 * Where the kept documents live: one folder in the app's own IAppData,
	 * beside the app's own tables — the same store-every-install-has doctrine,
	 * one layer down (ADR-0015 §2). The controller that serves them carries
	 * the same literal; its suite pins the served bytes against this folder,
	 * so drift answers as a redirect, never as a broken link.
	 */
	private const FOLDER = 'informes';

	/**
	 * The publisher's stable page base for one week: PAGE . 'semana-{se}-{year}/'
	 * is the address the ISP prints for every informe of the series — the
	 * redirect behind it opens the CURRENT document (verified from a
	 * reachable vantage, 2026-09-20), so the address never changes while the
	 * informe behind it is reissued. This is the address a human gets — the
	 * pane's href before the document is kept, the route's fallback whenever
	 * the kept copy cannot be vouched for. The row keeps the exact,
	 * version-suffixed document address instead (provenance, the archiver's
	 * target, the reissue signal); it never reaches a browser. The capture's
	 * own <link>s pin the derivation, and the controller carries its own
	 * literal — the FOLDER doctrine: drift answers as a failing pin, never
	 * as a wrong redirect.
	 */
	private const PAGE = 'https://www.ispch.gob.cl/documento/';

	/**
	 * The route a kept row's href names — the one routes.php registers
	 * (informe#show, so the derived name is this). Drift between the two
	 * strings breaks every kept href at once, so the reader suite ledgers
	 * the route name the wire asks for and a manual criterion walks the real
	 * route once.
	 */
	private const ROUTE = Application::APP_ID . '.informe.show';

	/**
	 * The largest document we will keep. A bound, because everything that
	 * fetches is bounded (#130): a document body is a string in worker memory
	 * the moment getBody() returns, and an unbounded one is the worker's
	 * memory. Never measured — the dev VPS cannot reach the ISP — so it is
	 * sized against the shape: a graphical weekly PDF report is well under
	 * 2 MB, and 8 MB is four times that with nothing worth stealing behind
	 * it. An oversize document is refused (logged), the row keeps the
	 * publisher's link, nothing is lost.
	 */
	private const DOC_MAX_BYTES = 8 * 1024 * 1024;

	/**
	 * How many documents one refresh may fetch: the pass's budget, newest
	 * first. A fresh install's backfill names years of weeks at once, and
	 * keeping them in one pass would hold the reader's worker for the whole
	 * download — inside PageController::index(), while someone waits. Ten a
	 * refresh keeps the current week immediate (unarchived() is newest
	 * first), the back catalogue converging over the next hours of pane
	 * opens and days of the refresh job, and the pass bounded to ten
	 * requests however many rows are waiting. Success or failure, each
	 * attempt spends one unit: a document that will never answer must not
	 * wedge the pass into spending the whole budget failing on it (#74's
	 * silent stall, on the document scale).
	 */
	private const ARCHIVE_BUDGET = 10;

	private ICache $cache;

	private IMemcache $lock;

	private IAppData $appData;

	public function __construct(
		private IClientService $clients,
		private CertificateBundle $bundle,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ReportMapper $reports,
		private IAppConfig $config,
		private IURLGenerator $urlGenerator,
		ICacheFactory $cacheFactory,
		IAppDataFactory $appDataFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
		$this->lock = $cacheFactory->createLocking('epidemiologia');
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	public function get(): array {
		$stored = $this->stored();

		if ($stored !== null && $stored['fetched_at'] + self::FRESH_FOR > $this->time->getTime()) {
			return $this->present($stored, false);
		}

		/*
		 * A failed refresh writes nothing, so the entry stays expired and every read
		 * would retry. PageController::index() fetches synchronously while someone
		 * waits, so against a host that drops packets rather than refusing them this
		 * is a gateway timeout instead of the degraded banner.
		 */
		if ($this->cache->get($this->cooldownKey()) !== null) {
			return $this->present($stored, true);
		}

		/*
		 * A refusal armed the freno — request() classifies. While the brake
		 * holds, this read must not ask again: a 403 is an answer, not a
		 * challenge (ADR-0015 §4). Served like any degraded copy, from the
		 * cache or, after an app update, from the table. The probe is the one
		 * ask that goes through, once a day.
		 */
		if ($this->braked()) {
			return $this->present($stored, true);
		}

		/*
		 * One refresh at a time: the page-1 refresh is a fetch, some upserts
		 * and a read-back — seconds, not minutes, since the walk moved out
		 * (L3-08) — and `add()` is atomic; a loser serves the copy it already
		 * has, which is at most an hour older than the one being fetched.
		 *
		 * Cold readers do not lock either, and no longer need the exception's
		 * old excuse: a cold reader refreshes page 1 and serves it, so two of
		 * them racing are two bounded fetches, not two walks.
		 *
		 * The co-refresh defense, corrected (L3-09): what actually makes a
		 * concurrent winner harmless is the composite (year, se) primary key
		 * — a racing insert dies on the PK and lands in store()'s catch —
		 * and NOT "idempotent upserts and document writes", this block's old
		 * claim. The upserts are find-then-write; the claim was prose, not
		 * mechanism, and the mapper suite (tests/integration/) pins the real
		 * one now.
		 */
		$held = $stored !== null && $this->lock->add($this->lockKey(), 1, self::LOCK_FOR);

		if ($stored !== null && !$held) {
			return $this->present($stored, false);
		}

		try {
			return $this->refresh($stored);
		} finally {
			// Only what we took: a reader that never took the lock must not
			// release the one another request is mid-walk under.
			if ($held) {
				$this->lock->remove($this->lockKey());
			}
		}
	}

	/**
	 * Daily breakage detection. False means a human has to look.
	 *
	 * Asks THROUGH the freshness window rather than around it, and through
	 * the freno too, which is why it calls refresh() and not get(): a detector
	 * that reuses the reader's rule reports an outage as silence — the defect
	 * #218beba records, where a fully-braked feed reported health for a week.
	 * The ask is also the brake's road back: a refusal escalates the count
	 * and an answer clears it, exactly as on the hourly path, because
	 * request() runs the classification either way.
	 *
	 * The ask is no longer archival (L3-08): probe() runs store(), which
	 * upserts page 1 and reads the table back — the document archiver and
	 * the back-catalogue walk are converge()'s, on the refresh job. An
	 * unopened install converges there, at ARCHIVE_BUDGET documents a day.
	 */
	public function probe(): bool {
		return $this->refresh($this->stored())['degraded'] === false;
	}

	/**
	 * Fetch, validate, and either persist or degrade.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function refresh(?array $stored): array {
		$xml = $this->request();
		$fresh = $xml === null ? [] : $this->parse($xml);

		return $fresh === [] ? $this->fail($xml, $stored) : $this->store($fresh, $stored);
	}

	/**
	 * The zero-items path — and for an informe, zero items is ALWAYS a
	 * failure, never a result: the rule that makes this class a sibling of
	 * MinsalRepository rather than a second feed inside it. Caching an empty
	 * parse would replace the series with an empty list, which a clinician
	 * reads as "no hay informes" — so: leave the last good copy alone, cache
	 * and table both, and say we are degraded.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function fail(?string $xml, ?array $stored): array {
		/*
		 * Which fault it was, in the sentence itself: `$xml` is null when
		 * nothing was read at all and a string when the feed was read and
		 * carried no informe, and the payload is byte-identical either way —
		 * both degrade, both serve the last good copy. The WAF case is the
		 * one that misleads even a log reader: a 200 with a block page looks
		 * like a restructure, and naming it "unreachable" sends whoever
		 * reads the log to the wrong page of the ISP's site.
		 */
		$this->logger->warning(
			$xml === null
				? 'The ISP informes feed could not be read'
				: 'The ISP informes feed carried no informe',
		);

		return $this->degrade($stored);
	}

	/**
	 * Turns a freshly parsed reading into the persisted series and the cache
	 * entry over it.
	 *
	 * The table is the series: this reading is upserted, and the entry served
	 * — and cached — is the table read back.
	 *
	 * The deep work — the backfill walk and the document archiver — does not
	 * run here any more (L3-08): the reader path is bounded to page 1, and
	 * converge() is the job's door to everything deeper. The read-back stays
	 * load-bearing: it is the one exit the series has.
	 *
	 * A storage failure is its own fault class (ADR-0015 §2, the sibling's
	 * store() doctrine): logged, retried on the next store(), never a
	 * degrade — the fetch succeeded, and the reading serves regardless,
	 * accumulated over the last copy the way this fuente always accumulated.
	 *
	 * @param list<array<string, string>> $fresh
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function store(array $fresh, ?array $stored): array {
		$now = $this->time->getTime();

		try {
			foreach ($fresh as $item) {
				$this->reports->upsert($this->entity($item));
			}

			$entry = ['items' => $this->wire($this->reports->all()), 'fetched_at' => $now];
		} catch (Throwable $e) {
			$this->logger->warning('Could not persist the ISP informes series', [
				'exception' => $e,
			]);

			$entry = ['items' => $this->accumulate($fresh, $stored['items'] ?? []), 'fetched_at' => $now];
		}

		$this->cache->set($this->key(), $entry, self::KEEP_FOR);

		return $this->present($entry, false);
	}

	/**
	 * Walk the feed's older pages until the 404 past the last page retires the
	 * walk: the one-shot backfill. The back catalogue is unfetchable from the
	 * page — the year archive renders client-side — so this walk is the only
	 * way the series ever exists whole, and it runs once per install: one run
	 * for any series the ISP will ever serve (WALK_PAGES is twelve years of
	 * it), and a longer one continues next refresh from its cursor.
	 *
	 * The cursor lives in app config, beside the stall count, because the
	 * series they are claims about lives in the table: a cache flush must not
	 * say "start over" about a table that still holds everything, and app
	 * config dies exactly when the table does — on uninstall, and not before
	 * (the 3f07b81 lesson, with the book and the bookmark in one lifetime
	 * this time; ProbeRecord.php carries the same IAppConfig rationale).
	 *
	 * STRICTLY ADDITIVE: it only upserts older rows in. It cannot empty the
	 * series, cannot set degraded, does not touch freshness — which is why it
	 * runs inside store(), after the reading was parsed and judged.
	 */
	private function backfill(): void {
		$page = $this->config->getAppValueInt('backfill_page', 2);

		if ($page === 0) {
			return;   // exhausted — the walk is over for the life of the install
		}

		$stalls = $this->config->getAppValueInt('backfill_stalls', 0);
		$budget = self::WALK_PAGES;

		while ($budget-- > 0) {
			$items = $this->page($page);

			/*
			 * Could not read it: the cursor does NOT advance, so the page is
			 * retried next refresh rather than skipped forever.
			 */
			if ($items === null) {
				$stalls++;

				if ($stalls < self::STALLS_BEFORE_SKIP) {
					$this->config->setAppValueInt('backfill_stalls', $stalls);

					return;
				}

				/*
				 * Three tries, then step over it and say so: one lost page
				 * beats a walk that is dead and quiet about it (#74's silent
					 * stall, which spent requests forever on a page nothing
					 * could ever read).
				 */
				$this->logger->warning('Page {page} of the ISP informes feed unreadable {n} times, backfill skips it', [
					'page' => $page, 'n' => $stalls,
				]);
				$this->config->setAppValueInt('backfill_stalls', 0);
				$this->config->setAppValueInt('backfill_page', $page + 1);
				$page++;

				continue;
			}

			// Past the end. Retire the cursor: this feed is fully walked and
			// must never be re-walked — the difference between a backfill and
			// a loop.
			if ($items === []) {
				$this->config->setAppValueInt('backfill_page', 0);

				return;
			}

			foreach ($items as $item) {
				$this->reports->upsert($this->entity($item));
			}

			$this->config->setAppValueInt('backfill_stalls', 0);
			$page++;
			$this->config->setAppValueInt('backfill_page', $page);
		}
	}

	/**
	 * One older page of the feed. Unconditional — every page is different
	 * bytes, so validators would only ever miss.
	 *
	 * @return ?list<array<string, string>> null could not read it · [] past the last page
	 */
	private function page(int $page): ?array {
		$client = $this->clients->newClient();

		try {
			$response = $client->get(self::SOURCE . '?paged=' . $page, [
				'timeout' => 15,
				'headers' => ['User-Agent' => self::UA],
				'verify' => $this->bundle->path(),
			]);
		} catch (Throwable $e) {
			/*
			 * WordPress answers 404 past the last page, and the client throws
			 * on it. That is exhaustion, not failure, and the two must not be
			 * confused: treating it as failure would leave the cursor alive
			 * forever and the walk would never end.
			 *
			 * Classified through getResponseFromThrowable() rather than mined
			 * out of the message string — the one status semantics this app
			 * had was a str_contains('404') on prose, and a WAF's HTML answer
			 * can carry '404' in its body text.
			 *
			 * Any other refusal is the walk's to stop on, and deliberately
			 * not the freno's to arm: the front page is the cheap, cached
			 * query and a deep page is the expensive uncached one, so letting
			 * a deep page's refusal brake the hourly read would silence a
			 * healthy page 1 — the ordering the old backfill promised it does
			 * not break. The stall counter is the whole of the deep page's
			 * failure machinery.
			 */
			try {
				$status = $client->getResponseFromThrowable($e)->getStatusCode();
			} catch (Throwable) {
				$status = null;
			}

			if ($status === 404) {
				return [];
			}

			$this->logger->debug('Page {page} of the ISP informes feed unavailable', [
				'page' => $page, 'exception' => $e,
			]);

			return null;
		}

		$body = (string)$response->getBody();

		if (strlen($body) > self::FEED_MAX_BYTES) {
			$this->logger->warning('Page {page} of the ISP informes feed of {bytes} bytes, discarded', [
				'page' => $page, 'bytes' => strlen($body),
			]);

			return null;
		}

		$items = $this->parse($body);

		/*
		 * Empty means "could not read it", NOT "past the end". WordPress 404s
		 * past the last page and that is caught above; a 200 parsing to
		 * nothing is the ISP's WAF, a soft-404 theme page or a truncated body,
		 * and one such answer once retired a walk permanently — the guard
		 * this return exists to keep.
		 */
		return $items === [] ? null : $items;
	}

	/**
	 * Keep each informe's document (ADR-0015 §2): one fetch on first sight,
	 * the bytes into IAppData, the path and the sha256 of those bytes on the
	 * row — and never a second fetch while the row's url stands. The work
	 * list is the unarchived rows, NEWEST FIRST, so the week a clinician
	 * opens today is kept before the back catalogue the walk just named;
	 * the budget bounds the pass so a long list converges over refreshes
	 * instead of holding the reader's worker for one long download.
	 *
	 * The order inside one row is load-bearing: the file is written BEFORE
	 * the row claims it (path + hash), so a row that says "kept" always
	 * names bytes that exist. The other order — claim first, write after —
	 * would leave the row pointing at nothing on a failed write, with
	 * nothing to retry with: after the claim the row is no longer in
	 * unarchived(), and the one step that cannot be re-entered is the one
	 * that must not fail last (the 3f07b81 and 513958a lesson: publish
	 * atomically, key the claim on the writer).
	 *
	 * A row whose document cannot be fetched is left alone and said so, at
	 * debug: nothing is lost — the row keeps the publisher's own link until
	 * its document is kept, and the pane never lies. A document's failure is
	 * never the feed's failure: no freno, no cooldown, no degraded flag (the
	 * page() doctrine — the cheap feed read must not pay for the deep
	 * document's troubles).
	 */
	private function archive(): void {
		$left = self::ARCHIVE_BUDGET;

		foreach ($this->reports->unarchived() as $report) {
			if ($left === 0) {
				return;
			}
			$left--;

			$name = $this->fileName($report);

			try {
				$body = $this->document($report->getUrl());

				if ($body === null) {
					continue;
				}

				$this->folder()->newFile($name, $body);
			} catch (Throwable $e) {
				// The write side of the row-local fault class document() logs
				// for the fetch side: skip this row this pass, keep the rest.
				$this->logger->debug('Could not keep the informe document for semana {se} of {year}', [
					'year' => $report->getYear(), 'se' => $report->getSe(), 'exception' => $e,
				]);

				continue;
			}

			$report->setPath($name);
			$report->setHash(hash('sha256', $body));
			$this->reports->upsert($report);
		}
	}

	/**
	 * The deep pass, out of the request path (L3-08): the one-shot backfill
	 * and the document archiver, which the reader used to run inside
	 * store() — holding PageController::index() for WALK_PAGES of pages plus
	 * ARCHIVE_BUDGET of documents at 15 s each on a cold install, the ~18
	 * minutes the review named. First paint serves page 1; this is where the
	 * back catalogue converges, on the refresh job's daily cadence.
	 *
	 * Walked rows reach the pane on the NEXT store(): the read-back is the
	 * series' one exit, and rewriting the cache entry here would be a second
	 * writer for store() to disagree with. A day of latency is the documented
	 * convergence cadence — the same "within a day" the archiver already
	 * promises — and the weeks a clinician opens first are page 1's, which
	 * get() has already served.
	 */
	public function converge(): void {
		$this->backfill();

		$this->archive();
	}

	/**
	 * The one folder the kept documents live in, created on first need — the
	 * per-row try in archive() is what a newFolder() race between two
	 * co-refreshers lands in: one skipped row, retried next pass.
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	/** informe-{year}-{se}.pdf — digits both, canonical se: one name per week, forever. */
	private function fileName(Report $report): string {
		return sprintf('informe-%s-%s.pdf', $report->getYear(), $report->getSe());
	}

	/**
	 * One informe's document, or null when it cannot be kept. Bounded like
	 * every fetch (timeout, DOC_MAX_BYTES), self-naming like every request,
	 * and refusing what it cannot vouch for: the body must start with the
	 * PDF magic, because the ISP's WAF answers document urls with 200 and an
	 * HTML block page — the exact shape that once got 24 empty pages cached
	 * as healthy (ae98ade) — and bytes that are not a PDF are not the
	 * informe, whatever the url says.
	 *
	 * Null is row-local, always: the pass continues, the row keeps its
	 * publisher link, and neither the freno nor the cooldown is armed. The
	 * status is not even read — every answer the document gives, refusal or
	 * not, is the same to the pass: skip this row this time.
	 */
	private function document(string $url): ?string {
		try {
			$response = $this->clients->newClient()->get($url, [
				'timeout' => 15,
				'headers' => ['User-Agent' => self::UA],
				// The ISP serves only its leaf for documents too: the same
				// merged anchors every other request to this host verifies
				// against. `verify => false` is forbidden here as everywhere.
				'verify' => $this->bundle->path(),
			]);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->debug('Could not fetch the informe document at {url}', [
				'url' => $url, 'exception' => $e,
			]);

			return null;
		}

		if (strlen($body) > self::DOC_MAX_BYTES) {
			$this->logger->debug('Informe document of {bytes} bytes at {url}, discarded', [
				'bytes' => strlen($body), 'url' => $url,
			]);

			return null;
		}

		if (!str_starts_with($body, '%PDF')) {
			$this->logger->debug('The body at {url} is not a PDF, discarded', ['url' => $url]);

			return null;
		}

		return $body;
	}

	/**
	 * RSS 2.0: title, description, pubDate, parsed with the XML reader
	 * rather than by regex — unlike the alertas page, this really is a
	 * document with a schema — plus the one regex the page scrape always
	 * carried: an informe's own PDF arrives inside <content:encoded>'s
	 * anchor, and there is no schema for the HTML inside it. Lifted from
	 * IspRepository before ADR-0016 deletes it.
	 *
	 * @return list<array<string, string>>
	 */
	private function parse(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		$feed = simplexml_load_string($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($feed === false || !isset($feed->channel->item)) {
			return [];
		}

		$items = [];
		$rejected = 0;
		foreach ($feed->channel->item as $node) {
			// The same normalisation both siblings carry: a leading NBSP renders
			// as a visible indent on the pane's first row, and `trim()` alone
			// cannot see it. It runs on the description too: the label INFORME
			// reads is prose, and the editor's U+00A0 breaks it as invisibly
			// as it breaks the title.
			$clean = static fn (string $text): string => trim((string)preg_replace(
				'/[\\s\\x{00A0}\\x{2000}-\\x{200A}\\x{202F}\\x{205F}\\x{3000}]+/u',
				' ',
				$text,
			));

			$title = $clean((string)$node->title);

			// Only the informe is an informe: the documento feed carries the
			// ISP's other posts too, and only the label tells the weekly
			// series from them.
			$description = $clean((string)$node->description);
			if (preg_match(self::INFORME, $description, $semana) !== 1) {
				continue;
			}

			/*
			 * The document's own address is the anchor inside content:encoded
			 * — the same href the page scrape once read. <link> is the post's
			 * own page (/documento/semana-35-2026/), a redirect the publisher
			 * points at the PDF, never the document itself — and a defaced
			 * feed serving "javascript:…/informe.pdf" under a label this
			 * parser accepts would otherwise reach href byte for byte, so
			 * the https guard the siblings carry applies to the anchor's
			 * href: an item whose anchor cannot be read is counted and
			 * warned like the pubDate reject below, never silently kept.
			 */
			$href = '';
			$encoded = (string)($node->children('content', true)->encoded ?? '');
			if (preg_match('#<a\\s[^>]*href="([^"]+)"#i', $encoded, $anchor) === 1) {
				$href = trim($anchor[1]);
			}

			if ($href === '' || stripos($href, 'https://') !== 0) {
				$rejected++;
				continue;
			}
			$url = $href;

			$at = strtotime((string)$node->pubDate);

			/*
			 * The year is half the identity (the (year, se) key), and it comes
			 * from pubDate or not at all — never from a URL. An informe whose
			 * pubDate cannot be read cannot be keyed, and dropping it silently
			 * would be the partial silent loss this app is built to avoid, so
			 * it is counted and warned like the https reject above.
			 */
			if ($at === false) {
				$rejected++;
				continue;
			}

			$when = (new \DateTimeImmutable('@' . $at))
				->setTimezone(new \DateTimeZone(self::PUBLISHER_TZ));

			$items[] = [
				'title' => $title,
				'url' => $url,
				// Canonical digits, not the label's: `SE05` and `SE5` are one
				// week typed two ways, and the row key must agree with
				// itself or a republish that dropped the zero writes a second
				// row for the same week.
				'se' => (string)(int)$semana[1],
				'year' => $when->format('Y'),
				'month' => $when->format('Y-m'),
				'published_at' => (string)$at,
			];
		}

		if ($rejected > 0) {
			$this->logger->warning('Dropped {rejected} informe(s) the feed could not be trusted for', [
				'rejected' => $rejected,
			]);
		}

		return $items;
	}

	/**
	 * The feed's first page, or null on anything that stops us reading it.
	 *
	 * Every request names this app (UA) and classifies every failure the way
	 * ADR-0015 §4 does: a 4xx/5xx status is the host SAYING no, and arms the
	 * freno so the next reads stop asking; a timeout, a TLS error, a DNS miss
	 * carry no response to read and stay cooldown-class — recovery stays
	 * automatic, because those are faults that lift on their own. A 200 that
	 * parses to nothing keeps the cooldown too (fail() handles it): a WAF
	 * block page is indistinguishable from a restructure, and braking on it
	 * would refuse a healthy host forever.
	 */
	private function request(): ?string {
		$client = $this->clients->newClient();

		try {
			$response = $client->get(self::SOURCE, [
				'timeout' => 15,
				'headers' => ['User-Agent' => self::UA],
				// The ISP serves only its leaf certificate, so verification
				// needs the intermediate CertificateBundle merges into the
				// instance's own anchors. It degrades to those anchors by
				// itself and never throws.
				'verify' => $this->bundle->path(),
			]);

			/*
			 * An answer — any answer — is the host not refusing. The brake
			 * lifts here and not on a successful parse so recovery is one
			 * step, and a WAF 200 lands in the cooldown, not back under the
			 * brake it just lifted. One cache DELETE per successful fetch,
			 * priced and kept.
			 */
			$this->cache->remove($this->frenoKey());

			$body = (string)$response->getBody();

			if (strlen($body) > self::FEED_MAX_BYTES) {
				$this->logger->warning('ISP informes feed of {bytes} bytes, discarded', [
					'bytes' => strlen($body),
				]);

				return null;
			}

			return $body;
		} catch (Throwable $e) {
			/*
			 * getResponseFromThrowable() is the status affordance: it hands
			 * back the response a BadResponseException carries — and RETHROWS
			 * when the throwable has none, which is exactly the timeout/TLS
			 * class. So the classification is a nested catch: the outer
			 * throwable reached this line from the transport, the inner one
			 * asks whether an answer came with it.
			 */
			try {
				$status = $client->getResponseFromThrowable($e)->getStatusCode();
			} catch (Throwable) {
				$status = null;
			}

			if ($status !== null && $status >= 400) {
				// The host said no. The brake's own warning names the refusal,
				// once per escalation; fail()'s null-branch line still fires
				// from the reader's side, which is true — a refusal IS an
				// unreadable feed — but the "could not be read" sentence stays
				// for the faults with no answer attached.
				$this->brake($status);

				return null;
			}

			// 'exception' is a key Nextcloud's log pipeline treats specially:
			// it serialises the class, the trace and the previous chain, which
			// getMessage() would throw away in exactly the case where the
			// trace is what you need.
			$this->logger->warning('Could not reach the ISP informes feed', [
				'exception' => $e,
			]);

			return null;
		}
	}

	/**
	 * The cache entry, or the table's durable copy shaped as one — the
	 * fallback every reader of this seam inherits (ADR-0015 §2): after an app
	 * update moves the cache namespace (ADR-0012), the read still has a series
	 * to serve, and one healthy fetch re-materializes the cache from the
	 * table.
	 *
	 * The fabricated entry carries no fetched_at: that was the cache's fact
	 * and it left with the cache. The wire says null and claims no freshness
	 * it cannot back — which is also what makes the next get() refresh rather
	 * than serving the table as fresh.
	 */
	private function stored(): ?array {
		$stored = $this->cache->get($this->key());

		if (is_array($stored) && isset($stored['items'], $stored['fetched_at'])) {
			return $stored;
		}

		try {
			$items = $this->wire($this->reports->all());
		} catch (Throwable $e) {
			// The same fault class as the write side: logged, and the read
			// falls back to a plain cold cache. Never routed through degrade().
			$this->logger->warning('Could not read the persisted ISP informes series', [
				'exception' => $e,
			]);

			return null;
		}

		/*
		 * An empty table is no fallback: it is never seeded (ADR-0015,
		 * Consequences), so empty means "nothing was ever stored here", and
		 * the honest answer is a cold reader, not a durable copy that claims
		 * to exist.
		 */
		if ($items === []) {
			return null;
		}

		return ['items' => $items, 'fetched_at' => null];
	}

	/**
	 * @param array<string, string> $item
	 */
	private function entity(array $item): Report {
		$report = new Report();
		$report->setYear($item['year']);
		$report->setSe($item['se']);
		$report->setTitle($item['title']);
		$report->setUrl($item['url']);
		$report->setMonth($item['month']);
		$report->setPublishedAt((int)$item['published_at']);

		$existing = $this->reports->find($item['year'], $item['se']);

		if ($existing !== null && $existing->getUrl() === $item['url']) {
			/*
			 * Same week, same address: the kept document still vouches for
			 * what the publisher serves there, so it carries forward. The
			 * upsert writes every column the entity carries, and without
			 * this carry an hourly re-read of an already-kept week would
			 * quietly null its path and hash — a fresh reading must never
			 * null an archived row.
			 */
			$report->setPath($existing->getPath());
			$report->setHash($existing->getHash());
		}

		/*
		 * A different address is a reissue (…-V3 → …-V4), and the reissue is
		 * what a clinician must open: the kept copy is released here — the
		 * row lands back in unarchived() with a null path — and the archiver
		 * re-keeps the new document in the same pass, one fetch, a hash
		 * that changed. The publisher's url the row keeps is provenance —
		 * the archiver's target and the reissue signal; the pane's href is
		 * the route once the reissue is kept, the publisher's page before
		 * that.
		 */

		return $report;
	}

	/**
	 * The wire shape: title, url, year, month, se — the fields the pane
	 * prints. path and hash are storage's, not the wire's: an internal field
	 * that rides out to every browser is one nobody can change later without
	 * breaking a consumer that never should have seen it (the `h` precedent,
	 * IspRepository.php:906-913). They never enter, so there is nothing to
	 * strip — this is the one door items leave through. The one field that
	 * changes here is the url: a row whose document is kept serves from the
	 * app's own route, so its href IS that route. Every other row — this
	 * pass's budget, or a document the publisher will not serve — carries
	 * the publisher's own PAGE for the week: the stable address that opens
	 * the current informe, so the visible link never changes while the
	 * informe behind it is reissued. The exact, version-suffixed document
	 * address stays on the row — provenance, the archiver's target, the
	 * reissue signal — and never reaches a browser.
	 *
	 * @param list<Report> $rows
	 *
	 * @return list<array<string, string>>
	 */
	private function wire(array $rows): array {
		return array_map(function (Report $report): array {
			$url = $this->publisherPage($report->getYear(), $report->getSe());

			if ($report->getPath() !== null) {
				$url = $this->urlGenerator->linkToRoute(self::ROUTE, [
					'year' => $report->getYear(),
					'semana' => $report->getSe(),
				]);
			}

			return [
				'title' => $report->getTitle(),
				'url' => $url,
				'year' => $report->getYear(),
				'month' => $report->getMonth(),
				'se' => $report->getSe(),
			];
		}, $rows);
	}

	/**
	 * The publisher's page for one week — see PAGE. Derived from the row's
	 * own identity, the fileName() doctrine: the capture's own <link>s pin
	 * the formula, and the pane's href for an unarchived row, the route's
	 * fallback, and the no-table door below all answer with it.
	 */
	private function publisherPage(string $year, string $se): string {
		return sprintf(self::PAGE . 'semana-%s-%s/', $se, $year);
	}

	/**
	 * The cache-side fallback when the table cannot be written or read: the
	 * fresh reading accumulated over the last copy, keyed (year, se) — the
	 * union the se-only array_column once did, with the year the feed read
	 * added to the key so SE 1 of 2027 never replaces SE 1 of 2026.
	 *
	 * Every item is re-keyed to the wire's five fields on the way in: the
	 * fresh reading is parse()-shaped and carries `published_at`, which is
	 * the entity's, not the browser's — this path is the one door out that
	 * wire() does not build, and an internal field that rides through it
	 * would be exactly the leak the `h` precedent (IspRepository.php:906-913)
	 * forbids. The publisher's own page addresses are what this door serves:
	 * it is the no-table door, so no kept document is known to it.
	 *
	 * @param list<array<string, string>> $fresh
	 * @param list<array<string, string>> $stored
	 *
	 * @return list<array<string, string>>
	 */
	private function accumulate(array $fresh, array $stored): array {
		$items = [];

		// Stored first, fresh second: a later assignment wins, and the fresh
		// reading winning is exactly what a reissue is.
		foreach ([...$stored, ...$fresh] as $item) {
			$items[$item['year'] . '-' . $item['se']] = [
				'title' => $item['title'],
				'url' => $this->publisherPage($item['year'], $item['se']),
				'year' => $item['year'],
				'month' => $item['month'],
				'se' => $item['se'],
			];
		}

		$items = array_values($items);

		// (year, se) desc — the mapper's own order, approximated on the two
		// wire fields it has: the epoch the table sorts by does not ride.
		usort($items, static fn (array $a, array $b): int
			=> [$b['year'], (int)$b['se']] <=> [$a['year'], (int)$a['se']]);

		return $items;
	}

	/**
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function present(?array $stored, bool $degraded): array {
		return [
			'items' => $stored['items'] ?? [],
			'fetched_at' => $stored['fetched_at'] ?? null,
			'degraded' => $degraded,
		];
	}

	/**
	 * Degrade, and remember it briefly so an outage is not re-attempted on
	 * every read. Every degrade path goes through here, so none can forget to.
	 */
	private function degrade(?array $stored): array {
		$this->cache->set($this->cooldownKey(), 1, self::COOLDOWN);

		return $this->present($stored, true);
	}

	/** Is the feed still braked? The stored value IS the moment the brake expires. */
	private function braked(): bool {
		$until = $this->cache->get($this->frenoKey());

		return $until !== null && $this->time->getTime() < (int)$until['until'];
	}

	/**
	 * Doubling from two hours, capped at a day — the curve lifted from the
	 * old machinery unchanged (ADR-0015 §4): two hours because refreshes are
	 * hourly (FRESH_FOR), anything shorter is not a freno, it is a rounding
	 * error. The count outlives the wait it caused, which is what makes the
	 * next refusal cost more; an answer removes the key outright, so recovery
	 * is one step and not a slow unwind.
	 */
	private function brake(int $status): void {
		$n = (int)($this->cache->get($this->frenoKey())['n'] ?? 0) + 1;
		$wait = min(2 * self::FRESH_FOR * (2 ** ($n - 1)), 86400);

		$this->logger->warning('The ISP informes feed answered {status}; under freno {n} time(s), waiting {wait}s', [
			'status' => $status, 'n' => $n, 'wait' => $wait,
		]);

		$this->cache->set($this->frenoKey(), [
			'n' => $n,
			'until' => $this->time->getTime() + $wait,
		], 86400 + $wait);
	}

	/** Its own key, beside the siblings' — unchanged from the page-scrape reader. */
	private function key(): string {
		return 'isp-informes';
	}

	/** Distinct from key(), or the cooldown flag would be read as the series. */
	private function cooldownKey(): string {
		return 'cooldown-isp-informes';
	}

	/** Distinct from key() and cooldownKey(), or the brake would be read as data. */
	private function frenoKey(): string {
		return 'freno-isp-informes';
	}

	private function lockKey(): string {
		return 'refresh-isp-informes';
	}
}
