<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epidemiologia\Notification;

use OCA\Epidemiologia\AppInfo\AppId;
use OCA\Epidemiologia\Service\Sources;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the probe job's notification. Nextcloud drops notifications from an app
 * that has no notifier, so this is what makes ProbeJob's detection visible — and a
 * detection nobody sees is not a detection.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private Sources $sources,
	) {
	}

	#[\Override]
	public function getID(): string {
		return AppId::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(AppId::APP_ID)->t('Epidemiología');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppId::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(AppId::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$days = (int)($params['days'] ?? 0);
		$source = (string)($params['source'] ?? '');

		// A row this notifier cannot render is refused, not guessed at. Rows written
		// by 0.2.0 carry the old `feed` key and reach here with no source at all, and
		// Sources::name() deliberately has no default arm — without this guard the
		// first such row would throw UnhandledMatchError inside the notifications
		// endpoint. Throwing here is the documented way out: Nextcloud drops the row.
		if (!in_array($source, Sources::ALL, true)) {
			throw new UnknownNotificationException();
		}

		// The name comes from the catalogue, institution included. It used to be a
		// ternary on 'irag' here, which meant every source that was not the informes
		// rendered as "las alertas epidemiológicas vigentes ... desde MINSAL" — so an
		// ISP outage sent an administrator to inspect a healthy MINSAL page.
		$what = $this->sources->name($source, $l);

		// How long it has been failing is the whole reason ProbeJob backs off rather
		// than filing this daily: the second and the thirtieth day are different news.
		$since = $days > 0
			? $l->n('Lleva %n día sin poder leerse.', 'Lleva %n días sin poder leerse.', $days)
			: '';

		// A host's outage rings once (ProbeJob::ring()), under its first failing
		// fuente — so the bell names the others that failed with it, each the way
		// the catalogue names it, or "the informe could not be read" would hide
		// three ISP alert feeds down beside it. An id the catalogue no longer has
		// is skipped rather than refused: the row outlives renames, and the rest
		// of it still says something true.
		$with = array_values(array_filter(
			array_map(strval(...), (array)($params['with'] ?? [])),
			static fn (string $id): bool => in_array($id, Sources::ALL, true),
		));
		$also = $with === []
			? ''
			: $l->t('En el mismo servidor tampoco se pudo leer: %s.', [
				implode('; ', array_map(fn (string $id): string => $this->sources->name($id, $l), $with)),
			]);

		return $notification
			->setParsedSubject($l->t('Epidemiología: no se pudo leer %s', [$what]))
			->setParsedMessage(trim($since . ' ' . $l->t(
				'La fuente cambió o no respondió, así que la lista mostrada puede estar '
				. 'incompleta. Se sigue mostrando la última copia buena. Revisa el registro de la '
				. 'instancia para ver qué falló.'
			) . ' ' . $also))
			// setIcon() rejects relative URLs. app-dark.svg is the unfilled variant —
			// the notification tray is a light surface, unlike the app menu.
			->setIcon($this->urls->getAbsoluteURL(
				$this->urls->imagePath(AppId::APP_ID, 'app-dark.svg')
			))
			->setLink($this->urls->linkToRouteAbsolute('epidemiologia.page.index'));
	}
}
