<?php
/**
 * Plugin Name:       Sombrilla Backups & Migration
 * Description:       Crea backups completos (base de datos + wp-content), restáuralos desde un archivo o una URL y migra sitios reemplazando automáticamente el dominio.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sombrilla Cloud
 * License:           GPL-2.0-or-later
 * Text Domain:       sombrilla-bm
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Puede cargarse dos veces durante una restauración (mu-plugin guard + plugin normal).
if ( defined( 'SBM_VERSION' ) ) {
	return;
}

define( 'SBM_VERSION', '1.0.0' );
define( 'SBM_FORMAT', 1 );
define( 'SBM_FILE', __FILE__ );
define( 'SBM_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBM_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'SBM_' ) !== 0 ) {
			return;
		}
		$file = SBM_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, 4 ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SBM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SBM_Plugin', 'deactivate' ) );

SBM_Plugin::boot();
