<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The contract other APS Conecta apps read.
 *
 * Session-authenticated on purpose: the only consumers today run inside this
 * Nextcloud, so there is no public route, no CORS and no rate limit to maintain.
 * A consumer outside Nextcloud would need all three, and that is a decision to
 * take when one actually exists.
 *
 * CSRF is not required because these are read-only: CSRF protects state changes,
 * and demanding a token would stop a plain authenticated GET from working.
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private MinsalRepository $repository,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function alerts(): JSONResponse {
		return new JSONResponse($this->repository->get(MinsalRepository::ALERTS));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): JSONResponse {
		return new JSONResponse($this->repository->get(MinsalRepository::IRAG));
	}
}
