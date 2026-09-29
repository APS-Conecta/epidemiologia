<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Controller;

use OCA\Epidemiologia\AppInfo\AppId;
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
 *         "alerts":  { "fetched_at": …, "degraded": false, "items": [ … ] },
 *         "reports": { … } } } }
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
	 * One route also means one bucket. Under the old shape a consumer fetching
	 * every source spent one bucket per source to assemble one page.
	 */
	private const LIMIT = 120;
	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private Sources $sources,
	) {
		parent::__construct(AppId::APP_ID, $request);
	}

	/**
	 * Everything this app publishes, keyed by source — or one source, whole
	 *
	 * `total` counts what the answer carries and `has_more` says whether anything
	 * was left out: every answer here is whole, so the count is the fuente's own and
	 * `has_more` is false.
	 *
	 * @param ?string $source One of Sources::ALL. Omit for every source, as before.
	 * @param ?string $category Retired with SANITARY_ALERTS (ADR-0016): no fuente can be narrowed, so any value is a 400
	 * @param ?int $olderThan Retired with the narrowing: any value is a 400
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array<string, array{fetched_at: ?int, degraded: bool, items: list<array{title: string, url: string, year: string, month: string, se?: string, date?: string}>, total?: int, has_more?: bool}>, array{}>
	 *
	 * 200: Each source asked for, with its items, its freshness and its own degraded flag — item keys are the per-source union (reports carries `se`; the ISP alert feeds carry `date`, the publisher's day; the MINSAL pages carry neither), per SourcesTest's name-every-key doctrine
	 * 400: Array-shaped garbage in a query parameter (`?source[]=x`), or a narrowing parameter (retired with SANITARY_ALERTS — refusing it loudly is how a still-paging consumer learns that)
	 * 404: No such source
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sources')]
	public function sources(
		$source = null,
		$category = null,
		$olderThan = null,
	): DataResponse {
		/*
		 * Array garbage at the door (L3-05): `?source[]=x` arrives as an array,
		 * and the typed parameter this method used to declare fatal-ed into an
		 * OCS 500 before any guard below could run. Untyped on purpose and
		 * refused here — 400, the same answer the narrowing gets, because a
		 * request that cannot even name its parameter is the loudest version
		 * of the confident-empty defect this route exists to refuse. Same
		 * class as territorio's L1-12 and farmacia's L2-03.
		 */
		if (is_array($source) || is_array($category) || is_array($olderThan)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		/*
		 * An OMITTED parameter is null; an EMPTY one is ''. A consumer building
		 * `&source=${selected}` with nothing selected sent the empty string, and `''`
		 * matched no source id at all — 200 with an empty list, the answer this route
		 * exists to refuse. Blank means "not asked".
		 */
		$source = ($source ?? '') === '' ? null : $source;
		$category = ($category ?? '') === '' ? null : $category;

		/*
		 * The narrowing retired with SANITARY_ALERTS (ADR-0016): no fuente can be
		 * narrowed any more, so a narrowing parameter is refused rather than ignored,
		 * named source or none. A consumer still sending `?category` or `?olderThan`
		 * that got 200 with the whole series would read the retirement as data — the
		 * confident-empty defect's quiet sibling, and the same reason the old guard
		 * answered 400 to a request that made no sense.
		 */
		if ($category !== null || $olderThan !== null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		/*
		 * No parameters is the route ADR-0006 describes, unchanged and byte-identical:
		 * every source, whole.
		 */
		if ($source === null) {
			return new DataResponse($this->sources->all());
		}

		// An unknown id would reach Sources::get()'s deliberately default-less match and
		// throw UnhandledMatchError — a 500 for a misspelled query string. Answering 404
		// keeps that loudness where it belongs (an id added to ALL and nowhere else) and
		// off the path a URL can reach.
		if (!in_array($source, Sources::ALL, true)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([$source => $this->sources->get($source)]);
	}
}
