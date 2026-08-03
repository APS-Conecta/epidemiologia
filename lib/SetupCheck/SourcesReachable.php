<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\SetupCheck;

use OCA\Epidemiologia\BackgroundJob\ProbeJob;
use OCA\Epidemiologia\Service\Sources;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Shows in Administración → Visión general whether this instance is still reaching
 * the sources it publishes.
 *
 * The bell and this row carry different things on purpose. A notification is good
 * at a *transition* — "we just lost MINSAL" — and bad at a state that persists for
 * weeks, which is why ProbeJob has to back off. A status row is the opposite: it
 * cannot spam and never needs deduplicating, but nobody is told, they have to come
 * and look. So both exist, and both read the same counter.
 *
 * It never calls out. SetupCheckManager::runAll() invokes every run() inline while
 * an admin waits for the page, so a check that phoned a foreign government server
 * would hang Visión general for as long as that server felt like taking. The
 * measurement already exists — ProbeJob asks once a day — and repeating it during
 * a page render is the same duplicated work in a hot path that
 * MinsalRepository::search() already refuses to do.
 *
 * It was called MinsalReachable and looped over MINSAL's two feeds by name, which
 * is why an ISP outage left this row green while ProbeJob was counting the failure
 * next door. It now enumerates from Sources::ALL, so it cannot fall behind the job
 * that writes what it reads.
 */
class SourcesReachable implements ISetupCheck {
	public function __construct(
		private IAppConfig $config,
		private Sources $sources,
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Epidemiología: conexión con las fuentes');
	}

	#[\Override]
	public function run(): SetupResult {
		$broken = [];
		foreach (Sources::ALL as $source) {
			if ($this->config->getAppValueInt(ProbeJob::FAILURES . $source) > 0) {
				$broken[] = $this->describe($source);
			}
		}

		if ($broken === []) {
			return SetupResult::success(
				$this->l->t('El último sondeo diario leyó todas las fuentes sin problemas.')
			);
		}

		// warning, not error: a source being unreachable degrades this app — the last
		// good copy is still served, with a banner — but it breaks nothing else.
		return SetupResult::warning(implode(' ', $broken) . ' ' . $this->l->t(
			'Se sigue mostrando la última copia buena. Revisa el registro de la instancia.'
		));
	}

	private function describe(string $source): string {
		$what = $this->sources->name($source, $this->l);
		$since = $this->config->getAppValueInt(ProbeJob::FAILING_SINCE . $source);

		if ($since === 0) {
			return $this->l->t('No se pudo leer %s.', [$what]);
		}

		// The date is the point of this row: a bell says "it broke", a status row is
		// the only place that can say "and it has been broken since the 14th".
		return $this->l->t('No se pudo leer %1$s desde el %2$s.', [
			$what,
			$this->l->l('date', new \DateTime('@' . $since), ['width' => 'medium']),
		]);
	}
}
