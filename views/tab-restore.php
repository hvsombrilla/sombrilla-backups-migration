<?php
defined( 'ABSPATH' ) || exit;
?>
<section class="sbm-panel" data-panel="restore" role="tabpanel" hidden>
	<div class="sbm-grid">
		<div class="sbm-card">
			<h3><?php esc_html_e( 'Subir un archivo de backup', 'sombrilla-bm' ); ?></h3>
			<p class="sbm-muted"><?php esc_html_e( 'Archivo .sombrilla generado con este plugin. Se sube por fragmentos, sin importar el límite de subida de PHP.', 'sombrilla-bm' ); ?></p>
			<label class="sbm-drop" id="sbm-drop" tabindex="0">
				<input type="file" id="sbm-file" accept=".sombrilla" hidden>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
				<strong><?php esc_html_e( 'Arrastra el archivo aquí', 'sombrilla-bm' ); ?></strong>
				<span><?php esc_html_e( 'o haz clic para seleccionarlo', 'sombrilla-bm' ); ?></span>
			</label>
		</div>

		<div class="sbm-card">
			<h3><?php esc_html_e( 'Restaurar desde una URL', 'sombrilla-bm' ); ?></h3>
			<p class="sbm-muted"><?php esc_html_e( 'Pega el enlace de descarga de un backup .sombrilla de otro sitio. Se descarga directamente en este servidor.', 'sombrilla-bm' ); ?></p>
			<form class="sbm-url-form" id="sbm-url-form">
				<input type="url" id="sbm-url" class="sbm-input" placeholder="https://sitio-origen.com/…/backup.sombrilla" required>
				<button type="submit" class="sbm-btn sbm-btn--primary"><?php esc_html_e( 'Descargar', 'sombrilla-bm' ); ?></button>
			</form>
		</div>
	</div>

	<div class="sbm-card sbm-note">
		<div class="sbm-card__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
		<div class="sbm-card__body">
			<h4><?php esc_html_e( 'Migración entre dominios', 'sombrilla-bm' ); ?></h4>
			<p class="sbm-muted"><?php esc_html_e( 'Si el backup viene de otro dominio, las URLs (home y siteurl, contenido, metadatos, Elementor, Gutenberg y datos serializados) se reemplazan automáticamente al terminar la restauración.', 'sombrilla-bm' ); ?></p>
		</div>
	</div>
</section>
