<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\AppFramework\Utility\ITimeFactory;
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
 * ponytail: the shared "zero items never overwrites the cache" mechanic is
 * duplicated from MinsalRepository, not extracted. Ten lines across two classes
 * does not earn a base class; extract one when a third source arrives.
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
	 * The 24 alert categories the ISP publishes. Each is its own feed capped at
	 * WordPress's default of 10 items, so `anamed` is a window like the rest and
	 * not a superset — pulling only it would silently lose the others.
	 *
	 * Measured across six of them: 51 item slots, 43 unique alerts, ~16 percent
	 * overlap, the same withdrawal appearing under both `anamed` and
	 * `retiro-de-mercado`. Hence dedupe by link, below.
	 */
	private const TERMS = [
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

	/** Same windows as MinsalRepository: fresh for an hour, kept for thirty days. */
	private const FRESH_FOR = 3600;
	private const KEEP_FOR = 30 * 24 * 3600;

	/** How many items the app ever shows. Fetching all 24 feeds already costs the round trips. */
	private const KEEP_ITEMS = 60;

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
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function get(): array {
		$stored = $this->stored();

		if ($stored !== null && $stored['fetched_at'] + self::FRESH_FOR > $this->time->getTime()) {
			return $this->present($stored, false);
		}

		return $this->refresh($stored);
	}

	/**
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function refresh(?array $stored = null): array {
		$items = [];
		$reached = 0;

		foreach (self::TERMS as $term) {
			$body = $this->fetch($term);
			if ($body === null) {
				continue;
			}
			$reached++;
			foreach ($this->parse($body) as $item) {
				// Dedupe by link. The same alert is filed under several categories,
				// so the first feed that carries it wins and the rest are skipped.
				$items[$item['url']] ??= $item;
			}
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
		if ($reached === 0) {
			$this->logger->warning('No se pudo contactar ningún feed del ISP ({total} intentos)', [
				'total' => count(self::TERMS),
			]);

			return $this->present($stored, true);
		}

		$items = array_values($items);
		usort($items, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
		$items = array_slice($items, 0, self::KEEP_ITEMS);

		$fresh = [
			'items' => $items,
			'reached' => $reached,
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key(), $fresh, self::KEEP_FOR);

		return $this->present($fresh, false);
	}

	/** Cheap liveness signal for the daily probe. False means a human has to look. */
	public function probe(): bool {
		return $this->refresh($this->stored())['degraded'] === false;
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
			$title = trim((string)$node->title);

			// Same guard as the MINSAL parser and for the same reason: htmlspecialchars
			// cannot make a URL scheme safe, so only https reaches an href.
			if ($title === '' || stripos($url, 'https://') !== 0) {
				continue;
			}

			$at = strtotime((string)$node->pubDate);

			$items[] = [
				'title' => $title,
				'url' => $url,
				// Unlike MINSAL, the ISP publishes a real day. Kept as ISO for the UI
				// and as an epoch for the sort, so the frontend never re-parses a date.
				'date' => $at === false ? '' : date('Y-m-d', $at),
				'at' => $at === false ? 0 : $at,
			];
		}

		return $items;
	}

	/** @return string|null the feed body, or null when this one could not be reached */
	private function fetch(string $term): ?string {
		try {
			return $this->clients->newClient()->get(sprintf(self::FEED, $term), [
				'timeout' => 15,
			])->getBody();
		} catch (Throwable $e) {
			// Per-feed failure is not reported: with 24 feeds a single blip would be
			// noise, and refresh() judges the set. Only reaching none of them is news.
			$this->logger->debug('Feed del ISP {term} no disponible', ['term' => $term, 'exception' => $e]);

			return null;
		}
	}

	private function stored(): ?array {
		$stored = $this->cache->get($this->key());

		return is_array($stored) ? $stored : null;
	}

	private function present(?array $stored, bool $degraded): array {
		return [
			'items' => $stored['items'] ?? [],
			'updated_at' => $stored['items'][0]['date'] ?? null,
			'fetched_at' => $stored['fetched_at'] ?? null,
			'degraded' => $degraded,
		];
	}

	private function key(): string {
		return 'feed-' . self::ALERTS;
	}
}
