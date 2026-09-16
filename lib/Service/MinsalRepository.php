<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use InvalidArgumentException;
use Normalizer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only place that knows about MINSAL.
 *
 * A read-through proxy over the page MINSAL publishes its in-force alerts on.
 * No database, no mirroring: PDFs are linked where MINSAL publishes them, so
 * staff always open the version in force.
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
	 * How long the copy survives in Redis — far longer than FRESH_FOR, on purpose.
	 * When MINSAL is unreachable we serve the last good copy flagged `degraded`,
	 * and we can only do that if it outlived its own freshness window.
	 */
	private const KEEP_FOR = 30 * 24 * 3600;

	private ICache $cache;

	public function __construct(
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
	}

	/**
	 * @param self::ALERTS $feed
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	public function get(string $feed): array {
		$this->assertKnownFuente($feed);

		$stored = $this->stored($feed);

		if ($stored !== null && $stored['fetched_at'] + self::FRESH_FOR > $this->time->getTime()) {
			return $this->present($stored, false);
		}

		/*
		 * A failed refresh writes nothing, so the entry stays expired and every read
		 * retries. PageController::index() fetches synchronously while someone waits,
		 * so against a host that drops packets rather than refusing them this is a
		 * gateway timeout instead of the degraded banner. Per feed, so one source's
		 * outage does not silence the other's.
		 */
		if ($this->cache->get($this->cooldownKey($feed)) !== null) {
			return $this->present($stored, true);
		}

		return $this->refresh($feed, $stored);
	}

	/**
	 * Fetch, validate, and either cache or degrade.
	 *
	 * Private: nothing outside this class calls it, and `$stored` is the raw cache entry.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function refresh(string $feed, ?array $stored = null): array {
		$html = $this->request();
		$items = $html === null ? [] : $this->parse($html);

		return $items === [] ? $this->fail($feed, $html, $stored) : $this->store($feed, $items);
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
	 * could do. So: leave the last good copy alone and say we are degraded.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function fail(string $feed, ?string $html, ?array $stored): array {
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

		return $this->degrade($feed, $stored);
	}

	/**
	 * Turns a freshly parsed list into the cache entry both refresh() and
	 * probe() write — including the digest probe() compares next time.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function store(string $feed, array $items): array {
		$fresh = [
			'items' => $items,
			'digest' => self::digest($items),
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key($feed), $fresh, self::KEEP_FOR);

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
	 * @return bool false when MINSAL is unreachable, or changed into something we
	 *              can no longer parse. Either way a human has to look.
	 */
	public function probe(string $feed): bool {
		$this->assertKnownFuente($feed);

		$html = $this->request();
		$items = $html === null ? [] : $this->parse($html);

		if ($items === []) {
			$this->fail($feed, $html, $this->stored($feed));

			return false;
		}

		$stored = $this->stored($feed);
		if ($stored !== null && ($stored['digest'] ?? null) === self::digest($items)) {
			return true;
		}

		$this->store($feed, $items);

		return true;
	}

	/**
	 * Cache-only search, for Nextcloud's global search box.
	 *
	 * It must never fetch. Every registered provider runs on every search, so one
	 * that called out to MINSAL would make all of Nextcloud's search wait on a
	 * foreign government server — for every user, including someone looking for a
	 * Word document. A cold cache returns nothing and self-heals on the next visit
	 * or the next probe.
	 *
	 * @return list<array<string, string>>
	 */
	public function search(string $term): array {
		$needle = self::fold($term);
		$stored = $this->stored(self::ALERTS);

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
	 * Decoded, with every kind of space collapsed — these titles are full of `&nbsp;`.
	 *
	 * The `\x{00A0}` is belt-and-braces, not the load-bearing part, and the comment that
	 * used to be here said the opposite: "`\s` does not match U+00A0 even under /u".
	 * Measured, that is false at both ends of the supported range — PHP 8.1 with PCRE2
	 * 10.39 and PHP 8.5 with PCRE2 10.44 both match U+00A0, U+2000-U+200A, U+202F,
	 * U+205F and U+3000 with `\s` alone, because PHP's /u carries UCP semantics. Kept
	 * anyway: it costs nothing and does not depend on that holding. IspRepository's copy
	 * names more codepoints for the same reason, and the difference is unobservable.
	 */
	private function text(string $html): string {
		$decoded = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $decoded));
	}

	/**
	 * The name each PDF is given by its own table row, keyed by URL.
	 *
	 * Empty when the page is not a table at all. So this adds a better title where one
	 * exists and changes nothing where it does not — the anchor scan above still finds
	 * every link either way.
	 *
	 * @return array<string, string>
	 */
	private function rowTitles(string $html): array {
		if (preg_match_all('#<tr\b.*?</tr>#is', $html, $rows) === 0) {
			return [];
		}

		$titles = [];
		foreach ($rows[0] as $row) {
			if (preg_match('#<a[^>]+href="([^"]+\.pdf)"#i', $row, $link) !== 1) {
				continue;
			}
			if (preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $row, $cells) === 0) {
				continue;
			}
			foreach ($cells[1] as $cell) {
				$name = $this->text($cell);
				// The first cell that says anything. `rowspan` cells and stray `&nbsp;`
				// ones are real on this page.
				if ($name !== '') {
					$titles[html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] = $name;
					break;
				}
			}
		}

		return $titles;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function parse(string $html): array {
		if (preg_match_all('#<a[^>]+href="([^"]+\.pdf)"[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER) === 0) {
			return [];
		}

		$rowTitles = $this->rowTitles($html);

		/*
		 * An anchor text used by more than one row is a LINK LABEL, not a title. On
		 * the previous host's page — a table `Nombre de la alerta | Fecha | Descarga`
		 * — the third cell repeated "Descargar Documento" 27 times out of 75, and
		 * taking the anchor text put 27 indistinguishable rows in front of a
		 * clinician. Nothing repeats on degreyd's page (a five-column table, `Evento
		 * | Desde | Alcance territorial | Tipo | Documento para descarga`, measured
		 * 0 repeated labels across 11 rows) — see testEveryAlertKeepsItsOwnTitle —
		 * so this branch is dormant here, not deleted: rowTitles() below still has to
		 * exist for whichever page MINSAL points this fuente at next.
		 *
		 * Detected by repetition rather than by a wordlist: a real title is unique, and
		 * a repeated label is the only kind of string this rule needs to catch. That
		 * also survives MINSAL rewording it, which a wordlist would not.
		 */
		$labels = array_count_values(array_map(
			fn (array $m): string => $this->text($m[2]),
			$matches,
		));

		$items = [];
		$rejected = 0;
		foreach ($matches as [, $href, $label]) {
			$url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			// Only https. `p()` escapes quotes and angle brackets, so it cannot make a
			// URL scheme safe: "javascript:alert(1)/uploads/2026/01/x.pdf" satisfies both
			// the anchor pattern and the upload-folder pattern below, and reaches href
			// byte for byte. Every URL MINSAL actually publishes is https, so this drops
			// nothing real — and it guards all three consumers at once, because the API
			// and the search provider read the same parsed items this page does.
			//
			// Counted rather than dropped quietly: a partial silent loss is the one
			// failure this app is built to avoid, and only zero items raises the banner.
			if (stripos($url, 'https://') !== 0) {
				$rejected++;
				continue;
			}

			// The upload folder is the only date signal there is, and it is not
			// derivable from the epi week — SE 29 sits under 2026/07 while SE 24
			// sits under 2026/06. Anchors are parsed, never constructed.
			if (preg_match('#/uploads/(\d{4})/(\d{2})/#', $url, $when) !== 1) {
				continue;
			}

			// The anchor text is the official title WHEN it is one. On the previous
			// host's page it carried the oficio number, which the row's cell did not,
			// and the fallback below took the row's first cell, headed "Nombre de la
			// alerta" there. Neither holds on degreyd's page — 0 of 11 anchor texts
			// carry an oficio number, and its own first cell is an EVENT/disease name
			// ("Sarampión", "Influenza B"), which CONTEXT.md is explicit this context
			// has no word for. The fallback is unreachable today (no label repeats;
			// see the comment above $labels), so it cannot yet title an alert by
			// disease — but if MINSAL ever changes that, this fallback would, and
			// this comment is what says so before it happens rather than after.
			$title = $this->text($label);
			if (($labels[$title] ?? 0) > 1) {
				$title = $rowTitles[$url] ?? $title;
			}
			if ($title === '') {
				continue;
			}

			$item = [
				'title' => $title,
				'url' => $url,
				// Upload year, not the oficio's year: measured 10 of 11 titles on the
				// live page carry no year at all (was 47 of 75 on the previous host),
				// so the path is the only field with full coverage either way. The UI
				// therefore labels it plain "Año" and claims nothing more.
				'year' => $when[1],
				'month' => $when[1] . '-' . $when[2],
			];

			// Keyed by URL, because MINSAL links the same PDF from two rows of its own
			// table. AlertsPane keys on the url, so a duplicate was a duplicate Vue key
			// as well as a row printed twice. IspRepository has deduped by link since
			// #47; this side never had to until the table shape made it possible.
			$items[$url] = $item;
		}

		if ($rejected > 0) {
			// No longer says which feed: there is one page left here, and naming it in
			// every line was only ever worth the words while the informes shared the class.
			$this->logger->warning(
				'Dropped {rejected} non-https links from the MINSAL alerts page',
				['rejected' => $rejected],
			);
		}

		return $this->sort(array_values($items));
	}

	/**
	 * MINSAL's own page order is not chronological: entries get appended and
	 * edited without re-sorting, so rendering in document order shows a visibly
	 * jumbled list to anyone who scrolls. Measured on the live page: 1 inversion
	 * across 11 anchors (`1781616195625_OFI.pdf` sits after two older alerts in
	 * the markup — see testTheFixtureStillContainsTheInversionThisSortExistsToFix);
	 * the previous host's page carried 9 across 75.
	 *
	 * usort is stable in PHP 8, which is the point — within a month (5 distinct
	 * months across 11 alerts today, was 26 across 75) MINSAL's own curation
	 * survives, and that is the only ordering signal finer than a month we are
	 * entitled to use.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return list<array<string, string>>
	 */
	private function sort(array $items): array {
		usort($items, static fn (array $a, array $b): int => strcmp($b['month'], $a['month']));

		return $items;
	}

	/**
	 * The page's HTML, or null on anything that stops us reading it.
	 *
	 * One fixed URL, no slug and nothing to narrow the response to: this used to
	 * resolve a page by slug against a WordPress REST endpoint, and the fallback
	 * loop that once tried more than one slug went with the informes — the
	 * year-templated slug was theirs, and this page has never carried a year in
	 * its own address. A page is not a collection to filter down, either, so
	 * there is no `_fields` left to ask for: the whole body is what parse() reads.
	 */
	private function request(): ?string {
		try {
			$response = $this->clients->newClient()->get(self::API, ['timeout' => 15]);
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

	private function stored(string $feed): ?array {
		$stored = $this->cache->get($this->key($feed));

		return is_array($stored) && isset($stored['items'], $stored['fetched_at']) ? $stored : null;
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
	private function degrade(string $feed, ?array $stored): array {
		$this->cache->set($this->cooldownKey($feed), 1, self::COOLDOWN);

		return $this->present($stored, true);
	}

	private function key(string $feed): string {
		return 'feed-' . $feed;
	}

	/** Distinct from key(), or the cooldown flag would be read as the feed's data. */
	private function cooldownKey(string $feed): string {
		return 'cooldown-' . $feed;
	}

	/**
	 * `get()` and `probe()` still take `$feed` because that is the signature
	 * `Sources` already calls — `$this->minsal->get(MinsalRepository::ALERTS)`,
	 * always with that one argument — from when this class answered for two
	 * fuentes and the informes had not yet split into their own sibling.
	 * `Sources` does not address every repository the same way: IspReportRepository
	 * was written after that split and its `get()`/`probe()` take nothing, because
	 * a class with one fuente never needed the parameter. This one still has it,
	 * and dropping it is a public-signature change for a caller this task does
	 * not own — narrowing what the parameter accepts is the smaller, safe one.
	 *
	 * `request()` stopped reading `$feed` once the slug that used to route to a
	 * page went with the informes, leaving only a docblock's `@param self::ALERTS`
	 * between a typo and `get('whatever')` quietly minting a `feed-whatever` cache
	 * entry that nothing else in this class would ever refresh, degrade or serve
	 * correctly. One fuente lives here now; asking for another is a caller's
	 * mistake, and a mistake should throw rather than be cached.
	 */
	private function assertKnownFuente(string $feed): void {
		if ($feed !== self::ALERTS) {
			throw new InvalidArgumentException(
				"MinsalRepository has one fuente ('" . self::ALERTS . "'), not '$feed'",
			);
		}
	}

	/**
	 * What probe() compares instead of a publication date — see its docblock for
	 * why. Keyed on URL alone, the same identity `parse()`'s own dedupe uses: a
	 * title or a row reordering would flag a change nobody could act on, and
	 * MINSAL reassigns numeric ids on republish, so those cannot be the key either.
	 *
	 * @param list<array<string, string>> $items
	 */
	private static function digest(array $items): string {
		return hash('sha256', implode("\n", array_column($items, 'url')));
	}
}
