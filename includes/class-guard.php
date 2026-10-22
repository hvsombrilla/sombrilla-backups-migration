<?php
defined( 'ABSPATH' ) || exit;

/**
 * Durante una restauración la base de datos cambia a mitad del proceso. El guard (mu-plugin temporal):
 *  - mantiene cargado solo este plugin en las peticiones AJAX propias (para que no fallen otros plugins del backup);
 *  - responde 503 en el front mientras dura la restauración.
 */
class SBM_Guard {

	const LOADER = 'sombrilla-bm-loader.php';

	private static function flag() {
		return SBM_Storage::dir( 'tmp' ) . '/maintenance.flag';
	}

	private static function loader() {
		return trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) . self::LOADER;
	}

	public static function engage() {
		SBM_Storage::ensure();
		file_put_contents( self::flag(), (string) time() );

		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}
		$code = "<?php\n// Sombrilla Backups & Migration - guard temporal de restauración (se elimina solo).\n"
			. '$sbm_flag = ' . var_export( self::flag(), true ) . ";\n"
			. '$sbm_plugin = ' . var_export( plugin_basename( SBM_FILE ), true ) . ";\n"
			. <<<'PHP'
if ( ! is_file( $sbm_flag ) || ( time() - (int) filemtime( $sbm_flag ) ) > 900 ) {
	@unlink( __FILE__ );
	return;
}
$sbm_action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
if ( 0 === strpos( $sbm_action, 'sombrilla_bm_' ) ) {
	add_filter( 'option_active_plugins', function () use ( $sbm_plugin ) { return array( $sbm_plugin ); }, 1 );
} elseif ( ! defined( 'WP_ADMIN' ) && ! defined( 'WP_CLI' ) && 'cli' !== PHP_SAPI ) {
	header( 'HTTP/1.1 503 Service Unavailable' );
	header( 'Retry-After: 120' );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Restaurando…</title><body style="font-family:sans-serif;text-align:center;padding:15vh 1rem"><h1>Sitio en restauración</h1><p>Volvemos en unos minutos.</p></body>';
	exit;
}
PHP;
		if ( false === @file_put_contents( self::loader(), $code ) ) {
			return false;
		}
		return true;
	}

	public static function touch() {
		if ( file_exists( self::flag() ) ) {
			@touch( self::flag() );
		}
	}

	public static function release() {
		if ( file_exists( self::flag() ) ) {
			@unlink( self::flag() );
		}
		if ( file_exists( self::loader() ) ) {
			@unlink( self::loader() );
		}
	}
}
