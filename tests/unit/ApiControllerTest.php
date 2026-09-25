<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Controller\ApiController;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The three status codes this controller decides, and nothing else.
 *
 * [ADR-0007](../../docs/adr/0007-tests-follow-branching-not-layering.md) lists
 * `ApiController` among the classes left to `scripts/smoke.sh` because they only
 * delegate. That was true when it was written: the route took no parameters and the
 * whole method was `return new DataResponse($this->sources->all())`. The parameters
 * that came later added decisions to it, and the same ADR's *rule* — "a class in
 * `lib/` gets a unit test when it contains a decision — a branch, a loop over a
 * list, a fallback" — has covered this class ever since. Between the rule and the
 * list of classes it was applied to, the rule is the load-bearing half; this file
 * follows the rule and the Consequences line is stale.
 *
 * What is deliberately NOT here, because it is Nextcloud's behaviour and not this app's:
 * the 412 without `OCS-APIRequest`, and the `ocs.data` envelope. `smoke.sh` asserts both
 * against a real instance, which is the only place either is real.
 *
 * `Sources` is mocked rather than built: what is under test is the translation from what
 * the catalogue answers into an HTTP status, and `SourcesTest` already owns the
 * catalogue's own semantics underneath. A real `Sources` here would re-test that and
 * hide this.
 */
class ApiControllerTest extends TestCase {
	private const WHOLE = ['items' => [], 'fetched_at' => null, 'degraded' => false, 'total' => 0, 'has_more' => false];

	private function controller(?Sources $sources = null): ApiController {
		return new ApiController($this->createMock(IRequest::class), $sources ?? $this->createMock(Sources::class));
	}

	/**
	 * The one that shipped as a bug.
	 *
	 * A consumer building `&source=${selected}` with nothing selected sends the EMPTY
	 * string, not no parameter at all. That arrived as `''`, matched no source id, and
	 * came back 404 — the app's own front end asking for everything and being told the
	 * source does not exist. Blank means "not asked", and nothing pinned that.
	 */
	public function testABlankSourceIsTreatedAsNoneAsked(): void {
		$whole = ['alerts' => self::WHOLE, 'reports' => self::WHOLE];
		$sources = $this->createMock(Sources::class);
		$sources->method('all')->willReturn($whole);

		$response = $this->controller($sources)->sources(source: '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($whole, $response->getData(), 'a blank source must mean the whole payload');
	}

	/**
	 * A typo in a URL is the caller's mistake, not a crash.
	 *
	 * Without the guard the id reaches `Sources::get()`'s deliberately default-less
	 * `match` and throws `UnhandledMatchError` — a 500 for a misspelled query string.
	 * smoke.sh checks only that the response does not contain "alerts", which a 500
	 * error page satisfies perfectly, so the status itself has never been asserted.
	 */
	public function testAnUnknownSourceIdAnswers404(): void {
		$response = $this->controller()->sources(source: 'no-such-source');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	/**
	 * 400 and not 404 or 200: the narrowing retired with SANITARY_ALERTS
	 * (ADR-0016), and the route refuses what it can no longer honour rather than
	 * ignoring the parameter — a consumer still paging that got 200 with the whole
	 * series would read the retirement as data, the confident-empty defect's quiet
	 * sibling. The same 400 the old guard gave a request that made no sense, kept
	 * for the guard's own sake.
	 */
	public function testANarrowingParameterAnswers400NowThatNothingCanBeNarrowed(): void {
		$controller = $this->controller();

		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$controller->sources(source: Sources::ALERTS, category: 'anamed')->getStatus(),
		);
		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$controller->sources(source: Sources::ALERTS, olderThan: 1)->getStatus(),
		);
	}

	/**
	 * The guard stands before the whole-payload early return, on purpose: a
	 * paging-era consumer that sends `?olderThan` with no source named must not get
	 * the whole series back and read the retirement as data. The refusal is total.
	 */
	public function testANarrowingParameterWithoutASourceAnswers400Too(): void {
		$response = $this->controller()->sources(olderThan: 1);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	/**
	 * Blank means "not asked" — the same reading the source parameter gets. A
	 * consumer building `&category=${selected}` with nothing selected sends the
	 * empty string and means no narrowing, retired or otherwise.
	 */
	public function testABlankNarrowingParameterMeansNoneAsked(): void {
		$response = $this->controller()->sources(source: Sources::REPORTS, category: '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * A named source comes back keyed by that source and alone — the property every
	 * consumer reads, and the shape a whole answer and a named one have always shared.
	 */
	public function testANamedSourceIsReturnedUnderItsOwnKeyAndAlone(): void {
		$sources = $this->createMock(Sources::class);
		$sources->method('get')->willReturn(self::WHOLE);

		$response = $this->controller($sources)->sources(source: Sources::REPORTS);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([Sources::REPORTS => self::WHOLE], $response->getData());
	}

	/**
	 * Array garbage at the door (L3-05): `?source[]=x` used to fatal the
	 * typed parameter into an OCS 500 before any guard could run — the
	 * same class as territorio's L1-12 and farmacia's L2-03. The door now
	 * refuses it the way it refuses a narrowing: 400, the loudest version
	 * of the confident-empty defect this route exists to refuse.
	 */
	public function testArrayGarbageInAQueryParameterAnswers400NotA500(): void {
		$controller = $this->controller();

		$reply = $controller->sources(['x'], null, null);
		self::assertSame(Http::STATUS_BAD_REQUEST, $reply->getStatus());

		$reply = $controller->sources(null, null, ['1']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $reply->getStatus());
	}

	/**
	 * Every id in the catalogue is answerable, walked from `Sources::ALL` rather than
	 * named — the move `SourcesTest` makes for the same reason. A third fuente added to
	 * the list and rejected by the controller's `in_array` guard fails here.
	 */
	public function testEveryIdInTheCatalogueIsAccepted(): void {
		$sources = $this->createMock(Sources::class);
		$sources->method('get')->willReturn(self::WHOLE);
		$controller = $this->controller($sources);

		foreach (Sources::ALL as $id) {
			self::assertSame(Http::STATUS_OK, $controller->sources(source: $id)->getStatus(), $id);
		}
	}
}
