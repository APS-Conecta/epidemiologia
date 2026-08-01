<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The contract other apps read — and, once it has a home, the front webpage.
 *
 * OCSController rather than a plain Controller because the manual calls it the
 * state-of-the-art surface and, more concretely, because a browser on another
 * origin cannot be served any other way: enabling CORS on a plain Controller is
 * "generally and highly discouraged", since the CSRF check then fails unless
 * security is disabled. The planned dashboard is exactly that case.
 *
 * Consumers send `OCS-APIRequest: true` and get the OCS envelope back:
 *
 *     GET /ocs/v2.php/apps/epidemiologia/api/v1/alerts
 *     { "ocs": { "meta": {…}, "data": { "updated_at": …, "degraded": false,
 *                                       "items": [ … ] } } }
 *
 * No #[CORS] yet: it needs a concrete origin and the dashboard does not have one.
 * Adding it blind would widen the surface for nobody.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/**
	 * Generous on purpose. Both routes are served from a distributed cache with an
	 * hour of freshness, so the hot path is one Redis read; the only expensive path
	 * is the miss, which makes a single outbound call to a foreign government
	 * server. This is a ceiling against a runaway consumer, not a quota.
	 */
	private const LIMIT = 120;
	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private MinsalRepository $repository,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alertas epidemiológicas vigentes del MINSAL
	 *
	 * @return DataResponse<Http::STATUS_OK, array{updated_at: ?string, fetched_at: ?int, degraded: bool, items: list<array<string, string>>}, array{}>
	 *
	 * 200: Las alertas vigentes, de más reciente a más antigua
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/alerts')]
	public function alerts(): DataResponse {
		return new DataResponse($this->repository->get(MinsalRepository::ALERTS));
	}

	/**
	 * Informes semanales de vigilancia ETI e IRAG
	 *
	 * @return DataResponse<Http::STATUS_OK, array{updated_at: ?string, fetched_at: ?int, degraded: bool, items: list<array<string, string>>}, array{}>
	 *
	 * 200: Los informes del año en curso, con la semana epidemiológica en `se`
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reports')]
	public function reports(): DataResponse {
		return new DataResponse($this->repository->get(MinsalRepository::IRAG));
	}
}
