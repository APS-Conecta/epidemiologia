<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service\Parser;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * The ISP's alert categories, as WordPress syndicates them: one RSS 2.0 feed
 * per category — ANAMED, Farmacovigilancia, Vigilancia de cosméticos
 * (ADR-0017). One shape, three feeds; the category is the address, and the
 * address is the fetcher's business.
 *
 * The retired IspRepository's parse(), restored without the two things
 * ADR-0016 retired it for: the 24-category plumbing (a fuente is one feed
 * now, and the table's fuente column is the category tag) and the `h`
 * revision fingerprint — an alert edited in place is upserted in place
 * (AlertMapper::upsert()), and nothing renders a revision.
 *
 * Read with the XML reader rather than by regex: unlike MINSAL's pages, this
 * really is a document with a schema. The informe's feed is read by
 * IspReportRepository's own parse, which reads what an informe needs — the
 * semana in <description>, the PDF inside <content:encoded> — and none of it
 * applies to an alert, whose post page is the document a clinician opens.
 */
class FeedParser implements Parser {
	/**
	 * A published day belongs to whoever published it. pubDate is an instant
	 * in +0000; rendered in the container's UTC, anything the ISP publishes
	 * after 20:00 in Santiago lands on the next day — measured on the live
	 * archive 2026-08-05, one alert of 173 was a day late. Not the reader's
	 * zone either: Chile spans three, and a recall does not change date
	 * because the clinic is in Punta Arenas.
	 */
	private const PUBLISHER_TZ = 'America/Santiago';

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array<string, string>>
	 */
	public function parse(string $body, string $subject): array {
		$previous = libxml_use_internal_errors(true);
		$feed = simplexml_load_string($body);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($feed === false || !isset($feed->channel->item)) {
			return [];
		}

		$items = [];
		$rejected = 0;
		foreach ($feed->channel->item as $node) {
			// The title rule is PageParser's: seven of 168 live ISP titles once
			// carried a leading U+00A0 or a trailing U+2002 — Farmacovigilancia's
			// valproato note still ends in one — and a leading one renders as a
			// visible indent on the pane's first row.
			$title = PageParser::text((string)$node->title);

			// The post's own page, the document a clinician opens: an alert's
			// PDF sits inside it, and some alerts carry none.
			$url = trim((string)$node->link);

			$at = strtotime((string)$node->pubDate);

			/*
			 * Only https reaches an href — `p()` cannot make a URL scheme safe —
			 * and an item with no title or no readable day cannot be shown or
			 * placed. Counted rather than dropped quietly: a partial silent loss
			 * is the one failure this app is built to avoid, and only zero items
			 * raises the banner.
			 */
			if ($title === '' || stripos($url, 'https://') !== 0 || $at === false) {
				$rejected++;
				continue;
			}

			$day = (new DateTimeImmutable('@' . $at))
				->setTimezone(new DateTimeZone(self::PUBLISHER_TZ))
				->format('Y-m-d');

			// Keyed by URL: one feed listing a post twice is one alert, the
			// same identity the table keys a stream on.
			$items[$url] = [
				'title' => $title,
				'url' => $url,
				'year' => substr($day, 0, 4),
				'month' => substr($day, 0, 7),
				'date' => $day,
			];
		}

		if ($rejected > 0) {
			$this->logger->warning(
				'Dropped {rejected} item(s) the {subject} could not be trusted for',
				['rejected' => $rejected, 'subject' => $subject],
			);
		}

		$items = array_values($items);

		// Newest first, stable: WordPress already lists by date, and the sort
		// is what the contract promises rather than what one feed happens to do.
		usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

		return $items;
	}
}
