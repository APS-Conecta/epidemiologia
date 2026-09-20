<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * The (year, semana) series behind a query service — the seam the repository
 * suites double (ADR-0007: no call-counting), so no test here fakes a fluent
 * SQL chain.
 *
 * ponytail: this class DOES branch (find()'s catch, upsert()'s find-then-write)
 * and those branches' home test is the deferred integration suite — the
 * design's Not Building names the deferral, and the unit seam for the
 * repository suites is the mapper MOCK, not this class. Add the
 * territorio-style integration suite when a mapper bug escapes, not before.
 *
 * Inserts are hand-rolled, not QBMapper::insert(): the inherited insert
 * unconditionally calls setId(getLastInsertId()), which on Postgres runs
 * SELECT lastval() and errors (SQLSTATE 55000) on a session that never drew
 * from a sequence — and both of this app's tables are deliberately
 * sequence-less (composite (year, se) key; url key). The territorio
 * provenance covers only autoincrement tables; this is the composite-PK
 * half the pattern never shipped.
 *
 * @template-extends QBMapper<Report>
 */
class ReportMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'epidemiologia_report', Report::class);
	}

	/**
	 * The whole series, newest first — the pane's order. published_at alone:
	 * a January semana outranks December's of the year before on its own
	 * publication date, and `se` is a string column — tie-breaking on it
	 * would sort SE 9 above SE 35.
	 *
	 * @return Report[]
	 */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('published_at', 'DESC');

		return $this->findEntities($qb);
	}

	/** One semana, or null — the route's key and the archiver's. */
	public function find(string $year, string $se): ?Report {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('year', $qb->createNamedParameter($year)))
			->andWhere($qb->expr()->eq('se', $qb->createNamedParameter($se)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Rows whose document is not kept yet — the archiver's work list.
	 *
	 * @return Report[]
	 */
	public function unarchived(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('path'))
			->orderBy('published_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Insert or replace on (year, se) — a reissue (…-V3) replaces its own week,
	 * never a third row. Find-then-write, not a native upsert: the QueryBuilder
	 * has none, and the composite PK is the backstop — the single-flight lock
	 * (Slice 3) is what makes the find window safe; without it two concurrent
	 * refreshes would race one duplicate-key error, which is the lock's defect
	 * to have, not the mapper's.
	 */
	public function upsert(Report $report): void {
		if ($this->find($report->getYear(), $report->getSe()) === null) {
			$this->insertRow($report);

			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('title', $qb->createNamedParameter($report->getTitle()))
			->set('url', $qb->createNamedParameter($report->getUrl()))
			->set('month', $qb->createNamedParameter($report->getMonth()))
			->set('published_at', $qb->createNamedParameter($report->getPublishedAt()))
			->set('path', $qb->createNamedParameter($report->getPath()))
			->set('hash', $qb->createNamedParameter($report->getHash()))
			->where($qb->expr()->eq('year', $qb->createNamedParameter($report->getYear())))
			->andWhere($qb->expr()->eq('se', $qb->createNamedParameter($report->getSe())));
		$qb->executeStatement();
	}

	/** See the class docblock: sequence-less tables cannot ride QBMapper::insert(). */
	private function insertRow(Report $report): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->setValue('year', $qb->createNamedParameter($report->getYear()))
			->setValue('se', $qb->createNamedParameter($report->getSe()))
			->setValue('title', $qb->createNamedParameter($report->getTitle()))
			->setValue('url', $qb->createNamedParameter($report->getUrl()))
			->setValue('month', $qb->createNamedParameter($report->getMonth()))
			->setValue('published_at', $qb->createNamedParameter($report->getPublishedAt()))
			->setValue('path', $qb->createNamedParameter($report->getPath()))
			->setValue('hash', $qb->createNamedParameter($report->getHash()));
		$qb->executeStatement();
	}
}
