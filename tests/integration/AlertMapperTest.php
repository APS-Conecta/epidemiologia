<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Integration;

use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OC\DB\Exceptions\DbalException;

/**
 * The in-force set behind the alertas pane — replaceSet()'s transaction is
 * the whole safety story (L3-06): a half-replaced set must never be visible,
 * and only a real database can roll one back.
 */
class AlertMapperTest extends IntegrationTestCase {
	private AlertMapper $alerts;

	protected function setUp(): void {
		parent::setUp();
		$this->alerts = new AlertMapper($this->db);
	}

	public function testAWithdrawnAlertVanishesWithTheSetThatNoLongerNamesIt(): void {
		$this->alerts->replaceSet([
			$this->alert('https://m/1', '2026-08'),
			$this->alert('https://m/2', '2026-08'),
		]);

		$this->alerts->replaceSet([$this->alert('https://m/3', '2026-07')]);

		self::assertSame(['https://m/3'], array_map(static fn (Alert $a) => $a->getUrl(), $this->alerts->readSet()),
			'upserts alone would leave the withdrawn alert in the table forever');
	}

	public function testAFailingReplaceLeavesTheLastGoodWholeSetUntouched(): void {
		$this->alerts->replaceSet([$this->alert('https://m/1', '2026-08')]);

		try {
			// A set containing its own key twice: the second insertRow dies on
			// the url PK mid-replacement — the transaction's failure mode.
			$this->alerts->replaceSet([
				$this->alert('https://m/2', '2026-08'),
				$this->alert('https://m/2', '2026-07'),
			]);
			self::fail('the duplicate url inside the set must throw');
		} catch (DbalException) {
			// the mapper re-throws after rolling back; that is the contract
		}

		self::assertSame(['https://m/1'], array_map(static fn (Alert $a) => $a->getUrl(), $this->alerts->readSet()),
			'rollback restored the last-good whole — a half-replaced set was never visible');
	}

	public function testReadSetOrdersNewestMonthFirst(): void {
		$this->alerts->replaceSet([
			$this->alert('https://m/1', '2026-06'),
			$this->alert('https://m/2', '2026-08'),
			$this->alert('https://m/3', '2026-07'),
		]);

		self::assertSame(['https://m/2', 'https://m/3', 'https://m/1'],
			array_map(static fn (Alert $a) => $a->getUrl(), $this->alerts->readSet()));
	}
}
