<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sanitary alerts from the ISP — market withdrawals, falsifications,
 * pharmacovigilance, device recalls.
 *
 * Deliberately a sibling of MinsalRepository rather than a second source inside
 * it. The two share about ten lines of cache mechanics and disagree on the thing
 * that matters: MINSAL is one page whose emptiness is always a failure, while
 * the ISP is 24 feeds of which several are legitimately empty. Folding that
 * disagreement into one class would give it two jobs and one confused rule.
 *
 * ponytail: the "zero items never overwrites the cache" rule is stated in both
 * classes rather than extracted, and they have since diverged further — MINSAL
 * keeps one entry per feed and always degrades on empty, this one keeps an entry
 * per category and accretes. What is shared is a sentence, not a mechanism; a base
 * class would now have more abstract hooks than shared lines.
 *
 * Two facts about the ISP, both measured 2026-08-02 and neither guessable:
 *
 *  - The host serves ONLY its leaf certificate. Nextcloud's HTTP client fails
 *    verification and returns nothing until the intermediate is imported, which
 *    provisioning phase 07-certs does. Without that phase this class is a no-op
 *    that looks like an outage.
 *  - The WAF answers a literal `curl/*` User-Agent with an EMPTY REPLY and 200
 *    to anything else. That reads exactly like a TLS fault and has already cost
 *    one wrong diagnosis. IClientService sends its own UA, so we are fine — but
 *    do not "simplify" a reproduction to curl and conclude the host is down.
 */
class IspRepository {
	public const ALERTS = 'isp-alerts';

	private const FEED = 'https://www.ispch.gob.cl/categorias-alertas/%s/feed/';

	/**
	 * Where the ISP publishes. A published day belongs to whoever published it, so
	 * it is rendered here and not in the server's zone (UTC in this container) nor
	 * the reader's — Chile spans three, and Easter Island is two hours from Santiago.
	 */
	private const PUBLISHER_TZ = 'America/Santiago';

	/**
	 * The 24 alert categories the ISP publishes. Each is its own feed capped at
	 * WordPress's default of 10 items, so `anamed` is a window like the rest and
	 * not a superset — pulling only it would silently lose the others.
	 *
	 * Measured across six of them: 51 item slots, 43 unique alerts, ~16 percent
	 * overlap, the same withdrawal appearing under both `anamed` and
	 * `retiro-de-mercado`. Hence dedupe by link, below.
	 *
	 * Public because the values are contract: every item carries `categories`, and the
	 * OCS route has to be able to tell a category it does not publish from one that
	 * merely has nothing in it today.
	 */
	public const TERMS = [
		'anamed', 'farmacovigilancia', 'retiro-de-mercado',
		'alerta-falsificado-producto-sin-registro', 'producto-sin-registro',
		'dispositivos-medicos', 'retiro-del-mercado-dispositivo-medico',
		'dispositivo-medico-falsificado', 'cese-de-utilizacion-dp', 'nota-informativa-dp',
		'alertas-cosmeticas', 'nota-informativa-cosmetovigilancia', 'vigilancia-de-cosmeticos',
		'desinfectantes-y-sanitizantes', 'falsificados-sin-registro-sanitario-desinfectantes',
		'advertencia-de-seguridad', 'informacion-de-seguridad', 'informe-tecnico',
		'nota-informativa', 'actualizacion-de-seguridad', 'cese-de-distribucion',
		'actualizacion-de-software', 'robo-de-dispositivos-medicos', 'robo-hurto',
	];

	/**
	 * How long a failed refresh is remembered, so an outage is not retried on every
	 * page load. Well under FRESH_FOR: recovery stays automatic and needs nobody.
	 */
	private const COOLDOWN = 300;

	/**
	 * Older pages fetched per run. Two, and named rather than inline, because it is the
	 * one number that decides how hard the app leans on a host that has already blocked
	 * a machine for asking 255 times (#45). Raising it is a deliberate act.
	 */
	private const BACKFILL_PAGES = 2;

	/**
	 * How long the refresh lock is held before another reader may take it. Above the
	 * worst case the walk can cost — 24 feeds plus 2 backfill pages at 15 s — so a lock
	 * cannot expire under a run that is still going. A process that dies mid-walk leaves
	 * it held for this long, during which readers get the stored copy, which is what they
	 * would have got anyway.
	 */
	private const LOCK_FOR = 600;

	/**
	 * Consecutive unreadable attempts on one page before the walk steps over it. Three,
	 * because a category is reached about twice a day: that is a day and a half of
	 * transient trouble tolerated before one page is given up on.
	 */
	private const STALLS_BEFORE_SKIP = 3;

	/**
	 * The largest response we will read. `timeout` was the only bound before, and the ISP
	 * is a public government WordPress — a compromise or a broken plugin is the realistic
	 * trigger. Measured: a 3 MB feed exhausts the image's 512 MB inside
	 * `simplexml_load_string`, fataling the worker in the middle of a page render.
	 *
	 * 512 KB against a largest-real-feed of 9,5 KB (anamed) and a largest-real-payload of
	 * 43 KB (MINSAL's alerts page): twelve times the biggest thing we have ever seen.
	 *
	 * ponytail: the body is already a string when we see it, so this bounds the PARSE, not
	 * the download. Bounding the download needs `on_headers` plus a chunked-transfer
	 * fallback; if a hostile response ever gets that far, that is the upgrade.
	 */
	private const MAX_BYTES = 512 * 1024;

	/** Same windows as MinsalRepository: fresh for an hour, kept for thirty days. */
	private const FRESH_FOR = 3600;
	private const KEEP_FOR = 30 * 24 * 3600;

	private ICache $cache;

	private IMemcache $lock;

	public function __construct(
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
		$this->lock = $cacheFactory->createLocking('epidemiologia');
	}

	/**
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	public function get(): array {
		$stored = $this->storedAll();
		$oldest = $this->oldestFetch($stored);

		if ($oldest !== null && $oldest + self::FRESH_FOR > $this->time->getTime()) {
			return $this->present($stored, false);
		}

		/*
		 * A failed refresh writes nothing, so without this the cache stays stale and
		 * EVERY request re-runs all 24 fetches. PageController::index() calls all()
		 * synchronously, so at 15s a feed that is a gateway timeout per page load, per
		 * user — the degraded banner never renders because the request never returns.
		 *
		 * Remembering the outage for a few minutes costs one stale-by-minutes list and
		 * turns the outage back into something the app can actually display.
		 */
		if ($this->cache->get($this->cooldownKey()) !== null) {
			return $this->present($stored, true);
		}

		/*
		 * One walk at a time. PageController::index() calls this synchronously and has no
		 * rate limit, so every stale page load used to start its own 24-feed fan-out at
		 * 15 s a feed — ~390 s of wall clock each, each holding a PHP-FPM worker. Eight
		 * tabs on a slow ISP exhausts the pool for the whole instance, not just this app.
		 *
		 * The cooldown does not cover it: degrade() needs EVERY feed to fail, and a merely
		 * slow ISP counts as reached, so it is never armed at all.
		 *
		 * `add()` because it is atomic — check-then-set on an ordinary cache is the race
		 * it is meant to close. A loser serves the copy it already has, which is at most
		 * an hour older than the one being fetched.
		 *
		 * Only when there IS a copy. With nothing stored, answering "no alerts" would be
		 * the exact lie the empty-versus-degraded rule exists to prevent, and a cold start
		 * happens once per instance while the herd this guards against is hourly.
		 */
		$held = $stored !== [] && $this->lock->add($this->lockKey(), 1, self::LOCK_FOR);

		if ($stored !== [] && !$held) {
			return $this->present($stored, false);
		}

		try {
			return $this->refresh($stored);
		} finally {
			// Only what we took. The release used to be unconditional while the acquire
			// was not, so a cold reader — a fresh instance, an evicted entry, or a
			// `memcache.locking` pointing at a different Redis than the distributed one —
			// walked without the lock and then DELETED the lock of whoever was mid-walk.
			// The next arrival won `add()` and started a third fan-out: the mutex defeated
			// in exactly the pile-up it exists for.
			if ($held) {
				$this->lock->remove($this->lockKey());
			}
		}
	}

	/**
	 * Private, and it always should have been: nothing outside this class has ever called
	 * it. Both its parameters are implementation — `$stored` is the raw per-category cache
	 * entry, `backfill` and `stalls` included, and `$ignoreBrakes` cannot be used
	 * correctly without knowing the freno exists and that ignoring it costs 26 requests.
	 *
	 * ADR-0010 says the freno lives inside this class. #75 put it on the interface to give
	 * `probe()` its parameter; this takes it back off without changing a line of
	 * behaviour. `get()`, `probe()`, `search()` and `ALERTS` are what a caller needs.
	 *
	 * @param ?array<string, array> $stored
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function refresh(?array $stored = null, bool $ignoreBrakes = false): array {
		$stored ??= $this->storedAll();

		/*
		 * An empty archive is worth one line, because it is usually not a new instance.
		 * `Memcache\Factory::getGlobalPrefix()` hashes every ENABLED app's name and
		 * version, so installing, enabling, disabling or updating ANY app on the instance
		 * moves this app's whole cache namespace — see ADR-0012. The walk rebuilds at two
		 * pages an hour and takes about three days, during which the pane understates the
		 * archive and the Año menu offers fewer years, silently.
		 */
		if ($stored === []) {
			$this->logger->warning('ISP archive is empty; rebuilding at {pages} pages per hour', [
				'pages' => self::BACKFILL_PAGES,
			]);
		}
		$fetched = [];
		$reached = 0;

		$confirmed = 0;

		$attempted = 0;

		foreach (self::TERMS as $term) {
			if (!$ignoreBrakes && $this->braked($term)) {
				continue;
			}
			$attempted++;

			$answer = $this->fetch($term, $stored[$term] ?? null);
			if ($answer === null) {
				$this->brake($term);
				continue;
			}
			$this->cache->remove($this->frenoKey($term));
			$reached++;

			// Unchanged: the copy we hold is current, so stamp it fresh and move on.
			// Nothing to parse, and NOT an empty answer — see the guard below.
			if ($answer['unchanged']) {
				$confirmed++;
				/*
				 * Spread the stored entry rather than listing its keys. The backfill owns
				 * fields in here — the cursor, and the count of unreadable attempts — and
				 * an explicit list silently dropped them on every hourly write, which is
				 * how the stall counter could never reach its own threshold.
				 */
				$this->cache->set($this->key($term), [
					...$stored[$term] ?? [],
					'items' => $stored[$term]['items'] ?? [],
					'etag' => $stored[$term]['etag'] ?? '',
					'modified' => $stored[$term]['modified'] ?? '',
					'fetched_at' => $this->time->getTime(),
				], self::KEEP_FOR);
				continue;
			}

			$fetched[$term] = [
				'items' => $this->parse($answer['body']),
				'etag' => $answer['etag'],
				'modified' => $answer['modified'],
			];
		}

		/*
		 * The rule that differs from MINSAL, and the reason this class exists.
		 *
		 * An individual ISP feed returning zero items is NORMAL — the categories
		 * are sparse and which ones are empty changes week to week. Degradation is
		 * therefore about the SET: if not one of the 24 feeds could be reached, we
		 * could not ask, and that is a failure. If they answered and had nothing
		 * between them, that is an answer.
		 */
		/*
		 * `$attempted`, not `count(self::TERMS)`. A braked category is not an unreachable
		 * one (CONTEXT.md, Freno): if every category is braked we asked nothing and
		 * learned nothing, which is not an outage. Degrading on that would let the freno
		 * manufacture the very banner it exists to prevent.
		 */
		if ($attempted > 0 && $reached === 0) {
			// `$attempted`, not count(TERMS). refresh() keeps the two apart for the
			// degradation decision and then logged the constant, so "24 intentos" could
			// mean one — in the only line an admin diagnoses an outage from.
			$this->logger->warning('No ISP feed could be reached ({total} attempts of {feeds})', [
				'total' => $attempted, 'feeds' => count(self::TERMS),
			]);

			return $this->degrade($stored);
		}

		/*
		 * Reached is counted before the parse, so "answered with something that is not
		 * RSS" arrives here looking exactly like "answered with nothing". The WAF above
		 * does precisely that. Holding a good copy and parsing nothing out of 24 live
		 * feeds is not an empty archive, it is a failure to read one.
		 *
		 * Only when a good copy exists: a cold cache with genuinely empty feeds is still
		 * a legitimate answer, which is the ISP rule this class exists for.
		 */
		$parsedSomething = array_filter($fetched, static fn (array $f): bool => $f['items'] !== []);
		if ($attempted > 0 && $confirmed === 0 && $parsedSomething === [] && $this->merge($stored) !== []) {
			$this->logger->warning('All {total} ISP feeds queried answered with nothing parseable', [
				'total' => $attempted,
			]);

			return $this->degrade($stored);
		}

		/*
		 * One entry per category, and each one ACCRETES.
		 *
		 * A feed is a ten-item window onto an archive of at least 1.423, and an ISP
		 * alert never expires — so replacing a category with what its window happens
		 * to show today would throw away everything older on every refresh. Union
		 * instead, keyed by link, and the archive assembles itself from the fetch the
		 * app already makes hourly. Nothing is capped, on the measurement in ADR-0009: ~126 KB gzipped
		 * at the full archive, against a page that is already 41 KB.
		 *
		 * A category nobody could reach this round is simply not written, so its last
		 * good copy stands on its own rather than needing a rule to protect it.
		 */
		$now = $this->time->getTime();
		foreach ($fetched as $term => $answer) {
			// Spread, for the reason above: the backfill's fields are not this loop's to
			// enumerate, and enumerating them is how they get dropped.
			$this->cache->set($this->key($term), [
				...$stored[$term] ?? [],
				'items' => $this->union($stored[$term]['items'] ?? [], $answer['items']),
				'etag' => $answer['etag'],
				'modified' => $answer['modified'],
				'fetched_at' => $now,
			], self::KEEP_FOR);
		}

		$this->backfill($this->storedAll());

		return $this->present($this->storedAll(), false);
	}

	/**
	 * Walk the archive backwards, a couple of pages an hour.
	 *
	 * The archive reaches to 2009 and is at least 1.423 alerts, but a bulk walk is not
	 * an option: 255 requests got a machine TLS-blocked by the ISP (#45). Two pages per
	 * run is funded by what conditional GET already saves, so the app costs less than it
	 * did while still backfilling.
	 *
	 * STRICTLY ADDITIVE. It only unions older alerts in. It cannot empty a category,
	 * cannot set degraded, and does not touch freshness — which is why it runs after the
	 * decisions above are made and its result is never consulted by them.
	 *
	 * The cursor lives IN the category's own cache entry, beside the alerts it is a claim
	 * about. It used to live in IAppConfig, to stop a Redis flush restarting every walk
	 * and paying the archive again — which had it exactly backwards: a flush takes the
	 * ARCHIVE, and a bookmark that survives the book says "already read" about ~1.180
	 * alerts nothing will ever fetch again. Re-paying is not the cost of a flush, it is
	 * the only cure for one. Together they cannot disagree; apart, they silently did.
	 *
	 * @param array<string, array> $stored
	 */
	private function backfill(array $stored): void {
		$budget = self::BACKFILL_PAGES;

		/*
		 * Start at a different category each hour instead of always at TERMS[0].
		 * With a fixed start the first categories would walk to 2009 while the last
		 * never began. Derived from the clock rather than stored, because a cursor for
		 * the cursor is state that can go stale on its own.
		 */
		$count = count(self::TERMS);
		$offset = intdiv($this->time->getTime(), self::FRESH_FOR) % $count;

		for ($i = 0; $i < $count && $budget > 0; $i++) {
			$term = self::TERMS[($offset + $i) % $count];

			$entry = $stored[$term] ?? [];
			$page = (int)($entry['backfill'] ?? 2);

			if ($page === 0 || $this->braked($term)) {
				continue;   // exhausted, or already backing off — never spend budget on it
			}
			$budget--;

			$items = $this->page($term, $page);

			/*
			 * Could not read it: the cursor does NOT advance, so the page is retried next
			 * run rather than skipped forever.
			 *
			 * No brake. The freno gates the HOURLY fetch, and a deep page is the expensive
			 * uncached query while the front feed is the cached one — so braking here let
			 * the archive walk silence a healthy category for 2 h, then 4, then 24. That
			 * is the ordering this method's docblock promises it does not break, and
			 * `continue` alone already leaves the page to be retried.
			 */
			if ($items === null) {
				/*
				 * With a counter, because without one this was a permanent silent stall.
				 * `parse()` returns nothing for a body over the size cap, for a page whose
				 * every item fails the https guard, and for anything that is not RSS — so a
				 * page that can NEVER be read was re-requested on every rotation forever,
				 * spending budget, while that category's archive stopped growing and
				 * nothing degraded. Three tries, then step over it and say so: one lost
				 * page beats a walk that is dead and quiet about it.
				 */
				$stalls = (int)($entry['stalls'] ?? 0) + 1;
				if ($stalls < self::STALLS_BEFORE_SKIP) {
					$this->cache->set($this->key($term), [...$entry, 'stalls' => $stalls], self::KEEP_FOR);
					continue;
				}

				$this->logger->warning('Page {page} of {term} unreadable {n} times, backfill skips it', [
					'term' => $term, 'page' => $page, 'n' => $stalls,
				]);
				$this->cache->set(
					$this->key($term),
					[...$entry, 'backfill' => $page + 1, 'stalls' => 0],
					self::KEEP_FOR,
				);
				continue;
			}

			// Past the end. Retire the cursor: this category is fully walked and must
			// never be re-walked, which is the difference between a backfill and a loop.
			if ($items === []) {
				$this->cache->set($this->key($term), [...$entry, 'backfill' => 0], self::KEEP_FOR);
				continue;
			}

			$this->cache->set($this->key($term), [
				'items' => $this->union($entry['items'] ?? [], $items),
				'etag' => $entry['etag'] ?? '',
				'modified' => $entry['modified'] ?? '',
				// Deliberately preserved, not stamped: freshness means "when did we last
				// hear from this feed about NEW alerts". A backfill page is old news, and
				// stamping it would make a stale category look fresh.
				'fetched_at' => $entry['fetched_at'] ?? $this->time->getTime(),
				'backfill' => $page + 1,
				'stalls' => 0,
			], self::KEEP_FOR);
		}
	}

	/**
	 * One older page of a category. Unconditional — every page is different bytes, so
	 * validators would only ever miss.
	 *
	 * @return ?list<array<string, string>> null could not read it · [] past the last page
	 */
	private function page(string $term, int $page): ?array {
		try {
			$response = $this->clients->newClient()->get(
				sprintf(self::FEED, $term) . '?paged=' . $page,
				['timeout' => 15],
			);
		} catch (Throwable $e) {
			// WordPress answers 404 past the last page, and the client throws on it. That
			// is exhaustion, not failure, and the two must not be confused: treating it as
			// failure would brake a healthy feed forever at the end of its own archive.
			if (str_contains($e->getMessage(), '404')) {
				return [];
			}
			$this->logger->debug('Page {page} of {term} unavailable', [
				'term' => $term, 'page' => $page, 'exception' => $e,
			]);

			return null;
		}

		/*
		 * Empty means "could not read it", NOT "past the end". WordPress 404s past the
		 * last page and that is caught above; a 200 parsing to nothing is the ISP's WAF,
		 * a soft-404 theme page, a truncated body or a redirect to HTML — all of which
		 * arrive here as []. refresh() has guarded exactly this since the class was
		 * written. This path had not, so one WAF answer retired a category permanently.
		 */
		$body = (string)$response->getBody();
		if (strlen($body) > self::MAX_BYTES) {
			$this->logger->warning('Page {page} of {term} of {bytes} bytes, discarded', [
				'term' => $term, 'page' => $page, 'bytes' => strlen($body),
			]);

			return null;
		}

		$items = $this->parse($body);

		return $items === [] ? null : $items;
	}

	/**
	 * Cheap liveness signal for the daily probe. False means a human has to look.
	 *
	 * Asks THROUGH the freno. `$attempted > 0` is the right rule for a reader — a braked
	 * category is not an unreachable one — but the detector reusing it made the sondeo
	 * report an outage as silence: five failed rounds cap the backoff at a day, so every
	 * category is braked, nothing is attempted, and "we asked nothing" was answered as
	 * "nothing is wrong". The count was cleared, the bell was emptied, and Visión general
	 * said all sources read fine while the ISP had been down a week.
	 *
	 * Twenty-six requests once a day is what a detector costs here — 24 feeds plus the two
	 * backfill pages `refresh()` always ends with.
	 *
	 * It does not SKIP the freno's bookkeeping, and an earlier version of this comment
	 * claimed it did: a category that answers has its freno cleared and one that fails has
	 * its count escalated, exactly as on the hourly path. That is the right behaviour — a
	 * category that just answered is not braked — but it is a write, so say so.
	 */
	public function probe(): bool {
		return $this->refresh(ignoreBrakes: true)['degraded'] === false;
	}

	/**
	 * Cache-only search, for Nextcloud's global search box. Never fetches, for the
	 * reason MinsalRepository::search() spells out — 24 outbound requests inside
	 * every user's every search would be far worse than MINSAL's one.
	 *
	 * Matches the title AND the raw category slugs, so "dispositivo" finds device
	 * alerts whose title never says the word — the title names the product. The
	 * pane matches prettified labels instead; duplicating its 24-entry map into PHP
	 * would buy a few accents in a surface whose job is only to get someone into
	 * the pane, where the real filters are.
	 *
	 * @return list<array<string, string>>
	 */
	public function search(string $term): array {
		$needle = MinsalRepository::fold($term);
		// Through present(), like every other way out. Merging straight from the store
		// meant the search box skipped the `h` strip and the day derivation, so it showed
		// a different date than the pane for the same alert, and shipped an internal
		// field into the search provider.
		if ($needle === '') {
			return [];
		}

		// The guard first. present() is 24 cache reads, a merge that dedupes ~2.500
		// memberships and sorts ~1.423 items, and a DateTimeImmutable per item — and
		// every registered provider runs on every unified search, for every user.
		$items = $this->present($this->storedAll(), false)['items'];

		if ($items === []) {
			return [];
		}

		return array_values(array_filter(
			$items,
			static fn (array $item): bool => str_contains(
				MinsalRepository::fold($item['title'] . ' ' . implode(' ', $item['categories'] ?? [])),
				$needle,
			),
		));
	}

	/**
	 * RSS 2.0: title, link, pubDate. Parsed with the XML reader rather than by
	 * regex — unlike MINSAL's page, this really is a document with a schema.
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
		foreach ($feed->channel->item as $node) {
			$url = trim((string)$node->link);
			// The same normalisation MinsalRepository does. Seven of 168 live ISP titles
			// carry a leading NBSP or a trailing EN SPACE, and the leading ones render as
			// a visible indent on the pane's first row. This used to say `\s` cannot match
			// those under /u; measured, it can — see MinsalRepository::text(). The named
			// codepoints stay because they cost nothing, not because `trim()` needs them.
			$title = trim((string)preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', (string)$node->title));

			// Same guard as the MINSAL parser and for the same reason: htmlspecialchars
			// cannot make a URL scheme safe, so only https reaches an href.
			if ($title === '' || stripos($url, 'https://') !== 0) {
				continue;
			}

			$at = strtotime((string)$node->pubDate);

			$items[] = [
				'title' => $title,
				'url' => $url,
				/*
				 * Unlike MINSAL, the ISP publishes a real day. Kept as ISO for the UI
				 * and as an epoch for the sort, so the frontend never re-parses a date.
				 *
				 * Rendered in the PUBLISHER'S timezone, not the server's. `date()` uses
				 * the container's UTC, which moves anything published after 20:00 in
				 * Santiago to the next day — measured on the live archive 2026-08-05,
				 * one alert of 173 was a day late. The day an alert carries is a fact
				 * about the ISP, so it is formatted where the ISP published it. Not the
				 * reader's zone either: Chile spans three, and a recall does not change
				 * date because the clinic is in Punta Arenas.
				 */
				'date' => $at === false ? '' : $this->publisherDay($at),
				'at' => $at === false ? 0 : $at,
				/*
				 * A fingerprint of the content, so a revision can be SEEN. The ISP edits
				 * an alert in place — typically adding affected lots — leaving the URL and
				 * usually the title alone, so neither can detect it. The description is
				 * where the change lands.
				 *
				 * The hash is stored and the description is NOT: the archive is ~1.423
				 * alerts and keeping their bodies would multiply it for a field nothing
				 * renders. Twelve hex characters is ample to tell two versions apart.
				 */
				'h' => substr(sha1($title . '|' . trim((string)$node->description)), 0, 12),
			];
		}

		return $items;
	}

	/**
	 * One feed, asked conditionally once we have seen it before.
	 *
	 * Measured 2026-08-04: the host serves both `ETag` and `Last-Modified` and honours
	 * them, answering `304` with an empty body — 9.708 bytes down to nothing. The app
	 * used to re-download all 24 every hour, almost all of it identical to what it held.
	 *
	 * @param ?array $stored this category's entry, for its validators
	 * @return ?array{unchanged: bool, body: string, etag: string, modified: string}
	 *         null when the feed could not be reached at all
	 */
	private function fetch(string $term, ?array $stored): ?array {
		$headers = [];
		if (($stored['etag'] ?? '') !== '') {
			$headers['If-None-Match'] = $stored['etag'];
		}
		if (($stored['modified'] ?? '') !== '') {
			$headers['If-Modified-Since'] = $stored['modified'];
		}

		try {
			$response = $this->clients->newClient()->get(sprintf(self::FEED, $term), [
				'timeout' => 15,
				'headers' => $headers,
			]);
		} catch (Throwable $e) {
			// Per-feed failure is not reported: with 24 feeds a single blip would be
			// noise, and refresh() judges the set. Only reaching none of them is news.
			$this->logger->debug('ISP feed {term} unavailable', ['term' => $term, 'exception' => $e]);

			return null;
		}

		$body = (string)$response->getBody();

		/*
		 * Too big to read is "could not read it", not "answered with nothing". Rejecting
		 * it inside parse() instead made an oversize answer indistinguishable from a
		 * legitimately empty feed — and on a cold cache the unparseable-set guard below
		 * does not fire, so 24 oversize responses were written as a healthy empty archive
		 * and kept for thirty days. Here it counts as unreachable, which is what it is.
		 */
		if (strlen($body) > self::MAX_BYTES) {
			$this->logger->warning('Response from {term} of {bytes} bytes, discarded', [
				'term' => $term, 'bytes' => strlen($body),
			]);

			return null;
		}

		return [
			'unchanged' => $response->getStatusCode() === 304,
			'body' => $body,
			'etag' => $response->getHeader('ETag'),
			'modified' => $response->getHeader('Last-Modified'),
		];
	}

	/**
	 * Every category's entry, keyed by category.
	 *
	 * Reading one category is one cache read of a few KB — the whole point of the
	 * split. This reads all 24 because get() still hands the merged archive to the
	 * page; the per-category route is #51's.
	 *
	 * @return array<string, array{items: list<array<string, string>>, fetched_at: int}>
	 */
	private function storedAll(): array {
		$stored = [];
		foreach (self::TERMS as $term) {
			$entry = $this->stored($term);
			if ($entry !== null) {
				$stored[$term] = $entry;
			}
		}

		return $stored;
	}

	/**
	 * One category's entry, or null when there is nothing usable there.
	 *
	 * Shape-checked rather than merely is_array(): an entry written by an older
	 * version has no 'items' and no 'fetched_at', and reading it half-way turned
	 * `$stored['fetched_at'] + FRESH_FOR` into an undefined-key warning coerced to
	 * 3600 — a cache entry that quietly claimed to be an hour old. MinsalRepository
	 * has always checked; this one did not.
	 */
	private function stored(string $term): ?array {
		$entry = $this->cache->get($this->key($term));

		return is_array($entry) && isset($entry['items'], $entry['fetched_at']) && is_array($entry['items'])
			? $entry
			: null;
	}

	/**
	 * The archive as one list: deduped by link, newest first, every category an
	 * alert was filed under carried on it.
	 *
	 * The categories come from the KEYS rather than from anything in the feed — the
	 * <category> elements are empty, so which entry holds an alert is the only place
	 * that fact exists, and it is the one facet a reader can filter by.
	 *
	 * @param array<string, array> $stored
	 * @return list<array<string, string>>
	 */
	private function merge(array $stored): array {
		$items = [];
		foreach ($stored as $term => $entry) {
			foreach ($entry['items'] as $item) {
				$url = $item['url'];
				if (isset($items[$url])) {
					$items[$url]['categories'][] = $term;
					continue;
				}
				$item['categories'] = [$term];
				$items[$url] = $item;
			}
		}

		$items = array_values($items);
		usort($items, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

		return $items;
	}

	/**
	 * Stored items plus freshly fetched ones, keyed by link, the fetched copy winning
	 * so an edited title replaces the one we held.
	 *
	 * @param list<array<string, string>> $stored
	 * @param list<array<string, string>> $fetched
	 * @return list<array<string, string>>
	 */
	private function union(array $stored, array $fetched): array {
		$byUrl = [];
		foreach ($stored as $item) {
			$byUrl[$item['url']] = $item;
		}

		foreach ($fetched as $item) {
			$was = $byUrl[$item['url']] ?? null;

			if ($was === null) {
				$byUrl[$item['url']] = $item;
				continue;
			}

			/*
			 * Same URL, same fingerprint: nothing happened. Keep the STORED copy rather
			 * than the fetched one, so an existing `updated` marker survives — taking the
			 * new copy would silently clear the marker on the next hourly pass, and the
			 * revision would stop being visible the moment it stopped being news.
			 */
			if (($was['h'] ?? '') === ($item['h'] ?? '')) {
				continue;
			}

			/*
			 * Revised. Replace the content, but keep the original publication date and
			 * therefore its place in the list: floating a revised 2024 alert to the top
			 * would present it as new, which is a different lie from the one being fixed.
			 * The marker carries the day the revision was SEEN — the ISP publishes no
			 * revision date, and inventing one would be worse than reporting our own.
			 */
			$byUrl[$item['url']] = [
				...$item,
				'date' => $was['date'],
				'at' => $was['at'],
				'updated' => $this->publisherDay($this->time->getTime()),
			];
		}

		return array_values($byUrl);
	}

	/**
	 * The age of the STALEST category, so freshness never claims more than the whole
	 * archive can back. Null when nothing is cached at all.
	 *
	 * @param array<string, array> $stored
	 */
	private function oldestFetch(array $stored): ?int {
		$times = array_column($stored, 'fetched_at');

		return $times === [] ? null : min($times);
	}

	/**
	 * Degrade, and remember it for a short while so an outage is not re-attempted on
	 * every page load. Every degrade path goes through here, so none can forget to.
	 *
	 * @param array<string, array> $stored
	 */
	private function degrade(array $stored): array {
		$this->cache->set($this->cooldownKey(), 1, self::COOLDOWN);

		return $this->present($stored, true);
	}

	/** @param array<string, array> $stored */
	private function present(array $stored, bool $degraded): array {
		/*
		 * `h` is how union() spots an alert the ISP rewrote. It is a cache concern:
		 * nothing renders it, and an internal field that rides out to every browser
		 * and into the OCS contract is one nobody can change later without breaking
		 * a consumer that never should have seen it. Stripped here, at the one place
		 * items leave the repository, rather than at each of the three writers.
		 */
		$items = array_map(
			function (array $i): array {
				unset($i['h']);

				/*
				 * Derived here, not trusted from storage. The archive accretes and
				 * union() keeps what it already holds, so items written before the day
				 * was rendered in the publisher's zone would have kept a UTC day
				 * forever — and re-fetching 1.423 alerts to correct them is what got a
				 * machine WAF-blocked (#45). `at` is the instant and it is stored, so
				 * every item is corrected on the way out at no cost.
				 */
				if (($i['at'] ?? 0) > 0) {
					$i['date'] = $this->publisherDay((int)$i['at']);
				}

				return $i;
			},
			$this->merge($stored),
		);

		/*
		 * The scheme guard again, on the way out. parse() already refuses anything that
		 * is not https, but that is ingest-only on a store that never evicts and whose
		 * every write refreshes KEEP_FOR — so one bad URL that ever got in would be
		 * trusted forever, and AlertRow binds `url` straight to href with no guard of its
		 * own. Here it covers the four renderers and the search provider at once, which
		 * is the point of having one door.
		 */
		$items = array_values(array_filter(
			$items,
			static fn (array $i): bool => stripos((string)($i['url'] ?? ''), 'https://') === 0,
		));

		return [
			'items' => $items,
			'fetched_at' => $this->oldestFetch($stored),
			'degraded' => $degraded,
		];
	}

	/** One entry per category — see refresh(). */
	/**
	 * Is this category still braked? The stored value IS the moment it expires, so
	 * asking is a comparison and there is no sweep to run.
	 */
	private function braked(string $term): bool {
		$until = $this->cache->get($this->frenoKey($term));

		return $until !== null && $this->time->getTime() < (int)$until['until'];
	}

	/**
	 * Doubling from two hours, capped at a day. Two hours because refreshes are
	 * hourly (FRESH_FOR): anything shorter is not a freno, it is a rounding error —
	 * the category would be asked again on the very next pass. The count outlives
	 * the wait it caused, which is what makes the next failure cost more; a success
	 * removes the key outright, so recovery is one step and not a slow unwind.
	 */
	private function brake(string $term): void {
		$n = (int)($this->cache->get($this->frenoKey($term))['n'] ?? 0) + 1;
		$wait = min(2 * self::FRESH_FOR * (2 ** ($n - 1)), 86400);

		/*
		 * `warning`, not `debug`: Nextcloud's default loglevel is warn, so at debug one
		 * dead category was invisible everywhere. A renamed slug 404s forever, 23 feeds
		 * keep degraded false, the probe stays true and the setup check stays green —
		 * until KEEP_FOR expires the entry and the category leaves the UI too, because
		 * the rail is derived from the data that is loaded. Here, and not at each failed
		 * request, so it is news once per escalation rather than hourly noise.
		 */
		$this->logger->warning('ISP category {term} under freno {n} time(s), {wait}s', [
			'term' => $term, 'n' => $n, 'wait' => $wait,
		]);

		$this->cache->set($this->frenoKey($term), [
			'n' => $n,
			'until' => $this->time->getTime() + $wait,
		], 86400 + $wait);
	}

	/**
	 * An instant, as the day it was in Chile.
	 *
	 * The one place that turns a moment into a calendar day. It was written out three
	 * times and one of the three used `date()`, which reads the container's zone — so the
	 * revision marker ran a day ahead for anything seen after 20:00 in Santiago, in the
	 * pill beside two dates that were right. Not the reader's zone either: Chile spans
	 * three, and a recall does not change date because the clinic is in Punta Arenas.
	 */
	private function publisherDay(int $at): string {
		return (new \DateTimeImmutable('@' . $at))
			->setTimezone(new \DateTimeZone(self::PUBLISHER_TZ))
			->format('Y-m-d');
	}

	private function frenoKey(string $term): string {
		return 'freno-' . self::ALERTS . '-' . $term;
	}

	private function key(string $term): string {
		return 'feed-' . self::ALERTS . '-' . $term;
	}

	private function lockKey(): string {
		return 'refresh-' . self::ALERTS;
	}

	/** Distinct from key(), or the cooldown flag would be read as the archive. */
	private function cooldownKey(): string {
		return 'cooldown-' . self::ALERTS;
	}
}
