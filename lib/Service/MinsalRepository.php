<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

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
 * A read-through proxy over the public WordPress REST API at epi.minsal.cl. No
 * database, no mirroring: PDFs are linked where MINSAL publishes them, so staff
 * always open the version in force.
 */
class MinsalRepository {
	public const ALERTS = 'alerts';
	public const IRAG = 'irag';

	private const API = 'https://epi.minsal.cl/wp-json/wp/v2/pages';

	/** Resolve pages by slug, never by ID — IDs change when MINSAL republishes. */
	private const ALERTS_SLUG = 'alertas-epidemiologicas-vigentes';
	private const IRAG_SLUG = 'influenza-e-infecciones-respiratorias-agudas-graves-irag-informes-ano-%d';

	/** How long a cached copy counts as fresh. */
	/**
	 * How long a failed refresh is remembered, so an outage is not retried on every
	 * page load. Well under FRESH_FOR: recovery stays automatic and needs nobody.
	 */
	private const COOLDOWN = 300;

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
	 * @param self::ALERTS|self::IRAG $feed
	 *
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function get(string $feed): array {
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
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function refresh(string $feed, ?array $stored = null): array {
		$page = $this->fetch($feed, 'content,modified');
		$items = $page === null ? [] : $this->parse($feed, (string)($page['content']['rendered'] ?? ''));

		// A parse returning zero items is a failure, never a result. Caching it
		// would replace every real alert with an empty list, which a clinician
		// reads as "no hay alertas vigentes" — the most dangerous thing this app
		// could do. So: leave the last good copy alone and say we are degraded.
		if ($items === []) {
			// No 'app' key: the injected LoggerInterface is already app-scoped —
			// DIContainer wraps it in ScopedPsrLogger, which merges it in on every call.
			$this->logger->warning('MINSAL devolvió 0 elementos para el feed {feed}', [
				'feed' => $feed,
			]);

			return $this->degrade($feed, $stored);
		}

		$fresh = [
			'items' => $items,
			'modified' => (string)($page['modified'] ?? ''),
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key($feed), $fresh, self::KEEP_FOR);

		return $this->present($fresh, false);
	}

	/**
	 * Daily breakage detection. Asks for the modification date alone — 859 bytes
	 * against 44 KB for a full fetch — because these responses carry no ETag and
	 * no Last-Modified, so a conditional GET is not available to us.
	 *
	 * @return bool false when MINSAL is unreachable, or changed into something we
	 *              can no longer parse. Either way a human has to look.
	 */
	public function probe(string $feed): bool {
		$page = $this->fetch($feed, 'modified');
		if ($page === null) {
			return false;
		}

		$stored = $this->stored($feed);
		if ($stored !== null && $stored['modified'] === (string)($page['modified'] ?? '')) {
			return true;
		}

		// It changed. Pull the real page and see whether it still parses — which
		// also leaves the cache warm for whoever opens the app first tomorrow.
		return $this->refresh($feed, $stored)['degraded'] === false;
	}

	/**
	 * Cache-only search, for Nextcloud's global search box.
	 *
	 * It must never fetch. Every registered provider runs on every search, so one
	 * that called epi.minsal.cl would make all of Nextcloud's search wait on a
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
	 * @return list<array<string, string>>
	 */
	private function parse(string $feed, string $html): array {
		if (preg_match_all('#<a[^>]+href="([^"]+\.pdf)"[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER) === 0) {
			return [];
		}

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

			// The anchor text IS the official title. Note that \s does not match
			// U+00A0 even under /u, and these titles are full of &nbsp;.
			$title = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $title));
			if ($title === '') {
				continue;
			}

			$item = [
				'title' => $title,
				'url' => $url,
				// Upload year, not the oficio's year: 47 of 75 titles carry no year
				// at all, so the path is the only field with full coverage. The UI
				// therefore labels it plain "Año" and claims nothing more.
				'year' => $when[1],
				'month' => $when[1] . '-' . $when[2],
			];

			if ($feed === self::IRAG) {
				$item['se'] = preg_match('/SE\s*(\d{1,2})/i', $title, $se) === 1 ? $se[1] : '';
			}

			$items[] = $item;
		}

		if ($rejected > 0) {
			$this->logger->warning(
				'Se descartaron {rejected} enlaces del feed {feed} por no ser https',
				['rejected' => $rejected, 'feed' => $feed],
			);
		}

		return $this->sort($feed, $items);
	}

	/**
	 * MINSAL's own page order is reliable for the first ~35 anchors and then is
	 * not: past that it carries 9 inversions, because entries get appended and
	 * edited without re-sorting. Rendering in document order shows a visibly
	 * jumbled list to anyone who scrolls.
	 *
	 * usort is stable in PHP 8, which is the point — within a month (26 distinct
	 * months across 75 alerts) MINSAL's own curation survives, and that is the
	 * only ordering signal finer than a month we are entitled to use.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return list<array<string, string>>
	 */
	private function sort(string $feed, array $items): array {
		usort($items, $feed === self::IRAG
			? static fn (array $a, array $b): int => (int)$b['se'] <=> (int)$a['se']
			: static fn (array $a, array $b): int => strcmp($b['month'], $a['month']));

		return $items;
	}

	private function fetch(string $feed, string $fields): ?array {
		foreach ($this->slugs($feed) as $slug) {
			$page = $this->request($slug, $fields);
			if ($page !== null) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * The IRAG page is year-templated, so every January it would 404 until MINSAL
	 * puts the new year up. Falling back one year survives the rollover with no
	 * code change and no ticket in the first week of January.
	 *
	 * @return list<string>
	 */
	private function slugs(string $feed): array {
		if ($feed === self::ALERTS) {
			return [self::ALERTS_SLUG];
		}

		$year = (int)date('Y', $this->time->getTime());

		return [sprintf(self::IRAG_SLUG, $year), sprintf(self::IRAG_SLUG, $year - 1)];
	}

	private function request(string $slug, string $fields): ?array {
		try {
			$response = $this->clients->newClient()->get(self::API, [
				'query' => ['slug' => $slug, '_fields' => $fields],
				'timeout' => 15,
			]);
			$pages = json_decode((string)$response->getBody(), true);
		} catch (Throwable $e) {
			// 'exception' is a key Nextcloud's log pipeline treats specially — Log.php
			// checks isset($entry['exception']) and serialises the class, the trace and
			// the previous chain. Passing getMessage() instead threw all of that away
			// in exactly the case where the trace is what you need.
			$this->logger->warning('No se pudo contactar a MINSAL ({slug})', [
				'slug' => $slug,
				'exception' => $e,
			]);

			return null;
		}

		return is_array($pages) && isset($pages[0]) && is_array($pages[0]) ? $pages[0] : null;
	}

	private function stored(string $feed): ?array {
		$stored = $this->cache->get($this->key($feed));

		return is_array($stored) && isset($stored['items'], $stored['fetched_at']) ? $stored : null;
	}

	/**
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	private function present(?array $stored, bool $degraded): array {
		return [
			'items' => $stored['items'] ?? [],
			'updated_at' => $stored['modified'] ?? null,
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
}
