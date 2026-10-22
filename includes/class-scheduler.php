<?php
defined( 'ABSPATH' ) || exit;

class SBM_Scheduler {

	const HOOK = 'sbm_scheduled_backup';
	const STEP = 'sbm_scheduled_step';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::STEP, array( __CLASS__, 'step' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	public static function schedules( $s ) {
		$s['sbm_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Una vez al mes', 'sombrilla-bm' ),
		);
		return $s;
	}

	public static function ensure() {
		$sch = SBM_Settings::get( 'schedule' );
		if ( ! empty( $sch['enabled'] ) && ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$sch = SBM_Settings::get( 'schedule' );
		if ( empty( $sch['enabled'] ) ) {
			return;
		}
		$map = array(
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => 'sbm_monthly',
		);
		wp_schedule_event( self::next_ts( $sch['time'] ), $map[ $sch['frequency'] ], self::HOOK );
	}

	public static function next_ts( $time ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
		$dt            = new DateTime( 'now', wp_timezone() );
		$dt->setTime( $h, $m, 0 );
		if ( $dt->getTimestamp() <= time() + 60 ) {
			$dt->modify( '+1 day' );
		}
		return $dt->getTimestamp();
	}

	public static function next_run() {
		return wp_next_scheduled( self::HOOK );
	}

	public static function run() {
		if ( SBM_Job::active() ) {
			return;
		}
		SBM_Storage::cleanup_jobs();
		$sch = SBM_Settings::get( 'schedule' );
		$job = SBM_Backup_Runner::create(
			array(
				'label' => 'scheduled',
				'scope' => $sch['scope'],
				'note'  => __( 'Backup programado', 'sombrilla-bm' ),
			)
		);
		self::queue( $job->s['id'] );
	}

	public static function step( $id ) {
		list( $job ) = SBM_Job::execute( $id );
		if ( $job && 'running' === $job->s['status'] ) {
			self::queue( $id );
		}
	}

	private static function queue( $id ) {
		wp_schedule_single_event( time() + 3, self::STEP, array( $id ) );
		spawn_cron();
	}
}
