<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use Normalizer;
use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OCA\Epidemiologia\Service\Parser\PageParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only place that knows where MINSAL publishes; how its page reads is
 * PageParser's (ADR-0017).
 *
 * A read-through proxy over the page MINSAL publishes its in-force alerts on.
 * The parsed set persists to the app's own table as metadata — the durable
 * last-good copy an app update's cache flush no longer takes with it
 * (ADR-0015 §2) — but nothing is mirrored: the PDFs are linked where MINSAL
 * publishes them, so staff always open the version in force.
 */
class MinsalRepository {
	public const ALERTS = 'alerts';

	/**
	 * The page MINSAL publishes its in-force alerts on.
	 *
	 * A fixed page address, and deliberately neither of the two alternatives. The
	 * `/wp-json/` route is closed across MINSAL's estate and the query form that
	 * still answers here is the same API reached by a spelling they missed — this
	 * app's only alerts source should not rest on somebody's oversight. The RSS
	 * feed was measured and rejected for a plainer reason: it carries the ten most
	 * recent posts, the alerts post already sits behind three news items, and a
	 * fuente that empties because its publisher had a busy news week is a fuente
	 * that fails for no reason a reader could act on.
	 */
	private const API = 'https://degreyd.minsal.cl/alertas-sanitarias-y-epidemiologicas-vigentes-en-chile/';

	/** The largest response we will read; IspRepository::parse() carries the measurement. */
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
	 * When MINSAL is unreachable we serve the last good copy flagged `degraded`,
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

	/**
	 * What the parser's log lines call this page — spelled so they read exactly
	 * as they did while the parse lived here: "Dropped 2 non-https links from
	 * the MINSAL alerts page".
	 */
	private const SUBJECT = 'MINSAL alerts page';

	private ICache $cache;

	private Freno $freno;

	public function __construct(
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private AlertMapper $alerts,
		ICacheFactory $cacheFactory,
		private IJobList $jobs,
		private PageParser $parser,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
		// One grain — this page — on the same cache it reads; the subject
		// keeps the brake's log sentence byte-identical to the copy this
		// replaces (L3-02).
		$this->freno = new Freno($this->cache, $this->time, $this->logger, 'freno-' . self::ALERTS, 'MINSAL');
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
		 * gateway timeout instead of the degraded banner. One fuente, one cooldown —
		 * the sibling repository keeps its own.
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
		 * makes the NEXT read degraded). The marker dedups the dispatch,
		 * and its TTL self-heals a job that never runs.
		 */
		if ($stored !== null) {
			if ($this->cache->get($this->revalidatingKey()) === null) {
				$this->cache->set($this->revalidatingKey(), 1, self::MARKER_FOR);
				// The class name as a string, deliberately: importing the
				// job would close MinsalRepository → RevalidateJob → Sources
				// → MinsalRepository, the cycle shape Phase 1 spent itself
				// killing. The suite pins the string against
				// RevalidateJob::class, so a rename answers as a failing
				// test, not as a silently dead dispatch.
				$this->jobs->add('OCA\\Epidemiologia\\BackgroundJob\\RevalidateJob', ['source' => self::ALERTS]);
			}

			return $this->present($stored, false);
		}

		// Cold install: nothing stored, so there is no copy to serve and
		// today's bounded synchronous fetch stays — once per install.
		return $this->refresh($stored);
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
		$items = $html === null ? [] : $this->parser->parse($html, self::SUBJECT);

		return $items === [] ? $this->fail($html, $stored) : $this->store($items);
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
		 * Same correction IspRepository already carries, where logging count(TERMS)
		 * rather than $attempted let "24 intentos" mean one.
		 *
		 * No 'app' key: the injected LoggerInterface is already app-scoped —
		 * DIContainer wraps it in ScopedPsrLogger, which merges it in on every call.
		 * No {feed} placeholder either, now that one fuente is all this class has —
		 * naming it in every line stopped earning its keep when the informe left.
		 */
		$this->logger->warning(
			$html === null
				? 'The MINSAL alerts page could not be read'
				: 'The MINSAL alerts page carried no alerts',
		);

		return $this->degrade($stored);
	}

	/**
	 * Turns a freshly parsed list into the cache entry both refresh() and
	 * probe() write — including the digest probe() compares next time — and
	 * the table's whole set, replaced (ADR-0015 §2).
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function store(array $items): array {
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
		$alerts = [];
		foreach ($items as $item) {
			$alert = new Alert();
			$alert->setUrl($item['url']);
			$alert->setTitle($item['title']);
			$alert->setYear($item['year']);
			$alert->setMonth($item['month']);
			$alerts[] = $alert;
		}

		try {
			$this->alerts->replaceSet(self::ALERTS, $alerts);
		} catch (Throwable $e) {
			$this->logger->warning('Could not persist the MINSAL alerts set', [
				'exception' => $e,
			]);
		}

		return $this->present($fresh, false);
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
	 * Asks THROUGH the freno, exactly as IspRepository's does: a braked host is
	 * not assumed unreachable, it is ASKED, once a day — #218beba's lesson, that
	 * "we asked nothing" must never be answered as "nothing is wrong". A refusal
	 * still escalates the brake's count and an answer still clears it (request()
	 * does both), so the sondeo is also the brake's road back: the first day
	 * MINSAL answers 200 again is the day the hourly reads resume.
	 *
	 * @return bool false when MINSAL is unreachable, or changed into something we
	 *              can no longer parse. Either way a human has to look.
	 */
	public function probe(): bool {
		$html = $this->request();
		$items = $html === null ? [] : $this->parser->parse($html, self::SUBJECT);

		if ($items === []) {
			$this->fail($html, $this->stored());

			return false;
		}

		$stored = $this->stored();
		if ($stored !== null && ($stored['digest'] ?? null) === self::digest($items)) {
			return true;
		}

		$this->store($items);

		return true;
	}

	/**
	 * Cache-then-table search, for Nextcloud's global search box.
	 *
	 * It must never fetch. Every registered provider runs on every search, so one
	 * that called out to MINSAL would make all of Nextcloud's search wait on a
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
	 * One fixed URL, no slug and nothing to narrow the response to: this used to
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
			$response = $client->get(self::API, [
				'timeout' => 15,
				'headers' => ['User-Agent' => self::UA],
			]);

			/*
			 * An answer — any answer — is the host not refusing. See
			 * Freno::lift(): recovery is one step, and a WAF 200 lands in
			 * the cooldown, not back under the brake it just lifted.
			 */
			$this->freno->lift();

			$body = (string)$response->getBody();

			/*
			 * The same bound IspRepository::parse() carries, and for the same reason:
			 * an unbounded body risks the worker's memory in preg_match_all() rather
			 * than in a JSON decoder, now that there is no JSON envelope to decode.
			 * Measured against the live page, 66 743 B — the cap leaves close to eight
			 * times that as headroom. A body this size is not this page, whatever it
			 * claims to be.
			 */
			if (strlen($body) > self::MAX_BYTES) {
				$this->logger->warning('MINSAL alerts page of {bytes} bytes, discarded', [
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
			$this->logger->warning('Could not reach the MINSAL alerts page', [
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
			$items = array_map(
				static fn (Alert $alert): array => [
					'title' => $alert->getTitle(),
					'url' => $alert->getUrl(),
					'year' => $alert->getYear(),
					'month' => $alert->getMonth(),
				],
				$this->alerts->readSet(self::ALERTS),
			);
		} catch (Throwable $e) {
			// The same fault class as the write side: logged, and the read falls
			// back to a plain cold cache. Never routed through degrade().
			$this->logger->warning('Could not read the persisted MINSAL alerts set', [
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
		return 'feed-' . self::ALERTS;
	}

	/** Distinct from key(), or the cooldown flag would be read as the feed's data. */
	private function cooldownKey(): string {
		return 'cooldown-' . self::ALERTS;
	}

	/** Distinct from key()/cooldownKey(), or the dispatch marker would be read as data. */
	private function revalidatingKey(): string {
		return 'revalidating-' . self::ALERTS;
	}

	/**
	 * The revalidation a stale serve dispatched: the background half of
	 * get()'s serve-then-refresh. Runs the same refresh()/store() the
	 * inline path ran — no second code path — and clears the dispatch
	 * marker in a finally so a failed revalidation re-arms on the next
	 * reader past the cooldown rather than suppressing dispatches until
	 * the marker's TTL.
	 */
	public function revalidate(): void {
		try {
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
