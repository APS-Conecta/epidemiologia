<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCA\Epidemiologia\Db\AlertMapper;
use OCA\Epidemiologia\Service\Parser\PageParser;
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
		};
	}
}
