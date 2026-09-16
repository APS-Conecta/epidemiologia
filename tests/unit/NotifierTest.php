<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Notification\Notifier;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCA\Epidemiologia\Service\IspRepository;
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
 * green suite. The first test below is that missing assertion.
 */
class NotifierTest extends TestCase {
	use Doubles;

	public function testAnIspOutageNamesTheIspAndNotMinsal(): void {
		$subject = $this->render(Sources::SANITARY_ALERTS);

		self::assertStringContainsString('las alertas sanitarias del ISP', $subject);
		self::assertStringNotContainsString('MINSAL', $subject);
	}

	public function testAMinsalAlertsOutageStillNamesTheAlertasVigentes(): void {
		self::assertStringContainsString(
			'las alertas epidemiológicas vigentes del MINSAL',
			$this->render(Sources::ALERTS),
		);
	}

	public function testAnIragOutageIsDistinguishableFromAnAlertsOutage(): void {
		$subject = $this->render(Sources::REPORTS);

		self::assertStringContainsString('los informes semanales ETI/IRAG del ISP', $subject);
		self::assertStringNotContainsString('MINSAL', $subject);
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
			$this->createMock(IspRepository::class),
			$this->createMock(IspReportRepository::class),
		);

		return new Notifier($factory, $urls, $sources);
	}
}
