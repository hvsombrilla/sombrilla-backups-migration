<?php
defined( 'ABSPATH' ) || exit;

class SBM_Job {

	const STALE_AFTER = 900;

	public $s;

	private $lock;
	private $deadline = 0;
	private $saved_at = 0;

	public static function phases( $type ) {
		$map = array(
			'backup'   => array(
				'init'     => __( 'Preparar', 'sombrilla-bm' ),
				'db'       => __( 'Base de datos', 'sombrilla-bm' ),
				'scan'     => __( 'Escanear', 'sombrilla-bm' ),
				'files'    => __( 'Archivos', 'sombrilla-bm' ),
				'finalize' => __( 'Finalizar', 'sombrilla-bm' ),
			),
			'restore'  => array(
				'init'   => __( 'Validar', 'sombrilla-bm' ),
				'files'  => __( 'Archivos', 'sombrilla-bm' ),
				'db'     => __( 'Base de datos', 'sombrilla-bm' ),
				'urls'   => __( 'Reemplazar URLs', 'sombrilla-bm' ),
				'finish' => __( 'Finalizar', 'sombrilla-bm' ),
			),
			'download' => array(
				'download' => __( 'Descargar', 'sombrilla-bm' ),
				'verify'   => __( 'Verificar', 'sombrilla-bm' ),
			),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}

	public static function dir() {
		return SBM_Storage::dir( 'jobs' );
	}

	public static function create( $type, array $data = array() ) {
		SBM_Storage::ensure();
		$phases      = array_keys( self::phases( $type ) );
		$job         = new self();
		$job->s      = array(
			'id'            => bin2hex( random_bytes( 8 ) ),
			'type'          => $type,
			'status'        => 'running',
			'phase'         => $phases ? $phases[0] : 'init',
			'percent'       => 0,
			'message'       => '',
			'detail'        => '',
			'done'          => 0,
			'total'         => 0,
			'unit'          => 'bytes',
			'indeterminate' => false,
			'log'           => array(),
			'seq'           => 0,
			'error'         => '',
			'token'         => bin2hex( random_bytes( 20 ) ),
			'created'       => time(),
			'updated'       => time(),
			'data'          => $data,
			'result'        => null,
		);
		$job->save();
		return $job;
	}

	public static function load( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			return null;
		}
		$file = self::dir() . '/' . $id . '.json';
		for ( $i = 0; $i < 3; $i++ ) {
			$raw = @file_get_contents( $file );
			if ( false !== $raw && '' !== $raw ) {
				$s = json_decode( $raw, true );
				if ( is_array( $s ) ) {
					$job    = new self();
					$job->s = $s;
					return $job;
				}
			}
			usleep( 60000 );
		}
		return null;
	}

	/** Job en curso (no abandonado), si existe. */
	public static function active() {
		foreach ( (array) glob( self::dir() . '/*.json' ) as $file ) {
			$job = self::load( basename( $file, '.json' ) );
			if ( $job && 'running' === $job->s['status'] && ! $job->is_stale() ) {
				return $job;
			}
		}
		return null;
	}

	public function is_stale() {
		return ( time() - (int) $this->s['updated'] ) > self::STALE_AFTER;
	}

	public function save() {
		$file = self::dir() . '/' . $this->s['id'] . '.json';
		$tmp  = $file . '.tmp';
		$this->s['updated'] = time();
		$this->saved_at     = microtime( true );
		if ( false === @file_put_contents( $tmp, SBM_Util::json( $this->s ) ) ) {
			return;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@copy( $tmp, $file );
			@unlink( $tmp );
		}
	}

	/** Guarda como máximo una vez por segundo; solo llamar en puntos consistentes. */
	public function tick() {
		if ( microtime( true ) - $this->saved_at >= 1 ) {
			$this->save();
		}
	}

	public function lock() {
		$this->lock = @fopen( self::dir() . '/' . $this->s['id'] . '.lock', 'c' );
		return $this->lock && flock( $this->lock, LOCK_EX | LOCK_NB );
	}

	public function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
	}

	public function begin_step() {
		$max = (int) ini_get( 'max_execution_time' );
		$b   = $max > 0 ? min( 10, $max * 0.5 ) : 10;
		$this->deadline = microtime( true ) + max( 3, $b );
	}

	public function expired() {
		return microtime( true ) >= $this->deadline;
	}

	public function &data() {
		return $this->s['data'];
	}

	public function phase( $phase ) {
		$this->s['phase'] = $phase;
	}

	public function progress( $percent, $message = null, $detail = null ) {
		$this->s['percent'] = max( 0, min( 100, round( $percent, 1 ) ) );
		if ( null !== $message ) {
			$this->s['message'] = $message;
		}
		if ( null !== $detail ) {
			$this->s['detail'] = $detail;
		}
	}

	public function log( $msg, $level = 'info' ) {
		$this->s['seq']++;
		$this->s['log'][] = array(
			'i' => $this->s['seq'],
			't' => time(),
			'l' => $level,
			'm' => mb_substr( (string) $msg, 0, 400 ),
		);
		if ( count( $this->s['log'] ) > 300 ) {
			$this->s['log'] = array_slice( $this->s['log'], -300 );
		}
	}

	public function fail( $msg ) {
		$this->s['status'] = 'error';
		$this->s['error']  = (string) $msg;
		$this->log( $msg, 'error' );
		if ( 'restore' === $this->s['type'] ) {
			SBM_Guard::release();
		} elseif ( 'backup' === $this->s['type'] ) {
			SBM_Backup_Runner::cleanup( $this );
		} elseif ( 'download' === $this->s['type'] ) {
			SBM_Download_Runner::cleanup( $this );
		}
	}

	public function finish( $result = null, $message = '' ) {
		$this->s['status']        = 'done';
		$this->s['percent']       = 100;
		$this->s['indeterminate'] = false;
		$this->s['result']        = $result;
		if ( '' !== $message ) {
			$this->s['message'] = $message;
		}
		$this->log( $message ? $message : __( 'Completado', 'sombrilla-bm' ), 'success' );
	}

	public function payload( $since = 0, $with_token = false ) {
		$s   = $this->s;
		$out = array(
			'id'            => $s['id'],
			'type'          => $s['type'],
			'status'        => $s['status'],
			'phase'         => $s['phase'],
			'phases'        => self::phases( $s['type'] ),
			'percent'       => $s['percent'],
			'message'       => $s['message'],
			'detail'        => $s['detail'],
			'done'          => $s['done'],
			'total'         => $s['total'],
			'unit'          => $s['unit'],
			'indeterminate' => $s['indeterminate'],
			'error'         => $s['error'],
			'updated'       => $s['updated'],
			'created'       => $s['created'],
			'result'        => $s['result'],
			'seq'           => $s['seq'],
			'log'           => array_values(
				array_filter(
					$s['log'],
					function ( $l ) use ( $since ) {
						return $l['i'] > $since;
					}
				)
			),
		);
		if ( $with_token ) {
			$out['token'] = $s['token'];
		}
		return $out;
	}

	/**
	 * Ejecuta un paso del job (compartido por AJAX y cron).
	 *
	 * @return array{0:SBM_Job|null,1:bool} job y si estaba ocupado.
	 */
	public static function execute( $id ) {
		$job = self::load( $id );
		if ( ! $job ) {
			return array( null, false );
		}
		if ( 'running' !== $job->s['status'] ) {
			return array( $job, false );
		}
		if ( ! $job->lock() ) {
			return array( $job, true );
		}
		// Recargar bajo lock por si otro paso terminó justo antes.
		$fresh = self::load( $id );
		if ( $fresh ) {
			$job->s = $fresh->s;
		}
		if ( 'running' !== $job->s['status'] ) {
			$job->unlock();
			return array( $job, false );
		}
		if ( $job->cancel_requested() ) {
			$job->finalize_cancel();
			$job->unlock();
			return array( $job, false );
		}
		$job->begin_step();
		@ignore_user_abort( true );
		@set_time_limit( 120 );
		try {
			switch ( $job->s['type'] ) {
				case 'backup':
					SBM_Backup_Runner::step( $job );
					break;
				case 'restore':
					SBM_Restore_Runner::step( $job );
					break;
				case 'download':
					SBM_Download_Runner::step( $job );
					break;
				default:
					$job->fail( 'Tipo de operación desconocido.' );
			}
		} catch ( Throwable $e ) {
			$job->fail( $e->getMessage() );
		}
		if ( $job->cancel_requested() ) {
			$job->finalize_cancel();
		}
		$job->save();
		$job->unlock();
		return array( $job, false );
	}

	private function cancel_file() {
		return self::dir() . '/' . $this->s['id'] . '.cancel';
	}

	public function cancel_requested() {
		return is_file( $this->cancel_file() );
	}

	/** Solicita la cancelación; si no hay un paso en curso se aplica al instante. */
	public static function cancel( $id ) {
		$job = self::load( $id );
		if ( ! $job || 'running' !== $job->s['status'] ) {
			return $job;
		}
		@file_put_contents( $job->cancel_file(), '1' );
		if ( $job->lock() ) {
			$fresh = self::load( $id );
			if ( $fresh ) {
				$job->s = $fresh->s;
			}
			$job->finalize_cancel();
			$job->unlock();
		}
		return $job;
	}

	public function finalize_cancel() {
		@unlink( $this->cancel_file() );
		if ( 'running' !== $this->s['status'] ) {
			return;
		}
		$this->s['status']        = 'cancelled';
		$this->s['indeterminate'] = false;
		switch ( $this->s['type'] ) {
			case 'backup':
				SBM_Backup_Runner::cleanup( $this );
				break;
			case 'download':
				SBM_Download_Runner::cleanup( $this );
				break;
			case 'restore':
				SBM_Guard::release();
				break;
		}
		$this->log( __( 'Operación cancelada', 'sombrilla-bm' ), 'warn' );
		$this->save();
	}
}
