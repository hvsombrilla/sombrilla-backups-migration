<?php
defined( 'ABSPATH' ) || exit;

class SBM_Db_Importer {

	/** Ejecuta sentencias del bloque SQL del archivo hasta agotar el tiempo del paso. @return bool true al terminar. */
	public static function step( SBM_Archive_Reader $reader, array &$st, SBM_Job $job ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->show_errors( false );

		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB
		if ( ! empty( $st['charset'] ) && preg_match( '/^[a-z0-9_]+$/i', $st['charset'] ) ) {
			$wpdb->query( 'SET NAMES ' . $st['charset'] ); // phpcs:ignore WordPress.DB
		}

		$end   = $st['start'] + $st['size'];
		$pos   = $st['start'] + $st['cursor'];
		$buf   = '';
		$bp    = 0;
		$remap = $st['src_prefix'] !== $st['dst_prefix'];

		while ( ! $job->expired() ) {
			$nl = $bp < strlen( $buf ) ? strpos( $buf, "\n", $bp ) : false;
			if ( false === $nl ) {
				if ( $pos >= $end ) {
					$nl = strlen( $buf );
					if ( $bp >= $nl ) {
						$st['cursor'] = $st['size'];
						wp_cache_flush();
						return true;
					}
				} else {
					$buf  = substr( $buf, $bp ) . $reader->read( $pos, min( 1048576, $end - $pos ) );
					$pos += min( 1048576, $end - $pos );
					$bp   = 0;
					continue;
				}
			}
			$line = substr( $buf, $bp, $nl - $bp );
			$bp   = $nl + 1;
			$st['cursor'] += strlen( $line ) + 1;

			$line = rtrim( $line, "\r" );
			if ( '' === $line || '-' === $line[0] ) {
				continue;
			}
			self::run( $line, $st, $remap, $job );
			$st['stmts']++;
			if ( 0 === $st['stmts'] % 25 ) {
				$job->progress( 60 + 25 * ( $st['cursor'] / max( 1, $st['size'] ) ), null, sprintf( '%s / %s', SBM_Util::bytes( $st['cursor'] ), SBM_Util::bytes( $st['size'] ) ) );
				$job->s['done']  = $st['cursor'];
				$job->s['total'] = $st['size'];
			}
		}
		wp_cache_flush();
		return false;
	}

	private static function run( $line, array &$st, $remap, SBM_Job $job ) {
		global $wpdb;

		if ( $remap && preg_match( '/^(DROP TABLE IF EXISTS |CREATE TABLE |INSERT INTO )`/', substr( $line, 0, 40 ), $m ) ) {
			$at = strlen( $m[0] );
			if ( substr( $line, $at, strlen( $st['src_prefix'] ) ) === $st['src_prefix'] ) {
				$line = substr_replace( $line, $st['dst_prefix'], $at, strlen( $st['src_prefix'] ) );
			}
		}
		if ( ! empty( $st['collation_map'] ) && 0 === strpos( $line, 'CREATE TABLE' ) ) {
			$line = strtr( $line, $st['collation_map'] );
		}

		if ( false !== $wpdb->query( $line ) ) { // phpcs:ignore WordPress.DB
			return;
		}

		// Fila duplicada (el sitio vivo escribió opciones entre pasos, o se reintentó un paso): el backup prevalece.
		if ( 0 === strpos( $line, 'INSERT INTO' ) && false !== stripos( $wpdb->last_error, 'Duplicate entry' ) ) {
			if ( false !== $wpdb->query( 'REPLACE INTO' . substr( $line, 11 ) ) ) { // phpcs:ignore WordPress.DB
				return;
			}
		}

		// Servidor destino sin la collation/charset del origen: degradar y reintentar.
		if ( 0 === strpos( $line, 'CREATE TABLE' ) && preg_match( '/collat|character set|charset/i', $wpdb->last_error ) ) {
			$maps = array(
				array( 'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci' ),
				array(
					'utf8mb4_0900_ai_ci'     => 'utf8mb4_unicode_ci',
					'utf8mb4_unicode_520_ci' => 'utf8mb4_unicode_ci',
				),
				array(
					'utf8mb4_0900_ai_ci'     => 'utf8_general_ci',
					'utf8mb4_unicode_520_ci' => 'utf8_general_ci',
					'utf8mb4_unicode_ci'     => 'utf8_general_ci',
					'utf8mb4'                => 'utf8',
				),
			);
			foreach ( $maps as $map ) {
				$try = strtr( $line, $map );
				if ( false !== $wpdb->query( $try ) ) { // phpcs:ignore WordPress.DB
					$st['collation_map'] = $map;
					$job->log( __( 'Collation no soportada por este servidor; se adaptó automáticamente.', 'sombrilla-bm' ), 'warn' );
					return;
				}
			}
		}

		throw new RuntimeException(
			sprintf(
				/* translators: 1: error de MySQL, 2: inicio de la sentencia */
				__( 'Error de base de datos: %1$s (sentencia: %2$s…)', 'sombrilla-bm' ),
				$wpdb->last_error,
				mb_substr( $line, 0, 90 )
			)
		);
	}
}
