<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service\Parser;

use Psr\Log\LoggerInterface;

/**
 * MINSAL's alert pages: a hand-edited WordPress page whose tables link each
 * alert as a PDF in the site's own upload folder — degreyd.minsal.cl's
 * in-force table, and epi.minsal.cl's two, "Alertas Nacionales" and "Alertas
 * Internacionales" (ADR-0017). One shape, two pages; the only thing that tells
 * them apart is the address, and the address is the fetcher's business.
 *
 * MinsalRepository::parse() and its helpers, moved without a behaviour change.
 * What changed on the way is the log: two pages share these lines now, so
 * each carries the subject it is about.
 *
 * Read by regex rather than a DOM, on purpose: this is not a document with a
 * schema, and the anchor scan has to keep finding every link whatever the
 * table around it has been edited into.
 */
class PageParser implements Parser {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array<string, string>>
	 */
	public function parse(string $body, string $subject): array {
		/*
		 * `\s*` around the captured URL: two of degreyd's own anchors pad
		 * the href with a trailing space inside the quotes —
		 * `href="…OFICIO_CP_N_11926_2026_ebola.pdf "` — which `[^"]+\.pdf"`
		 * required to sit immediately before the closing quote and so silently
		 * never matched. Both anchors are real, in-force alerts (the Bundibugyo/
		 * ébola oficios), and this class's own $rejected counter below could not
		 * see them either: they never entered $matches at all, and a counter
		 * downstream of the regex cannot count what the regex never matched. A
		 * browser strips this whitespace before treating the attribute as a URL,
		 * so tolerating it here is reading what MINSAL means, not being lenient
		 * about garbage.
		 */
		preg_match_all('#<a[^>]+href="\s*([^"]+\.pdf)\s*"[^>]*>(.*?)</a>#is', $body, $matches, PREG_SET_ORDER);

		// warnOfMissedAnchors() runs even when $matches is empty: a page that
		// carries pdf hrefs but zero readable anchors is the total-loss version
		// of the exact defect above, and it deserves the same warning, not
		// silence because the early return below has not happened yet.
		$this->warnOfMissedAnchors($body, count($matches), $subject);

		if ($matches === []) {
			return [];
		}

		$rowTitles = $this->rowTitles($body);

		/*
		 * An anchor text used by more than one row is a LINK LABEL, not a title.
		 * epi.minsal.cl's international table — `Nombre de la alerta | Fecha |
		 * Descarga` — repeats "Descargar Documento" in its third cell 27 times
		 * out of 78 links (measured 2026-09-28; it was 27 of 75 when that page
		 * was the alerts fuente's host), and taking the anchor text would put 27
		 * indistinguishable rows in front of a clinician — see PageParserTest.
		 * Nothing repeats on degreyd's page (a five-column table, `Evento | Desde
		 * | Alcance territorial | Tipo | Documento para descarga`, measured 0
		 * repeated labels across 13 rows) — see testEveryAlertKeepsItsOwnTitle.
		 * So this branch is live for one page and dormant for the other, and
		 * rowTitles() below is what the live one reads its titles from.
		 *
		 * Detected by repetition rather than by a wordlist: a real title is unique, and
		 * a repeated label is the only kind of string this rule needs to catch. That
		 * also survives MINSAL rewording it, which a wordlist would not.
		 */
		$labels = array_count_values(array_map(
			static fn (array $m): string => self::text($m[2]),
			$matches,
		));

		$items = [];
		$rejected = 0;
		foreach ($matches as [, $href, $label]) {
			// Trimmed for the same reason the regex above tolerates surrounding
			// whitespace: every guard from here on — https, the upload-path date —
			// must run on the URL a browser would actually request, not on a couple
			// of stray bytes the regex already excluded from the capture.
			$url = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

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

			// The anchor text is the official title WHEN it is one. On epi's
			// national table it carries the oficio number, and the fallback below
			// takes the international rows' first cell, headed "Nombre de la
			// alerta" there — the alert's own name. Neither holds on degreyd's
			// page — 0 of 13 anchor texts carry an oficio number, and its own first
			// cell is an EVENT/disease name ("Sarampión", "Influenza B"), which
			// CONTEXT.md is explicit this context has no word for. The fallback is
			// unreachable on degreyd (no label repeats; see the comment above
			// $labels), so it cannot yet title a degreyd alert by disease — but if
			// degreyd ever repeats a label, this fallback would, and this comment
			// is what says so before it happens rather than after.
			$title = self::text($label);
			if (($labels[$title] ?? 0) > 1) {
				$title = $rowTitles[$url] ?? $title;
			}
			if ($title === '') {
				continue;
			}

			$item = [
				'title' => $title,
				'url' => $url,
				// Upload year, not the oficio's year: measured 12 of 13 titles on
				// degreyd's page carry no year at all (47 of 75 on epi's, when it
				// was the alerts fuente's host), so the path is the only field with
				// full coverage either way. The UI therefore labels it plain "Año"
				// and claims nothing more.
				'year' => $when[1],
				'month' => $when[1] . '-' . $when[2],
			];

			// Keyed by URL, because MINSAL links the same PDF from two rows of its own
			// table — epi does, today: one 2024 respiratory alert, two rows. The panes
			// key on the url, so a duplicate was a duplicate Vue key as well as a row
			// printed twice.
			$items[$url] = $item;
		}

		if ($rejected > 0) {
			// Names its page again: two share this parser now (ADR-0017), and a
			// line about one of them must not send its reader to the other.
			$this->logger->warning(
				'Dropped {rejected} non-https links from the {subject}',
				['rejected' => $rejected, 'subject' => $subject],
			);
		}

		return $this->sort(array_values($items));
	}

	/**
	 * Decoded, with every kind of space collapsed — these titles are full of `&nbsp;`.
	 *
	 * Public and static because it is the title rule, not a page rule: a parser
	 * that lifts a title out of HTML lifts it through here, so what counts as a
	 * space is decided once.
	 *
	 * The `\x{00A0}` is belt-and-braces, not the load-bearing part, and the comment that
	 * used to be here said the opposite: "`\s` does not match U+00A0 even under /u".
	 * Measured, that is false at both ends of the supported range — PHP 8.1 with PCRE2
	 * 10.39 and PHP 8.5 with PCRE2 10.44 both match U+00A0, U+2000-U+200A, U+202F,
	 * U+205F and U+3000 with `\s` alone, because PHP's /u carries UCP semantics. Kept
	 * anyway: it costs nothing and does not depend on that holding. IspReportRepository's
	 * `$clean` names more codepoints for the same reason, and the difference is
	 * unobservable.
	 */
	public static function text(string $html): string {
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
	 * A rowspan continuation row has no first cell of its own, so its entry here
	 * is whichever cell comes first — epi's rowspan groups carry such rows. Never
	 * read today: those rows' anchors carry unique text, so parse() keeps the
	 * anchor and never asks (measured 2026-09-28).
	 *
	 * @return array<string, string>
	 */
	private function rowTitles(string $html): array {
		if (preg_match_all('#<tr\b.*?</tr>#is', $html, $rows) === 0) {
			return [];
		}

		$titles = [];
		foreach ($rows[0] as $row) {
			// `\s*` around the URL, same as parse()'s own anchor scan and for the
			// same reason: this side must key its dictionary by the SAME trimmed
			// URL parse() looks it up by, or a padded href would never match here.
			if (preg_match('#<a[^>]+href="\s*([^"]+\.pdf)\s*"#i', $row, $link) !== 1) {
				continue;
			}
			if (preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $row, $cells) === 0) {
				continue;
			}
			foreach ($cells[1] as $cell) {
				$name = self::text($cell);
				// The first cell that says anything. `rowspan` cells and stray `&nbsp;`
				// ones are real on this page.
				if ($name !== '') {
					$titles[trim(html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))] = $name;
					break;
				}
			}
		}

		return $titles;
	}

	/**
	 * A cheap, independent count of every `href="…pdf"`, anywhere in the raw
	 * document — with no dependency on the richer anchor pattern in parse()
	 * actually matching it. That pattern requires `<a[^>]+href="…"[^>]*>…</a>`,
	 * and the whitespace defect this method was added for is proof that a real
	 * anchor can fail that shape: `$rejected` above only counts URLs the regex
	 * DID capture and then reject, so a link the regex never matched at all was
	 * uncountable by construction, and the fuente under-reported by two out of
	 * thirteen with nothing in the log to say so.
	 *
	 * Deliberately just a warning, and deliberately silent on which anchors were
	 * lost: it exists so the NEXT shape of loss — not only the one just fixed —
	 * shows up in the log on day one instead of staying invisible for months. It
	 * must never fail the fetch or degrade a fuente that is otherwise healthy; a
	 * shortfall this coarse is a prompt to go look, not proof that nothing was
	 * read.
	 */
	private function warnOfMissedAnchors(string $html, int $matched, string $subject): void {
		$present = (int)preg_match_all('#href="\s*[^"]*\.pdf\s*"#i', $html);
		if ($present > $matched) {
			$this->logger->warning(
				'{subject} has {present} pdf link(s) in href attributes but only {matched} were read as anchors — an alert may be missing',
				['subject' => $subject, 'present' => $present, 'matched' => $matched],
			);
		}
	}

	/**
	 * MINSAL's own page order is not chronological: entries get appended and
	 * edited without re-sorting, so rendering in document order shows a visibly
	 * jumbled list to anyone who scrolls. Measured: 1 inversion across degreyd's
	 * 13 anchors (`1781616195625_OFI.pdf` sits after two older alerts in the
	 * markup — see testTheFixtureStillContainsTheInversionThisSortExistsToFix),
	 * 9 across epi's 78 (2026-09-28).
	 *
	 * usort is stable in PHP 8, which is the point — within a month (5 distinct
	 * months across degreyd's 13 alerts, 27 across epi's 77) MINSAL's own
	 * curation survives, and that is the only ordering signal finer than a month
	 * we are entitled to use.
	 *
	 * @param list<array<string, string>> $items
	 *
	 * @return list<array<string, string>>
	 */
	private function sort(array $items): array {
		usort($items, static fn (array $a, array $b): int => strcmp($b['month'], $a['month']));

		return $items;
	}
}
