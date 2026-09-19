<?php

declare(strict_types=1);

/**
 * Mount point. Everything the page shows comes from src/, and its data arrives
 * through IInitialState rather than through $_ — see PageController::index().
 */
?>
<div id="epidemiologia">
	<!-- aps-mount-loading stub — canonical fragment in MAPEO.md §5; Vue replaces the mount's children on boot. -->
	<div class="aps-mount-loading" role="status" aria-live="polite">Cargando…</div>
	<noscript><p class="aps-mount-noscript">Esta aplicación necesita JavaScript activado.</p></noscript>
</div>
