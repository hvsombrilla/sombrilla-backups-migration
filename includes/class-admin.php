<?php
defined( 'ABSPATH' ) || exit;

class SBM_Admin {

	const SLUG = 'sombrilla-bm';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SBM_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Sombrilla Backups & Migration', 'sombrilla-bm' ),
			__( 'Sombrilla Backups', 'sombrilla-bm' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-backup',
			80
		);
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Backups', 'sombrilla-bm' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sbm-admin', SBM_URL . 'assets/css/admin.css', array(), SBM_VERSION );
		wp_enqueue_script( 'sbm-admin', SBM_URL . 'assets/js/admin.js', array( 'wp-i18n' ), SBM_VERSION, true );
		wp_set_script_translations( 'sbm-admin', 'sombrilla-bm', SBM_DIR . 'languages' );

		$post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$limit    = wp_max_upload_size();
		if ( $post_max > 0 ) {
			$limit = min( $limit, $post_max );
		}
		$chunk = (int) max( 262144, min( 8 * MB_IN_BYTES, $limit - 262144 ) );

		SBM_Storage::ensure();
		$data = array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sbm_nonce' ),
			'homeUrl'  => get_option( 'home' ),
			'chunk'    => $chunk,
			'settings' => SBM_Settings::all(),
			'nextRun'  => SBM_Scheduler::next_run(),
			'backups'  => SBM_Storage::all(),
			'free'     => SBM_Util::free_space( SBM_Storage::base() ),
		);
		wp_add_inline_script( 'sbm-admin', 'window.SBM = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$info = array(
			'wp'      => get_bloginfo( 'version' ),
			'php'     => PHP_VERSION,
			'memory'  => ini_get( 'memory_limit' ),
			'exec'    => (int) ini_get( 'max_execution_time' ),
			'upload'  => size_format( wp_max_upload_size() ),
			'storage' => str_replace( wp_normalize_path( ABSPATH ), '', SBM_Storage::base() ),
			'x64'     => PHP_INT_SIZE >= 8,
		);
		include SBM_DIR . 'views/app.php';
	}
}
