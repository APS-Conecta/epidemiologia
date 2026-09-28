<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service\Parser;

/**
 * One publisher's response, read into alert items — ADR-0017.
 *
 * The seam is where the alert fuentes differ: fetching, caching, the freno
 * and the degraded state are one pipeline, and what varies from publisher to
 * publisher is the shape of the document it fetched. That shape is read
 * here, and only here.
 *
 * Stateless per call, so one instance serves every fuente that publishes in
 * its shape. `$subject` is what the fuente calls the document it fetched,
 * and reaches nothing but the log lines a parse can emit — two pages sharing
 * a parser must not share one sentence.
 *
 * An empty list is never a result. The caller reads it as a failed read and
 * keeps serving its last good copy (CONTEXT.md, estado degradado), so a parser
 * never tries to tell "the publisher lists nothing" from "this could not be
 * read": from the outside the two look the same, and only the second is
 * worth a clinician's trust.
 */
interface Parser {
	/**
	 * @param string $body    the response, already bounded by the caller's byte cap
	 * @param string $subject the document, as the log names it — "MINSAL alerts page"
	 *
	 * @return list<array<string, string>> title, url (https only), year and month, newest first
	 */
	public function parse(string $body, string $subject): array;
}
