<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Search;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\IL10N;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Puts the alerts in Nextcloud's global search box, so staff find them without
 * knowing this app exists.
 *
 * Reads the cache and nothing else — see MinsalRepository::search() for why a
 * provider must never fetch.
 */
class AlertProvider implements IProvider {
	public function __construct(
		private MinsalRepository $repository,
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getId(): string {
		return Application::APP_ID . '-alerts';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Alertas epidemiológicas');
	}

	#[\Override]
	public function getOrder(string $route, array $routeParameters): int {
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 55;
	}

	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$matches = array_slice($this->repository->search($query->getTerm()), 0, $query->getLimit());

		$entries = array_map(
			fn (array $alert): SearchResultEntry => new SearchResultEntry(
				'',
				$alert['title'],
				$this->l->t('Alerta epidemiológica MINSAL · %s', [$alert['year']]),
				$alert['url'],
				'icon-external',
			),
			$matches,
		);

		return SearchResult::complete($this->getName(), $entries);
	}
}
