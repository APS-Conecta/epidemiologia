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
	 * @return array<string, array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}>
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
	 * The archive outgrew what `IInitialState` can carry: 1.423+ alerts is ~357 KB of
	 * JSON in the HTML of every page load, paid by every user including the ones who
	 * never open the pane. So the newest slice still ships inline and the depth is
	 * fetched on demand, which is what this serves.
	 *
	 * The envelope is deliberately identical to get()'s. A caller that narrows should
	 * not also have to parse a different shape, and the pane appends these items to the
	 * ones it already holds.
	 *
	 * @param ?string $category one of the source's own category slugs, or null for all
	 * @param ?int    $olderThan epoch; only items published strictly before it
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function page(string $id, ?string $category = null, ?int $olderThan = null): array {
		$source = $this->get($id);

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
		$source['items'] = array_slice($source['items'], 0, self::PAGE);

		return $source;
	}

	/**
	 * @return array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool}
	 */
	public function get(string $id): array {
		// No default arm on purpose, here and below: an id added to ALL and nowhere
		// else throws UnhandledMatchError instead of quietly returning nothing. That
		// loudness is the property this class is for, and SourcesTest walks ALL
		// through every method so the failure lands in CI-less development, not in
		// front of a clinician.
		return match ($id) {
			self::ALERTS => $this->minsal->get(MinsalRepository::ALERTS),
			self::REPORTS => $this->minsal->get(MinsalRepository::IRAG),
			self::SANITARY_ALERTS => $this->isp->get(),
		};
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
