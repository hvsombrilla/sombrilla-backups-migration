<?php
/** @var array $info */
defined( 'ABSPATH' ) || exit;
?>
<section class="sbm-panel" data-panel="settings" role="tabpanel" hidden>
	<div class="sbm-grid">
		<form class="sbm-card" id="sbm-settings-form">
			<h3><?php esc_html_e( 'Ajustes', 'sombrilla-bm' ); ?></h3>

			<label class="sbm-field">
				<span><?php esc_html_e( 'Exclusiones (una por línea)', 'sombrilla-bm' ); ?></span>
				<textarea class="sbm-input" name="exclude" rows="8" spellcheck="false"></textarea>
				<small class="sbm-muted"><?php esc_html_e( 'Cada línea es una ruta relativa a wp-content que se excluye junto con todo su contenido (ej. uploads/cache). Con el prefijo "*/" se excluye ese nombre en cualquier nivel (ej. */node_modules).', 'sombrilla-bm' ); ?></small>
			</label>

			<label class="sbm-switch">
				<input type="checkbox" name="allow_private_urls">
				<span class="sbm-switch__track"></span>
				<span class="sbm-switch__label"><?php esc_html_e( 'Permitir descargar desde URLs de red local (localhost, IPs privadas)', 'sombrilla-bm' ); ?></span>
			</label>

			<div class="sbm-actions">
				<button type="submit" class="sbm-btn sbm-btn--primary"><?php esc_html_e( 'Guardar ajustes', 'sombrilla-bm' ); ?></button>
			</div>
		</form>

		<div class="sbm-card">
			<h3><?php esc_html_e( 'Entorno', 'sombrilla-bm' ); ?></h3>
			<dl class="sbm-dl">
				<dt>WordPress</dt><dd><?php echo esc_html( $info['wp'] ); ?></dd>
				<dt>PHP</dt><dd><?php echo esc_html( $info['php'] ); ?></dd>
				<dt><?php esc_html_e( 'Límite de memoria', 'sombrilla-bm' ); ?></dt><dd><?php echo esc_html( $info['memory'] ); ?></dd>
				<dt>max_execution_time</dt><dd><?php echo esc_html( $info['exec'] ? $info['exec'] . ' s' : '∞' ); ?></dd>
				<dt><?php esc_html_e( 'Subida máxima por petición', 'sombrilla-bm' ); ?></dt><dd><?php echo esc_html( $info['upload'] ); ?></dd>
				<dt><?php esc_html_e( 'Carpeta de backups', 'sombrilla-bm' ); ?></dt><dd><code><?php echo esc_html( $info['storage'] ); ?></code></dd>
			</dl>
			<p class="sbm-muted"><?php esc_html_e( 'La carpeta incluye protecciones para Apache e IIS. En Nginx, bloquea el acceso directo a /wp-content/sombrilla-bm/ en la configuración del servidor.', 'sombrilla-bm' ); ?></p>
		</div>
	</div>
</section>
