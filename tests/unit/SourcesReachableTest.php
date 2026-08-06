<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\IspRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCA\Epidemiologia\Service\ProbeRecord;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Epidemiologia\Service\Sources;
use OCA\Epidemiologia\SetupCheck\SourcesReachable;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What an administrator reads on Administración → Visión general.
 *
 * This class had no test at all while it was the one that stayed GREEN through an
 * ISP outage: ProbeJob had been writing the ISP's failure count since 0.2.0 and
 * this check walked MINSAL's two fuentes by name and never read it. So every
 * assertion here is on the rendered row — its severity and which fuente it names —
 * and none is on how a counter is stored.
 *
 * Every test walks Sources::ALL rather than naming the three fuentes, which is the
 * property that matters: a fourth fuente added to the catálogo and forgotten here
 * fails in this file instead of in front of an administrator.
 */
class SourcesReachableTest extends TestCase {
	use Doubles;

	/** 2026-08-03T12:00:00Z */
	private const NOW = 1785758400;

	protected function setUp(): void {
		$this->config = [];
	}

	// --- the row an administrator sees ---------------------------------------

	public function testEveryFuenteHealthyReadsAsSuccess(): void {
		$this->probed();

		$result = $this->check()->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	/**
	 * Before a first run there is nothing to report, and this row said
	 * "El último sondeo diario leyó todas las fuentes sin problemas" anyway.
	 *
	 * `consecutive()` reads 0 for a key that was never written, so "never probed" and
	 * "probed, all good" were the same value. The job is TIME_INSENSITIVE and the window
	 * opens at 05:00, so after every install the row asserted a result that did not exist
	 * for roughly 29 hours. It is the shape #75 fixed one layer down: an absence answered
	 * as good news.
	 */
	public function testAnInstanceThatHasNeverProbedSaysSoInsteadOfClaimingSuccess(): void {
		$result = $this->check()->run();

		self::assertSame(SetupResult::INFO, $result->getSeverity());
		self::assertStringNotContainsString('sin problemas', (string)$result->getDescription());
	}

	/**
	 * The defect this file exists for. Before #37 this row said nothing at all
	 * while ProbeJob counted the failures next door.
	 */
	public function testAnIspOutageIsReportedAndNamesTheIsp(): void {
		$this->failing(Sources::SANITARY_ALERTS, days: 3);

		$result = $this->check()->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringContainsString('ISP', (string)$result->getDescription());
	}

	/**
	 * Naming the wrong institution is the shape of the bug in the notifier, and it
	 * would be just as wrong here: an administrator sent to inspect MINSAL learns
	 * nothing, because MINSAL is fine.
	 */
	public function testAnIspOutageDoesNotMentionMinsal(): void {
		$this->failing(Sources::SANITARY_ALERTS, days: 3);

		self::assertStringNotContainsString('MINSAL', (string)$this->check()->run()->getDescription());
	}

	public function testAMinsalOutageNamesMinsalAndNotTheIsp(): void {
		$this->failing(Sources::ALERTS, days: 2);

		$description = (string)$this->check()->run()->getDescription();

		self::assertStringContainsString('MINSAL', $description);
		self::assertStringNotContainsString('ISP', $description);
	}

	/**
	 * A warning, not an error: the app keeps serving its last good copy behind the
	 * "problemas técnicos" banner, so nothing else on the instance is broken.
	 */
	public function testAnOutageIsAWarningAndNotAnError(): void {
		$this->failing(Sources::ALERTS, days: 30);

		self::assertSame(SetupResult::WARNING, $this->check()->run()->getSeverity());
	}

	// --- "and since when", which is the whole point of a row over a bell ------

	public function testTheRowSaysSinceWhenTheFuenteStoppedBeingReadable(): void {
		$this->failing(Sources::ALERTS, days: 5);

		// The l10n double renders a date as `date:<timestamp>`, so this asserts that
		// the stamp reached the sentence — not how it is formatted, which is the
		// platform's business and changes with the user's locale.
		self::assertStringContainsString('date:' . self::NOW, (string)$this->check()->run()->getDescription());
	}

	/**
	 * ProbeJob writes the stamp on the FIRST failure, so a counter without one is a
	 * state that should not exist. If it does, saying nothing beats inventing a
	 * date: an administrator acts on "since the 14th".
	 */
	/*
	 * testWithNoRecordedStartTheRowClaimsNoDate lived here and is gone.
	 *
	 * It drove the row with failures but no start stamp, and asserted the row said
	 * no date rather than "since 1970". That state was only reachable because the
	 * count and the stamp were two config keys a test could set apart; ProbeRecord
	 * stamps on the first failure, so it can no longer be built through the
	 * interface. The invariant it protected is now asserted where it belongs, in
	 * ProbeRecordTest::testAFailingFuenteAlwaysKnowsWhenItStarted.
	 *
	 * The null branch in SourcesReachable stays: a hand-edited config can still
	 * delete one key, and a row claiming an outage began at the epoch is worse than
	 * one that declines to say.
	 */

	// --- the property that stops a fourth fuente going unnoticed --------------

	public function testEveryFuenteInTheCatalogoIsReported(): void {
		foreach (Sources::ALL as $source) {
			$this->failing($source, days: 2);
		}

		$description = (string)$this->check()->run()->getDescription();

		// One sentence per fuente. Asserting the COUNT rather than three names is
		// what makes this survive a fourth fuente being added: it fails then too.
		self::assertSame(
			count(Sources::ALL),
			substr_count($description, 'No se pudo leer'),
			'every fuente in the catálogo must produce its own line',
		);
	}

	public function testOneBrokenFuenteDoesNotDragTheOthersIntoTheRow(): void {
		$this->failing(Sources::SANITARY_ALERTS, days: 2);

		$description = (string)$this->check()->run()->getDescription();

		self::assertSame(1, substr_count($description, 'No se pudo leer'));
	}

	// --- the constraint that is easy to break by accident ---------------------

	/**
	 * SetupCheckManager::runAll() invokes every check inline while an administrator
	 * waits for the page, so a check that fetched would hang Visión general for as
	 * long as a foreign government server felt like taking.
	 *
	 * The repositories below throw on any read. Reaching one fails the test rather
	 * than merely being slow, which is the only way this stays true by accident.
	 */
	public function testTheRowNeverReachesOutToAFuente(): void {
		$this->failing(Sources::ALERTS, days: 2);

		$result = $this->check(explosive: true)->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
	}

	public function testTheHealthyRowAlsoNeverReachesOut(): void {
		$this->probed();

		self::assertSame(SetupResult::SUCCESS, $this->check(explosive: true)->run()->getSeverity());
	}

	// -----------------------------------------------------------------------

	/** A completed run with nothing wrong — which is NOT the same as no run. */
	private function probed(): void {
		$record = $this->probeRecord();
		foreach (Sources::ALL as $source) {
			$record->record($source, true);
		}
	}

	private function failing(string $source, int $days): void {
		// Through the record, not by writing its keys. This helper used to import
		// ProbeJob for two string prefixes and set them by hand — the test agreeing
		// with production only because both spelled the same string.
		$record = $this->probeRecord();
		for ($i = 0; $i < $days; $i++) {
			$record->record($source, false);
		}
	}

	private function check(bool $explosive = false): SourcesReachable {
		$minsal = $this->createMock(MinsalRepository::class);
		$isp = $this->createMock(IspRepository::class);

		if ($explosive) {
			foreach (['get', 'probe', 'search'] as $read) {
				$minsal->method($read)->willThrowException(new RuntimeException('a setup check must not fetch'));
				$isp->method($read)->willThrowException(new RuntimeException('a setup check must not fetch'));
			}
		}

		return new SourcesReachable($this->probeRecord(), new Sources($minsal, $isp), $this->l10n());
	}

	/** Real, over the shared config double — see ProbeJobTest for why it is not a mock. */
	private function probeRecord(?int $now = null): ProbeRecord {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now ?? self::NOW);

		return new ProbeRecord($this->appConfig(), $time);
	}


}
