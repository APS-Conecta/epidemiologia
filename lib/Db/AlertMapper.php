<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Throwable;

/**
 * The in-force set behind a query service: keyed by url, REPLACED whole —
 * a withdrawn alert vanishes with the set that no longer names it. Never-overwrite
 * is fail()'s business (a zero-parse writes nothing, Slice 2); the transaction
 * below is what keeps a half-replaced set from ever being visible.
 *
 * ponytail: the branches here are replaceSet()'s rollback and readSet()'s
 * ordering; their home test is the integration suite (tests/integration/,
 * L3-06 — the deferral this paragraph used to carry) — the repository
 * suites mock this class.
 * Within-month order is not preserved on this path (no sequence column; the
 * cache — the hot path — keeps Minsal's own curation); the table serves only
 * after flushes and outages, where a cosmetic within-month reshuffle is the
 * honest price of two columns fewer.
 *
 * Inserts are hand-rolled for the sequence-less reason ReportMapper's docblock
 * carries — the url key means no autoincrement, and QBMapper::insert() would
 * still ask Postgres for a lastval() that was never drawn.
 *
 * @template-extends QBMapper<Alert>
 */
class AlertMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'epidemiologia_alert', Alert::class);
	}

	/**
	 * The durable last-good set, newest first.
	 *
	 * @return Alert[]
	 */
	public function readSet(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('month', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * The whole set, replaced in one transaction. Upserts alone would leave a
	 * withdrawn alert in the table forever; a half-written set must never be
	 * visible, so rollback restores the last-good whole.
	 *
	 * @param list<Alert> $alerts
	 */
	public function replaceSet(array $alerts): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName());
			$qb->executeStatement();

			foreach ($alerts as $alert) {
				$this->insertRow($alert);
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** See the class docblock: sequence-less tables cannot ride QBMapper::insert(). */
	private function insertRow(Alert $alert): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('url', $qb->createNamedParameter($alert->getUrl()))
			->setValue('title', $qb->createNamedParameter($alert->getTitle()))
			->setValue('year', $qb->createNamedParameter($alert->getYear()))
			->setValue('month', $qb->createNamedParameter($alert->getMonth()));
		$qb->executeStatement();
	}
}
