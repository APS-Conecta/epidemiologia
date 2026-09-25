<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Integration;

use OCA\Epidemiologia\Db\Report;
use OCA\Epidemiologia\Db\ReportMapper;
use OC\DB\Exceptions\DbalException;

/**
 * The (year, se) series behind the informes pane — the mapper's own branches,
 * which no mock can answer (L3-06). Every test works on its own empty
 * in-memory database; there is no ordering between them and nothing to clean.
 */
class ReportMapperTest extends IntegrationTestCase {
	private ReportMapper $reports;

	protected function setUp(): void {
		parent::setUp();
		$this->reports = new ReportMapper($this->db);
	}

	public function testTheInsertBranchWritesARowWhosePropertiesRoundTrip(): void {
		$this->reports->upsert($this->report('35', 1789000000));

		$row = $this->reports->find('2026', '35');
		self::assertNotNull($row, 'the row the upsert claimed to write is findable by its key');
		self::assertSame('Informe circulación virus respiratorios SE35', $row->getTitle());
		self::assertSame('2026-08', $row->getMonth());
		self::assertSame(1789000000, $row->getPublishedAt());
		self::assertSame('informes/x.pdf', $row->getPath());
		self::assertSame(str_repeat('a', 64), $row->getHash());
	}

	public function testAReissueReplacesItsOwnWeekAndNeverWritesAThirdRow(): void {
		$this->reports->upsert($this->report('35', 1789000000));
		$this->reports->upsert($this->report('35', 1789000500, path: null));

		self::assertCount(1, $this->reports->all(), 'a (…-V3 → …-V4) reissue replaces its own week');
		$row = $this->reports->find('2026', '35');
		self::assertSame(1789000500, $row->getPublishedAt());
		self::assertNull($row->getPath(), 'the released document clears the path, and NULL round-trips as NULL');
	}

	public function testAllOrdersNewestFirstOnPublishedAtAlone(): void {
		$this->reports->upsert($this->report('35', 1789000000));
		$this->reports->upsert($this->report('9', 1789999999));
		$this->reports->upsert($this->report('40', 1788000000));

		self::assertSame(['9', '35', '40'], array_map(static fn (Report $r) => $r->getSe(), $this->reports->all()),
			'published_at alone — a January semana outranks December\'s of the year before on its own publication date, and `se` is a string column that would mis-sort');
	}

	public function testUnarchivedListsOnlyRowsWithoutADocument(): void {
		$this->reports->upsert($this->report('35', 1789000000));
		$this->reports->upsert($this->report('34', 1788900000, path: null));

		self::assertSame(['34'], array_map(static fn (Report $r) => $r->getSe(), $this->reports->unarchived()));
	}

	public function testASecondInsertOfTheSameKeyDiesOnThePrimaryKey(): void {
		$this->reports->upsert($this->report('35', 1789000000));

		// The find-then-write window's failure mode (L3-09): two writers that
		// both missed the find race one duplicate-key error. The mapper does not
		// catch it — store()'s catch is the defense — so the pin is the throw
		// itself: the composite PK is the backstop the docblock claims.
		$this->expectException(DbalException::class);
		$this->db->executeQuery(
			'INSERT INTO epidemiologia_report (year, se, title, url, month, published_at)
			 VALUES (:year, :se, :title, :url, :month, :at)',
			['year' => '2026', 'se' => '35', 'title' => 'racing insert', 'url' => 'u', 'month' => '2026-08', 'at' => 1],
		);
	}
}
