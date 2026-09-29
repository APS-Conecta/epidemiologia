<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCA\Epidemiologia\Db\AlertMapper;
use OCA\Epidemiologia\Service\Parser\FeedParser;
use OCA\Epidemiologia\Service\Parser\PageParser;
use OCA\Epidemiologia\Service\Parser\PahoParser;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Where every alert fuente publishes, and the pipeline each one is served by
 * — ADR-0017. Sources asks this for a fuente by id; nothing else builds an
 * AlertRepository.
 *
 * Autowired like everything but the three services Application registers:
 * Nextcloud's container keys a service by class name, so six configured
 * instances of one class cannot be six registrations. They are one factory,
 * memoised, so a request that asks for a fuente twice is served by one
 * instance — its freno and its cache handle built once.
 *
 * The ids are spelled here, not imported: Sources' constructor takes this
 * class, and importing Sources for its constants would close the Sources ↔
 * repository cycle AppId's docblock retired. A drift is loud all the same —
 * the match has no default arm, like every one of Sources', and
 * AlertRepositoryTest walks Sources::ALL through it — and each id is written
 * once: the Fuente carries the one it was asked for.
 */
class AlertRepositories {
	/**
	 * The ISP's host, as a brake-and-cooldown grain (ADR-0017 D8): every fuente
	 * on www.ispch.gob.cl answers to one freno and one cooldown — the informe
	 * (IspReportRepository) and the ISP alert feeds alike — so the first
	 * timeout of a pass holds them all instead of each paying its own 15 s.
	 * One home, because the key is only shared while both sides spell it
	 * the same.
	 */
	public const ISP_HOST = 'isp';

	/** @var array<string, AlertRepository> */
	private array $built = [];

	public function __construct(
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private AlertMapper $alerts,
		private ICacheFactory $cacheFactory,
		private IJobList $jobs,
		private PageParser $pages,
		private IAppConfig $config,
		private CertificateBundle $bundle,
		private FeedParser $feeds,
		private PahoParser $paho,
	) {
	}

	public function repository(string $id): AlertRepository {
		return $this->built[$id] ??= new AlertRepository(
			$this->fuente($id),
			$this->clients,
			$this->time,
			$this->logger,
			$this->alerts,
			$this->cacheFactory,
			$this->jobs,
			$this->config,
		);
	}

	private function fuente(string $id): Fuente {
		return match ($id) {
			/*
			 * degreyd.minsal.cl's in-force page — a fixed page address, and
			 * deliberately neither of the two alternatives. The `/wp-json/`
			 * route is closed across MINSAL's estate and the query form that
			 * still answers here is the same API reached by a spelling they
			 * missed — a fuente should not rest on somebody's oversight. The RSS
			 * feed was measured and rejected for a plainer reason: it carries the
			 * ten most recent posts, the alerts post already sits behind three
			 * news items, and a fuente that empties because its publisher had a
			 * busy news week is a fuente that fails for no reason a reader could
			 * act on.
			 *
			 * The host is the fuente's own id: the key its brake and cooldown
			 * held while the grain was one fuente, kept (ADR-0017).
			 */
			'alerts' => new Fuente(
				id: $id,
				url: 'https://degreyd.minsal.cl/alertas-sanitarias-y-epidemiologicas-vigentes-en-chile/',
				host: 'alerts',
				publisher: 'MINSAL',
				name: 'MINSAL alerts',
				subject: 'MINSAL alerts page',
				parser: $this->pages,
			),
			/*
			 * epi.minsal.cl's in-force page, read by the same PageParser as
			 * degreyd's — the address is the only difference (its international
			 * table's repeated "Descargar Documento" is what rowTitles() is for).
			 * Not a copy of degreyd's list: 77 alerts against degreyd's 13, filed
			 * where the Departamento de Epidemiología files them. Its own host,
			 * because it is one: Cloudflare in front of it answers 403 to a
			 * datacentre and 200 to a home connection, so its brake must never
			 * hold degreyd's reads, nor degreyd's outage hold its.
			 */
			'epi_alerts' => new Fuente(
				id: $id,
				url: 'https://epi.minsal.cl/alertas-epidemiologicas-vigentes/',
				host: 'epi',
				publisher: 'MINSAL epi',
				name: 'MINSAL epi alerts',
				subject: 'MINSAL epi alerts page',
				parser: $this->pages,
			),
			/*
			 * The ISP's alert categories, one WordPress category feed each —
			 * streams: a feed lists its ten latest posts, so what fell off it
			 * is kept, and pages 2 and 3 are walked once for the history the
			 * first read could not see (D7). One host for all three and the
			 * informe (ISP_HOST): the first timeout of a pass holds the rest.
			 * The bundle because www.ispch.gob.cl serves only its leaf.
			 *
			 * The same post filed under two categories is one row in each,
			 * where the ISP filed it — ANAMED's ten share four with
			 * Farmacovigilancia and two with Cosméticos (the owner's captures,
			 * 2026-09-27).
			 */
			'isp_anamed' => new Fuente(
				id: $id,
				url: 'https://www.ispch.gob.cl/categorias-alertas/anamed/feed/',
				host: self::ISP_HOST,
				publisher: 'ISP',
				name: 'ISP ANAMED alerts',
				subject: 'ISP ANAMED alerts feed',
				parser: $this->feeds,
				backfill: [
					'https://www.ispch.gob.cl/categorias-alertas/anamed/feed/?paged=2',
					'https://www.ispch.gob.cl/categorias-alertas/anamed/feed/?paged=3',
				],
				bundle: $this->bundle,
			),
			'isp_pharmacovigilance' => new Fuente(
				id: $id,
				url: 'https://www.ispch.gob.cl/categorias-alertas/farmacovigilancia/feed/',
				host: self::ISP_HOST,
				publisher: 'ISP',
				name: 'ISP pharmacovigilance alerts',
				subject: 'ISP pharmacovigilance alerts feed',
				parser: $this->feeds,
				backfill: [
					'https://www.ispch.gob.cl/categorias-alertas/farmacovigilancia/feed/?paged=2',
					'https://www.ispch.gob.cl/categorias-alertas/farmacovigilancia/feed/?paged=3',
				],
				bundle: $this->bundle,
			),
			'isp_cosmetics' => new Fuente(
				id: $id,
				url: 'https://www.ispch.gob.cl/categorias-alertas/vigilancia-de-cosmeticos/feed/',
				host: self::ISP_HOST,
				publisher: 'ISP',
				name: 'ISP cosmetics alerts',
				subject: 'ISP cosmetics alerts feed',
				parser: $this->feeds,
				backfill: [
					'https://www.ispch.gob.cl/categorias-alertas/vigilancia-de-cosmeticos/feed/?paged=2',
					'https://www.ispch.gob.cl/categorias-alertas/vigilancia-de-cosmeticos/feed/?paged=3',
				],
				bundle: $this->bundle,
			),
			/*
			 * OPS/OMS's epidemiological alerts and updates — a stream, like the
			 * ISP's feeds: the list shows its latest eighteen documents, so
			 * what falls off it is kept, and pages 1 and 2 (Drupal counts from
			 * 0) are walked once for the history (D7). Its own host, and no
			 * bundle: www.paho.org serves a chain the instance's own anchors
			 * verify (measured from this VPS, 2026-09-28).
			 *
			 * Past its last page Drupal answers an empty 200, not WordPress's
			 * 404, which page() reads as unreadable rather than exhausted —
			 * moot here: the list ran to 38 pages on 2026-09-28, and a stream's
			 * history only grows.
			 */
			'paho_alerts' => new Fuente(
				id: $id,
				url: 'https://www.paho.org/es/alertas-actualizaciones-epidemiologicas',
				host: 'paho',
				publisher: 'PAHO',
				name: 'PAHO alerts',
				subject: 'PAHO alerts page',
				parser: $this->paho,
				backfill: [
					'https://www.paho.org/es/alertas-actualizaciones-epidemiologicas?page=1',
					'https://www.paho.org/es/alertas-actualizaciones-epidemiologicas?page=2',
				],
			),
		};
	}
}
