<?php
defined( 'ABSPATH' ) || exit;

class SBM_Ajax {

	public static function init() {
		foreach ( array( 'start', 'step', 'status', 'cancel', 'active', 'list', 'delete', 'save_settings', 'upload', 'download' ) as $a ) {
			add_action( 'wp_ajax_sombrilla_bm_' . $a, array( __CLASS__, $a ) );
		}
		// Durante una restauración la tabla de usuarios cambia: esos pasos se autentican con el token del job.
		foreach ( array( 'step', 'status', 'cancel', 'download' ) as $a ) {
			add_action( 'wp_ajax_nopriv_sombrilla_bm_' . $a, array( __CLASS__, $a ) );
		}
	}

	private static function can() {
		return is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
	}

	private static function nonce_ok() {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		return $nonce && wp_verify_nonce( $nonce, 'sbm_nonce' );
	}

	private static function deny() {
		wp_send_json_error( array( 'message' => __( 'No autorizado.', 'sombrilla-bm' ) ), 403 );
	}

	private static function auth_admin() {
		if ( ! self::can() || ! self::nonce_ok() ) {
			self::deny();
		}
	}

	private static function auth_job( $job ) {
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Operación no encontrada.', 'sombrilla-bm' ) ), 404 );
		}
		if ( self::can() && self::nonce_ok() ) {
			return;
		}
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( 'restore' === $job->s['type'] && '' !== $token && hash_equals( (string) $job->s['token'], $token ) ) {
			return;
		}
		self::deny();
	}

	private static function req( $key, $default = '' ) {
		return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : $default;
	}

	private static function flag( $key ) {
		return isset( $_REQUEST[ $key ] ) && filter_var( wp_unslash( $_REQUEST[ $key ] ), FILTER_VALIDATE_BOOLEAN );
	}

	public static function start() {
		self::auth_admin();
		$active = SBM_Job::active();
		if ( $active ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ya hay una operación en curso.', 'sombrilla-bm' ),
					'active'  => $active->payload( 0, true ),
				)
			);
		}

		$type = self::req( 'type' );
		if ( 'backup' === $type ) {
			$scope = isset( $_POST['scope'] ) ? (array) wp_unslash( $_POST['scope'] ) : array(); // phpcs:ignore WordPress.Security
			$label = 'pre-restore' === self::req( 'label' ) ? 'pre-restore' : 'manual';
			$job   = SBM_Backup_Runner::create(
				array(
					'scope' => $scope,
					'note'  => self::req( 'note' ),
					'label' => $label,
				)
			);
		} elseif ( 'restore' === $type ) {
			$job = SBM_Restore_Runner::create(
				self::req( 'file' ),
				array(
					'files' => self::flag( 'restore_files' ),
					'db'    => self::flag( 'restore_db' ),
				)
			);
		} elseif ( 'download' === $type ) {
			$job = SBM_Download_Runner::create( isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '' );
		} else {
			wp_send_json_error( array( 'message' => __( 'Tipo de operación inválido.', 'sombrilla-bm' ) ) );
		}

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( array( 'job' => $job->payload( 0, true ) ) );
	}

	public static function step() {
		$id = self::req( 'id' );
		self::auth_job( SBM_Job::load( $id ) );
		list( $job, $busy ) = SBM_Job::execute( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Operación no encontrada.', 'sombrilla-bm' ) ), 404 );
		}
		wp_send_json_success(
			array(
				'job'  => $job->payload( (int) self::req( 'since', 0 ) ),
				'busy' => $busy,
			)
		);
	}

	public static function status() {
		$job = SBM_Job::load( self::req( 'id' ) );
		self::auth_job( $job );
		if ( 'download' === $job->s['type'] ) {
			SBM_Download_Runner::live( $job );
		}
		wp_send_json_success( array( 'job' => $job->payload( (int) self::req( 'since', 0 ) ) ) );
	}

	public static function cancel() {
		$id = self::req( 'id' );
		self::auth_job( SBM_Job::load( $id ) );
		$job = SBM_Job::cancel( $id );
		wp_send_json_success( array( 'job' => $job ? $job->payload( 0 ) : null ) );
	}

	public static function active() {
		self::auth_admin();
		$job = SBM_Job::active();
		wp_send_json_success( array( 'job' => $job ? $job->payload( 0, true ) : null ) );
	}

	public static function save_settings() {
		self::auth_admin();
		$raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : null; // phpcs:ignore WordPress.Security
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'sombrilla-bm' ) ) );
		}
		$saved = SBM_Settings::save( $raw );
		wp_send_json_success(
			array(
				'settings' => $saved,
				'next_run' => SBM_Scheduler::next_run(),
			)
		);
	}

	public static function list() {
		self::auth_admin();
		wp_send_json_success( array( 'backups' => SBM_Storage::all() ) );
	}

	public static function delete() {
		self::auth_admin();
		if ( ! SBM_Storage::delete( self::req( 'file' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo eliminar el backup.', 'sombrilla-bm' ) ) );
		}
		wp_send_json_success( array( 'backups' => SBM_Storage::all() ) );
	}

	public static function download() {
		$file  = self::req( 'file' );
		$token = self::req( 'token' );
		if ( ! ( self::can() && self::nonce_ok() ) && ! SBM_Storage::token_ok( $file, $token ) ) {
			wp_die( esc_html__( 'No autorizado.', 'sombrilla-bm' ), '', array( 'response' => 403 ) );
		}
		SBM_Storage::stream( $file );
	}

	/** Subida por chunks: cada petición agrega un fragmento al archivo parcial. */
	public static function upload() {
		self::auth_admin();
		SBM_Storage::ensure();

		$uid = self::req( 'uid' );
		if ( ! preg_match( '/^[a-f0-9]{16,32}$/', $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Identificador de subida inválido.', 'sombrilla-bm' ) ) );
		}
		$part = SBM_Storage::dir( 'tmp' ) . '/upload-' . $uid . '.part';
		clearstatcache( true, $part );
		$cur = is_file( $part ) ? (int) filesize( $part ) : 0;

		if ( self::flag( 'probe' ) ) {
			wp_send_json_success( array( 'size' => $cur ) );
		}
		if ( self::flag( 'abort' ) ) {
			@unlink( $part );
			wp_send_json_success();
		}

		$offset = (int) self::req( 'offset', 0 );
		$total  = (int) self::req( 'total', 0 );
		$chunk  = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null; // phpcs:ignore WordPress.Security
		if ( ! $chunk || UPLOAD_ERR_OK !== (int) $chunk['error'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
			$msg = $chunk && in_array( (int) $chunk['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
				? __( 'El fragmento supera el límite de subida del servidor.', 'sombrilla-bm' )
				: __( 'No se recibió el fragmento.', 'sombrilla-bm' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		if ( 0 === $offset ) {
			$free = SBM_Util::free_space( SBM_Storage::dir( 'backups' ) );
			if ( null !== $free && $total > 0 && $free < $total + 52428800 ) {
				wp_send_json_error( array( 'message' => sprintf( __( 'Espacio en disco insuficiente (%s libres).', 'sombrilla-bm' ), SBM_Util::bytes( $free ) ) ) );
			}
			$magic = (string) file_get_contents( $chunk['tmp_name'], false, null, 0, strlen( SBM_Archive_Writer::MAGIC ) );
			if ( SBM_Archive_Writer::MAGIC !== $magic ) {
				wp_send_json_error( array( 'message' => __( 'El archivo no es un backup de Sombrilla válido.', 'sombrilla-bm' ) ) );
			}
			$cur = 0;
		}
		if ( $offset !== $cur ) {
			wp_send_json_error(
				array(
					'message' => __( 'Fragmento fuera de orden.', 'sombrilla-bm' ),
					'size'    => $cur,
					'resync'  => true,
				)
			);
		}

		$in  = fopen( $chunk['tmp_name'], 'rb' );
		$out = fopen( $part, 0 === $offset ? 'wb' : 'ab' );
		if ( ! $in || ! $out ) {
			wp_send_json_error( array( 'message' => __( 'No se puede escribir en wp-content/sombrilla-bm/tmp.', 'sombrilla-bm' ) ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $part );
		$size = (int) filesize( $part );

		if ( $total > 0 && $size < $total ) {
			wp_send_json_success(
				array(
					'size' => $size,
					'done' => false,
				)
			);
		}

		$reader = new SBM_Archive_Reader( $part );
		$ok     = $reader->open();
		$m      = true === $ok ? $reader->manifest() : $ok;
		$reader->close();
		if ( is_wp_error( $m ) ) {
			@unlink( $part );
			wp_send_json_error( array( 'message' => $m->get_error_message() ) );
		}

		$name  = sanitize_file_name( self::req( 'name' ) );
		$final = SBM_Storage::dir( 'backups' ) . '/' . $name;
		if ( ! SBM_Storage::name_ok( $name ) || file_exists( $final ) ) {
			$name  = 'imported-' . SBM_Storage::new_name();
			$final = SBM_Storage::dir( 'backups' ) . '/' . $name;
		}
		if ( ! @rename( $part, $final ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el backup subido.', 'sombrilla-bm' ) ) );
		}
		wp_send_json_success(
			array(
				'size'    => $size,
				'done'    => true,
				'file'    => $name,
				'summary' => SBM_Storage::summarize( $final ),
			)
		);
	}
}
