<?php
defined( 'ABSPATH' ) || exit;

class SBM_Backup_Runner {

	const CHUNK = 1048576;

	public static function create( array $opts ) {
		SBM_Storage::ensure();
		$scope = SBM_Settings::sanitize_scope( isset( $opts['scope'] ) ? $opts['scope'] : SBM_Settings::SCOPES );
		if ( ! $scope ) {
			$scope = SBM_Settings::SCOPES;
		}
		$final = SBM_Storage::dir( 'backups' ) . '/' . SBM_Storage::new_name();

		$job = SBM_Job::create(
			'backup',
			array(
				'label'         => isset( $opts['label'] ) ? sanitize_key( $opts['label'] ) : 'manual',
				'note'          => isset( $opts['note'] ) ? mb_substr( sanitize_text_field( $opts['note'] ), 0, 200 ) : '',
				'scope'         => $scope,
				'exclude'       => SBM_Settings::exclusions(),
				'final'         => $final,
				'part'          => $final . '.part',
				'committed'     => 0,
				'db'            => null,
				'db_stage'      => 'dump',
				'sql_path'      => SBM_Storage::dir( 'tmp' ) . '/' . bin2hex( random_bytes( 6 ) ) . '.sql',
				'sql_committed' => 0,
				'db_off'        => null,
				'db_size'       => 0,
				'copied'        => 0,
				'stack'         => array( '' ),
				'list_path'     => SBM_Storage::dir( 'tmp' ) . '/' . bin2hex( random_bytes( 6 ) ) . '.list',
				'list_committed' => 0,
				'list_pos'      => 0,
				'cur'           => null,
				'scan_files'    => 0,
				'scan_bytes'    => 0,
				'files_done'    => 0,
				'bytes_done'    => 0,
				'skipped'       => 0,
			)
		);
		$job->log( __( 'Backup iniciado', 'sombrilla-bm' ) );
		$job->save();
		return $job;
	}

	public static function step( SBM_Job $job ) {
		$d =& $job->data();
		while ( 'running' === $job->s['status'] && ! $job->expired() ) {
			switch ( $job->s['phase'] ) {
				case 'init':
					self::init( $job, $d );
					break;
				case 'db':
					'dump' === $d['db_stage'] ? self::dump( $job, $d ) : self::copy_sql( $job, $d );
					break;
				case 'scan':
					self::scan( $job, $d );
					break;
				case 'files':
					self::files( $job, $d );
					break;
				case 'finalize':
					self::finalize( $job, $d );
					break;
				default:
					$job->fail( 'Fase desconocida.' );
			}
		}
	}

	public static function cleanup( SBM_Job $job ) {
		$d = $job->s['data'];
		foreach ( array( 'part', 'sql_path', 'list_path' ) as $k ) {
			if ( ! empty( $d[ $k ] ) && is_file( $d[ $k ] ) ) {
				@unlink( $d[ $k ] );
			}
		}
	}

	private static function init( SBM_Job $job, array &$d ) {
		$dir = SBM_Storage::dir( 'backups' );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			throw new RuntimeException( __( 'wp-content/sombrilla-bm/backups no es escribible.', 'sombrilla-bm' ) );
		}
		$free = SBM_Util::free_space( $dir );
		if ( null !== $free && $free < 52428800 ) {
			throw new RuntimeException( __( 'Espacio en disco insuficiente (menos de 50 MB libres).', 'sombrilla-bm' ) );
		}
		$w = new SBM_Archive_Writer( $d['part'] );
		$w->open( 0 );
		$d['committed'] = $w->commit();
		$w->close();
		file_put_contents( $d['list_path'], '' );

		if ( in_array( 'db', $d['scope'], true ) ) {
			file_put_contents( $d['sql_path'], '' );
			$d['db'] = SBM_Db_Exporter::init_state();
			$job->log( sprintf( __( '%d tablas a exportar', 'sombrilla-bm' ), count( $d['db']['tables'] ) ) );
			$job->phase( 'db' );
		} else {
			$job->phase( 'scan' );
		}
		$job->progress( 1, __( 'Preparando backup…', 'sombrilla-bm' ) );
	}

	private static function dump( SBM_Job $job, array &$d ) {
		$fh = fopen( $d['sql_path'], 'c+b' );
		if ( ! $fh ) {
			throw new RuntimeException( __( 'No se puede escribir en wp-content/sombrilla-bm/tmp.', 'sombrilla-bm' ) );
		}
		ftruncate( $fh, $d['sql_committed'] );
		fseek( $fh, 0, SEEK_END );
		$done = false;
		while ( ! $done && ! $job->expired() ) {
			$done = SBM_Db_Exporter::step( $d['db'], $fh, $job );
			fflush( $fh );
			$d['sql_committed'] = ftell( $fh );
			$job->s['unit']     = 'items';
			$job->s['done']     = $d['db']['rows_done'];
			$job->s['total']    = $d['db']['rows_total'];
			$job->s['detail']   = sprintf( __( '%1$s filas · %2$s', 'sombrilla-bm' ), number_format_i18n( $d['db']['rows_done'] ), SBM_Util::bytes( $d['sql_committed'] ) );
			$job->tick();
		}
		fclose( $fh );
		if ( $done ) {
			$d['db_stage'] = 'copy';
			$job->log( sprintf( __( 'Base de datos exportada (%s)', 'sombrilla-bm' ), SBM_Util::bytes( $d['sql_committed'] ) ) );
		}
	}

	private static function copy_sql( SBM_Job $job, array &$d ) {
		$w = new SBM_Archive_Writer( $d['part'] );
		$w->open( $d['committed'] );
		if ( null === $d['db_off'] ) {
			$size         = (int) filesize( $d['sql_path'] );
			$d['db_off']  = $w->header(
				array(
					'type' => 'sql',
					'path' => 'database.sql',
					'size' => $size,
				)
			);
			$d['db_size']   = $size;
			$d['copied']    = 0;
			$d['committed'] = $w->commit();
		}
		$src = fopen( $d['sql_path'], 'rb' );
		fseek( $src, $d['copied'] );
		while ( $d['copied'] < $d['db_size'] && ! $job->expired() ) {
			$buf = fread( $src, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				throw new RuntimeException( 'Lectura del dump SQL interrumpida.' );
			}
			$w->write( $buf );
			$d['copied']   += strlen( $buf );
			$d['committed'] = $w->commit();
			$job->progress( 15, __( 'Empaquetando base de datos…', 'sombrilla-bm' ) );
			$job->tick();
		}
		fclose( $src );
		$w->close();
		if ( $d['copied'] >= $d['db_size'] ) {
			@unlink( $d['sql_path'] );
			$job->phase( 'scan' );
		}
	}

	private static function excluded( $rel, $name, array $excl ) {
		if ( 'sombrilla-bm' === $rel || 'mu-plugins/sombrilla-bm-loader.php' === $rel ) {
			return true;
		}
		foreach ( $excl as $p ) {
			if ( 0 === strpos( $p, '*/' ) ) {
				if ( 0 === strcasecmp( $name, substr( $p, 2 ) ) ) {
					return true;
				}
			} elseif ( $rel === $p || 0 === strpos( $rel, $p . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function scan( SBM_Job $job, array &$d ) {
		$root  = wp_normalize_path( WP_CONTENT_DIR );
		$list  = fopen( $d['list_path'], 'c+b' );
		ftruncate( $list, $d['list_committed'] );
		fseek( $list, 0, SEEK_END );
		$scope = $d['scope'];
		$job->s['unit'] = 'items';
		$job->s['indeterminate'] = true;

		while ( $d['stack'] && ! $job->expired() ) {
			$dir     = array_pop( $d['stack'] );
			$abs_dir = '' === $dir ? $root : $root . '/' . $dir;
			$entries = @scandir( $abs_dir );
			if ( ! $entries ) {
				continue;
			}
			foreach ( $entries as $e ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				$rel  = '' === $dir ? $e : $dir . '/' . $e;
				$path = $abs_dir . '/' . $e;
				if ( self::excluded( $rel, $e, $d['exclude'] ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( is_link( $path ) ) {
						continue;
					}
					if ( '' === $dir ) {
						$key = in_array( $e, array( 'uploads', 'plugins', 'themes' ), true ) ? $e : 'other';
						if ( ! in_array( $key, $scope, true ) ) {
							continue;
						}
					}
					$d['stack'][] = $rel;
				} elseif ( is_file( $path ) ) {
					if ( '' === $dir && ! in_array( 'other', $scope, true ) ) {
						continue;
					}
					$size = @filesize( $path );
					if ( false === $size ) {
						continue;
					}
					fwrite( $list, SBM_Util::json( array( $size, $rel ) ) . "\n" );
					$d['scan_files']++;
					$d['scan_bytes'] += $size;
				}
			}
			fflush( $list );
			$st                  = fstat( $list );
			$d['list_committed'] = (int) $st['size'];
			$job->progress( 15 + min( 5, $d['scan_files'] / 20000 ), __( 'Escaneando archivos…', 'sombrilla-bm' ), sprintf( __( '%1$s archivos · %2$s', 'sombrilla-bm' ), number_format_i18n( $d['scan_files'] ), SBM_Util::bytes( $d['scan_bytes'] ) ) );
			$job->s['done'] = $d['scan_files'];
			$job->tick();
		}
		fclose( $list );

		if ( ! $d['stack'] ) {
			$free = SBM_Util::free_space( SBM_Storage::dir( 'backups' ) );
			if ( null !== $free && $free < $d['scan_bytes'] + 52428800 ) {
				throw new RuntimeException(
					sprintf(
						__( 'Espacio en disco insuficiente: se necesitan ~%1$s y hay %2$s libres.', 'sombrilla-bm' ),
						SBM_Util::bytes( $d['scan_bytes'] ),
						SBM_Util::bytes( $free )
					)
				);
			}
			$job->log( sprintf( __( '%1$s archivos encontrados (%2$s)', 'sombrilla-bm' ), number_format_i18n( $d['scan_files'] ), SBM_Util::bytes( $d['scan_bytes'] ) ) );
			$job->s['indeterminate'] = false;
			$job->phase( 'files' );
		}
	}

	private static function files( SBM_Job $job, array &$d ) {
		$root = wp_normalize_path( WP_CONTENT_DIR );
		$w    = new SBM_Archive_Writer( $d['part'] );
		$w->open( $d['committed'] );
		$list = fopen( $d['list_path'], 'rb' );
		fseek( $list, $d['list_pos'] );
		$src      = null;
		$finished = false;
		$job->s['unit']  = 'bytes';
		$job->s['total'] = $d['scan_bytes'];

		while ( ! $job->expired() ) {
			if ( null === $d['cur'] ) {
				$line = fgets( $list );
				if ( false === $line ) {
					$finished = true;
					break;
				}
				$d['list_pos'] = ftell( $list );
				$e             = json_decode( $line, true );
				if ( ! is_array( $e ) || ! isset( $e[1] ) ) {
					continue;
				}
				$rel  = $e[1];
				$abs  = $root . '/' . $rel;
				$size = is_readable( $abs ) ? @filesize( $abs ) : false;
				if ( false === $size || ! SBM_Util::safe_rel( $rel ) ) {
					$d['skipped']++;
					continue;
				}
				$w->header(
					array(
						'type'  => 'file',
						'path'  => $rel,
						'size'  => $size,
						'mtime' => (int) @filemtime( $abs ),
					)
				);
				$d['committed'] = $w->commit();
				$d['cur']       = array(
					'rel'     => $rel,
					'size'    => $size,
					'written' => 0,
				);
				$d['files_done']++;
			}

			$abs = $root . '/' . $d['cur']['rel'];
			if ( ! $src && $d['cur']['written'] < $d['cur']['size'] ) {
				$src = @fopen( $abs, 'rb' );
				if ( $src ) {
					fseek( $src, $d['cur']['written'] );
				}
			}
			while ( $d['cur']['written'] < $d['cur']['size'] && ! $job->expired() ) {
				$n   = min( self::CHUNK, $d['cur']['size'] - $d['cur']['written'] );
				$buf = $src ? fread( $src, $n ) : '';
				if ( false === $buf || '' === $buf ) {
					// Archivo truncado o ilegible durante la lectura: se rellena para mantener el archivo coherente.
					$buf = str_repeat( "\0", $n );
				}
				$w->write( $buf );
				$len                    = strlen( $buf );
				$d['cur']['written']   += $len;
				$d['bytes_done']       += $len;
				$d['committed']         = $w->commit();
				$job->s['done']         = $d['bytes_done'];
				$job->progress(
					20 + 78 * min( 1, $d['bytes_done'] / max( 1, $d['scan_bytes'] ) ),
					sprintf( __( 'Archivando: %s', 'sombrilla-bm' ), mb_substr( $d['cur']['rel'], -70 ) ),
					sprintf( __( '%1$s / %2$s · %3$s archivos', 'sombrilla-bm' ), SBM_Util::bytes( $d['bytes_done'] ), SBM_Util::bytes( $d['scan_bytes'] ), number_format_i18n( $d['files_done'] ) )
				);
				$job->tick();
			}
			if ( $d['cur']['written'] >= $d['cur']['size'] ) {
				if ( $src ) {
					fclose( $src );
					$src = null;
				}
				$d['cur'] = null;
			}
		}
		if ( $src ) {
			fclose( $src );
		}
		fclose( $list );
		$w->close();
		if ( $finished ) {
			$job->phase( 'finalize' );
		}
	}

	private static function finalize( SBM_Job $job, array &$d ) {
		global $wpdb;
		$job->progress( 99, __( 'Finalizando backup…', 'sombrilla-bm' ) );

		$manifest = array(
			'format'         => SBM_FORMAT,
			'plugin_version' => SBM_VERSION,
			'created'        => gmdate( 'c' ),
			'created_ts'     => time(),
			'label'          => $d['label'],
			'note'           => $d['note'],
			'scope'          => $d['scope'],
			'site_name'      => get_bloginfo( 'name' ),
			'home_url'       => get_option( 'home' ),
			'site_url'       => get_option( 'siteurl' ),
			'abspath'        => wp_normalize_path( ABSPATH ),
			'content_dir'    => wp_normalize_path( WP_CONTENT_DIR ),
			'table_prefix'   => $wpdb->base_prefix,
			'db_charset'     => $wpdb->charset,
			'db_collate'     => $wpdb->collate,
			'db_version'     => $wpdb->db_version(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'multisite'      => is_multisite(),
			'theme'          => get_option( 'stylesheet' ),
			'db'             => null !== $d['db_off'] ? array(
				'offset' => $d['db_off'],
				'size'   => $d['db_size'],
				'tables' => $d['db']['tables'],
			) : null,
			'files'          => array(
				'count' => $d['files_done'],
				'bytes' => $d['bytes_done'],
			),
		);

		$w = new SBM_Archive_Writer( $d['part'] );
		$w->open( $d['committed'] );
		$w->finish( $manifest );

		if ( ! @rename( $d['part'], $d['final'] ) ) {
			throw new RuntimeException( __( 'No se pudo mover el backup a su ubicación final.', 'sombrilla-bm' ) );
		}
		@unlink( $d['list_path'] );

		if ( 'scheduled' === $d['label'] ) {
			$sch = SBM_Settings::get( 'schedule' );
			SBM_Storage::apply_retention( 'scheduled', $sch['retention'] );
		}

		if ( $d['skipped'] ) {
			$job->log( sprintf( __( '%d archivos omitidos (ilegibles)', 'sombrilla-bm' ), $d['skipped'] ), 'warn' );
		}
		$job->finish(
			array(
				'file' => basename( $d['final'] ),
				'size' => (int) filesize( $d['final'] ),
			),
			__( 'Backup completado', 'sombrilla-bm' )
		);
	}
}
