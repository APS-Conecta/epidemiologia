<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Tests\Unit;

use OCA\Epidemiologia\Service\CertificateBundle;
use OCA\Epidemiologia\Service\IspReportRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The informe fuente, now published by the ISP.
 *
 * `fixtures/isp-informes.html` is a real captured response, per this repo's standing
 * rule — synthetic markup would only prove the parser can read markup we wrote for it.
 * What it proves here is specific: the ISP's page carries SEVEN PDF anchors and six of
 * them satisfy the upload-folder rule the MINSAL parser uses, one being the ISP's own
 * code of ethics. Only the anchor label tells the weekly informe from the rest, which
 * is why the second test below is the one that matters.
 */
class IspReportRepositoryTest extends TestCase {
	use Doubles;

	/** 2026-09-15T12:00:00Z. Fixed, because every freshness assertion is relative to it. */
	private const NOW = 1789473600;

	/**
	 * Past FRESH_FOR (one hour), so a second reader in the same test genuinely refreshes
	 * instead of being served the copy the first one left behind.
	 */
	private const STALE = 7200;

	/** The in-memory distributed cache, shared by every repository one test builds. */
	private array $store = [];

	/** @var list<array<string, mixed>> the options of every request made, in order */
	private array $requested = [];

	/** @var list<array{level: string, message: string}> what the repository logged */
	private array $logged = [];

	protected function setUp(): void {
		$this->store = [];
		$this->requested = [];
		$this->logged = [];
	}

	// --- parsing: the label is the discriminator -----------------------------

	public function testItKeepsOnlyTheWeeklyInforme(): void {
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'));

		$items = $repo->get()['items'];

		self::assertNotSame([], $items, 'empty fixture would make every assertion below vacuous');
		foreach ($items as $item) {
			self::assertMatchesRegularExpression('/^\d{1,2}$/', $item['se']);
		}
		$titles = implode(' | ', array_column($items, 'title'));
		// Six of the page's seven PDF anchors satisfy the /uploads/YYYY/MM/ rule,
		// including the ISP's code of ethics. An informe pane must contain none of them.
		self::assertStringNotContainsString('Ética', $titles);
		self::assertStringNotContainsString('Genotipificación', $titles);
		self::assertStringNotContainsString('RESISTENCIA', $titles);
	}

	/**
	 * The captured page must keep carrying the thing the filter exists for. Without
	 * this, a re-capture of a page the ISP had tidied would leave the test above
	 * passing for free — it would be asserting that six anchors it no longer has are
	 * absent.
	 */
	public function testTheFixtureStillCarriesTheSixAnchorsTheFilterHasToReject(): void {
		$html = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html');

		self::assertNotSame('', $html, 'the fixture is empty — every assertion here would be vacuous');
		self::assertSame(
			7,
			preg_match_all('#<a[^>]+href="[^"]+\.pdf"#i', $html),
			'the captured page no longer matches what was measured',
		);
		self::assertSame(
			6,
			preg_match_all('#href="[^"]*/uploads/\d{4}/\d{2}/[^"]+\.pdf"#i', $html),
			'six anchors satisfying the upload-folder rule is what makes the label filter necessary',
		);
	}

	/**
	 * `title` and `url` are the publisher's own bytes: the informe's filename carries a
	 * version suffix (`…SE35-08-09-2026-V3`) that cannot be derived from a week number,
	 * so an anchor is read and never constructed.
	 */
	public function testTheInformeCarriesTheAnchorItWasReadFrom(): void {
		$items = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'))->get()['items'];

		self::assertCount(1, $items);
		self::assertSame('35', $items[0]['se']);
		self::assertStringContainsString('-V3.pdf', $items[0]['url']);
		self::assertSame('2026', $items[0]['year']);
		self::assertSame('2026-09', $items[0]['month'], 'the pane prints the month beside the semana');
	}

	// --- accumulation: the page only ever shows the current week --------------

	public function testItAccumulatesInformesAcrossWeeks(): void {
		$week35 = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html');
		$week36 = str_replace(['Semana 35', 'SE35'], ['Semana 36', 'SE36'], $week35);

		$repo = $this->repository($week35);
		$repo->get();
		$later = $this->repository($week36, reuseCache: true);

		$ses = array_column($later->get()['items'], 'se');
		self::assertContains('35', $ses);
		self::assertContains('36', $ses, 'the new semana must be added');
		self::assertSame(array_unique($ses), $ses, 'no semana may appear twice');
	}

	/**
	 * The reason the accumulation is keyed by semana rather than by url: the ISP
	 * reissues a week under a new version suffix, and two rows for semana 35 are a
	 * duplicate the pane — which prints the semana and the month, not the filename —
	 * cannot tell apart.
	 */
	public function testARepublishedWeekReplacesTheOneItSupersedes(): void {
		$v3 = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html');
		$v4 = str_replace('-V3.pdf', '-V4.pdf', $v3);

		$this->repository($v3)->get();
		$items = $this->repository($v4, reuseCache: true)->get()['items'];

		self::assertCount(1, $items, 'a reissue is the same informe, not a second one');
		self::assertStringContainsString('-V4.pdf', $items[0]['url'], 'the reissue is what a clinician must open');
	}

	/**
	 * `Semana 05` and `Semana 5` are one week typed two ways by the same WordPress
	 * editor the `/u` flag defends against, and the accumulation keys on those digits:
	 * PHP keeps '05' a string key and turns '5' into int 5, so a republish that dropped
	 * the zero wrote a SECOND row — and ReportPane prints `se` verbatim, so a clinician
	 * saw "Semana 05" and "Semana 5" as two different weeks.
	 */
	public function testAZeroPaddedSemanaIsTheSameWeekAsAnUnpaddedOne(): void {
		$page = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html');
		$padded = str_replace('Semana 35', 'Semana 05', $page);
		$bare = str_replace(['Semana 35', '-V3.pdf'], ['Semana 5', '-V4.pdf'], $page);

		$this->repository($padded)->get();
		$items = $this->repository($bare, reuseCache: true)->get()['items'];

		self::assertCount(1, $items, 'the same week, typed twice, is not two informes');
		self::assertSame('5', $items[0]['se'], 'the semana is stored canonically, not as typed');
	}

	/**
	 * Newest first by the month the informe was filed under, THEN by semana — not by
	 * semana alone. Semanas restart every January, so sorting on the number alone would
	 * put the current informe at the bottom of the pane for the whole first month of a
	 * year, and `InicioPane` shows the first three rows of this list.
	 */
	public function testTheNewestInformeComesFirstAcrossAYearBoundary(): void {
		$week52 = str_replace(
			['Semana 35', 'SE35', '/uploads/2026/09/'],
			['Semana 52', 'SE52', '/uploads/2026/12/'],
			(string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'),
		);
		$week01 = str_replace(
			['Semana 35', 'SE35', '/uploads/2026/09/'],
			['Semana 1', 'SE01', '/uploads/2027/01/'],
			(string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'),
		);

		$this->repository($week52)->get();
		$items = $this->repository($week01, reuseCache: true)->get()['items'];

		self::assertSame(['1', '52'], array_column($items, 'se'));
	}

	/**
	 * The other half of keeping an informe we could not file: it must not sink.
	 *
	 * parse() keeps an informe published outside /uploads/YYYY/MM/, because the semana is
	 * its identity — and the ISP already serves a PDF on this page from a second path
	 * convention, so this is a shape the page has. Ordering by month alone put that
	 * informe LAST, since '' is below every real month: the week just read fell to the
	 * bottom of the pane and off InicioPane's three-row card, which is exactly the
	 * outcome keeping it was meant to avoid.
	 */
	public function testAnInformeFiledOutsideTheUploadFolderIsNotPushedToTheBottom(): void {
		$filed = (string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html');
		$unfiled = str_replace(
			['Semana 35', '/wp-content/uploads/2026/09/Informe'],
			['Semana 36', '/sites/default/files/documento/2026/09/Informe'],
			$filed,
		);

		$this->repository($filed)->get();
		$items = $this->repository($unfiled, reuseCache: true)->get()['items'];

		self::assertSame('', $items[0]['month'], 'precondition: the newer informe carries no month');
		self::assertSame(['36', '35'], array_column($items, 'se'), 'the informe just read must come first');
	}

	// --- failure: zero items is a failure, never a result ---------------------

	public function testAPageThatYieldsNoInformeDegradesAndKeepsTheLastGoodCopy(): void {
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'));
		$good = $repo->get()['items'];

		$broken = $this->repository('<html><body>nothing here</body></html>', reuseCache: true);
		$result = $broken->get();

		self::assertTrue($result['degraded']);
		self::assertSame($good, $result['items'], 'a failed read must never replace the last good copy');
	}

	/**
	 * "Could not be read" and "was read and carried no informe" are different faults and
	 * the log has to name which. MinsalRepository carries the long version of this
	 * reasoning: under a WAF that answers 403 with a page, the two are byte-identical in
	 * the payload — both degrade, both serve the last good copy — and the sentence is the
	 * whole of an admin's diagnosis.
	 */
	public function testAPageThatCouldNotBeReadIsNotReportedAsOneThatCarriedNoInforme(): void {
		$result = $this->repository('', offline: true)->get();

		self::assertTrue($result['degraded']);
		self::assertStringNotContainsStringIgnoringCase(
			'no informe',
			implode(' | ', $this->loggedAt('warning')),
			'the ISP was never reached — saying its page carried no informe names the wrong fault',
		);
	}

	public function testWithNothingCachedAndTheIspDownItSaysDegradedRatherThanEmpty(): void {
		$result = $this->repository('', offline: true)->get();

		self::assertTrue($result['degraded']);
		self::assertSame([], $result['items']);
	}

	// --- lifecycle ------------------------------------------------------------

	public function testServesTheCacheWithoutRefetchingWhileItIsFresh(): void {
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'));
		$repo->get();
		$repo->get();

		self::assertCount(1, $this->requested, 'a fresh cache must not be refetched');
	}

	/**
	 * Degrading writes nothing, so the entry stays stale and every read would retry.
	 * `PageController::index()` fetches synchronously while someone waits, so against a
	 * host that drops packets rather than refusing them that is a gateway timeout instead
	 * of the degraded banner.
	 */
	public function testAnOutageIsNotRetriedOnEveryRead(): void {
		$this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'))->get();
		$this->repository('', reuseCache: true, offline: true)->get();
		$this->requested = [];

		$this->repository('', reuseCache: true, offline: true, now: self::NOW + self::STALE + 60)->get();

		self::assertSame([], $this->requested, 'the outage was retried instead of being remembered');
	}

	public function testTheCooldownExpiresAndTheIspIsAskedAgain(): void {
		$this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'))->get();
		$this->repository('', reuseCache: true, offline: true)->get();
		$this->requested = [];

		$this->repository('', reuseCache: true, offline: true, now: self::NOW + self::STALE + 301)->get();

		self::assertNotSame([], $this->requested, 'past COOLDOWN the page must be asked again');
	}

	/**
	 * The sondeo asks through the freshness window, not around it: it exists to DETECT,
	 * and reusing the reader's rule is what made a week-long ISP outage report itself as
	 * silence (#75, and `IspRepository::probe()` carries the same correction).
	 */
	public function testTheProbeAsksEvenWhenTheCachedCopyIsStillFresh(): void {
		$repo = $this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'));
		$repo->get();
		$this->requested = [];

		self::assertTrue($repo->probe());
		self::assertCount(1, $this->requested, 'a detector that asks nothing detects nothing');
	}

	public function testTheProbeReportsFailureWhenTheIspStopsParsing(): void {
		self::assertFalse($this->repository('<html><body>en mantención</body></html>')->probe());
	}

	public function testTheProbeReportsFailureWhenTheIspIsUnreachable(): void {
		self::assertFalse($this->repository('', offline: true)->probe());
	}

	// --- trust boundary: the ISP's HTML is not ours ---------------------------

	/**
	 * `p()` is htmlspecialchars: it escapes quotes and angle brackets and cannot make a
	 * URL scheme safe. A defaced page serving a label this parser accepts would otherwise
	 * reach href byte for byte — in the pane, in the OCS payload and in the initial state.
	 */
	public function testRejectsAnInformeAnchorThatIsNotHttps(): void {
		$hostile = '<a href="javascript:alert(1)/uploads/2026/09/x.pdf">Descargar Informe Semana 35 en formato PDF</a>';

		$result = $this->repository($hostile)->get();

		self::assertSame([], $result['items'], 'only https may reach an href');
		self::assertTrue($result['degraded'], 'a page that parses to nothing never overwrites a good copy');
	}

	/**
	 * `www.ispch.gob.cl` serves its leaf certificate and nothing else, so an instance
	 * that verifies against its own anchors alone fails every request with `cURL error
	 * 60`. `CertificateBundle` merges the missing intermediate in; this asserts the
	 * request actually carries it, which is the difference between a fresh install that
	 * works and one that reports a permanent outage.
	 *
	 * Asserted on the option, never by relaxing it: `verify => false` is forbidden in
	 * this repo, and a test that accepted it would be the way it arrived.
	 */
	public function testEveryRequestVerifiesAgainstTheMergedCaBundle(): void {
		$this->repository((string)file_get_contents(__DIR__ . '/fixtures/isp-informes.html'))->get();

		self::assertNotSame([], $this->requested, 'precondition: something was actually requested');
		foreach ($this->requested as $options) {
			self::assertSame(self::BUNDLE, $options['verify'] ?? null);
		}
	}

	// --- doubles --------------------------------------------------------------

	/**
	 * The path the CertificateBundle double answers with. Never read from disk — the
	 * mocked client below answers regardless — so it only has to be recognisable.
	 */
	private const BUNDLE = '/fake/ca-bundle.pem';

	/**
	 * @param string $body       what the ISP's page answers with
	 * @param bool   $reuseCache this reader arrives after the stored copy went stale,
	 *                           against the same in-memory cache the previous one wrote.
	 *                           Both halves are the point: `$this->store` lives for the
	 *                           whole test, so without moving the clock a second
	 *                           repository would serve the first's still-fresh copy
	 *                           unread and never exercise the accumulation at all.
	 * @param bool   $offline    the host cannot be reached — a TLS fault, a refused
	 *                           connection, a timeout; all of them arrive as a throw
	 * @param int|null $now      an explicit clock, for the cooldown's own expiry
	 */
	private function repository(
		string $body,
		bool $reuseCache = false,
		bool $offline = false,
		?int $now = null,
	): IspReportRepository {
		$now ??= self::NOW + ($reuseCache ? self::STALE : 0);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $offline): IResponse {
				$this->requested[] = $options;

				if ($offline) {
					throw new RuntimeException('cURL error 60: SSL certificate problem');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body);

				return $response;
			},
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache($now));

		$bundle = $this->createMock(CertificateBundle::class);
		$bundle->method('path')->willReturn(self::BUNDLE);

		return new IspReportRepository($clients, $bundle, $time, $this->logger(), $cacheFactory);
	}

	/**
	 * A logger that records instead of discarding. One failure path is only observable
	 * as a log line — see testAPageThatCouldNotBeReadIsNotReportedAsOneThatCarriedNoInforme.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => (string)$message];
				},
			);
		}

		return $logger;
	}

	/** Every message logged at $level, for an assertion that names the sentence. */
	private function loggedAt(string $level): array {
		return array_values(array_map(
			static fn (array $l): string => $l['message'],
			array_filter($this->logged, static fn (array $l): bool => $l['level'] === $level),
		));
	}
}
