<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

/**
 * What the daily sondeo learned about one fuente — three answers, not two.
 *
 * A bool said only "readable or not", which left ProbeJob unable to tell a
 * fuente's own fault from its host's. That difference is the host grain's
 * (ADR-0017 D8): four fuentes share the ISP's host, and when the host gives
 * no answer at all, asking the other three costs one 15-second timeout each
 * and learns nothing the first did not — measured on the lab 2026-09-29, a
 * minute of cron spent on four timeouts in a row.
 */
enum Probe {
	/** The host answered and the fuente parsed. */
	case Healthy;

	/**
	 * The host answered — a refusal, a WAF page, a restructured document, an
	 * oversize body — and this fuente cannot be read. Its own fault as far as the
	 * sondeo can tell: each of the host's other fuentes still gets its own ask,
	 * because each address can answer differently, and a refusal is lifted by
	 * the first answer that follows it (Freno::lift()).
	 */
	case Unreadable;

	/**
	 * No answer came back at all: a timeout, a refused connection, a DNS or TLS
	 * fault. The host's, not the fuente's — the sondeo holds the host's other
	 * fuentes to this verdict for the rest of the pass instead of asking again.
	 */
	case Unreachable;
}
