<?php
defined( 'ABSPATH' ) || exit;

class SBM_Plugin {

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'init' ) );
		SBM_Ajax::init();
		SBM_Scheduler::init();
		if ( is_admin() ) {
			SBM_Admin::init();
		}
	}

	public static function init() {
		load_plugin_textdomain( 'sombrilla-bm', false, dirname( plugin_basename( SBM_FILE ) ) . '/languages' );
		if ( get_option( 'sbm_version' ) !== SBM_VERSION ) {
			SBM_Storage::ensure();
			update_option( 'sbm_version', SBM_VERSION, false );
		}
	}

	public static function activate() {
		if ( PHP_INT_SIZE < 8 ) {
			deactivate_plugins( plugin_basename( SBM_FILE ) );
			wp_die( esc_html__( 'Sombrilla Backups & Migration requiere PHP de 64 bits para manejar archivos grandes.', 'sombrilla-bm' ) );
		}
		SBM_Storage::ensure();
		SBM_Scheduler::reschedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( SBM_Scheduler::HOOK );
	}
}
