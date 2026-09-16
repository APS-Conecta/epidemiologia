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
	private const SHIPPED = __DIR__ . '/../../certs/gsgccr6alphasslca2025.crt';

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

		// Which user is asking. `posix_geteuid()` or nothing: the old fallback,
		// getmyuid(), reports the owner of THIS FILE rather than of the process, and
		// this file is root-owned in a normal checkout — so on an instance without
		// ext-posix the ownership check below compared root against root and trusted
		// exactly the squatted file it exists to reject. A value no real owner can
		// ever hold makes that check fail closed instead: the file is simply rebuilt.
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;

		// Identity, not a constant filename: the instance bundle's own PATH (not
		// only its mtime — getAbsoluteBundlePath() switches between two different
		// files depending on whether any CA is imported, and an admin removing
		// their last imported CA flips it to the packaged default, whose mtime is
		// its release date and can be OLDER than an existing merge; an mtime-only
		// check would never rebuild, and the removed CA would stay trusted
		// forever), its mtime and size, the shipped PEM's mtime, and the euid of the
		// process that would write it. Different inputs can never collide on a
		// filename, so two instances sharing a host, or an instance whose trust
		// anchors changed, can never be confused with each other's cache.
		//
		// The euid is in there because ownership is checked but not repairable: a
		// root-run `occ` command arriving first writes the merge root-owned, after
		// which www-data can neither trust it (wrong owner) nor overwrite it (no
		// write permission, and a sticky temp directory forbids unlinking someone
		// else's file). Without the euid that is permanent — both ISP fuentes degrade
		// forever behind a filename nobody can guess. With it, each user gets its own
		// cache file and each one self-heals.
		$identity = hash('sha256', $instance . '|' . $instanceStat['mtime'] . '|' . $instanceStat['size'] . '|' . $shippedMtime . '|' . $euid);
		$merged = $base . '/epidemiologia-isp-ca-bundle-' . $identity . '.pem';

		// The right identity is still not proof this process wrote the file: it may
		// have been left by someone else. Measured in production — a root-owned
		// leftover at this app's (then-constant) filename that the web server's
		// user could neither read past nor repair, so ISP requests silently
		// verified against a stray fixture instead of the instance's real anchors.
		// A file this process does not own is treated as though it does not exist.
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
		//
		// Success is the WHOLE payload landing, not merely "not false": a truncated
		// write publishes a partial CA file under a correct identity name and a
		// correct owner, so nothing would ever reject it or rebuild it again. The
		// byte count is what file_put_contents() documents itself to return.
		$payload = $anchors . "\n" . $shipped;
		$written = @file_put_contents($merged, $payload, LOCK_EX);

		return $written !== strlen($payload) ? $instance : $merged;
	}
}
