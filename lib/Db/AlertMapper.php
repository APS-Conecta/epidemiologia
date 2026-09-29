<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
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
 * A stream's items are UPSERTED one by one instead (ADR-0017 D6): a stream
 * keeps every item it ever read, so nothing it stops listing is withdrawn,
 * and there is no whole set to replace.
 *
 * ponytail: the branches here are replaceSet()'s rollback and scope,
 * upsert()'s find-then-write and readSet()'s ordering; their home test is the
 * integration suite (tests/integration/, L3-06) — the repository suites mock
 * this class.
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

	/**
	 * One stream item, inserted, or replaced in place on (fuente, url): a
	 * publisher that edits a title in place — the ISP does, adding affected
	 * lots — is read as the same item, never a second row. The scope is
	 * stamped here for replaceSet()'s reason.
	 *
	 * Find-then-write, not a native upsert: the QueryBuilder has none. No lock
	 * guards the window (D6): two refreshes racing one new item lose one
	 * insert to the (fuente, url) key, and the loser's store() logs it and
	 * serves its reading anyway — the key is the backstop, ReportMapper's
	 * doctrine.
	 */
	public function upsert(string $fuente, Alert $alert): void {
		$alert->setFuente($fuente);

		if ($this->find($fuente, $alert->getUrl()) === null) {
			$this->insertRow($alert);

			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('title', $qb->createNamedParameter($alert->getTitle()))
			->set('year', $qb->createNamedParameter($alert->getYear()))
			->set('month', $qb->createNamedParameter($alert->getMonth()))
			->set('day', $qb->createNamedParameter($alert->getDay()))
			->set('tipo', $qb->createNamedParameter($alert->getTipo()))
			->where($qb->expr()->eq('fuente', $qb->createNamedParameter($fuente)))
			->andWhere($qb->expr()->eq('url', $qb->createNamedParameter($alert->getUrl())));
		$qb->executeStatement();
	}

	/** One fuente's row for one document, or null — upsert()'s question. */
	private function find(string $fuente, string $url): ?Alert {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('fuente', $qb->createNamedParameter($fuente)))
			->andWhere($qb->expr()->eq('url', $qb->createNamedParameter($url)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
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
