<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dump resumible: una sentencia SQL por línea (los saltos de línea de los datos van escapados).
 */
class SBM_Db_Exporter {

	const MAX_STATEMENT = 400000;

	/** @return array estado inicial */
	public static function init_state() {
		global $wpdb;
		$tables = SBM_Util::tables();
		$rows   = 0;
		foreach ( $tables as $t ) {
			$st = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $t ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $st && isset( $st['Rows'] ) ) {
				$rows += (int) $st['Rows'];
			}
		}
		return array(
			'tables'     => $tables,
			'ti'         => 0,
			'meta'       => null,
			'last'       => null,
			'offset'     => 0,
			'batch'      => 500,
			'rows_done'  => 0,
			'rows_total' => max( 1, $rows ),
		);
	}

	/** @return bool true cuando termina todo el dump */
	public static function step( array &$st, $fh, SBM_Job $job ) {
		global $wpdb;
		$wpdb->suppress_errors( true );

		while ( ! $job->expired() ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				return true;
			}
			$table = $st['tables'][ $st['ti'] ];
			$q     = SBM_Util::ident( $table );

			if ( null === $st['meta'] ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $q, ARRAY_N ); // phpcs:ignore WordPress.DB
				if ( ! $create || empty( $create[1] ) ) {
					throw new RuntimeException( sprintf( 'No se pudo leer la estructura de la tabla %s.', $table ) );
				}
				fwrite( $fh, 'DROP TABLE IF EXISTS ' . $q . ";\n" );
				fwrite( $fh, preg_replace( '/\s*\R\s*/', ' ', $create[1] ) . ";\n" );
				$st['meta']   = self::table_meta( $q );
				$st['last']   = null;
				$st['offset'] = 0;
				$job->progress( 1 + 14 * min( 1, $st['rows_done'] / $st['rows_total'] ), sprintf( __( 'Exportando tabla %s', 'sombrilla-bm' ), $table ) );
			}

			$meta  = $st['meta'];
			$limit = max( 10, (int) $st['batch'] );
			$sql   = 'SELECT * FROM ' . $q;
			if ( $meta['keyset'] ) {
				$pk = SBM_Util::ident( $meta['pk'][0] );
				if ( null !== $st['last'] ) {
					$sql .= " WHERE $pk > '" . SBM_Util::esc( $st['last'] ) . "'";
				}
				$sql .= " ORDER BY $pk LIMIT $limit";
			} else {
				if ( $meta['pk'] ) {
					$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'SBM_Util', 'ident' ), $meta['pk'] ) );
				}
				$sql .= " LIMIT $limit OFFSET " . (int) $st['offset'];
			}

			$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB
			if ( null === $rows && $wpdb->last_error ) {
				throw new RuntimeException( sprintf( 'Error leyendo %s: %s', $table, $wpdb->last_error ) );
			}
			$count = count( $rows );
			$bytes = 0;
			$stmt  = '';
			$head  = 'INSERT INTO ' . $q . ' VALUES ';
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $row as $i => $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( isset( $meta['bin'][ $i ] ) && '' !== $v ) {
						$vals[] = '0x' . bin2hex( $v );
					} else {
						$vals[] = "'" . SBM_Util::esc( $v ) . "'";
					}
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( '' !== $stmt && strlen( $stmt ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
					$bytes += fwrite( $fh, $head . $stmt . ";\n" );
					$stmt   = '';
				}
				$stmt .= ( '' === $stmt ? '' : ',' ) . $tuple;
			}
			if ( '' !== $stmt ) {
				$bytes += fwrite( $fh, $head . $stmt . ";\n" );
			}
			if ( $count && $meta['keyset'] ) {
				$st['last'] = (string) $rows[ $count - 1 ][ $meta['pk_index'] ];
			}
			$st['offset']    += $count;
			$st['rows_done'] += $count;
			$st['batch']      = $bytes > 4194304 ? max( 10, (int) ( $limit / 2 ) ) : min( 500, $limit * 2 );
			unset( $rows );

			if ( $count < $limit ) {
				$st['ti']++;
				$st['meta'] = null;
			}
			// Punto consistente: el llamador confirma el offset del archivo tras cada vuelta.
			return false;
		}
		return false;
	}

	private static function table_meta( $q ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $q, ARRAY_A ); // phpcs:ignore WordPress.DB
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . $q . " WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$bin  = array();
		$idx  = array();
		foreach ( (array) $cols as $i => $c ) {
			$idx[ $c['Field'] ] = $i;
			if ( preg_match( '/blob|binary|bit/i', $c['Type'] ) ) {
				$bin[ $i ] = true;
			}
		}
		$pk = array();
		foreach ( (array) $keys as $k ) {
			$pk[] = $k['Column_name'];
		}
		$keyset = false;
		if ( 1 === count( $pk ) && isset( $idx[ $pk[0] ] ) && preg_match( '/int/i', $cols[ $idx[ $pk[0] ] ]['Type'] ) ) {
			$keyset = true;
		}
		return array(
			'pk'       => $pk,
			'keyset'   => $keyset,
			'pk_index' => $pk && isset( $idx[ $pk[0] ] ) ? $idx[ $pk[0] ] : 0,
			'bin'      => $bin,
		);
	}
}
