<?php
defined( 'ABSPATH' ) || exit;
?>
<section class="sbm-panel is-active" data-panel="backups" role="tabpanel">
	<div class="sbm-card sbm-card--cta">
		<div class="sbm-card__icon sbm-card__icon--primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
		<div class="sbm-card__body">
			<h3><?php esc_html_e( 'Crear un backup completo', 'sombrilla-bm' ); ?></h3>
			<p><?php esc_html_e( 'Incluye la base de datos y todo wp-content (uploads, plugins, themes y más) en un único archivo .sombrilla. Funciona con sitios de varios GB.', 'sombrilla-bm' ); ?></p>
		</div>
		<button type="button" class="sbm-btn sbm-btn--primary sbm-btn--lg" id="sbm-create-backup"><?php esc_html_e( 'Crear backup', 'sombrilla-bm' ); ?></button>
	</div>

	<div class="sbm-card">
		<div class="sbm-card__head">
			<h3><?php esc_html_e( 'Backups guardados', 'sombrilla-bm' ); ?></h3>
			<button type="button" class="sbm-btn sbm-btn--ghost" id="sbm-refresh"><?php esc_html_e( 'Actualizar', 'sombrilla-bm' ); ?></button>
		</div>
		<div id="sbm-backup-list" class="sbm-list"></div>
	</div>
</section>
