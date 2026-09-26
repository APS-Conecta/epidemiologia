<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\MinsalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Accent folding exists twice — `MinsalRepository::fold()` for OCS and global search,
 * `fold()` in `src/pure.js` (re-exported through `src/state.js` until L7-01)
 * for the in-browser filter — because a clinician types
 * "sarampion" on both sides of the wire. Two languages, so the duplication cannot be
 * removed, only kept honest.
 *
 * Until this test the two agreed by coincidence. Nothing read both, so a change to
 * either could make searching "sarampion" and filtering "sarampion" return different
 * sets with no suite anywhere going red — the same shape of defect the shared cache
 * double closed in 0.8.0.
 *
 * The table is `fold-cases.json`, read by this test and by `fold.test.js`, so a single
 * edit to it fails a test in EACH suite. That is the property. A case list copied into
 * both files would drift exactly the way the implementations already could.
 *
 * The implementations are not identical and do not need to be: this one strips every
 * non-spacing mark (`\p{Mn}`), the JS one strips the Combining Diacritical Marks block
 * (`U+0300–U+036F`). Nothing either side receives decomposes to a mark outside that
 * block, so the difference is unreachable from Spanish. Add a case that reaches it and
 * this is where the disagreement surfaces.
 */
class FoldConformanceTest extends TestCase {
	public function testFoldsEveryCaseTheSameWayTheBrowserDoes(): void {
		$json = (string)file_get_contents(__DIR__ . '/fold-cases.json');
		$cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

		self::assertNotEmpty($cases, 'the shared table is empty');

		foreach ($cases as [$input, $expected]) {
			// The input in the message, because a bare "failed asserting two strings are
			// equal" over combining marks is unreadable — one of these cases IS a
			// combining mark, and it renders as nothing at all.
			self::assertSame($expected, MinsalRepository::fold($input), json_encode($input));
		}
	}
}
