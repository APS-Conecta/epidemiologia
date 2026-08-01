<?php

declare(strict_types=1);

/**
 * Server-rendered on purpose: the whole page is a list, a card, an iframe and a
 * link. There is nothing here a build step would earn — js/main.js only switches
 * tabs and filters rows that are already in the DOM.
 *
 * @var array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool} $_['alerts']
 * @var array{items: list<array<string, string>>, updated_at: ?string, fetched_at: ?int, degraded: bool} $_['reports']
 * @var string $_['tablero']
 */

script('epidemiologia', 'main');
style('epidemiologia', 'main');

$alerts = $_['alerts'];
$reports = $_['reports'];
$latest = $reports['items'][0] ?? null;

$years = array_values(array_unique(array_column($alerts['items'], 'year')));
rsort($years);

/** "hace 3 horas" — staleness is always on screen, never hidden. */
$ago = static function (?int $then): string {
	if ($then === null) {
		return 'sin datos todavía';
	}
	$seconds = max(0, time() - $then);
	if ($seconds < 3600) {
		return 'hace ' . max(1, intdiv($seconds, 60)) . ' min';
	}
	if ($seconds < 86400) {
		return 'hace ' . intdiv($seconds, 3600) . ' h';
	}

	return 'hace ' . intdiv($seconds, 86400) . ' días';
};

/** The banner both feeds share when MINSAL could not be reached. */
$degraded = static function (array $feed): void {
	if (!$feed['degraded']) {
		return;
	} ?>
	<div class="epi-aviso" role="status">
		<span aria-hidden="true">⚠</span>
		<div>
			<strong>Problemas técnicos.</strong> No hemos podido contactar a MINSAL, así que esta
			lista puede estar incompleta.
			<a href="https://epi.minsal.cl/" target="_blank" rel="noopener">Ver en epi.minsal.cl ↗</a>
		</div>
	</div>
	<?php
};
?>

<div id="app-navigation">
	<ul role="tablist" aria-label="Secciones de Epidemiología">
		<li><button class="epi-tab" role="tab" id="t-alertas" aria-controls="p-alertas" aria-selected="true">
			Alertas vigentes <span class="epi-count"><?php p(count($alerts['items'])); ?></span>
		</button></li>
		<li><button class="epi-tab" role="tab" id="t-informe" aria-controls="p-informe" aria-selected="false">
			Informe semanal
		</button></li>
		<li><button class="epi-tab" role="tab" id="t-tablero" aria-controls="p-tablero" aria-selected="false">
			Tablero MINSAL
		</button></li>
		<li><button class="epi-tab" role="tab" id="t-epivigila" aria-controls="p-epivigila" aria-selected="false">
			EPIVIGILA
		</button></li>
	</ul>
</div>

<div id="app-content">
	<div id="app-content-wrapper" class="epi">

		<section class="epi-pane" id="p-alertas" role="tabpanel" aria-labelledby="t-alertas">
			<h2>Alertas epidemiológicas vigentes</h2>
			<p class="epi-sub">Fuente: Departamento de Epidemiología, MINSAL · actualizado
				<?php p($ago($alerts['fetched_at'])); ?></p>

			<?php $degraded($alerts); ?>

			<div class="epi-filtros">
				<input type="search" id="epi-q" placeholder="Buscar por título — sarampión, hantavirus, coqueluche…"
					   aria-label="Buscar alertas por título" autocomplete="off">
				<select id="epi-anio" aria-label="Filtrar por año">
					<option value="">Todos los años</option>
					<?php foreach ($years as $year) { ?>
						<option value="<?php p($year); ?>"><?php p($year); ?></option>
					<?php } ?>
				</select>
			</div>
			<p class="epi-conteo" id="epi-conteo"
			   data-total="<?php p(count($alerts['items'])); ?>"><?php p(count($alerts['items'])); ?> alertas vigentes</p>

			<ul class="epi-lista" id="epi-alertas">
				<?php foreach ($alerts['items'] as $alert) { ?>
					<li data-year="<?php p($alert['year']); ?>">
						<a href="<?php p($alert['url']); ?>" target="_blank" rel="noopener">
							<?php p($alert['title']); ?></a>
						<span class="epi-anio-chip"><?php p($alert['year']); ?></span>
					</li>
				<?php } ?>
			</ul>
		</section>

		<section class="epi-pane" id="p-informe" role="tabpanel" aria-labelledby="t-informe" hidden>
			<h2>Informe semanal de vigilancia ETI e IRAG</h2>
			<p class="epi-sub">Fuente: Departamento de Epidemiología, MINSAL · actualizado
				<?php p($ago($reports['fetched_at'])); ?></p>

			<?php $degraded($reports); ?>

			<?php if ($latest !== null) { ?>
				<div class="epi-informe">
					<div class="epi-se"><small>Semana epi.</small><?php p($latest['se'] ?: '—'); ?></div>
					<div class="epi-informe-txt">
						<strong><?php p($latest['title']); ?></strong>
						<a class="epi-btn epi-btn-claro" href="<?php p($latest['url']); ?>"
						   target="_blank" rel="noopener">Abrir el informe (PDF) ↗</a>
					</div>
				</div>

				<details class="epi-archivo">
					<summary>Semanas anteriores de este año
						(<?php p(max(0, count($reports['items']) - 1)); ?>)</summary>
					<ul class="epi-lista">
						<?php foreach (array_slice($reports['items'], 1) as $report) { ?>
							<li><a href="<?php p($report['url']); ?>" target="_blank" rel="noopener">
								<?php p($report['title']); ?></a></li>
						<?php } ?>
					</ul>
				</details>
			<?php } else { ?>
				<p class="epi-sub">No hay informes disponibles en este momento.</p>
			<?php } ?>
		</section>

		<section class="epi-pane" id="p-tablero" role="tabpanel" aria-labelledby="t-tablero" hidden>
			<h2>Tablero MINSAL · ETI e IRAG</h2>
			<p class="epi-sub">El tablero oficial, incrustado. Lo mantiene MINSAL, no nosotros ·
				tarda unos segundos en cargar · <strong>Total País</strong> y cada región se eligen
				dentro del tablero.</p>

			<!-- src is set by js/main.js when this tab is first opened: the report takes
			     ~15-20 s to paint and nobody reading the alert list should wait for it. -->
			<iframe class="epi-frame" title="Situación Epidemiológica Vigilancia ETI e IRAG"
					data-src="<?php p($_['tablero']); ?>" loading="lazy"></iframe>
		</section>

		<section class="epi-pane" id="p-epivigila" role="tabpanel" aria-labelledby="t-epivigila" hidden>
			<h2>EPIVIGILA · Notificación de ENO</h2>
			<p class="epi-sub">Un enlace al sistema oficial. No se incrusta, y aquí no se guarda nada.</p>

			<div class="epi-qes">
				<strong>Qué es.</strong> La plataforma del MINSAL donde se notifican las Enfermedades
				de Notificación Obligatoria. Cada profesional entra con su <strong>clave única
				personal</strong>, y los datos de pacientes viven allá — nunca en esta intranet.
			</div>

			<div class="epi-enlaces">
				<a class="epi-btn" href="https://epivigila.minsal.cl/" target="_blank" rel="noopener">
					Abrir EPIVIGILA ↗</a>
				<a class="epi-btn epi-btn-claro"
				   href="https://epi.minsal.cl/formularios-de-notificacion-obligatoria/"
				   target="_blank" rel="noopener">Formularios PDF de la SEREMI ↗</a>
			</div>

			<div class="epi-aviso">
				<span aria-hidden="true">⚠</span>
				<div>
					<strong>Acceso restringido.</strong> El MINSAL mantiene EPIVIGILA en funcionamiento
					excepcional: solo ingresan los establecimientos públicos con conexión a
					<strong>Red MINSAL</strong>. Si no puedes entrar, notifica con los formularios PDF
					a la SEREMI de tu región.
					<div class="epi-fuente">Fuente: Colegio Médico de Chile, 1 de julio de 2026 ·
						verificado el 1 de agosto de 2026</div>
				</div>
			</div>

			<p class="epi-fuente">Por qué no va incrustado: EPIVIGILA es un formulario de acceso con
				credenciales. Mostrarlo dentro de esta intranet le enseñaría al personal a escribir su
				clave del MINSAL en una ventana que no es del MINSAL.</p>
		</section>

	</div>
</div>
