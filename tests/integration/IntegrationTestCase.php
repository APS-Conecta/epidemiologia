<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use OC\DB\AdapterSqlite;
use OC\DB\Connection;
use OC\DB\ConnectionAdapter;
use OCA\Epidemiologia\Db\Alert;
use OCA\Epidemiologia\Db\Report;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * One in-memory SQLite database per test, carrying the app's two tables as
 * the migrations leave them (Version001000, then Version001100's alert key).
 *
 * Built through Doctrine's DriverManager with Nextcloud's own wrapper class,
 * and deliberately NOT through OC\DB\ConnectionFactory: the factory merges
 * the install's own database configuration into whatever parameters it is
 * handed — measured while writing this suite, a ":memory:" request came back
 * as a stray file in data/ pointing at the system config's sqlite path.
 * DriverManager builds exactly what it is asked for; the wrapper needs its
 * `primary`/`replica` shape because OC\DB\Connection is a read-replica
 * connection underneath.
 *
 * SQLite, and not the install's Postgres: the suite runs wherever the unit
 * suite runs — inside the container — and a throwaway in-memory database is
 * the only one that cannot leak a row into a clinician's install. The
 * dialect the mappers speak (Query Builder, transactions, composite keys)
 * is the shared one.
 */
abstract class IntegrationTestCase extends TestCase {
	protected IDBConnection $db;

	protected function setUp(): void {
		$params = [
			'adapter' => AdapterSqlite::class,
			'driver' => 'pdo_sqlite',
			'path' => ':memory:',
			'tablePrefix' => '',
		];
		$inner = DriverManager::getConnection($params + [
			'wrapperClass' => Connection::class,
			'primary' => $params,
			'replica' => [$params],
		]);
		$this->db = new ConnectionAdapter($inner);

		$this->db->executeQuery('CREATE TABLE epidemiologia_report (
			year VARCHAR(4) NOT NULL,
			se VARCHAR(2) NOT NULL,
			title VARCHAR(255) NOT NULL,
			url VARCHAR(255) NOT NULL,
			month VARCHAR(7) NOT NULL,
			published_at INTEGER NOT NULL,
			path VARCHAR(255) DEFAULT NULL,
			hash VARCHAR(64) DEFAULT NULL,
			PRIMARY KEY(year, se)
		)');
		$this->db->executeQuery('CREATE TABLE epidemiologia_alert (
			fuente VARCHAR(32) NOT NULL DEFAULT \'alerts\',
			url VARCHAR(255) NOT NULL,
			title VARCHAR(1000) NOT NULL,
			year VARCHAR(4) NOT NULL,
			month VARCHAR(7) NOT NULL,
			day VARCHAR(10) DEFAULT NULL,
			tipo VARCHAR(16) DEFAULT NULL,
			PRIMARY KEY(fuente, url)
		)');
	}

	/** One report row, as the feed reader builds them. */
	protected function report(string $se, int $publishedAt, ?string $path = 'informes/x.pdf'): Report {
		$r = new Report();
		$r->setYear('2026');
		$r->setSe($se);
		$r->setTitle('Informe circulación virus respiratorios SE' . $se);
		$r->setUrl('https://www.ispch.gob.cl/documento/semana-' . $se . '-2026/');
		$r->setMonth('2026-08');
		$r->setPublishedAt($publishedAt);
		$r->setPath($path);
		$r->setHash($path === null ? null : str_repeat('a', 64));

		return $r;
	}

	/** One alert row, as an alert repository builds them; replaceSet() stamps the fuente it writes under. */
	protected function alert(
		string $url,
		string $month,
		string $fuente = 'alerts',
		?string $day = null,
		?string $tipo = null,
	): Alert {
		$a = new Alert();
		$a->setFuente($fuente);
		$a->setUrl($url);
		$a->setTitle('OFICIO ' . $url);
		$a->setYear(substr($month, 0, 4));
		$a->setMonth($month);
		$a->setDay($day);
		$a->setTipo($tipo);

		return $a;
	}
}
