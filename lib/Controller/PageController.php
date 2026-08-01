<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

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
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'index', [
			'alerts' => $this->repository->get(MinsalRepository::ALERTS),
			'reports' => $this->repository->get(MinsalRepository::IRAG),
			'tablero' => self::TABLERO_URL,
		]);

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
