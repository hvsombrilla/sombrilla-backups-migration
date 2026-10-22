<?php
/** @var array $info */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap sbm" id="sbm-app">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Sombrilla Backups & Migration', 'sombrilla-bm' ); ?></h1>

	<header class="sbm-hero">
		<div class="sbm-hero__brand">
			<span class="sbm-hero__logo" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/></svg>
			</span>
			<div>
				<h2><?php esc_html_e( 'Sombrilla Backups & Migration', 'sombrilla-bm' ); ?></h2>
				<p><?php esc_html_e( 'Respalda, restaura y migra tu sitio completo con un solo archivo.', 'sombrilla-bm' ); ?></p>
			</div>
		</div>
		<div class="sbm-hero__stats" id="sbm-stats"></div>
	</header>

	<nav class="sbm-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Secciones', 'sombrilla-bm' ); ?>">
		<button type="button" role="tab" class="sbm-tab is-active" data-tab="backups"><?php esc_html_e( 'Backups', 'sombrilla-bm' ); ?></button>
		<button type="button" role="tab" class="sbm-tab" data-tab="restore"><?php esc_html_e( 'Restaurar / Migrar', 'sombrilla-bm' ); ?></button>
		<button type="button" role="tab" class="sbm-tab" data-tab="schedule"><?php esc_html_e( 'Programación', 'sombrilla-bm' ); ?></button>
		<button type="button" role="tab" class="sbm-tab" data-tab="settings"><?php esc_html_e( 'Ajustes', 'sombrilla-bm' ); ?></button>
	</nav>

	<?php if ( ! $info['x64'] ) : ?>
		<div class="sbm-alert sbm-alert--warn"><?php esc_html_e( 'Este servidor usa PHP de 32 bits: los backups de más de 2 GB no funcionarán.', 'sombrilla-bm' ); ?></div>
	<?php endif; ?>

	<main class="sbm-main">
		<?php
		include SBM_DIR . 'views/tab-backups.php';
		include SBM_DIR . 'views/tab-restore.php';
		include SBM_DIR . 'views/tab-schedule.php';
		include SBM_DIR . 'views/tab-settings.php';
		?>
	</main>

	<div class="sbm-toasts" id="sbm-toasts" aria-live="polite"></div>
</div>
