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
 * One alert table for every alert fuente — ADR-0017.
 *
 * `epidemiologia_alert` was keyed on url alone because one fuente wrote it,
 * and AlertMapper::replaceSet() emptied the whole table to replace that one
 * fuente's set: a second fuente on it would have wiped the first. So the
 * fuente becomes half of the key. The same document filed by two publishers,
 * or under two ISP categories, is two rows — one per sector, the "where the
 * publisher filed it" rule.
 *
 * Altered in place rather than rebuilt: the rows already here are degreyd's
 * in-force set, and the column default labels them in the statement that
 * adds the column — 'alerts' is that fuente's Sources id — so no backfill
 * step exists to be skipped (a postSchemaChange backfill would not run on a
 * fresh install anyway: MigrationService skips it for schema-only installs).
 * This is the org's first primary-key swap on a populated table. DBAL 3.10
 * emits DROP CONSTRAINT …_pkey / ADD PRIMARY KEY (fuente, url) on Postgres;
 * what vouches for it on real data is a rehearsal against a restore of an
 * install's database, not this file — a comment cannot run a migration.
 *
 * `day` is the publisher's own day ('YYYY-MM-DD') for the fuentes that
 * publish one (the ISP, OPS/OMS); null for the MINSAL pages, whose only date
 * is an upload month. `tipo` is OPS/OMS's alerta/actualización split; null
 * everywhere else. Strings both, the first migration's end-to-end rule.
 *
 * `title` widens to 1000: the epi.minsal.cl page carries a 270-character
 * oficio title (measured 2026-09-28), and one title too long for its column
 * fails the whole replaceSet() transaction — that fuente's durable copy would
 * never exist. Titles are shown whole, never truncated, so the column grows.
 */
class Version001100Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('epidemiologia_alert')) {
			return null;
		}

		$table = $schema->getTable('epidemiologia_alert');
		if ($table->hasColumn('fuente')) {
			return null;
		}

		$table->addColumn('fuente', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'alerts']);
		$table->addColumn('day', Types::STRING, ['notnull' => false, 'length' => 10]);
		$table->addColumn('tipo', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->getColumn('title')->setLength(1000);
		$table->dropPrimaryKey();
		$table->setPrimaryKey(['fuente', 'url']);

		return $schema;
	}
}
