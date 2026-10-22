<?php
defined( 'ABSPATH' ) || exit;

class SBM_Storage {

	public static function base() {
		return wp_normalize_path( WP_CONTENT_DIR ) . '/sombrilla-bm';
	}

	public static function dir( $sub ) {
		return self::base() . '/' . $sub;
	}

	public static function ensure() {
		foreach ( array( '', 'backups', 'tmp', 'jobs' ) as $sub ) {
			$dir = rtrim( self::base() . '/' . $sub, '/' );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			self::protect( $dir );
		}
	}

	private static function protect( $dir ) {
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content );
			}
		}
	}

	public static function name_ok( $name ) {
		return is_string( $name ) && preg_match( '/^[A-Za-z0-9._-]{1,200}\.sombrilla$/', $name ) && false === strpos( $name, '..' );
	}

	public static function path( $name ) {
		return self::name_ok( $name ) ? self::dir( 'backups' ) . '/' . $name : false;
	}

	private static function secret() {
		$secret = get_option( 'sbm_link_secret' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'sbm_link_secret', $secret, false );
		}
		return $secret;
	}

	public static function token( $name ) {
		return hash_hmac( 'sha256', (string) $name, self::secret() );
	}

	public static function token_ok( $name, $token ) {
		return self::name_ok( $name ) && is_string( $token ) && '' !== $token && hash_equals( self::token( $name ), $token );
	}

	/** URL pública con token: no requiere sesión, apta para importar desde otro sitio. */
	public static function link( $name ) {
		return add_query_arg(
			array(
				'action' => 'sombrilla_bm_download',
				'file'   => $name,
				'token'  => self::token( $name ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function new_name() {
		return sprintf( '%s-%s-%s.sombrilla', SBM_Util::host_slug(), gmdate( 'Ymd-His' ), SBM_Util::rand( 8 ) );
	}

	public static function summarize( $path ) {
		$reader = new SBM_Archive_Reader( $path );
		if ( true !== $reader->open() ) {
			return null;
		}
		$m = $reader->manifest();
		$reader->close();
		if ( is_wp_error( $m ) ) {
			return null;
		}
		$files = isset( $m['files'] ) && is_array( $m['files'] ) ? $m['files'] : array();
		return array(
			'file'        => basename( $path ),
			'link'        => self::link( basename( $path ) ),
			'size'        => (int) filesize( $path ),
			'created'     => isset( $m['created_ts'] ) ? (int) $m['created_ts'] : (int) filemtime( $path ),
			'label'       => isset( $m['label'] ) ? (string) $m['label'] : 'manual',
			'note'        => isset( $m['note'] ) ? (string) $m['note'] : '',
			'home_url'    => isset( $m['home_url'] ) ? (string) $m['home_url'] : '',
			'site_url'    => isset( $m['site_url'] ) ? (string) $m['site_url'] : '',
			'site_name'   => isset( $m['site_name'] ) ? (string) $m['site_name'] : '',
			'wp_version'  => isset( $m['wp_version'] ) ? (string) $m['wp_version'] : '',
			'php_version' => isset( $m['php_version'] ) ? (string) $m['php_version'] : '',
			'scope'       => isset( $m['scope'] ) && is_array( $m['scope'] ) ? $m['scope'] : array(),
			'has_db'      => ! empty( $m['db'] ),
			'tables'      => ! empty( $m['db']['tables'] ) ? count( $m['db']['tables'] ) : 0,
			'files'       => isset( $files['count'] ) ? (int) $files['count'] : 0,
			'files_bytes' => isset( $files['bytes'] ) ? (int) $files['bytes'] : 0,
			'prefix'      => isset( $m['table_prefix'] ) ? (string) $m['table_prefix'] : '',
		);
	}

	public static function all() {
		$out = array();
		foreach ( (array) glob( self::dir( 'backups' ) . '/*.sombrilla' ) as $path ) {
			$sum = self::summarize( $path );
			if ( $sum ) {
				$out[] = $sum;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return $out;
	}

	public static function delete( $name ) {
		$path = self::path( $name );
		return $path && is_file( $path ) && @unlink( $path );
	}

	public static function apply_retention( $label, $keep ) {
		$list = array_values(
			array_filter(
				self::all(),
				function ( $b ) use ( $label ) {
					return $b['label'] === $label;
				}
			)
		);
		foreach ( array_slice( $list, max( 1, (int) $keep ) ) as $old ) {
			self::delete( $old['file'] );
		}
	}

	public static function cleanup_jobs() {
		foreach ( (array) glob( self::dir( 'jobs' ) . '/*' ) as $f ) {
			if ( is_file( $f ) && preg_match( '/\.(json|lock|tmp)$/', $f ) && filemtime( $f ) < time() - 3 * DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
		foreach ( (array) glob( self::dir( 'tmp' ) . '/*' ) as $f ) {
			if ( is_file( $f ) && 0 !== strpos( basename( $f ), 'index.' ) && filemtime( $f ) < time() - 3 * DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
	}

	/** Entrega el archivo con soporte de Range para descargas reanudables. */
	public static function stream( $name ) {
		$path = self::path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup no encontrado.', 'sombrilla-bm' ), '', array( 'response' => 404 ) );
		}
		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$code  = 200;

		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$code = 206;
		}

		@set_time_limit( 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 206 === $code ) {
			header( "Content-Range: bytes $start-$end/$size" );
		}

		$fh     = fopen( $path, 'rb' );
		$remain = $end - $start + 1;
		fseek( $fh, $start );
		while ( $remain > 0 && ! feof( $fh ) && 0 === connection_aborted() ) {
			$buf = fread( $fh, min( 1048576, $remain ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput
			$remain -= strlen( $buf );
			flush();
		}
		fclose( $fh );
		exit;
	}
}
