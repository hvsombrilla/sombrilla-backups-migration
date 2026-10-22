<?php
defined( 'ABSPATH' ) || exit;

class SBM_Restore_Runner {

	const CHUNK = 1048576;

	/** @return SBM_Job|WP_Error */
	public static function create( $file, array $opts ) {
		global $wpdb;
		$path = SBM_Storage::path( $file );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'sbm_missing', __( 'El backup no existe.', 'sombrilla-bm' ) );
		}
		$reader = new SBM_Archive_Reader( $path );
		$ok     = $reader->open();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$m = $reader->manifest();
		$reader->close();
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		if ( isset( $m['format'] ) && (int) $m['format'] > SBM_FORMAT ) {
			return new WP_Error( 'sbm_format', __( 'Este backup fue creado con una versión más nueva del plugin. Actualiza Sombrilla Backups & Migration.', 'sombrilla-bm' ) );
		}

		$has_db  = ! empty( $m['db'] );
		$restore = array(
			'files' => ! empty( $opts['files'] ),
			'db'    => ! empty( $opts['db'] ) && $has_db,
		);
		if ( ! $restore['files'] && ! $restore['db'] ) {
			return new WP_Error( 'sbm_nothing', __( 'No hay nada que restaurar con las opciones elegidas.', 'sombrilla-bm' ) );
		}

		$dest = array(
			'home'     => get_option( 'home' ),
			'site'     => get_option( 'siteurl' ),
			'abspath'  => wp_normalize_path( ABSPATH ),
			'prefix'   => $wpdb->base_prefix,
			'settings' => get_option( SBM_Settings::OPTION, null ),
		);
		$pairs = array();
		if ( $restore['db'] ) {
			$pairs = SBM_Url_Replacer::build_pairs(
				array(
					'home'    => isset( $m['home_url'] ) ? $m['home_url'] : '',
					'site'    => isset( $m['site_url'] ) ? $m['site_url'] : '',
					'abspath' => isset( $m['abspath'] ) ? $m['abspath'] : '',
				),
				array(
					'home'    => $dest['home'],
					'site'    => $dest['site'],
					'abspath' => $dest['abspath'],
				)
			);
		}

		$job = SBM_Job::create(
			'restore',
			array(
				'file'     => $file,
				'path'     => $path,
				'restore'  => $restore,
				'dest'     => $dest,
				'pairs'    => $pairs,
				'src_home' => isset( $m['home_url'] ) ? $m['home_url'] : '',
				'extract'  => array(
					'pos'     => strlen( SBM_Archive_Writer::MAGIC ),
					'end'     => 0,
					'cur'     => null,
					'files'   => 0,
					'skipped' => 0,
				),
				'db'       => $restore['db'] ? array(
					'start'         => (int) $m['db']['offset'],
					'size'          => (int) $m['db']['size'],
					'cursor'        => 0,
					'src_prefix'    => isset( $m['table_prefix'] ) ? $m['table_prefix'] : $wpdb->base_prefix,
					'dst_prefix'    => $wpdb->base_prefix,
					'charset'       => isset( $m['db_charset'] ) ? $m['db_charset'] : '',
					'stmts'         => 0,
					'collation_map' => array(),
				) : null,
				'urls'     => null,
			)
		);
		$job->log( sprintf( __( 'Restauración iniciada desde %s', 'sombrilla-bm' ), $file ) );
		$job->save();
		return $job;
	}

	public static function step( SBM_Job $job ) {
		$d =& $job->data();
		SBM_Guard::touch();

		$reader = new SBM_Archive_Reader( $d['path'] );
		$ok     = $reader->open();
		if ( is_wp_error( $ok ) ) {
			throw new RuntimeException( $ok->get_error_message() );
		}
		try {
			while ( 'running' === $job->s['status'] && ! $job->expired() ) {
				switch ( $job->s['phase'] ) {
					case 'init':
						self::init( $job, $d, $reader );
						break;
					case 'files':
						self::files( $job, $d, $reader );
						break;
					case 'db':
						self::db( $job, $d, $reader );
						break;
					case 'urls':
						self::urls( $job, $d );
						break;
					case 'finish':
						self::finish( $job, $d );
						break;
					default:
						$job->fail( 'Fase desconocida.' );
				}
			}
		} finally {
			$reader->close();
		}
	}

	private static function next_phase( SBM_Job $job, array $d, $from ) {
		$order = array( 'files', 'db', 'urls', 'finish' );
		$idx   = array_search( $from, $order, true );
		foreach ( array_slice( $order, false === $idx ? 0 : $idx + 1 ) as $p ) {
			if ( 'files' === $p && ! $d['restore']['files'] ) {
				continue;
			}
			if ( 'db' === $p && ! $d['restore']['db'] ) {
				continue;
			}
			if ( 'urls' === $p && ( ! $d['restore']['db'] || ! $d['pairs'] ) ) {
				continue;
			}
			$job->phase( $p );
			return;
		}
	}

	private static function init( SBM_Job $job, array &$d, SBM_Archive_Reader $reader ) {
		$d['extract']['end'] = $reader->manifest_offset();
		if ( ! SBM_Guard::engage() ) {
			$job->log( __( 'No se pudo activar el guard de restauración (mu-plugins no escribible). Otros plugins podrían interferir.', 'sombrilla-bm' ), 'warn' );
		}
		if ( $d['pairs'] ) {
			$job->log( sprintf( __( 'Cambio de dominio detectado: %1$s → %2$s', 'sombrilla-bm' ), $d['src_home'], $d['dest']['home'] ) );
		}
		$job->progress( 2, __( 'Backup validado', 'sombrilla-bm' ) );
		self::next_phase( $job, $d, 'init' );
	}

	private static function skip_rel( $rel ) {
		$own = 'plugins/' . basename( rtrim( SBM_DIR, '/\\' ) );
		if ( 'sombrilla-bm' === $rel || 0 === strpos( $rel, 'sombrilla-bm/' ) ) {
			return true;
		}
		if ( $rel === $own || 0 === strpos( $rel, $own . '/' ) ) {
			return true;
		}
		return in_array( $rel, array( 'mu-plugins/sombrilla-bm-loader.php', 'object-cache.php', 'advanced-cache.php', 'db.php' ), true );
	}

	private static function files( SBM_Job $job, array &$d, SBM_Archive_Reader $reader ) {
		$root = wp_normalize_path( WP_CONTENT_DIR );
		$out  = null;
		$hi   = $d['restore']['db'] ? 60 : 95;
		$done = false;
		$job->s['unit']  = 'bytes';
		$job->s['total'] = $d['extract']['end'];

		while ( ! $job->expired() ) {
			if ( null === $d['extract']['cur'] ) {
				if ( $d['extract']['pos'] >= $d['extract']['end'] ) {
					$done = true;
					break;
				}
				$rec = $reader->header_at( $d['extract']['pos'] );
				if ( ! $rec ) {
					throw new RuntimeException( __( 'El backup está corrupto (registro ilegible).', 'sombrilla-bm' ) );
				}
				$h = $rec['header'];
				if ( 'manifest' === $h['type'] ) {
					$done = true;
					break;
				}
				if ( 'file' !== $h['type'] ) {
					$d['extract']['pos'] = $rec['next'];
					continue;
				}
				$rel = isset( $h['path'] ) ? $h['path'] : '';
				if ( ! SBM_Util::safe_rel( $rel ) ) {
					$job->log( sprintf( __( 'Ruta insegura omitida: %s', 'sombrilla-bm' ), $rel ), 'warn' );
					$d['extract']['skipped']++;
					$d['extract']['pos'] = $rec['next'];
					continue;
				}
				if ( self::skip_rel( $rel ) ) {
					$d['extract']['pos'] = $rec['next'];
					continue;
				}
				$dest = $root . '/' . $rel;
				if ( is_dir( $dest ) || ! wp_mkdir_p( dirname( $dest ) ) ) {
					$job->log( sprintf( __( 'No se pudo crear: %s', 'sombrilla-bm' ), $rel ), 'warn' );
					$d['extract']['skipped']++;
					$d['extract']['pos'] = $rec['next'];
					continue;
				}
				$d['extract']['cur'] = array(
					'rel'     => $rel,
					'data'    => $rec['data'],
					'size'    => $h['size'],
					'written' => 0,
					'mtime'   => isset( $h['mtime'] ) ? (int) $h['mtime'] : 0,
					'next'    => $rec['next'],
				);
			}

			$cur  = $d['extract']['cur'];
			$dest = $root . '/' . $cur['rel'];
			if ( ! $out ) {
				$out = @fopen( $dest, $cur['written'] > 0 ? 'cb' : 'wb' );
				if ( ! $out ) {
					$job->log( sprintf( __( 'No se pudo escribir: %s', 'sombrilla-bm' ), $cur['rel'] ), 'warn' );
					$d['extract']['skipped']++;
					$d['extract']['pos'] = $cur['next'];
					$d['extract']['cur'] = null;
					continue;
				}
				fseek( $out, $cur['written'] );
			}
			while ( $d['extract']['cur']['written'] < $cur['size'] && ! $job->expired() ) {
				$w   = $d['extract']['cur']['written'];
				$buf = $reader->read( $cur['data'] + $w, min( self::CHUNK, $cur['size'] - $w ) );
				if ( '' === $buf ) {
					throw new RuntimeException( __( 'El backup está truncado.', 'sombrilla-bm' ) );
				}
				if ( strlen( $buf ) !== fwrite( $out, $buf ) ) {
					throw new RuntimeException( sprintf( __( 'Error de escritura en %s (¿disco lleno?).', 'sombrilla-bm' ), $cur['rel'] ) );
				}
				$d['extract']['cur']['written'] += strlen( $buf );
				$pos = $cur['data'] + $d['extract']['cur']['written'];
				$job->s['done'] = $pos;
				$job->progress(
					2 + ( $hi - 2 ) * min( 1, $pos / max( 1, $d['extract']['end'] ) ),
					sprintf( __( 'Extrayendo: %s', 'sombrilla-bm' ), mb_substr( $cur['rel'], -70 ) ),
					sprintf( __( '%1$s / %2$s · %3$s archivos', 'sombrilla-bm' ), SBM_Util::bytes( $pos ), SBM_Util::bytes( $d['extract']['end'] ), number_format_i18n( $d['extract']['files'] ) )
				);
				$job->tick();
			}
			if ( $d['extract']['cur']['written'] >= $cur['size'] ) {
				fclose( $out );
				$out = null;
				if ( $cur['mtime'] > 0 ) {
					@touch( $dest, $cur['mtime'] );
				}
				$d['extract']['pos'] = $cur['next'];
				$d['extract']['cur'] = null;
				$d['extract']['files']++;
			}
		}
		if ( $out ) {
			fclose( $out );
		}
		if ( $done ) {
			$job->log( sprintf( __( '%d archivos restaurados', 'sombrilla-bm' ), $d['extract']['files'] ) );
			if ( $d['extract']['skipped'] ) {
				$job->log( sprintf( __( '%d archivos omitidos', 'sombrilla-bm' ), $d['extract']['skipped'] ), 'warn' );
			}
			self::next_phase( $job, $d, 'files' );
		}
	}

	private static function db( SBM_Job $job, array &$d, SBM_Archive_Reader $reader ) {
		if ( 0 === $d['db']['cursor'] && 0 === $d['db']['stmts'] ) {
			$job->log( __( 'Importando base de datos…', 'sombrilla-bm' ) );
			$job->progress( 60, __( 'Importando base de datos…', 'sombrilla-bm' ) );
		}
		$job->s['unit'] = 'bytes';
		$finished       = SBM_Db_Importer::step( $reader, $d['db'], $job );
		$job->save();
		if ( $finished ) {
			self::fix_prefix( $d['db'] );
			$job->log( sprintf( __( 'Base de datos importada (%s sentencias)', 'sombrilla-bm' ), number_format_i18n( $d['db']['stmts'] ) ) );
			self::next_phase( $job, $d, 'db' );
		}
	}

	private static function fix_prefix( array $db ) {
		global $wpdb;
		$src = $db['src_prefix'];
		$dst = $db['dst_prefix'];
		if ( $src === $dst ) {
			return;
		}
		$opts = SBM_Util::ident( $dst . 'options' );
		$meta = SBM_Util::ident( $dst . 'usermeta' );
		$wpdb->query( $wpdb->prepare( "UPDATE $opts SET option_name = %s WHERE option_name = %s", $dst . 'user_roles', $src . 'user_roles' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE $meta SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
				$dst,
				strlen( $src ) + 1,
				$wpdb->esc_like( $src ) . '%'
			)
		);
	}

	private static function urls( SBM_Job $job, array &$d ) {
		if ( null === $d['urls'] ) {
			$d['urls'] = SBM_Url_Replacer::init_state();
			$job->log( __( 'Reemplazando URLs en la base de datos…', 'sombrilla-bm' ) );
		}
		$job->s['unit']          = 'items';
		$job->s['indeterminate'] = false;
		$replacer                = new SBM_Url_Replacer( $d['pairs'] );
		if ( $replacer->step( $d['urls'], $job ) ) {
			$job->log( sprintf( __( 'URLs reemplazadas (%s filas modificadas)', 'sombrilla-bm' ), number_format_i18n( $d['urls']['changed'] ) ) );
			if ( $d['urls']['failed'] ) {
				$job->log( sprintf( __( '%d filas no pudieron actualizarse', 'sombrilla-bm' ), $d['urls']['failed'] ), 'warn' );
			}
			self::next_phase( $job, $d, 'urls' );
		}
	}

	private static function finish( SBM_Job $job, array &$d ) {
		global $wpdb;
		$job->progress( 98, __( 'Finalizando…', 'sombrilla-bm' ) );
		$dest = $d['dest'];

		if ( $d['restore']['db'] ) {
			wp_cache_flush();
			update_option( 'home', $dest['home'] );
			update_option( 'siteurl', $dest['site'] );

			// Los ajustes de este plugin pertenecen al sitio destino, no al backup.
			if ( null === $dest['settings'] ) {
				delete_option( SBM_Settings::OPTION );
			} else {
				update_option( SBM_Settings::OPTION, $dest['settings'], false );
			}
			self::ensure_active();

			$opt = SBM_Util::ident( $wpdb->base_prefix . 'options' );
			$wpdb->query( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM $opt WHERE option_name = 'rewrite_rules'" ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . SBM_Util::ident( $wpdb->base_prefix . 'postmeta' ) . ' WHERE meta_key = %s', '_elementor_css' ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM $opt WHERE option_name = '_elementor_global_css'" ); // phpcs:ignore WordPress.DB
		}
		if ( $d['restore']['files'] ) {
			foreach ( (array) glob( wp_normalize_path( WP_CONTENT_DIR ) . '/uploads/elementor/css/*' ) as $f ) {
				if ( is_file( $f ) ) {
					@unlink( $f );
				}
			}
		}

		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		SBM_Scheduler::reschedule();
		SBM_Guard::release();

		$changed = ! empty( $d['pairs'] );
		$job->finish(
			array(
				'url_changed' => $changed,
				'from'        => $d['src_home'],
				'to'          => $dest['home'],
				'files'       => $d['extract']['files'],
				'db'          => $d['restore']['db'],
				'login_url'   => trailingslashit( $dest['site'] ) . 'wp-login.php',
			),
			__( 'Restauración completada', 'sombrilla-bm' )
		);
	}

	/** Lee/escribe active_plugins por SQL: durante el guard get_option() devuelve una lista filtrada. */
	private static function ensure_active() {
		global $wpdb;
		$table = SBM_Util::ident( $wpdb->base_prefix . 'options' );
		$raw   = $wpdb->get_var( "SELECT option_value FROM $table WHERE option_name = 'active_plugins'" ); // phpcs:ignore WordPress.DB
		$list  = $raw ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : array();
		$list  = is_array( $list ) ? $list : array();
		$me    = plugin_basename( SBM_FILE );
		if ( ! in_array( $me, $list, true ) ) {
			$list[] = $me;
			if ( null === $raw ) {
				$wpdb->insert(
					$wpdb->base_prefix . 'options',
					array(
						'option_name'  => 'active_plugins',
						'option_value' => serialize( $list ),
						'autoload'     => 'yes',
					)
				);
			} else {
				$wpdb->update( $wpdb->base_prefix . 'options', array( 'option_value' => serialize( $list ) ), array( 'option_name' => 'active_plugins' ) );
			}
		}
	}
}
