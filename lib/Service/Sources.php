<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\IL10N;

/**
 * The catalogue: what sources this app has, who owns each, and what to call them.
 *
 * It exists because seven places used to enumerate the sources independently —
 * the API, the page, the frontend state, the probe, the setup check, the notifier
 * and the search provider — and adding the ISP updated four of them. The other
 * three were not separate bugs; they were the same omission, and the next source
 * would have produced it again. So: one list here, and everything else asks.
 *
 * It is a catalogue, NOT a base class. MinsalRepository and IspRepository are not
 * merged and must not be: MINSAL is one page whose emptiness is always a failure,
 * while the ISP is 24 feeds of which several are legitimately empty. That
 * disagreement is the whole reason the second class exists — see its docblock.
 * This maps ids to owners; the owners keep their rules.
 *
 * Outside a repository, a source is identified by the id below. The repositories
 * keep their own internal feed names and cache keys, and this is the only place
 * the translation between the two lives.
 */
class Sources {
	public const ALERTS = 'alerts';
	public const REPORTS = 'reports';

	/**
	 * `sanitary_alerts`, not `isp_alerts`: CONTEXT.md names the object *alerta
	 * sanitaria ISP* and lists *alerta ISP* under Avoid. The id is public — it is
	 * a key in the OCS payload — so it follows the glossary, not the class
	 * constant it happens to be stored under.
	 */
	public const SANITARY_ALERTS = 'sanitary_alerts';

	/** Every source, in the order the app presents them. */
	public const ALL = [self::ALERTS, self::REPORTS, self::SANITARY_ALERTS];

	/**
	 * What each fuente can be narrowed by. Absent means "nothing" — MINSAL publishes a
	 * year and a month, so neither a category nor a cursor means anything to it.
	 *
	 * A constant and not a sample of the data: see page(). Adding a narrowing to a fuente
	 * is a deliberate act, and this is the one line it takes.
	 */
	private const NARROWABLE = [self::SANITARY_ALERTS => ['categories', 'at']];

	/** The category values each fuente publishes. Only the ISP has any. */
	private const VOCABULARY = [self::SANITARY_ALERTS => IspRepository::TERMS];

	/**
	 * The two that carry alerts somebody would search for by subject. An informe
	 * is a weekly PDF identified by its semana epidemiológica, so matching
	 * "sarampión" against its title finds nothing a reader wanted.
	 */
	private const SEARCHABLE = [self::ALERTS, self::SANITARY_ALERTS];

	/**
	 * Items per narrowed response. One number, because it is the only thing standing
	 * between a caller and the whole 1.423-alert archive in a single payload — which is
	 * the problem the narrowing exists to solve.
	 */
	private const PAGE = 100;

	public function __construct(
		private MinsalRepository $minsal,
		private IspRepository $isp,
	) {
	}

	/**
	 * Every source with its freshness and its state — the whole payload of the page
	 * and of the OCS route.
	 *
	 * @return array<string, array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}>
	 */
	public function all(): array {
		$sources = [];
		foreach (self::ALL as $id) {
			$sources[$id] = $this->get($id);
		}

		return $sources;
	}

	/**
	 * One source, narrowed — a category and/or everything older than a cursor.
	 *
	 * Returns NULL when the source cannot answer the narrowing asked of it. That is
	 * the whole point of the guard: the filters read `categories` and `at`, which only
	 * ISP items carry. MINSAL items carry `year` and `month`, so the old code answered
	 * a MINSAL request with confident nonsense — `category` matched nothing and
	 * returned an empty archive, `olderThan` compared against a defaulted 0 and
	 * returned everything, both labelled as if the filter had been applied.
	 *
	 * Refusing is better than guessing. A caller asking for something a fuente cannot
	 * express has made a mistake, and a wrong answer that looks right is the failure
	 * this repository keeps paying for elsewhere.
	 *
	 * @param ?string $category one of the source's own category slugs, or null for all
	 * @param ?int    $olderThan epoch; only items published strictly before it
	 * @return ?array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool, total: int, has_more: bool}
	 */
	public function page(string $id, ?string $category = null, ?int $olderThan = null): ?array {
		$source = $this->get($id);

		/*
		 * Judged on the FUENTE, not on a sample of its contents.
		 *
		 * This used to read the keys off `items[0]`, so that a fuente growing an `at`
		 * later would start being narrowable with no constant to remember. The cost was
		 * that the guard disappeared exactly when there was nothing to sample: during a
		 * MINSAL outage the same unanswerable narrowing that gets 400 on a good day came
		 * back 200 with an empty list. What a fuente can express is a fact about the
		 * fuente, and an empty one has not stopped being MINSAL.
		 */
		$narrowable = self::NARROWABLE[$id] ?? [];
		if ($category !== null && !in_array('categories', $narrowable, true)) {
			return null;
		}
		if ($olderThan !== null && !in_array('at', $narrowable, true)) {
			return null;
		}

		/*
		 * A category the fuente does not publish is a narrowing it cannot express, the
		 * same as asking MINSAL for one — so it gets the same 400. Judged against the
		 * vocabulary, never against the items: during an outage the items are empty, and
		 * answering "no hay alertas" to a valid category would be the confident-empty
		 * this route already refuses elsewhere.
		 */
		if ($category !== null && !in_array($category, self::VOCABULARY[$id] ?? [], true)) {
			return null;
		}

		// A cursor is an instant. `?olderThan=abc` arrives here as 0 after PHP's cast,
		// and 0 silently matches nothing rather than saying the request was nonsense.
		if ($olderThan !== null && $olderThan <= 0) {
			return null;
		}

		$source['items'] = array_values(array_filter(
			$source['items'],
			static fn (array $i): bool =>
				($category === null || in_array($category, $i['categories'] ?? [], true))
				&& ($olderThan === null || (int)($i['at'] ?? 0) < $olderThan),
		));

		// Capped so one call cannot ask for the whole archive by omitting the cursor —
		// the reason this route exists is that the whole archive is too big to hand over
		// in one response. Items are already newest-first, so this is the newest page of
		// whatever was asked for, and the caller pages back with the last `at` it saw.
		//
		// `total` and `has_more` because the cap was invisible: a narrowed response of
		// 100 looked exactly like a complete one, and naming a source returned FEWER
		// items than not naming one — 100 against 273 — with nothing in the envelope
		// saying so. A consumer that cannot tell "that is all" from "that is a page" is
		// the confident-empty defect again, one level up.
		$total = count($source['items']);
		$source['items'] = array_slice($source['items'], 0, self::PAGE);
		$source['total'] = $total;          // of the NARROWING, not of the fuente
		$source['has_more'] = $total > self::PAGE;

		return $source;
	}

	/**
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool}
	 */
	public function get(string $id): array {
		// No default arm on purpose, here and below: an id added to ALL and nowhere
		// else throws UnhandledMatchError instead of quietly returning nothing. That
		// loudness is the property this class is for, and SourcesTest walks ALL
		// through every method so the failure lands in CI-less development, not in
		// front of a clinician.
		$source = match ($id) {
			self::ALERTS => $this->minsal->get(MinsalRepository::ALERTS),
			self::REPORTS => $this->minsal->get(MinsalRepository::IRAG),
			self::SANITARY_ALERTS => $this->isp->get(),
		};

		// Every source says how many it holds and whether anything was left out, so a
		// narrowed answer and a whole one are the same shape — the property
		// testPageLeavesTheEnvelopeAlone records. Whole means nothing was left out.
		$source['total'] = count($source['items']);
		$source['has_more'] = false;

		return $source;
	}

	/** @return bool false when the source is unreachable, or changed into something we cannot parse */
	public function probe(string $id): bool {
		return match ($id) {
			self::ALERTS => $this->minsal->probe(MinsalRepository::ALERTS),
			self::REPORTS => $this->minsal->probe(MinsalRepository::IRAG),
			self::SANITARY_ALERTS => $this->isp->probe(),
		};
	}

	/**
	 * What the source is called mid-sentence, institution included: "no se pudo leer
	 * las alertas sanitarias del ISP". The institution is part of the phrase rather
	 * than a second parameter because that is the fact the old notification got
	 * wrong — it said "desde MINSAL" for every feed that was not the IRAG one.
	 */
	public function name(string $id, IL10N $l): string {
		return match ($id) {
			self::ALERTS => $l->t('las alertas epidemiológicas vigentes del MINSAL'),
			self::REPORTS => $l->t('los informes semanales ETI/IRAG del MINSAL'),
			self::SANITARY_ALERTS => $l->t('las alertas sanitarias del ISP'),
		};
	}

	/**
	 * What one item of this source is, for a search result that has to stand alone
	 * in a list mixed with files and contacts. CONTEXT.md is explicit that an alerta
	 * sanitaria and an alerta vigente are different objects and never merged, so the
	 * kind is what keeps them apart once they share a result list.
	 */
	public function kind(string $id, IL10N $l): string {
		return match ($id) {
			self::ALERTS => $l->t('Alerta epidemiológica MINSAL'),
			self::REPORTS => $l->t('Informe ETI/IRAG'),
			self::SANITARY_ALERTS => $l->t('Alerta sanitaria ISP'),
		};
	}

	/**
	 * Cache-only search across the searchable sources, carrying each hit's source
	 * with it so the caller can label it. Never fetches — see MinsalRepository::search().
	 *
	 * @return list<array{source: string, item: array<string, string>}>
	 */
	public function search(string $term): array {
		$hits = [];
		foreach (self::SEARCHABLE as $id) {
			$items = match ($id) {
				self::ALERTS => $this->minsal->search($term),
				self::SANITARY_ALERTS => $this->isp->search($term),
			};

			foreach ($items as $item) {
				$hits[] = ['source' => $id, 'item' => $item];
			}
		}

		return $hits;
	}
}
