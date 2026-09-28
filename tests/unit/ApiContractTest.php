<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\AlertRepository;
use OCA\Epidemiologia\Service\Sources;
use OCA\Epidemiologia\Service\IspReportRepository;
use PHPUnit\Framework\TestCase;

/**
 * The published-surface fence (org ADR 0005: REST is the one published
 * surface; farmacia's ApiContractTest is the pattern, adapted to this app's
 * one-route contract per ADR-0006). Three families:
 *
 * 1. Route inventory — openapi.json carries exactly the one OCS route.
 *    A second route appearing without the spec moving breaks here.
 * 2. The spec's 200 names the keys the route serves. 'items' collides with
 *    the JSON-schema keyword of the same name, so the spec side asserts the
 *    four unambiguous source keys plus the item keys; the full source-level
 *    set is carried by family 3, read from the code.
 * 3. Sources::get() serves exactly the five source keys — the code-side
 *    half, so a rename breaks here loudly before a consumer's integration
 *    does it quietly in production.
 */
final class ApiContractTest extends TestCase {
	use Doubles;

	private const RUTA = '/ocs/v2.php/apps/epidemiologia/api/v1/sources';

	/** What the spec can name unambiguously: source keys minus the schema-keyword collision, plus item keys. */
	private const NOMBRES = ['fetched_at', 'degraded', 'total', 'has_more', 'title', 'url', 'year', 'month', 'se'];

	/** What Sources::get() serves, read from the code. */
	private const SIRVIDO = ['items', 'fetched_at', 'degraded', 'total', 'has_more'];

	public function testThePublishedRouteIsExactlyTheOneSourceRead(): void {
		$spec = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/openapi.json'),
			true,
		);
		self::assertIsArray($spec, 'openapi.json is missing or unparseable');

		self::assertSame(
			[self::RUTA],
			array_keys($spec['paths']),
			'the published surface moved — a route was added or removed without openapi.json (org ADR 0005, app ADR-0006)',
		);
	}

	public function testTheSpecNamesExactlyTheServedKeys(): void {
		$spec = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/openapi.json'),
			true,
		);
		self::assertIsArray($spec, 'openapi.json is missing or unparseable');

		$schema = $spec['paths'][self::RUTA]['get']['responses']['200'] ?? null;
		self::assertNotNull($schema, 'the sources GET 200 response is gone from the spec');

		$json = json_encode($schema['content']['application/json']['schema']);
		self::assertIsString($json);
		preg_match_all('/"([a-zA-Z_]+)":\s*\{/', $json, $matches);
		$named = array_values(array_unique($matches[1]));

		self::assertSame(
			[],
			array_values(array_diff(self::NOMBRES, $named)),
			'the spec no longer names these served keys',
		);
		self::assertSame(
			[],
			array_values(array_diff($named, self::NOMBRES, ['type', 'properties', 'required', 'items', 'description', 'ocs', 'meta', 'data', 'additionalProperties', 'nullable'])),
			'the spec names keys the route does not serve',
		);
	}

	public function testSourcesServesExactlyTheFiveSourceKeys(): void {
		$payload = ['items' => [], 'fetched_at' => null, 'degraded' => false];
		$minsal = $this->createMock(AlertRepository::class);
		$minsal->method('get')->willReturn($payload);
		$informes = $this->createMock(IspReportRepository::class);
		$informes->method('get')->willReturn($payload);

		$served = (new Sources($this->alertRepositories($minsal), $informes))->get(Sources::ALERTS);

		self::assertSame(
			self::SIRVIDO,
			array_keys($served),
			'the served source shape moved — update the @return docblock and regenerate the spec',
		);
	}
}
