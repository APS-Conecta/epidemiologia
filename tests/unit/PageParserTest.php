<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\Parser\PageParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The page parser against the page that takes the branch degreyd never does.
 *
 * degreyd's 13 alerts stay pinned through the repository, in
 * AlertRepositoryTest, untouched by the move. This suite reads
 * epi.minsal.cl's "Alertas Epidemiológicas Vigentes" as the owner captured it
 * on 2026-09-27 from a residential connection (the VPS is answered with a
 * Cloudflare 403, ADR-0014): 78 PDF links in two tables, 27 of them labelled
 * "Descargar Documento", so those alerts are titled from their row — the
 * rowTitles() branch, dormant on degreyd, live here. A synthetic table would
 * only prove the fallback reads markup written for it; this one carries epi's
 * own `<td>&nbsp;</p><p>…` cells and rowspan groups.
 */
class PageParserTest extends TestCase {
	private const SUBJECT = 'MINSAL epi alerts page';

	/** @var list<array{message: string, context: array}> every warning the parser logged — the one level it logs at */
	private array $warnings = [];

	protected function setUp(): void {
		$this->warnings = [];
	}

	/**
	 * Guard against a re-capture quietly removing what this suite exists for:
	 * with no repeated label left, every title test below would pass through
	 * the anchor text, and with no inversion left the sort test would pass
	 * for free — both exercising nothing.
	 */
	public function testTheFixtureStillCarriesWhatThisSuiteExistsFor(): void {
		$html = $this->epi();

		self::assertSame(78, preg_match_all('#href="\s*[^"]*\.pdf\s*"#i', $html), 'the 78 PDF links measured 2026-09-28');
		self::assertSame(27, preg_match_all('#>\s*Descargar Documento\s*</a>#i', $html), 'the 27 link labels measured 2026-09-28');

		// The same pattern degreyd's inversion pin uses, and for its reason.
		preg_match_all('#<a[^>]+href="[^"]*/uploads/(\d{4})/(\d{2})/[^"]+\.pdf\s*"#i', $html, $found);
		$inversions = 0;
		for ($i = 1, $n = count($found[1]); $i < $n; $i++) {
			if ($found[1][$i - 1] . $found[2][$i - 1] < $found[1][$i] . $found[2][$i]) {
				$inversions++;
			}
		}
		self::assertSame(9, $inversions, 'the 9 month inversions measured 2026-09-28');
	}

	public function testReadsEveryAlertEpiPublishesAndListsTheTwiceLinkedPdfOnce(): void {
		$urls = array_column($this->parse(), 'url');

		self::assertCount(77, $urls, '78 links, one PDF linked from two rows');
		self::assertSame(array_unique($urls), $urls);
		self::assertCount(1, array_filter(
			$urls,
			static fn (string $u): bool => str_ends_with($u, '/2024_ago_5_alerta_incremento_sars-cov_2_influenza_otros_respiratorios.pdf'),
		));
	}

	public function testALinkLabelledDescargarDocumentoIsTitledFromItsRow(): void {
		$titles = array_column($this->parse(), 'title', 'url');

		self::assertNotContains('Descargar Documento', $titles, 'a repeated link label is not a title');
		self::assertSame(
			'Alerta Epidemiológica – Fiebre amarilla en la Región de las Américas.',
			$titles['https://epi.minsal.cl/wp-content/uploads/2025/06/2025_mayo_31_phe_alerta_epidemiologica_fiebre_amarilla.pdf'] ?? null,
			'the row\'s first cell, "Nombre de la alerta", with its &#8211; decoded',
		);
	}

	/** Shown whole, never truncated — the reason `title` is varchar(1000) (Version001100Date20260928000000). */
	public function testTheLongestTitleIsKeptWhole(): void {
		$titles = array_column($this->parse(), 'title', 'url');
		$bundibugyo = $titles['https://epi.minsal.cl/wp-content/uploads/2026/06/OFICIO_CP_N_12712_2026_Virus_Bundibugyo.pdf'] ?? '';

		self::assertSame(270, mb_strlen($bundibugyo));
		self::assertStringEndsWith('(ESPII).', $bundibugyo);
	}

	/**
	 * epi dates every row in its "Fecha" column, and that day is the alert's
	 * (ADR-0017 D11, amended in 0.11.3): the upload folder is when a PDF was
	 * put on the server, and it names another month for 24 of the 77 — the
	 * oropouche oficio of 10 December 2024 sits in 2025/04.
	 */
	public function testEveryAlertCarriesTheDayItsRowGivesIt(): void {
		$items = $this->parse();
		self::assertNotSame([], $items, 'the fixture must parse, or this test asserts nothing');

		$dated = array_filter($items, static fn (array $item): bool => isset($item['date']));
		self::assertCount(76, $dated, 'every row but the one that names no day');
		foreach ($dated as $item) {
			self::assertSame(['title', 'url', 'year', 'month', 'date'], array_keys($item));
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
			self::assertSame(substr($item['date'], 0, 7), $item['month']);
			self::assertSame(substr($item['date'], 0, 4), $item['year']);
		}
	}

	/**
	 * One pin per shape the column takes: its plain form, the date a rowspan
	 * cell carries down to the rows it spans, a row's own date beside a
	 * spanned name, and the three spellings MINSAL mixes in.
	 */
	public function testTheRowsDayIsReadWhateverShapeTheRowTakes(): void {
		$days = array_column($this->parse(), 'date', 'url');
		$uploads = 'https://epi.minsal.cl/wp-content/uploads/';

		foreach ([
			'2026/09/OFICIO_CP_N22695_2026_coqueluche.pdf' => '2026-09-15', // "15 de Septiembre de 2026"
			'2025/04/ORD_3422_INFORMA_SITUACION_DE_OROPOUCHE_EN_LAS_AMERICAS.pdf' => '2024-12-10', // filed four months late
			'2025/05/ORD_1255_ALERTA_EPIDEMIOLOGICA_VIGILANCIA_ENF_MENINGOCOCICA_POR_AUMENTO_ESTACIONAL_ESPERADO_2025.pdf' => '2025-05-06', // a rowspan date, "6 de mayo del 2025"
			'2024/05/ORD_1359_09_05_2024.pdf' => '2024-06-09', // a rowspan date again
			'2024/07/ORD-2869-24-07-2023-Alerta-epidem-deteccion-de-EV-A71.pdf' => '2023-07-24', // its own date, its name spanned
			'2024/06/3760_ORD_REFUERZA_VACUNACION_CONTRA_SARAMPION.pdf' => '2024-09-15', // "15 de septiembre 2024"
			'2025/06/2025_mayo_31_phe_alerta_epidemiologica_fiebre_amarilla.pdf' => '2025-05-31', // "31 mayo 2025"
			'2024/07/2023_Dic_26_Alerta_epidemiologica-Riesgo_infeccion_por_virus_encefalitis_equina_del_oeste.pdf' => '2023-12-26', // "Corrigendum – 26 de diciembre del 2023"
		] as $path => $day) {
			self::assertSame($day, $days[$uploads . $path] ?? null, $path);
		}
	}

	/** "Junio 2024" names a month and no day: the month is the row's, and no day is made up for it. */
	public function testARowThatNamesNoDayKeepsItsMonthAndClaimsNoDay(): void {
		$items = array_column($this->parse(), null, 'url');
		$protocolo = $items['https://epi.minsal.cl/wp-content/uploads/2024/09/RES_EXENTA_N_1227_PROTOCOLO.pdf'] ?? null;

		self::assertSame(['title', 'url', 'year', 'month'], array_keys($protocolo ?? []));
		self::assertSame('2024-06', $protocolo['month'], 'the row\'s June, not the folder\'s September');
		self::assertSame('2024', $protocolo['year']);
	}

	/**
	 * degreyd's table heads its date column "Desde" — in force since, not
	 * published on — so none of its alerts is dated from it, and its wire
	 * shape stays byte for byte what ADR-0017 D11 pinned.
	 */
	public function testATableWithNoFechaColumnDatesNothing(): void {
		$html = file_get_contents(__DIR__ . '/fixtures/degreyd-alertas.html');
		self::assertIsString($html, 'the degreyd capture is missing from fixtures/');

		$items = $this->parser()->parse($html, 'MINSAL alerts page');

		self::assertCount(13, $items);
		foreach ($items as $item) {
			self::assertSame(['title', 'url', 'year', 'month'], array_keys($item));
		}
	}

	/**
	 * epi's markup carries 9 month inversions across its 78 links (pinned
	 * above); the list carries none, and within a month the later day leads.
	 */
	public function testSortsNewestFirst(): void {
		$items = $this->parse();

		self::assertSame('2026-09-15', $items[0]['date']);
		for ($i = 1, $n = count($items); $i < $n; $i++) {
			self::assertGreaterThanOrEqual(
				[$items[$i]['month'], $items[$i]['date'] ?? ''],
				[$items[$i - 1]['month'], $items[$i - 1]['date'] ?? ''],
				"alert $i is newer than the one before it",
			);
		}
	}

	public function testAHealthyPageWarnsNothing(): void {
		$this->parse();

		self::assertSame([], $this->warnings, '78 links present, 78 read, none rejected');
	}

	/**
	 * Two pages share this parser, so each line names its own — a warning that
	 * said "MINSAL alerts page" about an epi fault would send its reader to the
	 * wrong host.
	 */
	public function testEveryWarningNamesThePageItIsAbout(): void {
		$items = $this->parser()->parse($this->twoFaults(), self::SUBJECT);

		self::assertCount(1, $items, 'only the https anchor survives');
		self::assertCount(2, $this->warnings, 'one missed-anchor warning, one rejected-link warning');
		foreach ($this->warnings as $warning) {
			self::assertStringContainsString('{subject}', $warning['message']);
			self::assertSame(self::SUBJECT, $warning['context']['subject'] ?? null);
		}
	}

	/**
	 * degreyd's subject renders the two lines exactly as they read while the
	 * parse was MinsalRepository's own — the move changes no sentence an admin
	 * greps for.
	 */
	public function testDegreydsLinesReadAsTheyDidBeforeTheMove(): void {
		$this->parser()->parse($this->twoFaults(), 'MINSAL alerts page');

		self::assertSame(
			[
				'MINSAL alerts page has 3 pdf link(s) in href attributes but only 2 were read as anchors — an alert may be missing',
				'Dropped 1 non-https links from the MINSAL alerts page',
			],
			array_map(
				static fn (array $w): string => strtr($w['message'], array_combine(
					array_map(static fn (string $k): string => '{' . $k . '}', array_keys($w['context'])),
					array_map('strval', $w['context']),
				)),
				$this->warnings,
			),
		);
	}

	/** One real anchor, one http downgrade, and a PDF href no anchor pattern can read. */
	private function twoFaults(): string {
		return '<a href="https://epi.minsal.cl/wp-content/uploads/2026/01/real.pdf">Alerta real</a>'
			. '<a href="http://epi.minsal.cl/wp-content/uploads/2026/01/downgrade.pdf">Alerta rebajada</a>'
			. '<link rel="alternate" href="https://epi.minsal.cl/wp-content/uploads/2026/01/ghost.pdf">';
	}

	/** @return list<array<string, string>> */
	private function parse(): array {
		return $this->parser()->parse($this->epi(), self::SUBJECT);
	}

	private function epi(): string {
		$html = file_get_contents(__DIR__ . '/fixtures/epi-alertas-vigentes.html');
		self::assertIsString($html, 'the epi capture is missing from fixtures/');

		return $html;
	}

	/** The repository suites' recorder, cut to the one level this class logs at. */
	private function parser(): PageParser {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = ['message' => (string)$message, 'context' => $context];
			},
		);

		return new PageParser($logger);
	}
}
