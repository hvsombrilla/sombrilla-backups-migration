<?php
defined( 'ABSPATH' ) || exit;

class SBM_Download_Runner {

	const CHUNK = 8388608;

	/** @return SBM_Job|WP_Error */
	public static function create( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( 'sbm_url', __( 'La URL no es válida (debe empezar con http:// o https://).', 'sombrilla-bm' ) );
		}
		$allow = (bool) SBM_Settings::get( 'allow_private_urls' );
		if ( ! $allow && ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'sbm_url', __( 'La URL apunta a una dirección no permitida (red local o puerto no estándar). Actívalo en Ajustes si es intencional.', 'sombrilla-bm' ) );
		}
		SBM_Storage::ensure();
		$final = SBM_Storage::dir( 'backups' ) . '/imported-' . SBM_Storage::new_name();
		$id    = bin2hex( random_bytes( 6 ) );

		$job = SBM_Job::create(
			'download',
			array(
				'url'      => esc_url_raw( $url ),
				'allow'    => $allow,
				'part'     => $final . '.part',
				'final'    => $final,
				'tmp'      => SBM_Storage::dir( 'tmp' ) . '/' . $id . '.chunk',
				'offset'   => 0,
				'total'    => 0,
				'retries'  => 0,
				'checked'  => false,
			)
		);
		$job->s['indeterminate'] = true;
		$job->log( sprintf( __( 'Descargando desde %s', 'sombrilla-bm' ), wp_parse_url( $url, PHP_URL_HOST ) ) );
		$job->save();
		return $job;
	}

	public static function cleanup( SBM_Job $job ) {
		foreach ( array( 'part', 'tmp' ) as $k ) {
			if ( ! empty( $job->s['data'][ $k ] ) && is_file( $job->s['data'][ $k ] ) ) {
				@unlink( $job->s['data'][ $k ] );
			}
		}
	}

	/** Bytes en vuelo del chunk actual, para el polling de progreso. */
	public static function live( SBM_Job $job ) {
		$d = $job->s['data'];
		if ( 'running' === $job->s['status'] && 'download' === $job->s['phase'] && ! empty( $d['tmp'] ) ) {
			clearstatcache( true, $d['tmp'] );
			$size = (int) @filesize( $d['tmp'] );
			if ( $size > 0 ) {
				$job->s['done'] = $d['offset'] + $size;
			}
		}
	}

	public static function step( SBM_Job $job ) {
		$d =& $job->data();
		$job->s['unit'] = 'bytes';

		if ( 'verify' === $job->s['phase'] ) {
			self::verify( $job, $d );
			return;
		}

		while ( 'running' === $job->s['status'] && 'download' === $job->s['phase'] && ! $job->expired() ) {
			@set_time_limit( 0 );
			$resp = wp_remote_get(
				$d['url'],
				array(
					'timeout'            => 1800,
					'redirection'        => 5,
					'stream'             => true,
					'filename'           => $d['tmp'],
					'reject_unsafe_urls' => ! $d['allow'],
					'user-agent'         => 'SombrillaBM/' . SBM_VERSION,
					'headers'            => array( 'Range' => 'bytes=' . $d['offset'] . '-' . ( $d['offset'] + self::CHUNK - 1 ) ),
				)
			);

			if ( is_wp_error( $resp ) ) {
				@unlink( $d['tmp'] );
				if ( ++$d['retries'] > 3 ) {
					throw new RuntimeException( sprintf( __( 'Error de red: %s', 'sombrilla-bm' ), $resp->get_error_message() ) );
				}
				$job->log( sprintf( __( 'Error de red, reintentando (%d/3)…', 'sombrilla-bm' ), $d['retries'] ), 'warn' );
				sleep( 2 );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 206 === $code ) {
				if ( preg_match( '#bytes\s+\d+-\d+/(\d+)#', (string) wp_remote_retrieve_header( $resp, 'content-range' ), $m ) ) {
					$d['total'] = (int) $m[1];
				}
				$len = (int) @filesize( $d['tmp'] );
				self::append( $d['tmp'], $d['part'] );
				$d['offset'] += $len;
				$complete     = 0 === $len || ( $d['total'] && $d['offset'] >= $d['total'] );
			} elseif ( 200 === $code ) {
				// El servidor ignoró Range: llegó el archivo completo.
				@unlink( $d['part'] );
				if ( ! @rename( $d['tmp'], $d['part'] ) ) {
					self::append( $d['tmp'], $d['part'] );
				}
				$d['offset'] = (int) filesize( $d['part'] );
				$d['total']  = $d['offset'];
				$complete    = true;
			} elseif ( 416 === $code && $d['offset'] > 0 ) {
				@unlink( $d['tmp'] );
				$complete = true;
			} else {
				@unlink( $d['tmp'] );
				throw new RuntimeException( sprintf( __( 'El servidor respondió HTTP %d.', 'sombrilla-bm' ), $code ) );
			}

			if ( ! $d['checked'] && $d['offset'] >= strlen( SBM_Archive_Writer::MAGIC ) ) {
				$fh    = fopen( $d['part'], 'rb' );
				$magic = fread( $fh, strlen( SBM_Archive_Writer::MAGIC ) );
				fclose( $fh );
				if ( SBM_Archive_Writer::MAGIC !== $magic ) {
					@unlink( $d['part'] );
					throw new RuntimeException( __( 'La URL no devuelve un backup .sombrilla válido.', 'sombrilla-bm' ) );
				}
				$d['checked'] = true;
			}

			$job->s['done']          = $d['offset'];
			$job->s['total']         = $d['total'];
			$job->s['indeterminate'] = ! $d['total'];
			$job->progress(
				$d['total'] ? 95 * $d['offset'] / $d['total'] : 0,
				__( 'Descargando backup…', 'sombrilla-bm' ),
				$d['total'] ? sprintf( '%s / %s', SBM_Util::bytes( $d['offset'] ), SBM_Util::bytes( $d['total'] ) ) : SBM_Util::bytes( $d['offset'] )
			);
			$job->tick();

			if ( $complete ) {
				$job->log( sprintf( __( 'Descarga completa (%s)', 'sombrilla-bm' ), SBM_Util::bytes( $d['offset'] ) ) );
				$job->phase( 'verify' );
				$job->s['indeterminate'] = false;
				self::verify( $job, $d );
			}
		}
	}

	private static function append( $from, $to ) {
		$in  = fopen( $from, 'rb' );
		$out = fopen( $to, 'ab' );
		if ( ! $in || ! $out ) {
			throw new RuntimeException( __( 'No se puede escribir en wp-content/sombrilla-bm.', 'sombrilla-bm' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		@unlink( $from );
	}

	private static function verify( SBM_Job $job, array &$d ) {
		$job->progress( 97, __( 'Verificando backup…', 'sombrilla-bm' ) );
		$reader = new SBM_Archive_Reader( $d['part'] );
		$ok     = $reader->open();
		$m      = true === $ok ? $reader->manifest() : $ok;
		$reader->close();
		if ( is_wp_error( $m ) ) {
			@unlink( $d['part'] );
			throw new RuntimeException( $m->get_error_message() );
		}
		if ( ! @rename( $d['part'], $d['final'] ) ) {
			throw new RuntimeException( __( 'No se pudo guardar el backup descargado.', 'sombrilla-bm' ) );
		}
		$job->finish(
			array(
				'file' => basename( $d['final'] ),
				'size' => (int) filesize( $d['final'] ),
			),
			__( 'Backup descargado y verificado', 'sombrilla-bm' )
		);
	}
}
