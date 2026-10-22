<?php
defined( 'ABSPATH' ) || exit;

class SBM_Archive_Reader {

	private $fh;
	private $path;
	private $size = 0;
	private $manifest_offset = 0;

	public function __construct( $path ) {
		$this->path = $path;
	}

	/** @return true|WP_Error */
	public function open() {
		if ( PHP_INT_SIZE < 8 ) {
			return new WP_Error( 'sbm_32bit', __( 'Se requiere PHP de 64 bits.', 'sombrilla-bm' ) );
		}
		$this->fh = @fopen( $this->path, 'rb' );
		if ( ! $this->fh ) {
			return new WP_Error( 'sbm_open', __( 'No se puede leer el archivo de backup.', 'sombrilla-bm' ) );
		}
		clearstatcache( true, $this->path );
		$this->size = (int) filesize( $this->path );
		$magic      = strlen( SBM_Archive_Writer::MAGIC );
		if ( $this->size < $magic + 16 || fread( $this->fh, $magic ) !== SBM_Archive_Writer::MAGIC ) {
			$this->close();
			return new WP_Error( 'sbm_magic', __( 'El archivo no es un backup de Sombrilla válido.', 'sombrilla-bm' ) );
		}
		fseek( $this->fh, $this->size - 16 );
		$footer = fread( $this->fh, 16 );
		if ( 16 !== strlen( $footer ) || substr( $footer, 8 ) !== SBM_Archive_Writer::FOOTER ) {
			$this->close();
			return new WP_Error( 'sbm_footer', __( 'El backup está incompleto o corrupto.', 'sombrilla-bm' ) );
		}
		$u                     = unpack( 'J', substr( $footer, 0, 8 ) );
		$this->manifest_offset = (int) $u[1];
		return true;
	}

	public function size() {
		return $this->size;
	}

	public function manifest_offset() {
		return $this->manifest_offset;
	}

	public function manifest() {
		$rec = $this->header_at( $this->manifest_offset );
		if ( ! $rec || 'manifest' !== $rec['header']['type'] || $rec['header']['size'] > 8388608 ) {
			return new WP_Error( 'sbm_manifest', __( 'No se pudo leer el manifest del backup.', 'sombrilla-bm' ) );
		}
		$m = json_decode( $this->read( $rec['data'], $rec['header']['size'] ), true );
		return is_array( $m ) ? $m : new WP_Error( 'sbm_manifest', __( 'Manifest inválido.', 'sombrilla-bm' ) );
	}

	/** @return array|null ['header'=>array,'data'=>int,'next'=>int] */
	public function header_at( $pos ) {
		if ( $pos < 0 || $pos + 4 > $this->size ) {
			return null;
		}
		fseek( $this->fh, $pos );
		$raw = fread( $this->fh, 4 );
		if ( 4 !== strlen( $raw ) ) {
			return null;
		}
		$u   = unpack( 'N', $raw );
		$len = (int) $u[1];
		if ( $len < 2 || $len > 65536 ) {
			return null;
		}
		$h = json_decode( (string) fread( $this->fh, $len ), true );
		if ( ! is_array( $h ) || ! isset( $h['type'], $h['size'] ) ) {
			return null;
		}
		$h['size'] = (int) $h['size'];
		$data      = $pos + 4 + $len;
		return array(
			'header' => $h,
			'data'   => $data,
			'next'   => $data + $h['size'],
		);
	}

	public function read( $offset, $len ) {
		fseek( $this->fh, $offset );
		$data = fread( $this->fh, $len );
		return false === $data ? '' : $data;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}
}
