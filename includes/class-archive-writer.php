<?php
defined( 'ABSPATH' ) || exit;

/**
 * Formato .sombrilla:
 *   "SOMBRILLA1\n" + registros [uint32 BE largo][header JSON][datos] ... + registro manifest + footer(uint64 offset manifest + "SBMEND01")
 */
class SBM_Archive_Writer {

	const MAGIC  = "SOMBRILLA1\n";
	const FOOTER = 'SBMEND01';

	private $fh;
	private $path;

	public function __construct( $path ) {
		$this->path = $path;
	}

	/** Abre y descarta cualquier byte posterior al último punto confirmado. */
	public function open( $committed = 0 ) {
		if ( $committed > 0 && file_exists( $this->path ) ) {
			$this->fh = fopen( $this->path, 'r+b' );
			if ( ! $this->fh ) {
				throw new RuntimeException( 'No se puede abrir el archivo de backup.' );
			}
			ftruncate( $this->fh, $committed );
			fseek( $this->fh, 0, SEEK_END );
		} else {
			$this->fh = fopen( $this->path, 'wb' );
			if ( ! $this->fh ) {
				throw new RuntimeException( 'No se puede crear el archivo de backup. Revisa permisos de wp-content/sombrilla-bm.' );
			}
			fwrite( $this->fh, self::MAGIC );
		}
	}

	public function pos() {
		return ftell( $this->fh );
	}

	/** @return int offset donde comienzan los datos del registro. */
	public function header( array $h ) {
		$json = SBM_Util::json( $h );
		$this->write( pack( 'N', strlen( $json ) ) . $json );
		return $this->pos();
	}

	public function write( $data ) {
		$len = strlen( $data );
		$w   = fwrite( $this->fh, $data );
		if ( $w !== $len ) {
			throw new RuntimeException( 'Error al escribir el backup (¿disco lleno?).' );
		}
	}

	public function finish( array $manifest ) {
		$offset = $this->pos();
		$json   = SBM_Util::json( $manifest );
		$this->header(
			array(
				'type' => 'manifest',
				'path' => 'manifest.json',
				'size' => strlen( $json ),
			)
		);
		$this->write( $json );
		$this->write( pack( 'J', $offset ) . self::FOOTER );
		$this->close();
	}

	public function commit() {
		fflush( $this->fh );
		return $this->pos();
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}
}
