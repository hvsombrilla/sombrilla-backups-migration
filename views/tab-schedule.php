<?php
defined( 'ABSPATH' ) || exit;
?>
<section class="sbm-panel" data-panel="schedule" role="tabpanel" hidden>
	<form class="sbm-card" id="sbm-schedule-form">
		<div class="sbm-card__head">
			<div>
				<h3><?php esc_html_e( 'Backups programados', 'sombrilla-bm' ); ?></h3>
				<p class="sbm-muted" id="sbm-next-run"></p>
			</div>
			<label class="sbm-switch">
				<input type="checkbox" name="enabled" id="sbm-sch-enabled">
				<span class="sbm-switch__track"></span>
				<span class="sbm-switch__label"><?php esc_html_e( 'Activar', 'sombrilla-bm' ); ?></span>
			</label>
		</div>

		<div class="sbm-fields">
			<label class="sbm-field">
				<span><?php esc_html_e( 'Frecuencia', 'sombrilla-bm' ); ?></span>
				<select class="sbm-input" name="frequency">
					<option value="daily"><?php esc_html_e( 'Diaria', 'sombrilla-bm' ); ?></option>
					<option value="weekly"><?php esc_html_e( 'Semanal', 'sombrilla-bm' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Mensual', 'sombrilla-bm' ); ?></option>
				</select>
			</label>
			<label class="sbm-field">
				<span><?php esc_html_e( 'Hora (zona horaria del sitio)', 'sombrilla-bm' ); ?></span>
				<input class="sbm-input" type="time" name="time" required>
			</label>
			<label class="sbm-field">
				<span><?php esc_html_e( 'Conservar los últimos', 'sombrilla-bm' ); ?></span>
				<input class="sbm-input" type="number" name="retention" min="1" max="100" required>
			</label>
		</div>

		<fieldset class="sbm-checks" id="sbm-sch-scope">
			<legend><?php esc_html_e( 'Qué incluir', 'sombrilla-bm' ); ?></legend>
		</fieldset>

		<p class="sbm-muted"><?php esc_html_e( 'Los backups programados usan WP-Cron: se ejecutan cuando el sitio recibe visitas. Para mayor precisión configura un cron real del servidor que llame a wp-cron.php.', 'sombrilla-bm' ); ?></p>

		<div class="sbm-actions">
			<button type="submit" class="sbm-btn sbm-btn--primary"><?php esc_html_e( 'Guardar programación', 'sombrilla-bm' ); ?></button>
		</div>
	</form>
</section>
