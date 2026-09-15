<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The weekly informe, as the ISP publishes it.
 *
 * A third sibling rather than a method on IspRepository, for the reason those two are
 * already siblings (ADR-0010): the rules disagree, and sharing a HOST is not sharing a
 * rule. For the alertas sanitarias an empty category is normal and only reaching none of
 * the 24 is degradation; for an informe, zero items is always a failure. IspRepository's
 * freno, its lock and its backfill are 24-feed machinery that means nothing to one page.
 *
 * What it does share with IspRepository is the host, and therefore the certificate: the
 * ISP serves only its leaf, so `verify` points at CertificateBundle's merged anchors or
 * every request dies `cURL error 60` on a fresh install. `verify => false` is forbidden
 * here as everywhere.
 *
 * It differs from MinsalRepository — whose lifecycle it otherwise mirrors — in exactly
 * two places, and both are below: the anchor filter is the LABEL, and the items
 * ACCUMULATE.
 */
class IspReportRepository {
	/** The ISP's own page for the informe. One page, no slug, no year in it. */
	private const SOURCE = 'https://www.ispch.gob.cl/virusrespiratorios/';

	/**
	 * The label is the discriminator, and it carries the semana.
	 *
	 * `/u` is load-bearing, not decoration: it turns on Unicode character properties, so
	 * `\s` also matches the non-breaking space a WordPress editor leaves between words.
	 * Without it a label typed with a U+00A0 stops matching and the pane silently holds
	 * nothing. None is present today; the flag is what stops one appearing later from
	 * emptying the pane in silence.
	 */
	private const INFORME = '/Informe\s+Semana\s+(\d{1,2})/iu';

	/** The largest response we will read; IspRepository::parse() carries the measurement. */
	private const MAX_BYTES = 512 * 1024;

	/**
	 * How long a failed refresh is remembered, so an outage is not retried on every page
	 * load. Well under FRESH_FOR: recovery stays automatic and needs nobody.
	 */
	private const COOLDOWN = 300;

	/** How long a cached copy counts as fresh. Same windows as the two siblings. */
	private const FRESH_FOR = 3600;

	/**
	 * How long the copy survives in Redis — far longer than FRESH_FOR, on purpose. When
	 * the ISP is unreachable we serve the last good copy flagged `degraded`, and we can
	 * only do that if it outlived its own freshness window. Here it is also what holds
	 * the accumulated series together between reads.
	 */
	private const KEEP_FOR = 30 * 24 * 3600;

	private ICache $cache;

	public function __construct(
		private IClientService $clients,
		private CertificateBundle $bundle,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('epidemiologia');
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
		 * would retry. PageController::index() fetches synchronously while someone waits,
		 * so against a host that drops packets rather than refusing them this is a
		 * gateway timeout instead of the degraded banner.
		 */
		if ($this->cache->get($this->cooldownKey()) !== null) {
			return $this->present($stored, true);
		}

		return $this->refresh($stored);
	}

	/**
	 * Daily breakage detection. False means a human has to look.
	 *
	 * It asks THROUGH the freshness window rather than around it, which is why it calls
	 * refresh() and not get(): a detector that reuses the reader's rule reports an outage
	 * as silence, the defect #75 records on the ISP side. One page a day is what a
	 * detector costs here — there is no cheap validator to ask for instead, the way
	 * MinsalRepository asks WordPress for `modified` alone.
	 */
	public function probe(): bool {
		return $this->refresh($this->stored())['degraded'] === false;
	}

	/**
	 * Fetch, validate, and either accumulate or degrade.
	 *
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	private function refresh(?array $stored): array {
		$html = $this->request();
		$fresh = $html === null ? [] : $this->parse($html);

		// Zero items is a failure, never a result. Caching it would replace the informes
		// with an empty list, which a clinician reads as "no hay informes" — so: leave the
		// last good copy alone and say we are degraded.
		if ($fresh === []) {
			/*
			 * Which fault it was, in the sentence itself. `$html` is null when nothing was
			 * read at all and a string when the page was read and carried no informe, and
			 * the payload is byte-identical either way — both degrade, both serve the last
			 * good copy. The log line is the whole of an admin's diagnosis, and
			 * MinsalRepository carries the long version of why it must not guess.
			 */
			$this->logger->warning(
				$html === null
					? 'The ISP informe page could not be read'
					: 'The ISP informe page carried no informe',
			);

			return $this->degrade($stored);
		}

		/*
		 * Accumulated, not replaced. The ISP publishes only the CURRENT informe on this
		 * page — its year archive renders client-side and carries nothing a server can
		 * read — so the series exists only if we keep what we saw. Keyed by semana: the
		 * ISP reissues a week under a new version suffix (…-V3, …-V4) and a second row for
		 * the same semana would be a duplicate the pane cannot tell apart, since what it
		 * prints is the semana and the month, never the filename.
		 *
		 * `+` and not array_merge: the fresh reading wins on a key both sides hold, which
		 * is exactly what a reissue is.
		 *
		 * The key carries no YEAR, so SE n of a later year replaces SE n of an earlier
		 * one. Stated rather than fixed: an entry lives KEEP_FOR at most and ADR-0012
		 * empties the cache on every app update, so the series cannot span a full year to
		 * collide with itself — and if it ever did, the informe being kept is the newer
		 * one, which is the one that matters.
		 *
		 * ADR-0012 applies: this is a cache, so any app update on the instance empties it
		 * and the series restarts from the current week. That is accepted — this app has
		 * no database, and the current informe is the one that matters.
		 */
		$items = array_column($fresh, null, 'se') + array_column($stored['items'] ?? [], null, 'se');

		$entry = [
			'items' => $this->sort(array_values($items)),
			'fetched_at' => $this->time->getTime(),
		];
		$this->cache->set($this->key(), $entry, self::KEEP_FOR);

		return $this->present($entry, false);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function parse(string $html): array {
		if (preg_match_all('#<a[^>]+href="([^"]+\.pdf)"[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER) === 0) {
			return [];
		}

		$items = [];
		foreach ($matches as [, $href, $label]) {
			$url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			// Only https, the same guard both siblings carry: `p()` escapes quotes and
			// angle brackets, so it cannot make a URL scheme safe, and a defaced page
			// serving "javascript:…/informe.pdf" under a label this parser accepts would
			// otherwise reach href byte for byte.
			if (stripos($url, 'https://') !== 0) {
				continue;
			}

			$title = $this->text($label);

			/*
			 * The page carries seven PDF anchors and six of them satisfy an upload-folder
			 * rule — two genotipificación summaries, a 2023 ordinario, an antiviral-
			 * resistance study and the ISP's own code of ethics. Only the label
			 * distinguishes the weekly informe, and it is also the only place the semana
			 * epidemiológica appears, so one match does both jobs. Anchors are read, never
			 * constructed: the filename carries a version suffix (…SE35-08-09-2026-V3)
			 * that cannot be derived from a week number.
			 */
			if (preg_match(self::INFORME, $title, $semana) !== 1) {
				continue;
			}

			// Decoration here, not identity — the semana is the identity, so an informe
			// filed somewhere unexpected is still an informe and is kept without a month
			// rather than dropped. The pane renders an empty month as nothing at all.
			$filed = preg_match('#/uploads/(\d{4})/(\d{2})/#', $url, $when) === 1;

			$items[] = [
				// The publisher's own words. Rendered by ReportPane as hidden text that
				// adds to the row's accessible name, never as the visible label — what a
				// reader sees is the semana and the month.
				'title' => $title,
				'url' => $url,
				'year' => $filed ? $when[1] : '',
				'month' => $filed ? $when[1] . '-' . $when[2] : '',
				/*
				 * Canonical digits, not the label's: `Semana 05` and `Semana 5` are one
				 * week typed two ways, and the accumulation below keys on this value.
				 * PHP would key them APART — '05' stays a string key, '5' becomes int 5 —
				 * so a republish that dropped the zero produced a second row, and
				 * ReportPane prints `se` verbatim: "Semana 05" and "Semana 5", side by
				 * side, for the same week. That is the duplicate the pane cannot tell
				 * apart, arriving through the key meant to prevent it.
				 *
				 * Same class of variance as the whitespace `/u` defends against, from the
				 * same WordPress editor, and it was the half left undefended. sort() has
				 * always cast to int; now the key agrees with it.
				 */
				'se' => (string)(int)$semana[1],
			];
		}

		return $items;
	}

	/**
	 * Newest first: by the month it was filed under, THEN by semana.
	 *
	 * Not by semana alone, which is the obvious reading of "newest informe first" and is
	 * wrong for one month a year: semanas restart every January, so SE 1 of the new year
	 * would sort below SE 52 of the old one and the current informe would sit at the
	 * bottom of the pane — and InicioPane shows the first three rows of this list.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return list<array<string, string>>
	 */
	private function sort(array $items): array {
		/*
		 * An informe with no month sorts FIRST, not last.
		 *
		 * parse() keeps an informe filed outside /uploads/YYYY/MM/ rather than dropping
		 * it, because the semana is its identity and the folder is only decoration — and
		 * the ISP already serves a PDF on this very page from a second path convention
		 * (/sites/default/files/documento/…), so this is a shape the page has rather than
		 * one imagined for it. Ordering it by '' undid that decision: '' is lower than
		 * every real month, so the informe just read sank to the bottom of the pane and
		 * off InicioPane's three-row card entirely.
		 *
		 * The sentinel is a SORT key and is never stored or rendered: `month` stays empty,
		 * because the app does not invent a publication date it was not given. First is
		 * the right place for it — this page only ever publishes the current informe, so
		 * one we could not file is one we have just read.
		 *
		 * The price, stated because it is real: an unfiled informe outranks filed ones for
		 * as long as the cache holds it, so a later filed week would appear beneath it.
		 * That is the lesser error. Sorting it last hides the current informe; sorting it
		 * first shows a stale one above a newer one, on a pane that prints the month
		 * beside each row.
		 */
		$when = static fn (array $i): string => $i['month'] === '' ? '9999-99' : $i['month'];

		usort(
			$items,
			static fn (array $a, array $b): int
				=> [$when($b), (int)$b['se']] <=> [$when($a), (int)$a['se']],
		);

		return $items;
	}

	/**
	 * Decoded, with every kind of space collapsed. The informe's label is wrapped in
	 * `<strong>` on the live page, and `\s` under /u already covers U+00A0 — see
	 * MinsalRepository::text(), which carries the measurement.
	 */
	private function text(string $html): string {
		$decoded = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $decoded));
	}

	/** @return ?string null when the page could not be read at all */
	private function request(): ?string {
		try {
			$response = $this->clients->newClient()->get(self::SOURCE, [
				'timeout' => 15,
				// The ISP sends its leaf certificate and nothing else, so verification
				// needs the intermediate CertificateBundle merges into the instance's own
				// anchors. It degrades to those anchors by itself and never throws.
				'verify' => $this->bundle->path(),
			]);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			// 'exception' is a key Nextcloud's log pipeline treats specially: it
			// serialises the class, the trace and the previous chain, which getMessage()
			// would throw away in exactly the case where the trace is what you need.
			$this->logger->warning('Could not reach the ISP informe page', ['exception' => $e]);

			return null;
		}

		/*
		 * The same bound both siblings carry: a parser on an unbounded body fatals the
		 * worker inside a page render. Measured against the largest real payload here —
		 * this page at 59 KB — nearly nine times over. Counted as unread rather than as
		 * empty, because that is what it is.
		 */
		if (strlen($body) > self::MAX_BYTES) {
			$this->logger->warning('ISP informe page of {bytes} bytes, discarded', ['bytes' => strlen($body)]);

			return null;
		}

		return $body;
	}

	private function stored(): ?array {
		$stored = $this->cache->get($this->key());

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
	private function degrade(?array $stored): array {
		$this->cache->set($this->cooldownKey(), 1, self::COOLDOWN);

		return $this->present($stored, true);
	}

	/** Its own key, alongside MinsalRepository's `feed-*` and IspRepository's per-category ones. */
	private function key(): string {
		return 'isp-informes';
	}

	/** Distinct from key(), or the cooldown flag would be read as the fuente's data. */
	private function cooldownKey(): string {
		return 'cooldown-isp-informes';
	}
}
