<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

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
 * Reads the persisted set and nothing on the network — see MinsalRepository::search()
 * for why a provider must never fetch.
 *
 * It asks the catalogue what is searchable rather than naming one repository:
 * that is the shape #37 fixed, when sixty ISP alerts were invisible here while
 * being rendered in the app next door, because this class named MinsalRepository
 * in its constructor instead of asking what the app publishes. The alertas
 * sanitarias have since retired (ADR-0016), so today the answer is MINSAL's
 * alertas alone — and the next searchable fuente joins this box with no edit
 * here.
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
				// The kind carries the institution, because a result has to stand
				// alone in a list mixed with files and contacts. With the alertas
				// sanitarias retired every hit is MINSAL's, and MINSAL publishes
				// only the upload year — the date is whichever the source has.
				$this->sources->kind($hit['source'], $this->l)
					. ' · ' . ($hit['item']['year'] ?? ''),
				$hit['item']['url'],
				'icon-external',
			),
			$matches,
		);

		return SearchResult::complete($this->getName(), $entries);
	}
}
