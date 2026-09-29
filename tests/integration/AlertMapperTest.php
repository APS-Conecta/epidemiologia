<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Integration;

use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\AlertMapper;
use OC\DB\Exceptions\DbalException;

/**
 * The alert fuentes' sets — replaceSet()'s transaction is the whole safety
 * story (L3-06): a half-replaced set must never be visible, and only a real
 * database can roll one back. Since ADR-0017 the table is shared, so the scope
 * is the other half of it: one fuente's replacement must never touch another
 * fuente's rows — and a stream's upsert() must rewrite its own row in place.
 */
class AlertMapperTest extends IntegrationTestCase {
	private AlertMapper $alerts;

	protected function setUp(): void {
		parent::setUp();
		$this->alerts = new AlertMapper($this->db);
	}

	public function testAWithdrawnAlertVanishesWithTheSetThatNoLongerNamesIt(): void {
		$this->alerts->replaceSet('alerts', [
			$this->alert('https://m/1', '2026-08'),
			$this->alert('https://m/2', '2026-08'),
		]);

		$this->alerts->replaceSet('alerts', [$this->alert('https://m/3', '2026-07')]);

		self::assertSame(['https://m/3'], $this->urls('alerts'),
			'upserts alone would leave the withdrawn alert in the table forever');
	}

	public function testAFailingReplaceLeavesTheLastGoodWholeSetUntouched(): void {
		$this->alerts->replaceSet('alerts', [$this->alert('https://m/1', '2026-08')]);

		try {
			// A set containing its own key twice: the second insertRow dies on
			// the (fuente, url) PK mid-replacement — the transaction's failure mode.
			$this->alerts->replaceSet('alerts', [
				$this->alert('https://m/2', '2026-08'),
				$this->alert('https://m/2', '2026-07'),
			]);
			self::fail('the duplicate url inside the set must throw');
		} catch (DbalException) {
			// the mapper re-throws after rolling back; that is the contract
		}

		self::assertSame(['https://m/1'], $this->urls('alerts'),
			'rollback restored the last-good whole — a half-replaced set was never visible');
	}

	public function testReadSetOrdersNewestMonthFirst(): void {
		$this->alerts->replaceSet('alerts', [
			$this->alert('https://m/1', '2026-06'),
			$this->alert('https://m/2', '2026-08'),
			$this->alert('https://m/3', '2026-07'),
		]);

		self::assertSame(['https://m/2', 'https://m/3', 'https://m/1'], $this->urls('alerts'));
	}

	/** The collision ADR-0017 keys the table against: the old whole-table DELETE. */
	public function testReplacingOneFuentesSetLeavesEveryOtherFuentesRowsAlone(): void {
		$this->alerts->replaceSet('alerts', [$this->alert('https://m/1', '2026-08')]);
		$this->alerts->replaceSet('epi_alerts', [$this->alert('https://e/1', '2026-08')]);

		$this->alerts->replaceSet('epi_alerts', [$this->alert('https://e/2', '2026-09')]);

		self::assertSame(['https://m/1'], $this->urls('alerts'),
			'replacing epi\'s set must not empty degreyd\'s — the unscoped DELETE did exactly that');
		self::assertSame(['https://e/2'], $this->urls('epi_alerts'));
	}

	/** "Where the publisher filed it": one document in two sectors is two rows, not a collision. */
	public function testTheSameUrlIsOneRowPerFuente(): void {
		$this->alerts->replaceSet('alerts', [$this->alert('https://m/shared.pdf', '2026-08')]);
		$this->alerts->replaceSet('epi_alerts', [$this->alert('https://m/shared.pdf', '2026-08')]);

		self::assertSame(['https://m/shared.pdf'], $this->urls('alerts'));
		self::assertSame(['https://m/shared.pdf'], $this->urls('epi_alerts'),
			'the (fuente, url) key lets both sectors hold the same document');
	}

	public function testTheScopeIsStampedOnEveryRowWhateverTheEntityCarried(): void {
		$this->alerts->replaceSet('epi_alerts', [$this->alert('https://e/1', '2026-08', fuente: 'alerts')]);

		self::assertSame([], $this->urls('alerts'),
			'a row written under a fuente no replaceSet() of this one would delete is a row nothing ever withdraws');
		self::assertSame(['epi_alerts'],
			array_map(static fn (Alert $a): string => $a->getFuente(), $this->alerts->readSet('epi_alerts')));
	}

	public function testTheDayAndTheTipoRoundTripAndOrderInsideAMonth(): void {
		$this->alerts->replaceSet('paho_alerts', [
			$this->alert('https://p/1', '2026-08', day: '2026-08-07', tipo: 'alerta'),
			$this->alert('https://p/2', '2026-08', day: '2026-08-26', tipo: 'actualizacion'),
			$this->alert('https://p/3', '2026-07', day: '2026-07-09'),
		]);

		$rows = $this->alerts->readSet('paho_alerts');

		self::assertSame(['https://p/2', 'https://p/1', 'https://p/3'],
			array_map(static fn (Alert $a): string => $a->getUrl(), $rows), 'newest day first inside a month');
		self::assertSame(['2026-08-26', '2026-08-07', '2026-07-09'],
			array_map(static fn (Alert $a): ?string => $a->getDay(), $rows));
		self::assertSame(['actualizacion', 'alerta', null],
			array_map(static fn (Alert $a): ?string => $a->getTipo(), $rows));
	}

	/** A MINSAL page publishes no day and no type: the row says null, not an empty string that looks like a value. */
	public function testAPageFuentesRowCarriesNoDayAndNoTipo(): void {
		$this->alerts->replaceSet('alerts', [$this->alert('https://m/1', '2026-08')]);

		$row = $this->alerts->readSet('alerts')[0];

		self::assertNull($row->getDay());
		self::assertNull($row->getTipo());
	}

	/**
	 * A stream's item (ADR-0017 D6): read again, it is the same row, its
	 * title as the publisher now words it — the ISP edits in place. Nothing
	 * a stream stops listing is withdrawn, so upsert never deletes.
	 */
	public function testAStreamItemIsOneRowRewrittenInPlace(): void {
		$this->alerts->upsert('isp_anamed', $this->alert('https://i/1', '2026-08', day: '2026-08-25'));
		$this->alerts->upsert('isp_anamed', $this->alert('https://i/2', '2026-09', day: '2026-09-15'));

		$edited = $this->alert('https://i/1', '2026-08', day: '2026-08-25');
		$edited->setTitle('AVISO — lotes afectados actualizados');
		$this->alerts->upsert('isp_anamed', $edited);

		$rows = $this->alerts->readSet('isp_anamed');
		self::assertSame(['https://i/2', 'https://i/1'], array_map(static fn (Alert $a): string => $a->getUrl(), $rows));
		self::assertSame('AVISO — lotes afectados actualizados', $rows[1]->getTitle(), 'the second read replaced the row, never added one');
		self::assertSame('2026-08-25', $rows[1]->getDay());
	}

	/** The scope, stream-side: the same url in another fuente is another row, untouched. */
	public function testAnUpsertStaysInsideItsFuente(): void {
		$this->alerts->replaceSet('isp_pharmacovigilance', [$this->alert('https://i/shared', '2026-08')]);

		$this->alerts->upsert('isp_anamed', $this->alert('https://i/shared', '2026-09', fuente: 'isp_pharmacovigilance'));
		$this->alerts->upsert('isp_anamed', $this->alert('https://i/shared', '2026-10'));

		self::assertSame(['https://i/shared'], $this->urls('isp_anamed'), 'stamped with the fuente it was upserted under');
		self::assertSame('2026-10', $this->alerts->readSet('isp_anamed')[0]->getMonth(), 'the second upsert rewrote its own row');
		self::assertSame('2026-08', $this->alerts->readSet('isp_pharmacovigilance')[0]->getMonth(),
			'the other sector\'s row for the same document is not this upsert\'s to rewrite');
	}

	/** @return list<string> */
	private function urls(string $fuente): array {
		return array_map(static fn (Alert $a): string => $a->getUrl(), $this->alerts->readSet($fuente));
	}
}
