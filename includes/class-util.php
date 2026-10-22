<?php
defined( 'ABSPATH' ) || exit;

class SBM_Util {

	public static function bytes( $n, $dec = 1 ) {
		$n     = (float) $n;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $n >= 1024 && $i < 4 ) {
			$n /= 1024;
			$i++;
		}
		return round( $n, $i ? $dec : 0 ) . ' ' . $units[ $i ];
	}

	/** Ruta relativa segura para extraer dentro de wp-content. */
	public static function safe_rel( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || strpos( $rel, "\0" ) !== false || strpos( $rel, '\\' ) !== false ) {
			return false;
		}
		if ( '/' === $rel[0] || preg_match( '#^[A-Za-z]:#', $rel ) ) {
			return false;
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				return false;
			}
		}
		return true;
	}

	/** Escapa un valor para SQL sin los placeholders internos de wpdb. */
	public static function esc( $s ) {
		global $wpdb;
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
			return mysqli_real_escape_string( $wpdb->dbh, $s );
		}
		return $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $s ) );
	}

	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function host_slug() {
		$host = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
		$host = $host ? $host : 'site';
		return trim( preg_replace( '/[^a-z0-9.-]+/', '-', strtolower( $host ) ), '-.' );
	}

	public static function rand( $len = 8 ) {
		return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
	}

	/** Tablas reales (sin vistas) del sitio, según el prefijo base. */
	public static function tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ) {
				continue;
			}
			$out[] = $row[0];
		}
		return $out;
	}

	public static function json( $data ) {
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	public static function free_space( $path ) {
		$free = @disk_free_space( $path );
		return false === $free ? null : (float) $free;
	}
}
