<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service\Parser;

use OCA\Epidemiologia\Service\AlertRepository;
use Psr\Log\LoggerInterface;

/**
 * OPS/OMS's "Alertas y actualizaciones epidemiológicas": a Drupal Views
 * listing on www.paho.org, eighteen documents a page, newest first
 * (ADR-0017). Each row is one document: its publication instant in a
 * `<time datetime>`, then its title, linking the document's own page — the
 * PDF sits inside it.
 *
 * Read by regex, like MINSAL's pages: the rows are the only structure this
 * page promises. Each row is read inside its own bounds — from its
 * `views-field-created` field to the next row's — and inside them the time
 * comes first and the link in the `views-field-title` field second. The
 * image link every row opens with, the menus and the pager never match:
 * measured 36 rows over the owner's two captures (2026-09-28), 36 read.
 *
 * Every item carries a type (D13): the list mixes alerts, updates and a few
 * other publications, and PAHO says which in the first words of each title.
 */
class PahoParser implements Parser {
	/** What the listing's hrefs are relative to: the page's own origin. */
	private const ORIGIN = 'https://www.paho.org';

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array<string, string>>
	 */
	public function parse(string $body, string $subject): array {
		/*
		 * One chunk per row, cut at the field every row opens its text with.
		 * Bounded so: a row that lost its title field is dropped and counted,
		 * never paired with the next row's link — one lazy pattern across the
		 * whole body would date the next document by the row before it, and
		 * say nothing.
		 */
		$rows = array_slice(explode('views-field-created', $body), 1);

		$items = [];
		$rejected = 0;
		foreach ($rows as $row) {
			if (preg_match('#<time[^>]*\bdatetime="([^"]+)".*?views-field-title.*?<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $row, $m) !== 1) {
				$rejected++;
				continue;
			}

			// The title rule is PageParser's: what counts as a space is decided once.
			$title = PageParser::text($m[3]);
			$url = self::absolute(trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

			/*
			 * The publisher's day: the date part of the instant as PAHO wrote
			 * it, in its own offset — Washington's, -04:00 or -05:00. Not UTC:
			 * a note stamped 19:26 on 11 December at -05:00 is the 12th in UTC,
			 * and PAHO dated it the 11th. Nor Santiago: a published day belongs
			 * to whoever published it (FeedParser).
			 */
			$day = preg_match('/^(\d{4}-\d{2}-\d{2})T/', $m[1], $when) === 1 ? $when[1] : null;

			/*
			 * Only https reaches an href — `p()` cannot make a URL scheme safe —
			 * and a row with no title or no readable day cannot be shown or
			 * placed. Counted rather than dropped quietly: a partial silent loss
			 * is the one failure this app is built to avoid, and only zero
			 * items raises the banner.
			 */
			if ($title === '' || $url === null || $day === null) {
				$rejected++;
				continue;
			}

			// Keyed by URL: a document listed twice is one alert, the same
			// identity the table keys a stream on.
			$items[$url] = [
				'title' => $title,
				'url' => $url,
				'year' => substr($day, 0, 4),
				'month' => substr($day, 0, 7),
				'date' => $day,
				'tipo' => self::tipo($title),
			];
		}

		if ($rejected > 0) {
			$this->logger->warning(
				'Dropped {rejected} row(s) of the {subject} that could not be read or trusted',
				['rejected' => $rejected, 'subject' => $subject],
			);
		}

		$items = array_values($items);

		// Newest first, stable: PAHO already lists by date, and the sort is
		// what the contract promises rather than what one page happens to do.
		usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

		return $items;
	}

	/**
	 * The href as a browser would request it, or null when that is not https.
	 * The listing links its documents by path (`/es/documentos/…`), resolved
	 * against the origin; an absolute https link is kept as written. Anything
	 * else — http, `javascript:`, a protocol-relative `//host` — never becomes
	 * an item.
	 */
	private static function absolute(string $href): ?string {
		if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
			return self::ORIGIN . $href;
		}

		return stripos($href, 'https://') === 0 ? $href : null;
	}

	/**
	 * alerta, actualizacion or otro, from the title's first two words (D13):
	 * folded as search folds (AlertRepository::fold()), on a title whose
	 * spaces PageParser::text() already collapsed, and blind to whatever
	 * follows them — so "ALERTA EPIDEMIOLÓGICA: Sarampión" and "Alerta
	 * epidemiologica - Dengue" say the same. Measured 32 of the 36 rows fold
	 * to the two; the other four — two notas informativas, travel advice and
	 * an ESPII briefing — are "otro", never forced into either.
	 *
	 * fold() is called, not copied: it is the app's one folding rule, pinned
	 * against its JS twin (FoldConformanceTest). A static call on a pure
	 * function is no container edge — this class's constructor takes only the
	 * logger — so it cannot close the kind of resolution cycle AppId's
	 * docblock retired.
	 */
	private static function tipo(string $title): string {
		$words = AlertRepository::fold($title);

		return match (true) {
			str_starts_with($words, 'alerta epidemiologica') => 'alerta',
			str_starts_with($words, 'actualizacion epidemiologica') => 'actualizacion',
			default => 'otro',
		};
	}
}
