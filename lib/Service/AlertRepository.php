<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use Normalizer;
use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One alert fuente's pipeline — ADR-0017. Every alert fuente runs through
 * this class, one instance each, built by AlertRepositories from its Fuente:
 * a named, bounded fetch through its host's freno → the Fuente's parser →
 * the cache and the table → stale-serve and revalidation → degraded. What
 * differs between fuentes is the Fuente's; everything here is the same for
 * all of them.
 *
 * It was MinsalRepository while degreyd's page was the only alert fuente,
 * and it moved without a behaviour change: degreyd's address, keys, log
 * sentences and wire shape are what its suite pinned before the move
 * (AlertRepositoryTest).
 *
 * A read-through proxy over the document a publisher lists its alerts in.
 * The parsed set persists to the app's own table as metadata — the durable
 * last-good copy an app update's cache flush no longer takes with it
 * (ADR-0015 §2) — but nothing is mirrored: the documents are linked where
 * their publisher keeps them, so staff always open the version in force.
 *
 * Two storage rules, one per kind of fuente (ADR-0017 D6). An IN-FORCE page
 * (degreyd's, epi's) lists what is in force today, so its set is replaced
 * whole and a withdrawn alert vanishes with it. A STREAM (the ISP's
 * categories, OPS/OMS's list) lists only its latest posts, so every item it ever listed is
 * kept, and its older pages are walked once for the history the first read
 * could not see (converge()). The Fuente's backfill is what tells them apart.
 */
class AlertRepository {
	/** The largest response we will read; request() carries the measurement. */
	private const MAX_BYTES = 512 * 1024;

	/**
	 * How long a failed refresh is remembered, so an outage is not retried on every
	 * page load. Well under FRESH_FOR: recovery stays automatic and needs nobody.
	 */
	private const COOLDOWN = 300;

	/** How long a cached copy counts as fresh. */
	private const FRESH_FOR = 3600;

	/**
	 * How long the stale-serve dispatch marker lives: a revalidation job
	 * that never runs (crashed, or a cron-less install between requests)
	 * must not suppress the next dispatch forever — five minutes, the same
	 * self-healing window the outage cooldown carries.
	 */
	private const MARKER_FOR = 300;

	/**
	 * How long the copy survives in Redis — far longer than FRESH_FOR, on purpose.
	 * When a publisher is unreachable we serve the last good copy flagged `degraded`,
	 * and we can only do that if it outlived its own freshness window.
	 */
	private const KEEP_FOR = 30 * 24 * 3600;

	/**
	 * Who is asking, on every request. A bot the publishers have no reason to
	 * block is a visitor they can name: anonymous is not a right, and a WAF that
	 * blocks what it cannot identify looks, from this side, exactly like an
	 * outage (ADR-0015 §4).
	 */
	private const UA = 'APS-Conecta-Epidemiologia/1.0 (+https://github.com/APS-Conecta/epidemiologia)';

	private ICache $cache;

	private Freno $freno;

	public function __construct(
		private Fuente $fuente,
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private AlertMapper $alerts,
		ICacheFactory $cacheFactory,
		private IJobList $jobs,
		private IAppConfig $config,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
		// One grain — the fuente's host — on the same cache it reads; the
		// publisher is who the brake's log sentence names, so degreyd's still
		// reads "MINSAL answered …" (L3-02).
		$this->freno = new Freno($this->cache, $this->time, $this->logger, 'freno-' . $fuente->host, $fuente->publisher);
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
		 * retries. PageController::index() fetches synchronously while someone waits,
		 * so against a host that drops packets rather than refusing them this is a
		 * gateway timeout instead of the degraded banner. The cooldown is kept per
		 * host (cooldownKey()), so a sibling's outage holds this read too — the
		 * informe answers to the ISP host's the same way (ADR-0017).
		 */
		if ($this->cache->get($this->cooldownKey()) !== null) {
			return $this->present($stored, true);
		}

		/*
		 * A refusal armed the freno — request() classifies. While the brake
		 * holds, this read must not ask again: a 403 is an answer, not a
		 * challenge (ADR-0015 §4). Served like any degraded copy, from the
		 * cache or, after an app update, from the table. The probe is the one
		 * ask that goes through, once a day — see probe().
		 */
		if ($this->freno->braked()) {
			return $this->present($stored, true);
		}

		/*
		 * L1-01/L3-04: an expired-but-kept copy serves immediately, and the
		 * refresh happens behind the response — first paint never waits on
		 * a healthy-but-slow publisher. A stale serve is NOT the degraded
		 * state: fetched_at carries the honest date and the banner appears
		 * only when a revalidation actually fails (the cooldown it arms
		 * makes the NEXT read degraded).
		 */
		if ($stored !== null) {
			$this->dispatch();

			return $this->present($stored, false);
		}

		/*
		 * Cold: nothing stored, so there is no copy to serve. The first cold
		 * read fetches synchronously, as a fresh install always has — once per
		 * cache lifetime, which is the marker's lifetime (an app update moves
		 * the namespace, ADR-0012, and the next cold read is a first one again).
		 * A later cold read — the first fetch failed and its cooldown lapsed
		 * with nothing stored — moves the fetch behind the response and says
		 * degraded (ADR-0017 D9): a never-stored fuente behind a blocked host
		 * would otherwise hold a render for one bounded timeout every five
		 * minutes, for as long as the host stays blocked.
		 */
		if ($this->cache->get($this->coldKey()) === null) {
			$this->cache->set($this->coldKey(), 1, self::KEEP_FOR);

			return $this->refresh($stored);
		}

		$this->dispatch();

		return $this->present(null, true);
	}

	/**
	 * One revalidation behind the response — the stale serve's and the later
	 * cold read's. The marker dedups the dispatch, and its TTL self-heals a
	 * job that never runs.
	 */
	private function dispatch(): void {
		if ($this->cache->get($this->revalidatingKey()) !== null) {
			return;
		}

		$this->cache->set($this->revalidatingKey(), 1, self::MARKER_FOR);
		// The class name as a string, deliberately: importing the
		// job would close AlertRepository → RevalidateJob → Sources
		// → AlertRepositories → AlertRepository, the cycle shape
		// Phase 1 spent itself killing. The suite pins the string
		// against RevalidateJob::class, so a rename answers as a
		// failing test, not as a silently dead dispatch.
		$this->jobs->add('OCA\\Epidemiologia\\BackgroundJob\\RevalidateJob', ['source' => $this->fuente->id]);
	}

	/**
	 * Fetch, validate, and either cache or degrade.
	 *
	 * Private: nothing outside this class calls it, and `$stored` is the raw cache entry.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function refresh(?array $stored = null): array {
		$html = $this->request();
		$items = $html === null ? [] : $this->fuente->parser->parse($html, $this->fuente->subject);

		return $items === [] ? $this->fail($html, $stored) : $this->store($items, $stored);
	}

	/**
	 * The zero-items path, shared by refresh() and probe(): same log, same
	 * degrade, same cooldown. Split out so probe() can reach this conclusion
	 * from the page it already fetched and parsed, instead of asking refresh()
	 * to fetch the same page a second time to find out what probe() already
	 * knows — see probe()'s own docblock for why that second fetch mattered.
	 *
	 * A parse returning zero items is a failure, never a result. Caching it
	 * would replace every real alert with an empty list, which a clinician
	 * reads as "no hay alertas vigentes" — the most dangerous thing this app
	 * could do. So: leave the last good copy alone — cache and table both —
	 * and say we are degraded.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function fail(?string $html, ?array $stored): array {
		/*
		 * Two different faults reach this guard, and naming them apart is the whole
		 * value of the line: this is called both when the page answered with content
		 * that parses to nothing — the restructure this rule exists to survive — and
		 * when nothing was read at all, because `$html` is null.
		 *
		 * Conflating them said "returned 0 items" for both, which is a claim about
		 * MINSAL that is false in the second case: an unreachable host, an oversize
		 * body and a WAF block page all become "MINSAL is publishing an empty list",
		 * sending whoever reads it to look for a MINSAL that stopped publishing — the
		 * wrong diagnosis for exactly the fault this fuente's previous host served,
		 * without interruption, for eleven months before it moved here.
		 *
		 * No 'app' key: the injected LoggerInterface is already app-scoped —
		 * DIContainer wraps it in ScopedPsrLogger, which merges it in on every call.
		 * A {subject} placeholder instead, back since every alert fuente shares
		 * these lines (ADR-0017): a line about one fuente must name it, and
		 * degreyd's renders exactly as it read before the move.
		 */
		$this->logger->warning(
			$html === null
				? 'The {subject} could not be read'
				: 'The {subject} carried no alerts',
			['subject' => $this->fuente->subject],
		);

		return $this->degrade($stored);
	}

	/**
	 * Turns a freshly parsed list into the cache entry both refresh() and
	 * probe() write — including the digest probe() compares next time — and
	 * the table's whole set, replaced (ADR-0015 §2). A stream keeps instead
	 * (accumulate()).
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function store(array $items, ?array $stored): array {
		if ($this->fuente->backfill !== []) {
			return $this->accumulate($items, $stored);
		}

		$fresh = [
			'items' => $items,
			'digest' => self::digest($items),
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key(), $fresh, self::KEEP_FOR);

		/*
		 * The whole set, replaced: a withdrawn alert vanishes with the set that
		 * no longer names it. Cache first, table second — a storage failure is
		 * its own fault class (logged, retried on the next store()), never a
		 * degrade: the fetch succeeded and the fresh copy serves regardless.
		 */
		try {
			$this->alerts->replaceSet($this->fuente->id, array_map(self::alert(...), $items));
		} catch (Throwable $e) {
			$this->logger->warning('Could not persist the {name} set', [
				'name' => $this->fuente->name,
				'exception' => $e,
			]);
		}

		return $this->present($fresh, false);
	}

	/**
	 * A stream's store (ADR-0017 D6): the reading is upserted into the
	 * fuente's rows, and the entry served — and cached — is those rows read
	 * back, so an item the publisher stopped listing stays served. Table
	 * first, cache second, because the cache holds what the table answered.
	 *
	 * A storage failure keeps store()'s rule: logged, never a degrade — the
	 * fetch succeeded — and the reading serves accumulated over the last copy
	 * this fuente served, so a broken table never shrinks a stream to its
	 * latest page. IspReportRepository::store() is the precedent.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function accumulate(array $items, ?array $stored): array {
		try {
			foreach ($items as $item) {
				$this->alerts->upsert($this->fuente->id, self::alert($item));
			}

			$kept = array_map(self::item(...), $this->alerts->readSet($this->fuente->id));
		} catch (Throwable $e) {
			$this->logger->warning('Could not persist the {name} set', [
				'name' => $this->fuente->name,
				'exception' => $e,
			]);

			$kept = self::union($stored['items'] ?? [], $items);
		}

		$fresh = [
			'items' => $kept,
			'digest' => self::digest($items),
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key(), $fresh, self::KEEP_FOR);

		return $this->present($fresh, false);
	}

	/**
	 * A stream's one-shot backfill (ADR-0017 D7): its Fuente's older pages,
	 * walked once per install for the history page 1 cannot carry — ten
	 * posts is a few weeks of a busy ISP category — then never again. The
	 * refresh job's door (Sources::converge()), off the request path.
	 *
	 * The flag lives in app config, not the cache, for the informe cursor's
	 * reason (IspReportRepository::backfill()): a cache flush must not say
	 * "walk again" about a table that still holds the history, and app config
	 * dies exactly when the table does.
	 *
	 * Not before the fuente has a present: history upserted into a table that
	 * holds no page 1 would be served as the durable copy, a list of old
	 * alerts standing in for today's. Not while its host cools down or is
	 * braked either — a walk is an ask like any other, and the host said wait.
	 *
	 * STRICTLY ADDITIVE: rows in, never out; the cache entry and the degraded
	 * flag are untouched. Walked rows reach the pane on the next store(),
	 * whose read-back is the table's one way out.
	 */
	public function converge(): void {
		$flag = 'backfilled_' . $this->fuente->id;

		if ($this->fuente->backfill === [] || $this->config->getAppValueBool($flag)) {
			return;
		}

		if ($this->stored() === null
			|| $this->cache->get($this->cooldownKey()) !== null
			|| $this->freno->braked()) {
			return;
		}

		foreach ($this->fuente->backfill as $url) {
			$items = $this->page($url);

			// Unreadable: the flag stays down, and tomorrow's pass asks again.
			if ($items === null) {
				return;
			}

			// Past the last page: a category with less history than the walk.
			if ($items === []) {
				break;
			}

			try {
				foreach ($items as $item) {
					$this->alerts->upsert($this->fuente->id, self::alert($item));
				}
			} catch (Throwable $e) {
				$this->logger->warning('Could not persist the {name} set', [
					'name' => $this->fuente->name,
					'exception' => $e,
				]);

				return;
			}
		}

		$this->config->setAppValueBool($flag, true);
	}

	/**
	 * One older page, read by the Fuente's parser.
	 *
	 * Deliberately outside the freno, both ways, as the informe's deep pages
	 * are (IspReportRepository::page()): a deep page's refusal must not brake
	 * the cheap page-1 read, and a deep page's answer does not vouch for it.
	 *
	 * Logged at warning where the informe's page() logs at debug, on purpose:
	 * the informe counts its stalls and warns on the third, and a stream has
	 * no stall counter — a walk that never completes must say so in the log
	 * an admin reads, and the daily cadence bounds it to one line a day.
	 *
	 * @return ?list<array<string, string>> null could not read it · [] past the last page
	 */
	private function page(string $url): ?array {
		$client = $this->clients->newClient();

		try {
			$body = (string)$client->get($url, $this->options())->getBody();
		} catch (Throwable $e) {
			// WordPress answers 404 past its last page: exhaustion, not failure.
			// Read from the response's status, never from the message's prose.
			if (Freno::status($client, $e) === 404) {
				return [];
			}

			$this->logger->warning('An older page of the {subject} could not be read', [
				'subject' => $this->fuente->subject,
				'url' => $url,
				'exception' => $e,
			]);

			return null;
		}

		if (strlen($body) > self::MAX_BYTES) {
			$this->logger->warning('An older page of the {subject} of {bytes} bytes, discarded', [
				'subject' => $this->fuente->subject,
				'bytes' => strlen($body),
			]);

			return null;
		}

		$items = $this->fuente->parser->parse($body, $this->fuente->subject);

		/*
		 * Empty means "could not read it", NOT "past the end": the 404 is
		 * caught above, and a 200 parsing to nothing is a WAF or a soft-404
		 * page — one such answer once retired the informe's walk for good.
		 */
		return $items === [] ? null : $items;
	}

	/**
	 * Daily breakage detection.
	 *
	 * Compares a digest of the parsed items, NOT a publication date: degreyd's
	 * `modified` field read 2026-06-24 while its table listed alerts dated 31 July
	 * 2026, so a date comparison reports "unchanged" straight through real edits.
	 * The sondeo exists to DETECT; it has to compare something that moves.
	 *
	 * One fetch, always — there is no field-limited request left to ask for now
	 * that the page carries no JSON envelope, so an unchanged, a changed and a
	 * broken probe all cost exactly what get()'s own refresh does. The digest's
	 * saving is narrower than a fetch: it is the redundant CACHE WRITE that gets
	 * skipped when nothing moved, decided from the page this call already fetched
	 * and parsed rather than by asking refresh() to fetch it again — that second
	 * round trip against a foreign government server is what fail()/store() above
	 * exist to avoid on every path, not only this one.
	 *
	 * Asks THROUGH the freno, exactly as IspReportRepository's does: a braked host is
	 * not assumed unreachable, it is ASKED, once a day — #218beba's lesson, that
	 * "we asked nothing" must never be answered as "nothing is wrong". A refusal
	 * still escalates the brake's count and an answer still clears it (request()
	 * does both), so the sondeo is also the brake's road back: the first day
	 * the host answers 200 again is the day the hourly reads resume.
	 *
	 * @return bool false when the publisher is unreachable, or changed into something
	 *              we can no longer parse. Either way a human has to look.
	 */
	public function probe(): bool {
		$html = $this->request();
		$items = $html === null ? [] : $this->fuente->parser->parse($html, $this->fuente->subject);

		if ($items === []) {
			$this->fail($html, $this->stored());

			return false;
		}

		$stored = $this->stored();
		if ($stored !== null && ($stored['digest'] ?? null) === self::digest($items)) {
			return true;
		}

		$this->store($items, $stored);

		return true;
	}

	/**
	 * Cache-then-table search, for Nextcloud's global search box.
	 *
	 * It must never fetch. Every registered provider runs on every search, so one
	 * that called out to a publisher would make all of Nextcloud's search wait on a
	 * foreign government server — for every user, including someone looking for a
	 * Word document. The cache is the hot path; when only the cache is cold — an
	 * app update's flush (ADR-0012) — the durable table behind the fuente answers
	 * (stored(), ADR-0015 §2), so the box self-heals without a single fetch: a
	 * table read is not a fetch.
	 *
	 * @return list<array<string, string>>
	 */
	public function search(string $term): array {
		$needle = self::fold($term);
		$stored = $this->stored();

		if ($needle === '' || $stored === null) {
			return [];
		}

		return array_values(array_filter(
			$stored['items'],
			static fn (array $item): bool => str_contains(self::fold($item['title']), $needle),
		));
	}

	/**
	 * Accent-folded lowercase, so that "sarampion" typed in a hurry still finds
	 * "sarampión". Applied to both sides of every comparison.
	 */
	public static function fold(string $text): string {
		$decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
		$stripped = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $text : $decomposed);

		return mb_strtolower((string)$stripped, 'UTF-8');
	}

	/**
	 * The page's HTML, or null on anything that stops us reading it.
	 *
	 * One fixed URL per fuente — the Fuente's — with no slug and nothing to
	 * narrow the response to: degreyd's used to
	 * resolve a page by slug against a WordPress REST endpoint, and the fallback
	 * loop that once tried more than one slug went with the informes — the
	 * year-templated slug was theirs, and this page has never carried a year in
	 * its own address. A page is not a collection to filter down, either, so
	 * there is no `_fields` left to ask for: the whole body is what the parser reads.
	 *
	 * Every request names this app (UA) and classifies every failure the way it
	 * classifies every answer (ADR-0015 §4): a 4xx/5xx status is the host SAYING
	 * no, and arms the freno so the next reads stop asking; a timeout, a TLS
	 * error, a DNS miss carry no response to read and stay cooldown-class —
	 * recovery stays automatic, because those are faults that lift on their own.
	 * A 200 that parses to nothing keeps the cooldown too (fail() handles it):
	 * a WAF block page is indistinguishable from a restructure, and braking on
	 * it would refuse a healthy host forever.
	 */
	private function request(): ?string {
		$client = $this->clients->newClient();

		try {
			$response = $client->get($this->fuente->url, $this->options());

			/*
			 * An answer — any answer — is the host not refusing. See
			 * Freno::lift(): recovery is one step, and a WAF 200 lands in
			 * the cooldown, not back under the brake it just lifted.
			 */
			$this->freno->lift();

			$body = (string)$response->getBody();

			/*
			 * An unbounded body risks the worker's memory in the parser's
			 * preg_match_all() rather than in a JSON decoder, now that there is no
			 * JSON envelope to decode. Measured against degreyd's live page,
			 * 66 743 B — the cap leaves close to eight times that as headroom. A body
			 * this size is not the fuente's document, whatever it claims to be.
			 */
			if (strlen($body) > self::MAX_BYTES) {
				$this->logger->warning('{subject} of {bytes} bytes, discarded', [
					'subject' => $this->fuente->subject,
					'bytes' => strlen($body),
				]);

				return null;
			}

			return $body;
		} catch (Throwable $e) {
			$status = Freno::status($client, $e);

			if ($status !== null && $status >= 400) {
				// The host said no. The brake's own warning names the refusal,
				// once per escalation; fail()'s null-branch line still fires from
				// the reader's side, which is true — a refusal IS an unreadable
				// page — but the "could not be read" sentence below stays for
				// the faults with no answer attached.
				$this->freno->brake($status);

				return null;
			}

			// 'exception' is a key Nextcloud's log pipeline treats specially — Log.php
			// checks isset($entry['exception']) and serialises the class, the trace and
			// the previous chain. Passing getMessage() instead threw all of that away
			// in exactly the case where the trace is what you need.
			$this->logger->warning('Could not reach the {subject}', [
				'subject' => $this->fuente->subject,
				'exception' => $e,
			]);

			return null;
		}
	}

	/**
	 * The cache entry, or the table's durable copy shaped as one — the fallback
	 * every reader of this seam inherits (ADR-0015 §2): after an app update
	 * moves the cache namespace (ADR-0012), get(), probe() and search() all
	 * still have a set to serve, and search self-heals without a single fetch.
	 * A table read is not a fetch — the never-fetch contract holds.
	 *
	 * The fabricated entry carries no digest, deliberately: the digest is
	 * cache-entry-internal, and a cold-cache probe re-stores — ~13 idempotent
	 * upserts once per app update — rather than trust a comparison against a
	 * digest re-derived from the very copy it would be validating. No
	 * fetched_at either: that was the cache's fact and it left with the cache;
	 * the wire says null and claims no freshness it cannot back.
	 */
	private function stored(): ?array {
		$stored = $this->cache->get($this->key());

		if (is_array($stored) && isset($stored['items'], $stored['fetched_at'])) {
			return $stored;
		}

		try {
			$items = array_map(self::item(...), $this->alerts->readSet($this->fuente->id));
		} catch (Throwable $e) {
			// The same fault class as the write side: logged, and the read falls
			// back to a plain cold cache. Never routed through degrade().
			$this->logger->warning('Could not read the persisted {name} set', [
				'name' => $this->fuente->name,
				'exception' => $e,
			]);

			return null;
		}

		/*
		 * An empty table is no fallback: this table is never seeded (ADR-0015,
		 * Consequences), so empty means "nothing was ever stored here", and the
		 * honest answer is a cold cache, not a durable copy that claims to exist.
		 */
		if ($items === []) {
			return null;
		}

		return ['items' => $items, 'fetched_at' => null];
	}

	/**
	 * Every request's options: bounded, named (UA), and verified against the
	 * Fuente's bundle when its host needs one — the ISP serves only its leaf,
	 * so without the bundle every ISP request dies `cURL error 60` on a fresh
	 * install. `verify => false` is forbidden here as everywhere.
	 *
	 * @return array{timeout: int, headers: array{User-Agent: string}, verify?: string}
	 */
	private function options(): array {
		$options = [
			'timeout' => 15,
			'headers' => ['User-Agent' => self::UA],
		];

		if ($this->fuente->bundle !== null) {
			$options['verify'] = $this->fuente->bundle->path();
		}

		return $options;
	}

	/**
	 * One parsed item as a row. The fuente is the mapper's to stamp.
	 *
	 * @param array<string, string> $item
	 */
	private static function alert(array $item): Alert {
		$alert = new Alert();
		$alert->setUrl($item['url']);
		$alert->setTitle($item['title']);
		$alert->setYear($item['year']);
		$alert->setMonth($item['month']);
		$alert->setDay($item['date'] ?? null);
		$alert->setTipo($item['tipo'] ?? null);

		return $alert;
	}

	/**
	 * One row as the wire carries it (ADR-0017 D11): title, url, year and
	 * month for every fuente, `date` where the publisher dates its items and
	 * `tipo` where it types them (OPS/OMS). A key is omitted, never null —
	 * degreyd's rows read exactly as they did.
	 *
	 * @return array<string, string>
	 */
	private static function item(Alert $alert): array {
		$item = [
			'title' => $alert->getTitle(),
			'url' => $alert->getUrl(),
			'year' => $alert->getYear(),
			'month' => $alert->getMonth(),
		];

		if ($alert->getDay() !== null) {
			$item['date'] = $alert->getDay();
		}

		if ($alert->getTipo() !== null) {
			$item['tipo'] = $alert->getTipo();
		}

		return $item;
	}

	/**
	 * The cache-side fallback when a stream's table cannot be written or read:
	 * the fresh reading over the last copy, keyed by url — fresh second, so a
	 * title edited in place wins — in readSet()'s order, month then day.
	 *
	 * @param list<array<string, string>> $stored
	 * @param list<array<string, string>> $fresh
	 *
	 * @return list<array<string, string>>
	 */
	private static function union(array $stored, array $fresh): array {
		$items = [];
		foreach ([...$stored, ...$fresh] as $item) {
			$items[$item['url']] = $item;
		}

		$items = array_values($items);
		usort($items, static fn (array $a, array $b): int
			=> [$b['month'], $b['date'] ?? ''] <=> [$a['month'], $a['date'] ?? '']);

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
	 * Degrade, and remember it briefly so an outage is not re-attempted on every read.
	 * Every degrade path goes through here, so none can forget to.
	 */
	private function degrade(?array $stored): array {
		$this->cache->set($this->cooldownKey(), 1, self::COOLDOWN);

		return $this->present($stored, true);
	}

	private function key(): string {
		return 'feed-' . $this->fuente->id;
	}

	/**
	 * Distinct from key(), or the cooldown flag would be read as the feed's data.
	 * Keyed by host, the freno's grain: one outage is one host's, whichever of
	 * its fuentes met it.
	 */
	private function cooldownKey(): string {
		return 'cooldown-' . $this->fuente->host;
	}

	/** Distinct from key()/cooldownKey(), or the dispatch marker would be read as data. */
	private function revalidatingKey(): string {
		return 'revalidating-' . $this->fuente->id;
	}

	/** Set by the first cold read (get()); distinct from every other key, or it would be read as data. */
	private function coldKey(): string {
		return 'cold-' . $this->fuente->id;
	}

	/**
	 * The revalidation a stale serve dispatched: the background half of
	 * get()'s serve-then-refresh. Runs the same refresh()/store() the
	 * inline path ran — no second code path — and clears the dispatch
	 * marker in a finally so a failed revalidation re-arms on the next
	 * reader past the cooldown rather than suppressing dispatches until
	 * the marker's TTL.
	 *
	 * The host's gates are asked again here, because a queued job passed
	 * get()'s before a sibling met the outage (ADR-0017 D8): the hourly
	 * pass dispatches one revalidation per stale fuente, and the first to
	 * time out arms its host's cooldown — the rest must then ask nothing,
	 * or one blocked host costs one timeout per fuente it serves. The
	 * probe stays the one ask that goes through the brake.
	 */
	public function revalidate(): void {
		try {
			if ($this->cache->get($this->cooldownKey()) !== null || $this->freno->braked()) {
				return;
			}

			$this->refresh($this->stored());
		} finally {
			$this->cache->remove($this->revalidatingKey());
		}
	}

	/**
	 * What probe() compares instead of a publication date — see its docblock for
	 * why. Keyed on URL alone, the same identity PageParser::parse()'s dedupe uses: a
	 * title or a row reordering would flag a change nobody could act on, and
	 * MINSAL reassigns numeric ids on republish, so those cannot be the key either.
	 *
	 * @param list<array<string, string>> $items
	 */
	private static function digest(array $items): string {
		return hash('sha256', implode("\n", array_column($items, 'url')));
	}
}
