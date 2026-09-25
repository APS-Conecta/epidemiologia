<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Daniel Espinoza Charrier
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * The app's first routes.php, and it carries exactly one route: the informe's
 * own address, /informe/{year}/{semana} (Spanish, because the person reading
 * it is the person opening it).
 *
 * An install that keeps a document serves it from the install (ADR-0015 §2),
 * so the address has to exist as a route before a kept row's href can name
 * it — IspReportRepository::wire() builds that href with linkToRoute, and
 * the name Nextcloud derives from 'name' => 'informe#show' below is
 * epidemiologia.informe.show, the string the reader's ROUTE const carries.
 *
 * Plain, not OCS: this is the pane's link, not the published contract, and
 * ADR-0006's one-OCS-route stands. Like the app's own panes it is there for
 * every logged-in user (NoAdminRequired on the controller) and for nobody
 * else — an influenza week's report is the clinic's material, not public.
 */

return [
	'routes' => [
		['name' => 'informe#show', 'url' => '/informe/{year}/{semana}', 'verb' => 'GET'],
	],
];

