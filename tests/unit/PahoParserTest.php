<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\Parser\PahoParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OPS/OMS's "Alertas y actualizaciones epidemiológicas", read as PAHO serves
 * it: the owner's captures of the listing's first two pages (2026-09-28), 18
 * documents each, Drupal Views markup — a created field carrying a
 * `<time datetime>` in Washington's offset, then a title field linking the
 * document by path.
 */
class PahoParserTest extends TestCase {
	private const PAGES = [
		'paho-alertas-p0.html' => 18,
		'paho-alertas-p1.html' => 18,
	];

	private const SUBJECT = 'PAHO alerts page';

	/** @var list<array{message: string, context: array}> every warning the parser logged — the one level it logs at */
	private array $warnings = [];

	protected function setUp(): void {
		$this->warnings = [];
	}

	/**
	 * Guard against a re-capture quietly removing what this suite exists for:
	 * the row count each page is read against, and the evening note whose
	 * UTC day is not PAHO's — without it the day test below passes for free.
	 */
	public function testTheFixturesStillCarryWhatThisSuiteExistsFor(): void {
		foreach (self::PAGES as $file => $rows) {
			self::assertSame($rows, substr_count($this->page($file), 'views-field-created'), "$file: the rows measured 2026-09-28");
		}

		self::assertStringContainsString(
			'<time datetime="2025-12-11T19:26:00-05:00">',
			$this->page('paho-alertas-p1.html'),
			'the note published at 19:26 in Washington, already the 12th in UTC',
		);
	}

	public function testReadsEveryRowOfEachPage(): void {
		foreach (self::PAGES as $file => $rows) {
			self::assertCount($rows, $this->parse($file), $file);
		}

		self::assertSame([], $this->warnings, 'a healthy page warns nothing');
	}

	/** The stream wire shape, typed (ADR-0017 D11/D13): the document's own page, dated by PAHO's day. */
	public function testEveryItemIsAnHttpsDocumentDatedAndTyped(): void {
		foreach (array_keys(self::PAGES) as $file) {
			foreach ($this->parse($file) as $item) {
				self::assertSame(['title', 'url', 'year', 'month', 'date', 'tipo'], array_keys($item), $file);
				self::assertNotSame('', $item['title']);
				self::assertStringStartsWith('https://www.paho.org/es/documentos/', $item['url']);
				self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
				self::assertSame(substr($item['date'], 0, 7), $item['month']);
				self::assertSame(substr($item['date'], 0, 4), $item['year']);
				self::assertContains($item['tipo'], ['alerta', 'actualizacion', 'otro']);
			}
		}
	}

	/** The listing's path, resolved against PAHO's origin; the title whole, as PAHO worded it. */
	public function testTheFirstRowReadsAsPahoPublishedIt(): void {
		self::assertSame(
			[
				'title' => 'Actualización epidemiológica Influenza aviar A(H5) en la Región de las Américas - 26 de agosto del 2026',
				'url' => 'https://www.paho.org/es/documentos/actualizacion-epidemiologica-influenza-aviar-ah5-region-americas-26-agosto-2026',
				'year' => '2026',
				'month' => '2026-08',
				'date' => '2026-08-26',
				'tipo' => 'actualizacion',
			],
			$this->parse('paho-alertas-p0.html')[0],
		);
	}

	/**
	 * PAHO's day, in PAHO's offset: 19:26 at -05:00 on 11 December is the
	 * 12th in UTC, and PAHO dated the note the 11th — its own title says so.
	 */
	public function testTheDayIsThePublishersDayNotUtcs(): void {
		$dates = array_column($this->parse('paho-alertas-p1.html'), 'date', 'url');

		self::assertSame(
			'2025-12-11',
			$dates['https://www.paho.org/es/documentos/nota-informativa-influenza-ah3n2-subclado-k-j241-consideraciones-para-region-americas-11'] ?? null,
		);
	}

	/** D13, measured on both captures: 18 alerts, 14 updates, and 4 publications that are neither. */
	public function testEveryRowIsTypedByItsTitle(): void {
		$tipos = array_count_values(array_column([...$this->parse('paho-alertas-p0.html'), ...$this->parse('paho-alertas-p1.html')], 'tipo'));
		ksort($tipos);

		self::assertSame(['actualizacion' => 14, 'alerta' => 18, 'otro' => 4], $tipos);
	}

	/** Case, accents, spacing and whatever follows say nothing about the type; the title's first two words say everything. */
	public function testTheTypeIgnoresCaseAccentsAndSpacing(): void {
		$items = $this->parser()->parse($this->rows([
			['t' => 'ALERTA EPIDEMIOLÓGICA: Sarampión', 'u' => '/es/documentos/a'],
			['t' => 'Actualizacion  epidemiologica:Dengue', 'u' => '/es/documentos/b'],
			['t' => 'Alertas y actualizaciones epidemiológicas', 'u' => '/es/documentos/c'],
			['t' => 'Nota informativa - Alerta epidemiológica', 'u' => '/es/documentos/d'],
		]), self::SUBJECT);

		self::assertSame(['alerta', 'actualizacion', 'otro', 'otro'], array_column($items, 'tipo'));
	}

	/**
	 * Only https reaches an href, and a row with no title or no readable day
	 * cannot be shown or placed — each dropped and counted in one line naming
	 * its page. A path is PAHO's own and resolves against its origin.
	 */
	public function testWhatCannotBeTrustedIsDroppedAndCounted(): void {
		$items = $this->parser()->parse($this->rows([
			['t' => 'javascript', 'u' => 'javascript:alert(1)'],
			['t' => 'plain http', 'u' => 'http://www.paho.org/es/documentos/x'],
			['t' => 'protocol-relative', 'u' => '//evil.example/es/documentos/y'],
			['t' => 'no day', 'u' => '/es/documentos/z', 'd' => 'pronto'],
			['t' => '   ', 'u' => '/es/documentos/w'],
			['t' => 'absolute', 'u' => 'https://www.paho.org/es/documentos/abs'],
			['t' => 'relative', 'u' => '/es/documentos/rel'],
		]), self::SUBJECT);

		self::assertSame(['https://www.paho.org/es/documentos/abs', 'https://www.paho.org/es/documentos/rel'], array_column($items, 'url'));
		self::assertSame(
			[['message' => 'Dropped {rejected} row(s) of the {subject} that could not be read or trusted', 'context' => ['rejected' => 5, 'subject' => self::SUBJECT]]],
			$this->warnings,
		);
	}

	/**
	 * Each row is read inside its own bounds: a row that lost its title field
	 * is dropped and counted, never paired with the next row's link — one
	 * pattern across the whole body would date that document by the row
	 * before it, and say nothing.
	 */
	public function testARowWithoutItsTitleNeverBorrowsTheNextRowsDocument(): void {
		$body = '<div class="views-field views-field-created"><time datetime="2026-09-20T10:00:00-04:00">20 Sep 2026</time></div>'
			. $this->rows([['t' => 'Alerta Epidemiológica Dengue', 'u' => '/es/documentos/dengue', 'd' => '2026-09-01T10:00:00-04:00']]);

		$items = $this->parser()->parse($body, self::SUBJECT);

		self::assertSame(['https://www.paho.org/es/documentos/dengue'], array_column($items, 'url'));
		self::assertSame('2026-09-01', $items[0]['date'], 'dated by its own row, not the orphan before it');
		self::assertSame(1, $this->warnings[0]['context']['rejected'] ?? null, 'the orphan row is counted');
	}

	public function testSortsNewestFirstAndListsADocumentOnce(): void {
		$items = $this->parser()->parse($this->rows([
			['t' => 'older', 'u' => '/es/documentos/a', 'd' => '2026-08-10T12:00:00-04:00'],
			['t' => 'newer', 'u' => '/es/documentos/b', 'd' => '2026-09-15T12:00:00-04:00'],
			['t' => 'older again', 'u' => '/es/documentos/a', 'd' => '2026-08-10T12:00:00-04:00'],
		]), self::SUBJECT);

		self::assertSame(['https://www.paho.org/es/documentos/b', 'https://www.paho.org/es/documentos/a'], array_column($items, 'url'));

		foreach (array_keys(self::PAGES) as $file) {
			$dates = array_column($this->parse($file), 'date');
			$sorted = $dates;
			rsort($sorted);
			self::assertSame($sorted, $dates, $file);
		}
	}

	/** A WAF page, a body cut before its first row: nothing, never an exception — the pipeline reads [] as a failed read. */
	public function testAnUnreadableBodyIsNoItems(): void {
		self::assertSame([], $this->parser()->parse('<html><body>Access denied</body></html>', self::SUBJECT));
		self::assertSame([], $this->parser()->parse(substr($this->page('paho-alertas-p0.html'), 0, 4000), self::SUBJECT));
		self::assertSame([], $this->parser()->parse('', self::SUBJECT));
		self::assertSame([], $this->warnings, 'no row, nothing dropped');
	}

	/** @return list<array<string, string>> */
	private function parse(string $file): array {
		return $this->parser()->parse($this->page($file), self::SUBJECT);
	}

	private function page(string $file): string {
		$html = file_get_contents(__DIR__ . '/fixtures/' . $file);
		self::assertIsString($html, "$file is missing from fixtures/");

		return $html;
	}

	/**
	 * Rows in PAHO's Views shape — the created field, then the title field
	 * linking the document — dated 2026-09-15 unless a row's own 'd' says
	 * otherwise.
	 *
	 * @param list<array{t: string, u: string, d?: string}> $rows
	 */
	private function rows(array $rows): string {
		$body = '';
		foreach ($rows as $row) {
			$body .= sprintf(
				'<div class="views-field views-field-created"><span class="field-content"><time datetime="%s">x</time></span></div>'
				. '<div class="views-field views-field-title"><span class="field-content"><a href="%s" hreflang="es">%s</a></span></div>',
				$row['d'] ?? '2026-09-15T12:00:00-04:00',
				htmlspecialchars($row['u'], ENT_QUOTES),
				htmlspecialchars($row['t'], ENT_QUOTES),
			);
		}

		return '<div class="view-content">' . $body . '</div>';
	}

	/** PageParserTest's recorder, cut to the one level this class logs at. */
	private function parser(): PahoParser {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = ['message' => (string)$message, 'context' => $context];
			},
		);

		return new PahoParser($logger);
	}
}
