<?php
/** Scheduling occupancy and concurrency checks on a disposable WordPress site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Use only the disposable leagueflow.test installation.' );
}

$GLOBALS['lfsc_checks'] = 0;
$GLOBALS['lfsc_posts'] = array();
$lfsc_user = get_current_user_id();
$lfsc_settings = get_option( 'leagueflow_settings' );
$lfsc_windows = get_option( \LeagueFlow\Field_Availability_Manager::OPTION );

function lfsc_check( $condition, $message ) {
	++$GLOBALS['lfsc_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function lfsc_post( $type, $meta = array() ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Scheduling regression ' . wp_generate_uuid4() ), true );
	lfsc_check( ! is_wp_error( $id ), 'Create scheduling test post.' );
	$GLOBALS['lfsc_posts'][] = (int) $id;
	foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
	return (int) $id;
}

try {
	wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
	update_option( 'leagueflow_settings', \LeagueFlow\defaults() );
	update_option( \LeagueFlow\Field_Availability_Manager::OPTION, array() );
	$service = new \LeagueFlow\Field_Availability_Manager();
	$venue = 'Scheduling test gym ' . wp_generate_uuid4();
	$a = lfsc_post( 'lf_team' );
	$b = lfsc_post( 'lf_team' );
	$match = static function( $meta = array() ) use ( $a, $b ) {
		return lfsc_post( 'lf_match', array_merge( array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled' ), $meta ) );
	};
	$window = static function( $id, $date, $start = '09:00', $end = '12:00', $minutes = 60 ) use ( $service, $venue ) {
		$r = $service->save_availability( array( 'id' => $id, 'name' => 'Scheduling regression window', 'venue' => $venue, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'slot_minutes' => $minutes, 'active' => true ) );
		lfsc_check( ! is_wp_error( $r ), 'Create availability window.' );
		return $r;
	};

	// A closure that began before the query's previous-day boundary still
	// occupies the venue while it overlaps the selected date.
	$window( 'regression-closure', '2033-02-15' );
	$closure = lfsc_post( 'lf_calendar_event', array( 'lf_event_start_datetime' => '2033-02-01 09:00', 'lf_event_end_datetime' => '2033-02-20 23:59', 'lf_event_venue' => $venue, 'lf_event_status' => 'scheduled' ) );
	lfsc_check( array() === $service->get_available_slots( array( 'date' => '2033-02-15' ) ), 'A long-running closure blocks every overlapping availability slot.' );
	$blocked = $match();
	lfsc_check( 0 === $service->schedule_match( $blocked, array( 'date' => '2033-02-15' ) )['scheduled'] && '' === get_post_meta( $blocked, 'lf_match_datetime', true ), 'Scheduling cannot write through a long-running closure.' );
	update_post_meta( $closure, 'lf_event_status', 'cancelled' );
	lfsc_check( 3 === count( $service->get_available_slots( array( 'date' => '2033-02-15' ) ) ), 'A cancelled closure releases its slots.' );

	// Legacy appointments retain their linked rule's 90-minute duration; new
	// appointments persist duration so later rule edits do not resize them.
	$window( 'regression-long', '2033-03-01', '09:00', '10:30', 90 );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 30 );
	$existing = $match( array( 'lf_match_datetime' => '2033-03-01 09:00', 'lf_venue' => $venue, \LeagueFlow\Field_Availability_Manager::META_AVAILABILITY_ID => 'regression-long' ) );
	lfsc_check( 90 === \LeagueFlow\Field_Availability_Manager::get_match_duration_minutes( $existing ), 'Legacy match duration comes from its linked availability.' );
	$next = $match();
	$result = $service->schedule_match( $next, array( 'date' => '2033-03-01', 'availability_id' => 'regression-short' ) );
	lfsc_check( 1 === $result['scheduled'] && '2033-03-01 10:30' === get_post_meta( $next, 'lf_match_datetime', true ), 'The first free slot follows the full 90-minute fixture, not a fixed one-hour estimate.' );
	lfsc_check( 30 === (int) get_post_meta( $next, 'lf_duration_minutes', true ), 'Newly scheduled fixtures store their duration.' );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 45 );
	lfsc_check( 30 === \LeagueFlow\Field_Availability_Manager::get_match_duration_minutes( $next ), 'Editing the rule does not change a saved fixture duration.' );
	$window( 'regression-short', '2033-03-01', '10:00', '12:00', 30 );
	$third = $match();
	lfsc_check( 1 === $service->schedule_match( $third, array( 'date' => '2033-03-01', 'availability_id' => 'regression-short' ) )['scheduled'] && '2033-03-01 11:00' === get_post_meta( $third, 'lf_match_datetime', true ), 'A separate scheduling run respects the first run\'s saved half-hour reservation.' );

	// Rest/rematch rules need history beyond one day outside the requested
	// range, both before and after a candidate date.
	$past = $match( array( 'lf_match_datetime' => '2033-04-01 09:00', 'lf_venue' => $venue, 'lf_status' => 'finished' ) );
	$window( 'regression-gap-early', '2033-04-04', '09:00', '10:00' );
	$window( 'regression-gap-late', '2033-04-08', '09:00', '10:00' );
	$settings = \LeagueFlow\defaults(); $settings['min_days_between_rematch'] = 7;
	update_option( 'leagueflow_settings', $settings );
	$rematch = $match();
	lfsc_check( 0 === $service->schedule_match( $rematch, array( 'date' => '2033-04-04' ) )['scheduled'], 'A match three days before the requested range enforces a seven-day rematch gap.' );
	lfsc_check( 1 === $service->schedule_match( $rematch, array( 'date' => '2033-04-08' ) )['scheduled'], 'The exact seven-day gap is allowed.' );
	update_post_meta( $rematch, 'lf_status', 'cancelled' );
	$future = $match( array( 'lf_match_datetime' => '2033-04-12 09:00', 'lf_venue' => $venue ) );
	$before_future = $match();
	lfsc_check( 0 === $service->schedule_match( $before_future, array( 'date' => '2033-04-08' ) )['scheduled'], 'A later fixture outside the range also enforces the rematch gap.' );
	update_post_meta( $future, 'lf_status', 'cancelled' );
	$settings['min_days_between_rematch'] = 0; $settings['min_rest_days'] = 7;
	update_option( 'leagueflow_settings', $settings );
	$rested = $match();
	lfsc_check( 1 === $service->schedule_match( $rested, array( 'date_from' => '2033-04-04', 'date_to' => '2033-04-08' ) )['scheduled'] && '2033-04-08 09:00' === get_post_meta( $rested, 'lf_match_datetime', true ), 'Preferred rest days consider earlier matches outside the scheduling range.' );
	update_option( 'leagueflow_settings', \LeagueFlow\defaults() );

	// Neither an overwrite selection nor a mid-run transition can move an
	// active fixture. Existing finished/cancelled protections remain in place.
	$window( 'regression-live', '2033-05-01' );
	$live = $match( array( 'lf_status' => 'live', 'lf_match_datetime' => '2033-05-01 08:00', 'lf_venue' => $venue ) );
	$before = get_post_meta( $live );
	lfsc_check( 0 === $service->schedule_match( $live, array( 'date' => '2033-05-01', 'overwrite' => true ) )['scheduled'] && $before === get_post_meta( $live ), 'Overwrite cannot move a live match.' );

	// An unavailable advisory-lock service must fail closed before any write.
	$locked = $match();
	$fail_lock = static function( $query ) { return 0 === stripos( ltrim( $query ), 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $query; };
	add_filter( 'query', $fail_lock );
	try { $result = $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) ); }
	finally { remove_filter( 'query', $fail_lock ); }
	lfsc_check( 0 === $result['scheduled'] && 1 === $result['failed'] && '' === get_post_meta( $locked, 'lf_match_datetime', true ), 'Unavailable scheduler lock writes nothing.' );
	lfsc_check( false !== strpos( implode( ' ', $result['messages'] ), 'lock' ), 'Lock failure gives staff an actionable explanation.' );

	// A second database session holding the scheduling lock reproduces a
	// simultaneous request without relying on timing or a mocked reservation.
	global $wpdb;
	list( $db_host, $db_port, $db_socket ) = $wpdb->parse_db_host( DB_HOST );
	$other = new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ?: 3306, $db_socket );
	lfsc_check( ! $other->connect_errno, 'Open independent lock holder.' );
	$lock_name = \LeagueFlow\db_lock_name( 'schedule', 'site' );
	$escaped = $other->real_escape_string( $lock_name );
	lfsc_check( '1' === (string) $other->query( "SELECT GET_LOCK('$escaped', 0)" )->fetch_row()[0], 'Independent session holds the scheduler lock.' );
	try {
		$result = $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) );
		lfsc_check( 0 === $result['scheduled'] && '' === get_post_meta( $locked, 'lf_match_datetime', true ), 'A simultaneous scheduling run cannot reserve a slot while another run owns the lock.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('$escaped')" ); $other->close(); }
	lfsc_check( 1 === $service->schedule_match( $locked, array( 'date' => '2033-05-01' ) )['scheduled'], 'Scheduling succeeds after the other run releases its lock.' );

	// Referee finalization can commit after the scope query cached a match. The
	// scheduler must refresh that state inside the shared per-result lock.
	$window( 'regression-finalization', '2033-06-01' );
	$finalizing = $match( array( 'lf_match_datetime' => '2033-06-01 07:00', 'lf_venue' => $venue ) );
	$finalizing_lock = \LeagueFlow\db_lock_name( 'result', (string) $finalizing );
	$changed = false;
	$finish_before_lock = static function( $sql ) use ( $wpdb, $finalizing, $finalizing_lock, &$changed ) {
		if ( ! $changed && false !== strpos( $sql, 'GET_LOCK(' ) && false !== strpos( $sql, $finalizing_lock ) ) {
			$changed = true;
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'finished' ), array( 'post_id' => $finalizing, 'meta_key' => 'lf_status' ) );
		}
		return $sql;
	};
	add_filter( 'query', $finish_before_lock );
	try { $result = $service->schedule_match( $finalizing, array( 'date' => '2033-06-01', 'overwrite' => true ) ); }
	finally { remove_filter( 'query', $finish_before_lock ); }
	lfsc_check( $changed && 0 === $result['scheduled'] && '2033-06-01 07:00' === get_post_meta( $finalizing, 'lf_match_datetime', true ) && 'finished' === get_post_meta( $finalizing, 'lf_status', true ), 'A freshly finalized result cannot be moved using cached Scheduled state.' );

	// A selected existing booking still occupies its slot when its own update
	// is busy. Selection alone must never release that slot to another match.
	$busy = $match( array( 'lf_match_datetime' => '2033-06-01 09:00', 'lf_venue' => $venue ) );
	$other_match = $match();
	$other = new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, $db_port ?: 3306, $db_socket );
	$result_lock = \LeagueFlow\db_lock_name( 'result', (string) $busy );
	$escaped_result = $other->real_escape_string( $result_lock );
	lfsc_check( '1' === (string) $other->query( "SELECT GET_LOCK('$escaped_result', 0)" )->fetch_row()[0], 'Independent editor holds a selected match result lock.' );
	try {
		$result = $service->schedule_matches( array( 'match_ids' => array( $busy, $other_match ), 'date' => '2033-06-01', 'overwrite' => true ) );
		lfsc_check( 1 === $result['scheduled'] && 1 === $result['failed'] && '2033-06-01 09:00' === get_post_meta( $busy, 'lf_match_datetime', true ) && '2033-06-01 10:00' === get_post_meta( $other_match, 'lf_match_datetime', true ), 'A busy selected fixture retains occupancy while another selected match uses the next free slot.' );
	} finally { $other->query( "SELECT RELEASE_LOCK('$escaped_result')" ); $other->close(); }

	// The staff editor invokes scheduling while already holding this same lock.
	lfsc_check( \LeagueFlow\acquire_db_lock( $result_lock, 0 ), 'Editor can hold its own match result lock.' );
	try {
		$result = $service->schedule_match( $busy, array( 'date' => '2033-06-01', 'overwrite' => true ) );
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $result_lock ) );
		lfsc_check( 1 === $result['scheduled'] && (string) $owner === (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'Nested editor scheduling succeeds and leaves the outer editor lock held.' );
	} finally { \LeagueFlow\release_db_lock( $result_lock ); }
	wp_set_current_user( 0 );
	lfsc_check( 0 === $service->schedule_match( $locked, array( 'date' => '2033-05-01', 'overwrite' => true ) )['scheduled'], 'Anonymous caller cannot schedule.' );

	WP_CLI::success( 'Scheduling occupancy and concurrency: ' . $GLOBALS['lfsc_checks'] . ' assertions passed.' );
} finally {
	wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
	foreach ( array_reverse( $GLOBALS['lfsc_posts'] ) as $id ) { wp_delete_post( $id, true ); }
	if ( false === $lfsc_settings ) { delete_option( 'leagueflow_settings' ); } else { update_option( 'leagueflow_settings', $lfsc_settings ); }
	if ( false === $lfsc_windows ) { delete_option( \LeagueFlow\Field_Availability_Manager::OPTION ); } else { update_option( \LeagueFlow\Field_Availability_Manager::OPTION, $lfsc_windows ); }
	wp_set_current_user( $lfsc_user );
}
