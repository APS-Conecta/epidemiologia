<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\Parser\FeedParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ISP's category feeds, read as the ISP serves them. Three are the
 * owner's captures of the feeds the fuentes read (2026-09-27, from a vantage
 * the ISP answers — this VPS gets nothing): ANAMED, Farmacovigilancia and
 * Vigilancia de cosméticos, 10/10/3 posts. Two are the retired reader's July
 * captures of the same WordPress (849053b^), kept because the shape they pin
 * is the one the ISP has served since: plain titles, a +0000 pubDate, the
 * post page as <link>, no <category> at all.
 */
class FeedParserTest extends TestCase {
	private const FEEDS = [
		'isp-anamed.xml' => 10,
		'isp-farmacovigilancia.xml' => 10,
		'isp-vigilancia-de-cosmeticos.xml' => 3,
		'isp-anamed-2026-07.xml' => 10,
		'isp-retiro-2026-07.xml' => 10,
	];

	/** @var list<array{message: string, context: array}> every warning the parser logged — the one level it logs at */
	private array $warnings = [];

	protected function setUp(): void {
		$this->warnings = [];
	}

	/**
	 * Guard against a re-capture quietly removing what this suite exists for:
	 * the trailing U+2002 the title rule must strip, and the absence of any
	 * <category> — a category tag would be a facet this parser drops.
	 */
	public function testTheFixturesStillCarryWhatThisSuiteExistsFor(): void {
		foreach (array_keys(self::FEEDS) as $file) {
			self::assertSame(0, substr_count($this->feed($file), '<category'), "$file carries no <category>, measured 2026-09-27");
		}

		self::assertStringContainsString(
			"REACCIONES ADVERSAS CUTÁNEAS GRAVES\u{2002}</title>",
			$this->feed('isp-farmacovigilancia.xml'),
			'the valproato note\'s trailing EN SPACE, measured 2026-09-27',
		);
	}

	public function testReadsEveryPostOfEachFeed(): void {
		foreach (self::FEEDS as $file => $count) {
			self::assertCount($count, $this->parse($file), $file);
		}

		self::assertSame([], $this->warnings, 'a healthy feed warns nothing');
	}

	/** The stream wire shape (ADR-0017 D11): the post page, dated by the publisher's day. */
	public function testEveryItemIsAnHttpsPostDatedByItsDay(): void {
		foreach (array_keys(self::FEEDS) as $file) {
			foreach ($this->parse($file) as $item) {
				self::assertSame(['title', 'url', 'year', 'month', 'date'], array_keys($item), $file);
				self::assertNotSame('', $item['title']);
				self::assertStringStartsWith('https://www.ispch.gob.cl/', $item['url']);
				self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
				self::assertSame(substr($item['date'], 0, 7), $item['month']);
				self::assertSame(substr($item['date'], 0, 4), $item['year']);
			}
		}
	}

	/** <link> is the post a clinician opens — not the PDF inside <content:encoded>, which some posts lack. */
	public function testTheUrlIsThePostsOwnPage(): void {
		$first = $this->parse('isp-anamed.xml')[0];

		self::assertSame(
			'https://www.ispch.gob.cl/alerta/nota-informativa-de-farmacovigilancia-depresion-respiratoria-asociada-al-uso-concomitante-de-gabapentinoides-y-tramadol-y-trastorno-por-consumo-de-opioides-asociado-al-uso-de-tramadol/',
			$first['url'],
		);
		self::assertSame('2026-09-23', $first['date']);
	}

	public function testTitlesCarryNoWhitespaceTrimCannotSee(): void {
		$titles = array_column($this->parse('isp-farmacovigilancia.xml'), 'title');

		$valproato = array_values(array_filter($titles, static fn (string $t): bool => str_contains($t, 'VALPROATO')));
		self::assertCount(1, $valproato);
		self::assertStringEndsWith('CUTÁNEAS GRAVES', $valproato[0]);

		$titles = array_column($this->parser()->parse($this->rss([
			['t' => "\u{00A0}Suturas V-Loc 90", 'u' => 'https://www.ispch.gob.cl/alerta/a/'],
			['t' => "RETIRO CON\u{00A0}FINALIDAD", 'u' => 'https://www.ispch.gob.cl/alerta/b/'],
		]), 'ISP test feed'), 'title');
		self::assertSame(['Suturas V-Loc 90', 'RETIRO CON FINALIDAD'], $titles);
	}

	/**
	 * The ISP prints a Chilean date. 2026-08-04T02:30:00Z is still 3 August in
	 * Santiago (UTC-4); rendered in the container's UTC it would be a day late
	 * — one alert of 173 was, on the live archive (2026-08-05).
	 */
	public function testTheDayIsThePublishersDayNotTheServers(): void {
		$items = $this->parser()->parse($this->rss([
			['t' => 'Alerta nocturna', 'u' => 'https://www.ispch.gob.cl/alerta/x/', 'p' => 'Tue, 04 Aug 2026 02:30:00 +0000'],
		]), 'ISP test feed');

		self::assertSame('2026-08-03', $items[0]['date']);
		self::assertSame('2026-08', $items[0]['month']);
	}

	/**
	 * Only https reaches an href, and an item with no readable day cannot be
	 * placed — each dropped and counted in one line naming its feed, never lost
	 * in silence.
	 */
	public function testWhatCannotBeTrustedIsDroppedAndCounted(): void {
		$items = $this->parser()->parse($this->rss([
			['t' => 'javascript', 'u' => 'javascript:alert(1)'],
			['t' => 'plain http', 'u' => 'http://www.ispch.gob.cl/alerta/x/'],
			['t' => 'no day', 'u' => 'https://www.ispch.gob.cl/alerta/y/', 'p' => 'pronto'],
			['t' => '   ', 'u' => 'https://www.ispch.gob.cl/alerta/z/'],
			['t' => 'good', 'u' => 'https://www.ispch.gob.cl/alerta/ok/'],
		]), 'ISP ANAMED alerts feed');

		self::assertSame(['good'], array_column($items, 'title'));
		self::assertSame(
			[['message' => 'Dropped {rejected} item(s) the {subject} could not be trusted for', 'context' => ['rejected' => 4, 'subject' => 'ISP ANAMED alerts feed']]],
			$this->warnings,
		);
	}

	public function testSortsNewestFirstAndListsAPostOnce(): void {
		$items = $this->parser()->parse($this->rss([
			['t' => 'older', 'u' => 'https://www.ispch.gob.cl/alerta/a/', 'p' => 'Mon, 10 Aug 2026 12:00:00 +0000'],
			['t' => 'newer', 'u' => 'https://www.ispch.gob.cl/alerta/b/', 'p' => 'Tue, 15 Sep 2026 12:00:00 +0000'],
			['t' => 'older again', 'u' => 'https://www.ispch.gob.cl/alerta/a/', 'p' => 'Mon, 10 Aug 2026 12:00:00 +0000'],
		]), 'ISP test feed');

		self::assertSame(['https://www.ispch.gob.cl/alerta/b/', 'https://www.ispch.gob.cl/alerta/a/'], array_column($items, 'url'));

		foreach (array_keys(self::FEEDS) as $file) {
			$dates = array_column($this->parse($file), 'date');
			$sorted = $dates;
			rsort($sorted);
			self::assertSame($sorted, $dates, $file);
		}
	}

	/** A WAF page, a truncated body: nothing, never an exception — the pipeline reads [] as a failed read. */
	public function testAnUnreadableBodyIsNoItems(): void {
		self::assertSame([], $this->parser()->parse('<html><body>Access denied</body></html>', 'ISP test feed'));
		self::assertSame([], $this->parser()->parse(substr($this->feed('isp-anamed.xml'), 0, 4000), 'ISP test feed'));
		self::assertSame([], $this->parser()->parse($this->rss([]), 'ISP test feed'));
	}

	/** @return list<array<string, string>> */
	private function parse(string $file): array {
		return $this->parser()->parse($this->feed($file), 'ISP test feed');
	}

	private function feed(string $file): string {
		$xml = file_get_contents(__DIR__ . '/fixtures/' . $file);
		self::assertIsString($xml, "$file is missing from fixtures/");

		return $xml;
	}

	/**
	 * A synthetic feed in the ISP's shape — the post page as <link>, a +0000
	 * pubDate unless the item's own 'p' says otherwise.
	 *
	 * @param list<array{t: string, u: string, p?: string}> $items
	 */
	private function rss(array $items): string {
		$body = '';
		foreach ($items as $item) {
			$body .= sprintf(
				'<item><title>%s</title><link>%s</link><pubDate>%s</pubDate></item>',
				htmlspecialchars($item['t'], ENT_XML1),
				htmlspecialchars($item['u'], ENT_XML1),
				$item['p'] ?? 'Tue, 15 Sep 2026 12:00:00 +0000',
			);
		}

		return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>' . $body . '</channel></rss>';
	}

	/** PageParserTest's recorder, cut to the one level this class logs at. */
	private function parser(): FeedParser {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = ['message' => (string)$message, 'context' => $context];
			},
		);

		return new FeedParser($logger);
	}
}
