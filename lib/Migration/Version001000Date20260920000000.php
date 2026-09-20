<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's first two tables — ADR-0015.
 *
 * Empty on arrival, deliberately: nothing is seeded, because a fresh install's
 * first successful fetch backfills what the publishers still offer, and an
 * install during an outage starts empty, which is correct (ADR-0015,
 * Consequences: the alertas are never seeded — a stale in-force list is a
 * clinical hazard).
 *
 * `epidemiologia_report` keys on (year, se): the semana is the identity the
 * pane prints, and semanas restart every January — 2027's Semana 1 can never
 * collide with 2026's, the exact crash class the se-only cache key tolerated
 * only because ADR-0012 guaranteed the series never spanned a year.
 *
 * Columns are strings end to end: the wire contract is
 * array<string, string> items (see MinsalRepository::present()), and a
 * cast-free round trip is the only way the table's copy and the cache's copy
 * stay byte-identical. published_at is the one integer — an epoch is a
 * number, and it is the ordering truth that never gets re-derived from a URL.
 *
 * `epidemiologia_alert` keys on url: the identity parse() already guarantees
 * unique (its own dedupe, the digest, AlertsPane's Vue key). Rows are
 * metadata only — the PDFs stay links where MINSAL publishes them; only the
 * informes keep documents (ADR-0015 §2).
 */
class Version001000Date20260920000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('epidemiologia_report')) {
			$table = $schema->createTable('epidemiologia_report');
			// Composite, no autoincrement: a semana is identified by the pair,
			// and an upsert on the pair must never mint a third row for one week.
			$table->addColumn('year', Types::STRING, ['notnull' => true, 'length' => 4]);
			$table->addColumn('se', Types::STRING, ['notnull' => true, 'length' => 2]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			// The publisher's address — provenance and the route's fallback. NOT the
			// pane's href once the document is archived: that becomes
			// /informe/{year}/{semana} (ADR-0015 §2, decision D5).
			$table->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 255]);
			// 'YYYY-MM' from pubDate in the publisher's zone — the pane renders mes().
			$table->addColumn('month', Types::STRING, ['notnull' => true, 'length' => 7]);
			$table->addColumn('published_at', Types::INTEGER, ['notnull' => true]);
			// Null until the document is kept; set on first sight (ADR-0015 §2).
			$table->addColumn('path', Types::STRING, ['notnull' => false, 'length' => 255]);
			// sha256 of the archived bytes: never re-download one it has; it
			// changes only when the publisher edits a published informe.
			$table->addColumn('hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['year', 'se']);
		}

		if (!$schema->hasTable('epidemiologia_alert')) {
			$table = $schema->createTable('epidemiologia_alert');
			$table->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Upload-path year/month, as parse() derives them today — the
			// metadata AlertsPane filters on, never the oficio's year.
			$table->addColumn('year', Types::STRING, ['notnull' => true, 'length' => 4]);
			$table->addColumn('month', Types::STRING, ['notnull' => true, 'length' => 7]);
			$table->setPrimaryKey(['url']);
		}

		return $schema;
	}
}
