<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Notification;

use OCA\Epidemiologia\AppInfo\Application;
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
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Epidemiología');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
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

		return $notification
			->setParsedSubject($l->t('Epidemiología: no se pudo leer %s', [$what]))
			->setParsedMessage(trim($since . ' ' . $l->t(
				'La fuente cambió o no respondió, así que la lista mostrada puede estar '
				. 'incompleta. Se sigue mostrando la última copia buena. Revisa el registro de la '
				. 'instancia para ver qué falló.'
			)))
			// setIcon() rejects relative URLs. app-dark.svg is the unfilled variant —
			// the notification tray is a light surface, unlike the app menu.
			->setIcon($this->urls->getAbsoluteURL(
				$this->urls->imagePath(Application::APP_ID, 'app-dark.svg')
			))
			->setLink($this->urls->linkToRouteAbsolute('epidemiologia.page.index'));
	}
}
