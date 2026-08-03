<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\Application;
use OCA\Epidemiologia\Service\Sources;
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
 *     GET /ocs/v2.php/apps/epidemiologia/api/v1/sources
 *     { "ocs": { "meta": {…}, "data": {
 *         "alerts":          { "updated_at": …, "degraded": false, "items": [ … ] },
 *         "reports":         { … },
 *         "sanitary_alerts": { … } } } }
 *
 * ONE route keyed by source, not a route per source — see ADR-0006. Nextcloud
 * serves the one kind of consumer this contract exists for exactly this way:
 * `dashboard.rst` returns `ocs.data` keyed by widget id, and `cloud/capabilities`
 * keyed by app. Resource-per-endpoint, as groupfolders does it, is for mutable
 * things with ids and a CRUD surface, which this app has none of.
 *
 * No #[CORS] yet: it needs a concrete origin and the dashboard does not have one.
 * Adding it blind would widen the surface for nobody.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/**
	 * Generous on purpose. The route is served from a distributed cache with an hour
	 * of freshness, so the hot path is a handful of Redis reads; the only expensive
	 * path is the miss, which makes outbound calls to foreign government servers.
	 * This is a ceiling against a runaway consumer, not a quota.
	 *
	 * One route also means one bucket. Under the old shape a consumer fetching all
	 * three sources spent three of its own allowance to assemble one page.
	 */
	private const LIMIT = 120;
	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private Sources $sources,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Everything this app publishes, keyed by source
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, array{updated_at: ?string, fetched_at: ?int, degraded: bool, items: list<array<string, string>>}>, array{}>
	 *
	 * 200: Each source with its items, its freshness and its own degraded flag
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sources')]
	public function sources(): DataResponse {
		return new DataResponse($this->sources->all());
	}
}
