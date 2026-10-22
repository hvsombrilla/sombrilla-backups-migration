<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sbm_settings' );
delete_option( 'sbm_version' );
wp_clear_scheduled_hook( 'sbm_scheduled_backup' );

// Los backups se conservan a propósito; solo se eliminan jobs y temporales.
$base = trailingslashit( WP_CONTENT_DIR ) . 'sombrilla-bm';
foreach ( array( 'jobs', 'tmp' ) as $sub ) {
	$files = glob( $base . '/' . $sub . '/*' );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
}
