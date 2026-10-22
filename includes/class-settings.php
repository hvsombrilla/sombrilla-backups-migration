<?php
defined( 'ABSPATH' ) || exit;

class SBM_Settings {

	const OPTION = 'sbm_settings';

	const SCOPES = array( 'db', 'uploads', 'plugins', 'themes', 'other' );

	public static function defaults() {
		return array(
			'exclude'            => "cache\nupgrade\nupgrade-temp-backup\nbackup\nbackups\nbackup-db\nupdraft\nai1wm-backups\nwpvividbackups\ndebug.log\n*/node_modules\n*/.git",
			'allow_private_urls' => false,
			'schedule'           => array(
				'enabled'   => false,
				'frequency' => 'daily',
				'time'      => '03:00',
				'retention' => 5,
				'scope'     => self::SCOPES,
			),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$def   = self::defaults();
		$out   = array_merge( $def, $saved );
		$out['schedule'] = array_merge( $def['schedule'], isset( $saved['schedule'] ) && is_array( $saved['schedule'] ) ? $saved['schedule'] : array() );
		return $out;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function exclusions() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'exclude' ) );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( str_replace( '\\', '/', $line ), " \t/" );
			if ( '' !== $line && false === strpos( $line, '..' ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function sanitize_scope( $scope ) {
		$scope = is_array( $scope ) ? $scope : array();
		return array_values( array_intersect( self::SCOPES, array_map( 'sanitize_key', $scope ) ) );
	}

	public static function save( array $in ) {
		$cur = self::all();

		if ( isset( $in['exclude'] ) ) {
			$cur['exclude'] = sanitize_textarea_field( wp_unslash( $in['exclude'] ) );
		}
		if ( isset( $in['allow_private_urls'] ) ) {
			$cur['allow_private_urls'] = filter_var( $in['allow_private_urls'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $in['schedule'] ) && is_array( $in['schedule'] ) ) {
			$s    = $in['schedule'];
			$sch  = $cur['schedule'];
			$freq = isset( $s['frequency'] ) ? sanitize_key( $s['frequency'] ) : $sch['frequency'];

			$sch['enabled']   = isset( $s['enabled'] ) ? filter_var( $s['enabled'], FILTER_VALIDATE_BOOLEAN ) : $sch['enabled'];
			$sch['frequency'] = in_array( $freq, array( 'daily', 'weekly', 'monthly' ), true ) ? $freq : 'daily';
			$sch['time']      = ( isset( $s['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $s['time'] ) ) ? $s['time'] : $sch['time'];
			$sch['retention'] = isset( $s['retention'] ) ? max( 1, min( 100, (int) $s['retention'] ) ) : $sch['retention'];
			$sch['scope']     = isset( $s['scope'] ) ? self::sanitize_scope( $s['scope'] ) : $sch['scope'];
			if ( empty( $sch['scope'] ) ) {
				$sch['scope'] = self::SCOPES;
			}
			$cur['schedule'] = $sch;
		}

		update_option( self::OPTION, $cur, false );
		SBM_Scheduler::reschedule();
		return $cur;
	}
}
