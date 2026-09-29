<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One alert of one alert fuente, as ADR-0015 persists it and ADR-0017 keys
 * it: (fuente, url)-keyed metadata, the durable last-good copy of the set the
 * cache serves. The document stays a link where its publisher keeps it —
 * only the informes keep documents.
 *
 * `fuente` is the Sources id. `day` is the publisher's day where it publishes
 * one and `tipo` is OPS/OMS's alerta/actualización split — null otherwise.
 *
 * Untyped defaulted properties for the Entity-base reason Report.php names.
 * AlertMapper writes every column by hand, so an initializer equal to the
 * value being written cannot drop a column from the INSERT — the
 * QBMapper::insert() updated-fields trap does not reach this table.
 *
 * @method string getFuente()
 * @method void setFuente(string $fuente)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getYear()
 * @method void setYear(string $year)
 * @method string getMonth()
 * @method void setMonth(string $month)
 * @method ?string getDay()
 * @method void setDay(?string $day)
 * @method ?string getTipo()
 * @method void setTipo(?string $tipo)
 */
class Alert extends Entity {
	protected $fuente = '';
	protected $url = '';
	protected $title = '';
	protected $year = '';
	protected $month = '';
	protected $day = null;
	protected $tipo = null;
}
