<?php

declare(strict_types=1);

namespace OCA\Epidemiologia\Notification;

use OCA\Epidemiologia\AppInfo\Application;
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
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Epidemiología');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$feed = $notification->getSubjectParameters()['feed'] ?? '';

		$what = $feed === 'irag'
			? $l->t('los informes semanales ETI/IRAG')
			: $l->t('las alertas epidemiológicas vigentes');

		return $notification
			->setParsedSubject($l->t('Epidemiología: no se pudo leer %s desde MINSAL', [$what]))
			->setParsedMessage($l->t(
				'La página de MINSAL cambió o no respondió, así que la lista mostrada puede estar '
				. 'incompleta. Se sigue mostrando la última copia buena. Revisa el registro de la '
				. 'instancia para ver qué falló.'
			))
			->setLink($this->urls->linkToRouteAbsolute('epidemiologia.page.index'));
	}
}
