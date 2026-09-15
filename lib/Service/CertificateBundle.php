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
 * This is NOT a way around verification. A certificate placed in a CAfile is
 * trusted by OpenSSL as an additional anchor, not merely as chain-completion
 * material — the merged bundle keeps every anchor the instance already trusts
 * and adds this one, so it is trusted as an additional anchor for these two
 * requests specifically, and verification still runs. `verify => false`
 * remains forbidden.
 */
class CertificateBundle {
	private const SHIPPED = __DIR__ . '/../../certs/gsgccr6alphasslca2025.pem';

	public function __construct(
		private ICertificateManager $certificates,
		private ITempManager $temp,
	) {
	}

	/**
	 * Absolute path to a bundle of the instance's anchors plus the shipped
	 * intermediate. Named after the exact bytes that went into it, so a stale or
	 * foreign file at the same path is structurally impossible — it would simply
	 * be a miss, never something read and mistaken for fresh.
	 *
	 * Both the anchor bundle's location and the scratch directory come from the
	 * instance, never from a constant: hard-coding either is what makes an app
	 * work on one machine and not the next.
	 *
	 * Degrades to the instance bundle rather than throwing. A hardened deployment
	 * with no writable scratch directory, or a merge that cannot be written for
	 * any other reason, then produces exactly today's behaviour — the ISP fails
	 * its TLS handshake and the fuente degrades — instead of a fatal inside a
	 * page render, which is a worse outcome than the problem being fixed.
	 */
	public function path(): string {
		$instance = $this->certificates->getAbsoluteBundlePath();

		try {
			$base = rtrim($this->temp->getTempBaseDir(), '/');
		} catch (\Throwable $e) {
			// getTempBaseDir() is documented to throw UnexpectedValueException when a
			// hardened deployment has no writable candidate directory at all — the
			// lookup itself can fail, not only the write. Same degrade as below.
			return $instance;
		}

		$instanceStat = @stat($instance);
		$shippedMtime = @filemtime(self::SHIPPED);
		if ($instanceStat === false || $shippedMtime === false) {
			return $instance;
		}

		// Identity, not a constant filename: the instance bundle's own PATH (not
		// only its mtime — getAbsoluteBundlePath() switches between two different
		// files depending on whether any CA is imported, and an admin removing
		// their last imported CA flips it to the packaged default, whose mtime is
		// its release date and can be OLDER than an existing merge; an mtime-only
		// check would never rebuild, and the removed CA would stay trusted
		// forever), its mtime and size, and the shipped PEM's mtime. Different
		// inputs can never collide on a filename, so two instances sharing a host,
		// or an instance whose trust anchors changed, can never be confused with
		// each other's cache.
		$identity = hash('sha256', $instance . '|' . $instanceStat['mtime'] . '|' . $instanceStat['size'] . '|' . $shippedMtime);
		$merged = $base . '/epidemiologia-isp-ca-bundle-' . $identity . '.pem';

		// The right identity is still not proof this process wrote the file: it may
		// have been left by someone else. Measured in production — a root-owned
		// leftover at this app's (then-constant) filename that the web server's
		// user could neither read past nor repair, so ISP requests silently
		// verified against a stray fixture instead of the instance's real anchors.
		// A file this process does not own is treated as though it does not exist.
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		if (is_file($merged) && @fileowner($merged) === $euid) {
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
