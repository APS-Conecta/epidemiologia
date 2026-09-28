<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCA\Epidemiologia\Service\Parser\Parser;

/**
 * One alert fuente, as data — ADR-0017. The pipeline is one class,
 * AlertRepository, and everything that differs between the fuentes it
 * serves is written here instead: where the fuente publishes, which host's
 * brake it answers to, how its document reads, and what the log calls it.
 * Built by AlertRepositories and nowhere else.
 *
 * - `id`: the Sources id — the cache entry's, the table scope's and the
 *   revalidation's name for this fuente.
 * - `url`: the one address it reads.
 * - `host`: the grain its freno and outage cooldown are kept under —
 *   `freno-{host}`, `cooldown-{host}`.
 * - `publisher`: who the freno's warning names when the host says no —
 *   "MINSAL answered 403".
 * - `name`: its kept set, as the log names it — "Could not persist the
 *   MINSAL alerts set".
 * - `subject`: the document it fetches, as the log names it — "The MINSAL
 *   alerts page could not be read". The parser's `$subject` too.
 * - `parser`: how that document reads.
 *
 * Every string is spelled out per fuente rather than derived from another,
 * because degreyd's are pinned: its keys and its log sentences are what they
 * were while it was MinsalRepository's (AlertRepositoryTest), and a fuente
 * added later names its own.
 */
final readonly class Fuente {
	public function __construct(
		public string $id,
		public string $url,
		public string $host,
		public string $publisher,
		public string $name,
		public string $subject,
		public Parser $parser,
	) {
	}
}
