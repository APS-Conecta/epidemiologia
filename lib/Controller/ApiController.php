<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

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
	 * Everything this app publishes, keyed by source — or one source, narrowed
	 *
	 * @param ?string $source One of Sources::ALL. Omit for every source, as before.
	 * @param ?string $category A category slug of that source; omit for all of them
	 * @param ?int $olderThan Only items published strictly before this epoch
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array<string, array{updated_at: ?string, fetched_at: ?int, degraded: bool, items: list<array<string, string>>}>, array{}>
	 *
	 * 200: Each source asked for, with its items, its freshness and its own degraded flag
	 * 400: That source cannot be narrowed the way you asked
	 * 404: No such source
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sources')]
	public function sources(
		?string $source = null,
		?string $category = null,
		?int $olderThan = null,
	): DataResponse {
		/*
		 * No parameters is the route ADR-0006 describes, unchanged and byte-identical:
		 * every source, whole. The narrowing below is additive (ADR-0006 amendment), so
		 * an existing consumer keeps its exact meaning and response shape.
		 */
		if ($source === null) {
			return new DataResponse($this->sources->all());
		}

		// An unknown id would reach Sources::get()'s deliberately default-less match and
		// throw UnhandledMatchError — a 500 for what is a caller's typo. Answering 404
		// keeps that loudness where it belongs (an id added to ALL and nowhere else) and
		// off the path a URL can reach.
		if (!in_array($source, Sources::ALL, true)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// Null means the fuente cannot express the narrowing asked of it — MINSAL items
		// have no category and no instant. 400, not 404: the source exists, the request
		// does not make sense against it. Answering 200 with a filtered-looking payload
		// is what this replaced.
		$page = $this->sources->page($source, $category, $olderThan);
		if ($page === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse([$source => $page]);
	}
}
