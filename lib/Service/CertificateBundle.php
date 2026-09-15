<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Service;

use OCP\ICertificateManager;
use OCP\ITempManager;

/**
 * The CA bundle this app's ISP requests verify against.
 *
 * `www.ispch.gob.cl` sends only its leaf certificate. A browser recovers by
 * chasing the AIA extension; PHP, curl and Guzzle do not, so the chain cannot be
 * built and every request dies `cURL error 60` — reported, until now, as a
 * generic outage. Importing the intermediate by hand fixes one instance and
 * pins one issuer; the publisher rotates on its own schedule (it did on
 * 2026-09-15) and nothing detects it. Carrying the intermediate here is what
 * makes a fresh install on ANY machine work with no provisioning step.
 *
 * This is NOT a way around verification. The merged bundle keeps every anchor
 * the instance already trusts and adds one public intermediate, so the chain
 * resolves and verification still runs. `verify => false` remains forbidden.
 */
class CertificateBundle {
	private const SHIPPED = __DIR__ . '/../../certs/gsgccr6alphasslca2025.pem';
	private const MERGED = 'epidemiologia-isp-ca-bundle.pem';

	public function __construct(
		private ICertificateManager $certificates,
		private ITempManager $temp,
	) {
	}

	/**
	 * Absolute path to a bundle of the instance's anchors plus the shipped
	 * intermediate, rebuilt whenever either input is newer than the merge.
	 *
	 * Both locations come from the instance, never from a constant: the anchor
	 * bundle is wherever this deployment keeps it, and the scratch directory is
	 * whatever it considers writable. Hard-coding either is what makes an app
	 * work on one machine and not the next.
	 *
	 * Degrades to the instance bundle rather than throwing. A read-only or full
	 * scratch directory then produces exactly today's behaviour — the ISP fails
	 * its TLS handshake and the fuente degrades — instead of a fatal inside a
	 * page render, which is a worse outcome than the problem being fixed.
	 */
	public function path(): string {
		$instance = $this->certificates->getAbsoluteBundlePath();
		$merged = rtrim($this->temp->getTempBaseDir(), '/') . '/' . self::MERGED;

		$stale = !is_file($merged)
			|| filemtime($merged) < filemtime($instance)
			|| filemtime($merged) < filemtime(self::SHIPPED);

		if (!$stale) {
			return $merged;
		}

		$anchors = @file_get_contents($instance);
		$shipped = @file_get_contents(self::SHIPPED);
		if ($anchors === false || $shipped === false) {
			return $instance;
		}

		// LOCK_EX because the web SAPI and the cron SAPI can arrive together, and a
		// half-written bundle verifies nothing.
		return @file_put_contents($merged, $anchors . "\n" . $shipped, LOCK_EX) === false
			? $instance
			: $merged;
	}
}
