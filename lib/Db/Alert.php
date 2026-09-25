<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One in-force MINSAL alert, as ADR-0015 persists it: url-keyed metadata, the
 * durable last-good copy of the same snapshot the cache serves. The PDF stays
 * a link where MINSAL publishes it — only the informes keep documents.
 *
 * Untyped defaulted properties for the Entity-base reason Report.php names.
 *
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getYear()
 * @method void setYear(string $year)
 * @method string getMonth()
 * @method void setMonth(string $month)
 */
class Alert extends Entity {
	protected $url = '';
	protected $title = '';
	protected $year = '';
	protected $month = '';
}
