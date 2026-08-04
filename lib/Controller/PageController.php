<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller {
	/** Power BI's "Publish to web" host — the tablero. */
	private const TABLERO_HOST = 'https://app.powerbi.com';

	/** MINSAL's ETI/IRAG report, as Microsoft's own publish-to-web snippet embeds it. */
	private const TABLERO_URL = self::TABLERO_HOST . '/view?r=eyJrIjoiOGE3ZjM5YWMtZGFlNS00ZjQ3'
		. 'LTg3MjEtYjkzMDcwN2Q5OWQ1IiwidCI6Ijc0NDRkNTdjLTA0YzgtNDJkZS1hMDgxLWRkODk5YWYyOTIyZSIsImMiOjR9';

	public function __construct(
		IRequest $request,
		private Sources $sources,
		private IInitialState $initialState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// IInitialState, not a fetch on mount: the server already holds every feed
		// in Redis, so a round trip to ourselves would buy a spinner and nothing
		// else. The OCS route exists for the consumers that are not this page.
		//
		// One key, not one per source. Each key is a contract between this file and
		// state.js that has to be spelled identically in two languages, so three of
		// them were three chances to typo a source into silence.
		$this->initialState->provideInitialState('sources', $this->sources->all());

		// Not a source: a constant URL with nothing to fetch, parse, cache or
		// degrade. It stays out of Sources for the same reason it stays out of the
		// OCS payload — see ADR-0006.
		$this->initialState->provideInitialState('tablero', self::TABLERO_URL);

		// The bundle name carries the app id — vite-config prefixes it.
		Util::addScript(Application::APP_ID, 'epidemiologia-main');
		// 'epidemiologia-style', not '-main': with cssCodeSplit off Vite emits one
		// stylesheet named after the library, not after the entry. See vite.config.js.
		Util::addStyle(Application::APP_ID, 'epidemiologia-style');

		$response = new TemplateResponse(Application::APP_ID, 'index');

		// Nextcloud blocks third-party frames by default. The tablero is a Power BI
		// report published to the web, for which Microsoft generates an iframe
		// snippet — embedding is the sanctioned use. Without this one line the
		// tablero tab renders blank, which looks exactly like MINSAL being down.
		// scripts/smoke.sh asserts this header for that reason.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain(self::TABLERO_HOST);
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
