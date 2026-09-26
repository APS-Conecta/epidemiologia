<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\BackgroundJob\RevalidateJob;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The stale serve's background half (L1-01): the job is thin by design —
 * it hands the fuente's id to the catalogue's revalidate() door and
 * nothing else — so what this suite pins is the DOOR it rides and the two
 * doors it must not: get() (which would serve the stale copy again and
 * dispatch a second job — the loop the marker exists to prevent) and
 * probe() (which asks through the freno and would re-ask a host that said
 * no — #218beba, the same reason the refresher rides the reader's rule).
 *
 * No Doubles trait here: nothing this suite builds reads config, cache or
 * l10n — the Sources double is built directly, the way RefreshJobTest
 * builds its own.
 */
class RevalidateJobTest extends TestCase {
	/** @var list<string> every Sources::revalidate() ask, in order */
	private array $revalidated = [];

	public function testTheJobRevalidatesTheFuenteItWasDispatchedFor(): void {
		$this->runJob(['source' => Sources::ALERTS]);

		self::assertSame([Sources::ALERTS], $this->revalidated);
	}

	public function testTheJobAnswersForEveryFuenteInTheCatalogue(): void {
		foreach (Sources::ALL as $id) {
			$this->runJob(['source' => $id]);
		}

		self::assertSame(Sources::ALL, $this->revalidated, 'one dispatch per fuente id, through the catalogue');
	}

	private function runJob(array $argument): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	private function job(): RevalidateJob {
		$sources = $this->createMock(Sources::class);
		$sources->method('revalidate')->willReturnCallback(function (string $id): void {
			$this->revalidated[] = $id;
		});
		$sources->method('get')->willThrowException(
			new RuntimeException('the revalidator must ride revalidate() — get() would serve the stale copy again and dispatch a second job'),
		);
		$sources->method('probe')->willThrowException(
			new RuntimeException('the revalidator must not ask through the freno (#218beba) — that door is the detector\'s'),
		);

		return new RevalidateJob($this->createMock(ITimeFactory::class), $sources);
	}
}
