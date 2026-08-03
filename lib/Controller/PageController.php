<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\IspRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
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
		private MinsalRepository $repository,
		private IspRepository $isp,
		private IInitialState $initialState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// IInitialState, not a fetch on mount: the server already holds both feeds
		// in Redis, so a round trip to ourselves would buy a spinner and nothing
		// else. The OCS routes exist for the consumers that are not this page.
		$this->initialState->provideInitialState('alerts', $this->repository->get(MinsalRepository::ALERTS));
		$this->initialState->provideInitialState('reports', $this->repository->get(MinsalRepository::IRAG));
		$this->initialState->provideInitialState('ispAlerts', $this->isp->get());
		$this->initialState->provideInitialState('tablero', self::TABLERO_URL);

		// The bundle name carries the app id — vite-config prefixes it.
		Util::addScript(Application::APP_ID, 'epidemiologia-main');
		Util::addStyle(Application::APP_ID, 'epidemiologia-main');

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
