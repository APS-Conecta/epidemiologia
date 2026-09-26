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
 * It is a catalogue, NOT a base class. Its two owners are not merged and must not
 * be: MinsalRepository is one page whose emptiness is always a failure, and
 * IspReportRepository is one feed that ACCUMULATES what it reads, because the ISP
 * publishes only the current informe and the series' history lives in the feed's
 * pages. Those disagreements are the whole reason there are two classes — see
 * each one's docblock. This maps ids to owners; the owners keep their rules.
 *
 * Outside a repository, a source is identified by the id below. The repositories
 * keep their own internal feed names and cache keys, and this is the only place
 * the translation between the two lives.
 */
class Sources {
	public const ALERTS = 'alerts';
	public const REPORTS = 'reports';

	/** Every source, in the order the app presents them. */
	public const ALL = [self::ALERTS, self::REPORTS];

	/**
	 * The sources that carry alerts somebody would search for by subject. An
	 * informe is a weekly PDF identified by its semana epidemiológica, so matching
	 * "sarampión" against its title finds nothing a reader wanted.
	 */
	private const SEARCHABLE = [self::ALERTS];

	public function __construct(
		private MinsalRepository $minsal,
		private IspReportRepository $informes,
	) {
	}

	/**
	 * Every source with its freshness and its state — the whole payload of the page
	 * and of the OCS route.
	 *
	 * @return array<string, array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool, total: int, has_more: bool}>
	 */
	public function all(): array {
		$sources = [];
		foreach (self::ALL as $id) {
			$sources[$id] = $this->get($id);
		}

		return $sources;
	}

	/**
	 * @return array{items: list<array<string, string>>, fetched_at: ?int, degraded: bool, total: int, has_more: bool}
	 */
	public function get(string $id): array {
		// No default arm on purpose, here and below: an id added to ALL and nowhere
		// else throws UnhandledMatchError instead of quietly returning nothing. That
		// loudness is the property this class is for, and SourcesTest walks ALL
		// through every method so the failure lands in CI-less development, not in
		// front of a clinician.
		$source = match ($id) {
			self::ALERTS => $this->minsal->get(),
			self::REPORTS => $this->informes->get(),
		};

		// Every source says how many it holds and whether anything was left out.
		// Whole means nothing was left out.
		$source['total'] = count($source['items']);
		$source['has_more'] = false;

		return $source;
	}

	/** @return bool false when the source is unreachable, or changed into something we cannot parse */
	public function probe(string $id): bool {
		return match ($id) {
			self::ALERTS => $this->minsal->probe(),
			self::REPORTS => $this->informes->probe(),
		};
	}

	/**
	 * The accumulating fuente's deep pass — the one-shot backfill and the
	 * document archiver (L3-08). The read path serves page 1 and stops; this
	 * is the job's door to everything deeper. Only REPORTS accumulates a
	 * back catalogue — an in-force set replaces itself whole inside get() —
	 * so the call is direct rather than a match: a second fuente that starts
	 * accumulating says so by gaining an arm here, the same loudness every
	 * other method of this class carries.
	 */
	public function converge(): void {
		$this->informes->converge();
	}

	/**
	 * What the source is called mid-sentence, institution included: "no se pudo leer
	 * las alertas del MINSAL". The institution is part of the phrase rather
	 * than a second parameter because that is the fact the old notification got
	 * wrong — it said "desde MINSAL" for every feed that was not the IRAG one.
	 */
	public function name(string $id, IL10N $l): string {
		return match ($id) {
			self::ALERTS => $l->t('las alertas epidemiológicas vigentes del MINSAL'),
			self::REPORTS => $l->t('el informe semanal de Circulación de Virus Respiratorios del ISP'),
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
			self::REPORTS => $l->t('Informe del ISP'),
		};
	}

	/**
	 * Search across the searchable sources — the cache, then the durable table
	 * behind each fuente — carrying each hit's source with it so the caller can
	 * label it. Never fetches — see MinsalRepository::search().
	 *
	 * @return list<array{source: string, item: array<string, string>}>
	 */
	public function search(string $term): array {
		$hits = [];
		foreach (self::SEARCHABLE as $id) {
			$items = match ($id) {
				self::ALERTS => $this->minsal->search($term),
			};

			foreach ($items as $item) {
				$hits[] = ['source' => $id, 'item' => $item];
			}
		}

		return $hits;
	}
}
