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
 * be: AlertRepositories serves every alert fuente on one pipeline, an
 * AlertRepository per fuente (ADR-0017), and IspReportRepository is one feed read
 * as a SERIES — one informe per semana, keyed by (year, se), each document kept —
 * because the ISP publishes only the current informe and the series' history
 * lives in the feed's pages. Those disagreements are the whole reason there are
 * two owners — see each one's docblock. This maps ids to owners; the owners keep
 * their rules.
 *
 * Outside a repository, a source is identified by the id below. An alert fuente
 * carries the id itself — AlertRepositories spells each one in its own match, and
 * AlertRepositoryTest walks ALL through it — while the informe keeps its own feed
 * name and cache key, and this is where that one translates.
 */
class Sources {
	public const ALERTS = 'alerts';
	public const EPI = 'epi_alerts';
	public const REPORTS = 'reports';
	public const ISP_ANAMED = 'isp_anamed';
	public const ISP_PHARMACOVIGILANCE = 'isp_pharmacovigilance';
	public const ISP_COSMETICS = 'isp_cosmetics';
	public const PAHO = 'paho_alerts';

	/** Every source, in the order the app presents them. */
	public const ALL = [
		self::ALERTS,
		self::EPI,
		self::REPORTS,
		self::ISP_ANAMED,
		self::ISP_PHARMACOVIGILANCE,
		self::ISP_COSMETICS,
		self::PAHO,
	];

	/**
	 * The sources that carry alerts somebody would search for by subject. An
	 * informe is a weekly PDF identified by its semana epidemiológica, so matching
	 * "sarampión" against its title finds nothing a reader wanted.
	 */
	private const SEARCHABLE = [
		self::ALERTS,
		self::EPI,
		self::ISP_ANAMED,
		self::ISP_PHARMACOVIGILANCE,
		self::ISP_COSMETICS,
		self::PAHO,
	];

	/**
	 * The sources that keep what they read and have a history deeper than
	 * their first page — the refresh job's deep pass walks these (converge()).
	 * An in-force set replaces itself whole inside get() and has none.
	 */
	public const ACCUMULATING = [
		self::REPORTS,
		self::ISP_ANAMED,
		self::ISP_PHARMACOVIGILANCE,
		self::ISP_COSMETICS,
		self::PAHO,
	];

	public function __construct(
		private AlertRepositories $alerts,
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
			self::ALERTS => $this->alerts->repository(self::ALERTS)->get(),
			self::EPI => $this->alerts->repository(self::EPI)->get(),
			self::REPORTS => $this->informes->get(),
			self::ISP_ANAMED => $this->alerts->repository(self::ISP_ANAMED)->get(),
			self::ISP_PHARMACOVIGILANCE => $this->alerts->repository(self::ISP_PHARMACOVIGILANCE)->get(),
			self::ISP_COSMETICS => $this->alerts->repository(self::ISP_COSMETICS)->get(),
			self::PAHO => $this->alerts->repository(self::PAHO)->get(),
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
			self::ALERTS => $this->alerts->repository(self::ALERTS)->probe(),
			self::EPI => $this->alerts->repository(self::EPI)->probe(),
			self::REPORTS => $this->informes->probe(),
			self::ISP_ANAMED => $this->alerts->repository(self::ISP_ANAMED)->probe(),
			self::ISP_PHARMACOVIGILANCE => $this->alerts->repository(self::ISP_PHARMACOVIGILANCE)->probe(),
			self::ISP_COSMETICS => $this->alerts->repository(self::ISP_COSMETICS)->probe(),
			self::PAHO => $this->alerts->repository(self::PAHO)->probe(),
		};
	}

	/**
	 * The revalidation a stale serve dispatched (L1-01): the background
	 * half of get()'s serve-then-refresh. Match arms like every other
	 * method — an id added to ALL and nowhere else throws here too.
	 */
	public function revalidate(string $id): void {
		match ($id) {
			self::ALERTS => $this->alerts->repository(self::ALERTS)->revalidate(),
			self::EPI => $this->alerts->repository(self::EPI)->revalidate(),
			self::REPORTS => $this->informes->revalidate(),
			self::ISP_ANAMED => $this->alerts->repository(self::ISP_ANAMED)->revalidate(),
			self::ISP_PHARMACOVIGILANCE => $this->alerts->repository(self::ISP_PHARMACOVIGILANCE)->revalidate(),
			self::ISP_COSMETICS => $this->alerts->repository(self::ISP_COSMETICS)->revalidate(),
			self::PAHO => $this->alerts->repository(self::PAHO)->revalidate(),
		};
	}

	/**
	 * One accumulating fuente's deep pass (L3-08): the informe's backfill walk
	 * and document archiver, a stream's one-shot backfill (ADR-0017 D7). The
	 * read path serves page 1 and stops; this is the job's door to everything
	 * deeper. Arms for ACCUMULATING alone, and no default: an in-force id has
	 * no history to walk, and asking for one throws rather than doing nothing.
	 */
	public function converge(string $id): void {
		match ($id) {
			self::REPORTS => $this->informes->converge(),
			self::ISP_ANAMED => $this->alerts->repository(self::ISP_ANAMED)->converge(),
			self::ISP_PHARMACOVIGILANCE => $this->alerts->repository(self::ISP_PHARMACOVIGILANCE)->converge(),
			self::ISP_COSMETICS => $this->alerts->repository(self::ISP_COSMETICS)->converge(),
			self::PAHO => $this->alerts->repository(self::PAHO)->converge(),
		};
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
			self::EPI => $l->t('las alertas del Departamento de Epidemiología del MINSAL'),
			self::REPORTS => $l->t('el informe semanal de Circulación de Virus Respiratorios del ISP'),
			self::ISP_ANAMED => $l->t('las alertas de medicamentos (ANAMED) del ISP'),
			self::ISP_PHARMACOVIGILANCE => $l->t('las alertas de farmacovigilancia del ISP'),
			self::ISP_COSMETICS => $l->t('las alertas de vigilancia de cosméticos del ISP'),
			self::PAHO => $l->t('las alertas y actualizaciones epidemiológicas de la OPS/OMS'),
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
			self::EPI => $l->t('Alerta del Departamento de Epidemiología del MINSAL'),
			self::REPORTS => $l->t('Informe del ISP'),
			self::ISP_ANAMED => $l->t('Alerta de medicamentos (ANAMED) del ISP'),
			self::ISP_PHARMACOVIGILANCE => $l->t('Alerta de farmacovigilancia del ISP'),
			self::ISP_COSMETICS => $l->t('Alerta de vigilancia de cosméticos del ISP'),
			self::PAHO => $l->t('Alerta o actualización epidemiológica de la OPS/OMS'),
		};
	}

	/**
	 * Search across the searchable sources — the cache, then the durable table
	 * behind each fuente — carrying each hit's source with it so the caller can
	 * label it. Never fetches — see AlertRepository::search().
	 *
	 * @return list<array{source: string, item: array<string, string>}>
	 */
	public function search(string $term): array {
		$hits = [];
		foreach (self::SEARCHABLE as $id) {
			$items = match ($id) {
				self::ALERTS => $this->alerts->repository(self::ALERTS)->search($term),
				self::EPI => $this->alerts->repository(self::EPI)->search($term),
				self::ISP_ANAMED => $this->alerts->repository(self::ISP_ANAMED)->search($term),
				self::ISP_PHARMACOVIGILANCE => $this->alerts->repository(self::ISP_PHARMACOVIGILANCE)->search($term),
				self::ISP_COSMETICS => $this->alerts->repository(self::ISP_COSMETICS)->search($term),
				self::PAHO => $this->alerts->repository(self::PAHO)->search($term),
			};

			foreach ($items as $item) {
				$hits[] = ['source' => $id, 'item' => $item];
			}
		}

		return $hits;
	}
}
