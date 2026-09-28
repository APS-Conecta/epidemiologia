<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Throwable;

/**
 * Every alert fuente's set behind a query service, in one table keyed
 * (fuente, url) — ADR-0017. Every read and every replacement is SCOPED to one
 * fuente: replaceSet() used to empty the whole table, which was right while
 * one fuente owned it and would have wiped degreyd's set the first time any
 * other fuente stored one.
 *
 * An in-force fuente's set is REPLACED whole — a withdrawn alert vanishes
 * with the set that no longer names it. Never-overwrite is fail()'s business
 * (a zero-parse writes nothing); the transaction below is what keeps a
 * half-replaced set from ever being visible.
 *
 * ponytail: the branches here are replaceSet()'s rollback and scope and
 * readSet()'s ordering; their home test is the integration suite
 * (tests/integration/, L3-06) — the repository suites mock this class.
 * Within-month order is not preserved on this path for the MINSAL pages (no
 * sequence column, and they publish no day; the cache — the hot path — keeps
 * MINSAL's own curation); the table serves only after flushes and outages,
 * where a cosmetic within-month reshuffle is the honest price of a column
 * fewer.
 *
 * Inserts are hand-rolled for the sequence-less reason ReportMapper's docblock
 * carries — the composite key means no autoincrement, and QBMapper::insert()
 * would still ask Postgres for a lastval() that was never drawn. Every column
 * is written explicitly, so no entity default can drop one from the INSERT.
 *
 * @template-extends QBMapper<Alert>
 */
class AlertMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'epidemiologia_alert', Alert::class);
	}

	/**
	 * One fuente's durable last-good set, newest first: the month, then the
	 * publisher's day for the fuentes that publish one.
	 *
	 * @return Alert[]
	 */
	public function readSet(string $fuente): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('fuente', $qb->createNamedParameter($fuente)))
			->orderBy('month', 'DESC')
			->addOrderBy('day', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * One fuente's whole set, replaced in one transaction; every other
	 * fuente's rows are not this call's to touch. Upserts alone would leave a
	 * withdrawn alert in the table forever; a half-written set must never be
	 * visible, so rollback restores the last-good whole.
	 *
	 * The scope is stamped onto every row here, so no row can land under a
	 * fuente that a later replaceSet() of this one would not delete.
	 *
	 * @param list<Alert> $alerts
	 */
	public function replaceSet(string $fuente, array $alerts): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('fuente', $qb->createNamedParameter($fuente)));
			$qb->executeStatement();

			foreach ($alerts as $alert) {
				$alert->setFuente($fuente);
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
			->setValue('fuente', $qb->createNamedParameter($alert->getFuente()))
			->setValue('url', $qb->createNamedParameter($alert->getUrl()))
			->setValue('title', $qb->createNamedParameter($alert->getTitle()))
			->setValue('year', $qb->createNamedParameter($alert->getYear()))
			->setValue('month', $qb->createNamedParameter($alert->getMonth()))
			->setValue('day', $qb->createNamedParameter($alert->getDay()))
			->setValue('tipo', $qb->createNamedParameter($alert->getTipo()));
		$qb->executeStatement();
	}
}
