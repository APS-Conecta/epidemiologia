<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\AppId;
use OCA\Epidemiologia\Db\Report;
use OCA\Epidemiologia\Db\ReportMapper;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The informe's own address: the href a kept row carries on the wire.
 *
 * Three answers, one per thing that can be true about a week:
 *
 * - the row exists and its document is kept and vouched for — the bytes,
 *   inline, so the pane's target=_blank opens the report the way the
 *   publisher's own link always did;
 * - the row exists but the kept copy cannot be served — not kept yet, gone
 *   from app data, or (the reason the hash check exists) a partial file that
 *   would otherwise be served forever at a correct name (513958a) — and the
 *   publisher's own PAGE for the week answers instead: the stable
 *   /documento/semana-{se}-{year}/ address that opens the current informe,
 *   so a fallback never points at a document address the publisher may
 *   already have reissued. A redirect, never an error page: the clinician
 *   asked for the informe, and this route's one job is that the ask
 *   succeeds;
 * - no such week — 404, for a link nothing mints.
 *
 * The redirect target is derived from the week's own (se, year) — https by
 * construction, never feed-supplied — so this is a redirect to the
 * publisher, never an open door. The route is plain, not OCS (ADR-0006's one route stands),
 * authenticated like the app itself, and out of the generated spec on
 * purpose: it is the pane's link, not a published contract.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class InformeController extends Controller {
	public function __construct(
		IRequest $request,
		private ReportMapper $reports,
		private IAppDataFactory $appData,
		private LoggerInterface $logger,
	) {
		parent::__construct(AppId::APP_ID, $request);
	}

	/**
	 * One informe's document, from the install's own kept copy.
	 *
	 * @param string $year the semana's year, from pubDate in the publisher's zone
	 * @param string $semana the semana epidemiológica, canonical digits
	 *
	 * 200: the kept document, served inline as application/pdf
	 * 303: the publisher's own page for the week, when the kept copy cannot be vouched for
	 * 404: no such informe
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $year, string $semana): DataDownloadResponse|RedirectResponse|NotFoundResponse {
		$report = $this->reports->find($year, $semana);

		if ($report === null) {
			return new NotFoundResponse();
		}

		if ($report->getPath() === null) {
			// Not kept (yet): the publisher's own page is the honest href,
			// and it is the one the wire carried before the document was kept.
			return new RedirectResponse($this->publisherPage($report));
		}

		try {
			$bytes = $this->appData
				->get(AppId::APP_ID)
				->getFolder(IspReportRepository::FOLDER)
				->getFile($report->getPath())
				->getContent();
		} catch (NotFoundException) {
			// Kept once, gone now (an app data wipe the table survived). The
			// publisher's page is the fallback that always answers.
			return new RedirectResponse($this->publisherPage($report));
		}

		/*
		 * The row's hash vouches for these bytes, and it is checked rather
		 * than trusted: a partial or replaced file under a correct name is
		 * the exact defect 513958a records, and serving it would present a
		 * corrupted informe as the week's. Mismatch is the publisher's turn
		 * then — and a warning, because a kept copy that rots is a fact
		 * only an admin can act on.
		 */
		if (hash('sha256', $bytes) !== $report->getHash()) {
			$this->logger->warning(
				'The kept document for semana {se} of {year} does not match its hash; serving the publisher\'s page instead',
				['year' => $year, 'se' => $semana],
			);

			return new RedirectResponse($this->publisherPage($report));
		}

		$name = sprintf('informe-%s-%s.pdf', $year, $semana);

		// Inline, not attachment: the pane opens this in a new tab, and the
		// report is something to read, not something to file. addHeader
		// replaces by name, which is what makes the override stick.
		return (new DataDownloadResponse($bytes, $name, 'application/pdf'))
			->addHeader('Content-Disposition', 'inline; filename="' . $name . '"');
	}

	/**
	 * The publisher's stable page for this week — /documento/semana-{se}-{year}/,
	 * the address that opens the current informe. Derived from the row's own
	 * identity, https by construction: a fallback must never point at a
	 * version-suffixed document address the publisher may already have
	 * reissued.
	 */
	private function publisherPage(Report $report): string {
		return sprintf(IspReportRepository::PAGE . 'semana-%s-%s/', $report->getSe(), $report->getYear());
	}
}

