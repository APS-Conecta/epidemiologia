<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One informe: a (year, semana) of the ISP's weekly series, as ADR-0015 persists it.
 *
 * Untyped properties with defaults, exactly as territorio's entities carry them:
 * the base Entity's setter reads `$this->$name` before the first assignment
 * (Entity.php:95), so a typed-no-default property fatals there on every row it
 * maps — measured by the slice verifier, not by any suite.
 *
 * @method string getYear()
 * @method void setYear(string $year)
 * @method string getSe()
 * @method void setSe(string $se)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getMonth()
 * @method void setMonth(string $month)
 * @method int getPublishedAt()
 * @method void setPublishedAt(int $publishedAt)
 * @method ?string getPath()
 * @method void setPath(?string $path)
 * @method ?string getHash()
 * @method void setHash(?string $hash)
 */
class Report extends Entity {
	protected $year = '';
	protected $se = '';
	protected $title = '';
	protected $url = '';
	protected $month = '';
	protected $publishedAt = 0;
	protected $path = null;
	protected $hash = null;
}
