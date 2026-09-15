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
 * whole method was `return new DataResponse($this->sources->all())`. The narrowing
 * added three decisions to it, and the same ADR's *rule* — "a class in `lib/` gets a
 * unit test when it contains a decision — a branch, a loop over a list, a fallback"
 * — has covered this class ever since. Between the rule and the list of classes it was
 * applied to, the rule is the load-bearing half; this file follows the rule and the
 * Consequences line is stale.
 *
 * What is deliberately NOT here, because it is Nextcloud's behaviour and not this app's:
 * the 412 without `OCS-APIRequest`, and the `ocs.data` envelope. `smoke.sh` asserts both
 * against a real instance, which is the only place either is real.
 *
 * `Sources` is mocked rather than built: what is under test is the translation from what
 * the catalogue answers into an HTTP status, and `SourcesTest` already owns the
 * narrowing semantics underneath. A real `Sources` here would re-test that and hide this.
 */
class ApiControllerTest extends TestCase {
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
		$whole = ['alerts' => ['items' => [], 'fetched_at' => null, 'degraded' => false]];
		$sources = $this->createMock(Sources::class);
		$sources->method('all')->willReturn($whole);
		// If the blank ever reached the narrowing path again, this would be the answer.
		$sources->method('page')->willReturn(null);

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
	 * 400 and not 404: the source exists, the request does not make sense against it.
	 *
	 * `Sources::page()` returns null for a narrowing a fuente cannot express — asking
	 * MINSAL for a category, or for a cursor. Answering 200 with a filtered-looking
	 * empty payload is what this replaced, and it is the confident-empty this app
	 * refuses everywhere else.
	 */
	public function testANarrowingTheFuenteCannotExpressAnswers400(): void {
		$sources = $this->createMock(Sources::class);
		$sources->method('page')->willReturn(null);

		$response = $this->controller($sources)->sources(source: Sources::ALERTS, category: 'anamed');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	/**
	 * A named source comes back keyed by that source, so a narrowed answer and a whole
	 * one are the same shape — which is the property every consumer reads.
	 */
	public function testANamedSourceIsReturnedUnderItsOwnKeyAndAlone(): void {
		$page = ['items' => [], 'fetched_at' => 1, 'degraded' => false, 'total' => 0, 'has_more' => false];
		$sources = $this->createMock(Sources::class);
		$sources->method('page')->willReturn($page);

		$response = $this->controller($sources)->sources(source: Sources::SANITARY_ALERTS);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([Sources::SANITARY_ALERTS => $page], $response->getData());
	}

	/**
	 * Every id in the catalogue is answerable, walked from `Sources::ALL` rather than
	 * named — the move `SourcesTest` makes for the same reason. A fourth fuente added to
	 * the list and rejected by the controller's `in_array` guard fails here.
	 */
	public function testEveryIdInTheCatalogueIsAccepted(): void {
		$page = ['items' => [], 'fetched_at' => null, 'degraded' => false, 'total' => 0, 'has_more' => false];
		$sources = $this->createMock(Sources::class);
		$sources->method('page')->willReturn($page);
		$controller = $this->controller($sources);

		foreach (Sources::ALL as $id) {
			self::assertSame(Http::STATUS_OK, $controller->sources(source: $id)->getStatus(), $id);
		}
	}
}
