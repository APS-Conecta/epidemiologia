<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Search;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\Sources;
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
 *
 * It covers every searchable source rather than only MINSAL's. Sixty ISP alerts
 * were invisible here while being rendered in the app next door, because this
 * class named one repository in its constructor instead of asking what the app
 * publishes.
 */
class AlertProvider implements IProvider {
	public function __construct(
		private Sources $sources,
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
		$matches = array_slice($this->sources->search($query->getTerm()), 0, $query->getLimit());

		$entries = array_map(
			fn (array $hit): SearchResultEntry => new SearchResultEntry(
				'',
				$hit['item']['title'],
				// The kind carries the institution, because this list mixes the two
				// kinds of alert with each other and with files and contacts, and
				// CONTEXT.md is explicit that an alerta sanitaria and an alerta
				// vigente are never merged. The date is whichever the source has:
				// the ISP publishes a day, MINSAL only the upload year.
				$this->sources->kind($hit['source'], $this->l)
					. ' · ' . ($hit['item']['date'] ?? $hit['item']['year'] ?? ''),
				$hit['item']['url'],
				'icon-external',
			),
			$matches,
		);

		return SearchResult::complete($this->getName(), $entries);
	}
}
