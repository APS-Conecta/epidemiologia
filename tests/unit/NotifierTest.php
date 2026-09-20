<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Notification\Notifier;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\MinsalRepository;
use OCA\Epidemiologia\Service\Sources;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator actually reads when a source goes down.
 *
 * This class had one test's worth of a hole in it and shipped a real defect
 * through: ProbeJob counted an ISP outage correctly and this notifier rendered it
 * as "no se pudo leer las alertas epidemiológicas vigentes desde MINSAL", sending
 * whoever read it to inspect a MINSAL page that was working. ProbeJobTest asserted
 * the counter and nothing asserted the sentence, so the bug was invisible to a
 * green suite. The institution assertions below are that missing pin.
 */
class NotifierTest extends TestCase {
	use Doubles;

	public function testAMinsalAlertsOutageStillNamesTheAlertasVigentes(): void {
		self::assertStringContainsString(
			'las alertas epidemiológicas vigentes del MINSAL',
			$this->render(Sources::ALERTS),
		);
	}

	/**
	 * Named for the fuente and not for ETI/IRAG, which is what the PREVIOUS publisher
	 * called a document this app no longer reads. A test name is read by whoever has
	 * to fix the thing it guards, so it is one more place the old name would have
	 * kept a second vocabulary alive.
	 */
	public function testAnInformeOutageIsDistinguishableFromAnAlertsOutage(): void {
		$subject = $this->render(Sources::REPORTS);

		self::assertStringContainsString('el informe semanal de Circulación de Virus Respiratorios del ISP', $subject);
		self::assertStringNotContainsString('MINSAL', $subject);
		// The tablero keeps ETI e IRAG, legitimately — it really is MINSAL's report.
		// This fuente carrying the acronym too is what made the two read as one series
		// from one publisher, which is now exactly wrong.
		self::assertStringNotContainsString('IRAG', $subject);
	}

	/**
	 * Rows written before the retirement carry `sanitary_alerts`, which is no
	 * longer in Sources::ALL. The same guard that refuses the old `feed` rows drops
	 * these too — a stale row must not take the bell out for everyone. (The repair
	 * step shipping in 0.10.0 wipes them for good, but the notifier cannot assume
	 * it has run.)
	 */
	public function testARowFromTheRetiredFuenteIsRefusedRatherThanFatal(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->render(null, ['source' => 'sanitary_alerts', 'days' => 2]);
	}

	/**
	 * Rows written by 0.2.0 carry `feed`, not `source`, so they arrive here with no
	 * source at all. Sources::name() has no default match arm on purpose, so without
	 * the guard the first such row would throw UnhandledMatchError inside the
	 * notifications endpoint — a stale row would take out the bell for everyone.
	 */
	public function testANotificationFromTheOldSchemaIsRefusedRatherThanFatal(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->render(null, ['feed' => 'irag', 'days' => 2]);
	}

	public function testAnUnknownSourceIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->render(null, ['source' => 'inventada', 'days' => 2]);
	}

	// -----------------------------------------------------------------------

	private function render(?string $source, ?array $parameters = null): string {
		$parsed = '';

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('epidemiologia');
		$notification->method('getSubjectParameters')
			->willReturn($parameters ?? ['source' => $source, 'days' => 2]);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use ($notification, &$parsed): INotification {
				$parsed = $text;

				return $notification;
			},
		);
		foreach (['setParsedMessage', 'setIcon', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$this->notifier()->prepare($notification, 'es');

		return $parsed;
	}

	private function notifier(): Notifier {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->l10n());

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturn('https://nc/img/app-dark.svg');
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc/apps/epidemiologia/');

		$sources = new Sources(
			$this->createMock(MinsalRepository::class),
			$this->createMock(IspReportRepository::class),
		);

		return new Notifier($factory, $urls, $sources);
	}
}
