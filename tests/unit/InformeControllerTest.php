<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Controller\InformeController;
use OCA\Epidemiologia\Db\Report;
use OCA\Epidemiologia\Db\ReportMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The informe's own address, answered by each thing that can be true about
 * a week: the kept bytes when the row vouches for them, the publisher's own
 * address whenever they cannot be served, and a 404 for a week nothing
 * mints.
 *
 * FakeAppData is the store, the hand-written double (not a createMock):
 * getFile() throws the real OCP\Files\NotFoundException this controller
 * catches, which is the whole difference between a redirect pin and a pin
 * that would fatal on the real thing. The mapper double is the table,
 * state-backed (ADR-0007): find() answers what seed() wrote, so a redirect
 * is asserted as an answer about a ROW, never as a collaborator call count.
 *
 * Header values are read by reflection: getHeaders() asks the server
 * container for a request id, and the unit suite deliberately boots none.
 * The reflection is one property deep and asserts the two headers that ARE
 * this route's behaviour — Content-Type application/pdf and the inline
 * disposition — rather than trusting the constructor call's arguments.
 */
class InformeControllerTest extends TestCase {
	use Doubles;

	private const PDF = '%PDF-1.4 the week 35 informe, kept by the install';

	private const URL = 'https://www.ispch.gob.cl/wp-content/uploads/2026/09/Informe-SE35-08-09-2026-V3.pdf';

	/** The publisher's page the fallbacks redirect to — the URL above is the row's provenance, never a browser's address. */
	private const PAGE = 'https://www.ispch.gob.cl/documento/semana-35-2026/';

	/** @var array<string, Report> the mapper double's table, keyed 'year-se' */
	private array $rows = [];

	/** @var list<array{level: string, message: string, context: array}> what the controller logged */
	private array $logged = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->logged = [];
		$this->config = [];
	}

	/**
	 * The row the pins stand on — minted by the test, because the controller
	 * is never the thing that mints rows: it serves, redirects, or 404s over
	 * what the reader already persisted.
	 */
	private function seed(?string $path = null, ?string $hash = null): void {
		$report = new Report();
		$report->setYear('2026');
		$report->setSe('35');
		$report->setTitle('Semana 35, 2026');
		$report->setUrl(self::URL);
		$report->setMonth('2026-09');
		$report->setPublishedAt(1789473600);
		$report->setPath($path);
		$report->setHash($hash);
		$this->rows['2026-35'] = $report;
	}

	private function controller(FakeAppData $data): InformeController {
		$mapper = $this->createMock(ReportMapper::class);
		$mapper->method('find')->willReturnCallback(
			fn (string $year, string $se): ?Report => $this->rows[$year . '-' . $se] ?? null,
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				},
			);
		}

		return new InformeController(
			$this->createMock(IRequest::class),
			$mapper,
			$this->appDataFactory($data),
			$logger,
		);
	}

	/** getHeaders() needs a server container; the suite boots none. One property, two headers. */
	private function headerOf($response, string $name): ?string {
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))
			->getValue($response);

		return $headers[$name] ?? null;
	}

	public function testServesTheKeptDocument(): void {
		$this->seed('informe-2026-35.pdf', hash('sha256', self::PDF));
		$data = new FakeAppData();
		$data->newFile('informe-2026-35.pdf', self::PDF);

		$response = $this->controller($data)->show('2026', '35');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(self::PDF, $response->render(), 'the kept bytes, whole');
		self::assertSame('application/pdf', $this->headerOf($response, 'Content-Type'));
		self::assertSame(
			'inline; filename="informe-2026-35.pdf"',
			$this->headerOf($response, 'Content-Disposition'),
			'the pane opens a tab to read the report, not to file it',
		);
	}

	public function testRedirectsToThePublisherWhenTheKeptFileIsGone(): void {
		$this->seed('informe-2026-35.pdf', hash('sha256', self::PDF));
		$data = new FakeAppData();   // empty: kept once, gone now

		$response = $this->controller($data)->show('2026', '35');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(self::PAGE, $response->getRedirectURL());
	}

	public function testRedirectsToThePublisherWhileTheDocumentIsNotKept(): void {
		$this->seed();   // no path: the archiver has not reached this week yet

		$response = $this->controller(new FakeAppData())->show('2026', '35');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(
			self::PAGE,
			$response->getRedirectURL(),
			'the wire carried this page before the document was kept; it still opens the informe',
		);
	}

	public function testAnswers404ForAWeekNothingMints(): void {
		$response = $this->controller(new FakeAppData())->show('1999', '1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * 513958a: a partial file served forever at a correct name. The hash is
	 * the row's whole claim about the bytes, and this is the one place it is
	 * checked — the mismatch must redirect, warn, and never serve.
	 */
	public function testRefusesToServeBytesTheRowDoesNotVouchFor(): void {
		$this->seed('informe-2026-35.pdf', hash('sha256', self::PDF));
		$data = new FakeAppData();
		$data->newFile('informe-2026-35.pdf', '%PDF-1.4 truncated by some earlier crash');

		$response = $this->controller($data)->show('2026', '35');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(self::PAGE, $response->getRedirectURL());
		$warnings = array_values(array_filter(
			$this->logged,
			static fn (array $l): bool => $l['level'] === 'warning',
		));
		self::assertCount(1, $warnings, 'a kept copy that rots is a fact only an admin can act on');
		self::assertStringContainsString('does not match its hash', $warnings[0]['message']);
	}
}

