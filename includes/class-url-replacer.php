<?php
defined( 'ABSPATH' ) || exit;

/**
 * Search & replace de URLs seguro con datos serializados (sin instanciar objetos), JSON escapado y URL-encoded.
 */
class SBM_Url_Replacer {

	private $map   = array();
	private $regex = '';

	/**
	 * @param array $src home, site, abspath del sitio origen.
	 * @param array $dst home, site, abspath del sitio destino.
	 * @return array lista de [tipo, desde, hasta]
	 */
	public static function build_pairs( array $src, array $dst ) {
		$pairs = array();
		foreach ( array( 'home', 'site' ) as $k ) {
			$from = untrailingslashit( trim( (string) $src[ $k ] ) );
			$to   = untrailingslashit( trim( (string) $dst[ $k ] ) );
			if ( '' !== $from && '' !== $to && 0 !== strcasecmp( $from, $to ) && ! isset( $pairs[ strtolower( $from ) ] ) ) {
				$pairs[ strtolower( $from ) ] = array( 'url', $from, $to );
			}
		}
		$pairs = array_values( $pairs );

		$from = untrailingslashit( wp_normalize_path( (string) $src['abspath'] ) );
		$to   = untrailingslashit( wp_normalize_path( (string) $dst['abspath'] ) );
		if ( '' !== $from && '' !== $to && $from !== $to ) {
			$pairs[] = array( 'path', $from, $to );
		}
		return $pairs;
	}

	public function __construct( array $pairs ) {
		foreach ( $pairs as $p ) {
			if ( 'url' === $p[0] ) {
				$from = preg_replace( '#^https?://#i', '', $p[1] );
				$to   = preg_replace( '#^https?:#i', '', $p[2] );
				$this->add( 'http://' . $from, $p[2] );
				$this->add( 'https://' . $from, $p[2] );
				$this->add( '//' . $from, $to );
			} else {
				$this->add( $p[1], $p[2] );
			}
		}
		uksort(
			$this->map,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		if ( $this->map ) {
			$alts        = array_map(
				function ( $k ) {
					return preg_quote( $k, '~' );
				},
				array_keys( $this->map )
			);
			$this->regex = '~(?:' . implode( '|', $alts ) . ')(?![\w-]|\.\w)~i';
		}
	}

	private function add( $from, $to ) {
		$bs    = '\\';
		$forms = array(
			array( $from, $to ),
			array( str_replace( '/', $bs . '/', $from ), str_replace( '/', $bs . '/', $to ) ),
			array( str_replace( '/', $bs . $bs . $bs . '/', $from ), str_replace( '/', $bs . $bs . $bs . '/', $to ) ),
			array( rawurlencode( $from ), rawurlencode( $to ) ),
		);
		foreach ( $forms as $f ) {
			$k = strtolower( $f[0] );
			if ( ! isset( $this->map[ $k ] ) ) {
				$this->map[ $k ] = $f[1];
			}
		}
	}

	public function has_work() {
		return '' !== $this->regex;
	}

	public function replace_value( $v ) {
		if ( ! is_string( $v ) || '' === $v || '' === $this->regex ) {
			return $v;
		}
		if ( is_serialized( $v, true ) ) {
			return $this->replace_serialized( $v );
		}
		return $this->replace_plain( $v );
	}

	private function replace_plain( $s ) {
		$map = $this->map;
		$out = preg_replace_callback(
			$this->regex,
			function ( $m ) use ( $map ) {
				return $map[ strtolower( $m[0] ) ];
			},
			$s
		);
		return null === $out ? $s : $out;
	}

	/** Recorre los tokens s:N:"..."; recalculando longitudes, sin unserialize(). */
	private function replace_serialized( $s ) {
		$out = '';
		$pos = 0;
		$len = strlen( $s );
		while ( $pos < $len && preg_match( '/(?<![A-Za-z0-9_])s:(\d+):"/', $s, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$mstart = $m[0][1];
			$cstart = $mstart + strlen( $m[0][0] );
			$n      = (int) $m[1][0];
			if ( $cstart + $n + 2 > $len || '";' !== substr( $s, $cstart + $n, 2 ) ) {
				$out .= substr( $s, $pos, $cstart - $pos );
				$pos  = $cstart;
				continue;
			}
			$new  = $this->replace_value( substr( $s, $cstart, $n ) );
			$out .= substr( $s, $pos, $mstart - $pos ) . 's:' . strlen( $new ) . ':"' . $new . '";';
			$pos  = $cstart + $n + 2;
		}
		return $out . substr( $s, $pos );
	}

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
			'rows_done'  => 0,
			'rows_total' => max( 1, $rows ),
			'changed'    => 0,
			'failed'     => 0,
		);
	}

	/** @return bool true al terminar todas las tablas */
	public function step( array &$st, SBM_Job $job ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$batch = 200;

		while ( ! $job->expired() ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				return true;
			}
			$table = $st['tables'][ $st['ti'] ];
			$q     = SBM_Util::ident( $table );

			if ( null === $st['meta'] ) {
				$st['meta']   = $this->table_meta( $table, $q );
				$st['last']   = null;
				$st['offset'] = 0;
			}
			$meta = $st['meta'];
			if ( ! $meta['text'] || ! $meta['pk'] ) {
				$st['ti']++;
				$st['meta'] = null;
				continue;
			}

			$cols = implode( ',', array_map( array( 'SBM_Util', 'ident' ), array_merge( $meta['pk'], $meta['text'] ) ) );
			$sql  = "SELECT $cols FROM $q";
			if ( $meta['keyset'] ) {
				$pk = SBM_Util::ident( $meta['pk'][0] );
				if ( null !== $st['last'] ) {
					$sql .= " WHERE $pk > '" . SBM_Util::esc( $st['last'] ) . "'";
				}
				$sql .= " ORDER BY $pk LIMIT $batch";
			} else {
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'SBM_Util', 'ident' ), $meta['pk'] ) ) . " LIMIT $batch OFFSET " . (int) $st['offset'];
			}

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( null === $rows ) {
				throw new RuntimeException( sprintf( 'Error leyendo %s: %s', $table, $wpdb->last_error ) );
			}
			foreach ( $rows as $row ) {
				$upd = array();
				foreach ( $meta['text'] as $c ) {
					if ( isset( $row[ $c ] ) && '' !== $row[ $c ] ) {
						$new = $this->replace_value( $row[ $c ] );
						if ( $new !== $row[ $c ] ) {
							$upd[ $c ] = $new;
						}
					}
				}
				if ( $upd ) {
					$where = array();
					foreach ( $meta['pk'] as $c ) {
						$where[ $c ] = $row[ $c ];
					}
					if ( false === $wpdb->update( $table, $upd, $where ) ) { // phpcs:ignore WordPress.DB
						$st['failed']++;
					} else {
						$st['changed']++;
					}
				}
			}
			$count = count( $rows );
			if ( $count && $meta['keyset'] ) {
				$st['last'] = (string) $rows[ $count - 1 ][ $meta['pk'][0] ];
			}
			$st['offset']    += $count;
			$st['rows_done'] += $count;
			if ( $count < $batch ) {
				$st['ti']++;
				$st['meta'] = null;
			}
			unset( $rows );

			$job->progress( 85 + 12 * min( 1, $st['rows_done'] / $st['rows_total'] ), sprintf( __( 'Reemplazando URLs en %s', 'sombrilla-bm' ), $table ), sprintf( __( '%s filas modificadas', 'sombrilla-bm' ), number_format_i18n( $st['changed'] ) ) );
			$job->tick();
		}
		return false;
	}

	private function table_meta( $table, $q ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $q, ARRAY_A ); // phpcs:ignore WordPress.DB
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . $q . " WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$pk   = array();
		foreach ( (array) $keys as $k ) {
			$pk[] = $k['Column_name'];
		}
		$text   = array();
		$int_pk = false;
		foreach ( (array) $cols as $c ) {
			if ( in_array( $c['Field'], $pk, true ) ) {
				if ( 1 === count( $pk ) && preg_match( '/int/i', $c['Type'] ) ) {
					$int_pk = true;
				}
				continue;
			}
			if ( preg_match( '/char|text/i', $c['Type'] ) && ! ( 'guid' === $c['Field'] && substr( $table, -5 ) === 'posts' ) ) {
				$text[] = $c['Field'];
			}
		}
		return array(
			'pk'     => $pk,
			'text'   => $text,
			'keyset' => $int_pk,
		);
	}
}
